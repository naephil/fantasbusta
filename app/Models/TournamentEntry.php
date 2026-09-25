<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un partecipante a un torneo.
 *
 * `seed` non è decorativo: decide gli accoppiamenti del primo turno ed è
 * l'ultimo spareggio quando punti e fantapunti sono identici. Senza, due
 * squadre pari resterebbero pari per sempre e il tabellone non avanzerebbe.
 */
class TournamentEntry extends Model
{
    protected $guarded = [];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    public function elimina(int $matchday): void
    {
        $this->update(['state' => 'eliminato', 'eliminated_matchday' => $matchday]);
    }
}
