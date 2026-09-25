<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le squadre governate dal sistema.
 *
 * Servono a riempire una lega che non arriva a dodici: con quattro persone il
 * calendario dà tre sfide a testa e il pool del draft resta pieno per due
 * terzi, cioè si prova un gioco che non somiglia a quello vero.
 *
 * Un bot non è un manager distratto: sbusta appena tocca a lui e schiera sempre
 * la miglior formazione possibile. Per questo **non prende la penalità da
 * dimenticanza** — quella punisce chi si scorda, e un bot non si scorda. Il
 * rovescio, da tenere presente leggendo la classifica di una prova: il bot
 * gioca sempre al meglio delle sue carte, quindi è un avversario un po' più
 * ostico della media di una lega di amici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managers', function (Blueprint $t) {
            $t->boolean('is_bot')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('managers', function (Blueprint $t) {
            $t->dropColumn('is_bot');
        });
    }
};
