<?php

namespace App\Services\Calendar;

/**
 * Girone all'italiana col metodo del cerchio.
 *
 * Si immagina il primo manager fisso e tutti gli altri disposti in cerchio
 * attorno a lui: a ogni turno il cerchio ruota di un posto e le coppie sono
 * quelle che si trovano di fronte. Con n manager bastano n−1 turni perché
 * tutti abbiano incontrato tutti esattamente una volta, senza mai ripetersi e
 * senza che nessuno resti fuori.
 *
 * Logica pura sugli id: nessun accesso al database, così il calendario si può
 * verificare a tavolino prima di scriverlo.
 */
final class ScheduleGenerator
{
    /**
     * Il calendario completo, girone dopo girone.
     *
     * Dal secondo girone in poi i lati si invertono. Non cambia niente per il
     * punteggio — casa e trasferta non danno vantaggio — ma tiene il tabellone
     * simmetrico e rende ovvio a colpo d'occhio che è il ritorno.
     *
     * @param  list<int>  $managerIds
     * @return list<list<array{int,int}>> turni, ciascuno con le sue coppie
     */
    public static function build(array $managerIds, int $gironi = 2): array
    {
        if (count($managerIds) < 2 || $gironi < 1) {
            return [];
        }

        $base = self::singleRoundRobin($managerIds);
        $calendario = [];

        for ($g = 0; $g < $gironi; $g++) {
            foreach ($base as $turno) {
                $calendario[] = $g % 2 === 0
                    ? $turno
                    : array_map(fn (array $c) => [$c[1], $c[0]], $turno);
            }
        }

        return $calendario;
    }

    /**
     * Un solo girone: n−1 turni.
     *
     * Con un numero dispari di manager si aggiunge un posto vuoto, e chi ci
     * capita davanti quel turno riposa. Non dovrebbe servire — la lega è di
     * dodici — ma un abbandono a stagione in corso non deve rompere il
     * generatore.
     *
     * @param  list<int>  $ids
     * @return list<list<array{int,int}>>
     */
    private static function singleRoundRobin(array $ids): array
    {
        $ids = array_values($ids);

        if (count($ids) % 2 === 1) {
            $ids[] = null;   // il posto vuoto: chi lo incontra riposa
        }

        $n = count($ids);
        $fisso = array_shift($ids);
        $rotanti = $ids;
        $turni = [];

        for ($turno = 0; $turno < $n - 1; $turno++) {
            $cerchio = array_merge([$fisso], $rotanti);
            $coppie = [];

            for ($i = 0; $i < intdiv($n, 2); $i++) {
                [$casa, $fuori] = [$cerchio[$i], $cerchio[$n - 1 - $i]];

                if ($casa !== null && $fuori !== null) {
                    $coppie[] = [$casa, $fuori];
                }
            }

            $turni[] = $coppie;

            // La rotazione di un posto: è tutto il trucco del metodo.
            array_unshift($rotanti, array_pop($rotanti));
        }

        return $turni;
    }
}
