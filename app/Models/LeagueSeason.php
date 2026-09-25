<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Una stagione giocata da un gruppo: la competizione vera e propria.
 *
 * È il perno del modello. Il gruppo e le identità delle squadre sono
 * permanenti; tutto ciò che si gioca — carte, draft, formazioni, sfide,
 * classifica, scambi, tornei — appartiene a una stagione di lega e muore con
 * lei. È ciò che permette allo stesso gruppo di giocare più anni senza che la
 * 5ª giornata del 2023 si scontri con la 5ª del 2024.
 *
 * Quasi tutti i servizi ricevono questo oggetto invece della lega: porta con
 * sé sia il gruppo sia l'anno, quindi non c'è modo di passarne uno e
 * dimenticare l'altro.
 */
class LeagueSeason extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
    ];

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(Standing::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(Draft::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function matchups(): HasMany
    {
        return $this->hasMany(Matchup::class);
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    /**
     * Chi partecipa: i manager attivi del gruppo.
     *
     * Chi ha lasciato resta in `managers` — le sue carte e i suoi risultati
     * passati devono restare leggibili — ma non entra nelle stagioni nuove.
     *
     * @return Collection<int,Manager>
     */
    public function partecipanti(): Collection
    {
        return $this->league->managers()->where('active', true)->orderBy('id')->get();
    }

    /**
     * La giornata è cominciata? Da lì in poi le formazioni non si toccano.
     *
     * Vale per quella dichiarata e per tutte le precedenti: se è cominciata la
     * 5ª, la 4ª è cominciata a maggior ragione. Senza il `<=` una giornata
     * saltata — capita, l'amministratore dimentica di premere — resterebbe
     * modificabile per sempre, e ci si potrebbe schierare a partite finite.
     */
    public function giornataIniziata(int $matchday): bool
    {
        return $this->started_matchday !== null && $matchday <= $this->started_matchday;
    }

    /** Come si chiama in interfaccia: «2023/24». */
    public function etichetta(): string
    {
        return $this->season.'/'.substr((string) ($this->season + 1), 2);
    }
}
