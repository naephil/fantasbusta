<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\Trade;
use App\Services\Trade\TradeException;
use App\Services\Trade\TradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Ciclo di vita degli scambi.
 *
 * ⚠️ Gira su SQLite, dove `lockForUpdate()` è un'istruzione muta: questi test
 * dimostrano che le regole sono giuste, NON che i lock funzionano. Le prove di
 * concorrenza vere vanno fatte su MariaDB — docs/DESIGN.md §8.
 */
class TradeTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private TradeService $trades;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trades = new TradeService;
    }

    // ───────────────────────── quando apre il mercato ─────────────────────────

    public function test_a_draft_aperto_non_si_scambia(): void
    {
        // ⚠️ §4 lo dice da sempre — il trading apre a draft concluso — ma
        // nessuno lo imponeva. La pagina si comportava da aperta e poi ogni
        // proposta veniva respinta dal pavimento delle undici: a metà draft una
        // rosa di undici carte non esiste ancora per definizione. Il manager
        // leggeva «rosa troppo corta», cioè la conseguenza, e il motivo vero
        // non compariva da nessuna parte.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        Draft::create([
            'league_season_id' => $league->id,
            'matchday' => 1,
            'state' => 'open',
            'opens_at' => now()->subHour(),
            'deadline_at' => now()->addHours(24),
            'rounds' => 5,
            'pack_size' => 5,
        ]);

        $this->expectException(TradeException::class);
        $this->expectExceptionMessage('non è ancora finito');

        $this->trades->propose(
            $league, $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'A')->id],
            requested: [$this->firstOfRole($rosaGiulia, 'D')->id],
            matchday: 1,
        );
    }

    public function test_a_draft_chiuso_il_mercato_apre(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        Draft::create([
            'league_season_id' => $league->id,
            'matchday' => 1,
            'state' => 'closed',
            'opens_at' => now()->subDay(),
            'deadline_at' => now()->subHour(),
            'rounds' => 5,
            'pack_size' => 5,
        ]);

        $trade = $this->trades->propose(
            $league, $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'A')->id],
            requested: [$this->firstOfRole($rosaGiulia, 'D')->id],
            matchday: 1,
        );

        $this->assertSame('pending', $trade->state);
    }

    public function test_il_draft_di_un_altra_giornata_non_blocca_il_mercato(): void
    {
        // Il draft della giornata dopo è aperto quasi sempre: bloccare su
        // quello terrebbe il mercato chiuso per tutta la stagione.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        Draft::create([
            'league_season_id' => $league->id,
            'matchday' => 2,
            'state' => 'open',
            'opens_at' => now()->subHour(),
            'deadline_at' => now()->addHours(24),
            'rounds' => 5,
            'pack_size' => 5,
        ]);

        $trade = $this->trades->propose(
            $league, $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'A')->id],
            requested: [$this->firstOfRole($rosaGiulia, 'D')->id],
            matchday: 1,
        );

        $this->assertSame('pending', $trade->state);
    }

    // ───────────────────────── proposta ─────────────────────────

    public function test_la_proposta_registra_le_carte_nei_due_versi(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $trade = $this->trades->propose(
            $league,
            $marco,
            $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'A')->id],
            requested: [$this->firstOfRole($rosaGiulia, 'D')->id],
            matchday: 1,
        );

        $this->assertSame('pending', $trade->state);
        $this->assertCount(1, $trade->offered()->get());
        $this->assertCount(1, $trade->requested()->get());
    }

    public function test_non_si_propongono_carte_che_non_si_possiedono(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $this->makeRoster($marco, 'PDDDDCCCCAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAA');

        $this->expectException(TradeException::class);

        // La carta è di Giulia, non di Marco.
        $this->trades->propose(
            $league,
            $marco,
            $giulia,
            offered: [$this->firstOfRole($rosaGiulia, 'A')->id],
            requested: [],
            matchday: 1,
        );
    }

    public function test_la_proposta_non_valuta_il_pavimento(): void
    {
        // Marco offre il suo unico portiere e non chiede nulla: alla proposta
        // deve passare comunque. La validazione è all'accettazione, perché nel
        // frattempo Marco può procurarsi un secondo portiere altrove.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAA');
        $this->makeRoster($giulia, 'PDDDDCCCCAA');

        $trade = $this->trades->propose(
            $league,
            $marco,
            $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'P')->id],
            requested: [],
            matchday: 1,
        );

        $this->assertSame('pending', $trade->state);
    }

    // ───────────────────────── accettazione ─────────────────────────

    public function test_l_accettazione_sposta_le_carte(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $daMarco = $this->firstOfRole($rosaMarco, 'A');
        $daGiulia = $this->firstOfRole($rosaGiulia, 'D');

        $trade = $this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$daMarco->id],
            requested: [$daGiulia->id],
            matchday: 1,
        );

        $accettato = $this->trades->accept($trade);

        $this->assertSame('accepted', $accettato->state);
        $this->assertNotNull($accettato->resolved_at);
        $this->assertSame($giulia->id, $daMarco->fresh()->owner_manager_id);
        $this->assertSame($marco->id, $daGiulia->fresh()->owner_manager_id);
    }

    public function test_la_provenienza_della_carta_non_cambia_mai(): void
    {
        // `original_owner_id` è ciò che rende leggibile il feed a fine
        // giornata: dice da chi è passata la carta, non chi ce l'ha adesso.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $carta = $this->firstOfRole($rosaMarco, 'A');

        $this->trades->accept($this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$carta->id],
            requested: [],
            matchday: 1,
        ));

        $carta->refresh();

        $this->assertSame($giulia->id, $carta->owner_manager_id);
        $this->assertSame($marco->id, $carta->original_owner_id);
    }

    public function test_lo_scambio_sette_per_uno_e_permesso(): void
    {
        // Chi cede sette carte per una consolida qualità e si assottiglia,
        // chi accetta si riempie di panchina. Finché entrambi restano
        // schierabili non c'è nessuna regola che lo vieti.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PPDDDDDDCCCCCCAAAA');   // 18 carte
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAA');        // 11 secche

        // Il secondo portiere fra le sette: è merce di scambio reale proprio
        // perché la garanzia della busta ne assicura uno solo.
        $sette = $this->pickByRole($rosaMarco, ['P' => 1, 'D' => 3, 'C' => 2, 'A' => 1]);

        $trade = $this->trades->accept($this->trades->propose(
            $league,
            $marco, $giulia,
            offered: $sette,
            requested: [$this->firstOfRole($rosaGiulia, 'A')->id],
            matchday: 1,
        ));

        $this->assertSame('accepted', $trade->state, $trade->reject_reason ?? '');
        $this->assertSame(12, Card::where('owner_manager_id', $marco->id)->count());
        $this->assertSame(17, Card::where('owner_manager_id', $giulia->id)->count());
    }

    // ───────────────────────── il pavimento ─────────────────────────

    public function test_lo_scambio_che_lascia_senza_portiere_e_rifiutato_col_motivo(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAA');          // un solo portiere
        $rosaGiulia = $this->makeRoster($giulia, 'PPDDDDDCCCCAAA');

        $trade = $this->trades->accept($this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'P')->id],
            requested: [$this->firstOfRole($rosaGiulia, 'D')->id],
            matchday: 1,
        ));

        $this->assertSame('rejected', $trade->state);
        $this->assertSame('Marco resterebbe senza portiere', $trade->reject_reason);

        // Nulla si è mosso.
        $this->assertSame(11, Card::where('owner_manager_id', $marco->id)->count());
    }

    public function test_il_pavimento_vale_anche_per_chi_accetta(): void
    {
        // Giulia accetterebbe volentieri, ma cedendo scenderebbe sotto gli
        // undici: il rifiuto scatta sulla sua rosa, non su quella di Marco.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $this->makeRoster($marco, 'PPDDDDDDCCCCCCAAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAA');   // 11 secche

        $trade = $this->trades->accept($this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [],
            requested: [$this->firstOfRole($rosaGiulia, 'C')->id],
            matchday: 1,
        ));

        $this->assertSame('rejected', $trade->state);
        $this->assertStringStartsWith('Giulia', $trade->reject_reason);
        $this->assertStringContainsString('10 carte', $trade->reject_reason);
    }

    public function test_una_carta_ceduta_nel_frattempo_manda_a_vuoto_la_proposta(): void
    {
        // Il motivo per cui la validazione sta all'accettazione: fra la
        // proposta e l'accettazione la carta è finita a un terzo manager.
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');
        $luca = $this->makeManager($league, 'Luca');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $this->makeRoster($giulia, 'PDDDDCCCCAAA');
        $this->makeRoster($luca, 'PDDDDCCCCAAA');

        $carta = $this->firstOfRole($rosaMarco, 'A');

        $trade = $this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$carta->id],
            requested: [],
            matchday: 1,
        );

        $carta->update(['owner_manager_id' => $luca->id]);

        $esito = $this->trades->accept($trade);

        $this->assertSame('rejected', $esito->state);
        $this->assertStringContainsString('non è più nella rosa', $esito->reject_reason);
        $this->assertSame($luca->id, $carta->fresh()->owner_manager_id);
    }

    // ───────────────────────── chiusure ─────────────────────────

    public function test_una_proposta_gia_risolta_non_si_riapplica(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $carta = $this->firstOfRole($rosaMarco, 'A');

        $trade = $this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$carta->id],
            requested: [],
            matchday: 1,
        );

        $this->trades->accept($trade);
        $this->trades->accept($trade->fresh());

        // La seconda accettazione non deve rimandare indietro nulla.
        $this->assertSame($giulia->id, $carta->fresh()->owner_manager_id);
        $this->assertSame(11, Card::where('owner_manager_id', $marco->id)->count());
        $this->assertSame(13, Card::where('owner_manager_id', $giulia->id)->count());
    }

    public function test_il_rifiuto_esplicito_non_porta_motivo_automatico(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $trade = $this->trades->reject($this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'A')->id],
            requested: [],
            matchday: 1,
        ));

        $this->assertSame('rejected', $trade->state);
        $this->assertNull($trade->reject_reason);
    }

    public function test_le_proposte_appese_scadono_alla_deadline(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $viva = $this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'A')->id],
            requested: [],
            matchday: 1,
        );

        $risolta = $this->trades->reject($this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'D')->id],
            requested: [],
            matchday: 1,
        ));

        $scadute = $this->trades->expirePending($league->id, matchday: 1);

        $this->assertSame(1, $scadute);
        $this->assertSame('expired', $viva->fresh()->state);
        $this->assertSame('rejected', $risolta->fresh()->state);
    }

    public function test_il_feed_pubblico_mostra_solo_gli_scambi_risolti(): void
    {
        $league = $this->makeLeague();
        $marco = $this->makeManager($league, 'Marco');
        $giulia = $this->makeManager($league, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $this->trades->accept($this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'A')->id],
            requested: [],
            matchday: 1,
        ));

        $this->trades->propose(
            $league,
            $marco, $giulia,
            offered: [$this->firstOfRole($rosaMarco, 'D')->id],
            requested: [],
            matchday: 1,
        );

        $feed = Trade::feed($league->id, 1)->get();

        $this->assertCount(1, $feed);
        $this->assertSame('accepted', $feed->first()->state);
    }
}
