<?php

namespace App\Services\Lineup;

use App\Models\Card;
use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\LineupSlot;
use App\Models\Manager;
use App\Models\Standing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Salva la formazione di un manager per una giornata.
 *
 * La panchina non è un avanzo: il suo ORDINE decide chi entra al posto di chi,
 * ed è l'unica cosa che il manager controlla davvero sulle sostituzioni. Il
 * draft della giornata si chiude prima che escano le formazioni ufficiali,
 * quindi i titolari senza voto sono la norma — vedi docs/DESIGN.md §5.2.
 */
class LineupSaver
{
    /**
     * @param  list<int>  $titolari  card_id, undici
     * @param  list<int>  $panchina  card_id, nell'ordine di ingresso scelto
     *
     * @throws RuntimeException
     */
    public function save(Manager $manager, LeagueSeason $stagione, int $matchday, string $module, array $titolari, array $panchina): Lineup
    {
        if ($this->isLocked($stagione, $matchday)) {
            throw new RuntimeException('La finestra di questa giornata è chiusa: la formazione non si tocca più.');
        }

        if (! isset(ModuleValidator::MODULES[$module])) {
            throw new RuntimeException("Modulo sconosciuto: {$module}.");
        }

        $rosa = Card::where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $manager->id)
            ->where('matchday', $matchday)
            ->get()
            ->keyBy('id');

        $this->assertBelongs($rosa, array_merge($titolari, $panchina));
        $this->assertFitsModule($rosa, $module, $titolari);

        return DB::transaction(function () use ($manager, $stagione, $matchday, $module, $titolari, $panchina, $rosa) {
            $lineup = Lineup::updateOrCreate(
                [
                    'league_season_id' => $stagione->id,
                    'manager_id' => $manager->id,
                    'matchday' => $matchday,
                ],
                [
                    'module' => $module,
                    'state' => 'draft',
                    'auto_generated' => false,
                ],
            );

            // Si riscrive tutto invece di aggiornare a pezzi: gli slot sono
            // una ventina e la coerenza fra titolari e ordine di panchina è
            // più facile da garantire riscrivendo che da riconciliare.
            $lineup->slots()->delete();

            foreach ($titolari as $cardId) {
                LineupSlot::create([
                    'lineup_id' => $lineup->id,
                    'card_id' => $cardId,
                    'is_starter' => true,
                ]);
            }

            // Le carte non elencate finiscono in fondo alla panchina, nell'ordine
            // in cui stanno in rosa: meglio una posizione arbitraria che nessuna,
            // perché senza `bench_order` non entrerebbero mai.
            $restanti = $rosa->keys()
                ->reject(fn (int $id) => in_array($id, $titolari, true) || in_array($id, $panchina, true))
                ->values()
                ->all();

            foreach ([...$panchina, ...$restanti] as $i => $cardId) {
                LineupSlot::create([
                    'lineup_id' => $lineup->id,
                    'card_id' => $cardId,
                    'is_starter' => false,
                    'bench_order' => $i + 1,
                ]);
            }

            return $lineup->load('slots.card');
        });
    }

    /**
     * La formazione si blocca quando la giornata COMINCIA.
     *
     * ⚠️ Non alla scadenza del draft, che è quello che si guardava prima. Le
     * due cose sembravano equivalenti perché il draft chiude poco prima delle
     * partite, ma la finestra del draft è una quota calcolata fra due primi
     * fischi: «da quando non posso più schierare» finiva per dipendere da un
     * conto sulle date invece che da un fatto, e in una stagione ricaricata —
     * dove il calendario è nel passato — quel conto non voleva dire niente.
     *
     * ⚠️ E nemmeno al primo fischio vero, che è il bug ancora precedente: con
     * un'annata archiviata ogni fischio è già suonato, quindi ogni formazione
     * nasceva bloccata e il gioco era ingiocabile. Il messaggio «il primo
     * fischio è passato» mandava a cercare il guasto nell'orologio invece che
     * nella regola.
     *
     * Adesso è un fatto dichiarato: l'amministratore dice che la giornata è
     * cominciata, e da quel momento le rose sono quelle. È l'unica regola che
     * funziona uguale su una stagione in diretta e su una rigiocata dieci anni
     * dopo, perché non dipende dall'orologio.
     */
    public function isLocked(LeagueSeason $stagione, int $matchday): bool
    {
        if ($stagione->giornataIniziata($matchday)) {
            return true;
        }

        // Rete di sicurezza per la giornata già CHIUSA: i punti sono in
        // classifica da un pezzo, e riscrivere la formazione che li ha
        // prodotti cambierebbe la storia sotto gli occhi di chi l'ha letta.
        // Serve perché «iniziata» lo preme una persona, e le persone saltano
        // dei passaggi.
        return Standing::where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->exists();
    }

    /**
     * Quando cominciano le partite di quella giornata, se si sa.
     *
     * ⚠️ Non è più «quando scade la formazione» — quello adesso lo decide un
     * pulsante — ma resta l'informazione utile da mostrare: dice a che ora
     * conviene aver deciso. In una stagione ricaricata è una data del passato,
     * e la pagina la tratta di conseguenza invece di prenderla per una
     * scadenza.
     */
    public function lockAt(LeagueSeason $stagione, int $matchday): ?Carbon
    {
        return Fixture::firstKickoff($stagione->season, $matchday);
    }

    /** @param  Collection<int,Card>  $rosa */
    private function assertBelongs(mixed $rosa, array $cardIds): void
    {
        foreach ($cardIds as $cardId) {
            if (! $rosa->has($cardId)) {
                throw new RuntimeException('Una delle carte non è nella tua rosa per questa giornata.');
            }
        }

        if (count($cardIds) !== count(array_unique($cardIds))) {
            throw new RuntimeException('Una carta non può stare in due posti insieme.');
        }
    }

    /**
     * Il modulo va rispettato ESATTAMENTE, non «almeno».
     *
     * Un 4-4-2 con cinque difensori non è un 4-4-2 con uno in più: è una
     * formazione da dodici, e il conteggio dei ruoli è ciò che tiene onesto
     * il calcolo delle sostituzioni, che sostituisce ruolo su ruolo.
     *
     * @param  Collection<int,Card>  $rosa
     */
    private function assertFitsModule(mixed $rosa, string $module, array $titolari): void
    {
        if (count($titolari) !== 11) {
            throw new RuntimeException('Servono esattamente undici titolari, ne hai scelti '.count($titolari).'.');
        }

        $conteggio = collect($titolari)
            ->map(fn (int $id) => $rosa[$id]->role)
            ->countBy()
            ->all();

        $atteso = ['P' => 1] + ModuleValidator::MODULES[$module];

        foreach ($atteso as $ruolo => $quanti) {
            if (($conteggio[$ruolo] ?? 0) !== $quanti) {
                throw new RuntimeException(
                    "Il {$module} vuole {$quanti} ".$this->nome($ruolo).', ne hai messi '.($conteggio[$ruolo] ?? 0).'.',
                );
            }
        }
    }

    private function nome(string $ruolo): string
    {
        return match ($ruolo) {
            'P' => 'portiere',
            'D' => 'difensori',
            'C' => 'centrocampisti',
            default => 'attaccanti',
        };
    }
}
