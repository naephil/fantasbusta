<?php

namespace Tests\Feature;

use App\Models\PlayerSeason;
use App\Models\Team;
use App\Services\Ingest\ApiFootball;
use App\Services\Ingest\ListoneStimato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Quotazioni dedotte dalle statistiche, quando il listone vero non c'è.
 *
 * Serve a togliere il sorteggio dalla prima giornata: senza quotazioni nessuno
 * ha ancora giocato, il power è identico per tutti e la rarità la decide lo
 * spareggio sull'id.
 */
class ListoneStimatoTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('apifootball.key', 'chiave-di-prova');
        config()->set('apifootball.league', 135);
    }

    private function stima(): ListoneStimato
    {
        return new ListoneStimato(new ApiFootball);
    }

    /** Una riga di listone non confermata, come la lascia il sync. */
    private function daQuotare(string $ruolo, int $teamId = 500): PlayerSeason
    {
        Team::firstOrCreate(['id' => $teamId], ['name' => "Squadra {$teamId}"]);

        $player = $this->makePlayer($ruolo, quotazione: 1.0, teamId: $teamId);

        return tap(PlayerSeason::where('player_id', $player->id)->firstOrFail())
            ->update(['role_confirmed' => false]);
    }

    /** @param  list<array<string,mixed>>  $giocatori */
    private function rispondi(array $giocatori): void
    {
        Http::fake(['*/players*' => Http::response([
            'errors' => [],
            'results' => count($giocatori),
            'paging' => ['current' => 1, 'total' => 1],
            'response' => array_map(fn (array $g) => [
                'player' => ['id' => $g['id']],
                'statistics' => [[
                    'games' => ['appearences' => $g['presenze'], 'rating' => $g['rating']],
                    'goals' => ['total' => $g['gol'] ?? 0, 'assists' => $g['assist'] ?? 0],
                ]],
            ], $giocatori),
        ])]);
    }

    // ───────────────────────── la deduzione ─────────────────────────

    public function test_chi_ha_reso_di_piu_vale_di_piu(): void
    {
        $forte = $this->daQuotare('A');
        $scarso = $this->daQuotare('A');

        $this->rispondi([
            ['id' => $forte->player_id, 'presenze' => 35, 'rating' => '7.5', 'gol' => 20, 'assist' => 5],
            ['id' => $scarso->player_id, 'presenze' => 30, 'rating' => '6.0', 'gol' => 1],
        ]);

        $this->stima()->stima(self::ANNATA, self::ANNATA - 1);

        $this->assertGreaterThan(
            $scarso->fresh()->quotazione_iniziale,
            $forte->fresh()->quotazione_iniziale,
        );
    }

    public function test_ogni_ruolo_ha_la_sua_fascia_di_prezzo(): void
    {
        // Le fasce ricalcano il listone vero: un portiere non costa come un
        // attaccante, e appiattirle renderebbe il draft cieco ai reparti —
        // che è metà del gioco, visto che la busta ha una garanzia di
        // composizione.
        $portiere = $this->daQuotare('P');
        $riserva = $this->daQuotare('P');
        $attaccante = $this->daQuotare('A');
        $panchinaro = $this->daQuotare('A');

        $this->rispondi([
            ['id' => $portiere->player_id, 'presenze' => 38, 'rating' => '7.0'],
            ['id' => $riserva->player_id, 'presenze' => 2, 'rating' => '5.6'],
            ['id' => $attaccante->player_id, 'presenze' => 38, 'rating' => '7.0', 'gol' => 25],
            ['id' => $panchinaro->player_id, 'presenze' => 2, 'rating' => '5.6'],
        ]);

        $this->stima()->stima(self::ANNATA, self::ANNATA - 1);

        // Il primo del ruolo prende il tetto della sua fascia, l'ultimo il
        // pavimento: la distribuzione è piena dentro ogni reparto.
        $this->assertSame(16.0, $portiere->fresh()->quotazione_iniziale);
        $this->assertSame(45.0, $attaccante->fresh()->quotazione_iniziale);
        $this->assertSame(1.0, $riserva->fresh()->quotazione_iniziale);
        $this->assertSame(1.0, $panchinaro->fresh()->quotazione_iniziale);
    }

    public function test_chi_non_ha_mai_giocato_resta_al_minimo(): void
    {
        // È esattamente quanto vale nel listone vero: uno che non scende in
        // campo non si compra.
        $titolare = $this->daQuotare('C');
        $mairvisto = $this->daQuotare('C');

        $this->rispondi([
            ['id' => $titolare->player_id, 'presenze' => 30, 'rating' => '7.0', 'gol' => 5],
            ['id' => $mairvisto->player_id, 'presenze' => 0, 'rating' => null],
        ]);

        $this->stima()->stima(self::ANNATA, self::ANNATA - 1);

        $this->assertSame(1.0, $mairvisto->fresh()->quotazione_iniziale);
    }

    public function test_la_disponibilita_moltiplica_invece_di_sommarsi(): void
    {
        // Un fuoriclasse che ha giocato quattro partite non vale quasi niente
        // in un gioco dove la rosa si schiera ogni settimana.
        $fisso = $this->daQuotare('C');
        $intermittente = $this->daQuotare('C');

        $this->rispondi([
            ['id' => $fisso->player_id, 'presenze' => 36, 'rating' => '6.9', 'gol' => 4],
            ['id' => $intermittente->player_id, 'presenze' => 4, 'rating' => '7.6', 'gol' => 4],
        ]);

        $this->stima()->stima(self::ANNATA, self::ANNATA - 1);

        $this->assertGreaterThan(
            $intermittente->fresh()->quotazione_iniziale,
            $fisso->fresh()->quotazione_iniziale,
        );
    }

    public function test_una_quotazione_decisa_da_un_umano_non_si_tocca(): void
    {
        // Il listone vero e la pagina di verifica sono decisioni umane: una
        // stima non ha titolo per sovrascriverle.
        $deciso = $this->daQuotare('A');
        $deciso->update(['role_confirmed' => true, 'quotazione_iniziale' => 33.0]);

        $this->rispondi([
            ['id' => $deciso->player_id, 'presenze' => 38, 'rating' => '8.0', 'gol' => 30],
        ]);

        $this->stima()->stima(self::ANNATA, self::ANNATA - 1);

        $this->assertSame(33.0, $deciso->fresh()->quotazione_iniziale);
    }

    public function test_il_conteggio_distingue_chi_ha_dati_da_chi_no(): void
    {
        $conDati = $this->daQuotare('D');
        $this->daQuotare('D');

        $this->rispondi([
            ['id' => $conDati->player_id, 'presenze' => 30, 'rating' => '6.8'],
        ]);

        $esito = $this->stima()->stima(self::ANNATA, self::ANNATA - 1);

        $this->assertSame(1, $esito['quotati']);
        $this->assertSame(1, $esito['senzaDati']);
    }

    public function test_senza_listone_da_quotare_non_si_chiama_l_api(): void
    {
        Http::fake();

        $esito = $this->stima()->stima(self::ANNATA, self::ANNATA - 1);

        $this->assertSame(0, $esito['chiamate']);
        Http::assertNothingSent();
    }

    public function test_non_si_chiede_mai_la_quarta_pagina(): void
    {
        // ⚠️ Il piano gratuito blocca `/players` oltre la terza pagina, e la
        // quarta non torna vuota: torna un errore che ferma tutto.
        $this->daQuotare('A');

        Http::fake(['*/players*' => Http::response([
            'errors' => [],
            'results' => 0,
            'paging' => ['current' => 1, 'total' => 54],
            'response' => [],
        ])]);

        $this->stima()->stima(self::ANNATA, self::ANNATA - 1);

        Http::assertSentCount(3);
    }

    public function test_la_pagina_deduce_le_quotazioni(): void
    {
        $stagione = $this->makeLeague();
        $admin = tap($this->makeManager($stagione, 'Admin'))->update(['is_admin' => true]);

        $bomber = $this->daQuotare('A');
        $this->daQuotare('A');

        $this->rispondi([
            ['id' => $bomber->player_id, 'presenze' => 35, 'rating' => '7.6', 'gol' => 22],
        ]);

        $this->actingAs($admin)
            ->post(route('admin.annata.stima'), ['anno' => self::ANNATA, 'dati' => self::ANNATA - 1])
            ->assertRedirect()
            ->assertSessionHas('successo');

        $this->assertSame(45.0, $bomber->fresh()->quotazione_iniziale);
    }
}
