<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Lineup extends Model
{
    protected $guarded = [];

    protected $casts = [
        'locked_at' => 'datetime',
        'auto_generated' => 'boolean',
    ];

    public function leagueSeason(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(LineupSlot::class);
    }

    public function result(): HasOne
    {
        return $this->hasOne(LineupResult::class);
    }

    /** @return Collection<int,LineupSlot> */
    public function starters(): Collection
    {
        return $this->slots->where('is_starter', true)->values();
    }

    /**
     * La panchina nell'ordine deciso dal manager.
     *
     * L'ordine non è un dettaglio di presentazione: è l'unica cosa che decide
     * chi entra al posto di chi. Il draft della giornata N+1 si chiude prima
     * che escano le formazioni ufficiali, quindi i senza voto sono la norma.
     *
     * @return Collection<int,LineupSlot>
     */
    public function bench(): Collection
    {
        return $this->slots->where('is_starter', false)->sortBy('bench_order')->values();
    }
}
