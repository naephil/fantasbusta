<?php

namespace App\Services\Scoring;

use App\Models\Lineup;
use Illuminate\Support\Collection;

/**
 * Quanti fantapunti ha fatto ciascun manager in una giornata.
 *
 * Una riga sola di query, ma in un posto solo: la usano la classifica di
 * campionato e tutti i formati di torneo, e sono esattamente i punti su cui si
 * decidono le partite. Due implementazioni che divergono di un dettaglio
 * darebbero due verità diverse sullo stesso weekend.
 */
class MatchdayTotals
{
    /** @return Collection<int,float> manager_id => totale */
    public function forMatchday(int $leagueSeasonId, int $matchday): Collection
    {
        return Lineup::where('lineups.league_season_id', $leagueSeasonId)
            ->where('lineups.matchday', $matchday)
            ->join('lineup_results', 'lineup_results.lineup_id', '=', 'lineups.id')
            ->pluck('lineup_results.totale', 'lineups.manager_id')
            ->map(fn ($v) => (float) $v);
    }
}
