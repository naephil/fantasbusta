<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fantavoto calcolato: voto base più bonus meno malus.
 *
 * `fantavoto` nullo significa senza voto, ed è il segnale che fa scattare la
 * sostituzione automatica in fase di calcolo formazione.
 *
 * Appartiene alla stagione di lega e non all'annata: i coefficienti sono
 * tarabili per gruppo, quindi lo stesso 7 in pagella diventa un fantavoto
 * diverso a seconda di chi lo legge.
 */
class PlayerScore extends Model
{
    protected $guarded = [];

    protected $casts = [
        'voto_base' => 'float',
        'bonus' => 'float',
        'malus' => 'float',
        'fantavoto' => 'float',
    ];

    public function leagueSeason(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function hasVote(): bool
    {
        return $this->fantavoto !== null;
    }
}
