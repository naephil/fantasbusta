<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Power score e tier di un giocatore per una giornata.
 *
 * `matchday` è la giornata PER CUI vale, non quella da cui deriva.
 *
 * Appartiene alla stagione di lega: i pesi si tarano per gruppo, quindi lo
 * stesso giocatore può essere Epica per una lega e Rara per un'altra nella
 * stessa giornata di Serie A.
 */
class PlayerPower extends Model
{
    protected $table = 'player_power';

    protected $guarded = [];

    protected $casts = [
        'power' => 'float',
        'tier_changed' => 'boolean',
    ];

    public function leagueSeason(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
