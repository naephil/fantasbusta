<?php

namespace App\Console\Commands;

use App\Models\LeagueSeason;
use App\Services\Calendar\CalendarBuilder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Genera il calendario degli scontri diretti di una stagione.
 *
 * Si lancia una volta, prima che la stagione cominci:
 *
 *   php artisan calendar:generate 1 --start=7
 *
 * fa partire il turno 1 dalla 7ª giornata di Serie A, per una stagione che
 * nasce a campionato iniziato.
 */
class GenerateCalendar extends Command
{
    protected $signature = 'calendar:generate
        {stagione : Id della stagione di lega}
        {--start= : Giornata di Serie A del primo turno, altrimenti quella della stagione}
        {--gironi= : Quanti gironi, altrimenti quelli configurati}';

    protected $description = 'Genera il calendario degli scontri diretti della lega';

    public function handle(CalendarBuilder $builder): int
    {
        $stagione = LeagueSeason::with('league')->find($this->argument('stagione'));

        if (! $stagione) {
            $this->error('Stagione di lega inesistente.');

            return self::FAILURE;
        }

        $start = $this->option('start') !== null ? (int) $this->option('start') : $stagione->start_matchday;
        $gironi = $this->option('gironi') !== null ? (int) $this->option('gironi') : null;

        try {
            $turni = $builder->generate($stagione, $start, $gironi);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ultima = $start + $turni - 1;

        $this->info($stagione->league->name.' · '.$stagione->etichetta()." — {$turni} turni generati.");
        $this->line("  dalla {$start}ª alla {$ultima}ª giornata di Serie A");

        if ($ultima > 38) {
            $this->warn("  ⚠ il calendario sfora la 38ª: servono {$ultima} giornate.");
        }

        return self::SUCCESS;
    }
}
