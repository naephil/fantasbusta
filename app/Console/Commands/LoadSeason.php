<?php

namespace App\Console\Commands;

use App\Services\Season\SeasonLoader;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Scarica i dati di riferimento di un'annata di Serie A.
 *
 * Sul piano gratuito sono disponibili le stagioni dal 2022 al 2024, coi voti
 * veri: rigiocarle non è una simulazione, sono i rating di allora.
 *
 * Le annate convivono: caricare il 2024 non tocca il 2023, e un gruppo può
 * avere una stagione conclusa e una in corso senza che si pestino i piedi.
 * Rilanciarlo sulla stessa annata la aggiorna — utile quando il caricamento
 * si interrompe a metà per il tetto di chiamate.
 */
class LoadSeason extends Command
{
    protected $signature = 'stagione:carica {anno : Anno d\'inizio, es. 2023 per la 2023/24}';

    protected $description = 'Scarica squadre, listone e calendario di un\'annata di Serie A';

    public function handle(SeasonLoader $loader): int
    {
        $anno = (int) $this->argument('anno');
        $etichetta = $anno.'/'.substr((string) ($anno + 1), 2);

        $giaInCasa = $loader->disponibili()->all();

        if ($giaInCasa !== []) {
            $this->line('Già in casa: '.implode(', ', $giaInCasa));
        }

        try {
            $esito = $loader->carica($anno);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Annata {$etichetta} caricata");
        $this->line("  {$esito['squadre']} squadre · {$esito['giocatori']} giocatori");
        $this->line("  {$esito['partite']} partite su {$esito['giornate']} giornate");
        $this->newLine();
        $this->line("Ora: `listone:import <file> {$anno}` per ruoli e quotazioni,");
        $this->line('poi crea una stagione di lega dalla pagina di gestione.');

        return self::SUCCESS;
    }
}
