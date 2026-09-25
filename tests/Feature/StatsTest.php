<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Services\Scoring\LineupScorer;
use App\Services\Stats\DraftHistory;
use App\Services\Stats\MarketMovers;
use App\Services\Stats\MatchdayMvp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Le tre statistiche di lettura: movimento di valutazione, migliore in campo,
 * storico delle pescate. Nessuna tabella nuova: leggono ciò che c'è già.
 */
class StatsTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $league;

    private Manager $marco;

    protected function setUp(): void
    {
        parent::setUp();

        $this->league = $this->makeLeague();
        $this->marco = $this->makeManager($this->league, 'Marco');
    }

    // ───────────────────────── movimento di valutazione ─────────────────────────

    public function test_i_movimenti_si_leggono_dal_rank_delta_gia_scritto(): void
    {
        $sale = $this->makePlayer('A');
        $scende = $this->makePlayer('D');
        $fermo = $this->makePlayer('C');

        $this->makePlayerPower($sale, 4, 90.0)->update(['rank_delta' => 12, 'rank' => 3]);
        $this->makePlayerPower($scende, 4, 30.0)->update(['rank_delta' => -8, 'rank' => 40]);
        $this->makePlayerPower($fermo, 4, 50.0)->update(['rank_delta' => 0, 'rank' => 20]);

        $esito = (new MarketMovers)->forMatchday($this->league, 4);

        $this->assertSame($sale->id, $esito['salgono']->first()->player_id);
        $this->assertSame($scende->id, $esito['scendono']->first()->player_id);

        // Chi non si è mosso non è una notizia.
        $this->assertNotContains($fermo->id, $esito['salgono']->pluck('player_id'));
        $this->assertNotContains($fermo->id, $esito['scendono']->pluck('player_id'));
    }

    public function test_alla_prima_valutazione_non_c_e_movimento(): void
    {
        // `rank_delta` è nullo finché non esiste una giornata precedente da
        // confrontare: mostrarlo come «zero» sarebbe una bugia.
        $this->makePlayerPower($this->makePlayer('A'), 1, 90.0);

        $esito = (new MarketMovers)->forMatchday($this->league, 1);

        $this->assertTrue($esito['salgono']->isEmpty());
        $this->assertTrue($esito['scendono']->isEmpty());
    }

    public function test_i_cambi_di_fascia_si_elencano_a_parte(): void
    {
        $promosso = $this->makePlayer('A');
        $stabile = $this->makePlayer('C');

        $this->makePlayerPower($promosso, 4, 90.0, 'epica')->update(['rank_delta' => 20, 'tier_changed' => true]);
        $this->makePlayerPower($stabile, 4, 50.0)->update(['rank_delta' => 3]);

        $cambi = (new MarketMovers)->tierChanges($this->league, 4);

        $this->assertCount(1, $cambi);
        $this->assertSame($promosso->id, $cambi->first()->player_id);
    }

    // ───────────────────────── storico delle pescate ─────────────────────────

    public function test_lo_storico_conta_chi_ha_pescato_non_chi_possiede(): void
    {
        // Una carta pescata e subito ceduta conta come pescata: la domanda è
        // «a chi capita sempre», non «chi ce l'ha adesso».
        $giulia = $this->makeManager($this->league, 'Giulia');
        $carta = $this->makeRoster($this->marco, 'A', matchday: 1)->first();

        $carta->update(['owner_manager_id' => $giulia->id]);

        $storico = (new DraftHistory)->forPlayer($this->league, $carta->player_id);

        $this->assertCount(1, $storico);
        $this->assertSame('Marco', $storico->first()->manager);
    }

    public function test_l_affinita_emerge_solo_dalla_ricorrenza(): void
    {
        $ricorrente = $this->makePlayer('A');
        $unaVolta = $this->makePlayer('C');

        foreach ([1, 2, 3] as $giornata) {
            $this->carta($ricorrente->id, $this->marco, $giornata);
        }
        $this->carta($unaVolta->id, $this->marco, 1);

        $affinita = (new DraftHistory)->affinities($this->league, minimo: 2);

        $this->assertCount(1, $affinita);
        $this->assertSame(3, (int) $affinita->first()->volte);
        $this->assertSame('Marco', $affinita->first()->manager);
    }

    public function test_i_piu_pescati_contano_anche_quante_mani_diverse(): void
    {
        $giulia = $this->makeManager($this->league, 'Giulia');
        $conteso = $this->makePlayer('A');

        $this->carta($conteso->id, $this->marco, 1);
        $this->carta($conteso->id, $giulia, 2);
        $this->carta($conteso->id, $this->marco, 3);

        $riga = (new DraftHistory)->mostDrawn($this->league)->first();

        $this->assertSame(3, (int) $riga->volte);
        $this->assertSame(2, (int) $riga->mani);
    }

    // ───────────────────────── migliore in campo ─────────────────────────

    public function test_il_migliore_in_campo_e_quello_che_ha_davvero_giocato(): void
    {
        // Il titolare senza voto viene sostituito: premiare lui sarebbe assurdo,
        // e il sostituto che entra deve poter vincere il premio.
        $rosa = $this->makeRoster($this->marco, 'PDDDDCCCCAAA', matchday: 5);
        $this->scoreAll($rosa, 6.0, matchday: 5);

        $titolareAssente = $rosa->firstWhere('role', 'A');
        $panchinaro = $rosa->last();   // il terzo attaccante, fuori dagli undici

        $this->rescore($titolareAssente, null, matchday: 5);
        $this->rescore($panchinaro, 13.5, matchday: 5);

        $lineup = $this->makeLineup($this->marco, $rosa, matchday: 5);
        (new LineupScorer)->score($lineup);

        $mvp = (new MatchdayMvp)->forMatchday($this->league, 5);

        $this->assertCount(1, $mvp);
        $this->assertSame($panchinaro->player->last_name, $mvp->first()['giocatore']);
        $this->assertSame(13.5, $mvp->first()['fantavoto']);
    }

    public function test_i_panchinari_rimasti_fuori_non_vincono_niente(): void
    {
        $rosa = $this->makeRoster($this->marco, 'PDDDDCCCCAAA', matchday: 5);
        $this->scoreAll($rosa, 6.0, matchday: 5);

        // Il terzo attaccante resta in panchina e nessuno esce: il suo 15
        // non conta, perché non ha giocato.
        $this->rescore($rosa->last(), 15.0, matchday: 5);

        $lineup = $this->makeLineup($this->marco, $rosa, matchday: 5);
        (new LineupScorer)->score($lineup);

        $this->assertSame(6.0, (new MatchdayMvp)->forMatchday($this->league, 5)->first()['fantavoto']);
    }

    public function test_senza_giornate_calcolate_non_c_e_migliore(): void
    {
        $this->assertTrue((new MatchdayMvp)->forMatchday($this->league, 5)->isEmpty());
    }

    // ───────────────────────── la pagina ─────────────────────────

    public function test_la_pagina_e_riservata(): void
    {
        $this->get(route('stats.index'))->assertRedirect(route('login'));
    }

    public function test_la_pagina_riunisce_le_tre_statistiche(): void
    {
        $sale = $this->makePlayer('A');
        $this->makePlayerPower($sale, 4, 90.0)->update(['rank_delta' => 12, 'rank' => 3]);
        $this->carta($sale->id, $this->marco, 1);

        $this->actingAs($this->marco)
            ->get(route('stats.index'))
            ->assertOk()
            ->assertSee('In rialzo')
            ->assertSee('Migliore in campo')
            ->assertSee('Chi capita sempre a chi')
            ->assertSee($sale->last_name);
    }

    public function test_una_lega_appena_nata_non_esplode(): void
    {
        // Nessun power, nessuna carta, nessuna formazione: la pagina deve
        // reggere lo stesso e dire che non c'è niente.
        $this->actingAs($this->marco)
            ->get(route('stats.index'))
            ->assertOk()
            ->assertSee('Nessuna giornata ancora calcolata');
    }

    private function carta(int $playerId, Manager $manager, int $matchday): Card
    {
        return Card::create([
            'league_season_id' => $this->league->id,
            'matchday' => $matchday,
            'player_id' => $playerId,
            'tier' => 'comune',
            'role' => 'A',
            'owner_manager_id' => $manager->id,
            'original_owner_id' => $manager->id,
        ]);
    }
}
