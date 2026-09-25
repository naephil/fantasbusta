<?php

namespace App\Http\Controllers;

use App\Models\Lineup;
use App\Models\Matchup;
use App\Models\PlayerScore;
use App\Models\PlayerStat;
use App\Services\Scoring\LineupScorer;
use App\Services\Scoring\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Il tabellino di una sfida: chi ha giocato, con che voto, e chi è entrato.
 *
 * Mancava, e la mancanza si sentiva proprio dove il gioco dovrebbe dare più
 * soddisfazione: la classifica diceva «74,5 – 71,0» e non c'era modo di sapere
 * *perché*. Un fantacalcio in cui non si può aprire il tabellino è un
 * generatore di numeri.
 *
 * ⚠️ La cosa che questa pagina deve spiegare più di tutte sono le
 * SOSTITUZIONI. Il draft della giornata chiude prima che escano le formazioni
 * ufficiali, quindi schierare un titolare che poi resta fuori è la norma — e
 * senza vedere chi è entrato al suo posto il totale sembra sbagliato.
 */
class MatchupController extends Controller
{
    public function __construct(private LineupScorer $scorer) {}

    public function show(Request $request, Matchup $matchup): View
    {
        $matchup->load(['home', 'away', 'leagueSeason']);

        // Una sfida si guarda solo se è del proprio gruppo: le rose degli altri
        // gruppi non riguardano nessuno, e l'id è indovinabile a tentativi.
        abort_unless(
            $matchup->leagueSeason->league_id === $request->user()->league_id,
            404,
        );

        $formazioni = Lineup::with(['slots.card.player', 'result', 'manager'])
            ->where('league_season_id', $matchup->league_season_id)
            ->where('matchday', $matchup->matchday)
            ->whereIn('manager_id', [$matchup->home_manager_id, $matchup->away_manager_id])
            ->get()
            ->keyBy('manager_id');

        $voti = $this->voti($matchup);
        $eventi = $this->eventi($matchup);
        $settings = Settings::for($matchup->leagueSeason);

        return view('matchup.show', [
            'sfida' => $matchup,
            'settings' => $settings,
            'casa' => $this->tabellino($formazioni->get($matchup->home_manager_id), $voti, $eventi, $settings),
            'fuori' => $this->tabellino($formazioni->get($matchup->away_manager_id), $voti, $eventi, $settings),
        ]);
    }

    /**
     * Gol, assist e cartellini della giornata, per giocatore.
     *
     * ⚠️ Vengono da `player_stats` e non da `player_scores`: gli eventi sono
     * FATTI dell'annata — condivisi fra tutti i gruppi — mentre il fantavoto è
     * un'interpretazione che dipende dai coefficienti di lega. Due gruppi
     * vedono lo stesso gol e due fantavoti diversi.
     *
     * @return Collection<int,PlayerStat>
     */
    private function eventi(Matchup $matchup): Collection
    {
        return PlayerStat::where('season', $matchup->leagueSeason->season)
            ->where('matchday', $matchup->matchday)
            ->get()
            ->keyBy('player_id');
    }

    /**
     * I fantavoti della giornata, per giocatore.
     *
     * ⚠️ Si leggono da `player_scores` con la `league_season_id`, non
     * dall'annata: lo stesso giocatore nella stessa giornata vale diversamente
     * a seconda di chi lo schiera, perché bonus e malus si tarano per gruppo.
     *
     * @return Collection<int,PlayerScore>
     */
    private function voti(Matchup $matchup): Collection
    {
        return PlayerScore::where('league_season_id', $matchup->league_season_id)
            ->where('matchday', $matchup->matchday)
            ->get()
            ->keyBy('player_id');
    }

    /**
     * La formazione di una parte, già risolta: titolari, entrati, panchina.
     *
     * ⚠️ Le sostituzioni si chiedono a LineupScorer e non si ricalcolano qui.
     * Riscriverle verrebbe fuori simile e non uguale — il giro parte dalla
     * panchina e non dai titolari, c'è un tetto di sostituzioni, «senza voto»
     * è `hasVote()` — e la pagina mostrerebbe una sostituzione diversa da
     * quella che ha prodotto il totale scritto lì accanto.
     *
     * @return array<string,mixed>|null
     */
    private function tabellino(?Lineup $lineup, Collection $voti, Collection $eventi, Settings $settings): ?array
    {
        if (! $lineup) {
            return null;
        }

        $sostituti = $this->scorer->sostituzioniPer($lineup);
        $righe = [];

        // ⚠️ In ordine di REPARTO e non di voto né di inserimento. Gli slot
        // arrivano nell'ordine in cui sono stati spuntati nel modulo, che non
        // è un ordine: il tabellino si legge P-D-C-A, come una formazione.
        $titolari = $lineup->starters()->sortBy(
            fn ($slot) => array_search($slot->card->role, ['P', 'D', 'C', 'A'], true),
        );

        foreach ($titolari as $slot) {
            $entrato = $sostituti[$slot->id]['slot'] ?? null;
            $inCampo = $entrato ?? $slot;
            $voto = $voti->get($inCampo->card->player_id);

            $righe[] = [
                'slot' => $slot,
                'entrato' => $entrato,
                'voto' => $voto?->hasVote() ? $voto->fantavoto : null,
                // Il voto in pagella, accanto al fantavoto: senza, un 9,5 non
                // dice se è un 6 con tre gol o un 9 senza bonus, e sono due
                // giornate molto diverse.
                'base' => $voto?->voto_base,
                // Chi resta senza voto e senza sostituto prende il valore
                // d'ufficio: va mostrato, altrimenti il totale non torna.
                'ufficio' => $voto?->hasVote() ? null : $settings->senzaVoto($inCampo->card->role),
                'eventi' => $eventi->get($inCampo->card->player_id),
            ];
        }

        $entrati = array_map(fn (array $s) => $s['slot']->id, $sostituti);

        return [
            'lineup' => $lineup,
            'righe' => $righe,
            'panchina' => $lineup->bench()->reject(fn ($p) => in_array($p->id, $entrati, true))->values(),
            'totale' => $lineup->result?->totale,
            'penalita' => $lineup->result?->penalita,
        ];
    }
}
