<?php

namespace App\Services\Stats;

use App\Models\Card;
use App\Models\LeagueSeason;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chi pesca sempre chi.
 *
 * Il dato c'era già: `cards.original_owner_id` è immutabile per costruzione —
 * registra chi ha PESCATO la carta, non chi la possiede adesso — e con una
 * rosa riestratta ogni giornata diventa in fretta una statistica divertente.
 * Dopo dieci giornate si scopre che uno ha avuto lo stesso attaccante sei
 * volte su dieci, e quella è una storia che la lega si racconta da sola.
 *
 * Da non confondere con il possesso: una carta pescata e subito scambiata
 * conta come pescata. È voluto — la domanda è «a chi capita sempre», non
 * «chi ce l'ha adesso».
 */
class DraftHistory
{
    /**
     * Le coppie giocatore-manager più ricorrenti.
     *
     * @return Collection<int,object> playerId, managerId, volte
     */
    public function affinities(LeagueSeason $stagione, int $minimo = 2, int $quanti = 25): Collection
    {
        return Card::query()
            ->where('cards.league_season_id', $stagione->id)
            ->join('players', 'players.id', '=', 'cards.player_id')
            ->join('managers', 'managers.id', '=', 'cards.original_owner_id')
            ->groupBy('cards.player_id', 'cards.original_owner_id', 'players.last_name', 'managers.name')
            ->havingRaw('count(*) >= ?', [$minimo])
            ->orderByDesc(DB::raw('count(*)'))
            ->orderBy('players.last_name')
            ->limit($quanti)
            ->get([
                'cards.player_id',
                'cards.original_owner_id',
                'players.last_name as giocatore',
                'managers.name as manager',
                DB::raw('count(*) as volte'),
            ]);
    }

    /**
     * I giocatori più pescati in assoluto, con quante mani diverse li hanno avuti.
     *
     * @return Collection<int,object>
     */
    public function mostDrawn(LeagueSeason $stagione, int $quanti = 15): Collection
    {
        return Card::query()
            ->where('cards.league_season_id', $stagione->id)
            ->join('players', 'players.id', '=', 'cards.player_id')
            ->groupBy('cards.player_id', 'players.last_name', 'cards.role')
            ->orderByDesc(DB::raw('count(*)'))
            ->orderBy('players.last_name')
            ->limit($quanti)
            ->get([
                'cards.player_id',
                'cards.role',
                'players.last_name as giocatore',
                DB::raw('count(*) as volte'),
                DB::raw('count(distinct cards.original_owner_id) as mani'),
            ]);
    }

    /**
     * Quante volte un singolo giocatore è finito a ciascun manager.
     *
     * @return Collection<int,object>
     */
    public function forPlayer(LeagueSeason $stagione, int $playerId): Collection
    {
        return Card::query()
            ->where('cards.league_season_id', $stagione->id)
            ->where('cards.player_id', $playerId)
            ->join('managers', 'managers.id', '=', 'cards.original_owner_id')
            ->groupBy('cards.original_owner_id', 'managers.name')
            ->orderByDesc(DB::raw('count(*)'))
            ->get([
                'cards.original_owner_id',
                'managers.name as manager',
                DB::raw('count(*) as volte'),
            ]);
    }
}
