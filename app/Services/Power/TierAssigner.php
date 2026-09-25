<?php

namespace App\Services\Power;

use App\Enums\Tier;

/**
 * Dal posto in classifica alla rarità della carta.
 *
 * I tier si assegnano per PERCENTILE e non per soglia assoluta sul power: così
 * la piramide resta costante anche quando il livello medio del campionato si
 * sposta. Con una soglia fissa, una giornata di gol a raffica promuoverebbe
 * mezzo listone a Epica e svuoterebbe di senso la rarità.
 *
 * Vedi docs/DESIGN.md §2.2.
 */
final class TierAssigner
{
    /**
     * Il confronto avviene su POSIZIONI intere e non su percentuali.
     *
     * Confrontare `rank / total <= soglia` sembra equivalente e non lo è: le
     * quote non hanno una rappresentazione esatta in virgola mobile, e su una
     * popolazione dove il confine cade esatto — rank 45 su 100 — la somma
     * 0.03 + 0.12 + 0.30 finisce un ulp sotto 0.45 e declassa una carta che
     * doveva essere Rara. Convertire la quota in un numero di posizioni toglie
     * il problema alla radice e dice anche una cosa più utile: quante carte di
     * quel tier esistono davvero.
     *
     * @param  int  $rank  posizione 1-based nella classifica di power
     * @param  int  $total  quanti giocatori concorrono
     */
    public static function forRank(int $rank, int $total): string
    {
        if ($total < 1) {
            return Tier::Comune->value;
        }

        $quotaCumulata = 0.0;

        foreach (Tier::distribution() as $tier => $quota) {
            $quotaCumulata += $quota;

            if ($rank <= (int) floor($quotaCumulata * $total + 1e-9)) {
                return $tier;
            }
        }

        return Tier::Comune->value;
    }
}
