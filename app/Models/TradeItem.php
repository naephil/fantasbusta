<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una carta dentro una proposta, con il verso in cui si muove.
 *
 * Nessun timestamp: la riga nasce e muore con la proposta, e il quando è già
 * su `trades`.
 */
class TradeItem extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
