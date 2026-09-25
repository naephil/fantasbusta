<?php

namespace App\Services\Stats;

use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\PlayerScore;
use Illuminate\Support\Collection;

/**
 * Il migliore in campo di ogni manager, giornata per giornata.
 *
 * La formazione schierata non basta: fra i titolari e chi ha davvero giocato
 * ci sono le sostituzioni automatiche, e premiare un titolare rimasto senza
 * voto sarebbe assurdo. Gli undici effettivi si ricostruiscono da
 * `lineup_results.sostituzioni`, che registra chi è entrato per chi proprio
 * perché il report di fine giornata potesse dirlo.
 *
 * Ricostruire invece di salvare un campo `mvp_card_id` è una scelta: il
 * migliore cambia se si ricalcola la giornata — e le giornate si ricalcolano,
 * perché i rating arrivano tardi — mentre le sostituzioni no.
 */
class MatchdayMvp
{
    /**
     * @return Collection<int,array{manager: string, giocatore: string, ruolo: string, fantavoto: float, totale: float}>
     */
    public function forMatchday(LeagueSeason $stagione, int $matchday): Collection
    {
        $lineups = Lineup::with(['manager', 'result', 'slots.card.player'])
            ->where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->get()
            ->filter(fn (Lineup $l) => $l->result !== null);

        if ($lineups->isEmpty()) {
            return collect();
        }

        $voti = PlayerScore::where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->whereIn('player_id', $lineups->flatMap(fn (Lineup $l) => $l->slots->pluck('card.player_id')))
            ->whereNotNull('fantavoto')
            ->pluck('fantavoto', 'player_id');

        return $lineups
            ->map(fn (Lineup $lineup) => $this->best($lineup, $voti))
            ->filter()
            ->sortByDesc('fantavoto')
            ->values();
    }

    /**
     * @param  Collection<int,float>  $voti
     * @return array<string,mixed>|null
     */
    private function best(Lineup $lineup, Collection $voti): ?array
    {
        $inCampo = $this->effectiveEleven($lineup);

        $migliore = $lineup->slots
            ->filter(fn ($slot) => in_array($slot->card->player_id, $inCampo, true))
            ->map(fn ($slot) => [
                'giocatore' => $slot->card->player->last_name,
                'ruolo' => $slot->card->role,
                'fantavoto' => (float) ($voti[$slot->card->player_id] ?? 0),
            ])
            // A parità di fantavoto decide il cognome. Senza uno spareggio
            // esplicito l'ordine lo deciderebbe il database, e su MariaDB non è
            // garantito stabile: lo stesso migliore in campo cambierebbe fra un
            // caricamento e l'altro senza che sia successo niente.
            ->sortBy([['fantavoto', 'desc'], ['giocatore', 'asc']])
            ->first();

        if (! $migliore || $migliore['fantavoto'] <= 0) {
            return null;
        }

        return $migliore + [
            'manager' => $lineup->manager->name,
            'totale' => (float) $lineup->result->totale,
            'auto' => $lineup->auto_generated,
        ];
    }

    /**
     * Chi ha davvero giocato: titolari, meno chi è uscito, più chi è entrato.
     *
     * @return list<int> player_id
     */
    private function effectiveEleven(Lineup $lineup): array
    {
        $titolari = $lineup->starters()->pluck('card.player_id')->all();
        $sostituzioni = $lineup->result->sostituzioni ?? [];

        $usciti = array_column($sostituzioni, 'esce');
        $entrati = array_column($sostituzioni, 'entra');

        return array_values(array_merge(
            array_diff($titolari, $usciti),
            $entrati,
        ));
    }
}
