<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * La prova che il cron gira davvero.
 *
 * Il cron è l'unico pezzo del sistema che vive fuori dall'applicazione: sta in
 * una riga di crontab sul server, e se quella riga manca — o ha il percorso di
 * PHP sbagliato, o dei ritorni a capo DOS che Hostpoint rifiuta — non succede
 * niente e nessuno se ne accorge. Non c'è nessun errore da leggere: il draft
 * semplicemente non avanza, e sembra che il gioco sia lento.
 *
 * Quindi lo scheduler lascia un segno a ogni giro. Se il segno è fresco il cron
 * c'è; se è vecchio si è fermato; se non c'è mai stato non è mai partito. È
 * l'unico modo di distinguere i tre casi senza collegarsi al server.
 */
class Battito
{
    private const CHIAVE = 'battito-scheduler';

    /**
     * Oltre questa soglia il cron si considera fermo.
     *
     * Largo di proposito: su Hostpoint il cron minimo è ogni cinque minuti, e
     * un allarme che scatta a sei sarebbe un allarme che scatta sempre.
     */
    public const SOGLIA_MINUTI = 15;

    public static function segna(): void
    {
        // `forever` e non una scadenza: un battito scaduto e uno mai avvenuto
        // sono due situazioni diverse, e vanno distinte proprio quando il cron
        // è fermo da un pezzo — cioè quando la differenza conta di più.
        Cache::forever(self::CHIAVE, CarbonImmutable::now()->toIso8601String());
    }

    public static function ultimo(): ?CarbonImmutable
    {
        $segno = Cache::get(self::CHIAVE);

        return $segno ? CarbonImmutable::parse($segno) : null;
    }

    public static function vivo(): bool
    {
        return (bool) self::ultimo()?->greaterThan(CarbonImmutable::now()->subMinutes(self::SOGLIA_MINUTI));
    }

    /**
     * Come sta il cron, in una parola: 'vivo', 'fermo' o 'mai'.
     */
    public static function stato(): string
    {
        if (! self::ultimo()) {
            return 'mai';
        }

        return self::vivo() ? 'vivo' : 'fermo';
    }

    /**
     * Da quanto, detto come lo direbbe una persona.
     */
    public static function eta(): ?string
    {
        return self::ultimo()?->diffForHumans(['short' => false]);
    }

    public static function dimentica(): void
    {
        Cache::forget(self::CHIAVE);
    }
}
