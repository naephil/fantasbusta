<?php

namespace App\Console\Commands;

use App\Services\Simulation\MatchdaySimulator;
use Illuminate\Console\Command;

/**
 * Genera statistiche finte per una giornata.
 *
 * Alternativa a `sync:stats` quando si sta collaudando: stessa forma di dati,
 * zero chiamate all'API. I gol vengono dal risultato vero della partita, se
 * c'è, quindi il tabellino resta coerente.
 */
class SimulateMatchday extends Command
{
    protected $signature = 'simula:giornata {matchday : Giornata da simulare} {anno : Annata di Serie A} {--seed= : Per ripetere la stessa simulazione}
        {--sovrascrivi : Cancella anche eventuali statistiche vere}';

    protected $description = 'Genera statistiche di giornata plausibili, senza chiamare l\'API';

    public function handle(MatchdaySimulator $simulatore): int
    {
        $matchday = (int) $this->argument('matchday');
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : null;

        try {
            $esito = $simulatore->simulate(
                (int) $this->argument('anno'),
                $matchday,
                $seed,
                (bool) $this->option('sovrascrivi'),
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($esito['partite'] === 0) {
            $this->error("Nessuna partita in calendario per la giornata {$matchday}.");

            return self::FAILURE;
        }

        $this->info("Giornata {$matchday} simulata");
        $this->line("  {$esito['partite']} partite, {$esito['giocatori']} righe di statistica");
        $this->line('  ora: `score:matchday '.$matchday.'`');

        return self::SUCCESS;
    }
}
