<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'identità di un giocatore: quello che non cambia mai.
 *
 * Squadra, ruolo e quotazione stanno su PlayerSeason, perché cambiano ogni
 * anno. Qui restano nome, foto e la verifica dell'identità — che si fa una
 * volta sola e vale per sempre.
 */
class Player extends Model
{
    protected $guarded = [];

    public $incrementing = false;   // la PK è l'id API-Football

    protected $casts = [
        'photo_verified' => 'boolean',
    ];

    public function seasons(): HasMany
    {
        return $this->hasMany(PlayerSeason::class);
    }

    /** La sua riga per una stagione: squadra, ruolo, quotazione di quell'anno. */
    public function inSeason(int $season): ?PlayerSeason
    {
        return $this->seasons()->where('season', $season)->first();
    }

    /** Stesso spazio di id delle statistiche: nessun mapping da mantenere. */
    public function photoUrl(): string
    {
        return "https://media.api-sports.io/football/players/{$this->id}.png";
    }

    /**
     * Chi non è mai stato guardato in faccia.
     *
     * Un id che punta al giocatore sbagliato restituisce una foto plausibile e
     * supera qualunque controllo automatico: solo l'occhio lo intercetta.
     * Vedi docs/DESIGN.md §6.1.
     */
    public function scopeDaVerificare(Builder $q): Builder
    {
        return $q->where('photo_verified', false);
    }
}
