<?php

namespace App\Services\Scoring;

use App\Models\PlayerStat;

/**
 * Dal rating API-Football al fantavoto.
 *
 *     fantavoto = voto_base + bonus − malus
 *
 * Non esistono pagelle editoriali accessibili via API, quindi il voto base è
 * statistico. Vedi docs/DESIGN.md §5.3.
 */
class FantavotoCalculator
{
    public function __construct(private Settings $settings) {}

    /**
     * @return array{voto_base: ?float, bonus: float, malus: float, fantavoto: ?float}
     */
    public function forStat(PlayerStat $stat, string $role): array
    {
        return $this->compute(
            $stat->events(),
            $stat->hasVote() ? $stat->rating : null,
            $role,
        );
    }

    /**
     * Il nucleo, senza database: eventi contati, rating, ruolo.
     *
     * La ripartizione fra `bonus` e `malus` segue il SEGNO DEL RISULTATO, non
     * una classificazione fissa dell'evento. È ciò che rende sensata la
     * riconfigurazione: portare `gol` a un valore negativo lo fa comparire fra
     * i malus da solo, senza che il codice sappia nulla di quale evento
     * «dovrebbe» essere un premio.
     *
     * @param  array<string,int>  $events  quante volte è accaduto ciascun evento
     * @param  float|null  $rating  null se senza voto
     * @return array{voto_base: ?float, bonus: float, malus: float, fantavoto: ?float}
     */
    public function compute(array $events, ?float $rating, string $role): array
    {
        $bonus = 0.0;
        $malus = 0.0;

        foreach ($events as $evento => $volte) {
            if ($volte <= 0) {
                continue;
            }

            $contributo = $volte * $this->settings->coefficient($evento, $role);

            if ($contributo >= 0) {
                $bonus += $contributo;
            } else {
                $malus += -$contributo;
            }
        }

        // Senza voto i bonus si calcolano lo stesso — servono al report e al
        // power score — ma il fantavoto resta nullo, ed è quel nullo a far
        // scattare la sostituzione automatica.
        $votoBase = $rating === null ? null : $this->votoBase($rating);

        return [
            'voto_base' => $votoBase,
            'bonus' => round($bonus, 2),
            'malus' => round($malus, 2),
            'fantavoto' => $votoBase === null ? null : round($votoBase + $bonus - $malus, 2),
        ];
    }

    /**
     * La molla attorno al centro.
     *
     * I rating API-Football sono compressi: quasi tutti fra 6.0 e 7.5, perché
     * misurano la prestazione su una scala che non è quella del fantacalcio.
     * Usarli tali e quali appiattirebbe le pagelle e lascerebbe decidere tutto
     * a gol e assist. Il centro resta fermo e le distanze si moltiplicano per
     * `k`: con k = 1 la molla è disattivata.
     */
    public function votoBase(float $rating): float
    {
        ['centro' => $centro, 'k' => $k, 'min' => $min, 'max' => $max] = $this->settings->votoBaseParams();

        $voto = $centro + $k * ($rating - $centro);

        return round(max($min, min($max, $voto)), 2);
    }
}
