<?php

namespace Tests\Feature;

use App\Models\Matchup;
use App\Services\Calendar\StandingsUpdater;
use App\Services\Scoring\Arbitro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Come finisce una sfida: a gol come nel fantacalcio, o a scarto di fantapunti.
 *
 * La conversione in reti è tutta in una formula di tre righe, quindi le prove
 * che servono sono quelle sui confini — la soglia esatta, il punto appena sotto,
 * il passo successivo — e quella che il risultato finisca davvero in classifica
 * passando per il codice vero e non solo per l'arbitro.
 */
class ArbitroTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    // ───────────────────────── la scala dei gol ─────────────────────────

    public static function scalaDefault(): array
    {
        return [
            'a secco sotto la prima soglia' => [0.0, 0],
            'poco sotto è ancora zero' => [65.5, 0],
            'la soglia esatta vale un gol' => [66.0, 1],
            'appena sopra resta uno' => [66.5, 1],
            'poco sotto il passo resta uno' => [71.99, 1],
            'il passo esatto vale due' => [72.0, 2],
            'terzo scalino' => [78.0, 3],
            'quarto scalino' => [84.0, 4],
            'una giornata da novanta' => [90.0, 5],
            'i decimali non arrotondano in su' => [77.99, 2],
        ];
    }

    #[DataProvider('scalaDefault')]
    public function test_la_scala_di_partenza_e_quella_del_fantacalcio(float $fantapunti, int $attesi): void
    {
        // 66 e 6: i valori con cui gioca praticamente tutto il fantacalcio
        // italiano. Se cambiano i default questo test deve rompersi.
        $this->assertSame($attesi, Arbitro::per($this->makeLeague())->gol($fantapunti));
    }

    public function test_la_soglia_esatta_non_dipende_dalla_virgola_mobile(): void
    {
        // ⚠️ È il motivo per cui il conto si fa in centesimi interi: in binario
        // 66 + 6 non fa sempre esattamente 72, e un risultato sbagliato una
        // volta ogni tanto è il tipo di errore che non si riesce a riprodurre.
        $arbitro = Arbitro::per($this->makeLeague());

        foreach (range(1, 12) as $n) {
            $soglia = 66.0 + ($n - 1) * 6.0;

            $this->assertSame($n, $arbitro->gol($soglia), "soglia {$soglia}");
            $this->assertSame($n - 1, $arbitro->gol($soglia - 0.01), "appena sotto {$soglia}");
        }
    }

    public function test_la_scala_si_tara_per_stagione(): void
    {
        // Le regole sono di lega e di annata: un gruppo che vuole partite più
        // ricche abbassa la soglia e accorcia il passo.
        $stagione = $this->makeLeague(settings: [
            'sfida' => ['gol' => ['prima_soglia' => 60.0, 'passo' => 4.0]],
        ]);

        $arbitro = Arbitro::per($stagione);

        $this->assertSame(0, $arbitro->gol(59.9));
        $this->assertSame(1, $arbitro->gol(60.0));
        $this->assertSame(2, $arbitro->gol(64.0));
        $this->assertSame(6, $arbitro->gol(80.0));
    }

    // ───────────────────────── l'esito ─────────────────────────

    public function test_a_gol_si_vince_col_risultato_non_col_totale(): void
    {
        // È il senso di tutto il cambiamento: 74,5 contro 71,0 non è più «vinta
        // di tre punti e mezzo», è un 2–1 — e 71,0 contro 70,0 è un 1–1, perché
        // un punto di scarto dentro la stessa fascia non è una vittoria.
        $arbitro = Arbitro::per($this->makeLeague());

        $largo = $arbitro->esito(74.5, 71.0);
        $this->assertSame([2, 1], [$largo->golCasa, $largo->golFuori]);
        $this->assertSame([3, 0], [$largo->puntiCasa, $largo->puntiFuori]);

        $stretto = $arbitro->esito(71.0, 70.0);
        $this->assertSame([1, 1], [$stretto->golCasa, $stretto->golFuori]);
        $this->assertSame([1, 1], [$stretto->puntiCasa, $stretto->puntiFuori]);
    }

    public function test_due_giornate_storte_finiscono_a_reti_inviolate(): void
    {
        $esito = Arbitro::per($this->makeLeague())->esito(61.0, 58.0);

        $this->assertSame([0, 0], [$esito->golCasa, $esito->golFuori]);
        $this->assertSame([1, 1], [$esito->puntiCasa, $esito->puntiFuori]);
        $this->assertTrue($esito->aGol());
    }

    public function test_a_gol_spenti_torna_il_sistema_a_scarto(): void
    {
        $stagione = $this->makeLeague(settings: ['sfida' => ['gol' => ['attivo' => false]]]);
        $arbitro = Arbitro::per($stagione);

        // Un punto di scarto sta sotto la soglia di 2, quindi resta pari come
        // a gol — ma per un motivo diverso, ed è il motivo che si sta provando.
        $pari = $arbitro->esito(71.0, 70.0);
        $this->assertSame([1, 1], [$pari->puntiCasa, $pari->puntiFuori]);

        $vinta = $arbitro->esito(74.5, 71.0);
        $this->assertSame([3, 0], [$vinta->puntiCasa, $vinta->puntiFuori]);

        // ⚠️ Niente reti da mostrare: `null` e non `0`, che sarebbe uno 0–0.
        $this->assertNull($vinta->golCasa);
        $this->assertNull($vinta->golFuori);
        $this->assertFalse($vinta->aGol());
    }

    public function test_i_punti_di_classifica_restano_tarabili(): void
    {
        $stagione = $this->makeLeague(settings: [
            'sfida' => ['vittoria' => 5, 'pareggio' => 2, 'sconfitta' => -1],
        ]);

        $esito = Arbitro::per($stagione)->esito(80.0, 66.0);

        $this->assertSame([3, 1], [$esito->golCasa, $esito->golFuori]);
        $this->assertSame([5, -1], [$esito->puntiCasa, $esito->puntiFuori]);
    }

    public function test_un_passo_a_zero_non_fa_esplodere_la_giornata(): void
    {
        // La validazione lo esclude, ma un valore arrivato da una taratura
        // scritta a mano non deve far saltare il calcolo di tutta la giornata
        // con un DivisionByZeroError.
        $stagione = $this->makeLeague(settings: ['sfida' => ['gol' => ['passo' => 0]]]);

        $this->assertSame(1, Arbitro::per($stagione)->gol(100.0));
    }

    // ───────────────────────── fino alla classifica ─────────────────────────

    public function test_il_risultato_in_reti_finisce_sulla_sfida(): void
    {
        // Passando dal codice vero: se le colonne non venissero scritte,
        // l'arbitro avrebbe ragione e la pagina mostrerebbe comunque nulla.
        $stagione = $this->makeLeague();
        $casa = $this->makeManager($stagione, 'Casa');
        $fuori = $this->makeManager($stagione, 'Fuori');

        $this->makeLineupResult($casa, 1, 74.5, $stagione);
        $this->makeLineupResult($fuori, 1, 71.0, $stagione);

        $sfida = Matchup::create([
            'league_season_id' => $stagione->id,
            'round' => 1,
            'matchday' => 1,
            'home_manager_id' => $casa->id,
            'away_manager_id' => $fuori->id,
        ]);

        app(StandingsUpdater::class)->update($stagione, 1);

        $sfida->refresh();

        $this->assertSame(2, $sfida->home_goals);
        $this->assertSame(1, $sfida->away_goals);
        $this->assertSame(3, (int) $sfida->home_score);
        $this->assertSame(0, (int) $sfida->away_score);
        $this->assertTrue($sfida->aGol());

        // I fantapunti restano: sono lo spareggio della classifica, e il
        // risultato in reti non li sostituisce.
        $this->assertEqualsWithDelta(74.5, $sfida->home_points, 0.001);
    }
}
