<?php

namespace Tests\Feature;

use App\Models\Matchup;
use App\Models\PlayerStat;
use App\Services\Calendar\StandingsUpdater;
use App\Services\Scoring\MatchdayScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Il tabellino di una sfida.
 *
 * Mancava, e la mancanza si sentiva dove il gioco dovrebbe dare più
 * soddisfazione: la classifica diceva «74,5 – 71,0» e non c'era modo di sapere
 * perché. La cosa che questa pagina deve spiegare più di tutte sono le
 * SOSTITUZIONI — il draft chiude prima delle formazioni ufficiali, quindi
 * schierare un titolare che poi resta fuori è la norma, e senza vedere chi è
 * entrato al suo posto il totale sembra sbagliato.
 */
class MatchupDetailTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    /** Due squadre schierate e una sfida già giocata. */
    private function sfidaGiocata(): array
    {
        $stagione = $this->makeLeague();
        $casa = $this->makeManager($stagione, 'Marco');
        $fuori = $this->makeManager($stagione, 'Giulia');

        // Dodici carte: undici titolari più un panchinaro dello stesso ruolo
        // del titolare che resterà senza voto.
        $rosaCasa = $this->makeRoster($casa, 'PDDDDCCCCAAC');
        $rosaFuori = $this->makeRoster($fuori, 'PDDDDCCCCAAC');

        $this->makeLineup($casa, $rosaCasa);
        $this->makeLineup($fuori, $rosaFuori);

        $this->scoreAll($rosaCasa, 6.0);
        $this->scoreAll($rosaFuori, 6.0);

        $sfida = Matchup::create([
            'league_season_id' => $stagione->id,
            'round' => 1,
            'matchday' => 1,
            'home_manager_id' => $casa->id,
            'away_manager_id' => $fuori->id,
        ]);

        return [$stagione, $casa, $fuori, $rosaCasa, $rosaFuori, $sfida];
    }

    public function test_il_tabellino_mostra_i_voti_dei_titolari(): void
    {
        [$stagione, $casa, , $rosaCasa, , $sfida] = $this->sfidaGiocata();

        app(MatchdayScorer::class)->run($stagione, 1);
        app(StandingsUpdater::class)->update($stagione, 1);

        $risposta = $this->actingAs($casa)->get(route('matchup.show', $sfida))->assertOk();

        // I cognomi degli undici schierati, e il totale della formazione.
        foreach ($rosaCasa->take(11) as $carta) {
            $risposta->assertSee($carta->player->last_name);
        }

        $risposta->assertSee('Totale');
    }

    public function test_il_tabellino_dice_chi_e_entrato_al_posto_di_chi(): void
    {
        // ⚠️ È il pezzo che spiega i totali che «non tornano». Senza, un
        // cognome che nessuno ha schierato compare nel conto e sembra un bug.
        [$stagione, $casa, , $rosaCasa, , $sfida] = $this->sfidaGiocata();

        // Un centrocampista titolare resta senza voto: entra quello di panchina.
        $titolare = $rosaCasa->where('role', 'C')->first();
        $this->rescore($titolare, null);

        app(MatchdayScorer::class)->run($stagione, 1);
        app(StandingsUpdater::class)->update($stagione, 1);

        $this->actingAs($casa)
            ->get(route('matchup.show', $sfida))
            ->assertOk()
            ->assertSee('entrato per')
            ->assertSee($titolare->player->last_name);
    }

    public function test_chi_resta_senza_voto_e_senza_cambio_lo_dice(): void
    {
        [$stagione, $casa, , $rosaCasa, , $sfida] = $this->sfidaGiocata();

        // Il portiere non ha riserva: resta senza voto e prende l'ufficio.
        $this->rescore($rosaCasa->firstWhere('role', 'P'), null);

        app(MatchdayScorer::class)->run($stagione, 1);
        app(StandingsUpdater::class)->update($stagione, 1);

        $this->actingAs($casa)
            ->get(route('matchup.show', $sfida))
            ->assertOk()
            ->assertSee('nessun cambio possibile');
    }

    public function test_il_tabellino_e_in_ordine_di_reparto(): void
    {
        // ⚠️ Gli slot arrivano nell'ordine in cui sono stati spuntati, che non
        // è un ordine. Un tabellino ordinato per voto mette insieme un portiere,
        // due attaccanti e un terzino, e per capire com'era messa una squadra
        // bisogna ricomporla a mente. P-D-C-A è come si legge una formazione.
        [$stagione, $casa, , $rosaCasa, , $sfida] = $this->sfidaGiocata();

        app(MatchdayScorer::class)->run($stagione, 1);
        app(StandingsUpdater::class)->update($stagione, 1);

        $html = $this->actingAs($casa)->get(route('matchup.show', $sfida))->assertOk()->getContent();

        // Le posizioni dei ruoli nel testo devono salire: tutti i P prima dei
        // D, tutti i D prima dei C, e così via.
        $posizioni = [];

        foreach (['P', 'D', 'C', 'A'] as $ruolo) {
            $carta = $rosaCasa->firstWhere('role', $ruolo);
            $posizioni[$ruolo] = strpos($html, $carta->player->last_name);
        }

        $this->assertLessThan($posizioni['D'], $posizioni['P']);
        $this->assertLessThan($posizioni['C'], $posizioni['D']);
        $this->assertLessThan($posizioni['A'], $posizioni['C']);
    }

    public function test_il_tabellino_mostra_bonus_voto_base_e_colori(): void
    {
        // Un fantavoto da solo è un numero: 9,5 non dice se è un 6 in pagella
        // con tre gol o un 9 asciutto, e sono due giornate molto diverse.
        [$stagione, $casa, , $rosaCasa, , $sfida] = $this->sfidaGiocata();

        $marcatore = $rosaCasa->firstWhere('role', 'A');

        PlayerStat::updateOrCreate(
            ['player_id' => $marcatore->player_id, 'season' => $stagione->season, 'matchday' => 1],
            ['minutes' => 90, 'rating' => 6.0, 'goals' => 2, 'assists' => 1, 'yellow' => 1],
        );

        $this->rescore($marcatore, 12.5);

        app(MatchdayScorer::class)->run($stagione, 1);
        app(StandingsUpdater::class)->update($stagione, 1);

        $risposta = $this->actingAs($casa)->get(route('matchup.show', $sfida))->assertOk();

        $risposta->assertSee('⚽⚽', false);      // due gol
        $risposta->assertSee('1A');              // un assist
        $risposta->assertSee('voto in pagella'); // il voto base accanto al fantavoto
        $risposta->assertSee('text-ruolo-d', false);   // un 12,5 si colora
    }

    public function test_i_gol_subiti_si_mostrano_solo_al_portiere(): void
    {
        // ⚠️ Il coefficiente `gol_subito` è per ruolo e di fabbrica pesa solo
        // sul portiere: un difensore non perde niente per un gol della
        // squadra. Mostrarli a tutti farebbe leggere come malus una riga che
        // per dieci undicesimi della formazione non cambia niente.
        [$stagione, $casa, , $rosaCasa, , $sfida] = $this->sfidaGiocata();

        $portiere = $rosaCasa->firstWhere('role', 'P');
        $difensore = $rosaCasa->firstWhere('role', 'D');

        foreach ([$portiere, $difensore] as $carta) {
            PlayerStat::updateOrCreate(
                ['player_id' => $carta->player_id, 'season' => $stagione->season, 'matchday' => 1],
                ['minutes' => 90, 'rating' => 6.0, 'goals_conceded' => 3],
            );
        }

        app(MatchdayScorer::class)->run($stagione, 1);
        app(StandingsUpdater::class)->update($stagione, 1);

        $html = $this->actingAs($casa)->get(route('matchup.show', $sfida))->assertOk()->getContent();

        // Il segnalino compare una volta sola: quella del portiere.
        $this->assertSame(1, substr_count($html, 'gol subiti'));
    }

    public function test_una_sfida_non_giocata_lo_dice_invece_di_mentire(): void
    {
        [, $casa, , , , $sfida] = $this->sfidaGiocata();

        $this->actingAs($casa)
            ->get(route('matchup.show', $sfida))
            ->assertOk()
            ->assertSee('non è ancora stata giocata');
    }

    public function test_la_sfida_di_un_altro_gruppo_non_si_apre(): void
    {
        // ⚠️ L'id è indovinabile a tentativi, e le rose di un altro gruppo non
        // riguardano nessuno.
        [, , , , , $sfida] = $this->sfidaGiocata();

        $estraneo = $this->makeManager($this->makeLeague('Altro gruppo'), 'Estraneo');

        $this->actingAs($estraneo)->get(route('matchup.show', $sfida))->assertNotFound();
    }

    public function test_il_tabellino_e_riservato(): void
    {
        [, , , , , $sfida] = $this->sfidaGiocata();

        $this->get(route('matchup.show', $sfida))->assertRedirect(route('login'));
    }

    public function test_dalla_classifica_si_arriva_al_tabellino(): void
    {
        [$stagione, $casa, , , , $sfida] = $this->sfidaGiocata();

        app(MatchdayScorer::class)->run($stagione, 1);
        app(StandingsUpdater::class)->update($stagione, 1);

        $this->actingAs($casa)
            ->get(route('standings.index'))
            ->assertOk()
            ->assertSee(route('matchup.show', $sfida), false);
    }
}
