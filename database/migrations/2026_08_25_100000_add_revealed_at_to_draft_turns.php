<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando il manager ha VISTO uscire le sue carte.
 *
 * ⚠️ Non è un doppione di `opened_at`, e la distinzione è tutto il punto: una
 * busta può essere stata aperta senza che nessuno guardasse. Succede in due modi
 * — il turno scade e sbusta il cron, oppure il manager ha acceso lo sbustamento
 * automatico apposta per non doverci essere — e in entrambi i casi le carte
 * finiscono in rosa mentre la parte più bella del gioco va in scena per nessuno.
 *
 * `opened_by` non basta a dirlo: con lo sbustamento automatico vale 'manager',
 * perché la scelta è stata sua, ma davanti allo schermo non c'era. Questa
 * colonna registra l'unica cosa che conta per l'animazione — se quegli occhi
 * c'erano — ed è per questo che la scrive solo il controller, che è l'unico
 * punto del codice dove dall'altra parte c'è davvero un browser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draft_turns', function (Blueprint $t) {
            $t->dateTime('revealed_at')->nullable()->after('opened_by');
        });
    }

    public function down(): void
    {
        Schema::table('draft_turns', function (Blueprint $t) {
            $t->dropColumn('revealed_at');
        });
    }
};
