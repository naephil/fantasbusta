<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il gettone che apre le iscrizioni di UN gruppo.
 *
 * Sta sulla lega e non in configurazione perché è la lega la cosa in cui ci si
 * iscrive: l'indirizzo `/registra/{gettone}` dice già da sé dove si entra, e
 * due gruppi sullo stesso sito non possono rubarsi gli iscritti per sbaglio.
 *
 * ⚠️ `null` non è «gettone da generare»: è **iscrizioni chiuse**. Una colonna
 * sola porta entrambe le informazioni — se c'è il gettone la porta è aperta, se
 * non c'è la pagina risponde 404 — e non c'è modo di lasciarle in disaccordo,
 * come succederebbe con un gettone più un flag `aperta` da tenere allineati.
 *
 * Per questo il default è `null`: dopo la migrazione nessun gruppo ha le
 * iscrizioni aperte, e aprirle resta un gesto esplicito dell'admin. Il contrario
 * — generarlo qui per tutti — spalancherebbe le iscrizioni a ogni lega esistente
 * nel momento in cui si pubblica, che è esattamente quello che nessuno si
 * aspetta da una migrazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $t) {
            // Unique: è la chiave con cui si cerca la lega da un indirizzo
            // pubblico. L'indice serve alla ricerca, il vincolo esclude che due
            // gruppi finiscano sullo stesso link.
            $t->string('registration_token', 64)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $t) {
            $t->dropUnique(['registration_token']);
            $t->dropColumn('registration_token');
        });
    }
};
