<?php

namespace App\Services\Scoring;

use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\LineupSlot;
use App\Models\Manager;
use App\Services\Lineup\ModuleValidator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Formazione d'ufficio per chi non ha schierato entro la deadline.
 *
 * Lasciare a zero un dimenticone sarebbe semplice da scrivere e pessimo da
 * giocare: in una lega di amici distorce la classifica di tutti, non solo la
 * sua. Il sistema gli compone la miglior formazione possibile e gli applica
 * una penalità fissa — non è escluso dal campionato, ma non gioca gratis.
 *
 * Il criterio è il power score, che è già la misura di quanto vale una carta
 * e quindi l'approssimazione più onesta di cosa avrebbe schierato lui.
 */
class AutoLineup
{
    public function build(Manager $manager, LeagueSeason $stagione, int $matchday): Lineup
    {
        $cards = $this->rankedCards($manager, $stagione, $matchday);
        $moduli = ModuleValidator::playable($cards->countBy('role')->all());

        if ($moduli === []) {
            // Non dovrebbe accadere: la garanzia della busta e il pavimento
            // degli scambi lo escludono entrambi. Se accade è un bug altrove,
            // e va detto forte invece di produrre una formazione monca.
            throw new RuntimeException(
                "La rosa di {$manager->name} per la giornata {$matchday} non compone nessun modulo.",
            );
        }

        $migliore = $this->bestModule($cards, $moduli);

        return DB::transaction(function () use ($manager, $stagione, $matchday, $migliore, $cards) {
            $lineup = Lineup::create([
                'league_season_id' => $stagione->id,
                'manager_id' => $manager->id,
                'matchday' => $matchday,
                'module' => $migliore['module'],
                'state' => 'locked',
                'auto_generated' => true,
                'locked_at' => now(),
            ]);

            $titolari = $migliore['xi']->pluck('id')->all();

            foreach ($migliore['xi'] as $card) {
                LineupSlot::create([
                    'lineup_id' => $lineup->id,
                    'card_id' => $card->id,
                    'is_starter' => true,
                ]);
            }

            // La panchina eredita l'ordine di power: è la stessa logica con cui
            // sono stati scelti i titolari, quindi le sostituzioni automatiche
            // restano coerenti con il criterio che ha composto la formazione.
            $cards->reject(fn (Card $c) => in_array($c->id, $titolari, true))
                ->values()
                ->each(fn (Card $c, int $i) => LineupSlot::create([
                    'lineup_id' => $lineup->id,
                    'card_id' => $c->id,
                    'is_starter' => false,
                    'bench_order' => $i + 1,
                ]));

            return $lineup->load('slots.card');
        });
    }

    /**
     * Il modulo che estrae più power dalla rosa.
     *
     * Provarli tutti costa sette combinazioni su una rosa di due dozzine
     * scarse: non vale la pena di essere furbi, e la forza bruta è verificabile
     * a occhio.
     *
     * @param  Collection<int,Card>  $cards  già ordinate per power decrescente
     * @param  list<string>  $moduli
     * @return array{module: string, xi: Collection<int,Card>, power: float}
     */
    private function bestModule(Collection $cards, array $moduli): array
    {
        $perRuolo = $cards->groupBy('role');
        $migliore = null;

        foreach ($moduli as $module) {
            $xi = collect(['P' => 1] + ModuleValidator::MODULES[$module])
                ->flatMap(fn (int $n, string $role) => $perRuolo[$role]->take($n))
                ->values();

            $power = (float) $xi->sum('power');

            if ($migliore === null || $power > $migliore['power']) {
                $migliore = compact('module', 'xi', 'power');
            }
        }

        return $migliore;
    }

    /**
     * Le carte del manager, dalla più forte alla più debole.
     *
     * Il power può mancare — un giocatore mai visto prima non ha una riga in
     * `player_power` — e in quel caso vale zero: finisce in fondo, che è
     * esattamente dove lo metterebbe un manager che non lo conosce.
     *
     * @return Collection<int,Card>
     */
    private function rankedCards(Manager $manager, LeagueSeason $stagione, int $matchday): Collection
    {
        return Card::query()
            ->where('cards.league_season_id', $stagione->id)
            ->where('cards.owner_manager_id', $manager->id)
            ->where('cards.matchday', $matchday)
            ->leftJoin('player_power', function ($join) use ($stagione, $matchday) {
                $join->on('player_power.player_id', '=', 'cards.player_id')
                    ->where('player_power.league_season_id', '=', $stagione->id)
                    ->where('player_power.matchday', '=', $matchday);
            })
            ->select('cards.*', DB::raw('coalesce(player_power.power, 0) as power'))
            ->orderByDesc(DB::raw('coalesce(player_power.power, 0)'))
            ->orderBy('cards.id')
            ->get();
    }
}
