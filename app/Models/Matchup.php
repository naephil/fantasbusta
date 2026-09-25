<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una sfida di calendario fra due manager.
 */
class Matchup extends Model
{
    protected $guarded = [];

    protected $casts = [
        'home_points' => 'float',
        'away_points' => 'float',

        // Nullable di proposito: `null` è «decisa a scarto di fantapunti»,
        // che non è `0` — uno 0–0 è un risultato vero. Vedi EsitoSfida.
        'home_goals' => 'integer',
        'away_goals' => 'integer',
    ];

    /** Se la sfida ha un risultato in reti da mostrare. */
    public function aGol(): bool
    {
        return $this->home_goals !== null;
    }

    public function leagueSeason(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class);
    }

    public function home(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'home_manager_id');
    }

    public function away(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'away_manager_id');
    }

    public function scopeInvolving(Builder $q, int $managerId): Builder
    {
        return $q->where(fn (Builder $w) => $w->where('home_manager_id', $managerId)
            ->orWhere('away_manager_id', $managerId));
    }

    /** Punti di classifica e fantapunti dal punto di vista di un manager. */
    public function pointsFor(int $managerId): array
    {
        return $managerId === $this->home_manager_id
            ? ['punti' => (int) $this->home_score, 'fantapunti' => (float) $this->home_points]
            : ['punti' => (int) $this->away_score, 'fantapunti' => (float) $this->away_points];
    }
}
