<?php

namespace Tests\Feature;

use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Matchup;
use App\Models\Tournament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Tutti i risultati di una giornata, campionato e tornei insieme.
 *
 * ⚠️ Mancava un posto dove vedere «com'è andata». Le sfide di campionato
 * stavano in fondo alla classifica, quelle dei tornei ciascuna dentro il
 * proprio tabellone: per sapere cosa era successo in un weekend bisognava
 * aprire tre pagine e ricordarsi la quarta. E la classifica non è il posto —
 * lì si va per la posizione, non per i risultati.
 */
class RisultatiTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $stagione;

    private Manager $ada;

    private Manager $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagione = $this->makeLeague();
        $this->ada = $this->makeManager($this->stagione, 'Ada');
        $this->bruno = $this->makeManager($this->stagione, 'Bruno');
    }

    private function sfida(int $matchday, ?Tournament $torneo = null, string $stato = 'played'): Matchup
    {
        return Matchup::create([
            'league_season_id' => $this->stagione->id,
            'tournament_id' => $torneo?->id,
            'matchday' => $matchday,
            'round' => 1,
            'home_manager_id' => $this->ada->id,
            'away_manager_id' => $this->bruno->id,
            'state' => $stato,
            'home_points' => 74.5,
            'away_points' => 71.0,
            'home_score' => 3,
            'away_score' => 0,
        ]);
    }

    private function torneo(string $nome): Tournament
    {
        return Tournament::create([
            'league_season_id' => $this->stagione->id,
            'name' => $nome,
            'format' => 'girone',
            'state' => 'in_corso',
            'start_matchday' => 1,
        ]);
    }

    // ───────────────────────── la pagina ─────────────────────────

    public function test_la_pagina_e_riservata(): void
    {
        $this->get(route('risultati.index'))->assertRedirect(route('login'));
    }

    public function test_senza_sfide_la_pagina_lo_dice(): void
    {
        $this->actingAs($this->ada)
            ->get(route('risultati.index'))
            ->assertOk()
            ->assertSee('Nessuna sfida in calendario');
    }

    // ───────────────────────── campionato e tornei insieme ─────────────────────────

    public function test_mostra_campionato_e_tornei_della_stessa_giornata(): void
    {
        // ⚠️ È tutta la ragione per cui questa pagina esiste: nella stessa
        // giornata di Serie A si gioca il campionato E le coppe, e prima
        // bisognava aprire un tabellone per volta per mettere insieme il quadro.
        $this->sfida(3);
        $this->sfida(3, $this->torneo('Coppa'));

        $this->actingAs($this->ada)
            ->get(route('risultati.index', ['giornata' => 3]))
            ->assertOk()
            ->assertSee('Campionato')
            ->assertSee('Coppa');
    }

    public function test_una_competizione_senza_sfide_non_compare(): void
    {
        // Un titolo con sotto il vuoto fa sembrare che manchi qualcosa.
        $this->sfida(3);
        $this->sfida(5, $this->torneo('Coppa'));

        $this->actingAs($this->ada)
            ->get(route('risultati.index', ['giornata' => 3]))
            ->assertOk()
            ->assertSee('Campionato')
            ->assertDontSee('Coppa');
    }

    public function test_si_sceglie_la_giornata(): void
    {
        $this->sfida(3);
        $this->sfida(7, $this->torneo('Coppa'));

        $this->actingAs($this->ada)
            ->get(route('risultati.index', ['giornata' => 7]))
            ->assertOk()
            ->assertSee('Coppa')
            ->assertDontSee('Campionato');
    }

    public function test_di_base_si_apre_sull_ultima_giocata(): void
    {
        $this->sfida(3);
        $this->sfida(7);

        $this->actingAs($this->ada)
            ->get(route('risultati.index'))
            ->assertOk()
            ->assertSee('Risultati · giornata 7');
    }

    public function test_le_sfide_di_un_altro_gruppo_non_si_vedono(): void
    {
        $altra = $this->makeLeague('Altro gruppo');
        $estraneo = $this->makeManager($altra, 'Estraneo');

        Matchup::create([
            'league_season_id' => $altra->id,
            'matchday' => 3,
            'round' => 1,
            'home_manager_id' => $estraneo->id,
            'away_manager_id' => $this->makeManager($altra, 'Altro')->id,
            'state' => 'played',
        ]);

        $this->sfida(3);

        $this->actingAs($this->ada)
            ->get(route('risultati.index', ['giornata' => 3]))
            ->assertOk()
            ->assertDontSee('Estraneo');
    }

    // ───────────────────────── il punteggio di classifica ─────────────────────────

    public function test_il_punteggio_di_classifica_non_compare_fra_i_risultati(): void
    {
        /*
         * ⚠️ C'era, scritto «3 – 0 in classifica» accanto ai fantapunti, e non
         * voleva dire niente di leggibile: non è un risultato della sfida, è
         * quanto quella sfida ha fruttato in graduatoria — un'informazione che
         * ha senso nella colonna «punti» della classifica, dove infatti c'è
         * già. Messo qui sembrava un secondo punteggio della stessa partita, e
         * faceva chiedere quale dei due fosse quello vero.
         */
        $this->sfida(3);

        foreach ([route('risultati.index', ['giornata' => 3]), route('standings.index')] as $pagina) {
            $this->actingAs($this->ada)
                ->get($pagina)
                ->assertOk()
                ->assertDontSee('in classifica');
        }
    }

    public function test_i_fantapunti_restano(): void
    {
        // Il riassunto che serve: quello è il risultato vero della sfida.
        $this->sfida(3);

        $this->actingAs($this->ada)
            ->get(route('risultati.index', ['giornata' => 3]))
            ->assertOk()
            ->assertSee('74,5')
            ->assertSee('fantapunti');
    }
}
