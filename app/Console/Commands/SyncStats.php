<?php

namespace App\Console\Commands;

use App\Services\Ingest\StatSync;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Scarica le statistiche di una giornata.
 *
 * Costa una chiamata per partita, dieci in tutto. Va lanciato a giornata
 * conclusa: le partite ancora in corso vengono saltate apposta, perché una
 * statistica parziale è peggio di una assente — un rating non ancora
 * pubblicato verrebbe letto come «senza voto».
 */
class SyncStats extends Command
{
    protected $signature = 'sync:stats {matchday : Giornata da scaricare} {anno : Annata di Serie A} {--force : Include anche le partite non finite}';

    protected $description = 'Scarica le statistiche di giornata da API-Football';

    public function handle(StatSync $sync): int
    {
        $matchday = (int) $this->argument('matchday');

        try {
            $esito = $sync->matchday((int) $this->argument('anno'), $matchday, (bool) $this->option('force'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Giornata {$matchday}");
        $this->line("  {$esito['partite']} partite, {$esito['giocatori']} righe di statistica");

        if ($esito['ripulite'] > 0) {
            $this->line("  {$esito['ripulite']} righe simulate sostituite con quelle vere.");
        }

        if ($esito['aggiunti'] > 0) {
            $this->line("  {$esito['aggiunti']} giocatori non erano in anagrafica e sono stati aggiunti dal tabellino.");
        }

        if ($esito['saltate'] > 0) {
            $this->warn("  ⚠ {$esito['saltate']} partite non ancora finite, saltate. Rilancia a giornata conclusa.");
        }

        return self::SUCCESS;
    }
}
