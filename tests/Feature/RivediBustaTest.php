<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\DraftTurn;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Player;
use App\Models\PlayerPower;
use App\Services\Draft\DraftBuilder;
use App\Services\Draft\PackGenerator;
use App\Services\Draft\PackOpener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Rivedere una busta che si è aperta senza di te.
 *
 * Il draft produce questo caso da solo, senza che nessuno sbagli niente: il
 * turno arriva alle tre di notte e scade, oppure lo sbustamento automatico è
 * acceso apposta perché non si vuole stare lì ad aspettare. In entrambi i casi
 * le carte finiscono in rosa — quelle giuste, le stesse che sarebbero uscite
 * comunque — ma l'apertura, che è il momento in cui il gioco succede, è andata
 * in scena per nessuno.
 *
 * ⚠️ La replica dev'essere IDENTICA all'originale: stesse carte, stesso ordine,
 * stessa sorpresa in penultima posizione. Una busta «rivista» diversa da come
 * era uscita non sarebbe un recupero, sarebbe un'altra busta.
 */
class RivediBustaTest extends TestCase
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

        collect(str_split(str_repeat('P', 12).str_repeat('D', 28).str_repeat('C', 28).str_repeat('A', 20)))
            ->each(function (string $ruolo) {
                $player = $this->makePlayer($ruolo);
                $this->makePlayerPower($player, 3);
            });

        $this->draft = (new DraftBuilder)->build($this->league, 3);
        $this->draft->update(['state' => 'open']);

        $this->opener()->activateNext($this->draft);
    }

    private function opener(): PackOpener
    {
        return new PackOpener(app(PackGenerator::class));
    }

    private function turnoAttivo(): DraftTurn
    {
        return $this->draft->fresh()->activeTurn();
    }

    /** Un turno sbustato d'ufficio: nessuno l'ha visto uscire. */
    private function apertaDaSola(string $by = 'auto'): DraftTurn
    {
        $turno = $this->turnoAttivo();
        $this->opener()->open($turno, $by);

        return $turno->fresh();
    }

    // ───────────────────────── la replica ─────────────────────────

    public function test_si_rivede_una_busta_gia_aperta(): void
    {
        $turno = $this->apertaDaSola();

        $this->actingAs($turno->manager)
            ->getJson(route('draft.rivedi', $turno))
            ->assertOk()
            ->assertJsonCount($this->draft->pack_size, 'carte')
            ->assertJsonStructure(['carte' => [['tier', 'role', 'last', 'html', 'htmlScoperta']]]);
    }

    public function test_rivedere_non_muove_nessuna_carta(): void
    {
        // È l'unica cosa che questa strada non deve poter fare: le carte sono
        // in rosa da un pezzo, e una seconda pescata sarebbe una busta regalata.
        $turno = $this->apertaDaSola();
        $prima = $turno->cards()->pluck('id')->sort()->values();

        $this->actingAs($turno->manager)->getJson(route('draft.rivedi', $turno))->assertOk();
        $this->actingAs($turno->manager)->getJson(route('draft.rivedi', $turno))->assertOk();

        $this->assertEquals($prima, $turno->cards()->pluck('id')->sort()->values());
        $this->assertSame('done', $turno->fresh()->state);
    }

    public function test_la_replica_e_identica_all_originale(): void
    {
        // Stesso ordine di rivelazione, carta per carta: lo impone lo stesso
        // `orderForReveal()` dello sbustamento vero, non un ordinamento
        // reinventato dal controller.
        $turno = $this->turnoAttivo();
        $originale = $this->opener()->open($turno, 'manager');

        $atteso = $originale->pluck('player_id')->all();

        $risposta = $this->actingAs($turno->manager)
            ->getJson(route('draft.rivedi', $turno->fresh()))
            ->assertOk();

        $rivisti = collect($risposta->json('carte'))->pluck('last')->all();

        $this->assertSame(
            collect($atteso)->map(fn (int $id) => Player::findOrFail($id)->last_name)->all(),
            $rivisti,
        );
    }

    // ───────────────────────── di chi è la busta ─────────────────────────

    public function test_non_si_rivede_la_busta_di_un_altro(): void
    {
        // Il draft è una fila al buio per costruzione: cosa ha pescato un altro
        // lo dice lo storico, quando è già successo, non un endpoint aperto.
        $turno = $this->apertaDaSola();
        $altro = $this->managers->firstWhere(fn (Manager $m) => $m->id !== $turno->manager_id);

        $this->actingAs($altro)
            ->getJson(route('draft.rivedi', $turno))
            ->assertForbidden();
    }

    public function test_non_si_rivede_una_busta_mai_aperta(): void
    {
        $inAttesa = $this->draft->turns()->where('state', 'waiting')->firstOrFail();

        $this->actingAs($inAttesa->manager)
            ->getJson(route('draft.rivedi', $inAttesa))
            ->assertNotFound();
    }

    public function test_la_strada_e_riservata(): void
    {
        $turno = $this->apertaDaSola();

        $this->getJson(route('draft.rivedi', $turno))->assertUnauthorized();
    }

    // ───────────────────────── chi l'ha vista e chi no ─────────────────────────

    public function test_una_busta_scaduta_resta_da_rivedere(): void
    {
        $turno = $this->apertaDaSola();

        $this->assertNull($turno->revealed_at);
        $this->assertTrue($turno->daRivedere());
    }

    public function test_anche_lo_sbustamento_automatico_lascia_una_busta_da_rivedere(): void
    {
        // ⚠️ Col draft automatico acceso il turno risulta aperto dal 'manager' —
        // la scelta è stata sua — ma davanti allo schermo non c'era nessuno.
        // Guardare `opened_by` per decidere avrebbe tagliato fuori proprio chi
        // l'automatico l'ha acceso per non dover stare lì.
        $turno = $this->apertaDaSola(by: 'manager');

        $this->assertSame('manager', $turno->opened_by);
        $this->assertTrue($turno->daRivedere());
    }

    public function test_aprirla_dal_browser_la_marca_come_vista(): void
    {
        $turno = $this->turnoAttivo();

        $this->actingAs($turno->manager)
            ->postJson(route('draft.open'))
            ->assertOk();

        $this->assertNotNull($turno->fresh()->revealed_at);
        $this->assertFalse($turno->fresh()->daRivedere());
    }

    public function test_rivederla_la_marca_come_vista(): void
    {
        $turno = $this->apertaDaSola();

        $this->actingAs($turno->manager)->getJson(route('draft.rivedi', $turno))->assertOk();

        $this->assertNotNull($turno->fresh()->revealed_at);
    }

    // ───────────────────────── l'invito in pagina ─────────────────────────

    public function test_chi_non_l_ha_vista_trova_l_invito(): void
    {
        $turno = $this->apertaDaSola();

        $this->actingAs($turno->manager)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee('busta aperta senza di te')
            ->assertSee(route('draft.rivedi', $turno), escape: false);
    }

    public function test_l_invito_sparisce_dopo_averla_guardata(): void
    {
        $turno = $this->apertaDaSola();

        $this->actingAs($turno->manager)->getJson(route('draft.rivedi', $turno))->assertOk();

        $this->actingAs($turno->manager)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertDontSee('busta aperta senza di te');
    }

    public function test_l_invito_e_solo_per_chi_e_rimasto_fuori(): void
    {
        $turno = $this->apertaDaSola();
        $altro = $this->managers->firstWhere(fn (Manager $m) => $m->id !== $turno->manager_id);

        $this->actingAs($altro)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertDontSee('busta aperta senza di te');
    }

    public function test_l_invito_mostra_le_buste_del_draft_che_si_sta_guardando(): void
    {
        // ⚠️ Il caso vero, e quello che mi era sfuggito: all'avvio di una
        // stagione partono DUE draft insieme — la giornata di partenza e quella
        // dopo — quindi «le buste più recenti del gruppo» e «le buste di ciò
        // che sto guardando» non sono lo stesso insieme.
        //
        // Prendendo le più recenti, sotto il titolo del draft della 3ª
        // comparivano i bottoni delle buste della 4ª: si cliccava e uscivano
        // carte che non c'entravano niente, e quelle giuste arrivavano solo al
        // giro dopo.
        $turno = $this->apertaDaSola();

        // Lo stesso listone vale anche per la giornata dopo: al draft serve un
        // power per la giornata che sta preparando.
        PlayerPower::where('league_season_id', $this->league->id)
            ->where('matchday', 3)
            ->get()
            ->each(fn (PlayerPower $p) => PlayerPower::create([
                'league_season_id' => $p->league_season_id,
                'player_id' => $p->player_id,
                'matchday' => 4,
                'power' => $p->power,
                'tier' => $p->tier,
            ]));

        $dopo = (new DraftBuilder)->build($this->league, 4, opensAt: now(), deadlineAt: now()->addHours(48));
        $dopo->update(['state' => 'open']);
        $this->opener()->activateNext($dopo);

        $altrove = $this->opener()->open($dopo->fresh()->activeTurn(), 'auto');

        $this->assertGreaterThan($this->draft->id, $dopo->id, 'il secondo draft deve essere il più recente');

        // La pagina mostra il draft più vecchio ancora aperto: è quello che si
        // sta giocando, ed è quello di cui vanno offerte le buste.
        $risposta = $this->actingAs($turno->manager)->get(route('draft.show'))->assertOk();

        $risposta->assertSee(route('draft.rivedi', $turno), escape: false);

        foreach ($dopo->turns()->where('state', 'done')->get() as $estraneo) {
            $risposta->assertDontSee(route('draft.rivedi', $estraneo), escape: false);
        }

        $this->assertNotEmpty($altrove, 'la busta dell\'altro draft esiste comunque');
    }

    public function test_l_invito_resta_anche_a_draft_concluso(): void
    {
        // ⚠️ È il caso che conta di più: a draft chiuso la pagina diventa
        // «nessuna busta all'orizzonte», ed è lì che si arriva quando si è stati
        // via tutto il tempo. Cercando le buste dentro il draft in corso —
        // che non c'è — l'invito sparirebbe proprio a chi ne ha bisogno.
        $turno = $this->apertaDaSola();
        $this->draft->update(['state' => 'closed']);

        $this->actingAs($turno->manager)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee('Nessuna busta all\'orizzonte', escape: false)
            ->assertSee('busta aperta senza di te')
            ->assertSee(route('draft.rivedi', $turno), escape: false);
    }
}
