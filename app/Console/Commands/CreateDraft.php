<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RisolveStagione;
use App\Services\Draft\DraftBuilder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Prepara il draft di una giornata: pool esclusivo e coda dei turni snake.
 *
 * Va lanciato dopo `power:compute` della stessa giornata — il pool congela i
 * tier dal power score, quindi senza quello non c'è rarità da distribuire.
 * Da lì in avanti se ne occupa `draft:tick`, che lo apre alla data prevista e
 * fa scorrere i turni.
 */
class CreateDraft extends Command
{
    use RisolveStagione;

    protected $signature = 'draft:create
        {matchday : Giornata per cui si pesca}
        {--stagione= : Solo questa stagione di lega}
        {--opens= : Apertura esplicita, altrimenti il primo fischio della giornata precedente}
        {--deadline= : Chiusura esplicita, altrimenti una quota della finestra}';

    protected $description = 'Crea il draft di una giornata con pool e turni snake';

    public function handle(DraftBuilder $builder): int
    {
        $matchday = (int) $this->argument('matchday');

        $stagioni = $this->stagioniOFallisci();

        if ($stagioni === null) {
            return self::FAILURE;
        }

        $esito = self::SUCCESS;

        foreach ($stagioni as $stagione) {
            try {
                $draft = $builder->build(
                    $stagione,
                    $matchday,
                    $this->option('opens'),
                    $this->option('deadline'),
                );
            } catch (RuntimeException $e) {
                $this->error($this->etichetta($stagione).": {$e->getMessage()}");
                $esito = self::FAILURE;

                continue;
            }

            $this->info($this->etichetta($stagione)." — draft della {$matchday}ª pronto");
            $this->line("  {$draft->pool()->count()} carte nel pool");
            $this->line("  {$draft->turns()->count()} turni su {$draft->rounds} giri");
            $this->line("  apre {$draft->opens_at}, chiude {$draft->deadline_at}");
        }

        return $esito;
    }
}
