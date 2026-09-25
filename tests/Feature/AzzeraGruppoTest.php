<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\PlayerStat;
use App\Services\Season\LeagueReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * I due azzeramenti grossi, quelli al livello del gruppo.
 *
 * Sono due e non uno perché tagliano cose di valore diverso. Le identità delle
 * squadre — nomi, maglie, stemmi — sono la sola parte del gruppo che nessuno ha
 * voglia di rifare a mano: chi ripulisce una prova vuole via le partite, non le
 * persone. Nasconderle dentro «azzera tutto» avrebbe voluto dire ricostruire
 * dodici maglie che nessuno aveva chiesto di buttare.
 */
class AzzeraGruppoTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $stagione;

    private Manager $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagione = $this->makeLeague('Gruppo', ['eventi' => ['gol' => ['default' => 7]]]);
        $this->admin = tap($this->makeManager($this->stagione, 'Ada'))->update(['is_admin' => true]);
    }

    /** Due stagioni giocate, con carte e statistiche finte addosso. */
    private function gruppoGiocato(): LeagueSeason
    {
        $seconda = $this->makeStagione($this->stagione, 2022);

        $this->makeRoster($this->admin, 'PDDDDCCCCAA', stagione: $this->stagione);
        $this->makeRoster($this->admin, 'PDDDDCCCCAA', stagione: $seconda);

        PlayerStat::create([
            'player_id' => $this->makePlayer('A')->id,
            'season' => self::ANNATA,
            'matchday' => 1,
            'minutes' => 90,
            'rating' => 7.0,
            'source' => 'simulata',
        ]);

        return $seconda;
    }

    private function reset(): LeagueReset
    {
        return app(LeagueReset::class);
    }

    // ───────────────────────── azzera tutto ─────────────────────────

    public function test_via_tutte_le_stagioni_del_gruppo(): void
    {
        $this->gruppoGiocato();

        $fatto = $this->reset()->tutto($this->stagione->league);

        $this->assertSame(2, $fatto['stagioni']);
        $this->assertSame(0, LeagueSeason::where('league_id', $this->stagione->league_id)->count());
        $this->assertSame(0, Card::count());
    }

    public function test_le_squadre_iscritte_sopravvivono(): void
    {
        // È tutto il senso della separazione: maglie, stemmi e nomi restano.
        $this->makeManager($this->stagione, 'Bruno');
        $this->gruppoGiocato();

        $this->reset()->tutto($this->stagione->league);

        $this->assertSame(2, Manager::where('league_id', $this->stagione->league_id)->count());
    }

    public function test_anche_le_regole_di_casa_tornano_di_fabbrica(): void
    {
        // «Tutto» vuol dire foglio bianco: una taratura sopravvissuta si
        // scoprirebbe tre giornate dopo, quando i conti non tornano.
        $this->stagione->league->update(['settings' => ['eventi' => ['gol' => ['default' => 9]]]]);

        $this->reset()->tutto($this->stagione->league);

        $this->assertNull($this->stagione->league->fresh()->settings);
    }

    public function test_le_statistiche_finte_se_ne_vanno(): void
    {
        // ⚠️ È il motivo per cui dopo un reset la pagina «Serie A» restava
        // ferma alla giornata dove ci si era arrivati: quell'elenco mostra le
        // giornate che hanno almeno una statistica.
        $this->gruppoGiocato();

        $fatto = $this->reset()->tutto($this->stagione->league);

        $this->assertSame(1, $fatto['statistiche_finte']);
        $this->assertSame(0, PlayerStat::where('source', 'simulata')->count());
    }

    public function test_le_statistiche_vere_non_si_toccano_mai(): void
    {
        // Sono dati del mondo: costano chiamate all'API e le usano gli altri
        // gruppi che rigiocano la stessa annata.
        PlayerStat::create([
            'player_id' => $this->makePlayer('A')->id,
            'season' => self::ANNATA,
            'matchday' => 1,
            'minutes' => 90,
            'rating' => 7.0,
            'source' => 'reale',
        ]);

        $this->reset()->tutto($this->stagione->league);

        $this->assertSame(1, PlayerStat::where('source', 'reale')->count());
    }

    public function test_le_finte_restano_se_un_altro_gruppo_gioca_quell_annata(): void
    {
        // ⚠️ `player_stats` porta l'annata, non la lega: svuotarle sotto i piedi
        // di chi è nel mezzo di una prova sullo stesso anno sarebbe un danno
        // fatto a qualcun altro.
        $altro = $this->makeLeague('Altro gruppo');

        PlayerStat::create([
            'player_id' => $this->makePlayer('A')->id,
            'season' => self::ANNATA,
            'matchday' => 1,
            'minutes' => 90,
            'rating' => 7.0,
            'source' => 'simulata',
        ]);

        $this->reset()->tutto($this->stagione->league);

        $this->assertSame(1, PlayerStat::where('source', 'simulata')->count());
        $this->assertSame(1, LeagueSeason::where('league_id', $altro->league_id)->count());
    }

    // ───────────────────────── togli gli iscritti ─────────────────────────

    public function test_via_le_squadre_tranne_chi_preme(): void
    {
        $this->makeManager($this->stagione, 'Bruno');
        $this->makeManager($this->stagione, 'Carla');

        $quante = $this->reset()->iscritti($this->stagione->league, tranne: $this->admin);

        $this->assertSame(2, $quante);
        $this->assertSame(
            [$this->admin->id],
            Manager::where('league_id', $this->stagione->league_id)->pluck('id')->all(),
        );
    }

    public function test_l_amministratore_non_si_cancella_da_solo(): void
    {
        // ⚠️ Senza di lui il gruppo non avrebbe più una porta d'ingresso: si
        // recupererebbe solo da riga di comando sul server.
        $this->reset()->iscritti($this->stagione->league, tranne: $this->admin);

        $this->assertNotNull($this->admin->fresh());
    }

    public function test_le_squadre_di_un_altro_gruppo_non_si_toccano(): void
    {
        $altro = $this->makeLeague('Altro gruppo');
        $this->makeManager($altro, 'Estraneo');

        $this->reset()->iscritti($this->stagione->league, tranne: $this->admin);

        $this->assertSame(1, Manager::where('league_id', $altro->league_id)->count());
    }

    // ───────────────────────── dalla pagina ─────────────────────────

    public function test_l_admin_azzera_il_gruppo_dalla_gestione(): void
    {
        $this->gruppoGiocato();

        $this->actingAs($this->admin)
            ->post(route('admin.gruppo.azzera'))
            ->assertRedirect()
            ->assertSessionHas('successo');

        $this->assertSame(0, LeagueSeason::where('league_id', $this->stagione->league_id)->count());
    }

    public function test_l_admin_toglie_gli_iscritti_dalla_gestione(): void
    {
        $bruno = $this->makeManager($this->stagione, 'Bruno');

        $this->actingAs($this->admin)
            ->delete(route('admin.gruppo.iscritti'))
            ->assertRedirect()
            ->assertSessionHas('successo');

        $this->assertNull($bruno->fresh());
        $this->assertNotNull($this->admin->fresh());
    }

    public function test_chi_non_e_admin_non_azzera_niente(): void
    {
        $bruno = $this->makeManager($this->stagione, 'Bruno');
        $this->gruppoGiocato();

        $this->actingAs($bruno)->post(route('admin.gruppo.azzera'))->assertForbidden();
        $this->actingAs($bruno)->delete(route('admin.gruppo.iscritti'))->assertForbidden();

        $this->assertSame(2, LeagueSeason::where('league_id', $this->stagione->league_id)->count());
        $this->assertNotNull($bruno->fresh());
    }
}
