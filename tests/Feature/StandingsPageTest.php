<?php

namespace Tests\Feature;

use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Matchup;
use App\Models\Standing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * La pagina di classifica, con l'identità delle squadre.
 */
class StandingsPageTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $league;

    private Manager $marco;

    private Manager $giulia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->league = $this->makeLeague();
        $this->marco = $this->makeManager($this->league, 'Marco');
        $this->giulia = $this->makeManager($this->league, 'Giulia');
    }

    private function inClassifica(Manager $manager, int $posizione, int $punti, float $fantapunti, int $matchday = 1): void
    {
        Standing::create([
            'league_season_id' => $this->league->id,
            'manager_id' => $manager->id,
            'matchday' => $matchday,
            'punti' => $punti,
            'fantapunti' => $fantapunti,
            'posizione' => $posizione,
        ]);
    }

    // ───────────────────────── la pagina ─────────────────────────

    public function test_la_pagina_e_riservata(): void
    {
        $this->get(route('standings.index'))->assertRedirect(route('login'));
    }

    public function test_senza_giornate_concluse_la_pagina_lo_dice(): void
    {
        $this->actingAs($this->marco)
            ->get(route('standings.index'))
            ->assertOk()
            ->assertSee('Nessuna giornata ancora conclusa');
    }

    public function test_la_classifica_mostra_le_squadre_in_ordine(): void
    {
        $this->marco->update(['name' => 'Atletico Sbustamento']);
        $this->giulia->update(['name' => 'Real Fornello']);

        $this->inClassifica($this->giulia, 1, 3, 70.5);
        $this->inClassifica($this->marco, 2, 0, 64.0);

        $risposta = $this->actingAs($this->marco)->get(route('standings.index'))->assertOk();

        $risposta->assertSeeInOrder(['Real Fornello', 'Atletico Sbustamento']);
        $risposta->assertSee('70,5');
    }

    public function test_l_identita_della_squadra_compare_in_classifica(): void
    {
        $this->marco->update([
            'name' => 'Atletico Sbustamento',
            'coach_name' => 'Ada Fornaciari',
            'crest' => ['colori' => ['#000', '#fff', '#f00'], 'forma' => 'rombo', 'simbolo' => 'fulmine'],
        ]);

        $this->inClassifica($this->marco, 1, 3, 70.0);

        $this->actingAs($this->marco)
            ->get(route('standings.index'))
            ->assertSee('Atletico Sbustamento')
            ->assertSee('Ada Fornaciari')
            ->assertSee('data-forma="rombo"', escape: false)
            ->assertSee('sim-fulmine');
    }

    public function test_le_squadre_senza_identita_non_rompono_la_pagina(): void
    {
        // Una lega appena creata non ha ancora né maglie né stemmi.
        $this->inClassifica($this->marco, 1, 0, 0.0);

        $this->actingAs($this->marco)
            ->get(route('standings.index'))
            ->assertOk()
            ->assertSee('Marco');
    }

    // ───────────────────────── le sfide ─────────────────────────

    public function test_le_sfide_della_giornata_mostrano_il_risultato(): void
    {
        $this->inClassifica($this->marco, 1, 3, 72.5);
        $this->inClassifica($this->giulia, 2, 0, 68.0);

        Matchup::create([
            'league_season_id' => $this->league->id,
            'round' => 1,
            'matchday' => 1,
            'home_manager_id' => $this->marco->id,
            'away_manager_id' => $this->giulia->id,
            'home_points' => 72.5,
            'away_points' => 68.0,
            'home_score' => 3,
            'away_score' => 0,
            'state' => 'played',
        ]);

        $this->actingAs($this->marco)
            ->get(route('standings.index'))
            ->assertSee('Le sfide della 1ª')
            ->assertSee('72,5')
            ->assertSee('68,0');
    }

    public function test_una_sfida_non_ancora_giocata_non_inventa_un_punteggio(): void
    {
        $this->inClassifica($this->marco, 1, 0, 0.0);

        Matchup::create([
            'league_season_id' => $this->league->id,
            'round' => 1,
            'matchday' => 1,
            'home_manager_id' => $this->marco->id,
            'away_manager_id' => $this->giulia->id,
            'state' => 'scheduled',
        ]);

        $this->actingAs($this->marco)
            ->get(route('standings.index'))
            ->assertOk()
            ->assertSee('vs')
            ->assertDontSee('in classifica');
    }

    // ───────────────────────── lo storico ─────────────────────────

    public function test_si_puo_guardare_una_giornata_passata(): void
    {
        // La classifica è una fotografia per giornata: quella dopo la N−2 è
        // anche ciò che ha deciso l'ordine del draft, e deve restare leggibile.
        $this->inClassifica($this->marco, 2, 0, 50.0, matchday: 1);
        $this->inClassifica($this->marco, 1, 3, 120.0, matchday: 2);

        $this->actingAs($this->marco)
            ->get(route('standings.index', ['giornata' => 1]))
            ->assertOk()
            ->assertSee('50,0')
            ->assertDontSee('120,0');
    }

    public function test_senza_giornata_indicata_vale_l_ultima(): void
    {
        $this->inClassifica($this->marco, 2, 0, 50.0, matchday: 1);
        $this->inClassifica($this->marco, 1, 3, 120.0, matchday: 2);

        $this->actingAs($this->marco)
            ->get(route('standings.index'))
            ->assertSee('120,0');
    }

    public function test_la_classifica_di_un_altra_lega_resta_fuori(): void
    {
        $altra = $this->makeLeague('Altra lega');
        $estraneo = $this->makeManager($altra, 'Estraneo');

        Standing::create([
            'league_season_id' => $altra->id,
            'manager_id' => $estraneo->id,
            'matchday' => 1,
            'punti' => 99,
            'fantapunti' => 999,
            'posizione' => 1,
        ]);

        $this->inClassifica($this->marco, 1, 3, 70.0);

        $this->actingAs($this->marco)
            ->get(route('standings.index'))
            ->assertDontSee('Estraneo')
            ->assertDontSee('999');
    }
}
