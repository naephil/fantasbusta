<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Da dove vengono le statistiche di una giornata.
 *
 * Senza questa colonna una giornata simulata e una scaricata sono
 * indistinguibili, e la conseguenza non è teorica: il simulatore scrive in
 * updateOrCreate sulla stessa chiave del sync, quindi premere «Avanti» con
 * *simula* spuntato su una giornata già scaricata **cancella i voti veri**.
 *
 * E lo fa in silenzio nel modo peggiore: i gol li ridistribuisce a partire dal
 * risultato vero della partita, quindi il totale della giornata continua a
 * tornare. Solo i marcatori sono inventati, e nessuno va a ricontrollare
 * marcatore per marcatore. Su un piano da cento chiamate al giorno significa
 * buttare via dieci chiamate senza accorgersene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_stats', function (Blueprint $t) {
            // Il default è «reale» di proposito: è il valore che protegge, e
            // le righe già in tabella al momento della migrazione sono quelle
            // scaricate — il simulatore in produzione non ha ancora girato.
            $t->enum('source', ['reale', 'simulata'])->default('reale')->after('matchday');
            $t->index(['season', 'matchday', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('player_stats', function (Blueprint $t) {
            $t->dropIndex(['season', 'matchday', 'source']);
            $t->dropColumn('source');
        });
    }
};
