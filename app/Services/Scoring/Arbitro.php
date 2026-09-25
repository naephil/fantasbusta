<?php

namespace App\Services\Scoring;

use App\Models\LeagueSeason;

/**
 * Chi decide come finisce una sfida. Uno solo, per campionato e coppe.
 *
 * Prima questa regola stava scritta due volte — in StandingsUpdater e in
 * FormatoBase — ed è esattamente il caso che il commento di FormatoBase temeva:
 * due copie che prima o poi assegnano esiti diversi allo stesso punteggio,
 * senza che nessuno capisca perché la coppa dica una cosa e il campionato
 * un'altra. Adesso la copia è una.
 *
 * ── I due modi di finire una giornata ──
 *
 * **A gol**, come il fantacalcio vero: i fantapunti si convertono in reti e si
 * confronta 2–1, non 74,5 contro 71,0. È il modo di partenza perché è quello
 * che tutti si aspettano, ed è più clemente: chi fa una buona giornata contro
 * uno che ne fa una ottima perde 1–2, non «perde e basta» per mezzo punto.
 *
 * **A scarto**, il sistema di prima: vince chi ha più fantapunti, e sotto una
 * soglia di scarto è pari. Resta disponibile perché è più severo e c'è chi lo
 * preferisce — ogni gruppo gioca come vuole.
 */
final class Arbitro
{
    public function __construct(private readonly Settings $settings) {}

    public static function per(LeagueSeason $stagione): self
    {
        return new self(Settings::for($stagione));
    }

    /**
     * Quante reti valgono questi fantapunti.
     *
     * La scala del fantacalcio: sotto la prima soglia zero, alla soglia una,
     * e poi una in più ogni `passo`. Coi valori di partenza — 66 e 6 — fa
     * 66→1, 72→2, 78→3, e una giornata da 90 finisce 5.
     *
     * ⚠️ Il conto si fa in centesimi interi, non in virgola mobile. Un totale
     * di 72,00 che in binario diventa 71,999999 darebbe un gol invece di due,
     * e sarebbe un risultato sbagliato che capita una volta ogni tanto senza
     * che si riesca a riprodurlo — il tipo di errore peggiore da inseguire.
     */
    public function gol(float $fantapunti): int
    {
        $totale = (int) round($fantapunti * 100);
        $prima = (int) round($this->settings->primaSogliaGol() * 100);
        $passo = (int) round($this->settings->passoGol() * 100);

        if ($totale < $prima) {
            return 0;
        }

        // Un passo a zero significherebbe infinite reti alla prima soglia. La
        // validazione lo esclude già, ma qui è una divisione: senza guardia
        // sarebbe un DivisionByZeroError a metà calcolo di una giornata.
        if ($passo <= 0) {
            return 1;
        }

        return 1 + intdiv($totale - $prima, $passo);
    }

    /** Come finisce la sfida fra due totali di formazione. */
    public function esito(float $casa, float $fuori): EsitoSfida
    {
        $p = $this->settings->puntiSfida();

        if (! $this->settings->golAttivi()) {
            // Il pareggio scatta SOTTO la soglia, non a soglia raggiunta: con
            // `soglia = 2` uno scarto di esattamente due fantapunti è vittoria.
            [$pc, $pf] = abs($casa - $fuori) < $this->settings->sogliaPareggio()
                ? [$p['pareggio'], $p['pareggio']]
                : ($casa > $fuori
                    ? [$p['vittoria'], $p['sconfitta']]
                    : [$p['sconfitta'], $p['vittoria']]);

            return new EsitoSfida(null, null, $pc, $pf);
        }

        $golCasa = $this->gol($casa);
        $golFuori = $this->gol($fuori);

        // A gol il pareggio è pareggio e basta: nessuna soglia di scarto, che
        // qui non avrebbe senso — a decidere non sono più i decimali, e due
        // squadre nella stessa fascia hanno davvero fatto la stessa giornata.
        [$pc, $pf] = $golCasa === $golFuori
            ? [$p['pareggio'], $p['pareggio']]
            : ($golCasa > $golFuori
                ? [$p['vittoria'], $p['sconfitta']]
                : [$p['sconfitta'], $p['vittoria']]);

        return new EsitoSfida($golCasa, $golFuori, $pc, $pf);
    }
}
