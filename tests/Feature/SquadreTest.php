<?php

namespace Tests\Feature;

use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Services\Scoring\MatchdayScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Chi gioca: le persone invitate e i bot che riempiono i posti vuoti.
 */
class SquadreTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $stagione;

    private Manager $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagione = $this->makeLeague();
        $this->admin = tap($this->makeManager($this->stagione, 'Admin'))->update(['is_admin' => true]);
    }

    // ───────────────────────── accesso ─────────────────────────

    public function test_la_pagina_e_riservata_all_admin(): void
    {
        $this->actingAs($this->makeManager($this->stagione, 'Marco'))
            ->get(route('admin.squadre.index'))
            ->assertForbidden();
    }

    // ───────────────────────── invitare ─────────────────────────

    public function test_l_admin_crea_una_squadra_per_una_persona(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.squadre.store'), [
                'name' => 'Real Fornello',
                'coach_name' => 'Bruno',
                'email' => 'Bruno@Esempio.IT',
                'password' => 'segretissima',
            ])
            ->assertRedirect()
            ->assertSessionHas('successo');

        $nuova = Manager::where('name', 'Real Fornello')->firstOrFail();

        // L'email si normalizza: chi la detta a voce non sta attento alle
        // maiuscole, e due indirizzi che differiscono solo per quelle sono lo
        // stesso indirizzo.
        $this->assertSame('bruno@esempio.it', $nuova->email);
        $this->assertSame($this->stagione->league_id, $nuova->league_id);
        $this->assertFalse($nuova->is_bot);
    }

    public function test_due_squadre_non_possono_avere_la_stessa_email(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.squadre.store'), [
                'name' => 'Doppione',
                'email' => $this->admin->email,
                'password' => 'segretissima',
            ])
            ->assertSessionHasErrors('email');
    }

    // ───────────────────────── i bot ─────────────────────────

    public function test_i_bot_riempiono_i_posti_vuoti(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.squadre.bot'), ['quanti' => 3])
            ->assertRedirect();

        $bot = Manager::where('is_bot', true)->get();

        $this->assertCount(3, $bot);
        $this->assertTrue($bot->every(fn (Manager $m) => $m->auto_draft));
        $this->assertSame(3, $bot->pluck('name')->unique()->count());
    }

    public function test_i_bot_nascono_gia_vestiti(): void
    {
        // Un bot senza identità visiva è una riga grigia in mezzo a undici
        // squadre vestite, e si legge peggio delle altre proprio mentre gioca
        // come le altre.
        $this->actingAs($this->admin)->post(route('admin.squadre.bot'), ['quanti' => 6]);

        $bot = Manager::where('is_bot', true)->get();

        foreach ($bot as $m) {
            $this->assertNotNull($m->jersey, "{$m->name} senza maglia");
            $this->assertNotNull($m->crest, "{$m->name} senza stemma");
            $this->assertNotEmpty($m->jersey['colori'] ?? [], "{$m->name} senza colori");
            $this->assertNotSame('nessuno', $m->crest['simbolo'] ?? 'nessuno', "{$m->name} senza simbolo");
            $this->assertNotEmpty($m->sponsor['testo'] ?? '', "{$m->name} senza sponsor");
        }

        // Le palette girano a rotazione: sei bot devono avere sei divise
        // diverse, altrimenti tanto valeva non vestirli.
        $this->assertSame(6, $bot->map(fn (Manager $m) => implode($m->jersey['colori']))->unique()->count());
    }

    public function test_aggiungere_bot_due_volte_non_sbatte_sulle_email(): void
    {
        // I nomi sono una lista chiusa: alla seconda infornata devono
        // riprendere a contare da dove si erano fermati, non da capo.
        $this->actingAs($this->admin)->post(route('admin.squadre.bot'), ['quanti' => 2]);
        $this->actingAs($this->admin)->post(route('admin.squadre.bot'), ['quanti' => 2]);

        $this->assertSame(4, Manager::where('is_bot', true)->count());
        $this->assertSame(4, Manager::where('is_bot', true)->pluck('email')->unique()->count());
    }

    public function test_un_bot_non_prende_la_penalita_da_dimenticanza(): void
    {
        // La penalità punisce chi si scorda di schierare. Un bot non si
        // scorda: gioca d'ufficio per costruzione, e punirlo lo renderebbe un
        // avversario finto che perde sempre.
        $bot = tap($this->makeManager($this->stagione, 'Bot'))
            ->update(['is_bot' => true, 'auto_draft' => true]);

        $rosa = $this->makeRoster($bot, 'PDDDDCCCCAA');
        $this->scoreAll($rosa, 6.0);

        app(MatchdayScorer::class)->scoreLineups($this->stagione, 1);

        $lineup = $bot->lineups()->firstOrFail();

        $this->assertTrue($lineup->auto_generated);
        $this->assertSame(0.0, $lineup->result->penalita);
        $this->assertSame(66.0, $lineup->result->totale);
    }

    public function test_una_persona_distratta_la_penalita_la_prende_eccome(): void
    {
        $umano = $this->makeManager($this->stagione, 'Distratto');

        $rosa = $this->makeRoster($umano, 'PDDDDCCCCAA');
        $this->scoreAll($rosa, 6.0);

        app(MatchdayScorer::class)->scoreLineups($this->stagione, 1);

        $this->assertSame(-3.0, $umano->lineups()->firstOrFail()->result->penalita);
    }

    // ───────────────────────── togliere dal giro ─────────────────────────

    public function test_chi_non_ha_mai_giocato_si_cancella(): void
    {
        $mai = $this->makeManager($this->stagione, 'Mai entrato');

        $this->actingAs($this->admin)
            ->delete(route('admin.squadre.destroy', $mai))
            ->assertRedirect();

        $this->assertNull(Manager::find($mai->id));
    }

    public function test_chi_ha_gia_giocato_si_disattiva_e_basta(): void
    {
        // Le sue carte e i suoi risultati devono restare leggibili: una
        // classifica con un buco al posto di un avversario non si capisce più.
        $veterano = $this->makeManager($this->stagione, 'Veterano');
        $this->makeRoster($veterano, 'PDDDDCCCCAA');

        $this->actingAs($this->admin)->delete(route('admin.squadre.destroy', $veterano));

        $this->assertNotNull(Manager::find($veterano->id));
        $this->assertFalse($veterano->fresh()->active);
    }

    public function test_non_si_cancella_la_propria_squadra(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.squadre.destroy', $this->admin))
            ->assertForbidden();
    }

    public function test_non_si_tocca_la_squadra_di_un_altro_gruppo(): void
    {
        $altrui = $this->makeManager($this->makeLeague('Altro gruppo'), 'Estraneo');

        $this->actingAs($this->admin)
            ->delete(route('admin.squadre.destroy', $altrui))
            ->assertNotFound();
    }

    // ───────────────────────── l'ultimo admin ─────────────────────────

    public function test_l_ultimo_amministratore_non_si_puo_degradare(): void
    {
        // Resterebbe un gruppo che nessuno può più gestire, e non c'è nessuna
        // schermata da cui uscirne.
        $this->actingAs($this->admin)
            ->patch(route('admin.squadre.update', $this->admin), [
                'name' => $this->admin->name,
                'active' => 1,
            ])
            ->assertSessionHasErrors('squadra');

        $this->assertTrue($this->admin->fresh()->is_admin);
    }

    public function test_col_secondo_amministratore_il_primo_puo_farsi_da_parte(): void
    {
        tap($this->makeManager($this->stagione, 'Secondo'))->update(['is_admin' => true]);

        $this->actingAs($this->admin)
            ->patch(route('admin.squadre.update', $this->admin), [
                'name' => $this->admin->name,
                'active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->admin->fresh()->is_admin);
    }

    public function test_la_password_si_cambia_e_quella_vuota_non_la_azzera(): void
    {
        $manager = $this->makeManager($this->stagione, 'Marco');
        $vecchia = $manager->password;

        $this->actingAs($this->admin)->patch(route('admin.squadre.update', $manager), [
            'name' => 'Marco', 'active' => 1, 'password' => '',
        ]);

        $this->assertSame($vecchia, $manager->fresh()->password);

        $this->actingAs($this->admin)->patch(route('admin.squadre.update', $manager), [
            'name' => 'Marco', 'active' => 1, 'password' => 'nuovissima',
        ]);

        $this->assertNotSame($vecchia, $manager->fresh()->password);
    }
}
