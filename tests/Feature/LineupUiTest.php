<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\Manager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Schieramento della formazione dall'interfaccia.
 */
class LineupUiTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $stagione;

    private Manager $marco;

    /** @var Collection<int,Card> */
    private Collection $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagione = $this->makeLeague();
        $this->marco = $this->makeManager($this->stagione, 'Marco');
        $this->rosa = $this->makeRoster($this->marco, 'PPDDDDDCCCCCAAA', matchday: 3);

        // La giornata non è ancora cominciata: si può schierare.
        $this->makeFixture(3, now()->addDays(2)->toDateTimeString());
    }

    /** @return list<int> gli undici di un 4-4-2 */
    private function undici(): array
    {
        return collect(['P' => 1, 'D' => 4, 'C' => 4, 'A' => 2])
            ->flatMap(fn (int $n, string $ruolo) => $this->rosa->where('role', $ruolo)->take($n))
            ->pluck('id')
            ->all();
    }

    // ───────────────────────── la pagina ─────────────────────────

    public function test_la_pagina_e_riservata(): void
    {
        $this->get(route('lineup.show'))->assertRedirect(route('login'));
    }

    public function test_senza_carte_la_pagina_lo_dice(): void
    {
        $altro = $this->makeManager($this->stagione, 'Senzarosa');

        $this->actingAs($altro)->get(route('lineup.show'))->assertOk()->assertSee('Niente da schierare');
    }

    public function test_la_pagina_mostra_solo_i_moduli_davvero_schierabili(): void
    {
        // Rosa 2P 5D 5C 3A: tutti e sette i moduli reggono.
        $this->actingAs($this->marco)
            ->get(route('lineup.show'))
            ->assertOk()
            ->assertSee('4-4-2')
            ->assertSee('Schiera');
    }

    // ───────────────────────── il salvataggio ─────────────────────────

    public function test_si_salva_una_formazione_valida(): void
    {
        $undici = $this->undici();
        $panca = $this->rosa->whereNotIn('id', $undici)->pluck('id')->all();

        $this->actingAs($this->marco)
            ->post(route('lineup.store'), [
                'module' => '4-4-2',
                'titolari' => $undici,
                'panchina' => $panca,
            ])
            ->assertRedirect()
            ->assertSessionHas('successo');

        $lineup = Lineup::where('manager_id', $this->marco->id)->firstOrFail();

        $this->assertSame('4-4-2', $lineup->module);
        $this->assertCount(11, $lineup->starters());
        $this->assertFalse($lineup->auto_generated);
    }

    public function test_l_ordine_della_panchina_e_quello_scelto(): void
    {
        // È l'unica cosa che il manager controlla sulle sostituzioni.
        $undici = $this->undici();
        $panca = $this->rosa->whereNotIn('id', $undici)->pluck('id')->reverse()->values()->all();

        $this->actingAs($this->marco)->post(route('lineup.store'), [
            'module' => '4-4-2',
            'titolari' => $undici,
            'panchina' => $panca,
        ]);

        $ordine = Lineup::where('manager_id', $this->marco->id)->firstOrFail()
            ->bench()->pluck('card_id')->all();

        $this->assertSame($panca, $ordine);
    }

    public function test_le_carte_non_elencate_finiscono_comunque_in_panchina(): void
    {
        // Senza `bench_order` non entrerebbero mai: meglio una posizione
        // arbitraria in fondo che nessuna.
        $undici = $this->undici();

        $this->actingAs($this->marco)->post(route('lineup.store'), [
            'module' => '4-4-2',
            'titolari' => $undici,
            'panchina' => [],
        ]);

        $lineup = Lineup::where('manager_id', $this->marco->id)->firstOrFail();

        $this->assertCount($this->rosa->count() - 11, $lineup->bench());
        $this->assertCount(0, $lineup->bench()->whereNull('bench_order'));
    }

    // ───────────────────────── i rifiuti ─────────────────────────

    public function test_il_modulo_va_rispettato_esattamente(): void
    {
        // Un 4-4-2 con cinque difensori non è un 4-4-2 con uno in più:
        // è una formazione da dodici.
        $sbagliati = collect(['P' => 1, 'D' => 5, 'C' => 3, 'A' => 2])
            ->flatMap(fn (int $n, string $r) => $this->rosa->where('role', $r)->take($n))
            ->pluck('id')->all();

        $this->actingAs($this->marco)
            ->post(route('lineup.store'), ['module' => '4-4-2', 'titolari' => $sbagliati])
            ->assertSessionHasErrors('formazione');

        $this->assertSame(0, Lineup::count());
    }

    public function test_non_si_schierano_dieci_uomini(): void
    {
        $this->actingAs($this->marco)
            ->post(route('lineup.store'), [
                'module' => '4-4-2',
                'titolari' => array_slice($this->undici(), 0, 10),
            ])
            ->assertSessionHasErrors('formazione');
    }

    public function test_non_si_schierano_carte_di_un_altro(): void
    {
        $rivale = $this->makeManager($this->stagione, 'Rivale');
        $suaCarta = $this->makeRoster($rivale, 'A', matchday: 3)->first();

        $undici = $this->undici();
        $undici[10] = $suaCarta->id;

        $this->actingAs($this->marco)
            ->post(route('lineup.store'), ['module' => '4-4-2', 'titolari' => $undici])
            ->assertSessionHasErrors('formazione');
    }

    public function test_le_carte_della_rosa_arrivano_gia_rese_per_il_campo(): void
    {
        /*
         * ⚠️ Rese dal server e non chieste a richiesta, ed è la differenza fra
         * fattibile e no.
         *
         * `CartaController` serve una carta per volta apposta — su una pagina
         * come la giornata di Serie A ce ne vorrebbero cinquecento — ma qui la
         * rosa è già passata tutta dal presenter per riempire le tendine: i
         * dati sono in mano, e renderle costa zero query e zero richieste in
         * più. Undici caselle che chiedessero la propria carta al server
         * sarebbero invece undici richieste a OGNI cambio di modulo.
         */
        $risposta = $this->actingAs($this->marco)->get(route('lineup.show'))->assertOk();

        $risposta->assertSee('data-magazzino', escape: false);

        foreach ($this->rosa as $carta) {
            $risposta->assertSee('data-carta-di="'.$carta->id.'"', escape: false);
        }
    }

    public function test_a_giornata_cominciata_la_formazione_non_si_tocca(): void
    {
        $this->stagione->update(['started_matchday' => 3]);

        $this->actingAs($this->marco)
            ->post(route('lineup.store'), ['module' => '4-4-2', 'titolari' => $this->undici()])
            ->assertSessionHasErrors('formazione');

        $this->assertSame(0, Lineup::count());
    }

    public function test_il_primo_fischio_passato_da_solo_non_blocca_niente(): void
    {
        // ⚠️ È il bug che rese il gioco ingiocabile a un test coi volontari: le
        // annate si giocano ricaricate, quindi ogni fischio è già suonato da
        // anni. A decidere è la dichiarazione dell'amministratore, non
        // l'orologio — altrimenti nessuno potrebbe schierare mai.
        Fixture::where('matchday', 3)->update(['kickoff_at' => now()->subHour()]);

        $this->actingAs($this->marco)
            ->post(route('lineup.store'), ['module' => '4-4-2', 'titolari' => $this->undici()])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Lineup::count());
    }

    public function test_il_salvataggio_sostituisce_quello_precedente(): void
    {
        $undici = $this->undici();

        $this->actingAs($this->marco)->post(route('lineup.store'), [
            'module' => '4-4-2', 'titolari' => $undici,
        ]);

        $altri = collect(['P' => 1, 'D' => 3, 'C' => 5, 'A' => 2])
            ->flatMap(fn (int $n, string $r) => $this->rosa->where('role', $r)->take($n))
            ->pluck('id')->all();

        $this->actingAs($this->marco)->post(route('lineup.store'), [
            'module' => '3-5-2', 'titolari' => $altri,
        ]);

        $this->assertSame(1, Lineup::count());

        $lineup = Lineup::firstOrFail();
        $this->assertSame('3-5-2', $lineup->module);
        $this->assertCount(11, $lineup->starters());
        $this->assertCount($this->rosa->count(), $lineup->slots);
    }
}
