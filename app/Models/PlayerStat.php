<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statistiche grezze di un giocatore in una giornata, come arrivano dall'API.
 */
class PlayerStat extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rating' => 'float',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * Senza voto: zero minuti oppure rating assente.
     *
     * `rating` nullo è distinto da zero, che sarebbe un voto reale pessimo.
     * È la condizione che fa scattare la sostituzione automatica.
     */
    public function hasVote(): bool
    {
        return $this->minutes > 0 && $this->rating !== null;
    }

    /**
     * Conteggi degli eventi, con i nomi usati dai coefficienti.
     *
     * I gol dell'API includono i rigori. Vanno scorporati, altrimenti un
     * rigore verrebbe contato due volte nell'istante in cui i due coefficienti
     * smettono di essere uguali — ed è proprio il ritocco più probabile, visto
     * che molte leghe pagano il rigore meno di un gol su azione.
     *
     * @return array<string,int>
     */
    public function events(): array
    {
        return [
            'gol' => max(0, $this->goals - $this->pen_scored),
            'rigore_segnato' => $this->pen_scored,
            'rigore_parato' => $this->pen_saved,
            'rigore_sbagliato' => $this->pen_missed,
            'assist' => $this->assists,
            'ammonizione' => $this->yellow,
            'espulsione' => $this->red,
            'autorete' => $this->own_goals,
            'gol_subito' => $this->goals_conceded,
        ];
    }
}
