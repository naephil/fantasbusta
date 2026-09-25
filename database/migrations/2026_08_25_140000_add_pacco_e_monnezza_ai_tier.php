<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due fasce nuove in fondo alla piramide: «pacco» e «monnezza».
 *
 * ⚠️ E l'occasione per togliere l'`enum` dallo schema.
 *
 * L'elenco dei tier era scritto in tre DDL diversi — `player_power`, `cards`,
 * `draft_pool` — oltre che nell'enum PHP che è la fonte vera. Quattro copie
 * della stessa lista, e aggiungerne una voleva dire ricordarsi di tutte e
 * quattro: la quarta dimenticata non dà errore in sviluppo su SQLite, dove il
 * vincolo è un `check` permissivo, e salta fuori in produzione su MariaDB come
 * una riga troncata a stringa vuota.
 *
 * `App\Enums\Tier` resta l'unica fonte, il database tiene una stringa, e il
 * prossimo tier costerà una riga di PHP invece di una migration su tre tabelle.
 *
 * ⚠️ Non c'è niente da convertire nei dati: i valori esistenti — 'comune',
 * 'rara', 'epica', 'leggendaria' — sono già le stringhe giuste. Cambia solo
 * cosa il database è disposto ad accettare da qui in avanti.
 */
return new class extends Migration
{
    /** Le tre tabelle che portano una rarità congelata. */
    private const TABELLE = ['player_power', 'cards', 'draft_pool'];

    public function up(): void
    {
        foreach (self::TABELLE as $tabella) {
            Schema::table($tabella, function (Blueprint $t) {
                $t->string('tier', 20)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELLE as $tabella) {
            Schema::table($tabella, function (Blueprint $t) {
                // ⚠️ Tornando indietro le carte «pacco» e «monnezza» avrebbero
                // un valore che l'enum non ammette. Chi fa rollback deve
                // ricalcolare il power, che riassegna tutto secondo le regole
                // di allora: `power:compute` è rieseguibile apposta.
                $t->enum('tier', ['comune', 'rara', 'epica', 'leggendaria'])->change();
            });
        }
    }
};
