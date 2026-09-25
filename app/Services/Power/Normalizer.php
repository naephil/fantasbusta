<?php

namespace App\Services\Power;

/**
 * Riporta grandezze incomparabili sulla stessa scala 0..1.
 *
 * Serve perché il power score somma cose che non hanno niente in comune —
 * quotazioni in crediti, fantamedie in decimi di voto, percentuali di minuti —
 * e senza normalizzare il peso reale di ciascun addendo lo deciderebbe l'unità
 * di misura invece dei coefficienti in `leagues.settings`.
 */
final class Normalizer
{
    /**
     * Min-max sull'intera popolazione.
     *
     * Quando tutti valgono uguale il risultato è 0.5 e non 0: nessuno si
     * distingue, quindi nessuno deve guadagnarci né rimetterci.
     *
     * @param  array<int|string,float>  $values
     * @return array<int|string,float>
     */
    public static function minMax(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $min = min($values);
        $max = max($values);

        if ($max === $min) {
            return array_map(fn () => 0.5, $values);
        }

        return array_map(fn (float $v) => ($v - $min) / ($max - $min), $values);
    }

    /**
     * Min-max separato per gruppo.
     *
     * È il modo di normalizzare la quotazione per ruolo: il listone assegna a
     * un portiere numeri che non stanno sulla stessa scala di un attaccante,
     * quindi confrontarli in assoluto direbbe solo che gli attaccanti costano
     * di più. Confrontati dentro il proprio reparto dicono chi è forte.
     *
     * @param  array<int|string,float>  $values
     * @param  array<int|string,string>  $groups  stessa chiave => gruppo di appartenenza
     * @return array<int|string,float>
     */
    public static function minMaxByGroup(array $values, array $groups): array
    {
        $out = [];

        foreach (array_unique($groups) as $group) {
            $subset = array_intersect_key($values, array_filter($groups, fn ($g) => $g === $group));

            $out += self::minMax($subset);
        }

        return $out;
    }
}
