<?php

namespace Tests\Feature;

use App\Models\Matchup;
use App\Models\Trade;
use App\Models\TradeItem;
use App\Services\Calendar\StandingsUpdater;
use App\Services\Scoring\MatchdayScorer;
use App\Services\Stats\LiveMatchday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ComponentAttributeBag;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * La carta si rivede passando il mouse su un nome.
 *
 * Le carte sono la cosa più bella del gioco e si vedevano soltanto al draft:
 * per il resto della settimana i giocatori erano righe di testo in una tabella.
 */
class AnteprimaCartaTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    public function test_una_carta_si_rende_da_sola(): void
    {
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $carta = $this->makeRoster($marco, 'A')->first();

        $this->actingAs($marco)
            ->get(route('carta.show', $carta))
            ->assertOk()
            ->assertSee($carta->player->last_name)
            // È il markup della carta vera, non un riassunto: un secondo
            // renderer si aggiornerebbe solo a metà.
            ->assertSee('class="slot', false);
    }

    public function test_la_carta_di_un_altro_gruppo_non_si_apre(): void
    {
        // ⚠️ L'id è indovinabile a tentativi, e le rose di un altro gruppo non
        // riguardano nessuno.
        $mia = $this->makeLeague('Mia');
        $carta = $this->makeRoster($this->makeManager($mia, 'Marco'), 'A')->first();

        $estraneo = $this->makeManager($this->makeLeague('Altra'), 'Estraneo');

        $this->actingAs($estraneo)->get(route('carta.show', $carta))->assertNotFound();
    }

    public function test_l_anteprima_e_riservata(): void
    {
        $carta = $this->makeRoster($this->makeManager($this->makeLeague(), 'Marco'), 'A')->first();

        $this->get(route('carta.show', $carta))->assertRedirect(route('login'));
    }

    public function test_la_carta_di_un_compagno_di_lega_si_vede(): void
    {
        // Non è solo la propria rosa: passare il mouse sul giocatore di un
        // avversario è metà del motivo per cui l'anteprima esiste.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $giulia = $this->makeManager($stagione, 'Giulia');

        $carta = $this->makeRoster($giulia, 'A')->first();

        $this->actingAs($marco)->get(route('carta.show', $carta))->assertOk();
    }

    public function test_il_tabellino_aggancia_le_carte(): void
    {
        $stagione = $this->makeLeague();
        $casa = $this->makeManager($stagione, 'Marco');
        $fuori = $this->makeManager($stagione, 'Giulia');

        $rosaCasa = $this->makeRoster($casa, 'PDDDDCCCCAA');
        $rosaFuori = $this->makeRoster($fuori, 'PDDDDCCCCAA');

        $this->makeLineup($casa, $rosaCasa);
        $this->makeLineup($fuori, $rosaFuori);
        $this->scoreAll($rosaCasa, 6.0);
        $this->scoreAll($rosaFuori, 6.0);

        $sfida = Matchup::create([
            'league_season_id' => $stagione->id,
            'round' => 1,
            'matchday' => 1,
            'home_manager_id' => $casa->id,
            'away_manager_id' => $fuori->id,
        ]);

        app(MatchdayScorer::class)->run($stagione, 1);
        app(StandingsUpdater::class)->update($stagione, 1);

        $this->actingAs($casa)
            ->get(route('matchup.show', $sfida))
            ->assertOk()
            ->assertSee('data-carta="'.$rosaCasa->first()->id.'"', false);
    }

    public function test_il_mercato_aggancia_le_carte_di_una_proposta(): void
    {
        // ⚠️ È qui che si decide se uno scambio conviene, e per deciderlo
        // bisogna sapere CHE carte sono. I nomi stavano uniti in una stringa
        // sola — «Lautaro, Bastoni» — e non c'era niente a cui appendere
        // l'anteprima: l'unico modo di guardarle era cercarsele in rosa.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $giulia = $this->makeManager($stagione, 'Giulia');

        $mia = $this->makeRoster($marco, 'A')->first();
        $sua = $this->makeRoster($giulia, 'D')->first();

        $trade = Trade::create([
            'league_season_id' => $stagione->id,
            'matchday' => 1,
            'proposer_id' => $marco->id,
            'receiver_id' => $giulia->id,
            'state' => 'pending',
        ]);

        TradeItem::create(['trade_id' => $trade->id, 'card_id' => $mia->id, 'direction' => 'offered']);
        TradeItem::create(['trade_id' => $trade->id, 'card_id' => $sua->id, 'direction' => 'requested']);

        $this->actingAs($marco)
            ->get(route('trades.index'))
            ->assertOk()
            // Tutte e due le parti: si guarda quello che si dà quanto quello
            // che si prende.
            ->assertSee('data-carta="'.$mia->id.'"', false)
            ->assertSee('data-carta="'.$sua->id.'"', false);
    }

    public function test_la_pagina_di_proposta_aggancia_le_due_rose(): void
    {
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $giulia = $this->makeManager($stagione, 'Giulia');

        $mia = $this->makeRoster($marco, 'PDDDDCCCCAA');
        $sua = $this->makeRoster($giulia, 'PDDDDCCCCAA');

        $this->actingAs($marco)
            ->get(route('trades.create', $giulia))
            ->assertOk()
            ->assertSee('data-carta="'.$mia->first()->id.'"', false)
            ->assertSee('data-carta="'.$sua->first()->id.'"', false);
    }

    public function test_chi_non_ha_padrone_resta_testo(): void
    {
        // Nella giornata di Serie A la maggior parte dei giocatori non è di
        // nessuno: lì una carta non esiste proprio, e il nome deve restare un
        // nome invece di promettere un'anteprima che non c'è.
        $html = view('components.nome-giocatore', [
            'carta' => null,
            'slot' => 'Osimhen',
            'attributes' => new ComponentAttributeBag,
        ])->render();

        $this->assertStringNotContainsString('data-carta', $html);
        $this->assertStringContainsString('Osimhen', $html);
    }

    public function test_la_giornata_porta_l_id_della_carta_a_chi_ce_l_ha(): void
    {
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $carta = $this->makeRoster($marco, 'A')->first();

        $this->makeFixture(1, '2026-09-11 20:45:00');
        $this->makePerformance($carta->player, 1, 7.0);

        $partite = app(LiveMatchday::class)
            ->forMatchday($stagione, 1, $marco->id);

        $riga = $partite->flatMap(fn (array $p) => $p['casa']->concat($p['fuori']))
            ->firstWhere('nome', $carta->player->last_name);

        $this->assertSame($carta->id, $riga['carta']);
    }
}
