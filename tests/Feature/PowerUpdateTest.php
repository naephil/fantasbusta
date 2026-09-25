<?php

namespace Tests\Feature;

use App\Enums\Tier;
use App\Models\Player;
use App\Models\PlayerPower;
use App\Models\PlayerSeason;
use App\Services\Power\PowerUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Ricalcolo del power score su tutto il listone.
 */
class PowerUpdateTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private PowerUpdater $updater;

    protected function setUp(): void
    {
        parent::setUp();

        $this->updater = new PowerUpdater;
    }

    private function powerOf(Player $player, int $matchday): PlayerPower
    {
        return PlayerPower::where('player_id', $player->id)
            ->where('matchday', $matchday)
            ->firstOrFail();
    }

    // ───────────────────── la finestra dei dati ─────────────────────

    public function test_il_calcolo_si_ferma_due_giornate_indietro(): void
    {
        // È l'errore di un passo che rovinerebbe tutto: il draft della giornata
        // N+1 apre quando la N è ancora in corso, quindi i dati completi
        // arrivano solo fino alla N−1. Contare anche la N significherebbe
        // pescare sapendo come sta andando la giornata che si sta giocando.
        $league = $this->makeLeague();

        $bravoPrima = $this->makePlayer('A');
        $bravoDopo = $this->makePlayer('A');

        $this->makePerformance($bravoPrima, 1, 12.0);
        $this->makePerformance($bravoPrima, 2, 2.0);

        $this->makePerformance($bravoDopo, 1, 2.0);
        $this->makePerformance($bravoDopo, 2, 12.0);

        // Power per la 3ª: guarda fino alla 1ª, la 2ª non esiste ancora.
        $this->updater->update($league, 3);

        $this->assertSame(1, $this->powerOf($bravoPrima, 3)->rank);
        $this->assertSame(2, $this->powerOf($bravoDopo, 3)->rank);

        // Power per la 4ª: ora entrambe le giornate contano e i due si
        // equivalgono. Se la 2ª fosse già entrata nel conto della 3ª, anche
        // là sarebbero stati pari.
        $this->updater->update($league, 4);

        $this->assertSame(
            $this->powerOf($bravoPrima, 4)->power,
            $this->powerOf($bravoDopo, 4)->power,
        );
    }

    public function test_a_inizio_stagione_conta_solo_la_quotazione(): void
    {
        // Nessuna statistica esiste ancora: l'unico segnale è il listone, che
        // è esattamente ciò che si vuole per la prima giornata.
        $league = $this->makeLeague();

        $scarso = $this->makePlayer('A', quotazione: 5.0);
        $medio = $this->makePlayer('A', quotazione: 15.0);
        $fenomeno = $this->makePlayer('A', quotazione: 30.0);

        $valutati = $this->updater->update($league, 1);

        $this->assertSame(3, $valutati);
        $this->assertSame(1, $this->powerOf($fenomeno, 1)->rank);
        $this->assertSame(2, $this->powerOf($medio, 1)->rank);
        $this->assertSame(3, $this->powerOf($scarso, 1)->rank);
    }

    public function test_la_quotazione_pesa_dentro_il_ruolo_non_in_assoluto(): void
    {
        // Il portiere più caro del listone costa meno di un attaccante medio.
        // Senza normalizzare per reparto, nessun portiere entrerebbe mai nelle
        // fasce alte e il pool resterebbe senza portieri pregiati.
        $league = $this->makeLeague();

        $portiereTop = $this->makePlayer('P', quotazione: 8.0);
        $portiereScarso = $this->makePlayer('P', quotazione: 3.0);
        $attaccanteTop = $this->makePlayer('A', quotazione: 40.0);
        $attaccanteScarso = $this->makePlayer('A', quotazione: 12.0);

        $this->updater->update($league, 1);

        // I due migliori del proprio reparto stanno pari, pur avendo
        // quotazioni lontanissime.
        $this->assertSame(
            $this->powerOf($portiereTop, 1)->power,
            $this->powerOf($attaccanteTop, 1)->power,
        );

        $this->assertGreaterThan(
            $this->powerOf($portiereScarso, 1)->power,
            $this->powerOf($portiereTop, 1)->power,
        );

        $this->assertSame(
            $this->powerOf($portiereScarso, 1)->power,
            $this->powerOf($attaccanteScarso, 1)->power,
        );
    }

    // ───────────────────── rendimento e trend ─────────────────────

    public function test_il_rendimento_ribalta_la_classifica_e_il_trend_lo_racconta(): void
    {
        $league = $this->makeLeague();

        $x = $this->makePlayer('A');
        $y = $this->makePlayer('A');
        $z = $this->makePlayer('A');

        $this->makePerformance($x, 1, 12.0);
        $this->makePerformance($y, 1, 8.0);
        $this->makePerformance($z, 1, 4.0);

        $this->makePerformance($x, 2, 2.0);
        $this->makePerformance($y, 2, 8.0);
        $this->makePerformance($z, 2, 14.0);

        $this->updater->update($league, 3);   // sui dati della 1ª
        $this->updater->update($league, 4);   // sui dati di 1ª e 2ª

        // Media dopo due giornate: X 7, Y 8, Z 9. La classifica si capovolge.
        $this->assertSame(3, $this->powerOf($x, 4)->rank);
        $this->assertSame(2, $this->powerOf($y, 4)->rank);
        $this->assertSame(1, $this->powerOf($z, 4)->rank);

        // La pastiglia di trend: positivo = posizioni guadagnate.
        $this->assertSame(-2, $this->powerOf($x, 4)->rank_delta);
        $this->assertSame(0, $this->powerOf($y, 4)->rank_delta);
        $this->assertSame(2, $this->powerOf($z, 4)->rank_delta);
    }

    public function test_il_cambio_di_fascia_viene_segnalato(): void
    {
        $league = $this->makeLeague();

        $x = $this->makePlayer('A');
        $y = $this->makePlayer('A');
        $z = $this->makePlayer('A');

        $this->makePerformance($x, 1, 12.0);
        $this->makePerformance($y, 1, 8.0);
        $this->makePerformance($z, 1, 4.0);

        $this->makePerformance($x, 2, 2.0);
        $this->makePerformance($y, 2, 8.0);
        $this->makePerformance($z, 2, 14.0);

        $this->updater->update($league, 3);
        $this->updater->update($league, 4);

        // Su tre giocatori una sola carta sta sopra il taglio delle Rare.
        $this->assertSame(Tier::Rara->value, $this->powerOf($x, 3)->tier);
        $this->assertSame(Tier::Rara->value, $this->powerOf($z, 4)->tier);

        $this->assertTrue($this->powerOf($x, 4)->tier_changed);
        $this->assertTrue($this->powerOf($z, 4)->tier_changed);
        $this->assertFalse($this->powerOf($y, 4)->tier_changed);
    }

    public function test_alla_prima_valutazione_non_esiste_trend(): void
    {
        $league = $this->makeLeague();
        $solo = $this->makePlayer('A');

        $this->updater->update($league, 1);

        $this->assertNull($this->powerOf($solo, 1)->rank_delta);
        $this->assertFalse($this->powerOf($solo, 1)->tier_changed);
    }

    // ───────────────────── titolarità e rischio ─────────────────────

    public function test_chi_non_gioca_scivola_sotto_a_parita_di_voto(): void
    {
        // Stesso voto quando gioca, ma uno dei due è in campo la metà del
        // tempo: la titolarità è ciò che separa il titolare dalla riserva
        // che segna quando entra.
        $league = $this->makeLeague();

        $titolare = $this->makePlayer('A');
        $riserva = $this->makePlayer('A');

        foreach ([1, 2] as $giornata) {
            $this->makePerformance($titolare, $giornata, 7.0, minutes: 90);
            $this->makePerformance($riserva, $giornata, 7.0, minutes: 20);
        }

        $this->updater->update($league, 4);

        $this->assertSame(1, $this->powerOf($titolare, 4)->rank);
        $this->assertSame(2, $this->powerOf($riserva, 4)->rank);
    }

    public function test_l_assenza_recente_pesa_come_rischio(): void
    {
        $league = $this->makeLeague();

        $sano = $this->makePlayer('A');
        $infortunato = $this->makePlayer('A');

        $this->makePerformance($sano, 1, 7.0);
        $this->makePerformance($sano, 2, 7.0);

        // Stessa media, ma la seconda giornata l'ha saltata.
        $this->makePerformance($infortunato, 1, 7.0);
        $this->makePerformance($infortunato, 2, null, minutes: 0);

        $this->updater->update($league, 4);

        $this->assertGreaterThan(
            $this->powerOf($infortunato, 4)->power,
            $this->powerOf($sano, 4)->power,
        );
    }

    // ───────────────────── rieseguibilità ─────────────────────

    public function test_il_ricalcolo_sovrascrive_invece_di_duplicare(): void
    {
        $league = $this->makeLeague();
        $giocatore = $this->makePlayer('A');

        $this->updater->update($league, 3);
        $this->updater->update($league, 3);

        $this->assertSame(1, PlayerPower::where('player_id', $giocatore->id)
            ->where('matchday', 3)
            ->count());
    }

    public function test_i_giocatori_disattivati_restano_fuori_dal_listone(): void
    {
        $league = $this->makeLeague();

        $this->makePlayer('A');
        $ritirato = $this->makePlayer('A');
        PlayerSeason::where('player_id', $ritirato->id)->update(['active' => false]);

        $this->assertSame(1, $this->updater->update($league, 1));
        $this->assertSame(0, PlayerPower::where('player_id', $ritirato->id)->count());
    }
}
