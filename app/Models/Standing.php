<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Classifica cumulativa dopo una giornata.
 *
 * Una riga per manager per giornata: è una fotografia, non un totale che si
 * sovrascrive. Serve così perché l'ordine del draft guarda indietro a una
 * giornata precisa, e con un solo totale corrente non si potrebbe più sapere
 * com'era la classifica quando quel draft è partito.
 */
class Standing extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fantapunti' => 'float',
    ];

    public function leagueSeason(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    /**
     * L'ordine di pesca: inverso alla classifica.
     *
     * Chi sta peggio sceglie per primo. È l'unico riequilibrio previsto — il
     * draft non ha rubber banding aggiuntivo, perché questo lo fornisce già.
     *
     * @return list<int> id dei manager, dal primo a pescare
     */
    public static function draftOrder(int $leagueSeasonId, int $matchday): array
    {
        return self::where('league_season_id', $leagueSeasonId)
            ->where('matchday', $matchday)
            ->orderByDesc('posizione')
            ->pluck('manager_id')
            ->all();
    }
}
