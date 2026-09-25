<?php

namespace App\Http\Controllers;

use App\Models\Matchup;
use App\Models\Standing;
use App\Services\Scoring\Settings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La classifica e le sfide della giornata.
 *
 * `standings` è una fotografia per giornata, non un totale che si sovrascrive:
 * si può quindi guardare indietro, ed è anche il motivo per cui la classifica
 * dopo la N−2 resta consultabile — è quella che ha deciso l'ordine del draft.
 */
class StandingsController extends Controller
{
    public function index(Request $request): View
    {
        $stagione = $this->stagioneOAbort($request->user());

        $giornate = Standing::where('league_season_id', $stagione->id)
            ->distinct()
            ->orderByDesc('matchday')
            ->pluck('matchday');

        $matchday = $request->integer('giornata') ?: $giornate->first();

        return view('standings.index', [
            'stagione' => $stagione,
            'matchday' => $matchday,
            'giornate' => $giornate,
            'classifica' => $matchday ? $this->classifica($stagione->id, $matchday) : collect(),
            'sfide' => $matchday ? $this->sfide($stagione->id, $matchday) : collect(),
            'settings' => Settings::for($stagione),
        ]);
    }

    private function classifica(int $leagueSeasonId, int $matchday)
    {
        return Standing::with('manager')
            ->where('league_season_id', $leagueSeasonId)
            ->where('matchday', $matchday)
            ->orderBy('posizione')
            ->get();
    }

    private function sfide(int $leagueSeasonId, int $matchday)
    {
        return Matchup::with(['home', 'away'])
            ->where('league_season_id', $leagueSeasonId)
            ->whereNull('tournament_id')
            ->where('matchday', $matchday)
            ->orderBy('id')
            ->get();
    }
}
