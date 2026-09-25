<?php

namespace Tests\Unit;

use App\Models\PlayerStat;
use App\Services\Scoring\FantavotoCalculator;
use App\Services\Scoring\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Il fantavoto, verificato a tavolino.
 *
 * I coefficienti stanno tutti in `Settings`, quindi il calcolo si prova senza
 * database: si costruisce la lega che serve al caso e si legge il risultato.
 */
class FantavotoTest extends TestCase
{
    private function calc(array $overrides = []): FantavotoCalculator
    {
        return new FantavotoCalculator(new Settings($overrides));
    }

    // ───────────────────────── la molla sul voto base ─────────────────────────

    public function test_la_molla_allarga_le_distanze_dal_centro(): void
    {
        $c = $this->calc();

        // Il centro resta inchiodato, il resto si allontana del 20%.
        $this->assertSame(6.0, $c->votoBase(6.0));
        $this->assertSame(7.2, $c->votoBase(7.0));
        $this->assertSame(4.8, $c->votoBase(5.0));
    }

    public function test_con_k_uguale_a_uno_la_molla_e_disattivata(): void
    {
        $c = $this->calc(['voto_base' => ['k' => 1]]);

        $this->assertSame(7.0, $c->votoBase(7.0));
        $this->assertSame(5.0, $c->votoBase(5.0));
    }

    public function test_il_clamp_regge_i_rating_anomali(): void
    {
        $c = $this->calc();

        // Senza clamp un 9.5 diventerebbe 10.2, che sulla scala non esiste.
        $this->assertSame(9.0, $c->votoBase(9.5));
        $this->assertSame(4.0, $c->votoBase(3.0));
    }

    // ───────────────────────── bonus e malus ─────────────────────────

    public function test_gol_e_assist_finiscono_nei_bonus(): void
    {
        $esito = $this->calc()->compute(['gol' => 1, 'assist' => 1], rating: 7.0, role: 'A');

        $this->assertSame(7.2, $esito['voto_base']);
        $this->assertSame(4.0, $esito['bonus']);
        $this->assertSame(0.0, $esito['malus']);
        $this->assertSame(11.2, $esito['fantavoto']);
    }

    public function test_i_cartellini_finiscono_nei_malus(): void
    {
        $esito = $this->calc()->compute(['ammonizione' => 1, 'espulsione' => 1], rating: 6.0, role: 'D');

        $this->assertSame(0.0, $esito['bonus']);
        $this->assertSame(1.5, $esito['malus']);
        $this->assertSame(4.5, $esito['fantavoto']);
    }

    public function test_il_gol_subito_riguarda_solo_il_portiere(): void
    {
        $c = $this->calc();

        // Stesso evento, stesso conteggio, ruoli diversi: l'override per ruolo
        // è ciò che tiene il caso speciale nei dati invece che nel codice.
        $this->assertSame(2.0, $c->compute(['gol_subito' => 2], 6.0, 'P')['malus']);
        $this->assertSame(0.0, $c->compute(['gol_subito' => 2], 6.0, 'D')['malus']);
    }

    // ───────────────────────── riconfigurazione ─────────────────────────

    public function test_un_bonus_puo_diventare_un_malus_cambiando_segno(): void
    {
        // Nessun evento è un premio per costruzione: vale ciò che dice il suo
        // segno, e la ripartizione fra le colonne segue da sola.
        $c = $this->calc(['eventi' => ['gol' => ['default' => -3]]]);

        $esito = $c->compute(['gol' => 2], rating: 6.0, role: 'A');

        $this->assertSame(0.0, $esito['bonus']);
        $this->assertSame(6.0, $esito['malus']);
        $this->assertSame(0.0, $esito['fantavoto']);
    }

    public function test_un_malus_puo_diventare_un_bonus(): void
    {
        $c = $this->calc(['eventi' => ['ammonizione' => ['default' => 0.5]]]);

        $esito = $c->compute(['ammonizione' => 1], rating: 6.0, role: 'C');

        $this->assertSame(0.5, $esito['bonus']);
        $this->assertSame(0.0, $esito['malus']);
    }

    public function test_il_gol_puo_valere_diversamente_per_reparto(): void
    {
        $c = $this->calc(['eventi' => ['gol' => ['default' => 3, 'D' => 5]]]);

        $this->assertSame(5.0, $c->compute(['gol' => 1], 6.0, 'D')['bonus']);
        $this->assertSame(3.0, $c->compute(['gol' => 1], 6.0, 'A')['bonus']);
    }

    public function test_l_override_di_un_solo_evento_non_azzera_gli_altri(): void
    {
        // La sovrascrittura è per foglia: ritoccare il gol non deve far
        // sparire l'assist dalla tabella.
        $c = $this->calc(['eventi' => ['gol' => ['default' => 10]]]);

        $this->assertSame(11.0, $c->compute(['gol' => 1, 'assist' => 1], 6.0, 'A')['bonus']);
    }

    // ───────────────────────── lettura delle statistiche ─────────────────────────

    private function stat(array $attrs = []): PlayerStat
    {
        return new PlayerStat($attrs + [
            'minutes' => 90,
            'rating' => 7.0,
            'goals' => 0,
            'assists' => 0,
            'yellow' => 0,
            'red' => 0,
            'own_goals' => 0,
            'pen_scored' => 0,
            'pen_missed' => 0,
            'pen_saved' => 0,
            'goals_conceded' => 0,
        ]);
    }

    public function test_i_rigori_sono_scorporati_dai_gol(): void
    {
        // L'API conta il rigore anche fra i gol totali: due gol di cui uno su
        // rigore arrivano come goals = 2, pen_scored = 1.
        $eventi = $this->stat(['goals' => 2, 'pen_scored' => 1])->events();

        $this->assertSame(1, $eventi['gol']);
        $this->assertSame(1, $eventi['rigore_segnato']);
    }

    public function test_col_rigore_pagato_meno_lo_scorporo_si_vede(): void
    {
        // È il ritocco più probabile in assoluto, ed è esattamente quello che
        // senza scorporo darebbe il risultato sbagliato: sommando i due
        // coefficienti su un gol contato due volte verrebbero 8 invece di 5.
        $c = $this->calc(['eventi' => ['rigore_segnato' => ['default' => 2]]]);

        $esito = $c->forStat($this->stat(['goals' => 2, 'pen_scored' => 1]), 'A');

        $this->assertSame(5.0, $esito['bonus']);
    }

    public function test_finche_i_due_coefficienti_coincidono_lo_scorporo_e_invisibile(): void
    {
        $esito = $this->calc()->forStat($this->stat(['goals' => 2, 'pen_scored' => 1]), 'A');

        $this->assertSame(6.0, $esito['bonus']);
    }

    // ───────────────────────── senza voto ─────────────────────────

    public function test_zero_minuti_e_senza_voto_anche_col_rating_presente(): void
    {
        // Capita: l'API restituisce un rating per chi è rimasto in panchina.
        $esito = $this->calc()->forStat($this->stat(['minutes' => 0, 'rating' => 6.5]), 'C');

        $this->assertNull($esito['fantavoto']);
    }

    public function test_senza_rating_il_fantavoto_resta_nullo(): void
    {
        // È quel nullo a far scattare la sostituzione automatica: un giocatore
        // con un gol ma senza voto non porta comunque punti.
        $esito = $this->calc()->compute(['gol' => 1], rating: null, role: 'A');

        $this->assertNull($esito['voto_base']);
        $this->assertNull($esito['fantavoto']);
        $this->assertSame(3.0, $esito['bonus']);
    }
}
