<?php

namespace App\Services\Tournament;

use App\Models\LeagueSeason;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creazione e avanzamento dei tornei.
 *
 * Il servizio non sa niente di gironi né di tabelloni: chiede al formato di
 * fare la sua parte. È il motivo per cui aggiungerne uno nuovo non tocca
 * questo file.
 */
class TournamentService
{
    /**
     * @param  list<int>  $managerIds  nell'ordine di testa di serie
     * @param  array<string,mixed>  $settings
     *
     * @throws RuntimeException
     */
    public function crea(
        LeagueSeason $stagione,
        string $nome,
        string $formato,
        array $managerIds,
        int $startMatchday,
        array $settings = [],
    ): Tournament {
        $classe = config("tornei.formati.{$formato}");

        if (! $classe) {
            throw new RuntimeException("Formato sconosciuto: {$formato}.");
        }

        $managerIds = array_values(array_unique($managerIds));
        [$min, $max] = app($classe)->partecipanti();

        if (count($managerIds) < $min || count($managerIds) > $max) {
            throw new RuntimeException(
                "Questo formato vuole fra {$min} e {$max} partecipanti, ne hai scelti ".count($managerIds).'.',
            );
        }

        $iscritti = $stagione->partecipanti()->pluck('id')->all();

        if (array_diff($managerIds, $iscritti) !== []) {
            throw new RuntimeException('Qualche partecipante non gioca questa stagione.');
        }

        return DB::transaction(function () use ($stagione, $nome, $formato, $managerIds, $startMatchday, $settings) {
            $torneo = Tournament::create([
                'league_season_id' => $stagione->id,
                'name' => $nome,
                'format' => $formato,
                'state' => 'bozza',
                'start_matchday' => $startMatchday,
                'settings' => $settings,
            ]);

            // L'ordine di arrivo è l'ordine di testa di serie: chi crea il
            // torneo decide chi è la numero uno, e da lì discendono
            // accoppiamenti e spareggi.
            foreach ($managerIds as $i => $managerId) {
                TournamentEntry::create([
                    'tournament_id' => $torneo->id,
                    'manager_id' => $managerId,
                    'seed' => $i + 1,
                ]);
            }

            return $torneo->fresh();
        });
    }

    /** Genera la struttura e mette il torneo in gioco. */
    public function avvia(Tournament $torneo): Tournament
    {
        if ($torneo->state !== 'bozza') {
            throw new RuntimeException('Il torneo è già stato avviato.');
        }

        DB::transaction(function () use ($torneo) {
            $torneo->formato()->avvia($torneo);
            $torneo->update(['state' => 'in_corso']);
        });

        return $torneo->fresh();
    }

    /**
     * Fa avanzare tutti i tornei della stagione che coprono questa giornata.
     *
     * Va chiamato DOPO il calcolo dei punteggi: i formati leggono i totali di
     * formazione, e prima non esistono.
     *
     * @return int tornei mossi
     */
    public function avanzaTutti(LeagueSeason $stagione, int $matchday): int
    {
        $mossi = 0;

        $tornei = Tournament::where('league_season_id', $stagione->id)
            ->where('state', 'in_corso')
            ->get();

        foreach ($tornei as $torneo) {
            if (! $torneo->copre($matchday)) {
                continue;
            }

            $torneo->formato()->avanza($torneo, $matchday);
            $mossi++;
        }

        return $mossi;
    }

    public function elimina(Tournament $torneo): void
    {
        // Le sfide se ne vanno con lui: sono sue, non del campionato.
        $torneo->matchups()->delete();
        $torneo->delete();
    }
}
