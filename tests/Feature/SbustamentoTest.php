<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPoolEntry;
use App\Models\DraftTurn;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Player;
use App\Services\Draft\DraftBuilder;
use App\Services\Draft\PackGenerator;
use App\Services\Draft\PackOpener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Il palco del draft: apertura della busta e rivelazione.
 */
class SbustamentoTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $league;

    /** @var Collection<int,Manager> */
    private Collection $managers;

    private Draft $draft;

    protected function setUp(): void
    {
        parent::setUp();

        $this->league = $this->makeLeague();

        $this->managers = collect(['Ada', 'Bruno'])
            ->map(fn (string $nome) => $this->makeManager($this->league, $nome));

        $this->makeFixture(2, now()->subDay()->toDateTimeString());
        $this->makeFixture(3, now()->addDays(6)->toDateTimeString());

        // Listone abbondante: 2 manager × 5 giri × 5 carte = 50 da pescare.
        collect(str_split(str_repeat('P', 12).str_repeat('D', 28).str_repeat('C', 28).str_repeat('A', 20)))
            ->each(function (string $ruolo) {
                $player = $this->makePlayer($ruolo);
                $this->makePlayerPower($player, 3);
            });

        $this->draft = (new DraftBuilder)->build($this->league, 3);
        $this->draft->update(['state' => 'open']);

        (new PackOpener(app(PackGenerator::class)))->activateNext($this->draft);
    }

    private function diTurno(): Manager
    {
        return $this->draft->fresh()->activeTurn()->manager;
    }

    private function inAttesa(): Manager
    {
        $attivo = $this->diTurno();

        return $this->managers->firstWhere(fn (Manager $m) => $m->id !== $attivo->id);
    }

    // ───────────────────────── la pagina ─────────────────────────

    public function test_il_palco_e_riservato(): void
    {
        $this->get(route('draft.show'))->assertRedirect(route('login'));
    }

    public function test_chi_ha_il_turno_vede_il_pulsante(): void
    {
        $this->actingAs($this->diTurno())
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee('Tocca a te')
            ->assertSee('Apri la busta');
    }

    public function test_chi_aspetta_vede_di_chi_e_il_turno(): void
    {
        $this->actingAs($this->inAttesa())
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee('Aspetta il tuo turno')
            ->assertSee('Sta pescando '.$this->diTurno()->name)
            ->assertDontSee('Apri la busta');
    }

    public function test_chi_aspetta_puo_farsi_avvisare(): void
    {
        // L'aggancio della notifica del browser: il contenuto lo scrive il
        // JavaScript — solo lui conosce lo stato del permesso — ma il posto
        // dove scriverlo deve esserci, e deve esserci solo per chi aspetta.
        $this->actingAs($this->inAttesa())
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee('<div data-avviso hidden', escape: false);
    }

    public function test_a_turno_proprio_non_si_offre_nessun_avviso(): void
    {
        // La busta è già al centro della pagina: offrire un avviso per una cosa
        // che sta succedendo adesso non serve a nessuno.
        $this->actingAs($this->diTurno())
            ->get(route('draft.show'))
            ->assertOk()
            ->assertDontSee('<div data-avviso hidden', escape: false);
    }

    public function test_lo_stato_dice_a_chi_tocca(): void
    {
        // È il campo su cui si decide se notificare: senza, la pagina saprebbe
        // che qualcosa è cambiato ma non che è cambiato PER LEI.
        $this->actingAs($this->diTurno())
            ->getJson(route('draft.stato'))
            ->assertOk()
            ->assertJson(['mio' => true]);

        $this->actingAs($this->inAttesa())
            ->getJson(route('draft.stato'))
            ->assertOk()
            ->assertJson(['mio' => false]);
    }

    public function test_senza_draft_in_corso_la_pagina_lo_dice(): void
    {
        Draft::query()->delete();

        $this->actingAs($this->managers->first())
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee('Nessuna busta');
    }

    // ───────────────────────── l'apertura ─────────────────────────

    public function test_aprire_la_busta_assegna_cinque_carte(): void
    {
        $manager = $this->diTurno();

        $risposta = $this->actingAs($manager)->postJson(route('draft.open'));

        $risposta->assertOk()->assertJsonCount(5, 'carte');

        $this->assertSame(5, Card::where('owner_manager_id', $manager->id)->where('matchday', 3)->count());
    }

    public function test_la_risposta_porta_la_carta_gia_resa_dal_server(): void
    {
        // Un solo renderer: se le carte si ricostruissero in JavaScript,
        // prima o poi la rosa e la busta mostrerebbero cose diverse.
        $risposta = $this->actingAs($this->diTurno())->postJson(route('draft.open'));

        $carta = $risposta->json('carte.0');

        $this->assertArrayHasKey('html', $carta);
        $this->assertStringContainsString('class="slot', $carta['html']);
        $this->assertStringContainsString('flipped', $carta['html']);          // arriva coperta
        $this->assertStringNotContainsString('flipped', $carta['htmlScoperta']);
    }

    public function test_chi_non_ha_il_turno_non_apre_niente(): void
    {
        $this->actingAs($this->inAttesa())
            ->postJson(route('draft.open'))
            ->assertForbidden();

        $this->assertSame(0, Card::count());
    }

    public function test_la_seconda_apertura_non_regala_una_busta_in_piu(): void
    {
        // È la corsa di §7.1 vista dal lato del doppio clic: il turno passa a
        // 'done' dentro la transazione, quindi la seconda chiamata trova la
        // porta chiusa. Da lì in poi il turno è di un altro.
        $manager = $this->diTurno();

        $this->actingAs($manager)->postJson(route('draft.open'))->assertOk();

        $seconda = $this->actingAs($manager)->postJson(route('draft.open'));

        // O non è più il suo turno, o il turno era già stato chiuso: in
        // entrambi i casi niente carte nuove.
        $this->assertContains($seconda->status(), [403, 409]);
        $this->assertSame(5, Card::where('owner_manager_id', $manager->id)->count());
    }

    public function test_l_apertura_fa_scorrere_la_coda_subito(): void
    {
        // Senza questo, il manager successivo resterebbe fermo fino al giro di
        // cron: cinque minuti su un turno che ne dura venti.
        $primo = $this->diTurno();

        $this->actingAs($primo)->postJson(route('draft.open'))->assertOk();

        $nuovoTurno = $this->draft->fresh()->activeTurn();

        $this->assertNotNull($nuovoTurno);
        $this->assertNotSame($primo->id, $nuovoTurno->manager_id);
        $this->assertNotNull($nuovoTurno->expires_at);
    }

    public function test_il_turno_resta_tracciato_come_aperto_dal_manager(): void
    {
        $manager = $this->diTurno();
        $turno = $this->draft->fresh()->activeTurn();

        $this->actingAs($manager)->postJson(route('draft.open'));

        $turno->refresh();

        $this->assertSame('done', $turno->state);
        $this->assertSame('manager', $turno->opened_by);
        $this->assertNotNull($turno->opened_at);
    }

    public function test_le_carte_pescate_escono_dal_pool(): void
    {
        $this->actingAs($this->diTurno())->postJson(route('draft.open'));

        $this->assertSame(5, DraftPoolEntry::where('draft_id', $this->draft->id)
            ->where('status', 'drawn')
            ->count());
    }

    public function test_a_draft_non_aperto_non_si_sbusta(): void
    {
        $this->draft->update(['state' => 'pending']);

        $this->actingAs($this->diTurno())
            ->postJson(route('draft.open'))
            ->assertForbidden();
    }

    // ───────────────────────── la carta ─────────────────────────

    public function test_la_rosa_mostra_le_carte_pescate(): void
    {
        $manager = $this->diTurno();
        $this->actingAs($manager)->postJson(route('draft.open'));

        $carta = Card::where('owner_manager_id', $manager->id)->firstOrFail();

        $this->actingAs($manager)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee($carta->player->last_name)
            ->assertSee('La tua rosa');
    }

    public function test_la_foto_compare_solo_su_identita_verificata(): void
    {
        // Una faccia sbagliata su una carta da collezione è l'errore più
        // visibile possibile: senza verifica si mostrano le iniziali.
        $manager = $this->diTurno();
        $this->actingAs($manager)->postJson(route('draft.open'));

        $carta = Card::where('owner_manager_id', $manager->id)->firstOrFail();

        $this->actingAs($manager)->get(route('draft.show'))
            ->assertDontSee($carta->player->photoUrl());

        Player::whereKey($carta->player_id)->update(['photo_verified' => true]);

        $this->actingAs($manager)->get(route('draft.show'))
            ->assertSee($carta->player->photoUrl());
    }

    // ───────────────────────── il cron e il manager insieme ─────────────────────────

    public function test_il_cron_non_riapre_un_turno_gia_sbustato_dal_manager(): void
    {
        $manager = $this->diTurno();
        $turno = $this->draft->fresh()->activeTurn();

        $this->actingAs($manager)->postJson(route('draft.open'))->assertOk();

        $opener = app(PackOpener::class);

        $this->assertNull($opener->open($turno, 'auto'));
        $this->assertSame(5, Card::where('owner_manager_id', $manager->id)->count());
    }

    public function test_il_cron_completa_il_draft_lasciato_a_meta(): void
    {
        $this->actingAs($this->diTurno())->postJson(route('draft.open'))->assertOk();

        // Tutti in automatico: il cron smaltisce i turni rimasti.
        Manager::query()->update(['auto_draft' => true]);
        DraftTurn::where('draft_id', $this->draft->id)->update(['expires_at' => now()->subMinute()]);

        $this->artisan('draft:tick')->assertSuccessful();

        $this->assertSame('closed', $this->draft->fresh()->state);
        $this->assertSame(50, Card::where('matchday', 3)->count());   // 2 × 5 × 5
    }
}
