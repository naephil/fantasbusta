<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RisolveStagione;
use App\Services\Season\SeasonRunner;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Gioca una giornata della stagione caricata.
 *
 * Fa tutto in una volta: scarica le statistiche, calcola i voti, chiude le
 * sfide, aggiorna la classifica, fa avanzare i tornei e apre il draft
 * successivo.
 */
class PlayMatchday extends Command
{
    use RisolveStagione;

    protected $signature = 'stagione:gioca
        {matchday? : Giornata da giocare; senza, la prima non ancora giocata}
        {--stagione= : Solo questa stagione di lega}
        {--simula : Genera le statistiche invece di scaricarle}
        {--ore=48 : Quanto resta aperto il draft successivo}';

    protected $description = 'Gioca una giornata: statistiche, voti, classifica, prossimo draft';

    public function handle(SeasonRunner $runner): int
    {
        $stagioni = $this->stagioniOFallisci();

        if ($stagioni === null) {
            return self::FAILURE;
        }

        foreach ($stagioni as $stagione) {
            // La giornata si risolve per stagione e non una volta sola: due
            // gruppi che giocano annate diverse sono a punti diversi del
            // calendario, e un numero solo ne servirebbe bene uno e male l'altro.
            $matchday = $this->argument('matchday')
                ? (int) $this->argument('matchday')
                : $runner->prossima($stagione);

            if (! $matchday) {
                $this->warn($this->etichetta($stagione).' — nessuna giornata da giocare.');

                continue;
            }

            if (! $this->option('simula')) {
                $costo = $runner->costoChiamate($stagione->season, $matchday);
                $this->line("Costa {$costo} chiamate all'API (una per partita).");
            }

            try {
                $esito = $runner->gioca(
                    $stagione,
                    $matchday,
                    (bool) $this->option('simula'),
                    (int) $this->option('ore'),
                );
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $this->info($this->etichetta($stagione)." — giornata {$matchday} ({$esito['fonte']})");
            $this->line("  {$esito['voti']} fantavoti · {$esito['formazioni']} formazioni, {$esito['ufficio']} d'ufficio");
            $this->line("  classifica per {$esito['classifica']} squadre · {$esito['tornei']} tornei avanzati");
            $this->line('  draft: '.($esito['draft'] ?? 'nessuno, stagione finita'));
        }

        return self::SUCCESS;
    }
}
