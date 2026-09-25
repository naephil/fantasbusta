<?php

namespace App\Services\Calendar;

use App\Models\LeagueSeason;
use App\Models\Matchup;
use App\Services\Scoring\Settings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Scrive il calendario del campionato di una stagione.
 *
 * La giornata di partenza non è una costante: una stagione può nascere a
 * campionato già iniziato — o essere il replay di un anno passato ripreso a
 * metà — e in quel caso il turno 1 del calendario cade su una giornata di
 * Serie A qualunque. Se non viene passata si legge da `start_matchday`, che è
 * il posto dove la stagione la ricorda.
 */
class CalendarBuilder
{
    /** @return int turni generati */
    public function generate(LeagueSeason $stagione, ?int $startMatchday = null, ?int $gironi = null): int
    {
        $gironi ??= Settings::for($stagione)->gironi();
        $startMatchday ??= $stagione->start_matchday ?? 1;

        // Rigenerare a stagione in corso cancellerebbe risultati già acquisiti.
        // Meglio fermarsi: un calendario si rifà prima di cominciare, non dopo.
        if (Matchup::where('league_season_id', $stagione->id)->whereNull('tournament_id')->where('state', 'played')->exists()) {
            throw new RuntimeException(
                'Il calendario ha già sfide giocate: cancellale a mano prima di rigenerarlo.',
            );
        }

        $ids = $stagione->partecipanti()->pluck('id')->all();
        $calendario = ScheduleGenerator::build($ids, $gironi);

        DB::transaction(function () use ($stagione, $calendario, $startMatchday) {
            // Solo il campionato: le sfide dei tornei hanno un padrone loro e
            // rigenerare il calendario di lega non deve spazzarle via.
            Matchup::where('league_season_id', $stagione->id)->whereNull('tournament_id')->delete();

            foreach ($calendario as $i => $turno) {
                foreach ($turno as [$casa, $fuori]) {
                    Matchup::create([
                        'league_season_id' => $stagione->id,
                        'round' => $i + 1,
                        'matchday' => $startMatchday + $i,
                        'home_manager_id' => $casa,
                        'away_manager_id' => $fuori,
                    ]);
                }
            }
        });

        return count($calendario);
    }
}
