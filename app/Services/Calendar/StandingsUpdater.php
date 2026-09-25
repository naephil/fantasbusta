<?php

namespace App\Services\Calendar;

use App\Models\LeagueSeason;
use App\Models\Matchup;
use App\Models\Standing;
use App\Services\Scoring\Arbitro;
use App\Services\Scoring\MatchdayTotals;
use Illuminate\Support\Collection;

/**
 * Risolve le sfide di una giornata e ricostruisce la classifica.
 *
 * Va lanciato dopo il calcolo dei totali di formazione: legge i fantapunti da
 * `lineup_results` e li passa ad Arbitro, che ne ricava il risultato in reti e
 * i punti di classifica. Come si decide chi ha vinto non sta qui — sta lì, ed è
 * lo stesso codice che risolve le coppe.
 */
class StandingsUpdater
{
    /** @return int manager in classifica */
    public function update(LeagueSeason $stagione, int $matchday): int
    {
        $this->resolve($stagione, $matchday, Arbitro::per($stagione));

        return $this->rebuild($stagione, $matchday);
    }

    /** Assegna reti, punti e fantapunti alle sfide della giornata. */
    private function resolve(LeagueSeason $stagione, int $matchday, Arbitro $arbitro): void
    {
        $totali = $this->totals($stagione, $matchday);

        // Solo il campionato: una coppa non muove la classifica di lega.
        $sfide = Matchup::where('league_season_id', $stagione->id)
            ->whereNull('tournament_id')
            ->where('matchday', $matchday)
            ->get();

        foreach ($sfide as $sfida) {
            // Se nessuno dei due ha un totale, la giornata non è stata ancora
            // calcolata: la sfida resta in attesa. ⚠️ Da quando si gioca a gol
            // la distinzione conta il doppio — uno 0–0 adesso è un risultato
            // vero, quindi risolverla qui non la lascerebbe «da giocare», la
            // dichiarerebbe finita a reti inviolate.
            if (! $totali->has($sfida->home_manager_id) && ! $totali->has($sfida->away_manager_id)) {
                continue;
            }

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
    }

    /**
     * Classifica cumulativa fino alla giornata indicata.
     *
     * Si ricostruisce da zero sommando tutte le sfide giocate invece di
     * aggiungere la giornata alla riga precedente: un ricalcolo — che capita,
     * perché i rating arrivano tardi — deve poter correggere anche il passato.
     */
    private function rebuild(LeagueSeason $stagione, int $matchday): int
    {
        $sfide = Matchup::where('league_season_id', $stagione->id)
            ->whereNull('tournament_id')
            ->where('matchday', '<=', $matchday)
            ->where('state', 'played')
            ->get();

        $totali = $stagione->partecipanti()
            ->mapWithKeys(fn ($m) => [$m->id => [
                'manager_id' => $m->id,
                'punti' => 0,
                'fantapunti' => 0.0,
            ]])
            ->all();

        foreach ($sfide as $sfida) {
            foreach ([$sfida->home_manager_id, $sfida->away_manager_id] as $id) {
                if (! isset($totali[$id])) {
                    continue;
                }

                $quota = $sfida->pointsFor($id);
                $totali[$id]['punti'] += $quota['punti'];
                $totali[$id]['fantapunti'] += $quota['fantapunti'];
            }
        }

        // A parità di punti decidono i fantapunti totali: è lo spareggio
        // naturale, perché misura la stessa cosa senza l'arrotondamento
        // della soglia di pareggio.
        $classifica = collect($totali)
            ->sortBy([['punti', 'desc'], ['fantapunti', 'desc'], ['manager_id', 'asc']])
            ->values();

        foreach ($classifica as $i => $riga) {
            Standing::updateOrCreate(
                [
                    'league_season_id' => $stagione->id,
                    'manager_id' => $riga['manager_id'],
                    'matchday' => $matchday,
                ],
                [
                    'punti' => $riga['punti'],
                    'fantapunti' => round($riga['fantapunti'], 2),
                    'posizione' => $i + 1,
                ],
            );
        }

        return $classifica->count();
    }

    /**
     * @return Collection<int,float> totale di formazione per manager
     *
     * Delega al servizio condiviso: gli stessi numeri li leggono anche i
     * formati di torneo, e due implementazioni divergenti darebbero due
     * verità diverse sullo stesso weekend.
     */
    private function totals(LeagueSeason $stagione, int $matchday): Collection
    {
        return app(MatchdayTotals::class)->forMatchday($stagione->id, $matchday);
    }
}
