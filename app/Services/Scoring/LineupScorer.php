<?php

namespace App\Services\Scoring;

use App\Models\Lineup;
use App\Models\LineupResult;
use App\Models\LineupSlot;
use App\Models\PlayerScore;
use Illuminate\Support\Collection;

/**
 * Calcola il totale di una formazione, sostituzioni automatiche comprese.
 *
 * Le sostituzioni non sono un accessorio: il draft della giornata N+1 si chiude
 * prima che escano le formazioni ufficiali, quindi pescare un titolare che poi
 * resta in panchina è la norma e non l'eccezione. Vedi docs/DESIGN.md §5.2.
 */
class LineupScorer
{
    public function score(Lineup $lineup): LineupResult
    {
        $settings = Settings::for($lineup->leagueSeason);

        $lineup->load('slots.card');
        $scores = $this->scoresFor($lineup);

        $titolari = $lineup->starters();
        $sostituti = $this->substitute($titolari, $lineup->bench(), $scores, $settings);

        $totale = 0.0;
        $portiereUfficio = false;

        foreach ($titolari as $slot) {
            $inCampo = $sostituti[$slot->id]['slot'] ?? $slot;
            $voto = $scores->get($inCampo->card->player_id)?->fantavoto;

            if ($voto !== null) {
                $totale += $voto;

                continue;
            }

            // Senza voto e senza nessuno che possa entrare al suo posto.
            $ruolo = $inCampo->card->role;
            $totale += $settings->senzaVoto($ruolo);

            $portiereUfficio = $portiereUfficio || $ruolo === 'P';
        }

        // La penalità punisce chi si è scordato di schierare. Un bot non si
        // scorda: gioca d'ufficio per costruzione, e punirlo per questo lo
        // renderebbe un avversario finto — perderebbe sempre, e la classifica
        // di una prova non direbbe più niente.
        $penalita = $lineup->auto_generated && ! $lineup->manager->is_bot
            ? $settings->penalitaFormazioneMancante()
            : 0.0;

        return LineupResult::updateOrCreate(
            ['lineup_id' => $lineup->id],
            [
                'totale' => round($totale + $penalita, 2),
                'penalita' => $penalita,
                'sostituzioni' => array_values(array_map(
                    fn (array $s) => $s['report'],
                    $sostituti,
                )),
                'portiere_ufficio' => $portiereUfficio,
                'computed_at' => now(),
            ],
        );
    }

    /**
     * Chi è entrato per chi, senza ricalcolare il punteggio.
     *
     * Esiste per il tabellino, che deve SPIEGARE un totale già scritto. Se se
     * la riscrivesse per conto suo verrebbe fuori una regola simile e non
     * uguale — il giro parte dalla panchina e non dai titolari, c'è un tetto
     * alle sostituzioni, «senza voto» è `hasVote()` e non un null — e la
     * pagina finirebbe per mostrare una sostituzione diversa da quella che ha
     * prodotto il numero lì accanto. Meglio una chiamata in più che due verità.
     *
     * @return array<int,array{slot: LineupSlot, report: array}> indicizzato per slot sostituito
     */
    public function sostituzioniPer(Lineup $lineup): array
    {
        $lineup->loadMissing('slots.card');

        return $this->substitute(
            $lineup->starters(),
            $lineup->bench(),
            $this->scoresFor($lineup),
            Settings::for($lineup->leagueSeason),
        );
    }

    /**
     * Chi entra per chi.
     *
     * Il giro è guidato dalla PANCHINA, non dai titolari mancanti: la regola
     * dice che il titolare senza voto è rimpiazzato dalla prima carta in
     * panchina del suo ruolo, e l'ordine della panchina è l'unica cosa che il
     * manager controlla davvero. Scorrendo invece i titolari si finirebbe per
     * consumare le sostituzioni disponibili in un ordine che non ha scelto lui.
     *
     * Il modulo resta invariato: si sostituisce ruolo su ruolo, sempre.
     *
     * @param  Collection<int,LineupSlot>  $titolari
     * @param  Collection<int,LineupSlot>  $panchina
     * @param  Collection<int,PlayerScore>  $scores
     * @return array<int,array{slot: LineupSlot, report: array}> indicizzato per slot sostituito
     */
    private function substitute(
        Collection $titolari,
        Collection $panchina,
        Collection $scores,
        Settings $settings,
    ): array {
        $hasVote = fn (LineupSlot $s) => $scores->get($s->card->player_id)?->hasVote() ?? false;
        $sostituti = [];

        foreach ($panchina as $panchinaro) {
            if (count($sostituti) >= $settings->maxSostituzioni()) {
                break;
            }

            if (! $hasVote($panchinaro)) {
                continue;   // entrerebbe un altro senza voto: non risolve nulla
            }

            $uscente = $titolari->first(fn (LineupSlot $t) => ! isset($sostituti[$t->id])
                && $t->card->role === $panchinaro->card->role
                && ! $hasVote($t));

            if (! $uscente) {
                continue;
            }

            $sostituti[$uscente->id] = [
                'slot' => $panchinaro,
                'report' => [
                    'ruolo' => $uscente->card->role,
                    'esce' => $uscente->card->player_id,
                    'entra' => $panchinaro->card->player_id,
                ],
            ];
        }

        return $sostituti;
    }

    /** @return Collection<int,PlayerScore> indicizzata per player_id */
    private function scoresFor(Lineup $lineup): Collection
    {
        return PlayerScore::where('league_season_id', $lineup->league_season_id)
            ->where('matchday', $lineup->matchday)
            ->whereIn('player_id', $lineup->slots->pluck('card.player_id'))
            ->get()
            ->keyBy('player_id');
    }
}
