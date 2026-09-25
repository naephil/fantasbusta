<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftTurn;
use App\Models\Manager;
use App\Services\Draft\DraftBuilder;
use App\Services\Draft\PackOpener;
use App\Services\Trade\TradeService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Le corse che il gioco può davvero incontrare, provate sul serio.
 *
 * ⚠️ Questi test hanno senso **solo su MariaDB**: su SQLite `lockForUpdate()` è
 * un'istruzione muta e Laravel non protesta, quindi passerebbero senza aver mai
 * esercitato un lock. Si saltano da soli sull'altro database invece di dare una
 * falsa sicurezza — che è il modo in cui un test di concorrenza fa più danno di
 * quanto non ne eviti.
 *
 * La simultaneità si ottiene con connessioni distinte e transazioni aperte a
 * mano, non con processi separati: il punto da dimostrare è che il secondo
 * arrivato si mette in fila sul lock e poi trova il lavoro già fatto, e per
 * questo bastano due connessioni.
 */
class ConcorrenzaTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('I lock si provano solo su MariaDB: su SQLite non fanno nulla.');
        }
    }

    /**
     * Una seconda connessione al database, davvero separata.
     *
     * `DB::connection()` riusa la stessa: senza una connessione propria le due
     * transazioni sarebbero la stessa transazione, e il lock non verrebbe mai
     * conteso.
     */
    private function altraConnessione(): Connection
    {
        config()->set('database.connections.seconda', config('database.connections.'.config('database.default')));

        return DB::connection('seconda');
    }

    // ───────────────────────── scambi ─────────────────────────

    public function test_la_stessa_proposta_accettata_due_volte_sposta_le_carte_una_volta_sola(): void
    {
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $giulia = $this->makeManager($stagione, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $mia = $this->firstOfRole($rosaMarco, 'A');
        $sua = $this->firstOfRole($rosaGiulia, 'D');

        $trades = app(TradeService::class);
        $trade = $trades->propose($stagione, $marco, $giulia, [$mia->id], [$sua->id], 1);

        $primo = $trades->accept($trade);
        $secondo = $trades->accept($trade->fresh());

        $this->assertSame('accepted', $primo->state);
        $this->assertSame('accepted', $secondo->state);

        // La carta ha cambiato mano una volta sola: se il ricontrollo dentro la
        // transazione non ci fosse, il secondo passaggio la rimanderebbe
        // indietro e i due manager si scambierebbero le carte due volte.
        $this->assertSame($giulia->id, $mia->fresh()->owner_manager_id);
        $this->assertSame($marco->id, $sua->fresh()->owner_manager_id);
    }

    public function test_due_scambi_incrociati_sulla_stessa_carta_non_la_duplicano(): void
    {
        // È la corsa classica: due proposte diverse che vogliono la stessa
        // carta, accettate nello stesso istante. Senza il lock sulle rose la
        // carta finirebbe a entrambi.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $giulia = $this->makeManager($stagione, 'Giulia');
        $luca = $this->makeManager($stagione, 'Luca');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAAA');
        $rosaLuca = $this->makeRoster($luca, 'PDDDDCCCCAAA');

        $conteso = $this->firstOfRole($rosaMarco, 'A');

        $trades = app(TradeService::class);

        $aGiulia = $trades->propose($stagione, $marco, $giulia, [$conteso->id], [$this->firstOfRole($rosaGiulia, 'D')->id], 1);
        $aLuca = $trades->propose($stagione, $marco, $luca, [$conteso->id], [$this->firstOfRole($rosaLuca, 'D')->id], 1);

        $primo = $trades->accept($aGiulia);
        $secondo = $trades->accept($aLuca);

        $this->assertSame('accepted', $primo->state);

        // Il secondo trova che la carta non è più di chi doveva cederla.
        $this->assertSame('rejected', $secondo->state);
        $this->assertStringContainsString('non è più nella rosa', $secondo->reject_reason);

        $this->assertSame(1, Card::whereKey($conteso->id)->count());
        $this->assertSame($giulia->id, $conteso->fresh()->owner_manager_id);
    }

    public function test_il_lock_sulla_proposta_mette_in_fila_il_secondo(): void
    {
        // Qui si dimostra il lock, non solo il suo effetto: la seconda
        // connessione deve restare BLOCCATA finché la prima non chiude.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $giulia = $this->makeManager($stagione, 'Giulia');

        $rosaMarco = $this->makeRoster($marco, 'PDDDDCCCCAAA');
        $rosaGiulia = $this->makeRoster($giulia, 'PDDDDCCCCAAA');

        $trade = app(TradeService::class)->propose(
            $stagione, $marco, $giulia,
            [$this->firstOfRole($rosaMarco, 'A')->id],
            [$this->firstOfRole($rosaGiulia, 'D')->id],
            1,
        );

        $seconda = $this->altraConnessione();
        $seconda->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        DB::table('trades')->where('id', $trade->id)->lockForUpdate()->first();

        $bloccato = false;

        try {
            $seconda->beginTransaction();
            $seconda->table('trades')->where('id', $trade->id)->lockForUpdate()->first();
            $seconda->rollBack();
        } catch (\Throwable $e) {
            $bloccato = str_contains(strtolower($e->getMessage()), 'lock wait timeout');
            $seconda->rollBack();
        }

        DB::rollBack();

        $this->assertTrue($bloccato, 'la seconda connessione doveva restare in attesa del lock');
    }

    // ───────────────────────── sbustamento ─────────────────────────

    public function test_due_aperture_dello_stesso_turno_danno_una_busta_sola(): void
    {
        [$draft, $turno] = $this->draftPronto();

        $opener = app(PackOpener::class);

        $primo = $opener->open($turno, 'manager');
        $secondo = $opener->open($turno->fresh(), 'auto');

        $this->assertNotNull($primo, 'la prima apertura deve dare le carte');
        $this->assertNull($secondo, 'la seconda deve uscire a mani vuote');

        $this->assertSame(
            $draft->pack_size,
            Card::where('draft_turn_id', $turno->id)->count(),
        );
    }

    public function test_far_scorrere_la_coda_da_due_parti_non_attiva_due_turni(): void
    {
        // Il cron gira ogni minuto, l'amministratore può premere «sbusta tutto»
        // e un manager può aprire la sua busta: sono tre strade che arrivano
        // tutte a far scorrere la stessa coda.
        [$draft] = $this->draftPronto();

        $opener = app(PackOpener::class);

        $opener->activateNext($draft);
        $opener->activateNext($draft->fresh());
        $opener->activateNext($draft->fresh());

        $this->assertSame(
            1,
            DraftTurn::where('draft_id', $draft->id)->where('state', 'active')->count(),
            'due turni attivi insieme scardinano l\'ordine dello snake',
        );
    }

    public function test_il_pool_non_assegna_lo_stesso_giocatore_a_due_manager(): void
    {
        // La garanzia di esclusività della giornata: è l'invariante che rende
        // sensato il gioco, e l'unique in tabella è la sua ultima rete.
        [$draft] = $this->draftPronto();

        $opener = app(PackOpener::class);

        while ($turno = $draft->fresh()->activeTurn()) {
            $opener->open($turno, 'auto');
        }

        $carte = Card::where('league_season_id', $draft->league_season_id)
            ->where('matchday', $draft->matchday)
            ->pluck('player_id');

        $this->assertSame($carte->count(), $carte->unique()->count(), 'un giocatore è finito in due rose');
    }

    /**
     * Un draft aperto col primo turno già attivo.
     *
     * @return array{Draft, DraftTurn}
     */
    private function draftPronto(): array
    {
        $stagione = $this->makeLeague();

        foreach (['Ada', 'Bruno', 'Carla'] as $nome) {
            $this->makeManager($stagione, $nome);
        }

        $this->makeFixture(2, now()->subDay()->toDateTimeString());
        $this->makeFixture(3, now()->addDays(2)->toDateTimeString());

        foreach (str_split(str_repeat('P', 6).str_repeat('D', 14).str_repeat('C', 14).str_repeat('A', 10)) as $ruolo) {
            $this->makePlayerPower($this->makePlayer($ruolo), 3, stagione: $stagione);
        }

        $draft = app(DraftBuilder::class)->build($stagione, 3);
        $draft->update(['state' => 'open']);

        app(PackOpener::class)->activateNext($draft);

        return [$draft->fresh(), $draft->fresh()->activeTurn()];
    }
}
