<?php

namespace App\Services\Trade;

use App\Models\Card;
use App\Models\Draft;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Trade;
use App\Models\TradeItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo di vita degli scambi: proposta, accettazione, rifiuto, scadenza.
 *
 * La fase di trading apre a draft chiuso e si chiude al primo fischio della
 * giornata. Vedi docs/DESIGN.md §4.
 */
class TradeService
{
    /**
     * Registra una proposta.
     *
     * Qui si controlla solo che la proposta sia *ben formata* — carte davvero
     * possedute, controparte della stessa lega, stessa giornata. Il pavimento
     * NON si controlla adesso: fra la proposta e l'accettazione la controparte
     * può aver concluso altri scambi, e una proposta validata alla nascita
     * darebbe una garanzia che al momento di applicarla non vale più.
     *
     * @param  list<int>  $offered  carte del proponente
     * @param  list<int>  $requested  carte del ricevente
     *
     * @throws TradeException
     */
    public function propose(
        LeagueSeason $stagione,
        Manager $proposer,
        Manager $receiver,
        array $offered,
        array $requested,
        int $matchday,
    ): Trade {
        if ($proposer->id === $receiver->id) {
            throw new TradeException('Non si scambia con sé stessi.');
        }

        if ($proposer->league_id !== $receiver->league_id || $proposer->league_id !== $stagione->league_id) {
            throw new TradeException('I due manager non giocano questa stagione.');
        }

        if ($offered === [] && $requested === []) {
            throw new TradeException('Uno scambio deve muovere almeno una carta.');
        }

        if ($this->draftInCorso($stagione, $matchday)) {
            throw new TradeException(
                'Il draft di questa giornata non è ancora finito: il mercato apre quando tutti hanno pescato.',
            );
        }

        $this->assertOwns($stagione, $proposer, $offered, $matchday);
        $this->assertOwns($stagione, $receiver, $requested, $matchday);

        return DB::transaction(function () use ($stagione, $proposer, $receiver, $offered, $requested, $matchday) {
            $trade = Trade::create([
                'league_season_id' => $stagione->id,
                'matchday' => $matchday,
                'proposer_id' => $proposer->id,
                'receiver_id' => $receiver->id,
                'state' => 'pending',
            ]);

            $this->attach($trade, $offered, 'offered');
            $this->attach($trade, $requested, 'requested');

            return $trade;
        });
    }

    /**
     * Il draft di quella giornata è ancora in ballo?
     *
     * ⚠️ È la condizione che rendeva il mercato una porta chiusa senza cartello.
     * §4 dice da sempre che il trading apre a draft concluso, ma nessuno lo
     * imponeva: la pagina si apriva, le carte si sceglievano, e poi ogni singola
     * proposta veniva respinta dal pavimento delle undici — perché a metà draft
     * una rosa di undici carte non c'è ancora per definizione. Il messaggio
     * parlava di rose troppo corte, cioè della conseguenza, e il motivo vero
     * («non è ancora ora») non compariva da nessuna parte.
     */
    public function draftInCorso(LeagueSeason $stagione, int $matchday): bool
    {
        return Draft::where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->whereIn('state', ['pending', 'open'])
            ->exists();
    }

    /**
     * Applica lo scambio, o lo rifiuta spiegando perché.
     *
     * Un rifiuto del pavimento non è un errore: è l'esito normale di una
     * proposta invecchiata male. Torna come stato sulla proposta, con il motivo
     * in `reject_reason`, così l'interfaccia può dirlo a entrambe le parti.
     *
     * Le corse coperte sono due (docs/DESIGN.md §7.2):
     *
     *  1. la stessa proposta accettata due volte — lock sulla riga di `trades`
     *     e ricontrollo dello stato dentro la transazione;
     *  2. due scambi incrociati accettati nello stesso istante, che è il modo
     *     classico per duplicare una carta o far scendere una rosa sotto il
     *     pavimento senza che nessuno dei due se ne accorga — lock sulle rose.
     *
     * ⚠️ In locale il database è SQLite, dove `lockForUpdate()` non fa nulla e
     * Laravel non protesta: questi test passano senza aver mai esercitato un
     * lock. Le prove di concorrenza vanno rifatte su MariaDB.
     */
    public function accept(Trade $trade): Trade
    {
        return DB::transaction(function () use ($trade) {
            $fresh = Trade::whereKey($trade->getKey())->lockForUpdate()->first();

            if (! $fresh?->isPending()) {
                return $fresh ?? $trade;   // già risolta da qualcun altro
            }

            $locked = $this->lockRosters($fresh);
            $items = $fresh->items()->orderBy('card_id')->get();

            if ($reason = $this->violation($fresh, $items, $locked)) {
                return $this->resolve($fresh, 'rejected', $reason);
            }

            $this->moveCards($fresh, $items, $locked);

            return $this->resolve($fresh, 'accepted');
        });
    }

    /** Rifiuto esplicito del ricevente: nessun motivo automatico da registrare. */
    public function reject(Trade $trade): Trade
    {
        return $this->close($trade, 'rejected');
    }

    /** Ritiro della proposta da parte di chi l'ha fatta. */
    public function cancel(Trade $trade): Trade
    {
        return $this->close($trade, 'cancelled');
    }

    /**
     * Chiude le proposte rimaste appese al primo fischio della giornata.
     *
     * La deadline arriva da fuori invece di essere dedotta qui: la fonte è il
     * `kickoff_at` della prima partita della giornata, che vive su `fixtures` e
     * non è ancora modellata.
     *
     * @return int proposte scadute
     */
    public function expirePending(int $leagueSeasonId, int $matchday): int
    {
        return Trade::where('league_season_id', $leagueSeasonId)
            ->where('matchday', $matchday)
            ->pending()
            ->update([
                'state' => 'expired',
                'resolved_at' => now(),
            ]);
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Blocca le rose di entrambe le parti, in ordine di `card_id` crescente.
     *
     * L'ordine è ciò che evita il deadlock: due accettazioni che si intrecciano
     * acquisiscono gli stessi lock nella stessa sequenza globale, quindi una
     * delle due aspetta invece di girare in tondo. Vale per sottoinsiemi
     * qualunque, che è esattamente il caso di due scambi con un manager in
     * comune.
     *
     * Si bloccano le rose INTERE e non le sole carte scambiate: il pavimento è
     * un conteggio su tutta la rosa, e bloccare solo le carte in transito
     * lascerebbe una controparte libera di svuotare il resto mentre validiamo.
     *
     * @return Collection<int,Card> indicizzata per id
     */
    private function lockRosters(Trade $trade): Collection
    {
        $ids = Card::where('league_season_id', $trade->league_season_id)
            ->whereIn('owner_manager_id', [$trade->proposer_id, $trade->receiver_id])
            ->where('matchday', $trade->matchday)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return Card::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Il pavimento, rivalutato sotto lock.
     *
     * I conteggi si calcolano SOLO sulle carte effettivamente bloccate. Una
     * carta entrata in rosa dopo la lettura degli id non è nostra da bloccare,
     * quindi non la contiamo: al più ci fa rifiutare uno scambio che sarebbe
     * passato, mai accettarne uno che avrebbe dovuto essere rifiutato. Fra i
     * due modi di sbagliare, questo è quello innocuo.
     *
     * @param  Collection<int,TradeItem>  $items
     * @param  Collection<int,Card>  $locked
     */
    private function violation(Trade $trade, Collection $items, Collection $locked): ?string
    {
        $moving = ['offered' => [], 'requested' => []];

        foreach ($items as $item) {
            $card = $locked->get($item->card_id);

            // La carta può essere stata ceduta altrove mentre la proposta
            // dormiva: è il caso normale di una proposta invecchiata.
            if (! $card || $card->owner_manager_id !== $trade->sourceFor($item->direction)) {
                return 'una delle carte non è più nella rosa di chi doveva cederla';
            }

            $moving[$item->direction][] = $card->role;
        }

        $sides = [
            $trade->proposer_id => RosterFloor::apply(
                $this->countsFor($locked, $trade->proposer_id),
                out: $moving['offered'],
                in: $moving['requested'],
            ),
            $trade->receiver_id => RosterFloor::apply(
                $this->countsFor($locked, $trade->receiver_id),
                out: $moving['requested'],
                in: $moving['offered'],
            ),
        ];

        foreach ($sides as $managerId => $counts) {
            if ($reason = RosterFloor::violation($counts)) {
                $name = Manager::find($managerId)?->name ?? "manager {$managerId}";

                return "{$name} {$reason}";
            }
        }

        return null;
    }

    /**
     * @param  Collection<int,Card>  $locked
     * @return array<string,int>
     */
    private function countsFor(Collection $locked, int $managerId): array
    {
        return $locked->where('owner_manager_id', $managerId)
            ->countBy('role')
            ->all();
    }

    /**
     * @param  Collection<int,TradeItem>  $items
     * @param  Collection<int,Card>  $locked
     */
    private function moveCards(Trade $trade, Collection $items, Collection $locked): void
    {
        foreach ($items as $item) {
            // `original_owner_id` non si tocca: è la provenienza della carta,
            // non il possesso, ed è ciò che rende leggibile il feed a fine
            // giornata.
            $locked[$item->card_id]->update([
                'owner_manager_id' => $trade->destinationFor($item->direction),
            ]);
        }
    }

    /** @param  list<int>  $cardIds */
    private function assertOwns(LeagueSeason $stagione, Manager $manager, array $cardIds, int $matchday): void
    {
        if ($cardIds === []) {
            return;
        }

        $owned = Card::whereIn('id', $cardIds)
            ->where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $manager->id)
            ->where('matchday', $matchday)
            ->count();

        if ($owned !== count(array_unique($cardIds))) {
            throw new TradeException("Carte non nella rosa di {$manager->name} per la giornata {$matchday}.");
        }
    }

    /** @param  list<int>  $cardIds */
    private function attach(Trade $trade, array $cardIds, string $direction): void
    {
        foreach (array_unique($cardIds) as $cardId) {
            TradeItem::create([
                'trade_id' => $trade->id,
                'card_id' => $cardId,
                'direction' => $direction,
            ]);
        }
    }

    private function close(Trade $trade, string $state): Trade
    {
        return DB::transaction(function () use ($trade, $state) {
            $fresh = Trade::whereKey($trade->getKey())->lockForUpdate()->first();

            if (! $fresh?->isPending()) {
                return $fresh ?? $trade;
            }

            return $this->resolve($fresh, $state);
        });
    }

    private function resolve(Trade $trade, string $state, ?string $reason = null): Trade
    {
        $trade->update([
            'state' => $state,
            'reject_reason' => $reason,
            'resolved_at' => now(),
        ]);

        return $trade;
    }
}
