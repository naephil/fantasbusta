<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DraftTurn extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'opened_at' => 'datetime',
        'revealed_at' => 'datetime',
    ];

    /**
     * La busta è già in rosa, ma il manager non l'ha vista uscire.
     *
     * ⚠️ Non è `opened_by === 'auto'`. Con lo sbustamento automatico acceso il
     * turno risulta aperto dal 'manager' — la scelta è stata sua — ma davanti
     * allo schermo non c'era nessuno, e quella busta è da recuperare esattamente
     * come una scaduta.
     */
    public function daRivedere(): bool
    {
        return $this->state === 'done' && $this->revealed_at === null;
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    /** Le carte uscite da questa busta. */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class, 'draft_turn_id');
    }
}
