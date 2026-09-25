<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tornei: competizioni parallele al campionato, dentro una stagione di lega.
 *
 * Il formato è una stringa e non un enum di database, ed è deliberato:
 * aggiungerne uno nuovo deve costare una classe e una riga di configurazione,
 * non una migration. Le regole del singolo formato vivono in `settings`, che
 * ogni formato interpreta a modo suo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();

            $t->string('name');
            $t->string('format');

            $t->enum('state', ['bozza', 'in_corso', 'concluso'])->default('bozza');

            // Giornata di Serie A in cui comincia. Un torneo non ha un
            // calendario proprio: si appoggia alle giornate vere, perché i
            // punteggi vengono da lì.
            $t->unsignedSmallInteger('start_matchday');

            $t->json('settings')->nullable();

            $t->foreignId('winner_manager_id')->nullable()->constrained('managers')->nullOnDelete();
            $t->timestamps();

            $t->index(['league_season_id', 'state']);
        });

        Schema::create('tournament_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $t->foreignId('manager_id')->constrained()->cascadeOnDelete();

            // L'ordine di testa di serie: decide gli accoppiamenti del primo
            // turno e fa da ultimo spareggio quando tutto il resto è pari.
            $t->unsignedSmallInteger('seed');

            // Solo per i formati che raggruppano.
            $t->string('girone', 8)->nullable();

            $t->enum('state', ['attivo', 'eliminato'])->default('attivo');
            $t->unsignedSmallInteger('eliminated_matchday')->nullable();

            $t->timestamps();

            $t->unique(['tournament_id', 'manager_id']);
            $t->index(['tournament_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_entries');
        Schema::dropIfExists('tournaments');
    }
};
