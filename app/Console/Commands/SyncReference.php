<?php

namespace App\Console\Commands;

use App\Models\PlayerSeason;
use App\Services\Ingest\ReferenceSync;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Sincronizza squadre, giocatori e calendario di un'annata.
 *
 * Si lancia a inizio stagione e poi dopo ogni finestra di mercato: gli acquisti
 * entrano da soli, ma col ruolo indovinato dalla posizione inglese dell'API.
 * Chi resta con `role_confirmed` falso va sistemato a mano — `players:review`
 * li elenca.
 *
 * L'annata è esplicita perché in casa ce ne può essere più d'una: due gruppi
 * possono giocare anni diversi, e un sync senza anno non saprebbe quale
 * listone aggiornare.
 */
class SyncReference extends Command
{
    protected $signature = 'sync:reference {anno : Annata di Serie A, es. 2025} {--solo= : teams, players o fixtures}';

    protected $description = 'Sincronizza squadre, giocatori e calendario di un\'annata di Serie A';

    public function handle(ReferenceSync $sync): int
    {
        $solo = $this->option('solo');
        $anno = (int) $this->argument('anno');

        try {
            if (! $solo || $solo === 'teams') {
                $this->line(sprintf('Squadre: %d', $sync->teams($anno)));
            }

            if (! $solo || $solo === 'players') {
                $esito = $sync->players($anno);
                $this->line(sprintf(
                    'Giocatori: %d sincronizzati, %d nuovi, %d disattivati',
                    $esito['sincronizzati'],
                    $esito['nuovi'],
                    $esito['disattivati'],
                ));
            }

            if (! $solo || $solo === 'fixtures') {
                $this->line(sprintf('Partite: %d', $sync->fixtures($anno)));
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $daRivedere = PlayerSeason::where('season', $anno)
            ->where('active', true)
            ->where('role_confirmed', false)
            ->count();

        if ($daRivedere > 0) {
            $this->warn("⚠ {$daRivedere} giocatori hanno un ruolo solo ipotizzato: `php artisan players:review {$anno}`");
        }

        return self::SUCCESS;
    }
}
