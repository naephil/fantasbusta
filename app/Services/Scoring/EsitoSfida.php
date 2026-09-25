<?php

namespace App\Services\Scoring;

/**
 * Com'è finita una sfida: il risultato in gol e i punti di classifica.
 *
 * I gol sono `null` quando la lega gioca a scarto di fantapunti invece che a
 * gol — non zero. `0 – 0` è un risultato vero e frequente, e confonderlo con
 * «questa lega non usa i gol» significherebbe mostrare un pareggio a reti
 * inviolate dove non c'è nessuna rete da inviolare.
 */
final class EsitoSfida
{
    public function __construct(
        public readonly ?int $golCasa,
        public readonly ?int $golFuori,
        public readonly int $puntiCasa,
        public readonly int $puntiFuori,
    ) {}

    public function aGol(): bool
    {
        return $this->golCasa !== null;
    }
}
