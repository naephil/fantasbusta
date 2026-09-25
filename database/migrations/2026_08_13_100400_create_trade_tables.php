<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scambi liberi, anche sbilanciati (7 carte per 1).
 *
 * Nessun tetto massimo di rosa: l'unico vincolo è che nessuna delle due parti
 * resti inschierabile. Lo squilibrio si autoregola, perché chi cede sette carte
 * per una Leggendaria si assottiglia e chi accetta si riempie di panchina che
 * non schiererà mai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $t) {
            $t->id();
            $t->foreignId('league_season_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('matchday');

            $t->foreignId('proposer_id')->constrained('managers')->cascadeOnDelete();
            $t->foreignId('receiver_id')->constrained('managers')->cascadeOnDelete();

            $t->enum('state', ['pending', 'accepted', 'rejected', 'cancelled', 'expired'])
                ->default('pending');

            // Se lo scambio è stato rifiutato dalla validazione automatica,
            // il motivo resta qui: serve a spiegarlo in interfaccia.
            $t->string('reject_reason')->nullable();

            $t->dateTime('resolved_at')->nullable();
            $t->timestamps();

            // Feed pubblico della lega: con il 7-per-1 permesso il rischio non è
            // l'equilibrio ma la collusione, e la trasparenza è il rimedio.
            $t->index(['league_season_id', 'matchday', 'state']);
        });

        Schema::create('trade_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $t->foreignId('card_id')->constrained()->cascadeOnDelete();
            $t->enum('direction', ['offered', 'requested']);

            $t->unique(['trade_id', 'card_id']);
            $t->index('card_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_items');
        Schema::dropIfExists('trades');
    }
};
