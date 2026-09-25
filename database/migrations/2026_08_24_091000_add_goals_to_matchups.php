<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il risultato in reti di una sfida.
 *
 * Colonne nuove e non un riuso di `home_score`, che porta già i punti di
 * classifica: sono due numeri diversi — 2–1 è il risultato, 3–0 è quello che
 * finisce in classifica — e schiacciarli su una colonna sola renderebbe
 * impossibile mostrare l'uno senza ricalcolare l'altro.
 *
 * ⚠️ `null` significa «sfida giocata col sistema a scarto», non «zero reti».
 * Le sfide chiuse prima di questa migrazione restano a null ed è giusto così:
 * sono state decise dai fantapunti, e inventare loro un risultato in gol
 * riscriverebbe a posteriori una classifica che il gruppo ha già letto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matchups', function (Blueprint $t) {
            $t->unsignedTinyInteger('home_goals')->nullable()->after('away_points');
            $t->unsignedTinyInteger('away_goals')->nullable()->after('home_goals');
        });
    }

    public function down(): void
    {
        Schema::table('matchups', function (Blueprint $t) {
            $t->dropColumn(['home_goals', 'away_goals']);
        });
    }
};
