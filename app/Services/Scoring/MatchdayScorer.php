<?php

namespace App\Services\Scoring;

use App\Enums\Role;
use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\Manager;
use App\Models\PlayerScore;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;

/**
 * Chiusura di una giornata: prima i fantavoti, poi i totali di formazione.
 *
 * I fantavoti si scrivono per stagione di lega, non per annata: i coefficienti
 * sono tarabili per gruppo, quindi due leghe che rigiocano lo stesso anno
 * producono due tabelle di voti diverse dalle stesse statistiche.
 */
class MatchdayScorer
{
    public function __construct(
        private AutoLineup $autoLineup,
        private LineupScorer $scorer,
    ) {}

    /** @return array{voti: int, formazioni: int, ufficio: int} */
    public function run(LeagueSeason $stagione, int $matchday): array
    {
        return [
            'voti' => $this->scorePlayers($stagione, $matchday),
            ...$this->scoreLineups($stagione, $matchday),
        ];
    }

    /** @return int fantavoti calcolati */
    public function scorePlayers(LeagueSeason $stagione, int $matchday): int
    {
        $calculator = new FantavotoCalculator(Settings::for($stagione));
        $season = $stagione->season;
        $stagioneId = $stagione->id;
        $fatti = 0;

        // ⚠️ I ruoli si leggono TUTTI in una volta, prima del ciclo. Chiederli
        // riga per riga sembrava innocuo e costava una query per giocatore:
        // con millecento statistiche a giornata erano millecento query in più,
        // e su una stagione intera più di quarantamila. È la differenza fra
        // una giornata che si calcola in pochi secondi e una che sfonda il
        // limite di esecuzione del server.
        $ruoli = PlayerSeason::where('season', $season)
            ->pluck('role', 'player_id')
            ->map(fn ($r) => $r instanceof Role ? $r->value : (string) $r);

        $adesso = now();

        PlayerStat::where('season', $season)
            ->where('matchday', $matchday)
            ->chunkById(500, function ($stats) use ($calculator, $ruoli, $stagioneId, $matchday, $adesso, &$fatti) {
                $righe = [];

                foreach ($stats as $stat) {
                    // Il ruolo viene dal listone di QUELL'ANNO: il gol subito
                    // pesa solo sul portiere, e chi era portiere nel 2022 può
                    // non esserlo adesso.
                    $ruolo = $ruoli[$stat->player_id] ?? null;

                    if (! $ruolo) {
                        continue;   // non era in Serie A quell'anno
                    }

                    $righe[] = $calculator->forStat($stat, $ruolo) + [
                        'league_season_id' => $stagioneId,
                        'player_id' => $stat->player_id,
                        'matchday' => $matchday,
                        'created_at' => $adesso,
                        'updated_at' => $adesso,
                    ];
                }

                if ($righe === []) {
                    return;
                }

                // In blocco e non riga per riga: `updateOrCreate` fa due query
                // a giocatore, e su mille giocatori sono duemila query per
                // giornata. Il ricalcolo resta idempotente perché l'unique
                // (stagione, giocatore, giornata) trasforma il doppione in un
                // aggiornamento.
                PlayerScore::upsert(
                    $righe,
                    ['league_season_id', 'player_id', 'matchday'],
                    ['voto_base', 'bonus', 'malus', 'fantavoto', 'updated_at'],
                );

                $fatti += count($righe);
            });

        return $fatti;
    }

    /** @return array{formazioni: int, ufficio: int} */
    public function scoreLineups(LeagueSeason $stagione, int $matchday): array
    {
        $formazioni = 0;
        $ufficio = 0;

        foreach ($stagione->partecipanti() as $manager) {
            // Un manager entrato a stagione in corso può non avere rosa per le
            // giornate precedenti: non è un errore, semplicemente non gioca.
            if (! $this->hasRoster($manager, $stagione, $matchday)) {
                continue;
            }

            $lineup = $this->lineupOf($manager, $stagione, $matchday);

            if ($lineup === null) {
                $lineup = $this->autoLineup->build($manager, $stagione, $matchday);
                $ufficio++;
            }

            $this->scorer->score($lineup);
            $formazioni++;
        }

        return compact('formazioni', 'ufficio');
    }

    private function lineupOf(Manager $manager, LeagueSeason $stagione, int $matchday): ?Lineup
    {
        return Lineup::where('league_season_id', $stagione->id)
            ->where('manager_id', $manager->id)
            ->where('matchday', $matchday)
            ->first();
    }

    private function hasRoster(Manager $manager, LeagueSeason $stagione, int $matchday): bool
    {
        return Card::where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $manager->id)
            ->where('matchday', $matchday)
            ->exists();
    }
}
