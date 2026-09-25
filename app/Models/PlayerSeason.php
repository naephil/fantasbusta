<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quello che di un giocatore cambia da un anno all'altro.
 *
 * Squadra, ruolo e quotazione non appartengono al giocatore ma alla stagione:
 * i trasferimenti spostano il primo, il listone nuovo riscrive gli altri due.
 * Tenerli su `players` significherebbe che caricare il 2024 riscrive il 2022,
 * e un gruppo che sta rigiocando una stagione vecchia si ritroverebbe mezza
 * Serie A nella squadra sbagliata.
 */
class PlayerSeason extends Model
{
    protected $guarded = [];

    protected $casts = [
        'role' => Role::class,
        'quotazione_iniziale' => 'float',
        'role_confirmed' => 'boolean',
        'active' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeDellaStagione(Builder $q, int $season): Builder
    {
        return $q->where('season', $season);
    }

    /**
     * Chi aspetta una decisione dell'admin sul ruolo.
     *
     * `role_confirmed` falso significa che il ruolo è stato dedotto dalla
     * posizione inglese dell'API — che non è il ruolo fantacalcio — e che
     * nessuno l'ha ancora guardato.
     */
    public function scopeDaConfermare(Builder $q, ?int $season = null): Builder
    {
        return $q->where('active', true)
            ->where('role_confirmed', false)
            ->when($season, fn (Builder $q, int $s) => $q->where('season', $s));
    }
}
