<?php

namespace App\Models;

use App\Services\Tournament\Formats\FormatoTorneo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Tournament extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    public function leagueSeason(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TournamentEntry::class);
    }

    public function matchups(): HasMany
    {
        return $this->hasMany(Matchup::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'winner_manager_id');
    }

    /** L'implementazione del formato, presa dal registro. */
    public function formato(): FormatoTorneo
    {
        $classe = config("tornei.formati.{$this->format}");

        abort_unless($classe, 500, "Formato di torneo sconosciuto: {$this->format}");

        return app($classe);
    }

    /** @return Collection<int,TournamentEntry> */
    public function superstiti(): Collection
    {
        return $this->entries()->where('state', 'attivo')->with('manager')->get();
    }

    public function ultimaGiornata(): int
    {
        return $this->start_matchday + $this->formato()->durata(
            $this->entries()->count(),
            $this->settings ?? [],
        ) - 1;
    }

    /** Copre questa giornata? Serve a sapere se va fatto avanzare. */
    public function copre(int $matchday): bool
    {
        return $matchday >= $this->start_matchday && $matchday <= $this->ultimaGiornata();
    }

    /** @param  array<string,mixed>  $extra */
    public function impostazione(string $chiave, mixed $default = null): mixed
    {
        return data_get($this->settings, $chiave, $default);
    }
}
