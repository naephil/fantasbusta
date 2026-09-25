<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\PlayerSeason;
use Illuminate\Console\Command;

/**
 * Elenca i giocatori che aspettano una decisione umana.
 *
 * Due domande diverse, che il design tiene separate apposta (§6.1):
 *
 *  - `role_confirmed` falso → «che ruolo ha». Il ruolo è stato indovinato
 *    dalla posizione inglese dell'API, che non è il ruolo fantacalcio.
 *  - `photo_verified` falso → «è davvero lui». L'id API-Football potrebbe
 *    puntare a un altro giocatore, e un id sbagliato non si annuncia: se punta
 *    a un giocatore inesistente dà 404 e lo vedi subito, ma se punta a uno
 *    *diverso* restituisce una faccia plausibile e passa ogni controllo
 *    automatico. Con ~550 righe, l'unico strumento affidabile è l'occhio.
 *
 * Le due domande vivono in posti diversi, ed è voluto: il ruolo appartiene
 * all'annata — cambia, e in un replay del 2022 deve restare quello di allora —
 * mentre «è davvero lui» è una verifica sulla persona, che si fa una volta e
 * vale per sempre.
 *
 * Questo comando è il surrogato testuale della schermata di verifica, che
 * resta necessaria: qui i nomi si leggono, le facce no.
 */
class ReviewPlayers extends Command
{
    protected $signature = 'players:review
        {anno : Annata di cui rivedere il listone}
        {--confirm-role=* : Id da confermare nel ruolo di quell\'annata}
        {--verify=* : Id la cui identità è stata verificata a occhio}
        {--limit=30 : Quante righe elencare}';

    protected $description = 'Elenca i giocatori con ruolo ipotizzato o identità non verificata';

    public function handle(): int
    {
        $anno = (int) $this->argument('anno');

        $confermati = $this->confermaRuoli($anno);
        $verificati = $this->verificaIdentita();

        if ($confermati || $verificati) {
            $this->info("Confermati {$confermati} ruoli, verificate {$verificati} identità.");
            $this->newLine();
        }

        $this->elenca(
            "Ruolo solo ipotizzato dall'API",
            PlayerSeason::where('season', $anno)->where('active', true)->where('role_confirmed', false),
        );

        $this->elenca(
            'Identità mai verificata a occhio',
            PlayerSeason::where('season', $anno)
                ->where('active', true)
                ->whereHas('player', fn ($q) => $q->where('photo_verified', false)),
        );

        return self::SUCCESS;
    }

    private function confermaRuoli(int $anno): int
    {
        $ids = $this->idsDa('confirm-role');

        return $ids === [] ? 0 : PlayerSeason::where('season', $anno)
            ->whereIn('player_id', $ids)
            ->update(['role_confirmed' => true]);
    }

    private function verificaIdentita(): int
    {
        $ids = $this->idsDa('verify');

        return $ids === [] ? 0 : Player::whereIn('id', $ids)->update(['photo_verified' => true]);
    }

    /** @return list<int> */
    private function idsDa(string $opzione): array
    {
        return array_values(array_filter(array_map('intval', (array) $this->option($opzione))));
    }

    private function elenca(string $titolo, $query): void
    {
        $limite = (int) $this->option('limit');
        $totale = (clone $query)->count();

        $this->info("{$titolo}: {$totale}");

        if ($totale === 0) {
            return;
        }

        $this->table(
            ['id', 'cognome', 'ruolo', 'squadra', 'foto'],
            $query->with(['player', 'team'])->limit($limite)->get()->map(fn (PlayerSeason $p) => [
                $p->player_id,
                $p->player->last_name,
                $p->role->value,
                $p->team?->name ?? '—',
                $p->player->photoUrl(),
            ])->all(),
        );

        if ($totale > $limite) {
            $this->line('  … e altri '.($totale - $limite));
        }

        $this->newLine();
    }
}
