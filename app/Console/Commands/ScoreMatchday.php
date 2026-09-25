<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RisolveStagione;
use App\Services\Calendar\StandingsUpdater;
use App\Services\Scoring\MatchdayScorer;
use App\Services\Tournament\TournamentService;
use Illuminate\Console\Command;

/**
 * Chiude una giornata: fantavoti, sostituzioni automatiche, totali.
 *
 * Va lanciato quando le statistiche della giornata sono complete, non appena
 * finisce l'ultima partita: un giocatore ancora privo di rating verrebbe letto
 * come senza voto e farebbe scattare una sostituzione che non serviva. È
 * rieseguibile — ricalcola sovrascrivendo — quindi in caso di dubbio si rilancia.
 */
class ScoreMatchday extends Command
{
    use RisolveStagione;

    protected $signature = 'score:matchday {matchday : Giornata da calcolare} {--stagione= : Solo questa stagione di lega}';

    protected $description = 'Calcola fantavoti e totali di formazione per la giornata';

    public function handle(MatchdayScorer $scorer, StandingsUpdater $standings, TournamentService $tornei): int
    {
        $matchday = (int) $this->argument('matchday');

        $stagioni = $this->stagioniOFallisci();

        if ($stagioni === null) {
            return self::FAILURE;
        }

        foreach ($stagioni as $stagione) {
            $esito = $scorer->run($stagione, $matchday);

            // Le sfide si risolvono solo ora: prima non ci sarebbero i totali
            // da confrontare.
            $inClassifica = $standings->update($stagione, $matchday);

            $this->info($this->etichetta($stagione)." — giornata {$matchday}");
            $this->line("  {$esito['voti']} fantavoti");
            $this->line("  {$esito['formazioni']} formazioni, di cui {$esito['ufficio']} d'ufficio");
            $this->line("  classifica aggiornata per {$inClassifica} manager");

            // I tornei si muovono per ultimi: leggono i punteggi, che prima
            // di adesso non esistevano.
            if ($mossi = $tornei->avanzaTutti($stagione, $matchday)) {
                $this->line("  {$mossi} tornei avanzati");
            }
        }

        return self::SUCCESS;
    }
}
