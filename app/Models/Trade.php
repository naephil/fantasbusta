<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una proposta di scambio fra due manager della stessa lega.
 *
 * Lo stato `rejected` copre due casi diversi che in interfaccia vanno distinti:
 * il rifiuto esplicito del ricevente (`reject_reason` nullo) e il rifiuto
 * automatico della validazione all'accettazione (`reject_reason` valorizzato).
 */
class Trade extends Model
{
    protected $guarded = [];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function leagueSeason(): BelongsTo
    {
        return $this->belongsTo(LeagueSeason::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'proposer_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Manager::class, 'receiver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TradeItem::class);
    }

    /** Carte che vanno dal proponente al ricevente. */
    public function offered(): HasMany
    {
        return $this->items()->where('direction', 'offered');
    }

    /** Carte che vanno dal ricevente al proponente. */
    public function requested(): HasMany
    {
        return $this->items()->where('direction', 'requested');
    }

    public function isPending(): bool
    {
        return $this->state === 'pending';
    }

    /** Chi riceve la carta, dato il verso dell'item. */
    public function destinationFor(string $direction): int
    {
        return $direction === 'offered' ? $this->receiver_id : $this->proposer_id;
    }

    /** Chi deve possedere la carta perché lo scambio sia applicabile. */
    public function sourceFor(string $direction): int
    {
        return $direction === 'offered' ? $this->proposer_id : $this->receiver_id;
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('state', 'pending');
    }

    public function scopeInvolving(Builder $q, int $managerId): Builder
    {
        return $q->where(fn (Builder $w) => $w->where('proposer_id', $managerId)
            ->orWhere('receiver_id', $managerId));
    }

    /**
     * Feed pubblico della lega.
     *
     * Con il 7-per-1 permesso il rischio non è l'equilibrio ma la collusione,
     * e per una lega di amici la trasparenza è il rimedio proporzionato.
     * Vedi docs/DESIGN.md §4.6.
     */
    public function scopeFeed(Builder $q, int $leagueSeasonId, int $matchday): Builder
    {
        return $q->where('league_season_id', $leagueSeasonId)
            ->where('matchday', $matchday)
            ->whereIn('state', ['accepted', 'rejected'])
            ->latest('resolved_at');
    }
}
