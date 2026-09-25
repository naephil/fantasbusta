<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineupResult extends Model
{
    protected $guarded = [];

    protected $casts = [
        'totale' => 'float',
        'penalita' => 'float',
        'sostituzioni' => 'array',
        'portiere_ufficio' => 'boolean',
        'computed_at' => 'datetime',
    ];

    public function lineup(): BelongsTo
    {
        return $this->belongsTo(Lineup::class);
    }
}
