<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftTurn;
use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\LineupSlot;
use App\Models\Manager;
use App\Models\Matchup;
use App\Models\PlayerPower;
use App\Models\PlayerScore;
use App\Models\PlayerStat;
use App\Models\Standing;
use App\Models\Tournament;
use App\Models\TournamentEntry;
use App\Services\Season\SeasonReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Ricominciare da capo senza buttare via la stagione.
 *
 * «Azzera» e «cancella» non sono la stessa cosa, ed è tutta qui la ragione per
 * cui esiste: cancellare porta via anche ciò che si era tarato prima di
 * cominciare — regole di casa, giornata di partenza — e una prova andata storta
 * costringerebbe a reimpostare tutto a mano. Qui resta l'impalcatura e se ne va
 * soltanto il giocato.
 *
 * ⚠️ Il confine è quello di sempre: le statistiche di Serie A sono dati del
 * mondo, condivisi con gli altri gruppi che rigiocano la stessa annata, e non
 * sono nostre da buttare. Se ne vanno solo le loro interpretazioni — fantavoti
 * e power score — che appartengono alla stagione di lega.
 */
class AzzeraStagioneTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $stagione;

    private Manager $ada;

    private Manager $bruno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagione = $this->makeLeague('Gruppo', ['eventi' => ['gol' => ['default' => 7]]]);
        $this->stagione->update(['start_matchday' => 4]);

        $this->ada = $this->makeManager($this->stagione, 'Ada');
        $this->bruno = $this->makeManager($this->stagione, 'Bruno');
    }

    /** Una stagione con addosso tutto ciò che si può giocare. */
    private function stagioneGiocata(): void
    {
        $rosa = $this->makeRoster($this->ada, 'PDDDDCCCCAA');
        $this->makeLineup($this->ada, $rosa);
        $this->scoreAll($rosa, 6.5);
        $this->makePower($rosa->first(), 80.0);

        Matchup::create([
            'league_season_id' => $this->stagione->id,
            'matchday' => 1,
            'round' => 1,
            'home_manager_id' => $this->ada->id,
            'away_manager_id' => $this->bruno->id,
            'state' => 'played',
        ]);

        Standing::create([
            'league_season_id' => $this->stagione->id,
            'manager_id' => $this->ada->id,
            'matchday' => 1,
            'punti' => 3,
            'fantapunti' => 74.5,
        ]);

        $draft = Draft::create([
            'league_season_id' => $this->stagione->id,
            'matchday' => 2,
            'state' => 'closed',
            'opens_at' => now()->subDay(),
            'deadline_at' => now(),
            'rounds' => 1,
            'pack_size' => 5,
        ]);

        DraftTurn::create([
            'draft_id' => $draft->id,
            'manager_id' => $this->ada->id,
            'round' => 1,
            'pick_index' => 1,
            'state' => 'done',
        ]);

        $torneo = Tournament::create([
            'league_season_id' => $this->stagione->id,
            'name' => 'Coppa',
            'format' => 'eliminazione',
            'state' => 'in_corso',
            'start_matchday' => 1,
        ]);

        TournamentEntry::create([
            'tournament_id' => $torneo->id,
            'manager_id' => $this->ada->id,
            'seed' => 1,
        ]);

        // Una statistica di Serie A: è il dato che NON deve sparire.
        PlayerStat::create([
            'player_id' => $rosa->first()->player_id,
            'season' => self::ANNATA,
            'matchday' => 1,
            'minutes' => 90,
            'rating' => 7.0,
        ]);
    }

    private function azzera(): array
    {
        return app(SeasonReset::class)->azzera($this->stagione);
    }

    // ───────────────────────── cosa se ne va ─────────────────────────

    public function test_via_tutto_il_giocato(): void
    {
        $this->stagioneGiocata();
        $this->azzera();

        $id = $this->stagione->id;

        $this->assertSame(0, Card::where('league_season_id', $id)->count());
        $this->assertSame(0, Lineup::where('league_season_id', $id)->count());
        $this->assertSame(0, Matchup::where('league_season_id', $id)->count());
        $this->assertSame(0, Standing::where('league_season_id', $id)->count());
        $this->assertSame(0, Draft::where('league_season_id', $id)->count());
        $this->assertSame(0, Tournament::where('league_season_id', $id)->count());
        $this->assertSame(0, PlayerScore::where('league_season_id', $id)->count());
        $this->assertSame(0, PlayerPower::where('league_season_id', $id)->count());
    }

    public function test_se_ne_vanno_anche_i_figli(): void
    {
        // Slot di formazione, turni di draft, iscrizioni al torneo: nessuno di
        // loro porta la chiave di stagione, e restando in giro sarebbero righe
        // orfane che puntano a un padre che non c'è più.
        $this->stagioneGiocata();
        $this->azzera();

        $this->assertSame(0, LineupSlot::count());
        $this->assertSame(0, DraftTurn::count());
        $this->assertSame(0, TournamentEntry::count());
    }

    // ───────────────────────── cosa resta ─────────────────────────

    public function test_la_stagione_resta_con_le_sue_regole(): void
    {
        $this->stagioneGiocata();
        $this->azzera();

        $fresca = $this->stagione->fresh();

        $this->assertNotNull($fresca);
        $this->assertSame(['eventi' => ['gol' => ['default' => 7]]], $fresca->settings);
        $this->assertSame(4, $fresca->start_matchday);
    }

    public function test_si_torna_in_preparazione(): void
    {
        // Il calendario se n'è andato con le sfide: lasciarla «in corso» le
        // farebbe offrire un «gioca la 1ª» che non ha più niente contro cui
        // giocare.
        $this->stagioneGiocata();
        $this->azzera();

        $this->assertSame('preparazione', $this->stagione->fresh()->state);
    }

    public function test_le_squadre_del_gruppo_restano(): void
    {
        $this->stagioneGiocata();
        $this->azzera();

        $this->assertSame(2, Manager::where('league_id', $this->stagione->league_id)->count());
    }

    public function test_le_statistiche_vere_di_serie_a_non_si_toccano(): void
    {
        // Sono dati del mondo: le usano anche gli altri gruppi che rigiocano
        // la stessa annata, e costano chiamate all'API.
        $this->stagioneGiocata();
        $this->azzera();

        $this->assertSame(1, PlayerStat::where('season', self::ANNATA)->count());
    }

    public function test_le_statistiche_inventate_se_ne_vanno(): void
    {
        // ⚠️ È il motivo per cui dopo un reset la pagina «Serie A» restava
        // ferma alla giornata dove ci si era arrivati prima: quell'elenco
        // mostra le giornate che hanno almeno una statistica, e le righe finte
        // erano ancora tutte lì. La stagione ripartiva davvero da capo, ma il
        // campionato sembrava a metà.
        $this->stagioneGiocata();

        PlayerStat::create([
            'player_id' => $this->makePlayer('C')->id,
            'season' => self::ANNATA,
            'matchday' => 2,
            'minutes' => 90,
            'rating' => 6.0,
            'source' => 'simulata',
        ]);

        $fatto = $this->azzera();

        $this->assertSame(1, $fatto['statistiche_finte']);
        $this->assertSame(0, PlayerStat::where('source', 'simulata')->count());
        $this->assertSame(1, PlayerStat::where('season', self::ANNATA)->count(), 'la vera resta');
    }

    public function test_un_altra_stagione_dello_stesso_gruppo_non_si_accorge_di_niente(): void
    {
        $this->stagioneGiocata();

        $altra = $this->makeStagione($this->stagione, 2022);
        $rosa = $this->makeRoster($this->ada, 'PDDDDCCCCAA', stagione: $altra);
        $this->scoreAll($rosa, 6.0);

        $this->azzera();

        $this->assertSame(11, Card::where('league_season_id', $altra->id)->count());
        $this->assertSame(11, PlayerScore::where('league_season_id', $altra->id)->count());
        $this->assertSame('in_corso', $altra->fresh()->state);
    }

    // ───────────────────────── dalla pagina ─────────────────────────

    public function test_l_admin_azzera_dalla_gestione(): void
    {
        $this->stagioneGiocata();
        $this->ada->update(['is_admin' => true]);

        $this->actingAs($this->ada)
            ->post(route('admin.stagione.azzera', $this->stagione))
            ->assertRedirect()
            ->assertSessionHas('successo');

        $this->assertSame(0, Card::where('league_season_id', $this->stagione->id)->count());
    }

    public function test_chi_non_e_admin_non_azzera_niente(): void
    {
        $this->stagioneGiocata();

        $this->actingAs($this->bruno)
            ->post(route('admin.stagione.azzera', $this->stagione))
            ->assertForbidden();

        $this->assertSame(11, Card::where('league_season_id', $this->stagione->id)->count());
    }

    public function test_non_si_azzera_la_stagione_di_un_altro_gruppo(): void
    {
        $this->stagioneGiocata();

        $altrui = $this->makeLeague('Altrui');
        $estraneo = tap($this->makeManager($altrui, 'Estraneo'))->update(['is_admin' => true]);

        $this->actingAs($estraneo)
            ->post(route('admin.stagione.azzera', $this->stagione))
            ->assertNotFound();

        $this->assertSame(11, Card::where('league_season_id', $this->stagione->id)->count());
    }

    public function test_il_messaggio_dice_cosa_e_stato_portato_via(): void
    {
        // «Fatto» da solo non permette di accorgersi di aver azzerato la
        // stagione sbagliata.
        $this->stagioneGiocata();

        $fatto = $this->azzera();

        $this->assertSame(11, $fatto['carte']);
        $this->assertSame(1, $fatto['sfide']);
        $this->assertSame(1, $fatto['draft']);
        $this->assertSame(1, $fatto['tornei']);
        $this->assertSame(1, $fatto['giornate']);
    }
}
