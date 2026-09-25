<?php

namespace App\Services\Power;

use App\Services\Scoring\Settings;

/**
 * La somma pesata di docs/DESIGN.md §2.1.
 *
 *     power = (w1·baseline + w2·fantamedia + w3·forma + w4·titolarità)
 *             × (1 − w5·rischio)
 *
 * Tutte le componenti arrivano già normalizzate 0..1, quindi il risultato sta
 * anch'esso in 0..1 e viene riportato su 0..100 per leggibilità — e per avere
 * abbastanza risoluzione da non produrre pari merito a raffica in classifica.
 */
final class PowerFormula
{
    public function __construct(private Settings $settings) {}

    /**
     * @param  array<string,float>  $componenti  normalizzate 0..1
     */
    public function apply(array $componenti): float
    {
        $pesi = $this->settings->pesiPower();

        $valore = $pesi['baseline'] * ($componenti['baseline'] ?? 0)
            + $pesi['fantamedia'] * ($componenti['fantamedia'] ?? 0)
            + $pesi['forma'] * ($componenti['forma'] ?? 0)
            + $pesi['titolarita'] * ($componenti['titolarita'] ?? 0);

        // ⚠️ Il rischio SCONTA, non sottrae — e la differenza si vede solo in
        // fondo alla classifica, che è dove conta.
        //
        // Sottraendolo, chiunque valesse meno del rischio finiva sotto zero e
        // veniva schiacciato su 0.000 da un `max()`. Su un listone vero sono
        // centinaia di giocatori — quotazione bassa e nessun minuto giocato,
        // cioè tutta la panchina del campionato — tutti esattamente in parità.
        // E in parità la classifica la decide lo spareggio sull'id: «chi è
        // Rara e chi è Comune» diventava un sorteggio proprio nella fascia più
        // numerosa del pool.
        //
        // Come sconto invece l'ordine si conserva: chi non gioca vale poco ma
        // continua a valere in proporzione a quanto è quotato, che è l'unica
        // cosa che di lui si sa. Il risultato non può mai essere negativo
        // senza bisogno di tagliarlo, e in cima cambia pochissimo — un power
        // da 80 passa da 75 a 76.
        $sconto = 1 - $pesi['rischio'] * ($componenti['rischio'] ?? 0);

        return round($valore * max(0.0, $sconto) * 100, 3);
    }
}
