<?php

namespace App\Services\Draft;

use App\Enums\Role;
use App\Enums\Tier;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPoolEntry;
use App\Models\DraftTurn;
use App\Services\Lineup\ModuleValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Genera una busta pescando dal pool esclusivo della giornata.
 *
 * Va invocato dentro la transazione che tiene il lock sul turno: il pool è già
 * serializzato dalla struttura a turni, ma l'unique (draft_id, player_id) resta
 * la rete finale contro la doppia assegnazione.
 */
class PackGenerator
{
    /**
     * ⚠️ Le probabilità di estrazione NON sono più una costante, ed è una
     * correzione a un difetto strutturale.
     *
     * Erano fisse — 2% Leggendaria, 9% Epica — e non parlavano con la piramide
     * del listone né con quante carte il draft distribuisce davvero. Con dodici
     * manager, cinque giri da cinque, gli slot totali sono 300 su un pool di
     * ~540: il 2% ne produce SEI Leggendarie estratte su SEDICI esistenti.
     * Dieci big restavano liberi ogni volta, e la stessa aritmetica lasciava a
     * spasso quasi quaranta Epiche.
     *
     * Adesso si ricavano da quello che c'è: vedi oddsDalPool().
     */

    /** @return Collection<int,Card> la busta, già ordinata per la rivelazione */
    public function generate(DraftTurn $turn): Collection
    {
        $draft = $turn->draft;
        $manager = $turn->manager;

        $owned = $this->ownedByRole($draft->league_season_id, $manager->id, $draft->matchday);

        $planner = new SlotPlanner(
            ModuleValidator::GUARANTEE,
            packSize: $draft->pack_size,
            totalPacks: $draft->rounds,
        );

        $slots = $planner->plan($owned, $turn->round);
        $drawn = collect();
        $have = $owned + Role::tally();
        $slotsLeft = ($draft->rounds - $turn->round + 1) * $draft->pack_size;

        // Una volta per busta e non per slot: cinque estrazioni su un pool di
        // cinquecento non spostano le proporzioni, e cinque query in meno per
        // ogni busta sono cinque query in meno moltiplicate per sessanta turni.
        //
        // ⚠️ Il budget è quello di TUTTO IL DRAFT, non di questo manager. Con
        // le carte che restano a lui — venticinque su un pool di cinquecento —
        // la cascata concludeva che i sedici Leggendari ci stanno comodi in
        // venticinque slot, e glieli dava praticamente tutti: il primo a
        // pescare si portava via la cima del listone e a tutti gli altri
        // restavano i Comuni. È esattamente il «giocatori che non giocano mai»
        // che si vedeva in partita.
        $odds = $this->oddsDalPool($draft->id, $this->slotsResidui($draft));

        foreach ($slots as $i => $forcedRole) {
            $slotsLeft--;

            $roles = $forcedRole !== null
                ? [$forcedRole]
                : $planner->allowedForFreeSlot($have, $slotsLeft);

            $entry = $this->drawOne($draft->id, $roles, $this->tierFor($drawn, $i, $draft->pack_size, $odds));

            if (! $entry) {
                continue;   // pool esaurito per quel ruolo: slot saltato
            }

            $drawn->push($this->claim($entry, $turn));
            $have[$entry->role]++;
        }

        return $this->orderForReveal($drawn);
    }

    /**
     * Tier da tentare per lo slot corrente.
     *
     * Due garanzie sopra il tiro casuale: almeno una Rara per busta, e la
     * garanzia scatta sull'ultimo slot se non è ancora uscita nulla di meglio.
     *
     * @param  array<string,int>  $odds  pesi ricavati dal pool
     */
    private function tierFor(Collection $drawn, int $slotIndex, int $packSize, array $odds): ?string
    {
        $ultimoSlot = $slotIndex === $packSize - 1;

        // ⚠️ «Niente di buono» si misura sul RANGO, non sull'uguaglianza a
        // Comune. Scritto come confronto secco, una busta di soli Pacco e
        // Monnezza non faceva scattare la garanzia — quelle carte non sono
        // 'comune', quindi la busta risultava già soddisfacente — e sarebbero
        // uscite cinque carte da buttare senza nemmeno una Rara di consolazione.
        // È esattamente la busta che la garanzia esiste per impedire.
        $nienteDiBuono = $drawn->every(fn (Card $c) => Tier::from($c->tier)->rank() <= Tier::Comune->rank());

        if ($ultimoSlot && $nienteDiBuono) {
            return Tier::Rara->value;
        }

        return $this->rollTier($odds);
    }

    /**
     * Quanto spesso ogni tier deve uscire perché i top finiscano tutti in rosa.
     *
     * L'idea in una riga: **il pregio si distribuisce a cascata dall'alto**. Gli
     * slot che restano da pescare in tutto il draft sono un budget; si assegna
     * prima ai Leggendari finché ce n'è, poi agli Epici, poi ai Rari, e i Comuni
     * assorbono quello che avanza. Le probabilità sono quelle quote.
     *
     * Con un pool da ~540 e 300 slot il conto viene: 16 Leggendari e 65 Epici
     * ci stanno tutti dentro il budget, quindi escono tutti; i Rari pure; e i
     * ~57 slot rimasti pescano fra i Comuni. Nessun big resta libero, che era
     * il difetto — senza che nessuna soglia sia scritta a mano da qualche parte.
     *
     * Ricavarle invece di fissarle vuol dire anche che si tarano da sole: una
     * lega da sei manager, tre giri per busta, un listone più corto — cambiano
     * gli slot, cambiano le quote, e la proprietà «i top vengono usati» regge
     * lo stesso.
     *
     * @return array<string,int> tier => peso di estrazione
     */
    /**
     * Quante carte deve ancora distribuire il draft, contando tutti i manager.
     *
     * Il turno in corso è già `active` quando si arriva qui, quindi rientra nel
     * conto — ed è giusto, perché è proprio quello che si sta per riempire.
     */
    private function slotsResidui(Draft $draft): int
    {
        return $draft->turns()
            ->whereIn('state', ['waiting', 'active'])
            ->count() * $draft->pack_size;
    }

    private function oddsDalPool(int $draftId, int $slotsLeft): array
    {
        $disponibili = DraftPoolEntry::where('draft_id', $draftId)
            ->where('status', 'available')
            ->selectRaw('tier, count(*) as n')
            ->groupBy('tier')
            ->pluck('n', 'tier');

        $budget = max(1, $slotsLeft);
        $quote = [];

        // ⚠️ Dal più pregiato al meno, e l'elenco deve essere COMPLETO: si
        // scorreva `Tier::distribution()`, che contiene solo le quattro fasce a
        // percentile. Con l'arrivo di Pacco e Monnezza quelle due sarebbero
        // rimaste fuori dalla cascata — peso di estrazione zero — e non
        // sarebbero mai uscite da una busta, pur essendo la fetta più grossa
        // del pool. Il draft avrebbe distribuito solo carte buone finché ce
        // n'erano, e poi si sarebbe fermato.
        foreach (Tier::dallAltoInBasso() as $tier) {
            $quota = min((int) $disponibili->get($tier, 0), $budget);

            $quote[$tier] = $quota;
            $budget -= $quota;
        }

        // Il pool è più piccolo degli slot: si pesca tutto quello che c'è, e
        // gli slot in eccesso resteranno vuoti — se ne occupa già drawOne.
        return array_sum($quote) > 0 ? $quote : [Tier::Comune->value => 1];
    }

    /**
     * @param  array<string,int>  $odds
     *
     * ⚠️ `mt_rand` e non `random_int`, che era la scelta di prima. Il tiro di
     * una busta non è un segreto da proteggere — è un dado — e `random_int`
     * attinge dal generatore crittografico del sistema, che per costruzione
     * NON si può seminare. Restava quindi una sorgente di caso irriproducibile
     * dentro il draft, e faceva lampeggiare di rosso le misure sulla
     * distribuzione delle carte anche dopo aver sistemato le altre due.
     */
    private function rollTier(array $odds): string
    {
        $roll = mt_rand(1, max(1, array_sum($odds)));

        foreach ($odds as $tier => $weight) {
            if (($roll -= $weight) <= 0) {
                return $tier;
            }
        }

        return Tier::Comune->value;
    }

    /**
     * Estrae una riga disponibile del pool.
     *
     * Se il tier richiesto è esaurito per quel ruolo si ripiega su qualunque
     * tier: meglio una carta di fascia diversa che uno slot vuoto.
     *
     * @param  list<string>  $roles
     */
    private function drawOne(int $draftId, array $roles, ?string $tier): ?DraftPoolEntry
    {
        $base = fn () => DraftPoolEntry::where('draft_id', $draftId)
            ->where('status', 'available')
            ->whereIn('role', $roles);

        return $this->unaACaso((clone $base())->where('tier', $tier))
            ?? $this->unaACaso($base());
    }

    /**
     * Una riga a caso, sorteggiata in PHP e non dal database.
     *
     * ⚠️ Prima era `inRandomOrder()`, cioè un `ORDER BY RANDOM()`, e il caso
     * finiva quindi dentro il motore SQL — dove nessuno può metterci un seme.
     * SQLite non espone nessun modo per fissarlo, quindi una pescata non era
     * riproducibile nemmeno volendo.
     *
     * Non è un problema astratto: le misure sulla distribuzione delle carte —
     * che i pregiati si spartiscano fra i dodici, che nessuno peschi troppo più
     * in alto degli altri — sono statistiche, e senza un seme diventavano rosse
     * a caso qualche volta su dieci. Un test che lampeggia insegna a ignorare i
     * rossi, e a quel punto smette di difendere qualcosa.
     *
     * Sorteggiando in PHP il draft torna riproducibile: stesso seme, stesse
     * buste. È anche più economico — `ORDER BY RANDOM()` ordina l'intero pool a
     * ogni singola pescata, e le pescate per draft sono trecento.
     *
     * L'`orderBy('id')` non è decorazione: senza un ordine dichiarato l'indice
     * sorteggiato pescherebbe righe diverse a seconda di come il database
     * decide di restituirle.
     *
     * @param  Builder<DraftPoolEntry>  $query
     */
    private function unaACaso(Builder $query): ?DraftPoolEntry
    {
        $ids = $query->orderBy('id')->pluck('id');

        if ($ids->isEmpty()) {
            return null;
        }

        return DraftPoolEntry::find($ids[mt_rand(0, $ids->count() - 1)]);
    }

    private function claim(DraftPoolEntry $entry, DraftTurn $turn): Card
    {
        $entry->update([
            'status' => 'drawn',
            'drawn_by_manager_id' => $turn->manager_id,
            'drawn_at' => now(),
        ]);

        return Card::create([
            'league_season_id' => $turn->draft->league_season_id,
            'matchday' => $turn->draft->matchday,
            'player_id' => $entry->player_id,
            'tier' => $entry->tier,          // congelato qui, mai più aggiornato
            'role' => $entry->role,
            'draft_turn_id' => $turn->id,
            'owner_manager_id' => $turn->manager_id,
            'original_owner_id' => $turn->manager_id,
        ]);
    }

    /**
     * La carta migliore va in PENULTIMA posizione, come nei pacchetti Pokémon.
     *
     * Penultima e non quarta: con buste da cinque le due cose coincidevano, ma
     * la regola vera è quella — si costruisce l'attesa fino a lì e si lascia
     * un'ultima carta dopo, così la busta non finisce sull'anticlimax. Fissare
     * il quarto slot avrebbe messo il colpo a metà di una busta da otto.
     *
     * Il riordino avviene DOPO la pesca, mai durante: pescare "prima le comuni,
     * poi la rara" altererebbe le probabilità reali della busta. Così la
     * distribuzione resta quella del generatore e cambia solo la drammaturgia.
     *
     * @param  Collection<int,Card>  $pack
     * @return Collection<int,Card>
     */
    public function orderForReveal(Collection $pack): Collection
    {
        if ($pack->count() < 2) {
            return $pack;
        }

        $sorted = $pack->sortBy(fn (Card $c) => Tier::from($c->tier)->rank())->values();
        $best = $sorted->pop();

        $slot = max(0, $sorted->count() - 1);   // penultima, qualunque sia la lunghezza

        return $sorted->splice(0, $slot)
            ->push($best)
            ->concat($sorted)
            ->values();
    }

    /** @return array<string,int> */
    private function ownedByRole(int $leagueSeasonId, int $managerId, int $matchday): array
    {
        return Card::where('league_season_id', $leagueSeasonId)
            ->where('owner_manager_id', $managerId)
            ->where('matchday', $matchday)
            ->select('role', DB::raw('count(*) as n'))
            ->groupBy('role')
            ->pluck('n', 'role')
            ->all();
    }
}
