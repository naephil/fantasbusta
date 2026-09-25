<?php

namespace App\Services\Trade;

use App\Services\Lineup\ModuleValidator;

/**
 * Il pavimento rigido degli scambi.
 *
 * È l'unico vincolo del trading: non c'è tetto massimo di rosa e gli scambi
 * sbilanciati (sette carte per una) sono permessi. Lo squilibrio si autoregola
 * senza bisogno di regole aggiuntive — chi cede sette carte per una Leggendaria
 * consolida qualità e si assottiglia, chi accetta si riempie di panchina che
 * non schiererà mai. Il vincolo degli undici schierabili fa da solo tutto il
 * lavoro di bilanciamento. Vedi docs/DESIGN.md §4.
 *
 * Logica pura sugli effettivi per ruolo: nessun accesso al database, così è
 * verificabile a tavolino e riusabile sia in validazione sia in anteprima.
 */
final class RosterFloor
{
    /** Un portiere più dieci di movimento. */
    public const MIN_CARDS = 11;

    /**
     * Perché la rosa resterebbe inschierabile, o null se lo scambio passa.
     *
     * Le tre condizioni sono ridondanti fra loro — `isFieldable` implica già
     * sia il portiere sia gli undici — ma vanno tenute distinte perché il
     * motivo finisce sotto gli occhi del manager, e «resteresti senza portiere»
     * si capisce, «nessun modulo componibile» no.
     *
     * @param  array<string,int>  $counts  effettivi per ruolo DOPO lo scambio
     */
    public static function violation(array $counts): ?string
    {
        $total = array_sum($counts);

        if ($total < self::MIN_CARDS) {
            return "resterebbe con {$total} carte, meno delle ".self::MIN_CARDS.' necessarie';
        }

        if (($counts['P'] ?? 0) < 1) {
            return 'resterebbe senza portiere';
        }

        if (! ModuleValidator::isFieldable($counts)) {
            return 'non potrebbe comporre nessuno dei sette moduli';
        }

        return null;
    }

    /**
     * Effettivi risultanti dallo scambio, senza toccare il database.
     *
     * Calcolare gli effettivi a mente invece di applicare lo scambio e poi
     * annullarlo tiene la validazione fuori dalla transazione di scrittura:
     * un rifiuto non deve mai passare per un rollback.
     *
     * @param  array<string,int>  $counts  effettivi correnti
     * @param  list<string>  $out  ruoli delle carte cedute
     * @param  list<string>  $in  ruoli delle carte ricevute
     * @return array<string,int>
     */
    public static function apply(array $counts, array $out, array $in): array
    {
        foreach ($out as $role) {
            $counts[$role] = ($counts[$role] ?? 0) - 1;
        }

        foreach ($in as $role) {
            $counts[$role] = ($counts[$role] ?? 0) + 1;
        }

        return $counts;
    }
}
