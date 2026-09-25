<?php

namespace App\Models;

use App\Services\Scoring\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Draft extends Model
{
    protected $guarded = [];

    protected $casts = [
        'opens_at' => 'datetime',
        'deadline_at' => 'datetime',
    ];

    public function leagueSeason(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class);
    }

    public function turns(): HasMany
    {
        return $this->hasMany(DraftTurn::class);
    }

    public function pool(): HasMany
    {
        return $this->hasMany(DraftPoolEntry::class);
    }

    public function activeTurn(): ?DraftTurn
    {
        return $this->turns()->where('state', 'active')->first();
    }

    public function nextWaitingTurn(): ?DraftTurn
    {
        return $this->turns()->where('state', 'waiting')->orderBy('pick_index')->first();
    }

    /**
     * Durata del turno, ricalcolata a ogni attivazione.
     *
     * Un timer fisso non regge per due motivi che si sommano: la finestra passa
     * da ~72h nel weekend a ~48h nei turni infrasettimanali, e il numero di
     * turni dipende da quanti giri si sono scelti — cinque buste da cinque ne
     * fanno sessanta con dodici manager, tre da otto ne fanno trentasei, e il
     * tempo a testa raddoppia. Dividendo il tempo residuo per i turni ancora da
     * giocare il draft si auto-comprime senza che nessuno debba fare i conti.
     *
     * Gli estremi entro cui quel conto può muoversi sono invece una regola di
     * lega, tarabile da /admin/regole: il minimo esiste perché il gioco è
     * asincrono e un turno di due minuti nessuno lo vedrebbe mai, ma in una
     * stagione di prova è proprio quel minimo a far perdere le giornate.
     */
    public function turnDuration(): int
    {
        $settings = Settings::for($this->leagueSeason);

        $remaining = max(1, $this->turns()->whereIn('state', ['waiting', 'active'])->count());
        $seconds = now()->diffInSeconds($this->deadline_at, false) / $remaining;

        return (int) max($settings->turnoMinSecondi(), min($settings->turnoMaxSecondi(), $seconds));
    }
}
