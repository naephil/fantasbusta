<?php

namespace App\Console\Commands;

use App\Services\Ingest\ListoneImport;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Importa ruoli e quotazioni dal listone.
 *
 * Da lanciare dopo `sync:reference` della stessa annata, perché l'abbinamento
 * cerca fra i giocatori già in anagrafica per quell'anno. Accetta direttamente
 * l'.xlsx ufficiale.
 */
class ImportListone extends Command
{
    protected $signature = 'listone:import {file : Listone in .xlsx o .csv} {anno : Annata a cui si riferisce, es. 2025}';

    protected $description = 'Importa ruoli e quotazioni iniziali dal listone';

    public function handle(ListoneImport $import): int
    {
        try {
            $esito = $import->fromFile($this->argument('file'), (int) $this->argument('anno'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$esito['abbinati']} giocatori abbinati.");

        foreach (['ambigui' => 'più di un candidato', 'mancanti' => 'nessun candidato'] as $chiave => $motivo) {
            if ($esito[$chiave] === []) {
                continue;
            }

            $this->warn(sprintf('⚠ %d righe con %s:', count($esito[$chiave]), $motivo));

            foreach (array_slice($esito[$chiave], 0, 20) as $riga) {
                $this->line("    {$riga}");
            }

            if (count($esito[$chiave]) > 20) {
                $this->line('    … e altre '.(count($esito[$chiave]) - 20));
            }
        }

        $this->newLine();
        $this->warn('Gli abbinamenti NON sono verificati: `players:review` per il controllo a occhio.');

        return self::SUCCESS;
    }
}
