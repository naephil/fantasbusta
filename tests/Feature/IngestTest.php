<?php

namespace Tests\Feature;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Models\Team;
use App\Services\Ingest\ApiFootball;
use App\Services\Ingest\ReferenceSync;
use App\Services\Ingest\StatSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Ingest da API-Football, con le risposte finte.
 *
 * ⚠️ Le forme delle risposte qui riprodotte vengono dalla documentazione di
 * API-Football v3, non da una chiamata reale: vanno confrontate con la vera
 * API prima dell'avvio stagione. Questi test dimostrano che il parsing e le
 * invarianti reggono, non che i campi si chiamino davvero così.
 */
class IngestTest extends TestCase
{
    use RefreshDatabase;

    /** L'annata di prova: da quando i dati convivono, va detta sempre. */
    private const ANNATA = 2026;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('apifootball.key', 'chiave-di-prova');
        config()->set('apifootball.league', 135);
        config()->set('apifootball.season', 2026);
    }

    private function sync(): ReferenceSync
    {
        return new ReferenceSync(new ApiFootball);
    }

    /** La riga di listone dell'annata di prova: è lì che vivono ruolo e squadra. */
    private function listoneDi(int $playerId): PlayerSeason
    {
        return PlayerSeason::where('player_id', $playerId)
            ->where('season', self::ANNATA)
            ->firstOrFail();
    }

    /** L'involucro standard di API-Football. */
    private function envelope(array $response, int $current = 1, int $total = 1): array
    {
        return [
            'errors' => [],
            'results' => count($response),
            'paging' => ['current' => $current, 'total' => $total],
            'response' => $response,
        ];
    }

    private function team(int $id, string $nome): array
    {
        return ['team' => ['id' => $id, 'name' => $nome, 'code' => strtoupper(substr($nome, 0, 3))]];
    }

    /** La forma di /players/squads: una squadra con dentro la sua rosa. */
    /**
     * La forma di `/players?team=&season=`.
     *
     * Nome e cognome arrivano gia' separati — a differenza di `/players/squads`,
     * che li dava in un campo solo — e la posizione sta dentro le statistiche.
     */
    private function rosa(int $teamId, array $giocatori): array
    {
        return $giocatori;
    }

    private function inRosa(int $id, string $nome, string $position): array
    {
        [$primo, $cognome] = str_contains($nome, ' ')
            ? [explode(' ', $nome, 2)[0], explode(' ', $nome, 2)[1]]
            : ['', $nome];

        return [
            'player' => [
                'id' => $id,
                'firstname' => $primo,
                'lastname' => $cognome,
                'photo' => "https://x/{$id}.png",
            ],
            'statistics' => [[
                'team' => ['id' => $teamId ?? 0],
                'games' => ['position' => $position, 'appearences' => 1, 'rating' => '6.5'],
                'goals' => ['total' => 0, 'assists' => 0],
            ]],
        ];
    }

    // ───────────────────────── l'involucro ─────────────────────────

    public function test_un_errore_con_stato_duecento_e_comunque_un_errore(): void
    {
        // Il tranello dell'API: l'errore arriva con HTTP 200 e il messaggio
        // dentro `errors`. Fidarsi dello stato significherebbe scrivere zero
        // squadre credendo che il campionato non ne abbia.
        Http::fake([
            '*' => Http::response([
                'errors' => ['token' => 'Chiave non valida'],
                'response' => [],
            ]),
        ]);

        $this->expectExceptionMessage('Chiave non valida');

        $this->sync()->teams(self::ANNATA);
    }

    public function test_senza_chiave_non_si_parte(): void
    {
        config()->set('apifootball.key', null);

        $this->expectExceptionMessage('API_FOOTBALL_KEY');

        $this->sync()->teams(self::ANNATA);
    }

    public function test_le_rose_si_scaricano_squadra_per_squadra(): void
    {
        // ⚠️ Si passa da `/players?team=&season=` e non da `/players/squads`:
        // il secondo e' piu' economico ma NON accetta la stagione, quindi per
        // un'annata passata darebbe la rosa di oggi. Il replay si giocherebbe
        // con gente che allora non c'era.
        Http::fake([
            '*/teams*' => Http::response($this->envelope([$this->team(1, 'Inter'), $this->team(2, 'Milan')])),
            '*/players?*' => Http::sequence()
                ->push($this->envelope($this->rosa(1, [
                    $this->inRosa(10, 'M. Rossi', 'Defender'),
                    $this->inRosa(11, 'L. Bianchi', 'Midfielder'),
                ])))
                ->push($this->envelope($this->rosa(2, [
                    $this->inRosa(20, 'G. Verdi', 'Attacker'),
                ]))),
        ]);

        $this->sync()->teams(self::ANNATA);
        $esito = $this->sync()->players(self::ANNATA);

        $this->assertSame(3, $esito['sincronizzati']);
        $this->assertSame(3, Player::count());
        $this->assertSame(2, PlayerSeason::where('team_id', 1)->count());
    }

    public function test_il_nome_abbreviato_si_spezza_tenendo_il_cognome_intero(): void
    {
        // Il cognome e' cio' su cui gira l'abbinamento col listone: l'endpoint
        // lo da' gia' separato dal nome, e va preso tale e quale invece di
        // ricavarlo tagliando al primo spazio.
        Http::fake([
            '*/teams*' => Http::response($this->envelope([$this->team(1, 'Inter')])),
            '*/players?*' => Http::response($this->envelope($this->rosa(1, [
                $this->inRosa(10, 'R. Di Gennaro', 'Goalkeeper'),
                $this->inRosa(11, 'Ederson', 'Midfielder'),
            ]))),
        ]);

        $this->sync()->teams(self::ANNATA);
        $this->sync()->players(self::ANNATA);

        $this->assertSame('Di Gennaro', Player::find(10)->last_name);
        $this->assertSame('R.', Player::find(10)->first_name);
        $this->assertSame('Ederson', Player::find(11)->last_name);
    }

    // ───────────────────────── anagrafiche ─────────────────────────

    public function test_le_squadre_si_aggiornano_in_loco(): void
    {
        Http::fake(['*/teams*' => Http::response($this->envelope([
            $this->team(1, 'Inter'),
            $this->team(2, 'Milan'),
        ]))]);

        $this->sync()->teams(self::ANNATA);
        $this->sync()->teams(self::ANNATA);

        $this->assertSame(2, Team::count());
        $this->assertSame('Inter', Team::find(1)->name);
    }

    public function test_la_posizione_inglese_diventa_un_ruolo_solo_ipotizzato(): void
    {
        Http::fake([
            '*/teams*' => Http::response($this->envelope([$this->team(1, 'Inter')])),
            '*/players?*' => Http::response($this->envelope($this->rosa(1, [
                $this->inRosa(10, 'M. Rossi', 'Goalkeeper'),
                $this->inRosa(11, 'L. Bianchi', 'Midfielder'),
            ]))),
        ]);

        $this->sync()->teams(self::ANNATA);
        $this->sync()->players(self::ANNATA);

        $this->assertSame('P', $this->listoneDi(10)->role->value);
        $this->assertSame('C', $this->listoneDi(11)->role->value);

        // Nessuno dei due è confermato: la posizione API non è il ruolo.
        $this->assertFalse($this->listoneDi(10)->role_confirmed);
        $this->assertFalse($this->listoneDi(11)->role_confirmed);
    }

    public function test_una_sincronizzazione_non_sovrascrive_il_ruolo_del_listone(): void
    {
        // È l'invariante che tiene in piedi tutto: un sync di routine non deve
        // poter riclassificare mezzo campionato perché l'API chiama Midfielder
        // un esterno che il listone considera difensore.
        Http::fake([
            '*/teams*' => Http::response($this->envelope([$this->team(1, 'Inter')])),
            '*/players?*' => Http::response($this->envelope($this->rosa(1, [
                $this->inRosa(10, 'F. Dimarco', 'Midfielder'),
            ]))),
        ]);

        $this->sync()->teams(self::ANNATA);
        $this->sync()->players(self::ANNATA);

        $this->listoneDi(10)->update(['role' => 'D', 'role_confirmed' => true, 'quotazione_iniziale' => 18.5]);
        $squadraDelListone = $this->listoneDi(10)->team_id;

        $this->sync()->players(self::ANNATA);

        $this->assertSame('D', $this->listoneDi(10)->role->value);
        $this->assertSame(18.5, $this->listoneDi(10)->quotazione_iniziale);

        // ⚠️ E nemmeno la SQUADRA. L'API dà la rosa in cui il giocatore è
        // registrato, che a stagione iniziata può non essere quella in cui
        // gioca: nel 2023/24 Lukaku risultava all'Inter mentre il listone lo
        // dava alla Roma. Senza questa guardia, ogni sync di routine se lo
        // riportava indietro e nella giornata compariva sotto la partita
        // sbagliata, perché il raggruppamento passa da `team_id`.
        $this->assertSame($squadraDelListone, $this->listoneDi(10)->team_id);
    }

    public function test_chi_lascia_la_serie_a_si_disattiva_ma_non_sparisce(): void
    {
        // Cancellarlo romperebbe le carte, i voti e le statistiche che lo
        // referenziano: una rosa di ottobre deve restare leggibile a maggio.
        Http::fake([
            '*/teams*' => Http::response($this->envelope([$this->team(1, 'Inter')])),
            '*/players?*' => Http::sequence()
                ->push($this->envelope($this->rosa(1, [
                    $this->inRosa(10, 'M. Rossi', 'Defender'),
                    $this->inRosa(11, 'P. Partente', 'Attacker'),
                ])))
                ->push($this->envelope($this->rosa(1, [
                    $this->inRosa(10, 'M. Rossi', 'Defender'),
                ]))),
        ]);

        $this->sync()->teams(self::ANNATA);
        $this->sync()->players(self::ANNATA);

        $this->travel(1)->second();
        $esito = $this->sync()->players(self::ANNATA);

        $this->assertSame(1, $esito['disattivati']);
        $this->assertNotNull(Player::find(11));
        $this->assertFalse($this->listoneDi(11)->active);
        $this->assertTrue($this->listoneDi(10)->active);
    }

    public function test_un_acquisto_di_gennaio_entra_da_solo(): void
    {
        Http::fake([
            '*/teams*' => Http::response($this->envelope([$this->team(1, 'Inter')])),
            '*/players?*' => Http::sequence()
                ->push($this->envelope($this->rosa(1, [$this->inRosa(10, 'M. Rossi', 'Defender')])))
                ->push($this->envelope($this->rosa(1, [
                    $this->inRosa(10, 'M. Rossi', 'Defender'),
                    $this->inRosa(99, 'N. Nuovo', 'Attacker'),
                ]))),
        ]);

        $this->sync()->teams(self::ANNATA);
        $this->sync()->players(self::ANNATA);
        $esito = $this->sync()->players(self::ANNATA);

        $this->assertSame(1, $esito['nuovi']);
        $this->assertSame('A', $this->listoneDi(99)->role->value);
        $this->assertFalse($this->listoneDi(99)->role_confirmed);   // da rivedere a mano
    }

    // ───────────────────────── calendario ─────────────────────────

    public function test_il_turno_diventa_giornata_e_le_coppe_restano_fuori(): void
    {
        Http::fake(['*/fixtures*' => Http::response($this->envelope([
            [
                'fixture' => ['id' => 500, 'date' => '2026-09-04T20:45:00+02:00', 'status' => ['short' => 'FT']],
                'league' => ['round' => 'Regular Season - 3'],
                'teams' => ['home' => ['id' => 1], 'away' => ['id' => 2]],
                'goals' => ['home' => 2, 'away' => 1],
            ],
            [
                'fixture' => ['id' => 501, 'date' => '2026-09-07T20:45:00+02:00', 'status' => ['short' => 'NS']],
                'league' => ['round' => 'Coppa Italia - 8'],
                'teams' => ['home' => ['id' => 1], 'away' => ['id' => 2]],
                'goals' => ['home' => null, 'away' => null],
            ],
        ]))]);

        Team::create(['id' => 1, 'name' => 'Inter']);
        Team::create(['id' => 2, 'name' => 'Milan']);

        $this->assertSame(1, $this->sync()->fixtures(self::ANNATA));

        $this->assertSame(3, Fixture::find(500)->matchday);
        $this->assertSame('finished', Fixture::find(500)->status);
        $this->assertNull(Fixture::find(501));
    }

    // ───────────────────────── statistiche ─────────────────────────

    /**
     * La risposta di `/fixtures?id=`, che porta voti ed eventi nello stesso
     * involucro. Costa una chiamata invece di due, ed e' il motivo per cui
     * `/fixtures/players` e `/fixtures/events` non si usano piu'.
     *
     * @param  list<array<string,mixed>>  $squadre
     * @param  list<array<string,mixed>>  $eventi
     */
    private function partita(array $squadre, array $eventi = []): array
    {
        return $this->envelope([[
            'fixture' => ['id' => 500],
            'players' => $squadre,
            'events' => $eventi,
        ]]);
    }

    private function seedFixture(string $status = 'finished'): void
    {
        Team::create(['id' => 1, 'name' => 'Inter']);
        Team::create(['id' => 2, 'name' => 'Milan']);
        Player::create(['id' => 10, 'last_name' => 'Rossi']);

        PlayerSeason::create([
            'player_id' => 10,
            'season' => self::ANNATA,
            'team_id' => 1,
            'role' => 'A',
        ]);

        Fixture::create([
            'id' => 500,
            'season' => self::ANNATA,
            'matchday' => 3,
            'home_team_id' => 1,
            'away_team_id' => 2,
            'kickoff_at' => '2026-09-04 20:45:00',
            'status' => $status,
        ]);
    }

    public function test_le_statistiche_arrivano_complete_di_autoreti(): void
    {
        // Le autoreti stanno solo negli eventi: `goals.total` conta le reti
        // fatte, non quelle regalate. Senza guardarli, un −2 sparirebbe dal
        // fantavoto senza lasciare traccia. Confermato su Grassi in
        // Roma-Empoli 2023/24: `goals.total` a null, autorete solo fra gli eventi.
        $this->seedFixture();

        Http::fake(['*/fixtures*' => Http::response($this->partita(
            [[
                'team' => ['id' => 1],
                'players' => [[
                    'player' => ['id' => 10],
                    'statistics' => [[
                        'games' => ['minutes' => 90, 'rating' => '7.4'],
                        'goals' => ['total' => 2, 'assists' => 1, 'conceded' => 0],
                        'cards' => ['yellow' => 1, 'red' => 0],
                        'penalty' => ['scored' => 1, 'missed' => 0, 'saved' => 0],
                    ]],
                ]],
            ]],
            [
                ['type' => 'Goal', 'detail' => 'Own Goal', 'player' => ['id' => 10]],
                ['type' => 'Card', 'detail' => 'Yellow Card', 'player' => ['id' => 10]],
            ],
        ))]);

        (new StatSync(new ApiFootball))->matchday(self::ANNATA, 3);

        $stat = PlayerStat::where('player_id', 10)->where('matchday', 3)->firstOrFail();

        $this->assertSame(90, $stat->minutes);
        $this->assertSame(7.4, $stat->rating);
        $this->assertSame(2, $stat->goals);
        $this->assertSame(1, $stat->pen_scored);
        $this->assertSame(1, $stat->own_goals);
        $this->assertSame(1, $stat->yellow);
    }

    public function test_il_rating_assente_resta_nullo(): void
    {
        // Null significa senza voto, ed è distinto da zero.
        $this->seedFixture();

        Http::fake(['*/fixtures*' => Http::response($this->partita([[
            'team' => ['id' => 1],
            'players' => [[
                'player' => ['id' => 10],
                'statistics' => [['games' => ['minutes' => 12, 'rating' => null]]],
            ]],
        ]]))]);

        (new StatSync(new ApiFootball))->matchday(self::ANNATA, 3);

        $this->assertNull(PlayerStat::where('player_id', 10)->firstOrFail()->rating);
    }

    public function test_le_partite_non_finite_si_saltano(): void
    {
        $this->seedFixture(status: 'live');

        Http::fake(['*' => Http::response($this->envelope([]))]);

        $esito = (new StatSync(new ApiFootball))->matchday(self::ANNATA, 3);

        $this->assertSame(0, $esito['partite']);
        $this->assertSame(1, $esito['saltate']);
        $this->assertSame(0, PlayerStat::count());
        Http::assertNothingSent();
    }

    public function test_chi_ha_giocato_entra_in_anagrafica_anche_se_mancava(): void
    {
        // ⚠️ Il piano gratuito tronca le rose alla terza pagina, quindi
        // l'anagrafica è strutturalmente incompleta: le squadre lunghe
        // perdono la coda. Scartare in silenzio chi non c'è costava tre gol
        // su ventisei nella 1ª giornata 2023/24 — tre bonus mai assegnati, e
        // nessuna schermata che lo dicesse.
        //
        // Aggiungerlo non costa nessuna chiamata: nome, squadra e posizione
        // sono già dentro il tabellino che si sta leggendo.
        $this->seedFixture();

        Http::fake(['*/fixtures*' => Http::response($this->partita([[
            'team' => ['id' => 2],
            'players' => [[
                'player' => ['id' => 777, 'name' => 'Sconosciuto'],
                'statistics' => [['games' => ['minutes' => 90, 'rating' => '7.0', 'position' => 'F'], 'goals' => ['total' => 1]]],
            ]],
        ]]))]);

        $esito = (new StatSync(new ApiFootball))->matchday(self::ANNATA, 3);

        $this->assertSame(1, $esito['aggiunti']);
        $this->assertSame(1, $esito['giocatori']);

        $this->assertSame('Sconosciuto', Player::find(777)->last_name);

        $riga = PlayerSeason::where('player_id', 777)->where('season', self::ANNATA)->firstOrFail();

        $this->assertSame('A', $riga->role->value);          // 'F' del tabellino
        $this->assertSame(2, $riga->team_id);
        $this->assertFalse($riga->role_confirmed);           // resta un'ipotesi

        // E il gol non si perde per strada: era il punto.
        $this->assertSame(1, PlayerStat::where('player_id', 777)->firstOrFail()->goals);
    }

    public function test_un_giocatore_gia_noto_non_viene_riaggiunto(): void
    {
        $this->seedFixture();

        Http::fake(['*/fixtures*' => Http::response($this->partita([[
            'team' => ['id' => 1],
            'players' => [[
                'player' => ['id' => 10],
                'statistics' => [['games' => ['minutes' => 90, 'rating' => '6.5']]],
            ]],
        ]]))]);

        $this->assertSame(0, (new StatSync(new ApiFootball))->matchday(self::ANNATA, 3)['aggiunti']);
    }

    public function test_una_riga_senza_id_giocatore_si_scarta(): void
    {
        $this->seedFixture();

        Http::fake(['*/fixtures*' => Http::response($this->partita([[
            'team' => ['id' => 1],
            'players' => [[
                'player' => ['id' => null],
                'statistics' => [['games' => ['minutes' => 90, 'rating' => '6.5']]],
            ]],
        ]]))]);

        $esito = (new StatSync(new ApiFootball))->matchday(self::ANNATA, 3);

        $this->assertSame(0, $esito['giocatori']);
        $this->assertSame(0, $esito['aggiunti']);
    }

    public function test_il_riscarico_aggiorna_invece_di_duplicare(): void
    {
        $this->seedFixture();

        Http::fake(['*/fixtures*' => Http::response($this->partita([[
            'team' => ['id' => 1],
            'players' => [[
                'player' => ['id' => 10],
                'statistics' => [['games' => ['minutes' => 90, 'rating' => '6.0']]],
            ]],
        ]]))]);

        $sync = new StatSync(new ApiFootball);
        $sync->matchday(self::ANNATA, 3);
        $sync->matchday(self::ANNATA, 3);

        $this->assertSame(1, PlayerStat::where('player_id', 10)->count());
    }

    public function test_una_chiamata_fallita_non_passa_inosservata(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->expectException(RuntimeException::class);

        $this->sync()->teams(self::ANNATA);
    }
}
