<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Models\Team;
use App\Services\Ingest\ApiFootball;
use App\Services\Ingest\StatSync;
use App\Services\Simulation\MatchdaySimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Scaricare da una parte e giocare dall'altra.
 *
 * Il tetto dell'API è per CHIAVE e non per macchina, quindi conviene scaricare
 * in locale — dove il tempo di esecuzione non ha limiti — e portare il
 * pacchetto sul server. Qui si difende che il viaggio non perda né alteri
 * niente, e che i voti veri non si possano cancellare per sbaglio.
 */
class PacchettoAnnataTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = storage_path('app/test-pacchetto.jsonl');
        File::delete($this->file);
    }

    protected function tearDown(): void
    {
        File::delete($this->file);

        parent::tearDown();
    }

    /** Un'annata minima ma completa: squadre, listone, calendario, statistiche. */
    private function annata(int $anno = self::ANNATA): Player
    {
        $this->makeFixture(1, '2023-08-19 18:30:00', $anno)->update([
            'status' => 'finished', 'home_goals' => 2, 'away_goals' => 1,
        ]);

        $player = $this->makePlayer('A', quotazione: 28.5, season: $anno);

        PlayerStat::create([
            'player_id' => $player->id,
            'season' => $anno,
            'matchday' => 1,
            'source' => 'reale',
            'minutes' => 90,
            'rating' => 7.4,
            'goals' => 2,
            'assists' => 1,
        ]);

        return $player;
    }

    private function esporta(array $opzioni = []): int
    {
        return $this->artisan('annata:esporta', ['anno' => self::ANNATA, '--out' => $this->file] + $opzioni)->run();
    }

    // ───────────────────────── andata e ritorno ─────────────────────────

    public function test_il_pacchetto_conserva_tutto_quello_che_serve(): void
    {
        $player = $this->annata();

        $this->esporta();
        $this->assertFileExists($this->file);

        // Si azzera tutto e si riparte dal solo pacchetto, come farebbe il
        // server appena installato.
        PlayerStat::query()->delete();
        PlayerSeason::query()->delete();
        Fixture::query()->delete();
        Player::query()->delete();
        Team::query()->delete();

        $this->artisan('annata:importa', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(1, Player::count());
        $this->assertSame('Giocatore A', Player::find($player->id)->last_name);

        $listone = PlayerSeason::where('player_id', $player->id)->firstOrFail();
        $this->assertSame('A', $listone->role->value);
        $this->assertSame(28.5, $listone->quotazione_iniziale);

        $partita = Fixture::firstOrFail();
        $this->assertSame(2, $partita->home_goals);

        // ⚠️ La data va riletta identica: se l'export la serializza in ISO-8601
        // coi microsecondi, MariaDB rifiuta l'inserimento — e SQLite invece la
        // accetta come testo, quindi il difetto si vedrebbe solo sul server.
        $this->assertSame('2023-08-19 18:30:00', $partita->kickoff_at->format('Y-m-d H:i:s'));

        $stat = PlayerStat::firstOrFail();
        $this->assertSame(2, $stat->goals);
        $this->assertSame(7.4, $stat->rating);
        $this->assertSame('reale', $stat->source);
    }

    public function test_reimportare_aggiorna_invece_di_duplicare(): void
    {
        // È il modo previsto di lavorare: sul piano gratuito l'annata arriva
        // un pezzo al giorno, e si reimporta il pacchetto man mano che cresce.
        $this->annata();
        $this->esporta();

        $this->artisan('annata:importa', ['file' => $this->file])->assertSuccessful();
        $this->artisan('annata:importa', ['file' => $this->file])->assertSuccessful();

        $this->assertSame(1, PlayerStat::count());
        $this->assertSame(1, PlayerSeason::count());
        $this->assertSame(1, Fixture::count());
    }

    public function test_le_giornate_simulate_restano_fuori_dal_pacchetto(): void
    {
        // Sono dati inventati: trapiantarli su un'altra installazione
        // significherebbe spacciarli per veri proprio dove nessuno sa che non
        // lo sono.
        $this->annata();

        $this->makeFixture(2, '2023-08-26 18:30:00')->update(['status' => 'finished']);
        app(MatchdaySimulator::class)->simulate(self::ANNATA, 2);

        $this->assertSame(2, PlayerStat::distinct()->count('matchday'));

        $this->esporta();

        $righe = collect(file($this->file))
            ->map(fn (string $r) => json_decode($r, true))
            ->filter(fn (array $r) => ($r['t'] ?? '') === 'player_stat');

        $this->assertTrue($righe->every(fn (array $r) => $r['source'] === 'reale'));
        $this->assertTrue($righe->every(fn (array $r) => $r['matchday'] === 1));
    }

    public function test_un_ruolo_deciso_a_mano_non_viene_sovrascritto(): void
    {
        // Il listone è una decisione dell'amministratore di QUESTA
        // installazione: un pacchetto non ha titolo per calpestarla.
        $player = $this->annata();
        $this->esporta();

        PlayerSeason::where('player_id', $player->id)->update([
            'role' => 'D', 'quotazione_iniziale' => 9.0, 'role_confirmed' => true,
        ]);

        $this->artisan('annata:importa', ['file' => $this->file])->assertSuccessful();

        $listone = PlayerSeason::where('player_id', $player->id)->firstOrFail();
        $this->assertSame('D', $listone->role->value);
        $this->assertSame(9.0, $listone->quotazione_iniziale);
    }

    public function test_si_puo_forzare_la_sovrascrittura_del_listone(): void
    {
        $player = $this->annata();
        $this->esporta();

        PlayerSeason::where('player_id', $player->id)->update(['role' => 'D', 'role_confirmed' => true]);

        $this->artisan('annata:importa', ['file' => $this->file, '--sovrascrivi-listone' => true])
            ->assertSuccessful();

        $this->assertSame('A', PlayerSeason::where('player_id', $player->id)->firstOrFail()->role->value);
    }

    public function test_un_file_che_non_e_un_pacchetto_viene_rifiutato(): void
    {
        File::put($this->file, "non sono un pacchetto\n");

        $this->artisan('annata:importa', ['file' => $this->file])->assertFailed();
    }

    public function test_esportare_un_annata_che_non_c_e_fallisce(): void
    {
        $this->assertSame(1, $this->esporta());
    }

    // ───────────────────────── i voti veri si difendono ─────────────────────────

    public function test_simulare_una_giornata_gia_scaricata_viene_impedito(): void
    {
        // ⚠️ Il simulatore scrive sulla stessa chiave del sync. Senza questo
        // blocco, premere «Avanti» con *simula* spuntato su una giornata già
        // scaricata butterebbe via dieci chiamate di voti veri — e in silenzio,
        // perché i gol vengono ridistribuiti dal risultato vero e il totale
        // della giornata torna comunque.
        $this->annata();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cancellerebbe');

        app(MatchdaySimulator::class)->simulate(self::ANNATA, 1);
    }

    public function test_una_giornata_mai_scaricata_si_simula_senza_storie(): void
    {
        $this->makeFixture(5, '2023-09-20 18:30:00')->update(['status' => 'finished']);
        $this->makePlayer('C');

        $esito = app(MatchdaySimulator::class)->simulate(self::ANNATA, 5);

        $this->assertSame(1, $esito['partite']);
        $this->assertSame('simulata', PlayerStat::where('matchday', 5)->firstOrFail()->source);
    }

    public function test_forzando_si_puo_simulare_sopra_ai_voti_veri(): void
    {
        // Deve restare possibile — a volte è proprio quello che si vuole — ma
        // solo chiedendolo, non per distrazione.
        $this->annata();

        $esito = app(MatchdaySimulator::class)->simulate(self::ANNATA, 1, sovrascriviReali: true);

        $this->assertSame(1, $esito['partite']);
        $this->assertSame('simulata', PlayerStat::where('matchday', 1)->firstOrFail()->source);
    }

    public function test_i_dati_veri_spazzano_via_quelli_simulati_della_stessa_giornata(): void
    {
        // ⚠️ Non basta sovrascriverle: il simulatore scrive per TUTTA la rosa,
        // il tabellino vero solo per i convocati. Le righe di troppo
        // sopravviverebbero all'aggiornamento — voti inventati addosso a gente
        // che quel giorno non era nemmeno in panchina — e il calcolo le
        // prenderebbe per buone.
        $this->makeFixture(3, '2023-09-01 18:30:00')->update(['status' => 'finished']);

        $convocato = $this->makePlayer('A');
        $rimastoACasa = $this->makePlayer('C');

        app(MatchdaySimulator::class)->simulate(self::ANNATA, 3);
        $this->assertSame(2, PlayerStat::where('matchday', 3)->count());

        Http::fake(['*/fixtures*' => Http::response([
            'errors' => [],
            'results' => 1,
            'paging' => ['current' => 1, 'total' => 1],
            'response' => [[
                'fixture' => ['id' => self::ANNATA * 1000 + 3],
                'events' => [],
                'players' => [[
                    'team' => ['id' => 500],
                    'players' => [[
                        'player' => ['id' => $convocato->id],
                        'statistics' => [['games' => ['minutes' => 90, 'rating' => '7.0']]],
                    ]],
                ]],
            ]],
        ])]);

        $esito = (new StatSync(new ApiFootball))->matchday(self::ANNATA, 3);

        $this->assertSame(2, $esito['ripulite']);
        $this->assertSame(1, PlayerStat::where('matchday', 3)->count());
        $this->assertSame('reale', PlayerStat::where('matchday', 3)->firstOrFail()->source);

        // Chi non è sceso in campo non deve avere più nessuna riga.
        $this->assertSame(0, PlayerStat::where('player_id', $rimastoACasa->id)->count());
    }

    public function test_una_giornata_solo_simulata_resta_da_scaricare(): void
    {
        // Contarla come «già in casa» farebbe rifiutare lo scarico di ciò che
        // manca davvero: è il modo più sicuro di credere di avere un'annata
        // che invece è per metà inventata.
        $this->annata();   // giornata 1, reale

        $this->makeFixture(2, '2023-08-26 18:30:00')->update(['status' => 'finished']);
        app(MatchdaySimulator::class)->simulate(self::ANNATA, 2);

        Http::fake();

        // Solo la 2ª: così l'unica cosa in gioco è se una giornata simulata
        // venga offerta o scartata. La riserva altissima azzera il budget, e il
        // comando si limita a dire cosa avrebbe fatto.
        $this->artisan('stagione:scarica', [
            'anno' => self::ANNATA, 'da' => 2, 'a' => 2, '--riserva' => 999,
        ])->expectsOutputToContain('Mi fermo alla 2ª')->assertSuccessful();
    }

    public function test_le_statistiche_scaricate_si_marcano_come_reali(): void
    {
        $this->annata();

        $this->assertTrue(app(MatchdaySimulator::class)->haStatisticheVere(self::ANNATA, 1));
        $this->assertFalse(app(MatchdaySimulator::class)->haStatisticheVere(self::ANNATA, 9));
    }
}
