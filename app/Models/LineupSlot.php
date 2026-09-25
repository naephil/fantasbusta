<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineupSlot extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'is_starter' => 'boolean',
    ];

    public function lineup(): BelongsTo
    {
        return $this->belongsTo(Lineup::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
