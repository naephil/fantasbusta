<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Models\Team;
use App\Services\Stats\LiveMatchday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * La giornata di Serie A vista da dentro il fantacalcio.
 */
class MatchdayLiveTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $stagione;

    private Manager $marco;

    private Manager $giulia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagione = $this->makeLeague();
        $this->marco = $this->makeManager($this->stagione, 'Marco');
        $this->giulia = $this->makeManager($this->stagione, 'Giulia');

        $this->makeFixture(7, '2026-09-04 20:45:00');
    }

    private function inCampo(string $ruolo, int $teamId, ?float $voto, int $minuti = 90, int $gol = 0): Player
    {
        $player = $this->makePlayer($ruolo);
        PlayerSeason::where('player_id', $player->id)->update(['team_id' => $teamId]);

        PlayerStat::create([
            'player_id' => $player->id,
            'season' => 2026,
            'matchday' => 7,
            'minutes' => $minuti,
            'rating' => $voto,
            'goals' => $gol,
        ]);

        PlayerScore::create([
            'league_season_id' => $this->stagione->id,
            'player_id' => $player->id,
            'matchday' => 7,
            'voto_base' => $voto,
            'fantavoto' => $voto,
        ]);

        return $player;
    }

    private function assegna(Player $player, Manager $manager, ?LeagueSeason $stagione = null): Card
    {
        return Card::create([
            'league_season_id' => ($stagione ?? $this->stagione)->id,
            'matchday' => 7,
            'player_id' => $player->id,
            'tier' => 'comune',
            'role' => $player->inSeason(self::ANNATA)->role->value,
            'owner_manager_id' => $manager->id,
            'original_owner_id' => $manager->id,
        ]);
    }

    // ───────────────────────── la pagina ─────────────────────────

    public function test_la_pagina_e_riservata(): void
    {
        $this->get(route('matchday.show'))->assertRedirect(route('login'));
    }

    public function test_senza_statistiche_la_pagina_lo_dice(): void
    {
        $this->actingAs($this->marco)
            ->get(route('matchday.show'))
            ->assertOk()
            ->assertSee('Nessuna statistica per questa giornata');
    }

    public function test_la_partita_mostra_il_tabellone_e_i_giocatori(): void
    {
        $bomber = $this->inCampo('A', 500, 7.8, gol: 1);

        $this->actingAs($this->marco)
            ->get(route('matchday.show'))
            ->assertOk()
            ->assertSee('Squadra di prova')
            ->assertSee($bomber->last_name);
    }

    // ───────────────────────── l'appartenenza fanta ─────────────────────────

    public function test_accanto_al_giocatore_c_e_di_chi_e_la_carta(): void
    {
        // È l'unica schermata che collega le due metà del gioco: senza, un gol
        // resta un gol invece di essere «tre punti a Giulia».
        $suo = $this->inCampo('A', 500, 7.5);
        $this->assegna($suo, $this->giulia);

        $this->actingAs($this->marco)
            ->get(route('matchday.show'))
            ->assertOk()
            ->assertSee('Giulia');
    }

    public function test_le_proprie_carte_sono_marcate_a_parte(): void
    {
        $mio = $this->inCampo('C', 500, 7.0);
        $this->assegna($mio, $this->marco);

        $esito = (new LiveMatchday)->forMatchday($this->stagione, 7, $this->marco->id);
        $riga = $esito->first()['casa']->firstWhere('nome', $mio->last_name);

        $this->assertTrue($riga['mio']);
        $this->assertSame('Marco', $riga['proprietario']);
    }

    public function test_chi_non_e_di_nessuno_resta_senza_proprietario(): void
    {
        $libero = $this->inCampo('D', 500, 6.5);

        $esito = (new LiveMatchday)->forMatchday($this->stagione, 7, $this->marco->id);
        $riga = $esito->first()['casa']->firstWhere('nome', $libero->last_name);

        $this->assertNull($riga['proprietario']);
        $this->assertFalse($riga['mio']);
    }

    public function test_le_carte_di_un_altra_lega_non_si_vedono(): void
    {
        $altra = $this->makeLeague('Altra lega');
        $estraneo = $this->makeManager($altra, 'Estraneo');
        $player = $this->inCampo('A', 500, 7.0);
        $this->assegna($player, $estraneo, $altra);

        $esito = (new LiveMatchday)->forMatchday($this->stagione, 7, $this->marco->id);

        $this->assertNull($esito->first()['casa']->firstWhere('nome', $player->last_name)['proprietario']);
    }

    // ───────────────────────── l'ordine ─────────────────────────

    public function test_chi_ha_giocato_sta_sopra_a_chi_e_rimasto_fuori(): void
    {
        $panchinaro = $this->inCampo('A', 500, null, minuti: 0);
        $titolare = $this->inCampo('C', 500, 6.0);

        $casa = (new LiveMatchday)->forMatchday($this->stagione, 7, $this->marco->id)->first()['casa'];

        $this->assertSame($titolare->last_name, $casa->first()['nome']);
        $this->assertSame($panchinaro->last_name, $casa->last()['nome']);
    }

    public function test_le_due_squadre_restano_separate(): void
    {
        Team::firstOrCreate(['id' => 501], ['name' => 'Altra squadra']);

        $diCasa = $this->inCampo('A', 500, 7.0);
        $inTrasferta = $this->inCampo('A', 501, 7.0);

        $partita = (new LiveMatchday)->forMatchday($this->stagione, 7, $this->marco->id)->first();

        $this->assertSame([$diCasa->last_name], $partita['casa']->pluck('nome')->all());
        $this->assertSame([$inTrasferta->last_name], $partita['fuori']->pluck('nome')->all());
    }

    public function test_si_puo_scegliere_quale_giornata_guardare(): void
    {
        $this->makeFixture(8, '2026-09-11 20:45:00');
        $this->inCampo('A', 500, 7.0);

        $tardi = $this->makePlayer('A');
        PlayerSeason::where('player_id', $tardi->id)->update(['team_id' => 500]);
        PlayerStat::create(['player_id' => $tardi->id, 'season' => 2026, 'matchday' => 8, 'minutes' => 90, 'rating' => 8.0]);

        $this->actingAs($this->marco)
            ->get(route('matchday.show', ['giornata' => 8]))
            ->assertOk()
            ->assertSee($tardi->last_name);
    }
}
