<?php

namespace App\Services\Tournament\Formats;

use App\Models\Matchup;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Services\Scoring\Arbitro;
use App\Services\Scoring\MatchdayTotals;
use Illuminate\Support\Collection;

/**
 * Quello che tutti i formati fanno allo stesso modo.
 *
 * Come si decide chi ha vinto una sfida non sta più qui: sta in Arbitro, che è
 * lo stesso che risolve il campionato. Se ogni formato — o peggio, ogni
 * competizione — se la scrivesse per conto suo, prima o poi la coppa e il
 * campionato assegnerebbero esiti diversi allo stesso punteggio, e nessuno
 * capirebbe perché.
 */
abstract class FormatoBase implements FormatoTorneo
{
    public function __construct(protected MatchdayTotals $totali) {}

    /** @return array{0:int,1:int} */
    public function partecipanti(): array
    {
        return [2, 32];
    }

    /**
     * Risolve le sfide di una giornata e restituisce chi ha vinto.
     *
     * @return Collection<int,Matchup> le sfide risolte
     */
    protected function risolviSfide(Tournament $torneo, int $matchday): Collection
    {
        $arbitro = Arbitro::per($torneo->leagueSeason);

        $totali = $this->totali->forMatchday($torneo->league_season_id, $matchday);

        $sfide = $torneo->matchups()->where('matchday', $matchday)->where('state', 'scheduled')->get();

        foreach ($sfide as $sfida) {
            $casa = (float) $totali->get($sfida->home_manager_id, 0);
            $fuori = (float) $totali->get($sfida->away_manager_id, 0);

            $esito = $arbitro->esito($casa, $fuori);

            $sfida->update([
                'home_points' => $casa,
                'away_points' => $fuori,
                'home_goals' => $esito->golCasa,
                'away_goals' => $esito->golFuori,
                'home_score' => $esito->puntiCasa,
                'away_score' => $esito->puntiFuori,
                'state' => 'played',
            ]);
        }

        return $sfide;
    }

    /**
     * Chi passa il turno.
     *
     * A eliminazione diretta il pareggio non esiste: se i fantapunti sono
     * identici — succede, con due decimali — passa la testa di serie migliore.
     * Un tabellone che non avanza è peggio di un criterio arbitrario, purché
     * l'arbitrarietà sia dichiarata in anticipo.
     *
     * Si guardano i fantapunti e non le reti anche quando la lega gioca a gol,
     * e non è una svista: più fantapunti non danno mai meno reti — la
     * conversione è monotòna — quindi l'ordine non può contraddire il
     * risultato, e i decimali risolvono i tanti 1–1 che altrimenti finirebbero
     * tutti alla testa di serie.
     */
    protected function vincitore(Matchup $sfida, Tournament $torneo): int
    {
        if ($sfida->home_points > $sfida->away_points) {
            return $sfida->home_manager_id;
        }

        if ($sfida->away_points > $sfida->home_points) {
            return $sfida->away_manager_id;
        }

        $seed = $torneo->entries()
            ->whereIn('manager_id', [$sfida->home_manager_id, $sfida->away_manager_id])
            ->orderBy('seed')
            ->first();

        return $seed?->manager_id ?? $sfida->home_manager_id;
    }

    /** @param  list<array{int,int}>  $coppie */
    protected function creaSfide(Tournament $torneo, array $coppie, int $matchday, int $round, string $stage): void
    {
        foreach ($coppie as [$casa, $fuori]) {
            Matchup::create([
                'league_season_id' => $torneo->league_season_id,
                'tournament_id' => $torneo->id,
                'round' => $round,
                'stage' => $stage,
                'matchday' => $matchday,
                'home_manager_id' => $casa,
                'away_manager_id' => $fuori,
            ]);
        }
    }

    protected function concludi(Tournament $torneo, ?int $vincitore): void
    {
        $torneo->update([
            'state' => 'concluso',
            'winner_manager_id' => $vincitore,
        ]);
    }

    /** @return Collection<int,TournamentEntry> */
    protected function attivi(Tournament $torneo): Collection
    {
        return $torneo->entries()->where('state', 'attivo')->orderBy('seed')->get();
    }
}
