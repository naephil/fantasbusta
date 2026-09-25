<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qual è la giornata COMINCIATA, cioè quella in cui si sta giocando.
 *
 * ⚠️ Non è deducibile da niente di ciò che c'è già, e questo è il motivo per
 * cui serve una colonna. «Giocata» lo dicono le classifiche, «da giocare» lo
 * dice il calendario, ma il momento in mezzo — le partite sono cominciate, i
 * voti arrivano alla spicciolata, la classifica non si muove ancora — non
 * aveva nessun posto dove esistere.
 *
 * Prima quel momento veniva approssimato con la scadenza del draft, ed era
 * un'approssimazione che si vedeva: la finestra del draft è una quota calcolata
 * fra due primi fischi, quindi «la formazione si blocca» finiva per dipendere
 * da un conto sulle date invece che da un fatto. Adesso è un fatto, e lo
 * dichiara l'amministratore.
 *
 * Nullo finché la stagione non ne ha iniziata nemmeno una.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('league_seasons', function (Blueprint $t) {
            $t->unsignedSmallInteger('started_matchday')->nullable()->after('start_matchday');
        });
    }

    public function down(): void
    {
        Schema::table('league_seasons', function (Blueprint $t) {
            $t->dropColumn('started_matchday');
        });
    }
};
