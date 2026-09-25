<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Models\Standing;
use App\Models\Trade;
use App\Services\Calendar\CalendarBuilder;
use App\Services\Season\SeasonLoader;
use App\Services\Season\SeasonRunner;
use App\Services\Trade\TradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Rigiocare una stagione passata, una giornata alla volta.
 *
 * Le annate convivono: caricare il 2024 non tocca il 2023, e due gruppi possono
 * giocare anni diversi contemporaneamente. È la proprietà che più di ogni altra
 * va difesa qui, perché la sua rottura non si vede — produce solo classifiche
 * mescolate e giocatori nella squadra sbagliata.
 */
class SeasonReplayTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $league;

    /** @var Collection<int,Manager> */
    private Collection $squadre;

    private SeasonRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->league = $this->makeLeague();
        $this->runner = app(SeasonRunner::class);

        $this->squadre = collect(['Ada', 'Bruno'])
            ->map(fn (string $n) => $this->makeManager($this->league, $n));
    }

    /** Calendario di prova: una partita per giornata. */
    private function calendario(int $giornate, int $stagione = self::ANNATA): void
    {
        foreach (range(1, $giornate) as $g) {
            $this->makeFixture($g, now()->subYears(2)->addWeeks($g)->toDateTimeString(), $stagione)
                ->update(['status' => 'finished']);
        }
    }

    /** Un listone minimo, abbastanza per far girare draft e formazioni. */
    private function listone(int $perGiornata, int $anno = self::ANNATA, ?LeagueSeason $stagione = null): Collection
    {
        // Il pool va tenuto largo rispetto alle carte da distribuire. Con due
        // manager da venticinque servono cinquanta carte: su un listone di
        // cinquantadue il pool si esaurisce, PackGenerator salta gli slot che
        // non può più coprire e la rosa che ne esce puo' non comporre nessun
        // modulo. Non è un difetto del codice — è il suo modo di dirlo forte
        // invece di produrre una formazione monca — ma rende il test ballerino.
        // In una lega vera il rapporto è 300 carte su ~550, cioè il 56%.
        $composizione = str_repeat('P', 20).str_repeat('D', 40).str_repeat('C', 40).str_repeat('A', 30);

        return collect(str_split($composizione))->map(function (string $ruolo) use ($perGiornata, $anno, $stagione) {
            $player = $this->makePlayer($ruolo, season: $anno);

            // Il power appartiene alla stagione di LEGA: due gruppi sullo
            // stesso anno se lo calcolano ciascuno per conto suo, coi propri
            // pesi. Senza stagione si intende quella principale del test.
            foreach (range(1, $perGiornata) as $g) {
                $this->makePlayerPower($player, $g, stagione: $stagione ?? $this->league);
            }

            return $player;
        });
    }

    private function admin(): Manager
    {
        return tap($this->squadre->first())->update(['is_admin' => true]);
    }

    // ───────────────────────── lo stato ─────────────────────────

    public function test_senza_annate_in_casa_non_c_e_niente_da_giocare(): void
    {
        $this->assertTrue(app(SeasonLoader::class)->disponibili()->isEmpty());
        $this->assertNull($this->runner->prossima($this->league));
    }

    public function test_le_annate_in_casa_si_leggono_dal_calendario(): void
    {
        $this->calendario(3, stagione: 2022);
        $this->calendario(3, stagione: 2023);

        // Dalla più recente: è quella che si vuole vedere per prima.
        $this->assertSame([2023, 2022], app(SeasonLoader::class)->disponibili()->all());
    }

    public function test_un_annata_e_pronta_solo_con_listone_e_calendario(): void
    {
        $loader = app(SeasonLoader::class);

        $this->calendario(2, stagione: 2022);
        $this->assertFalse($loader->pronta(2022), 'senza listone non si gioca');

        $this->makePlayer('A', season: 2022);
        $this->assertTrue($loader->pronta(2022));
    }

    public function test_la_prossima_e_la_prima_senza_classifica(): void
    {
        $this->calendario(5);

        foreach ([1, 2] as $g) {
            Standing::create([
                'league_season_id' => $this->league->id,
                'manager_id' => $this->squadre->first()->id,
                'matchday' => $g, 'punti' => 3, 'fantapunti' => 60, 'posizione' => 1,
            ]);
        }

        $this->assertSame([1, 2], $this->runner->giocate($this->league));
        $this->assertSame(3, $this->runner->prossima($this->league));
    }

    public function test_una_lega_non_eredita_le_giornate_di_un_altra(): void
    {
        // Le statistiche di Serie A sono condivise — si scaricano una volta
        // sola — ma «giocata» è una faccenda di lega. Guardando le statistiche
        // questa lega salterebbe una giornata che non ha mai disputato.
        $altrui = $this->makeLeague('Altro gruppo');
        $this->calendario(3);

        $player = $this->makePlayer('A');
        PlayerStat::create(['player_id' => $player->id, 'season' => self::ANNATA, 'matchday' => 1, 'minutes' => 90, 'rating' => 6.5]);

        Standing::create([
            'league_season_id' => $altrui->id,
            'manager_id' => $this->makeManager($altrui, 'Estraneo')->id,
            'matchday' => 1, 'punti' => 3, 'fantapunti' => 60, 'posizione' => 1,
        ]);

        $this->assertSame([], $this->runner->giocate($this->league));
        $this->assertSame(1, $this->runner->prossima($this->league));
    }

    public function test_il_costo_in_chiamate_si_sa_prima(): void
    {
        // Una per partita: `/fixtures?id=` porta prestazioni ed eventi insieme.
        // Serve saperlo prima, non a metà scaricamento.
        $this->calendario(1);

        $this->assertSame(1, $this->runner->costoChiamate(self::ANNATA, 1));
    }

    // ───────────────────────── giocare ─────────────────────────

    public function test_giocare_una_giornata_fa_tutto_di_fila(): void
    {
        $this->calendario(4);
        $this->listone(4);

        foreach ($this->squadre as $manager) {
            $this->makeRoster($manager, 'PDDDDCCCCAA', matchday: 1);
        }

        app(CalendarBuilder::class)->generate($this->league, 1, 1);

        $esito = $this->runner->gioca($this->league, 1, simula: true);

        $this->assertSame('simulata', $esito['fonte']);
        $this->assertGreaterThan(0, $esito['voti']);
        $this->assertSame(2, $esito['formazioni']);
        $this->assertSame(2, $esito['classifica']);
        $this->assertNotNull(Standing::where('matchday', 1)->first());
    }

    public function test_dopo_la_giornata_il_draft_successivo_e_gia_aperto(): void
    {
        // Il calendario della stagione è nel passato: se il draft usasse i
        // primi fischi veri nascerebbe già scaduto.
        $this->calendario(4);
        $this->listone(4);

        foreach ($this->squadre as $manager) {
            $this->makeRoster($manager, 'PDDDDCCCCAA', matchday: 1);
        }

        $this->runner->gioca($this->league, 1, simula: true, oreDraft: 24);

        // ⚠️ La 2ª, non la 3ª. Lo sfasamento di due è finito: il draft della
        // giornata successiva apre quando questa COMINCIA, non due giornate
        // avanti. Era quello a far comparire in formazione le carte di una
        // giornata che il manager credeva ancora da pescare.
        $this->assertNull(Draft::where('matchday', 3)->first(), 'niente più draft a due giornate di distanza');

        $draft = Draft::where('matchday', 2)->first();

        $this->assertNotNull($draft);
        $this->assertSame('open', $draft->state);
        $this->assertTrue($draft->deadline_at->isFuture());
        $this->assertNotNull($draft->activeTurn(), 'il primo turno deve essere già attivo');
    }

    public function test_a_fine_stagione_non_si_prepara_nessun_draft(): void
    {
        $this->calendario(2);
        $this->listone(2);

        foreach ($this->squadre as $manager) {
            $this->makeRoster($manager, 'PDDDDCCCCAA', matchday: 2);
        }

        $esito = $this->runner->gioca($this->league, 2, simula: true);

        $this->assertNull($esito['draft']);
    }

    public function test_una_giornata_fuori_calendario_viene_rifiutata(): void
    {
        $this->calendario(3);

        $this->expectExceptionMessage('non è nel calendario');

        $this->runner->gioca($this->league, 9, simula: true);
    }

    public function test_la_giornata_di_un_altra_annata_non_conta_come_calendario(): void
    {
        // Il gruppo gioca il 2026: che il 2022 abbia una 9ª non lo autorizza.
        $this->calendario(3);
        $this->calendario(10, stagione: 2022);

        $this->expectExceptionMessage('non è nel calendario');

        $this->runner->gioca($this->league, 9, simula: true);
    }

    // ───────────────────────── il giro completo ─────────────────────────

    public function test_avviare_apre_solo_il_primo_draft(): void
    {
        // ⚠️ UNO, non due. Da qui in poi la catena si regge da sola — ogni
        // «giornata cominciata» apre il draft della successiva — ma la giornata
        // di partenza non ha nessuna giornata prima di sé a farlo, e senza
        // questo passaggio si arriverebbe alla 1ª con le rose vuote.
        //
        // Prima se ne aprivano due, e le carte della 2ª esistevano prima ancora
        // di giocare la 1ª: comparivano in formazione, e la busta quando si
        // apriva non rivelava più niente.
        $this->calendario(5);
        $this->listone(3);
        $this->makeFixture(0, now()->subYears(2)->toDateTimeString());

        $aperti = $this->runner->apriPrimoDraft($this->league);

        $this->assertCount(1, $aperti);
        $this->assertSame([1], Draft::where('league_season_id', $this->league->id)
            ->orderBy('matchday')->pluck('matchday')->all());
    }

    public function test_sbustare_d_ufficio_chiude_un_draft_per_volta(): void
    {
        // ⚠️ Uno solo, e la differenza è uno spoiler.
        //
        // All'avvio i draft aperti sono due — la giornata di partenza e quella
        // dopo — e prima di qui il pulsante li consumava entrambi in un colpo.
        // Le carte della 2ª finivano assegnate prima ancora di giocare la 1ª,
        // e siccome la pagina della formazione mostra la rosa della prossima
        // giornata da giocare, comparivano lì: elencate per nome, di una
        // giornata che il manager credeva ancora da pescare. Quando poi la
        // busta si apriva non rivelava più niente.
        $this->calendario(5);
        $this->listone(3);
        $this->makeFixture(0, now()->subYears(2)->toDateTimeString());

        $this->runner->apriPrimoDraft($this->league);

        // La 2ª apre quando la 1ª comincia: da qui in poi ce ne sono due in
        // ballo, ed è la condizione in cui il pulsante li consumava entrambi.
        $this->runner->iniziaGiornata($this->league, 1);

        $esito = $this->runner->concludiDraft($this->league->refresh());

        $this->assertSame(1, $esito['draft']);
        $this->assertGreaterThan(0, $esito['buste']);

        $carte = fn (int $g) => Card::where('league_season_id', $this->league->id)
            ->where('matchday', $g)->count();

        $this->assertSame(50, $carte(1), 'la 1ª è pescata: due squadre per venticinque carte');
        $this->assertSame(0, $carte(2), 'la 2ª non deve esistere ancora da nessuna parte');

        // Resta lì e aspetta il suo momento, invece di essere già consumato.
        $this->assertSame(2, $this->runner->draftInSospeso($this->league)?->matchday);

        $secondo = $this->runner->concludiDraft($this->league);

        $this->assertSame(1, $secondo['draft']);
        $this->assertSame(50, $carte(2));
        $this->assertNull($this->runner->draftInSospeso($this->league));
    }

    public function test_una_stagione_di_prova_arriva_in_fondo(): void
    {
        // Il giro che si fa dalla pagina: avvia, sbusta, gioca. Se uno dei tre
        // pezzi manca la stagione si pianta senza dirlo — giocare senza rose
        // produce zero formazioni e una classifica di soli zeri.
        $this->calendario(4);
        $this->listone(4);
        $this->makeFixture(0, now()->subYears(2)->toDateTimeString());

        app(CalendarBuilder::class)->generate($this->league, 1, 1);
        $this->runner->apriPrimoDraft($this->league);

        foreach ([1, 2] as $g) {
            $this->runner->concludiDraft($this->league);
            $esito = $this->runner->gioca($this->league, $g, simula: true);

            $this->assertSame(2, $esito['formazioni'], "la giornata {$g} non ha prodotto formazioni");
        }

        $this->assertSame([1, 2], $this->runner->giocate($this->league));
    }

    public function test_a_giornate_finite_la_stagione_si_chiude_da_sola(): void
    {
        // Restare «in corso» per sempre la terrebbe nel mirino del cron e non
        // direbbe a nessuno che è finita.
        $this->calendario(1);
        $this->listone(1);

        $this->runner->gioca($this->league, 1, simula: true);

        $this->assertSame('conclusa', $this->league->fresh()->state);
    }

    public function test_giocare_una_giornata_fa_scadere_le_proposte_appese(): void
    {
        // Il mercato chiude al primo fischio: una proposta rimasta pendente
        // riapparirebbe come accettabile quando le carte non sono più in rosa.
        $this->calendario(2);
        $this->listone(2);

        [$ada, $bruno] = [$this->squadre->first(), $this->squadre->last()];
        $rosaAda = $this->makeRoster($ada, 'PDDDDCCCCAA');
        $rosaBruno = $this->makeRoster($bruno, 'PDDDDCCCCAA');

        app(TradeService::class)->propose(
            $this->league, $ada, $bruno,
            offered: [$this->firstOfRole($rosaAda, 'A')->id],
            requested: [$this->firstOfRole($rosaBruno, 'D')->id],
            matchday: 1,
        );

        $esito = $this->runner->gioca($this->league, 1, simula: true);

        $this->assertSame(1, $esito['scambi_scaduti']);
        $this->assertSame('expired', Trade::firstOrFail()->state);
    }

    // ───────────────────────── le annate convivono ─────────────────────────

    public function test_buttare_un_annata_non_tocca_le_altre(): void
    {
        $this->calendario(3, stagione: 2021);
        $this->listone(1, anno: 2021);
        $this->calendario(3, stagione: 2022);
        $this->listone(1, anno: 2022);

        app(SeasonLoader::class)->scarta(2021);

        $this->assertSame(0, Fixture::where('season', 2021)->count());
        $this->assertSame(0, PlayerSeason::where('season', 2021)->count());
        $this->assertSame(3, Fixture::where('season', 2022)->count());
        $this->assertGreaterThan(0, PlayerSeason::where('season', 2022)->count());
    }

    public function test_un_annata_in_gioco_non_si_butta(): void
    {
        // Cancellare il listone sotto a una stagione viva lascerebbe carte che
        // puntano a giocatori senza ruolo, cioè rose non più schierabili.
        $this->calendario(3);
        $this->listone(1);

        $this->expectExceptionMessage('cancellale prima');

        app(SeasonLoader::class)->scarta(self::ANNATA);
    }

    public function test_lo_stesso_gruppo_puo_giocare_due_annate(): void
    {
        $seconda = $this->makeStagione($this->league, 2022);

        $this->calendario(3);
        $this->listone(3);
        $this->calendario(3, stagione: 2022);
        $this->listone(3, anno: 2022, stagione: $seconda);

        foreach ($this->squadre as $manager) {
            $this->makeRoster($manager, 'PDDDDCCCCAA', matchday: 1);
            $this->makeRoster($manager, 'PDDDDCCCCAA', matchday: 1, stagione: $seconda);
        }

        $this->runner->gioca($this->league, 1, simula: true);
        $this->runner->gioca($seconda, 1, simula: true);

        // Le carte, le classifiche e le formazioni restano ciascuna a casa sua.
        $this->assertSame(2, Standing::where('league_season_id', $this->league->id)->count());
        $this->assertSame(2, Standing::where('league_season_id', $seconda->id)->count());
        $this->assertSame(22, Card::where('league_season_id', $this->league->id)->count());
        $this->assertSame(22, Card::where('league_season_id', $seconda->id)->count());
    }

    // ───────────────────────── la pagina di gestione ─────────────────────────

    public function test_la_pagina_e_riservata_all_admin(): void
    {
        $this->actingAs($this->squadre->last())
            ->get(route('admin.gestione'))
            ->assertForbidden();
    }

    public function test_l_admin_vede_annate_e_stagioni(): void
    {
        $this->calendario(5, stagione: 2023);
        $this->listone(1, anno: 2023);

        $this->actingAs($this->admin())
            ->get(route('admin.gestione'))
            ->assertOk()
            ->assertSee('2023/24')
            ->assertSee('Nuova stagione');
    }

    public function test_l_admin_crea_una_stagione_dalla_pagina(): void
    {
        $this->calendario(5, stagione: 2023);
        $this->listone(1, anno: 2023);

        $this->actingAs($this->admin())
            ->post(route('admin.stagione.crea'), ['season' => 2023, 'start_matchday' => 1])
            ->assertRedirect()
            ->assertSessionHas('successo');

        $this->assertNotNull(
            LeagueSeason::where('league_id', $this->league->league_id)->where('season', 2023)->first(),
        );
    }

    public function test_non_si_crea_una_stagione_su_un_annata_che_non_c_e(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.stagione.crea'), ['season' => 2019, 'start_matchday' => 1])
            ->assertSessionHasErrors('stagione');
    }

    public function test_la_stessa_annata_non_si_gioca_due_volte_nello_stesso_gruppo(): void
    {
        $this->calendario(3);
        $this->listone(1);

        $this->actingAs($this->admin())
            ->post(route('admin.stagione.crea'), ['season' => self::ANNATA, 'start_matchday' => 1])
            ->assertSessionHasErrors('stagione');
    }

    public function test_l_admin_gioca_una_giornata_dalla_pagina(): void
    {
        $this->calendario(4);
        $this->listone(4);

        foreach ($this->squadre as $manager) {
            $this->makeRoster($manager, 'PDDDDCCCCAA', matchday: 1);
        }

        $this->actingAs($this->admin())
            ->post(route('admin.stagione.gioca', $this->league), ['giornata' => 1, 'simula' => 1, 'ore' => 12])
            ->assertRedirect()
            ->assertSessionHas('successo');

        $this->assertSame([1], $this->runner->giocate($this->league));
    }

    public function test_non_si_gioca_la_stagione_di_un_altro_gruppo(): void
    {
        $altrui = $this->makeLeague('Altro gruppo');

        $this->actingAs($this->admin())
            ->post(route('admin.stagione.gioca', $altrui), ['giornata' => 1, 'ore' => 12])
            ->assertNotFound();
    }
}
