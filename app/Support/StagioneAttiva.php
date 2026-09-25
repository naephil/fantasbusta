<?php

namespace App\Support;

use App\Models\LeagueSeason;
use App\Models\Manager;
use Illuminate\Database\Eloquent\Collection;

/**
 * Qual è la stagione che si sta guardando adesso.
 *
 * Da quando un gruppo può avere più annate — la 2023/24 conclusa, la 2024/25 in
 * corso — «la lega dell'utente» non basta più a decidere cosa mostrare. Serve
 * un secondo pezzo di stato, e vive in sessione: è una scelta di navigazione,
 * non un dato del gioco, e non deve sopravvivere al logout né sporcare il
 * profilo del manager.
 *
 * ⚠️ L'id in sessione viene sempre riconvalidato contro il gruppo dell'utente.
 * È l'unica difesa contro il classico: cambiare a mano il numero e guardare la
 * stagione di un altro gruppo.
 */
class StagioneAttiva
{
    private const CHIAVE = 'stagione_attiva';

    /**
     * Memoria per richiesta.
     *
     * Il servizio è un singleton e queste due domande arrivano da ogni vista
     * che le mostra — l'intestazione, la pagina delle regole, quella di
     * gestione. Senza memoria sarebbero una lettura di sessione e una query a
     * testa, moltiplicate per ogni vista annidata.
     *
     * @var array<int,LeagueSeason|null>
     */
    private array $memoria = [];

    /** @var array<int,Collection<int,LeagueSeason>> */
    private array $memoriaElenco = [];

    /** Quella da mostrare: la scelta in sessione, o la più viva del gruppo. */
    public function per(Manager $manager): ?LeagueSeason
    {
        if (array_key_exists($manager->id, $this->memoria)) {
            return $this->memoria[$manager->id];
        }

        return $this->memoria[$manager->id] = $this->cerca($manager);
    }

    private function cerca(Manager $manager): ?LeagueSeason
    {
        $scelta = session(self::CHIAVE);

        if ($scelta) {
            $stagione = LeagueSeason::with('league')
                ->where('league_id', $manager->league_id)
                ->find($scelta);

            if ($stagione) {
                return $stagione;
            }

            session()->forget(self::CHIAVE);   // non è sua, o non c'è più
        }

        return $manager->league?->stagioneCorrente()?->load('league');
    }

    /** Cambia stagione, se è davvero del suo gruppo. */
    public function scegli(Manager $manager, int $leagueSeasonId): bool
    {
        $sua = LeagueSeason::where('league_id', $manager->league_id)
            ->whereKey($leagueSeasonId)
            ->exists();

        if ($sua) {
            session([self::CHIAVE => $leagueSeasonId]);
            $this->memoria = [];   // la scelta è cambiata: la memoria non vale più
        }

        return $sua;
    }

    /**
     * Le stagioni fra cui può scegliere, dalla più recente.
     *
     * @return Collection<int,LeagueSeason>
     */
    public function disponibili(Manager $manager): Collection
    {
        return $this->memoriaElenco[$manager->id] ??= LeagueSeason::where('league_id', $manager->league_id)
            ->orderByDesc('season')
            ->get();
    }

    /** Da chiamare quando una stagione sparisce, per non lasciare un id morto. */
    public function dimentica(): void
    {
        session()->forget(self::CHIAVE);
        $this->memoria = [];
        $this->memoriaElenco = [];
    }
}
