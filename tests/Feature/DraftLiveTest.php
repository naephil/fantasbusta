<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPoolEntry;
use App\Models\DraftTurn;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Services\Draft\PackOpener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Quello che serve a chi sta col draft aperto davanti.
 *
 * Il draft è l'unica pagina in cui si ASPETTA: il turno arriva quando arriva, e
 * il cron lo fa scorrere anche mentre nessuno guarda. Al primo test coi
 * volontari era la lamentela numero uno — si restava fermi su «aspetta il tuo
 * turno» col turno già proprio — e la seconda era non sapere cosa avessero
 * preso quelli prima.
 */
class DraftLiveTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    /** Un draft pronto: pool, turni, e la finestra aperta. */
    private function draft(int $matchday = 3, int $quantiManager = 3): array
    {
        $stagione = $this->makeLeague();

        $managers = collect(range(1, $quantiManager))
            ->map(fn (int $i) => $this->makeManager($stagione, "Manager{$i}"))
            ->all();

        $draft = Draft::create([
            'league_season_id' => $stagione->id,
            'matchday' => $matchday,
            'state' => 'open',
            'opens_at' => now()->subHour(),
            'deadline_at' => now()->addHours(48),
            'rounds' => 2,
            'pack_size' => 5,
        ]);

        foreach (str_split('PPDDDDCCCCAA') as $ruolo) {
            DraftPoolEntry::create([
                'draft_id' => $draft->id,
                'player_id' => $this->makePlayer($ruolo, season: $stagione->season)->id,
                'tier' => 'comune',
                'role' => $ruolo,
                'status' => 'available',
            ]);
        }

        foreach ($managers as $i => $manager) {
            DraftTurn::create([
                'draft_id' => $draft->id,
                'manager_id' => $manager->id,
                'round' => 1,
                'pick_index' => $i + 1,
                'state' => $i === 0 ? 'active' : 'waiting',
                'expires_at' => $i === 0 ? now()->addHour() : null,
            ]);
        }

        return [$stagione, $managers, $draft];
    }

    /**
     * Lo stesso draft, ma con `$quante` buste già aperte alle spalle.
     *
     * I turni si scrivono a mano invece di farli sbustare davvero: alla
     * cronologia interessa chi ha preso cosa, non come ci è arrivato, e un pool
     * abbastanza grande da reggere dodici buste vere sarebbe sessanta
     * giocatori per provare un elenco.
     *
     * @return array{0: LeagueSeason, 1: list<Manager>, 2: Draft}
     */
    private function draftConStorico(int $quante): array
    {
        [$stagione, $managers, $draft] = $this->draft(quantiManager: 2);

        // ⚠️ `pick_index` riparte da cento: `draft()` ha già occupato i primi
        // posti coi turni ancora da giocare, e la coppia (draft, pick_index) è
        // unica.
        for ($i = 1; $i <= $quante; $i++) {
            $turno = DraftTurn::create([
                'draft_id' => $draft->id,
                'manager_id' => $managers[$i % 2]->id,
                'round' => 1,
                'pick_index' => 100 + $i,
                'state' => 'done',
                'opened_at' => now(),
                'opened_by' => 'manager',
            ]);

            Card::create([
                'league_season_id' => $stagione->id,
                'matchday' => $draft->matchday,
                'player_id' => $this->makePlayer('A', season: $stagione->season)->id,
                'tier' => 'comune',
                'role' => 'A',
                'owner_manager_id' => $turno->manager_id,
                'original_owner_id' => $turno->manager_id,
                'draft_turn_id' => $turno->id,
            ]);
        }

        return [$stagione, $managers, $draft];
    }

    /** La carta della busta più vecchia: quella che senza espandere non si vede. */
    private function piuVecchia(Draft $draft): Card
    {
        return $draft->turns()
            ->where('state', 'done')
            ->orderBy('pick_index')
            ->firstOrFail()
            ->cards()
            ->firstOrFail();
    }

    // ───────────────────────── accorgersi del proprio turno ─────────────────────────

    public function test_lo_stato_cambia_firma_quando_il_turno_scorre(): void
    {
        [, [$primo, $secondo], $draft] = $this->draft();

        $prima = $this->actingAs($secondo)->getJson(route('draft.stato'))->json();

        $this->assertFalse($prima['mio'], 'il turno non è ancora suo');

        // Il primo sbusta: da qui in poi la pagina del secondo è vecchia.
        app(PackOpener::class)->open($draft->activeTurn(), 'manager');

        $dopo = $this->actingAs($secondo)->getJson(route('draft.stato'))->json();

        $this->assertNotSame($prima['firma'], $dopo['firma']);
        $this->assertTrue($dopo['mio'], 'adesso tocca a lui e deve saperlo');
    }

    public function test_a_draft_fermo_la_firma_non_si_muove(): void
    {
        // Se la firma cambiasse da sola la pagina si ricaricherebbe ogni cinque
        // secondi, e nel mezzo di un draft sarebbe peggio del problema.
        [, [$marco]] = $this->draft();

        $prima = $this->actingAs($marco)->getJson(route('draft.stato'))->json('firma');
        $dopo = $this->actingAs($marco)->getJson(route('draft.stato'))->json('firma');

        $this->assertSame($prima, $dopo);
    }

    public function test_senza_draft_lo_stato_risponde_lo_stesso(): void
    {
        // La pagina «nessun draft» non interroga, ma una scheda lasciata aperta
        // da prima sì: non deve prendersi un 500.
        $stagione = $this->makeLeague();

        $this->actingAs($this->makeManager($stagione, 'Marco'))
            ->getJson(route('draft.stato'))
            ->assertOk()
            ->assertJson(['firma' => 'nessuno', 'mio' => false]);
    }

    public function test_lo_stato_e_riservato(): void
    {
        $this->getJson(route('draft.stato'))->assertUnauthorized();
    }

    // ───────────────────────── vedere cosa hanno preso gli altri ─────────────────────────

    public function test_lo_storico_mostra_le_buste_gia_aperte(): void
    {
        [, [$primo, $secondo], $draft] = $this->draft();

        $pack = app(PackOpener::class)->open($draft->activeTurn(), 'manager');

        $risposta = $this->actingAs($secondo)->get(route('draft.show'))->assertOk();

        $risposta->assertSee('Ultime buste aperte');
        $risposta->assertSee($primo->name);

        // I cognomi delle carte pescate: è l'informazione su cui si decide.
        foreach ($pack as $carta) {
            $risposta->assertSee($carta->player->last_name);
        }
    }

    public function test_lo_storico_dice_quando_ha_sbustato_il_sistema(): void
    {
        // Un turno chiuso d'ufficio non è la stessa cosa di uno scelto: chi
        // legge la fila deve poterlo distinguere.
        [, [, $secondo], $draft] = $this->draft();

        app(PackOpener::class)->open($draft->activeTurn(), 'auto');

        $this->actingAs($secondo)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee("d'ufficio", false);
    }

    public function test_la_cronologia_aggancia_le_carte(): void
    {
        // La fila delle buste aperte dice CHE COSA è stato portato via, ed è
        // l'informazione su cui si decide il turno dopo: un cognome da solo non
        // basta a sapere se quella era la carta che aspettavi.
        [, [, $secondo], $draft] = $this->draft();

        $pack = app(PackOpener::class)->open($draft->activeTurn(), 'auto');

        $risposta = $this->actingAs($secondo)->get(route('draft.show'))->assertOk();

        foreach ($pack as $carta) {
            $risposta->assertSee('data-carta="'.$carta->id.'"', false);
        }
    }

    // ───────────────────────── la cronologia completa ─────────────────────────

    public function test_di_base_la_cronologia_si_ferma_alle_ultime_otto(): void
    {
        // Le ultime scelte sono quello che serve al proprio turno: chi sta per
        // pescare vuole sapere cosa è appena sparito dal pool, non ripercorrere
        // il draft dall'inizio ogni volta che apre la pagina.
        [, [$marco], $draft] = $this->draftConStorico(12);

        $this->actingAs($marco)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee('8 di 12')
            ->assertDontSee('data-carta="'.$this->piuVecchia($draft)->id.'"', false);
    }

    public function test_si_chiedono_le_meno_recenti(): void
    {
        [, [$marco], $draft] = $this->draftConStorico(12);

        $this->actingAs($marco)
            ->get(route('draft.show'))
            ->assertOk()
            // Quante ne restano fuori, non un «mostra tutto» generico: dice
            // subito se vale la pena di aprirlo.
            ->assertSee('Mostra le meno recenti')
            ->assertSee('(4)', false);
    }

    public function test_la_cronologia_completa_le_mostra_tutte(): void
    {
        [, [$marco], $draft] = $this->draftConStorico(12);

        $this->actingAs($marco)
            ->get(route('draft.show', ['cronologia' => 'tutta']))
            ->assertOk()
            ->assertSee('Tutte le buste aperte')
            ->assertSee('12 di 12')
            ->assertSee('data-carta="'.$this->piuVecchia($draft)->id.'"', false)
            ->assertDontSee('Mostra le meno recenti');
    }

    public function test_da_aperta_si_puo_richiudere(): void
    {
        [, [$marco]] = $this->draftConStorico(12);

        $this->actingAs($marco)
            ->get(route('draft.show', ['cronologia' => 'tutta']))
            ->assertOk()
            ->assertSee('Mostra solo le ultime 8');
    }

    public function test_con_poche_buste_non_si_offre_niente_da_espandere(): void
    {
        // Un invito ad aprire una cronologia che è già tutta lì sarebbe un
        // pulsante che non fa niente.
        [, [$marco]] = $this->draftConStorico(3);

        $this->actingAs($marco)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertSee('3 di 3')
            ->assertDontSee('Mostra le meno recenti')
            ->assertDontSee('Mostra solo le ultime');
    }

    public function test_a_draft_appena_aperto_non_c_e_storico(): void
    {
        [, [$marco]] = $this->draft();

        $this->actingAs($marco)
            ->get(route('draft.show'))
            ->assertOk()
            ->assertDontSee('Ultime buste aperte');
    }
}
