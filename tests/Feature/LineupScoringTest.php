<?php

namespace Tests\Feature;

use App\Models\Lineup;
use App\Models\PlayerScore;
use App\Models\PlayerStat;
use App\Services\Scoring\AutoLineup;
use App\Services\Scoring\LineupScorer;
use App\Services\Scoring\MatchdayScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Totale di formazione, sostituzioni automatiche e formazione d'ufficio.
 */
class LineupScoringTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LineupScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new LineupScorer;
    }

    private function matchdayScorer(): MatchdayScorer
    {
        return new MatchdayScorer(new AutoLineup, $this->scorer);
    }

    // ───────────────────────── il totale ─────────────────────────

    public function test_il_totale_e_la_somma_degli_undici(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA');
        $this->scoreAll($rosa, 6.0);

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        $this->assertSame(66.0, $result->totale);
        $this->assertSame([], $result->sostituzioni);
        $this->assertFalse($result->portiere_ufficio);
    }

    // ───────────────────────── sostituzioni ─────────────────────────

    public function test_il_senza_voto_e_rimpiazzato_dal_primo_in_panchina_dello_stesso_ruolo(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        // La dodicesima carta è un difensore e finisce in panchina.
        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAAD');
        $this->scoreAll($rosa, 6.0);

        $titolareSv = $rosa->where('role', 'D')->first();
        $panchinaro = $rosa->last();

        $this->rescore($titolareSv, null);
        $this->rescore($panchinaro, 7.0);

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        $this->assertSame(67.0, $result->totale);   // 10 × 6 + 7
        $this->assertCount(1, $result->sostituzioni);
        $this->assertSame($panchinaro->player_id, $result->sostituzioni[0]['entra']);
        $this->assertSame($titolareSv->player_id, $result->sostituzioni[0]['esce']);
    }

    public function test_la_panchina_di_un_altro_ruolo_non_entra(): void
    {
        // Il modulo resta invariato: si sostituisce ruolo su ruolo, sempre.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAAC');   // in panchina un centrocampista
        $this->scoreAll($rosa, 6.0);

        $this->rescore($rosa->where('role', 'D')->first(), null);

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        $this->assertSame(60.0, $result->totale);   // 10 × 6, il difensore vale 0
        $this->assertCount(0, $result->sostituzioni);
    }

    public function test_l_ordine_della_panchina_vince_sulla_qualita(): void
    {
        // Il manager ha messo davanti il difensore peggiore: è una sua scelta,
        // e il sistema la rispetta invece di ottimizzare al posto suo.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAADD');
        $this->scoreAll($rosa, 6.0);

        $this->rescore($rosa->where('role', 'D')->first(), null);

        $primoInPanchina = $rosa->get(11);
        $secondoInPanchina = $rosa->get(12);

        $this->rescore($primoInPanchina, 7.0);
        $this->rescore($secondoInPanchina, 9.0);

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        $this->assertSame(67.0, $result->totale);
        $this->assertSame($primoInPanchina->player_id, $result->sostituzioni[0]['entra']);
    }

    public function test_le_sostituzioni_si_fermano_al_massimo_configurato(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAADDDD');
        $this->scoreAll($rosa, 6.0);

        // Quattro difensori titolari senza voto, quattro rincalzi validi.
        $rosa->where('role', 'D')->take(4)->each(
            fn ($c) => $this->rescore($c, null),
        );
        $rosa->where('role', 'D')->slice(4)->each(
            fn ($c) => $this->rescore($c, 7.0),
        );

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        // 3 entrati a 7, il quarto resta senza voto e vale 0.
        $this->assertCount(3, $result->sostituzioni);
        $this->assertSame(63.0, $result->totale);   // 6 + 21 + 0 + 24 + 12
    }

    // ───────────────────────── il portiere ─────────────────────────

    public function test_portiere_senza_voto_e_senza_riserva_prende_quattro(): void
    {
        // La regola che dà valore al secondo portiere sul mercato: senza una
        // penalità, non averlo non costerebbe nulla.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA');
        $this->scoreAll($rosa, 6.0);

        $this->rescore($rosa->firstWhere('role', 'P'), null);

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        $this->assertSame(64.0, $result->totale);   // 4 + 10 × 6
        $this->assertTrue($result->portiere_ufficio);
    }

    public function test_col_secondo_portiere_in_panchina_il_quattro_non_scatta(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAAP');
        $this->scoreAll($rosa, 6.0);

        $this->rescore($rosa->firstWhere('role', 'P'), null);
        $this->rescore($rosa->last(), 6.5);

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        $this->assertSame(66.5, $result->totale);
        $this->assertFalse($result->portiere_ufficio);
    }

    // ───────────────────────── taratura di lega ─────────────────────────

    public function test_la_lega_puo_ritarare_il_senza_voto(): void
    {
        $league = $this->makeLeague('Lega mite', [
            'senza_voto' => ['default' => 5, 'P' => 5.5],
        ]);
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA');
        $this->scoreAll($rosa, 6.0);

        $this->rescore($rosa->firstWhere('role', 'P'), null);

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        $this->assertSame(65.5, $result->totale);   // 5.5 + 10 × 6
    }

    public function test_la_lega_puo_cambiare_il_numero_di_sostituzioni(): void
    {
        $league = $this->makeLeague('Lega larga', ['sostituzioni' => ['max' => 4]]);
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAADDDD');
        $this->scoreAll($rosa, 6.0);

        $rosa->where('role', 'D')->take(4)->each(
            fn ($c) => $this->rescore($c, null),
        );
        $rosa->where('role', 'D')->slice(4)->each(
            fn ($c) => $this->rescore($c, 7.0),
        );

        $result = $this->scorer->score($this->makeLineup($marco, $rosa));

        $this->assertCount(4, $result->sostituzioni);
        $this->assertSame(70.0, $result->totale);   // 6 + 28 + 24 + 12
    }

    // ───────────────────────── dalle statistiche ai voti ─────────────────────────

    public function test_i_fantavoti_si_calcolano_dalle_statistiche_grezze(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA');

        $attaccante = $rosa->firstWhere('role', 'A');

        PlayerStat::create([
            'player_id' => $attaccante->player_id,
            'season' => 2026,
            'matchday' => 1,
            'minutes' => 90,
            'rating' => 7.0,
            'goals' => 1,
            'assists' => 1,
        ]);

        $fatti = $this->matchdayScorer()->scorePlayers($league, 1);
        $score = PlayerScore::where('player_id', $attaccante->player_id)->firstOrFail();

        $this->assertSame(1, $fatti);
        $this->assertSame(7.2, $score->voto_base);   // molla sul rating 7.0
        $this->assertSame(4.0, $score->bonus);       // gol + assist
        $this->assertSame(11.2, $score->fantavoto);
    }

    public function test_il_ricalcolo_sovrascrive_invece_di_duplicare(): void
    {
        // Il comando è rieseguibile: capita di lanciarlo prima che l'API abbia
        // finito di consolidare i rating, e va rilanciato senza pulire nulla.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA');

        $portiere = $rosa->firstWhere('role', 'P');

        $stat = PlayerStat::create([
            'player_id' => $portiere->player_id,
            'season' => 2026,
            'matchday' => 1,
            'minutes' => 90,
            'rating' => 6.0,
            'goals_conceded' => 2,
        ]);

        $this->matchdayScorer()->scorePlayers($league, 1);
        $stat->update(['goals_conceded' => 1]);
        $this->matchdayScorer()->scorePlayers($league, 1);

        $scores = PlayerScore::where('player_id', $portiere->player_id)->get();

        $this->assertCount(1, $scores);
        $this->assertSame(1.0, $scores->first()->malus);   // un solo gol subito
        $this->assertSame(5.0, $scores->first()->fantavoto);
    }

    // ───────────────────────── formazione d'ufficio ─────────────────────────

    public function test_chi_non_schiera_riceve_la_formazione_automatica_con_penalita(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA');
        $this->scoreAll($rosa, 6.0);

        $esito = $this->matchdayScorer()->scoreLineups($league, 1);

        $lineup = Lineup::where('manager_id', $marco->id)->firstOrFail();

        $this->assertSame(1, $esito['ufficio']);
        $this->assertTrue($lineup->auto_generated);
        $this->assertSame(-3.0, $lineup->result->penalita);
        $this->assertSame(63.0, $lineup->result->totale);   // 66 − 3
    }

    public function test_la_formazione_automatica_sceglie_il_modulo_piu_redditizio(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDDCCCCCAAA');
        $this->scoreAll($rosa, 6.0);

        // Centrocampo fortissimo, attacco discreto, difesa modesta: il modulo
        // che estrae più power è il 3-5-2.
        $rosa->each(fn ($c) => $this->makePower($c, match ($c->role) {
            'C' => 10.0,
            'A' => 5.0,
            default => 1.0,
        }));

        $lineup = (new AutoLineup)->build($marco, $league, 1);

        $this->assertSame('3-5-2', $lineup->module);
        $this->assertCount(11, $lineup->starters());
        $this->assertCount(3, $lineup->bench());
    }

    public function test_chi_schiera_da_solo_non_prende_penalita(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');

        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA');
        $this->scoreAll($rosa, 6.0);
        $this->makeLineup($marco, $rosa);

        $esito = $this->matchdayScorer()->scoreLineups($league, 1);

        $this->assertSame(0, $esito['ufficio']);
        $this->assertSame(66.0, Lineup::where('manager_id', $marco->id)->firstOrFail()->result->totale);
    }
}
