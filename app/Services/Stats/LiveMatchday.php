<?php

namespace App\Services\Stats;

use App\Models\Card;
use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\PlayerStat;
use Illuminate\Support\Collection;

/**
 * La giornata di Serie A vista da dentro il fantacalcio.
 *
 * Le stesse partite che si guardano alla TV, ma con accanto a ogni giocatore
 * il suo fantavoto e — la parte che conta — di chi è la carta. È l'unica
 * schermata che collega le due metà del gioco: senza, un gol di un difensore
 * resta un gol di un difensore, invece di essere «tre punti a Bruno».
 */
class LiveMatchday
{
    /**
     * @return Collection<int,array<string,mixed>> una voce per partita
     */
    public function forMatchday(LeagueSeason $stagione, int $matchday, ?int $ioManagerId = null): Collection
    {
        $fixtures = Fixture::with(['homeTeam', 'awayTeam'])
            ->where('season', $stagione->season)
            ->where('matchday', $matchday)
            ->orderBy('kickoff_at')
            ->get();

        if ($fixtures->isEmpty()) {
            return collect();
        }

        $squadre = $fixtures->flatMap(fn (Fixture $f) => [$f->home_team_id, $f->away_team_id])->unique();

        $righe = $this->players($squadre->all(), $stagione, $matchday);
        $proprietari = $this->owners($stagione, $matchday);

        return $fixtures->map(fn (Fixture $f) => [
            'fixture' => $f,
            'casa' => $this->forTeam($righe, $f->home_team_id, $proprietari, $ioManagerId),
            'fuori' => $this->forTeam($righe, $f->away_team_id, $proprietari, $ioManagerId),
        ]);
    }

    /**
     * @param  Collection<int,object>  $righe
     * @param  array<int,array{nome: string, id: int}>  $proprietari
     * @return Collection<int,array<string,mixed>>
     */
    private function forTeam(Collection $righe, int $teamId, array $proprietari, ?int $ioManagerId): Collection
    {
        return $righe
            ->where('team_id', $teamId)
            ->map(function (object $r) use ($proprietari, $ioManagerId) {
                $owner = $proprietari[$r->player_id] ?? null;

                return [
                    'nome' => $r->last_name,
                    'ruolo' => $r->role,
                    'minuti' => (int) $r->minutes,
                    'rating' => $r->rating !== null ? (float) $r->rating : null,
                    'fantavoto' => $r->fantavoto !== null ? (float) $r->fantavoto : null,
                    // Gli stessi conteggi che usa il tabellino della sfida, coi
                    // nomi dei coefficienti: i segnalini li disegna un
                    // componente solo, e due elenchi divergenti sono
                    // esattamente ciò che si vuole evitare.
                    'eventi' => [
                        'gol' => max(0, (int) $r->goals - (int) $r->pen_scored),
                        'rigore_segnato' => (int) $r->pen_scored,
                        'rigore_parato' => (int) $r->pen_saved,
                        'rigore_sbagliato' => (int) $r->pen_missed,
                        'assist' => (int) $r->assists,
                        'ammonizione' => (int) $r->yellow,
                        'espulsione' => (int) $r->red,
                        'autorete' => (int) $r->own_goals,
                        'gol_subito' => (int) $r->goals_conceded,
                    ],
                    'proprietario' => $owner['nome'] ?? null,
                    'carta' => $owner['carta'] ?? null,
                    'mio' => $owner !== null && $owner['id'] === $ioManagerId,
                ];
            })
            // ⚠️ Si ordina per RUOLO, non per fantavoto. Il voto in cima mette
            // insieme un portiere, due attaccanti e un terzino, e per capire
            // com'era messa una squadra bisogna ricomporla a mente ogni volta.
            // P-D-C-A è l'ordine in cui il fantacalcio si legge da sempre, ed è
            // anche l'ordine in cui si guarda la propria formazione.
            //
            // Chi non è sceso in campo resta comunque in fondo: dentro il
            // reparto è l'unica distinzione che serve.
            ->sortBy(fn (array $p) => [
                $p['minuti'] > 0 ? 0 : 1,
                array_search($p['ruolo'], ['P', 'D', 'C', 'A'], true),
                -($p['fantavoto'] ?? -99),
            ])
            ->values();
    }

    /**
     * Statistiche e voti in una query sola.
     *
     * @param  list<int>  $squadre
     * @return Collection<int,object>
     */
    private function players(array $squadre, LeagueSeason $stagione, int $matchday): Collection
    {
        $season = $stagione->season;

        // Squadra e ruolo vengono da `player_seasons`: sono attributi
        // dell'anno, non della persona. In un replay del 2022 il giocatore
        // deve comparire nella squadra in cui militava allora.
        return PlayerStat::query()
            ->where('player_stats.season', $season)
            ->where('player_stats.matchday', $matchday)
            ->join('players', 'players.id', '=', 'player_stats.player_id')
            ->join('player_seasons', function ($j) use ($season) {
                $j->on('player_seasons.player_id', '=', 'player_stats.player_id')
                    ->where('player_seasons.season', '=', $season);
            })
            ->whereIn('player_seasons.team_id', $squadre)
            ->leftJoin('player_scores', function ($j) use ($stagione, $matchday) {
                $j->on('player_scores.player_id', '=', 'player_stats.player_id')
                    ->where('player_scores.league_season_id', '=', $stagione->id)
                    ->where('player_scores.matchday', '=', $matchday);
            })
            ->get([
                'player_stats.player_id',
                'player_stats.minutes',
                'player_stats.rating',
                'player_stats.goals',
                'player_stats.assists',
                'player_stats.yellow',
                'player_stats.red',
                'player_stats.pen_scored',
                'player_stats.pen_saved',
                'player_stats.pen_missed',
                'player_stats.own_goals',
                'player_stats.goals_conceded',
                'players.last_name',
                'player_seasons.role',
                'player_seasons.team_id',
                'player_scores.fantavoto',
            ]);
    }

    /**
     * Di chi è la carta, adesso.
     *
     * Il possesso corrente e non chi l'ha pescata: qui la domanda è «chi sta
     * esultando», e a esultare è chi ce l'ha in rosa in questo momento.
     *
     * @return array<int,array{nome: string, id: int}>
     */
    private function owners(LeagueSeason $stagione, int $matchday): array
    {
        return Card::query()
            ->where('cards.league_season_id', $stagione->id)
            ->where('cards.matchday', $matchday)
            ->join('managers', 'managers.id', '=', 'cards.owner_manager_id')
            ->get(['cards.id as card_id', 'cards.player_id', 'managers.name', 'managers.id as manager_id'])
            ->mapWithKeys(fn ($c) => [
                (int) $c->player_id => [
                    'nome' => $c->name,
                    'id' => (int) $c->manager_id,
                    // Serve all'anteprima al passaggio del mouse: la carta è la
                    // cosa più bella del gioco e la si vede solo al draft.
                    'carta' => (int) $c->card_id,
                ],
            ])
            ->all();
    }

    /** Le giornate che hanno almeno una statistica: quelle da poter guardare. */
    public function availableMatchdays(int $season): Collection
    {
        return PlayerStat::select('matchday')
            ->where('season', $season)
            ->groupBy('matchday')
            ->orderByDesc('matchday')
            ->pluck('matchday');
    }
}
