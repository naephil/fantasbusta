<?php

namespace App\Services\Power;

use App\Enums\Tier;
use App\Models\LeagueSeason;
use App\Models\PlayerPower;
use App\Models\PlayerScore;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Services\Scoring\Settings;
use Illuminate\Support\Collection;

/**
 * Ricalcola power score e tier di tutto il listone per una giornata.
 *
 * ⚠️ `matchday` è la giornata PER CUI vale il power, non quella da cui deriva.
 * Il draft della giornata N+1 apre al primo fischio della giornata N, quando N
 * è ancora in corso: i dati completi arrivano fino a N−1. Per questo il calcolo
 * guarda indietro fino a `matchday − 2` e non a `matchday − 1`. Sbagliare di
 * uno qui significa pescare conoscendo risultati che non si dovrebbero sapere.
 *
 * Il power si scrive per stagione di lega: i pesi si tarano per gruppo, e una
 * lega che dà molto peso alla forma vedrà salire di tier carte che per un'altra
 * restano Comuni. I dati in ingresso — minuti e statistiche — sono invece
 * condivisi, perché quelli sono fatti.
 */
class PowerUpdater
{
    /** @return int giocatori valutati */
    public function update(LeagueSeason $stagione, int $matchday): int
    {
        $settings = Settings::for($stagione);
        $formula = new PowerFormula($settings);
        $season = $stagione->season;

        $rose = $this->inGioco($season);

        if ($rose->isEmpty()) {
            return 0;
        }

        $componenti = $this->components($rose, $season, $stagione->id, $matchday - 2, $settings);

        $powers = $rose->mapWithKeys(fn (PlayerSeason $r) => [
            $r->player_id => $formula->apply($componenti[$r->player_id]),
        ]);

        $ruoli = $rose->mapWithKeys(fn (PlayerSeason $r) => [$r->player_id => $r->role->value])->all();

        return $this->persist($powers, $ruoli, $stagione->id, $matchday, $settings);
    }

    /**
     * Chi è davvero in gioco quell'anno: il listone, non l'anagrafica.
     *
     * ⚠️ Non sono la stessa cosa, e su un'annata vera la differenza è il doppio.
     * API-Football restituisce le rose REGISTRATE — primavera, ceduti a gennaio,
     * terzi portieri mai convocati — e `StatSync` ne aggiunge altri dai
     * tabellini: si arriva a un migliaio di righe contro le ~540 del listone.
     *
     * Tenerli dentro rovinava il power in due modi che si sommano. Il primo è
     * che sono tutti in parità: senza quotazione (restano a 1) e senza minuti,
     * il punteggio viene zero per tutti e la classifica fra pari la decide l'id.
     * Il secondo è peggio ed è silenzioso: i tier sono PERCENTILI, quindi
     * seicento zeri in fondo alla graduatoria spingono in alto tutti gli altri —
     * il «top 3%» diventava il top 3% di una popolazione doppia di quella vera,
     * e la piramide misurava una cosa diversa da quella che dichiarava.
     *
     * Il ripiego su tutti serve solo prima che il listone sia stato importato:
     * lì la piramide non vale niente comunque, ma senza un power il draft non si
     * può nemmeno costruire. `diagnostica:draft` e `controlla` lo segnalano.
     *
     * @return Collection<int,PlayerSeason>
     */
    private function inGioco(int $season): Collection
    {
        $base = PlayerSeason::where('season', $season)->where('active', true);

        $listone = (clone $base)->where('role_confirmed', true)->get();

        return $listone->isNotEmpty() ? $listone : $base->get();
    }

    /**
     * Le cinque componenti, già normalizzate, per ogni giocatore.
     *
     * @param  Collection<int,PlayerSeason>  $rose
     * @return array<int,array<string,float>>
     */
    private function components(Collection $rose, int $season, int $leagueSeasonId, int $upTo, Settings $settings): array
    {
        $ids = $rose->pluck('player_id')->all();

        // La quotazione si normalizza DENTRO il ruolo: confrontata in assoluto
        // direbbe soltanto che gli attaccanti costano più dei portieri.
        $baseline = Normalizer::minMaxByGroup(
            $rose->mapWithKeys(fn (PlayerSeason $r) => [$r->player_id => (float) $r->quotazione_iniziale])->all(),
            $rose->mapWithKeys(fn (PlayerSeason $r) => [$r->player_id => $r->role->value])->all(),
        );

        $fantamedia = Normalizer::minMax($this->fill($ids, $this->avgFantavoto($leagueSeasonId, 1, $upTo)));
        $forma = Normalizer::minMax($this->fill(
            $ids,
            $this->avgFantavoto($leagueSeasonId, $upTo - $settings->giornateForma() + 1, $upTo),
        ));

        // Titolarità e rischio sono già quote 0..1: normalizzarle di nuovo
        // le stirerebbe sugli estremi della popolazione e farebbe sembrare
        // titolarissimo il meno peggio di una giornata in cui non gioca nessuno.
        $titolarita = $this->titolarita($ids, $season, $upTo);
        $rischio = $this->rischio($ids, $season, $upTo, $settings->giornateForma());

        return collect($ids)->mapWithKeys(fn (int $id) => [$id => [
            'baseline' => $baseline[$id] ?? 0.0,
            'fantamedia' => $fantamedia[$id] ?? 0.0,
            'forma' => $forma[$id] ?? 0.0,
            'titolarita' => $titolarita[$id] ?? 0.0,
            'rischio' => $rischio[$id] ?? 0.0,
        ]])->all();
    }

    /**
     * Fantamedia sull'intervallo di giornate, saltando i senza voto.
     *
     * @return array<int,float>
     */
    private function avgFantavoto(int $leagueSeasonId, int $from, int $to): array
    {
        if ($to < 1) {
            return [];
        }

        return PlayerScore::where('league_season_id', $leagueSeasonId)
            ->whereBetween('matchday', [max(1, $from), $to])
            ->whereNotNull('fantavoto')
            ->selectRaw('player_id, avg(fantavoto) as media')
            ->groupBy('player_id')
            ->pluck('media', 'player_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Quota di minuti giocati sul totale disponibile.
     *
     * @return array<int,float>
     */
    private function titolarita(array $ids, int $season, int $upTo): array
    {
        if ($upTo < 1) {
            return array_fill_keys($ids, 0.0);
        }

        $minuti = PlayerStat::where('season', $season)
            ->where('matchday', '<=', $upTo)
            ->selectRaw('player_id, sum(minutes) as m')
            ->groupBy('player_id')
            ->pluck('m', 'player_id');

        $massimo = 90 * $upTo;

        return collect($ids)->mapWithKeys(fn (int $id) => [
            $id => min(1.0, ((float) ($minuti[$id] ?? 0)) / $massimo),
        ])->all();
    }

    /**
     * Assenze recenti, come approssimazione di infortuni e squalifiche.
     *
     * Non esiste una fonte di infortuni fra i dati che abbiamo, quindi il
     * rischio si deduce da chi non è sceso in campo nelle ultime giornate. È
     * un indizio, non un referto: un panchinaro tecnico e un infortunato si
     * assomigliano molto visti da qui.
     *
     * Distinto dalla titolarità, che guarda tutta la stagione: qui conta solo
     * la finestra recente, perché è lì che vive il segnale di infortunio.
     *
     * @return array<int,float>
     */
    private function rischio(array $ids, int $season, int $upTo, int $finestra): array
    {
        if ($upTo < 1) {
            return array_fill_keys($ids, 0.0);
        }

        $finestra = max(1, min($finestra, $upTo));

        $presenze = PlayerStat::where('season', $season)
            ->whereBetween('matchday', [$upTo - $finestra + 1, $upTo])
            ->where('minutes', '>', 0)
            ->selectRaw('player_id, count(*) as n')
            ->groupBy('player_id')
            ->pluck('n', 'player_id');

        // Un rosso all'ultima giornata utile è una squalifica quasi certa.
        $espulsi = PlayerStat::where('season', $season)
            ->where('matchday', $upTo)
            ->where('red', '>', 0)
            ->pluck('player_id')
            ->flip();

        return collect($ids)->mapWithKeys(fn (int $id) => [
            $id => min(1.0, 1 - ((int) ($presenze[$id] ?? 0)) / $finestra + ($espulsi->has($id) ? 0.5 : 0)),
        ])->all();
    }

    /**
     * Classifica, tier per percentile, e le pastiglie di trend.
     *
     * ⚠️ Due classifiche diverse, e la distinzione è il punto:
     *
     *   `rank`  è GLOBALE — la posizione nel listone intero. Serve ai movimenti
     *           di mercato, che sono una graduatoria di lega: «Lautaro ha
     *           scavalcato dodici giocatori» ha senso solo fra tutti.
     *   `tier`  è DENTRO IL RUOLO. Assegnato in globale, la piramide diventa
     *           quasi solo attaccanti e centrocampisti — sono loro ad accumulare
     *           fantapunti — e il miglior portiere del campionato esce Comune.
     *           Il risultato è che i big di mezzo campionato restano liberi al
     *           draft perché nessuna busta li propone mai come carta pregiata.
     *
     * È la stessa scelta già fatta per la quotazione in components(), che si
     * normalizza per ruolo perché in assoluto direbbe soltanto che gli
     * attaccanti costano più dei portieri. Vale identico per la rarità.
     *
     * @param  Collection<int,float>  $powers
     * @param  array<int,string>  $ruoli  player_id => ruolo
     */
    private function persist(
        Collection $powers,
        array $ruoli,
        int $leagueSeasonId,
        int $matchday,
        Settings $settings,
    ): int {
        $precedenti = PlayerPower::where('league_season_id', $leagueSeasonId)
            ->where('matchday', $matchday - 1)
            ->get()
            ->keyBy('player_id');

        // A parità di power decide l'id: senza un criterio secondario la
        // classifica ballerebbe a ogni ricalcolo e i rank_delta sarebbero rumore.
        $ordinati = $powers
            ->map(fn (float $power, int $id) => ['player_id' => $id, 'power' => $power])
            ->sortBy([['power', 'desc'], ['player_id', 'asc']])
            ->values();

        $totale = $ordinati->count();
        $tierDi = $this->tierPerRuolo($ordinati, $ruoli, $settings);
        $adesso = now();
        $righe = [];

        foreach ($ordinati as $i => $riga) {
            $rank = $i + 1;
            $tier = $tierDi[$riga['player_id']];
            $prima = $precedenti->get($riga['player_id']);

            $righe[] = [
                'league_season_id' => $leagueSeasonId,
                'player_id' => $riga['player_id'],
                'matchday' => $matchday,
                'power' => $riga['power'],
                'tier' => $tier,
                'rank' => $rank,
                // Positivo = posizioni guadagnate.
                'rank_delta' => $prima ? $prima->rank - $rank : null,
                'tier_changed' => $prima ? $prima->tier !== $tier : false,
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ];
        }

        // A blocchi: il listone è di ~1100 righe e un solo INSERT gigante
        // sbatterebbe contro il limite di segnaposti del driver.
        foreach (array_chunk($righe, 500) as $blocco) {
            PlayerPower::upsert(
                $blocco,
                ['league_season_id', 'player_id', 'matchday'],
                ['power', 'tier', 'rank', 'rank_delta', 'tier_changed', 'updated_at'],
            );
        }

        return $totale;
    }

    /**
     * La rarità di ciascuno, contata dentro il proprio reparto.
     *
     * Ogni ruolo ha la sua piramide completa, quindi il miglior portiere è
     * Leggendario fra i portieri anche se in classifica generale sta
     * duecentesimo — ed è giusto così, perché al draft un portiere si sceglie
     * fra portieri, non contro un attaccante.
     *
     * ⚠️ Prima però si toglie il fondo, e senza questo passaggio la piramide
     * mente. Il listone vero porta centinaia di giocatori a quotazione 1 che non
     * hanno mai giocato — terzi portieri, primavera, ceduti a gennaio — e
     * contati nei percentili occupavano tutta la fascia Comune spingendo in
     * alto tutti gli altri: metà listone usciva Rara senza che nessuna di
     * quelle carte fosse migliorata di un punto.
     *
     * Le quote della piramide sono quindi percentuali di CHI È IN GIOCO, non
     * del listone intero. Chi sta sotto soglia prende una fascia sua — Pacco o
     * Monnezza — e non entra nel conto di nessuno.
     *
     * @param  Collection<int,array{player_id:int,power:float}>  $ordinati  già in ordine di power
     * @param  array<int,string>  $ruoli
     * @return array<int,string> player_id => tier
     */
    private function tierPerRuolo(Collection $ordinati, array $ruoli, Settings $settings): array
    {
        $pacco = $settings->sogliaPacco();
        $monnezza = $settings->sogliaMonnezza();

        $tier = [];

        // `groupBy` conserva l'ordine di arrivo, e $ordinati è già ordinato per
        // power: dentro ogni gruppo la posizione è quindi già quella giusta.
        foreach ($ordinati->groupBy(fn (array $r) => $ruoli[$r['player_id']] ?? 'C') as $righe) {
            $inGioco = [];

            foreach ($righe as $riga) {
                $fondo = Tier::sottoSoglia($riga['power'], $pacco, $monnezza);

                if ($fondo !== null) {
                    $tier[$riga['player_id']] = $fondo->value;

                    continue;
                }

                $inGioco[] = $riga;
            }

            // I percentili si contano qui dentro, su chi è rimasto: è la
            // popolazione di cui la piramide vuole parlare.
            $quanti = count($inGioco);

            foreach ($inGioco as $i => $riga) {
                $tier[$riga['player_id']] = TierAssigner::forRank($i + 1, $quanti);
            }
        }

        return $tier;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int,float>  $values
     * @return array<int,float>
     */
    private function fill(array $ids, array $values): array
    {
        return array_replace(array_fill_keys($ids, 0.0), $values);
    }
}
