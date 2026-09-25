<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una partita di Serie A.
 *
 * Da non confondere con `matchups`, che sono le sfide fra manager. Qui c'è il
 * calendario vero, e serve soprattutto per una cosa: sapere quando comincia una
 * giornata. È quel momento a scandire tutto il ciclo — congela le rose, apre il
 * draft della giornata dopo, chiude gli scambi.
 */
class Fixture extends Model
{
    protected $guarded = [];

    public $incrementing = false;   // la PK è l'id API-Football

    protected $casts = [
        'kickoff_at' => 'datetime',
    ];

    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /** Il primo fischio della giornata: l'istante che fa da cardine al ciclo. */
    public static function firstKickoff(int $season, int $matchday): ?Carbon
    {
        $kickoff = self::where('season', $season)->where('matchday', $matchday)->min('kickoff_at');

        return $kickoff ? Carbon::parse($kickoff) : null;
    }

    public function scopeDellaStagione($q, int $season)
    {
        return $q->where('season', $season);
    }
}
