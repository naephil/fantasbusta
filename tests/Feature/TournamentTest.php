<?php

namespace Tests\Feature;

use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Matchup;
use App\Models\Tournament;
use App\Services\Calendar\CalendarBuilder;
use App\Services\Tournament\TournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Tornei: creazione, avvio, avanzamento.
 *
 * I punteggi arrivano da `lineup_results`, quindi i test li scrivono
 * direttamente: qui si prova la meccanica del torneo, non il calcolo del
 * fantavoto — che ha già i suoi test.
 */
class TournamentTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $league;

    /** @var Collection<int,Manager> */
    private Collection $squadre;

    private TournamentService $tornei;

    protected function setUp(): void
    {
        parent::setUp();

        $this->league = $this->makeLeague();
        $this->tornei = app(TournamentService::class);

        $this->squadre = collect(['Ada', 'Bruno', 'Carla', 'Dario', 'Elena', 'Fabio', 'Gina', 'Hugo'])
            ->map(fn (string $n) => $this->makeManager($this->league, $n));
    }

    /** @param  array<int,float>  $punteggi  indice nella lista squadre => totale */
    private function giornata(int $matchday, array $punteggi): void
    {
        foreach ($punteggi as $i => $totale) {
            $this->makeLineupResult($this->squadre[$i], $matchday, $totale);
        }
    }

    private function crea(string $formato, int $quante = 8, array $settings = [], int $da = 1): Tournament
    {
        return $this->tornei->crea(
            $this->league,
            'Coppa di prova',
            $formato,
            $this->squadre->take($quante)->pluck('id')->all(),
            $da,
            $settings,
        );
    }

    // ───────────────────────── creazione ─────────────────────────

    public function test_un_torneo_nasce_in_bozza_con_le_teste_di_serie(): void
    {
        $torneo = $this->crea('eliminazione', 4);

        $this->assertSame('bozza', $torneo->state);
        $this->assertSame(4, $torneo->entries()->count());
        $this->assertSame(
            [1, 2, 3, 4],
            $torneo->entries()->orderBy('seed')->pluck('seed')->all(),
        );
    }

    public function test_l_ordine_di_iscrizione_e_l_ordine_di_testa_di_serie(): void
    {
        // Non è un dettaglio: decide gli accoppiamenti e fa da ultimo spareggio.
        $torneo = $this->crea('eliminazione', 4);

        $prima = $torneo->entries()->where('seed', 1)->first();

        $this->assertSame($this->squadre[0]->id, $prima->manager_id);
    }

    public function test_un_formato_inventato_viene_rifiutato(): void
    {
        $this->expectExceptionMessage('Formato sconosciuto');

        $this->crea('scala_quaranta', 4);
    }

    public function test_un_partecipante_di_un_altra_lega_viene_rifiutato(): void
    {
        $estraneo = $this->makeManager($this->makeLeague('Altra'), 'Estraneo');

        $this->expectExceptionMessage('non gioca questa stagione');

        $this->tornei->crea($this->league, 'Coppa', 'eliminazione',
            [$this->squadre[0]->id, $estraneo->id], 1);
    }

    public function test_il_numero_di_partecipanti_deve_stare_nei_limiti(): void
    {
        $this->expectException(RuntimeException::class);

        $this->crea('royal_rumble', 2);   // il rumble ne vuole almeno 3
    }

    public function test_un_torneo_non_si_avvia_due_volte(): void
    {
        $torneo = $this->tornei->avvia($this->crea('eliminazione', 4));

        $this->expectExceptionMessage('già stato avviato');

        $this->tornei->avvia($torneo);
    }

    // ───────────────────────── girone ─────────────────────────

    public function test_il_girone_genera_tutto_il_calendario_all_avvio(): void
    {
        // Nessun turno dipende dai risultati: si può sapere tutto subito.
        $torneo = $this->tornei->avvia($this->crea('girone', 4));

        $this->assertSame(3, $torneo->matchups()->distinct()->count('matchday'));
        $this->assertSame(6, $torneo->matchups()->count());   // 4 su 2
    }

    public function test_il_girone_incorona_chi_ha_piu_punti(): void
    {
        $torneo = $this->tornei->avvia($this->crea('girone', 4));

        // Ada vince sempre, con margine oltre la soglia di pareggio.
        foreach ([1, 2, 3] as $giornata) {
            $this->giornata($giornata, [0 => 100.0, 1 => 50.0, 2 => 40.0, 3 => 30.0]);
            $torneo->formato()->avanza($torneo->fresh(), $giornata);
        }

        $torneo->refresh();

        $this->assertSame('concluso', $torneo->state);
        $this->assertSame($this->squadre[0]->id, $torneo->winner_manager_id);
    }

    // ───────────────────────── eliminazione diretta ─────────────────────────

    public function test_il_tabellone_accoppia_primo_contro_ultimo(): void
    {
        // Tiene lontane le teste di serie migliori: altrimenti la finale
        // rischia di giocarsi al primo turno.
        $torneo = $this->tornei->avvia($this->crea('eliminazione', 4));

        $prima = $torneo->matchups()->orderBy('id')->first();

        $this->assertSame($this->squadre[0]->id, $prima->home_manager_id);
        $this->assertSame($this->squadre[3]->id, $prima->away_manager_id);
    }

    public function test_chi_perde_esce_e_il_turno_dopo_si_genera_da_solo(): void
    {
        $torneo = $this->tornei->avvia($this->crea('eliminazione', 4));

        $this->giornata(1, [0 => 90.0, 1 => 80.0, 2 => 40.0, 3 => 30.0]);
        $torneo->formato()->avanza($torneo, 1);

        $torneo->refresh();

        $this->assertSame(2, $torneo->entries()->where('state', 'eliminato')->count());
        $this->assertSame(1, $torneo->matchups()->where('matchday', 2)->count());
    }

    public function test_il_tabellone_arriva_a_un_vincitore(): void
    {
        $torneo = $this->tornei->avvia($this->crea('eliminazione', 4));

        $this->giornata(1, [0 => 90.0, 1 => 80.0, 2 => 40.0, 3 => 30.0]);
        $torneo->formato()->avanza($torneo, 1);

        $this->giornata(2, [0 => 95.0, 1 => 60.0]);
        $torneo->fresh()->formato()->avanza($torneo->fresh(), 2);

        $torneo->refresh();

        $this->assertSame('concluso', $torneo->state);
        $this->assertSame($this->squadre[0]->id, $torneo->winner_manager_id);
    }

    public function test_a_parita_esatta_passa_la_testa_di_serie_migliore(): void
    {
        // Il pareggio non esiste in un tabellone: un turno che non avanza è
        // peggio di un criterio arbitrario, purché dichiarato prima.
        $torneo = $this->tornei->avvia($this->crea('eliminazione', 2));

        $this->giornata(1, [0 => 70.0, 1 => 70.0]);
        $torneo->formato()->avanza($torneo, 1);

        $torneo->refresh();

        $this->assertSame($this->squadre[0]->id, $torneo->winner_manager_id);
    }

    public function test_con_numeri_non_potenza_di_due_i_migliori_riposano(): void
    {
        // Sei squadre: quattro giocano il preliminare, le prime due aspettano.
        $torneo = $this->tornei->avvia($this->crea('eliminazione', 6));

        $primo = $torneo->matchups()->where('matchday', 1)->get();

        $this->assertCount(2, $primo);
        $this->assertSame('preliminare', $primo->first()->stage);

        $inGara = $primo->flatMap(fn ($m) => [$m->home_manager_id, $m->away_manager_id]);

        $this->assertNotContains($this->squadre[0]->id, $inGara);
        $this->assertNotContains($this->squadre[1]->id, $inGara);
    }

    // ───────────────────────── royal rumble ─────────────────────────

    public function test_il_rumble_elimina_chi_ha_fatto_meno(): void
    {
        $torneo = $this->tornei->avvia($this->crea('royal_rumble', 4));

        $this->assertSame(0, $torneo->matchups()->count());   // nessuna sfida, mai

        $this->giornata(1, [0 => 80.0, 1 => 70.0, 2 => 60.0, 3 => 10.0]);
        $torneo->formato()->avanza($torneo, 1);

        $eliminato = $torneo->entries()->where('state', 'eliminato')->first();

        $this->assertSame($this->squadre[3]->id, $eliminato->manager_id);
        $this->assertSame(1, $eliminato->eliminated_matchday);
    }

    public function test_chi_e_gia_fuori_non_rientra_col_punteggio_alto(): void
    {
        // È crudele ed è il punto: eliminato è eliminato.
        $torneo = $this->tornei->avvia($this->crea('royal_rumble', 4));

        $this->giornata(1, [0 => 80.0, 1 => 70.0, 2 => 60.0, 3 => 10.0]);
        $torneo->formato()->avanza($torneo, 1);

        $this->giornata(2, [0 => 20.0, 1 => 70.0, 2 => 60.0, 3 => 999.0]);
        $torneo->fresh()->formato()->avanza($torneo->fresh(), 2);

        $fuori = $torneo->entries()->where('state', 'eliminato')->pluck('manager_id');

        $this->assertContains($this->squadre[3]->id, $fuori);
        $this->assertContains($this->squadre[0]->id, $fuori);   // stavolta il peggiore è Ada
    }

    public function test_il_rumble_finisce_con_un_solo_superstite(): void
    {
        $torneo = $this->tornei->avvia($this->crea('royal_rumble', 4));

        foreach ([1, 2, 3] as $giornata) {
            $this->giornata($giornata, [0 => 100.0, 1 => 80.0, 2 => 60.0, 3 => 10.0]);
            $torneo->fresh()->formato()->avanza($torneo->fresh(), $giornata);
        }

        $torneo->refresh();

        $this->assertSame('concluso', $torneo->state);
        $this->assertSame($this->squadre[0]->id, $torneo->winner_manager_id);
    }

    public function test_si_puo_eliminare_piu_di_uno_a_giornata(): void
    {
        $torneo = $this->tornei->avvia(
            $this->crea('royal_rumble', 8, ['eliminati_per_giornata' => 3]),
        );

        $this->giornata(1, [0 => 100.0, 1 => 90.0, 2 => 80.0, 3 => 70.0, 4 => 60.0, 5 => 50.0, 6 => 40.0, 7 => 30.0]);
        $torneo->formato()->avanza($torneo, 1);

        $this->assertSame(3, $torneo->entries()->where('state', 'eliminato')->count());
    }

    // ───────────────────────── gironi + eliminazione ─────────────────────────

    public function test_i_gruppi_si_riempiono_a_serpentina(): void
    {
        // Riempirli in ordine metterebbe le migliori tutte insieme.
        $torneo = $this->tornei->avvia($this->crea('gironi_eliminazione', 8, ['gruppi' => 2, 'qualificate' => 2]));

        $gruppoDellaPrima = $torneo->entries()->where('seed', 1)->first()->girone;
        $gruppoDellaSeconda = $torneo->entries()->where('seed', 2)->first()->girone;

        $this->assertNotSame($gruppoDellaPrima, $gruppoDellaSeconda);
        $this->assertSame(4, $torneo->entries()->where('girone', 'A')->count());
    }

    public function test_dopo_i_gironi_si_apre_il_tabellone(): void
    {
        $torneo = $this->tornei->avvia($this->crea('gironi_eliminazione', 8, ['gruppi' => 2, 'qualificate' => 2]));

        // Tre giornate di girone (4 squadre per gruppo).
        foreach ([1, 2, 3] as $giornata) {
            $this->giornata($giornata, [0 => 100.0, 1 => 95.0, 2 => 90.0, 3 => 85.0, 4 => 20.0, 5 => 15.0, 6 => 10.0, 7 => 5.0]);
            $torneo->fresh()->formato()->avanza($torneo->fresh(), $giornata);
        }

        $torneo->refresh();

        $this->assertSame(4, $torneo->entries()->where('state', 'attivo')->count());
        $this->assertSame(2, $torneo->matchups()->where('matchday', 4)->count());
    }

    // ───────────────────────── isolamento dal campionato ─────────────────────────

    public function test_le_sfide_di_torneo_non_entrano_nella_classifica_di_lega(): void
    {
        // Una coppa non muove il campionato, e viceversa: sono competizioni
        // diverse che condividono solo i punteggi di giornata.
        $torneo = $this->tornei->avvia($this->crea('girone', 4));

        $this->assertSame(0, Matchup::whereNull('tournament_id')->count());
        $this->assertSame(6, Matchup::whereNotNull('tournament_id')->count());
    }

    public function test_lo_stesso_manager_puo_giocare_campionato_e_coppa_nello_stesso_giorno(): void
    {
        // Il vincolo di unicità è dentro la competizione, non sulla giornata:
        // altrimenti la coppa sarebbe impossibile.
        app(CalendarBuilder::class)->generate($this->league, 1, 1);

        $this->tornei->avvia($this->crea('girone', 4));

        $sfidePrimaGiornata = Matchup::where('matchday', 1)
            ->involving($this->squadre[0]->id)
            ->count();

        $this->assertGreaterThan(1, $sfidePrimaGiornata);
    }
}
