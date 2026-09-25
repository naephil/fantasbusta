<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftPoolEntry extends Model
{
    protected $table = 'draft_pool';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['drawn_at' => 'datetime'];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Draft::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
