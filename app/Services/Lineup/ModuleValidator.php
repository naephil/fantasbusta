<?php

namespace App\Services\Lineup;

/**
 * I sette moduli canonici del fantacalcio.
 *
 * Nota di progetto: il vincolo generico "D≥3, C≥3, A≥1" NON è equivalente a
 * questo elenco, ed è la ragione per cui la garanzia della busta è più alta
 * dei minimi di schieramento. Contro-esempio:
 *
 *      rosa 1P 3D 3C 4A   → rispetta tutti i minimi
 *      3-4-3 vuole 4 C, ne ha 3      ✗
 *      3-5-2 vuole 5 C, ne ha 3      ✗
 *      4-x-x vuole 4 D, ne ha 3      ✗
 *                                    → INSCHIERABILE
 *
 * Da qui la garanzia {P:1, D:4, C:4, A:2}, che assicura almeno il 4-4-2.
 */
final class ModuleValidator
{
    /** @var array<string,array{D:int,C:int,A:int}> */
    public const MODULES = [
        '3-4-3' => ['D' => 3, 'C' => 4, 'A' => 3],
        '3-5-2' => ['D' => 3, 'C' => 5, 'A' => 2],
        '4-3-3' => ['D' => 4, 'C' => 3, 'A' => 3],
        '4-4-2' => ['D' => 4, 'C' => 4, 'A' => 2],
        '4-5-1' => ['D' => 4, 'C' => 5, 'A' => 1],
        '5-3-2' => ['D' => 5, 'C' => 3, 'A' => 2],
        '5-4-1' => ['D' => 5, 'C' => 4, 'A' => 1],
    ];

    /**
     * Garanzia minima della busta: la rete di sicurezza è il 4-4-2.
     * Un solo portiere — vedi docs/DESIGN.md §3.3 sul perché non due.
     *
     * @var array<string,int>
     */
    public const GUARANTEE = ['P' => 1, 'D' => 4, 'C' => 4, 'A' => 2];

    /**
     * Moduli effettivamente schierabili con questi effettivi.
     *
     * @param  array<string,int>  $counts  ['P'=>1,'D'=>5,'C'=>6,'A'=>3]
     * @return list<string>
     */
    public static function playable(array $counts): array
    {
        if (($counts['P'] ?? 0) < 1) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::MODULES),
            fn (string $m) => ($counts['D'] ?? 0) >= self::MODULES[$m]['D']
                           && ($counts['C'] ?? 0) >= self::MODULES[$m]['C']
                           && ($counts['A'] ?? 0) >= self::MODULES[$m]['A'],
        ));
    }

    /**
     * Il test che blocca uno scambio: nessuna delle due parti può restare
     * senza un modulo valido. Va rivalutato all'ACCETTAZIONE e non alla
     * proposta — nel frattempo la controparte può aver concluso altri scambi.
     *
     * @param  array<string,int>  $counts
     */
    public static function isFieldable(array $counts): bool
    {
        return self::playable($counts) !== [];
    }
}
