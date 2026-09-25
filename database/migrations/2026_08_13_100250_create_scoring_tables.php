<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Punteggio e rarità, ricalcolati dopo ogni giornata.
 *
 * ⚠️ Appartengono alla **stagione di lega**, non all'annata, e non è ridondanza.
 * Bonus, malus e pesi del power sono tarabili per gruppo e per stagione: lo
 * stesso giocatore nella stessa giornata di Serie A vale diversamente a seconda
 * di chi lo schiera. Condividere queste righe fra i gruppi significherebbe che
 * l'ultimo a ricalcolare sovrascrive i voti di tutti gli altri — in silenzio,
 * perché nessuna chiave se ne accorgerebbe.
 *
 * Le tabelle davvero condivisibili sono quelle a monte: `player_stats` registra
 * ciò che è successo in campo — minuti, gol, cartellini — e quello è uguale per
 * tutti. Da lì in poi comincia l'interpretazione, e l'interpretazione è di chi
 * gioca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_scores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('player_id');
            $t->unsignedSmallInteger('matchday');

            // NULL quando il giocatore è senza voto: fa scattare la sostituzione
            // automatica in fase di calcolo formazione.
            $t->decimal('voto_base', 4, 2)->nullable();
            $t->decimal('bonus', 4, 2)->default(0);
            $t->decimal('malus', 4, 2)->default(0);
            $t->decimal('fantavoto', 5, 2)->nullable();

            $t->timestamps();

            $t->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();

            $t->unique(['league_season_id', 'player_id', 'matchday']);
            $t->index(['league_season_id', 'matchday']);
        });

        Schema::create('player_power', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('player_id');

            // Giornata PER CUI vale questo power, non quella da cui deriva.
            // Il draft della giornata N+1 legge le righe con matchday = N+1,
            // calcolate sui dati fino a N-1.
            $t->unsignedSmallInteger('matchday');

            $t->decimal('power', 6, 3);

            // Congelato qui e ricopiato sulla carta al momento della pesca.
            $t->enum('tier', ['comune', 'rara', 'epica', 'leggendaria']);

            // Per la pastiglia di trend sulla carta: posizioni guadagnate o perse
            // nella classifica di power rispetto alla giornata precedente.
            $t->smallInteger('rank')->nullable();
            $t->smallInteger('rank_delta')->nullable();
            $t->boolean('tier_changed')->default(false);

            $t->timestamps();

            $t->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();

            $t->unique(['league_season_id', 'player_id', 'matchday']);
            $t->index(['league_season_id', 'matchday', 'tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_power');
        Schema::dropIfExists('player_scores');
    }
};
