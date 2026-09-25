<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dati di riferimento sincronizzati da API-Football.
 *
 * Le chiavi primarie sono gli id API-Football, non autoincrementi nostri:
 * lo stesso id serve statistiche, anagrafica e foto
 * (media.api-sports.io/football/players/{id}.png), quindi tenerlo come PK
 * elimina un intero livello di mapping.
 *
 * ⚠️ Questi dati sono CONDIVISI fra tutti i gruppi di amici. Il voto di un
 * giocatore alla 12ª del 2023 è lo stesso per chiunque: duplicarlo per lega
 * moltiplicherebbe le chiamate all'API senza aggiungere niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('name');
            $t->string('code', 8)->nullable();
            $t->string('logo_url')->nullable();
            $t->string('color', 9)->nullable();          // accento carta, es. #0068a8
            $t->timestamps();
        });

        // Solo l'identità: quello che di un giocatore non cambia mai.
        Schema::create('players', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('first_name')->nullable();
            $t->string('last_name');
            $t->string('photo_url')->nullable();

            // Falso finché un umano non ha confrontato foto e nome.
            // Un id che punta al giocatore sbagliato restituisce una faccia
            // plausibile e supera qualunque controllo automatico: solo la
            // verifica manuale lo intercetta. Vedi docs/DESIGN.md §6.1.
            // Sta qui e non nella stagione: l'identità si verifica una volta.
            $t->boolean('photo_verified')->default(false);

            $t->timestamps();

            $t->index('photo_verified');
        });

        /*
         * ★ Tutto ciò che di un giocatore cambia da un anno all'altro.
         *
         * La squadra cambia coi trasferimenti, il ruolo e la quotazione
         * cambiano col listone nuovo. Tenerli su `players` significherebbe che
         * caricare il 2024 riscrive il 2022, e un gruppo che sta rigiocando
         * una stagione vecchia si ritroverebbe mezza Serie A nella squadra
         * sbagliata.
         */
        Schema::create('player_seasons', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('player_id');
            $t->unsignedSmallInteger('season');
            $t->unsignedBigInteger('team_id');

            // Ruolo e quotazione vengono dal listone, non dall'API:
            // API-Football espone `position` in inglese, che non è il ruolo
            // fantacalcio. Finché `role_confirmed` è falso il ruolo è
            // un'ipotesi dedotta dalla posizione.
            $t->enum('role', ['P', 'D', 'C', 'A']);
            $t->boolean('role_confirmed')->default(false);
            $t->decimal('quotazione_iniziale', 4, 1)->default(1);

            $t->boolean('active')->default(true);
            $t->dateTime('last_seen_at')->nullable();

            $t->timestamps();

            $t->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
            $t->foreign('team_id')->references('id')->on('teams');

            $t->unique(['player_id', 'season']);
            $t->index(['season', 'team_id', 'role']);
            $t->index(['season', 'role_confirmed']);
        });

        Schema::create('fixtures', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->unsignedSmallInteger('season');
            $t->unsignedSmallInteger('matchday');
            $t->unsignedBigInteger('home_team_id');
            $t->unsignedBigInteger('away_team_id');
            $t->dateTime('kickoff_at');
            $t->enum('status', ['scheduled', 'live', 'finished'])->default('scheduled');
            $t->unsignedTinyInteger('home_goals')->nullable();
            $t->unsignedTinyInteger('away_goals')->nullable();
            $t->timestamps();

            $t->foreign('home_team_id')->references('id')->on('teams');
            $t->foreign('away_team_id')->references('id')->on('teams');

            $t->index(['season', 'matchday', 'kickoff_at']);
        });

        Schema::create('player_stats', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('player_id');
            $t->unsignedSmallInteger('season');
            $t->unsignedSmallInteger('matchday');

            $t->unsignedSmallInteger('minutes')->default(0);
            $t->unsignedTinyInteger('goals')->default(0);
            $t->unsignedTinyInteger('assists')->default(0);
            $t->unsignedTinyInteger('yellow')->default(0);
            $t->unsignedTinyInteger('red')->default(0);
            $t->unsignedTinyInteger('own_goals')->default(0);
            $t->unsignedTinyInteger('pen_scored')->default(0);
            $t->unsignedTinyInteger('pen_missed')->default(0);
            $t->unsignedTinyInteger('pen_saved')->default(0);
            $t->unsignedTinyInteger('goals_conceded')->default(0);

            // NULL = senza voto. Distinto da 0, che sarebbe un voto reale pessimo.
            $t->decimal('rating', 3, 1)->nullable();

            $t->timestamps();

            $t->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();

            // La stagione fa parte della chiave: la 1ª del 2023 e la 1ª del
            // 2024 sono due giornate diverse, non la stessa riga.
            $t->unique(['player_id', 'season', 'matchday']);
            $t->index(['season', 'matchday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_stats');
        Schema::dropIfExists('fixtures');
        Schema::dropIfExists('player_seasons');
        Schema::dropIfExists('players');
        Schema::dropIfExists('teams');
    }
};
