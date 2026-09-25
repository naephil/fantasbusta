<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il gruppo di amici, le sue squadre, le sue stagioni.
 *
 * Tre livelli, e la distinzione fra i primi due è il cuore del modello:
 *
 *   lega              il gruppo di amici. Permanente.
 *   manager           l'identità di una squadra. Permanente: nome, allenatore,
 *                     maglia e stemma restano tuoi da un anno all'altro.
 *   stagione di lega  la competizione. Ogni anno è una gara nuova, e tutto
 *                     ciò che si gioca — carte, draft, classifica, tornei —
 *                     appartiene a lei, non alla lega.
 *
 * Senza questa separazione un gruppo alla seconda stagione collide con sé
 * stesso sul numero di giornata, e ricrearlo ogni anno significherebbe
 * perdere maglie, stemmi e albo d'oro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $t) {
            $t->id();
            $t->string('name');

            // Pesi del power score, bonus/malus, quote di ruolo, timer, numero
            // di sostituzioni. In JSON perché sono parametri da tarare a caldo:
            // finirebbero altrimenti in una migration a ogni ritocco.
            // Sono le regole di casa del gruppo; una singola stagione può
            // scostarsene senza toccare le altre.
            $t->json('settings')->nullable();

            $t->timestamps();
        });

        Schema::create('managers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_id')->constrained()->cascadeOnDelete();

            $t->string('name');                         // il nome della SQUADRA
            $t->string('coach_name')->nullable();       // la persona dietro
            $t->string('email')->unique();
            $t->string('password');

            // Sbustamento automatico appena arriva il turno, senza attendere
            // la scadenza: fa scorrere il draft più in fretta per tutti.
            $t->boolean('auto_draft')->default(false);
            $t->boolean('is_admin')->default(false);

            // Chi lascia il gruppo non si cancella: le sue carte e i suoi
            // risultati passati devono restare leggibili. Semplicemente non
            // entra nelle stagioni nuove.
            $t->boolean('active')->default(true);

            // Maglia, sponsor e stemma in JSON: manciate di scelte fra insiemi
            // chiusi, che cambiano insieme e su cui nessuno farà una query.
            $t->json('jersey')->nullable();
            $t->json('sponsor')->nullable();
            $t->string('sponsor_path')->nullable();
            $t->json('crest')->nullable();
            $t->string('crest_path')->nullable();

            $t->string('telegram_chat_id')->nullable();
            $t->rememberToken();
            $t->timestamps();

            $t->index(['league_id', 'active']);
        });

        // ★ La competizione. Tutto ciò che si gioca punta qui.
        Schema::create('league_seasons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('season');

            $t->enum('state', ['preparazione', 'in_corso', 'conclusa'])->default('preparazione');

            // Da quale giornata di Serie A comincia il campionato del gruppo:
            // non è detto che si parta dalla prima.
            $t->unsignedSmallInteger('start_matchday')->default(1);

            // Scostamenti dalle regole del gruppo, per questa stagione sola.
            $t->json('settings')->nullable();

            $t->timestamps();

            $t->unique(['league_id', 'season']);
            $t->index(['league_id', 'state']);
        });

        Schema::create('standings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();
            $t->foreignId('manager_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('matchday');

            $t->unsignedSmallInteger('punti')->default(0);
            $t->decimal('fantapunti', 7, 2)->default(0);
            $t->unsignedTinyInteger('posizione')->nullable();

            $t->timestamps();

            $t->unique(['league_season_id', 'manager_id', 'matchday']);

            // L'ordine di draft è l'inverso di questa classifica: l'indice serve.
            $t->index(['league_season_id', 'matchday', 'posizione']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standings');
        Schema::dropIfExists('league_seasons');
        Schema::dropIfExists('managers');
        Schema::dropIfExists('leagues');
    }
};
