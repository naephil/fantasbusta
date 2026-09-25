<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\PlayerPower;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Perché in rosa finiscono giocatori che non giocano mai.
 *
 * È la domanda che nessun test sa porre, perché la risposta non sta nel codice
 * ma nei dati: il draft pesca dal power score, il power score si fida del
 * listone e delle statistiche, e se una di quelle due è monca la piramide
 * ordina il nulla. In quel caso il pescaggio funziona benissimo e distribuisce
 * carte a caso — che dall'interno è indistinguibile da un pescaggio rotto.
 *
 * Le tre cause vere, in ordine di frequenza:
 *
 *   1. Il listone non ha agganciato quasi nessuno. Ruolo indovinato e
 *      quotazione a 1 per tutti: il power non ha niente su cui ordinare.
 *   2. Mancano le statistiche delle giornate precedenti. Fantamedia, forma e
 *      titolarità valgono zero per chiunque, e resta solo la quotazione.
 *   3. È la prima o la seconda giornata, e allora è normale: il power guarda
 *      fino a `giornata − 2`, che non esiste ancora.
 */
class DiagnosticaDraft extends Command
{
    protected $signature = 'diagnostica:draft
        {--stagione= : Id della stagione di lega; altrimenti quella in corso}
        {--giornata= : La giornata da esaminare; altrimenti l\'ultima con carte}';

    protected $description = 'Dice perché le rose sono composte come sono';

    /** Da quanti minuti in su si considera che uno abbia giocato davvero. */
    private const MINUTI_TITOLARE = 60;

    public function handle(): int
    {
        $stagione = $this->stagione();

        if (! $stagione) {
            $this->error('Nessuna stagione da esaminare.');

            return self::FAILURE;
        }

        $giornata = (int) ($this->option('giornata')
            ?: Card::where('league_season_id', $stagione->id)->max('matchday'));

        if (! $giornata) {
            $this->error('Questa stagione non ha ancora nessuna carta.');

            return self::FAILURE;
        }

        $this->info("Stagione {$stagione->etichetta()} · giornata {$giornata}");

        $this->listone($stagione->season);
        $this->statistiche($stagione->season, $giornata);
        $this->power($stagione, $giornata);
        $this->rose($stagione, $giornata);

        return self::SUCCESS;
    }

    /**
     * Il listone ha agganciato i giocatori, o è rimasto tutto indovinato?
     *
     * È la prima cosa da guardare: senza quotazioni vere il power si riduce a
     * fantamedia e forma, e alla prima giornata nemmeno a quelle.
     */
    private function listone(int $season): void
    {
        $this->titolo('Listone');

        $tutti = PlayerSeason::where('season', $season)->where('active', true);
        $totale = (clone $tutti)->count();

        if ($totale === 0) {
            $this->riga('✗', "Nessun giocatore attivo per l'annata {$season}: l'anagrafica non è stata caricata.");

            return;
        }

        $confermati = (clone $tutti)->where('role_confirmed', true)->count();
        $aUno = (clone $tutti)->where('quotazione_iniziale', '<=', 1)->count();

        // ⚠️ Anagrafica e listone non sono la stessa cosa: API-Football
        // restituisce le rose REGISTRATE — primavera, ceduti, mai convocati —
        // e su un'annata vera fanno il doppio delle righe del listone. È un
        // fatto, non un guasto: conta solo che il draft peschi dal secondo.
        $this->line("    anagrafica dell'annata: {$totale} giocatori");
        $this->line("    di cui nel listone:     {$confermati}");

        $this->riga($confermati >= 400 ? '✓' : '✗', sprintf(
            'il draft pesca da questi %d (%.0f%% dell\'anagrafica)',
            $confermati, 100 * $confermati / $totale,
        ));

        $this->riga($aUno < $confermati * 0.1 ? '✓' : '!', sprintf(
            'quotazione ancora a 1: %d su %d in anagrafica',
            $aUno, $totale,
        ));

        if ($confermati < 400) {
            $this->line('    → il listone ha agganciato pochi giocatori: rilancia l\'import');
            $this->line('      e guarda quanti finiscono fra «mancanti». Quasi sempre è');
            $this->line('      l\'annata sbagliata, o l\'anagrafica non ancora scaricata.');
        }
    }

    /** Le statistiche su cui il power si basa ci sono davvero? */
    private function statistiche(int $season, int $giornata): void
    {
        $this->titolo('Statistiche delle giornate precedenti');

        // ⚠️ Il power della giornata N guarda fino a N−2, non a N−1: quando il
        // draft si apre, la giornata precedente è ancora in corso.
        $fino = $giornata - 2;

        if ($fino < 1) {
            $this->riga('!', "La giornata {$giornata} guarda indietro fino alla ".max(0, $fino).'ª: non c\'è storia.');
            $this->line('    → è normale alle prime due giornate. Il power si regge solo sulla');
            $this->line('      quotazione, quindi il listone è l\'unica cosa che conta.');

            return;
        }

        $conMinuti = PlayerStat::where('season', $season)
            ->whereBetween('matchday', [1, $fino])
            ->where('minutes', '>', 0)
            ->distinct()
            ->count('player_id');

        $this->riga($conMinuti > 200 ? '✓' : '✗', sprintf(
            'giocatori con almeno un minuto fino alla %dª: %d',
            $fino, $conMinuti,
        ));

        foreach (range(1, $fino) as $g) {
            $n = PlayerStat::where('season', $season)->where('matchday', $g)->count();
            $this->line(sprintf('    %2dª giornata: %4d righe%s', $g, $n, $n === 0 ? '   ← vuota' : ''));
        }

        if ($conMinuti <= 200) {
            $this->line('    → senza statistiche, fantamedia forma e titolarità valgono zero per tutti');
            $this->line('      e il power si riduce alla sola quotazione.');
        }
    }

    /** Il power distingue qualcuno, o sono tutti uguali? */
    private function power(LeagueSeason $stagione, int $giornata): void
    {
        $this->titolo('Power score');

        // ⚠️ Solo i giocatori del listone: sono l'unica popolazione che il
        // draft guarda, e contare anche gli altri direbbe che il power «non
        // distingue» quando invece non distingue chi non è in gioco — che è
        // esattamente quello che deve fare.
        $nelListone = PlayerSeason::where('season', $stagione->season)
            ->where('role_confirmed', true)
            ->pluck('player_id');

        $valori = PlayerPower::where('league_season_id', $stagione->id)
            ->where('matchday', $giornata)
            ->whereIn('player_id', $nelListone)
            ->pluck('power');

        if ($valori->isEmpty()) {
            $this->riga('✗', "Nessun power di listone per la giornata {$giornata}: lancia `power:compute {$giornata}`.");

            return;
        }

        $distinti = $valori->unique()->count();

        $this->riga($distinti > $valori->count() * 0.8 ? '✓' : '✗', sprintf(
            'fra i %d del listone, valori distinti: %d · da %.3f a %.3f',
            $valori->count(), $distinti, $valori->min(), $valori->max(),
        ));

        if ($distinti <= $valori->count() * 0.8) {
            $this->line('    → troppi giocatori del listone con lo stesso power: la classifica');
            $this->line('      la decide lo spareggio sull\'id, quindi «chi è Leggendaria» è');
            $this->line('      sorteggiato. Di solito vuol dire quotazioni rimaste a 1.');
        }

        // Le righe di power per chi nel listone non c'è sono resti di un
        // calcolo fatto prima della restrizione: non fanno danno, ma sapere che
        // ci sono spiega perché i conteggi di prima non tornavano.
        $tutte = PlayerPower::where('league_season_id', $stagione->id)
            ->where('matchday', $giornata)
            ->count();

        if ($tutte > $valori->count()) {
            $this->line(sprintf(
                '    (%d righe di power in tutto: %d sono di giocatori fuori dal listone,',
                $tutte, $tutte - $valori->count(),
            ));
            $this->line('     avanzi di un calcolo precedente. `power:compute` le lascia dov\'erano.)');
        }
    }

    /**
     * La domanda vera: in rosa ci sono giocatori che scendono in campo?
     */
    private function rose(LeagueSeason $stagione, int $giornata): void
    {
        $this->titolo('Chi è finito in rosa');

        // Chi ha davvero giocato quella giornata, secondo i tabellini.
        $inCampo = PlayerStat::where('season', $stagione->season)
            ->where('matchday', $giornata)
            ->where('minutes', '>=', self::MINUTI_TITOLARE)
            ->pluck('player_id');

        if ($inCampo->isEmpty()) {
            // La giornata delle carte è quella del prossimo draft, e quasi
            // sempre non è ancora stata giocata: si ripiega sull'ultima chiusa
            // invece di rimandare a un altro comando.
            $ultima = PlayerStat::where('season', $stagione->season)
                ->where('minutes', '>=', self::MINUTI_TITOLARE)
                ->max('matchday');

            if (! $ultima) {
                $this->riga('!', 'Nessuna giornata con statistiche: non c\'è ancora niente da confrontare.');

                return;
            }

            $this->riga('!', "La giornata {$giornata} non è ancora stata giocata: guardo la {$ultima}ª.");
            $this->newLine();

            $this->rose($stagione, (int) $ultima);

            return;
        }

        $carte = Card::where('league_season_id', $stagione->id)
            ->where('matchday', $giornata)
            ->get(['player_id', 'owner_manager_id', 'tier', 'role']);

        $posseduti = $carte->pluck('player_id')->unique();
        $titolariPosseduti = $posseduti->intersect($inCampo)->count();

        $this->cartaceoVecchio($stagione, $carte);

        $this->riga($titolariPosseduti > $inCampo->count() * 0.6 ? '✓' : '✗', sprintf(
            'titolari veri finiti in rosa: %d su %d (%.0f%%) — ne restano liberi %d',
            $titolariPosseduti,
            $inCampo->count(),
            100 * $titolariPosseduti / $inCampo->count(),
            $inCampo->count() - $titolariPosseduti,
        ));

        $this->newLine();
        $this->line('  Per manager — quante delle sue carte hanno giocato quella giornata:');

        $nomi = Manager::whereIn('id', $carte->pluck('owner_manager_id')->unique())->pluck('name', 'id');

        foreach ($carte->groupBy('owner_manager_id') as $managerId => $sue) {
            $hannoGiocato = $sue->pluck('player_id')->intersect($inCampo)->count();

            $this->line(sprintf(
                '    %-22s %2d / %2d',
                $nomi[$managerId] ?? "#{$managerId}",
                $hannoGiocato,
                $sue->count(),
            ));
        }

        // I nomi che stanno fuori: è il modo più rapido di riconoscere se il
        // problema è la piramide o l'aggancio del listone.
        // ⚠️ Le colonne vanno qualificate: dopo il join `player_id` e `season`
        // esistono in tutte e due le tabelle, e MariaDB si ferma con «Column
        // is ambiguous» invece di sceglierne una.
        $liberi = PlayerSeason::with('player')
            ->join('player_power', function ($j) use ($stagione, $giornata) {
                $j->on('player_power.player_id', '=', 'player_seasons.player_id')
                    ->where('player_power.league_season_id', '=', $stagione->id)
                    ->where('player_power.matchday', '=', $giornata);
            })
            ->where('player_seasons.season', $stagione->season)
            ->whereIn('player_seasons.player_id', $inCampo->diff($posseduti))
            ->orderBy('player_power.rank')
            ->limit(30)
            ->get(['player_seasons.*', 'player_power.rank', 'player_power.tier']);

        if ($liberi->isEmpty()) {
            return;
        }

        // ⚠️ Divisi in due, e la divisione è tutto il punto. Un titolare fuori
        // dal listone NON è una mancata del draft: nel fantacalcio non esiste,
        // quindi non poteva essere pescato — gioca e prende voti, ma non è in
        // gioco. Mescolarlo con le mancate vere fa leggere come guasto quello
        // che è il comportamento corretto, e manda a cercare un difetto che non
        // c'è. Solo il primo elenco è un problema.
        [$dentro, $fuori] = $liberi->partition(fn ($r) => (bool) $r->role_confirmed);

        if ($dentro->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=red>Nel listone ma rimasti liberi</> — queste sono mancate vere:');
            $this->elencaLiberi($dentro);
        }

        if ($fuori->isNotEmpty()) {
            $this->newLine();
            $this->line('  <fg=gray>Fuori dal listone</> — giocano ma non sono in gioco, quindi è giusto');
            $this->line('  <fg=gray>che nessuno li abbia: prestiti, trasferimenti, mai quotati.</>');
            $this->elencaLiberi($fuori);
        }
    }

    /** @param  Collection<int,PlayerSeason>  $righe */
    private function elencaLiberi(Collection $righe): void
    {
        foreach ($righe as $riga) {
            $this->line(sprintf(
                '    %-24s %s  q.%-6s rank %-5d %s',
                $riga->player->last_name,
                $riga->role->value,
                rtrim(rtrim(number_format((float) $riga->quotazione_iniziale, 1), '0'), '.'),
                $riga->rank,
                $riga->tier,
            ));
        }
    }

    /** La stagione chiesta, o quella che si sta giocando. */
    private function stagione(): ?LeagueSeason
    {
        if ($id = $this->option('stagione')) {
            return LeagueSeason::find((int) $id);
        }

        return LeagueSeason::where('state', 'in_corso')->orderByDesc('season')->first()
            ?? LeagueSeason::orderByDesc('season')->first();
    }

    /**
     * Questo draft è stato costruito prima che il pool si restringesse?
     *
     * ⚠️ È la domanda che evita l'equivoco più costoso di tutti. Le carte sono
     * **congelate alla pescata**: correggere il pool non le ritocca, quindi una
     * misura fatta su un draft vecchio racconta il difetto di prima e sembra
     * dire che la correzione non ha funzionato. Il segno è inequivocabile —
     * carte di giocatori che nel listone non ci sono proprio non possono
     * uscire da un pool ristretto al listone.
     *
     * @param  Collection<int,Card>  $carte
     */
    private function cartaceoVecchio(LeagueSeason $stagione, $carte): void
    {
        $nelListone = PlayerSeason::where('season', $stagione->season)
            ->where('role_confirmed', true)
            ->pluck('player_id');

        $fuoriListone = $carte->pluck('player_id')->unique()->diff($nelListone);

        if ($fuoriListone->isEmpty()) {
            return;
        }

        $this->riga('!', sprintf(
            '%d carte su %d sono di giocatori fuori dal listone.',
            $fuoriListone->count(), $carte->count(),
        ));

        $this->line('    → questo draft è stato pescato PRIMA che il pool si restringesse al');
        $this->line('      listone, e le carte non si ritoccano: restano quelle. I numeri qui');
        $this->line('      sotto raccontano il difetto vecchio, non l\'effetto della correzione.');
        $this->line('      Si vedrà dal prossimo draft — o rigenerando questo, se la giornata');
        $this->line('      non è ancora stata giocata.');
        $this->newLine();
    }

    private function titolo(string $testo): void
    {
        $this->newLine();
        $this->line("  <fg=gray>── {$testo}</>");
    }

    private function riga(string $segno, string $testo): void
    {
        $colore = match ($segno) {
            '✓' => 'green',
            '✗' => 'red',
            default => 'yellow',
        };

        $this->line("  <fg={$colore}>{$segno}</> {$testo}");
    }
}
