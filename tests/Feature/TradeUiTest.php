<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Trade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Il mercato dall'interfaccia.
 */
class TradeUiTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $stagione;

    private Manager $marco;

    private Manager $giulia;

    /** @var Collection<int,Card> */
    private Collection $rosaMarco;

    /** @var Collection<int,Card> */
    private Collection $rosaGiulia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagione = $this->makeLeague();
        $this->marco = $this->makeManager($this->stagione, 'Marco');
        $this->giulia = $this->makeManager($this->stagione, 'Giulia');

        $this->rosaMarco = $this->makeRoster($this->marco, 'PPDDDDDCCCCCAAA', matchday: 3);
        $this->rosaGiulia = $this->makeRoster($this->giulia, 'PPDDDDDCCCCCAAA', matchday: 3);
    }

    private function proponi(array $offerte = [], array $richieste = []): Trade
    {
        $this->actingAs($this->marco)->post(route('trades.store', $this->giulia), [
            'offerte' => $offerte,
            'richieste' => $richieste,
        ]);

        return Trade::latest('id')->firstOrFail();
    }

    // ───────────────────────── le pagine ─────────────────────────

    public function test_il_mercato_e_riservato(): void
    {
        $this->get(route('trades.index'))->assertRedirect(route('login'));
    }

    public function test_il_mercato_elenca_gli_avversari(): void
    {
        $this->actingAs($this->marco)
            ->get(route('trades.index'))
            ->assertOk()
            ->assertSee(route('trades.create', $this->giulia))
            ->assertDontSee(route('trades.create', $this->marco));
    }

    public function test_non_si_tratta_con_se_stessi(): void
    {
        $this->actingAs($this->marco)->get(route('trades.create', $this->marco))->assertNotFound();
    }

    public function test_non_si_tratta_con_un_altra_lega(): void
    {
        $estraneo = $this->makeManager($this->makeLeague('Altra lega'), 'Estraneo');

        $this->actingAs($this->marco)->get(route('trades.create', $estraneo))->assertNotFound();
    }

    public function test_la_pagina_di_proposta_mostra_le_due_rose(): void
    {
        $mia = $this->rosaMarco->first();
        $sua = $this->rosaGiulia->first();

        $this->actingAs($this->marco)
            ->get(route('trades.create', $this->giulia))
            ->assertOk()
            ->assertSee($mia->player->last_name)
            ->assertSee($sua->player->last_name);
    }

    // ───────────────────────── proporre ─────────────────────────

    public function test_si_propone_uno_scambio(): void
    {
        $trade = $this->proponi(
            offerte: [$this->rosaMarco->firstWhere('role', 'A')->id],
            richieste: [$this->rosaGiulia->firstWhere('role', 'D')->id],
        );

        $this->assertSame('pending', $trade->state);
        $this->assertSame($this->marco->id, $trade->proposer_id);
        $this->assertCount(1, $trade->offered()->get());
        $this->assertCount(1, $trade->requested()->get());
    }

    public function test_una_proposta_vuota_viene_respinta(): void
    {
        $this->actingAs($this->marco)
            ->post(route('trades.store', $this->giulia), ['offerte' => [], 'richieste' => []])
            ->assertSessionHasErrors('scambio');

        $this->assertSame(0, Trade::count());
    }

    public function test_non_si_offrono_carte_altrui(): void
    {
        $this->actingAs($this->marco)
            ->post(route('trades.store', $this->giulia), [
                'offerte' => [$this->rosaGiulia->first()->id],
            ])
            ->assertSessionHasErrors('scambio');
    }

    // ───────────────────────── rispondere ─────────────────────────

    public function test_il_ricevente_accetta_e_le_carte_si_muovono(): void
    {
        $mia = $this->rosaMarco->firstWhere('role', 'A');
        $sua = $this->rosaGiulia->firstWhere('role', 'D');

        $trade = $this->proponi([$mia->id], [$sua->id]);

        $this->actingAs($this->giulia)
            ->post(route('trades.accept', $trade))
            ->assertRedirect();

        $this->assertSame($this->giulia->id, $mia->fresh()->owner_manager_id);
        $this->assertSame($this->marco->id, $sua->fresh()->owner_manager_id);
        $this->assertSame('accepted', $trade->fresh()->state);
    }

    public function test_solo_il_ricevente_puo_accettare(): void
    {
        $trade = $this->proponi([$this->rosaMarco->first()->id]);

        $this->actingAs($this->marco)->post(route('trades.accept', $trade))->assertForbidden();

        $this->assertSame('pending', $trade->fresh()->state);
    }

    public function test_il_ricevente_rifiuta(): void
    {
        $trade = $this->proponi([$this->rosaMarco->first()->id]);

        $this->actingAs($this->giulia)->post(route('trades.reject', $trade))->assertRedirect();

        $this->assertSame('rejected', $trade->fresh()->state);
    }

    public function test_il_proponente_ritira(): void
    {
        $trade = $this->proponi([$this->rosaMarco->first()->id]);

        $this->actingAs($this->marco)->post(route('trades.cancel', $trade))->assertRedirect();

        $this->assertSame('cancelled', $trade->fresh()->state);
    }

    public function test_il_ricevente_non_puo_ritirare_la_proposta_altrui(): void
    {
        $trade = $this->proponi([$this->rosaMarco->first()->id]);

        $this->actingAs($this->giulia)->post(route('trades.cancel', $trade))->assertForbidden();
    }

    public function test_il_rifiuto_automatico_spiega_il_motivo(): void
    {
        // Giulia cederebbe tutto e resterebbe inschierabile: il pavimento
        // scatta all'accettazione, e il messaggio deve dirle perché.
        $trade = $this->proponi(richieste: $this->rosaGiulia->pluck('id')->all());

        $this->actingAs($this->giulia)
            ->post(route('trades.accept', $trade))
            ->assertSessionHas('successo', fn (string $m) => str_contains($m, 'rifiutato'));

        $this->assertSame('rejected', $trade->fresh()->state);
        $this->assertNotNull($trade->fresh()->reject_reason);
    }

    // ───────────────────────── il feed ─────────────────────────

    public function test_il_feed_e_pubblico_a_tutta_la_lega(): void
    {
        // Col sette-per-uno permesso il rischio è la collusione, e la
        // trasparenza è il rimedio.
        $terzo = $this->makeManager($this->stagione, 'Luca');
        $this->makeRoster($terzo, 'PDDDDCCCCAA', matchday: 3);

        $mia = $this->rosaMarco->firstWhere('role', 'A');
        $trade = $this->proponi([$mia->id], [$this->rosaGiulia->firstWhere('role', 'D')->id]);

        $this->actingAs($this->giulia)->post(route('trades.accept', $trade));

        $this->actingAs($terzo)
            ->get(route('trades.index'))
            ->assertOk()
            ->assertSee($mia->player->last_name)
            ->assertSee('concluso');
    }
}
