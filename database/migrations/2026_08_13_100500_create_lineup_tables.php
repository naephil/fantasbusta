<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();
            $t->foreignId('manager_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('matchday');

            // Solo i sette moduli canonici. Il vincolo "D≥3, C≥3, A≥1" da solo
            // non basta: una rosa 3D/3C/4A li rispetta tutti e resta comunque
            // inschierabile, perché nessuna combinazione valida arriva a 10.
            $t->enum('module', ['3-4-3', '3-5-2', '4-3-3', '4-4-2', '4-5-1', '5-3-2', '5-4-1']);

            $t->enum('state', ['draft', 'locked'])->default('draft');

            // Vero quando l'ha composta il sistema al posto di un assente.
            $t->boolean('auto_generated')->default(false);

            $t->dateTime('locked_at')->nullable();
            $t->timestamps();

            $t->unique(['league_season_id', 'manager_id', 'matchday']);
        });

        Schema::create('lineup_slots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lineup_id')->constrained()->cascadeOnDelete();
            $t->foreignId('card_id')->constrained()->cascadeOnDelete();

            $t->boolean('is_starter');

            // Ordine di ingresso per le sostituzioni automatiche. Servono davvero:
            // il draft della giornata N+1 si chiude prima che escano le formazioni
            // ufficiali, quindi i senza voto sono la norma, non l'eccezione.
            $t->unsignedTinyInteger('bench_order')->nullable();

            $t->unique(['lineup_id', 'card_id']);
        });

        Schema::create('lineup_results', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lineup_id')->constrained()->cascadeOnDelete();
            $t->decimal('totale', 6, 2);

            // Negativa: si somma al totale di chi non ha schierato da sé.
            $t->decimal('penalita', 4, 2)->default(0);

            // Chi è entrato per chi, per il report a fine giornata.
            $t->json('sostituzioni')->nullable();

            // Vero quando è scattato il voto d'ufficio 4 sul portiere:
            // niente voto e nessuna riserva in panchina.
            $t->boolean('portiere_ufficio')->default(false);

            $t->dateTime('computed_at');
            $t->timestamps();

            $t->unique('lineup_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lineup_results');
        Schema::dropIfExists('lineup_slots');
        Schema::dropIfExists('lineups');
    }
};
