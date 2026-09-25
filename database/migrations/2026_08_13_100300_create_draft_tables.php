<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft snake asincrono con pool esclusivo.
 *
 * La struttura a turni serializza già la pesca: un solo manager alla volta ha
 * il turno attivo, quindi non servono code né SKIP LOCKED. Restano due sole
 * corse reali, entrambe risolte con lock di riga — vedi docs/DESIGN.md §7.
 *
 * Tutto appartiene alla STAGIONE di lega, non alla lega: il draft della 5ª
 * giornata del 2023 e quello della 5ª del 2024 sono due cose diverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('matchday');
            $t->enum('state', ['pending', 'open', 'closed'])->default('pending');

            $t->dateTime('opens_at');       // primo fischio della giornata precedente
            $t->dateTime('deadline_at');    // chiusura, prima della fase di scambi

            $t->unsignedTinyInteger('rounds')->default(5);
            $t->unsignedTinyInteger('pack_size')->default(5);

            $t->timestamps();

            $t->unique(['league_season_id', 'matchday']);
            $t->index('state');
        });

        // ★ L'esclusività del pool vive su questo unique.
        // Anche a fronte di un errore logico il database rifiuta di assegnare
        // due volte lo stesso giocatore nella stessa giornata.
        Schema::create('draft_pool', function (Blueprint $t) {
            $t->id();
            $t->foreignId('draft_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('player_id');
            $t->enum('tier', ['comune', 'rara', 'epica', 'leggendaria']);
            $t->enum('role', ['P', 'D', 'C', 'A']);
            $t->enum('status', ['available', 'drawn'])->default('available');
            $t->foreignId('drawn_by_manager_id')->nullable()->constrained('managers')->nullOnDelete();
            $t->dateTime('drawn_at')->nullable();

            $t->foreign('player_id')->references('id')->on('players');
            $t->unique(['draft_id', 'player_id']);

            // Query calda: estrazione per ruolo e tier fra i disponibili.
            $t->index(['draft_id', 'status', 'role', 'tier']);
        });

        Schema::create('draft_turns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('draft_id')->constrained()->cascadeOnDelete();
            $t->foreignId('manager_id')->constrained()->cascadeOnDelete();

            $t->unsignedTinyInteger('round');        // 1..5
            $t->unsignedSmallInteger('pick_index');  // 1..60, ordine snake già risolto

            $t->enum('state', ['waiting', 'active', 'done'])->default('waiting');

            // Calcolato dinamicamente all'attivazione:
            //   (deadline − adesso) / turni_rimanenti, stretto fra i due
            // estremi tarabili in `draft.turno_min_minuti` e `turno_max_minuti`
            // — di fabbrica 20 minuti e 4 ore. Vedi Draft::turnDuration().
            $t->dateTime('expires_at')->nullable();

            $t->dateTime('opened_at')->nullable();
            $t->enum('opened_by', ['manager', 'auto'])->nullable();

            $t->timestamps();

            $t->unique(['draft_id', 'pick_index']);

            // Query del cron `draft:tick`, ogni 5 minuti.
            $t->index(['state', 'expires_at']);
        });

        // La rosa vive qui. Una carta è posseduta e può essere scambiata.
        Schema::create('cards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('matchday');
            $t->unsignedBigInteger('player_id');

            // Congelati alla pesca e mai più aggiornati. Il tier perché
            // altrimenti il valore di uno scambio cambierebbe dopo
            // l'accettazione; il ruolo perché una correzione del listone non
            // deve rendere inschierabile una rosa già formata.
            $t->enum('tier', ['comune', 'rara', 'epica', 'leggendaria']);
            $t->enum('role', ['P', 'D', 'C', 'A']);

            $t->foreignId('draft_turn_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('owner_manager_id')->constrained('managers')->cascadeOnDelete();
            $t->foreignId('original_owner_id')->constrained('managers')->cascadeOnDelete();

            $t->timestamps();

            $t->foreign('player_id')->references('id')->on('players');

            // Cintura e bretelle rispetto al pool: una carta per giocatore per giornata.
            $t->unique(['league_season_id', 'matchday', 'player_id']);
            $t->index(['owner_manager_id', 'matchday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
        Schema::dropIfExists('draft_turns');
        Schema::dropIfExists('draft_pool');
        Schema::dropIfExists('drafts');
    }
};
