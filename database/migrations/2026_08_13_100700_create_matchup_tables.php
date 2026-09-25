<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il calendario degli scontri diretti fra manager.
 *
 * Distinto da `fixtures`, che sono le partite di Serie A: qui ci sono le sfide
 * del gruppo. Con 12 manager un girone completo dura 11 turni, e due gironi ne
 * occupano 22 delle 38 giornate di campionato.
 *
 * `round` e `matchday` sono separati apposta. Il turno di calendario è
 * progressivo da 1, la giornata è quella vera di Serie A: la lega può partire
 * a stagione già iniziata.
 *
 * `tournament_id` nullo significa campionato. Una sfida è una sfida, comunque
 * la si sia generata, quindi coppe e campionato condividono la tabella.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matchups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tournament_id')->nullable()->constrained()->cascadeOnDelete();

            $t->unsignedSmallInteger('round');       // turno di calendario, da 1
            $t->string('stage', 32)->nullable();     // «girone», «quarti», «finale»
            $t->unsignedSmallInteger('matchday');    // giornata di Serie A

            // Casa e trasferta non danno nessun vantaggio — non c'è campo e non
            // c'è pubblico. Restano perché il girone all'italiana produce coppie
            // ordinate e perché «Marco – Giulia» si legge meglio di «coppia 3».
            $t->foreignId('home_manager_id')->constrained('managers')->cascadeOnDelete();
            $t->foreignId('away_manager_id')->constrained('managers')->cascadeOnDelete();

            $t->decimal('home_points', 6, 2)->nullable();   // fantapunti
            $t->decimal('away_points', 6, 2)->nullable();

            $t->unsignedTinyInteger('home_score')->nullable();   // punti di classifica
            $t->unsignedTinyInteger('away_score')->nullable();

            $t->enum('state', ['scheduled', 'played'])->default('scheduled');
            $t->timestamps();

            // Il vincolo sta dentro la singola competizione, non sulla giornata:
            // campionato e coppa si giocano lo stesso weekend, come nella realtà.
            $t->unique(['tournament_id', 'matchday', 'home_manager_id'], 'matchups_torneo_casa');
            $t->unique(['tournament_id', 'matchday', 'away_manager_id'], 'matchups_torneo_fuori');

            $t->index(['league_season_id', 'matchday']);
            $t->index(['league_season_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matchups');
    }
};
