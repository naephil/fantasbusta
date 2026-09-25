<?php

namespace App\Http\Controllers;

use App\Models\Lineup;
use App\Models\PlayerPower;
use App\Services\Stats\DraftHistory;
use App\Services\Stats\MarketMovers;
use App\Services\Stats\MatchdayMvp;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Le statistiche che si guardano per il gusto di guardarle.
 *
 * Tutte e tre girano su dati già scritti da altri pezzi: il movimento di
 * valutazione da `player_power.rank_delta`, lo storico delle pescate da
 * `cards.original_owner_id`, il migliore in campo da `lineup_results`.
 * Nessuna tabella nuova.
 */
class StatsController extends Controller
{
    public function __construct(
        private MarketMovers $movers,
        private DraftHistory $storico,
        private MatchdayMvp $mvp,
    ) {}

    public function index(Request $request): View
    {
        $stagione = $this->stagioneOAbort($request->user());
        $season = $stagione->season;

        // Due giornate diverse, e non è una svista: il movimento di valutazione
        // guarda avanti (il power della giornata per cui si pescherà), il
        // migliore in campo guarda indietro (l'ultima giornata calcolata).
        $giornataPower = $request->integer('power') ?: PlayerPower::where('league_season_id', $stagione->id)->max('matchday');
        $giornataMvp = $request->integer('mvp') ?: Lineup::where('league_season_id', $stagione->id)
            ->whereHas('result')
            ->max('matchday');

        $movimenti = $giornataPower
            ? $this->movers->forMatchday($stagione, $giornataPower)
            : ['salgono' => collect(), 'scendono' => collect()];

        return view('stats.index', [
            'stagione' => $stagione,
            'giornataPower' => $giornataPower,
            'giornataMvp' => $giornataMvp,
            'salgono' => $movimenti['salgono'],
            'scendono' => $movimenti['scendono'],
            'cambiFascia' => $giornataPower ? $this->movers->tierChanges($stagione, $giornataPower) : collect(),
            'mvp' => $giornataMvp ? $this->mvp->forMatchday($stagione, $giornataMvp) : collect(),
            'affinita' => $this->storico->affinities($stagione),
            'piuPescati' => $this->storico->mostDrawn($stagione),
        ]);
    }
}
