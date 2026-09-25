<?php

namespace App\Services\Stats;

use App\Models\LeagueSeason;
use App\Models\PlayerPower;
use App\Models\PlayerSeason;
use Illuminate\Support\Collection;

/**
 * Chi sale e chi scende nella classifica di valutazione.
 *
 * Non serve calcolare niente: `player_power.rank_delta` esiste già, scritto da
 * PowerUpdater a ogni ricalcolo proprio per questo. Qui si legge soltanto.
 *
 * Il movimento si misura in POSIZIONI e non in punti di power: il power è un
 * numero senza unità, e «+4,2» non dice niente a nessuno, mentre «sei posizioni
 * guadagnate» si capisce e si può confrontare fra giornate diverse.
 */
class MarketMovers
{
    /**
     * @return array{salgono: Collection<int,PlayerPower>, scendono: Collection<int,PlayerPower>}
     */
    public function forMatchday(LeagueSeason $stagione, int $matchday, int $quanti = 10): array
    {
        $base = fn () => PlayerPower::with('player')
            ->where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->whereNotNull('rank_delta')
            ->where('rank_delta', '!=', 0)
            ->limit($quanti);

        return [
            'salgono' => $this->conSquadra($base()->orderByDesc('rank_delta')->get(), $stagione->season),
            'scendono' => $this->conSquadra($base()->orderBy('rank_delta')->get(), $stagione->season),
        ];
    }

    /**
     * Chi ha cambiato fascia: è il movimento che si vede davvero in mano.
     *
     * @return Collection<int,PlayerPower>
     */
    public function tierChanges(LeagueSeason $stagione, int $matchday, int $quanti = 12): Collection
    {
        return $this->conSquadra(
            PlayerPower::with('player')
                ->where('league_season_id', $stagione->id)
                ->where('matchday', $matchday)
                ->where('tier_changed', true)
                ->orderByDesc('rank_delta')
                ->limit($quanti)
                ->get(),
            $stagione->season,
        );
    }

    /**
     * Attacca a ogni riga il club di quell'anno.
     *
     * La squadra non è una relazione di `player_power` e non può esserlo: sta
     * su `player_seasons`, cioè dipende dall'anno, e la stessa persona in due
     * annate diverse gioca in due squadre diverse. Si risolve con una query e
     * un innesto, che è meno elegante di un `with()` ma è l'unica cosa vera.
     *
     * @param  Collection<int,PlayerPower>  $righe
     * @return Collection<int,PlayerPower>
     */
    private function conSquadra(Collection $righe, int $season): Collection
    {
        if ($righe->isEmpty()) {
            return $righe;
        }

        $squadre = PlayerSeason::with('team')
            ->whereIn('player_id', $righe->pluck('player_id')->unique())
            ->where('season', $season)
            ->get()
            ->keyBy('player_id');

        return $righe->each(
            fn (PlayerPower $riga) => $riga->squadra = $squadre->get($riga->player_id)?->team?->name,
        );
    }
}
