<?php

namespace Tests\Unit;

use App\Enums\Tier;
use App\Services\Power\Normalizer;
use App\Services\Power\PowerFormula;
use App\Services\Power\TierAssigner;
use App\Services\Scoring\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Power score e piramide delle rarità, verificati a tavolino.
 */
class PowerTest extends TestCase
{
    // ───────────────────────── normalizzazione ─────────────────────────

    public function test_il_minmax_porta_gli_estremi_a_zero_e_uno(): void
    {
        $out = Normalizer::minMax([1 => 10.0, 2 => 20.0, 3 => 30.0]);

        $this->assertSame(0.0, $out[1]);
        $this->assertSame(0.5, $out[2]);
        $this->assertSame(1.0, $out[3]);
    }

    public function test_se_valgono_tutti_uguale_nessuno_si_distingue(): void
    {
        // Mezzo punto e non zero: se la popolazione è piatta, nessuno deve
        // guadagnarci né rimetterci.
        $out = Normalizer::minMax([1 => 7.0, 2 => 7.0]);

        $this->assertSame([1 => 0.5, 2 => 0.5], $out);
    }

    public function test_la_quotazione_si_confronta_dentro_il_proprio_ruolo(): void
    {
        // Il portiere più caro del listone vale meno, in crediti, di un
        // attaccante mediocre. Confrontati in assoluto, i portieri sarebbero
        // tutti scarsi; confrontati per reparto, torna fuori chi è forte.
        $valori = [1 => 5.0, 2 => 8.0, 3 => 15.0, 4 => 30.0];
        $ruoli = [1 => 'P', 2 => 'P', 3 => 'A', 4 => 'A'];

        $out = Normalizer::minMaxByGroup($valori, $ruoli);

        $this->assertSame(1.0, $out[2]);   // miglior portiere
        $this->assertSame(1.0, $out[4]);   // miglior attaccante
        $this->assertSame(0.0, $out[1]);
        $this->assertSame(0.0, $out[3]);
    }

    // ───────────────────────── la formula ─────────────────────────

    private function formula(array $overrides = []): PowerFormula
    {
        return new PowerFormula(new Settings($overrides));
    }

    public function test_il_giocatore_perfetto_arriva_a_cento(): void
    {
        $power = $this->formula()->apply([
            'baseline' => 1.0,
            'fantamedia' => 1.0,
            'forma' => 1.0,
            'titolarita' => 1.0,
            'rischio' => 0.0,
        ]);

        // I quattro pesi positivi sommano a 1.
        $this->assertSame(100.0, $power);
    }

    public function test_il_rischio_si_sottrae(): void
    {
        $componenti = [
            'baseline' => 1.0,
            'fantamedia' => 1.0,
            'forma' => 1.0,
            'titolarita' => 1.0,
            'rischio' => 1.0,
        ];

        // Peso del rischio 0.05 → cento meno cinque.
        $this->assertSame(95.0, $this->formula()->apply($componenti));
    }

    public function test_il_power_non_scende_sotto_zero(): void
    {
        // Un giocatore fermo ai box vale poco, non vale meno di niente.
        $power = $this->formula(['power' => ['pesi' => ['rischio' => 0.9]]])
            ->apply(['baseline' => 0.0, 'rischio' => 1.0]);

        $this->assertSame(0.0, $power);
    }

    public function test_alzare_il_peso_della_forma_rende_le_carte_volatili(): void
    {
        // È il parametro che decide che gioco è: caccia al giocatore in forma,
        // oppure mercato di valori consolidati.
        $componenti = ['baseline' => 1.0, 'fantamedia' => 0.0, 'forma' => 0.0, 'titolarita' => 0.0, 'rischio' => 0.0];

        $stabile = $this->formula(['power' => ['pesi' => ['baseline' => 0.7, 'forma' => 0.05]]]);
        $volatile = $this->formula(['power' => ['pesi' => ['baseline' => 0.05, 'forma' => 0.7]]]);

        // Stesso giocatore: caro di listino ma spento. Con i pesi volatili
        // il suo blasone non lo salva.
        $this->assertSame(70.0, $stabile->apply($componenti));
        $this->assertSame(5.0, $volatile->apply($componenti));
    }

    // ───────────────────────── la piramide ─────────────────────────

    public function test_la_piramide_rispetta_i_percentili(): void
    {
        // 550 giocatori: top 3% = 16 Leggendarie, poi 12% Epiche, 30% Rare.
        $this->assertSame(Tier::Leggendaria->value, TierAssigner::forRank(1, 550));
        $this->assertSame(Tier::Leggendaria->value, TierAssigner::forRank(16, 550));
        $this->assertSame(Tier::Epica->value, TierAssigner::forRank(17, 550));
        $this->assertSame(Tier::Epica->value, TierAssigner::forRank(82, 550));
        $this->assertSame(Tier::Rara->value, TierAssigner::forRank(83, 550));
        $this->assertSame(Tier::Rara->value, TierAssigner::forRank(247, 550));
        $this->assertSame(Tier::Comune->value, TierAssigner::forRank(248, 550));
        $this->assertSame(Tier::Comune->value, TierAssigner::forRank(550, 550));
    }

    public function test_la_piramide_tiene_su_popolazioni_diverse(): void
    {
        // Per percentile e non per soglia: la forma della piramide non dipende
        // da quanti giocatori ci sono né da quanto vale il campionato.
        $this->assertSame(Tier::Leggendaria->value, TierAssigner::forRank(3, 100));
        $this->assertSame(Tier::Epica->value, TierAssigner::forRank(4, 100));
        $this->assertSame(Tier::Rara->value, TierAssigner::forRank(16, 100));
        $this->assertSame(Tier::Comune->value, TierAssigner::forRank(46, 100));
    }

    public function test_il_confine_che_cade_esatto_non_declassa_nessuno(): void
    {
        // Su cento giocatori il confine fra Rara e Comune cade esattamente al
        // 45°. In virgola mobile 0.03 + 0.12 + 0.30 sta un soffio sotto 0.45,
        // e il 45° finirebbe fra i comuni per un errore di arrotondamento.
        $this->assertSame(Tier::Rara->value, TierAssigner::forRank(45, 100));
        $this->assertSame(Tier::Comune->value, TierAssigner::forRank(46, 100));

        // Stesso confine esatto fra Epica e Rara, sempre su cento.
        $this->assertSame(Tier::Epica->value, TierAssigner::forRank(15, 100));
        $this->assertSame(Tier::Rara->value, TierAssigner::forRank(16, 100));
    }

    public function test_la_piramide_conta_le_carte_che_esistono_davvero(): void
    {
        // Su 550 giocatori: 16 Leggendarie, 66 Epiche, 165 Rare, il resto
        // Comuni. Sono i numeri della tabella di §2.2.
        $conteggio = array_count_values(array_map(
            fn (int $rank) => TierAssigner::forRank($rank, 550),
            range(1, 550),
        ));

        $this->assertSame(16, $conteggio[Tier::Leggendaria->value]);
        $this->assertSame(66, $conteggio[Tier::Epica->value]);
        $this->assertSame(165, $conteggio[Tier::Rara->value]);
        $this->assertSame(303, $conteggio[Tier::Comune->value]);
    }

    public function test_su_pochi_giocatori_le_leggendarie_semplicemente_non_escono(): void
    {
        // Il 3% di venti è zero virgola sei: la piramide non inventa un
        // fuoriclasse dove non c'è la popolazione per sostenerlo.
        $this->assertSame(Tier::Epica->value, TierAssigner::forRank(1, 20));
    }
}
