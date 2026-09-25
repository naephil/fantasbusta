<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RisolveStagione;
use App\Services\Power\PowerUpdater;
use Illuminate\Console\Command;

/**
 * Ricalcola power score e tier del listone per una giornata.
 *
 * Va lanciato PRIMA che si apra il draft della giornata indicata, perché il
 * pool si popola per tier: senza queste righe non c'è rarità da distribuire.
 *
 * L'argomento è la giornata di destinazione, non quella appena giocata: per
 * preparare il draft della 12ª si lancia `power:compute 12`, e il calcolo
 * guarderà i dati fino alla 10ª. Vedi PowerUpdater.
 */
class ComputePower extends Command
{
    use RisolveStagione;

    protected $signature = 'power:compute {matchday : Giornata per cui vale il power} {--stagione= : Solo questa stagione di lega}';

    protected $description = 'Ricalcola power score e tier del listone per la giornata';

    public function handle(PowerUpdater $updater): int
    {
        $matchday = (int) $this->argument('matchday');

        $stagioni = $this->stagioniOFallisci();

        if ($stagioni === null) {
            return self::FAILURE;
        }

        foreach ($stagioni as $stagione) {
            $valutati = $updater->update($stagione, $matchday);

            $this->info($this->etichetta($stagione)." — power per la giornata {$matchday}");
            $this->line("  {$valutati} giocatori valutati sui dati fino alla ".max(0, $matchday - 2).'ª');
        }

        return self::SUCCESS;
    }
}
