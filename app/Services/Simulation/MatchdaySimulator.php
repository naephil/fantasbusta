<?php

namespace App\Services\Simulation;

use App\Models\Fixture;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Genera statistiche di giornata plausibili, senza chiamare l'API.
 *
 * Serve per collaudare la catena — voti, power, draft, formazioni, classifica —
 * senza consumare il tetto giornaliero di chiamate e senza aspettare che una
 * giornata vera finisca. Con cento chiamate al giorno, provare il ciclo su dati
 * reali costerebbe due giornate di quota per ogni tentativo.
 *
 * ⚠️ Non è un modello di calcio: è un generatore di dati con la forma giusta.
 * Le distribuzioni sono tarate a occhio perché il fantavoto risultante stia
 * nell'intervallo credibile, non perché prevedano qualcosa.
 *
 * I gol però NON sono inventati: si prendono dal risultato vero della partita
 * e si distribuiscono fra i giocatori. Così la simulazione resta coerente con
 * il tabellino, e un 3-0 non produce quattro marcatori.
 */
class MatchdaySimulator
{
    /** Peso con cui un ruolo si prende un gol della squadra. */
    private const PESI_GOL = ['A' => 60, 'C' => 28, 'D' => 10, 'P' => 1];

    /** Gli undici che partono. */
    private const TITOLARI = ['P' => 1, 'D' => 4, 'C' => 4, 'A' => 2];

    /** Quanti si siedono in panchina: la distinta della Serie A ne ammette dodici. */
    private const PANCHINA = 12;

    /** Quanti di quei dodici hanno una possibilità di entrare. */
    private const ENTRANO = 5;

    /**
     * @param  bool  $sovrascriviReali  butta via i voti scaricati, se ce ne sono
     * @return array{partite: int, giocatori: int}
     *
     * @throws RuntimeException quando ci sono già statistiche vere
     */
    public function simulate(int $season, int $matchday, ?int $seed = null, bool $sovrascriviReali = false): array
    {
        // ⚠️ Il simulatore scrive sulla STESSA chiave del sync, quindi qui si
        // cancellerebbero voti veri costati dieci chiamate. E in silenzio: i
        // gol vengono ridistribuiti a partire dal risultato vero, quindi il
        // totale della giornata torna comunque e solo i marcatori cambiano.
        if (! $sovrascriviReali && $this->haStatisticheVere($season, $matchday)) {
            // ⚠️ Il messaggio dice DOVE si forza, non solo che si può.
            //
            // Prima diceva «forza la sovrascrittura se è quello che vuoi» e in
            // tutta l'interfaccia non esisteva un modo per farlo: il motore
            // sapeva accettare il flag, il controller non glielo passava mai.
            // Chi ci sbatteva restava fermo lì, con un consiglio impossibile da
            // seguire.
            //
            // Il caso capita da sé, senza che nessuno sbagli niente: le
            // statistiche vere sopravvivono all'azzeramento di una stagione —
            // costano chiamate e sono condivise fra i gruppi — quindi una
            // giornata scaricata mesi fa resta scaricata anche dopo aver
            // riportato la lega al foglio bianco.
            throw new RuntimeException(
                "La giornata {$matchday} del {$season} ha già le statistiche vere: simularla le cancellerebbe. ".
                'Sono rimaste da uno scarico precedente — l\'azzeramento di una stagione non le tocca, perché '.
                'costano chiamate all\'API e le usano anche gli altri gruppi. '.
                'Aggiorna i voti senza «simula» per usarle, oppure spunta «riscrivi sopra le statistiche vere».',
            );
        }

        mt_srand($seed ?? ($season * 100 + $matchday));

        // ⚠️ Si riparte da zero invece di sovrascrivere, e non è pignoleria:
        // la distinta cambia a ogni simulazione, quindi le righe della volta
        // prima resterebbero lì addosso a gente che stavolta non era nemmeno
        // convocata — con i minuti della simulazione precedente, che il calcolo
        // prenderebbe per buoni. È lo stesso motivo per cui `StatSync` spazza
        // le righe simulate prima di scrivere quelle vere.
        PlayerStat::where('season', $season)->where('matchday', $matchday)->delete();

        $fixtures = Fixture::where('season', $season)->where('matchday', $matchday)->get();
        $giocatori = 0;

        foreach ($fixtures as $fixture) {
            $giocatori += $this->team($fixture->home_team_id, $season, $matchday, $fixture->home_goals ?? $this->golCasuali(), $fixture->away_goals ?? $this->golCasuali());
            $giocatori += $this->team($fixture->away_team_id, $season, $matchday, $fixture->away_goals ?? $this->golCasuali(), $fixture->home_goals ?? $this->golCasuali());
        }

        return ['partite' => $fixtures->count(), 'giocatori' => $giocatori];
    }

    /** Ci sono già voti scaricati per questa giornata? */
    public function haStatisticheVere(int $season, int $matchday): bool
    {
        return PlayerStat::where('season', $season)
            ->where('matchday', $matchday)
            ->where('source', 'reale')
            ->exists();
    }

    /**
     * Una squadra: chi gioca, quanto, e come si dividono i gol.
     *
     * @return int righe scritte
     */
    private function team(int $teamId, int $season, int $matchday, int $fatti, int $subiti): int
    {
        $rosa = PlayerSeason::with('player')
            ->where('season', $season)
            ->where('team_id', $teamId)
            ->where('active', true)
            ->get();

        if ($rosa->isEmpty()) {
            return 0;
        }

        $convocati = $this->convocati($rosa);
        $marcatori = $this->distribuisciGol($convocati['titolari'], $fatti);
        $assist = $this->distribuisciGol($convocati['titolari'], max(0, $fatti - mt_rand(0, $fatti)));

        $scritte = 0;
        $adesso = now();
        $righe = [];

        // Solo chi è in distinta, non tutta la rosa: vedi convocati().
        foreach ($convocati['distinta'] as $riga) {
            $minuti = $this->minuti($riga, $convocati);
            $ruolo = $riga->role->value;

            $gol = $marcatori[$riga->player_id] ?? 0;

            // Il rigore è una frazione dei gol, non un evento a parte: contarlo
            // due volte è l'errore che lo scorporo in PlayerStat previene.
            $rigori = $gol > 0 && mt_rand(1, 100) <= 12 ? 1 : 0;

            $righe[] = [
                'player_id' => $riga->player_id,
                'season' => $season,
                'matchday' => $matchday,
                'source' => 'simulata',
                'created_at' => $adesso,
                'updated_at' => $adesso,
                'minutes' => $minuti,
                'rating' => $minuti > 0 ? $this->rating($gol, $assist[$riga->player_id] ?? 0, $ruolo, $subiti) : null,
                'goals' => $gol,
                'assists' => $assist[$riga->player_id] ?? 0,
                'goals_conceded' => $ruolo === 'P' && $minuti > 0 ? $subiti : 0,
                'yellow' => $minuti > 0 && mt_rand(1, 100) <= 18 ? 1 : 0,
                'red' => $minuti > 0 && mt_rand(1, 200) === 1 ? 1 : 0,
                'own_goals' => $minuti > 0 && mt_rand(1, 400) === 1 ? 1 : 0,
                'pen_scored' => $rigori,
                'pen_missed' => $minuti > 0 && mt_rand(1, 300) === 1 ? 1 : 0,
                'pen_saved' => $ruolo === 'P' && $minuti > 0 && mt_rand(1, 120) === 1 ? 1 : 0,
            ];

            $scritte++;
        }

        // In blocco: una distinta sono ~23 righe, venti squadre quasi cinque
        // centinaia, e scriverle una per una raddoppiava il tempo di una
        // giornata simulata.
        foreach (array_chunk($righe, 500) as $blocco) {
            PlayerStat::upsert($blocco, ['player_id', 'season', 'matchday'], [
                'minutes', 'rating', 'goals', 'assists', 'goals_conceded',
                'yellow', 'red', 'own_goals', 'pen_scored', 'pen_missed', 'pen_saved',
                'source', 'updated_at',
            ]);
        }

        return $scritte;
    }

    /**
     * La distinta: gli undici, la panchina, e chi di quella panchina entrerà.
     *
     * ⚠️ Le statistiche si scrivono per i CONVOCATI, non per tutta la rosa, ed
     * è la forma che ha un tabellino vero: l'API riporta chi era in distinta e
     * nessun altro. Scrivere anche gli altri costava due cose. La prima si
     * vedeva: la schermata della giornata elencava sessanta nomi a squadra —
     * l'organico al completo, terzi portieri e primavera compresi — invece dei
     * ventitré che sono scesi in campo o si sono seduti in panchina. La seconda
     * no: quelle righe di troppo sopravvivevano all'aggiornamento coi dati
     * veri, ed è il motivo per cui `StatSync` deve spazzarle via prima.
     *
     * La formazione si sceglie per quotazione: i più cari giocano, ed è
     * l'approssimazione più onesta di come si schiera una squadra vera.
     * Serve anche a rendere la simulazione utile — se giocassero a caso, la
     * titolarità nel power score sarebbe rumore.
     *
     * @param  Collection<int,PlayerSeason>  $rosa
     * @return array{
     *     titolari: Collection<int,PlayerSeason>,
     *     subentrati: Collection<int,PlayerSeason>,
     *     distinta: Collection<int,PlayerSeason>,
     * }
     */
    private function convocati(Collection $rosa): array
    {
        $perRuolo = $rosa->sortByDesc('quotazione_iniziale')->groupBy(fn (PlayerSeason $p) => $p->role->value);

        $titolari = collect(self::TITOLARI)
            ->flatMap(fn (int $n, string $ruolo) => ($perRuolo[$ruolo] ?? collect())->take($n))
            ->values();

        $panchina = $rosa
            ->reject(fn (PlayerSeason $p) => $titolari->contains('id', $p->id))
            ->sortByDesc('quotazione_iniziale')
            ->take(self::PANCHINA)
            ->values();

        return [
            'titolari' => $titolari,
            // Entra solo la prima parte della panchina: gli altri restano seduti
            // per tutta la partita, ma nel tabellino ci sono — è la differenza
            // fra un panchinaro e uno rimasto a casa, e finora non esisteva.
            'subentrati' => $panchina->take(self::ENTRANO),
            'distinta' => $titolari->concat($panchina),
        ];
    }

    /** @param  array{titolari: Collection, subentrati: Collection}  $convocati */
    private function minuti(PlayerSeason $player, array $convocati): int
    {
        if ($convocati['titolari']->contains('id', $player->id)) {
            // Un titolare su tre esce prima della fine.
            return mt_rand(1, 3) === 1 ? mt_rand(55, 85) : 90;
        }

        if ($convocati['subentrati']->contains('id', $player->id)) {
            return mt_rand(1, 100) <= 70 ? mt_rand(5, 35) : 0;
        }

        return 0;   // panchinaro mai entrato: in distinta, ma senza voto
    }

    /**
     * Spartisce i gol della squadra fra i titolari, pesati per ruolo.
     *
     * @param  Collection<int,PlayerSeason>  $titolari
     * @return array<int,int> player_id => gol
     */
    private function distribuisciGol(Collection $titolari, int $quanti): array
    {
        if ($quanti <= 0 || $titolari->isEmpty()) {
            return [];
        }

        $urna = [];

        foreach ($titolari as $player) {
            $peso = self::PESI_GOL[$player->role->value] ?? 1;

            for ($i = 0; $i < $peso; $i++) {
                $urna[] = $player->player_id;
            }
        }

        $esito = [];

        for ($i = 0; $i < $quanti; $i++) {
            $scelto = $urna[mt_rand(0, count($urna) - 1)];
            $esito[$scelto] = ($esito[$scelto] ?? 0) + 1;
        }

        return $esito;
    }

    /**
     * Il rating, sulla scala compressa dell'API vera.
     *
     * Deliberatamente stretto attorno al 6,3: è così che arrivano i rating
     * veri, ed è la ragione per cui il voto base ha una molla che li allarga.
     * Simulare una forbice larga farebbe sembrare inutile quella molla.
     */
    private function rating(int $gol, int $assist, string $ruolo, int $subiti): float
    {
        $base = 6.3 + (mt_rand(-70, 90) / 100);

        $base += $gol * 0.55 + $assist * 0.25;

        if ($ruolo === 'P') {
            $base += $subiti === 0 ? 0.4 : -0.15 * $subiti;
        }

        return round(max(4.0, min(9.5, $base)), 1);
    }

    private function golCasuali(): int
    {
        return match (mt_rand(1, 100)) {
            1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25 => 0,
            default => mt_rand(1, 3),
        };
    }
}
