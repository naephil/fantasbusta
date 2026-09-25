<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Matchup;
use App\Models\Standing;
use App\Services\Calendar\CalendarBuilder;
use App\Services\Calendar\StandingsUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Calendario, risoluzione delle sfide e classifica.
 */
class CalendarTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private CalendarBuilder $builder;

    private StandingsUpdater $standings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new CalendarBuilder;
        $this->standings = new StandingsUpdater;
    }

    /** @return array{League, list<Manager>} */
    private function lega(int $quanti, ?array $settings = null): array
    {
        $league = $this->makeLeague('Lega di prova', $settings);

        $managers = collect(range(1, $quanti))
            ->map(fn (int $i) => $this->makeManager($league, "Manager{$i}"))
            ->all();

        return [$league->fresh(), $managers];
    }

    private function puntiDi(LeagueSeason $league, Manager $manager, int $matchday): int
    {
        return Matchup::where('league_season_id', $league->id)
            ->where('matchday', $matchday)
            ->involving($manager->id)
            ->firstOrFail()
            ->pointsFor($manager->id)['punti'];
    }

    // ───────────────────────── generazione ─────────────────────────

    public function test_dodici_manager_due_gironi_fanno_ventidue_turni(): void
    {
        [$league] = $this->lega(12);

        $turni = $this->builder->generate($league);

        $this->assertSame(22, $turni);
        $this->assertSame(132, Matchup::where('league_season_id', $league->id)->count());   // 22 × 6
        $this->assertSame(22, Matchup::where('league_season_id', $league->id)->max('matchday'));
    }

    public function test_la_lega_puo_partire_a_campionato_iniziato(): void
    {
        // Il caso che ha determinato la separazione fra turno e giornata:
        // non è detto che si cominci dalla prima di Serie A.
        [$league] = $this->lega(12);

        $this->builder->generate($league, startMatchday: 7);

        $primo = Matchup::where('league_season_id', $league->id)->orderBy('round')->first();
        $ultimo = Matchup::where('league_season_id', $league->id)->orderByDesc('round')->first();

        $this->assertSame(1, $primo->round);
        $this->assertSame(7, $primo->matchday);

        $this->assertSame(22, $ultimo->round);
        $this->assertSame(28, $ultimo->matchday);   // 7 + 22 − 1, dentro le 38
    }

    public function test_il_numero_di_gironi_e_configurabile_per_lega(): void
    {
        [$league] = $this->lega(12, ['calendario' => ['gironi' => 3]]);

        $this->assertSame(33, $this->builder->generate($league));
    }

    public function test_un_calendario_con_sfide_giocate_non_si_rigenera(): void
    {
        // Rigenerare a stagione in corso cancellerebbe risultati acquisiti.
        [$league] = $this->lega(12);

        $this->builder->generate($league);
        Matchup::where('league_season_id', $league->id)->limit(1)->update(['state' => 'played']);

        $this->expectException(RuntimeException::class);

        $this->builder->generate($league);
    }

    // ───────────────────────── a gol, come di fabbrica ─────────────────────────

    public function test_di_fabbrica_la_sfida_si_decide_a_gol(): void
    {
        // ⚠️ Il modo di partenza è questo, non più lo scarto di fantapunti: i
        // test qui sotto che provano lo scarto devono spegnere i gol a mano.
        [$league, [$marco, $giulia]] = $this->lega(2);
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 74.5);    // 2 gol
        $this->makeLineupResult($giulia, 1, 71.0);   // 1 gol

        $this->standings->update($league, 1);

        $sfida = Matchup::where('league_season_id', $league->id)->involving($marco->id)->firstOrFail();

        $this->assertSame([2, 1], [$sfida->home_goals, $sfida->away_goals]);
        $this->assertSame(3, $this->puntiDi($league, $marco, 1));
        $this->assertSame(0, $this->puntiDi($league, $giulia, 1));
    }

    public function test_a_gol_lo_scarto_dentro_la_stessa_fascia_non_basta(): void
    {
        // Due punti e mezzo di differenza che a scarto erano una vittoria: a
        // gol stanno nella stessa fascia, quindi è 1–1. È il cuore del cambio.
        [$league, [$marco, $giulia]] = $this->lega(2);
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 70.5);
        $this->makeLineupResult($giulia, 1, 68.0);

        $this->standings->update($league, 1);

        $this->assertSame(1, $this->puntiDi($league, $marco, 1));
        $this->assertSame(1, $this->puntiDi($league, $giulia, 1));
    }

    // ───────────────────────── a scarto, spegnendo i gol ─────────────────────────

    /** La taratura di chi preferisce il sistema di prima. */
    private const A_SCARTO = ['sfida' => ['gol' => ['attivo' => false]]];

    public function test_uno_scarto_ampio_assegna_la_vittoria(): void
    {
        [$league, [$marco, $giulia]] = $this->lega(2, self::A_SCARTO);
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 72.5);
        $this->makeLineupResult($giulia, 1, 68.0);

        $this->standings->update($league, 1);

        $this->assertSame(3, $this->puntiDi($league, $marco, 1));
        $this->assertSame(0, $this->puntiDi($league, $giulia, 1));
    }

    public function test_sotto_la_soglia_la_sfida_e_pari(): void
    {
        [$league, [$marco, $giulia]] = $this->lega(2, self::A_SCARTO);
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 70.5);
        $this->makeLineupResult($giulia, 1, 69.0);   // scarto 1.5

        $this->standings->update($league, 1);

        $this->assertSame(1, $this->puntiDi($league, $marco, 1));
        $this->assertSame(1, $this->puntiDi($league, $giulia, 1));
    }

    public function test_la_soglia_esatta_e_gia_vittoria(): void
    {
        // Il pareggio scatta SOTTO la soglia, non a soglia raggiunta.
        [$league, [$marco, $giulia]] = $this->lega(2, self::A_SCARTO);
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 70.0);
        $this->makeLineupResult($giulia, 1, 68.0);   // scarto esattamente 2.0

        $this->standings->update($league, 1);

        $this->assertSame(3, $this->puntiDi($league, $marco, 1));
    }

    public function test_a_scarto_non_resta_nessun_risultato_in_reti(): void
    {
        // `null` e non `0`: uno 0–0 è un risultato vero, e la pagina deve poter
        // distinguere «finita a reti inviolate» da «qui non si gioca a gol».
        [$league, [$marco, $giulia]] = $this->lega(2, self::A_SCARTO);
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 72.5);
        $this->makeLineupResult($giulia, 1, 68.0);

        $this->standings->update($league, 1);

        $sfida = Matchup::where('league_season_id', $league->id)->involving($marco->id)->firstOrFail();

        $this->assertNull($sfida->home_goals);
        $this->assertNull($sfida->away_goals);
        $this->assertFalse($sfida->aGol());
    }

    public function test_la_lega_puo_ritarare_soglia_e_punteggi(): void
    {
        [$league, [$marco, $giulia]] = $this->lega(2, array_replace_recursive(self::A_SCARTO, [
            'sfida' => ['soglia_pareggio' => 5.0, 'vittoria' => 2],
        ]));
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 72.5);
        $this->makeLineupResult($giulia, 1, 68.0);   // scarto 4.5, sotto la nuova soglia

        $this->standings->update($league, 1);

        $this->assertSame(1, $this->puntiDi($league, $marco, 1));
        $this->assertSame(1, $this->puntiDi($league, $giulia, 1));
    }

    public function test_senza_totali_la_sfida_resta_in_attesa(): void
    {
        // La giornata non è stata calcolata: non deve finire 0-0 d'ufficio.
        [$league] = $this->lega(2);
        $this->builder->generate($league, gironi: 1);

        $this->standings->update($league, 1);

        $this->assertSame('scheduled', Matchup::where('league_season_id', $league->id)->first()->state);
    }

    // ───────────────────────── la classifica ─────────────────────────

    public function test_la_classifica_accumula_giornata_dopo_giornata(): void
    {
        [$league, [$marco, $giulia]] = $this->lega(2);
        $this->builder->generate($league, gironi: 2);

        $this->makeLineupResult($marco, 1, 80.0);
        $this->makeLineupResult($giulia, 1, 70.0);    // Marco vince
        $this->standings->update($league, 1);

        $this->makeLineupResult($marco, 2, 70.0);
        $this->makeLineupResult($giulia, 2, 71.0);    // pari, scarto 1.0
        $this->standings->update($league, 2);

        $dopoLaPrima = Standing::where('manager_id', $marco->id)->where('matchday', 1)->firstOrFail();
        $dopoLaSeconda = Standing::where('manager_id', $marco->id)->where('matchday', 2)->firstOrFail();

        $this->assertSame(3, $dopoLaPrima->punti);
        $this->assertSame(4, $dopoLaSeconda->punti);
        $this->assertSame(150.0, $dopoLaSeconda->fantapunti);

        $this->assertSame(1, $dopoLaSeconda->posizione);
        $this->assertSame(2, Standing::where('manager_id', $giulia->id)->where('matchday', 2)->firstOrFail()->posizione);
    }

    public function test_a_parita_di_punti_decidono_i_fantapunti(): void
    {
        [$league, [$marco, $giulia]] = $this->lega(2);
        $this->builder->generate($league, gironi: 1);

        // Pareggio secco, ma Giulia ha totalizzato di più.
        $this->makeLineupResult($marco, 1, 69.0);
        $this->makeLineupResult($giulia, 1, 70.5);

        $this->standings->update($league, 1);

        $this->assertSame(1, Standing::where('manager_id', $giulia->id)->firstOrFail()->posizione);
        $this->assertSame(2, Standing::where('manager_id', $marco->id)->firstOrFail()->posizione);
    }

    public function test_il_ricalcolo_corregge_invece_di_sommarsi(): void
    {
        // Capita di rilanciare il calcolo quando i rating arrivano tardi.
        [$league, [$marco, $giulia]] = $this->lega(2);
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 80.0);
        $this->makeLineupResult($giulia, 1, 70.0);

        $this->standings->update($league, 1);
        $this->standings->update($league, 1);

        $this->assertSame(3, Standing::where('manager_id', $marco->id)->firstOrFail()->punti);
        $this->assertSame(2, Standing::where('league_season_id', $league->id)->count());
    }

    public function test_l_ordine_di_draft_e_l_inverso_della_classifica(): void
    {
        // Chi sta peggio pesca per primo: è l'unico riequilibrio previsto.
        [$league, [$marco, $giulia]] = $this->lega(2);
        $this->builder->generate($league, gironi: 1);

        $this->makeLineupResult($marco, 1, 80.0);
        $this->makeLineupResult($giulia, 1, 70.0);

        $this->standings->update($league, 1);

        $this->assertSame(
            [$giulia->id, $marco->id],
            Standing::draftOrder($league->id, 1),
        );
    }
}
