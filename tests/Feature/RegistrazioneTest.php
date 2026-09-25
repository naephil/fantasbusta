<?php

namespace Tests\Feature;

use App\Models\Manager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Iscriversi da sé col link della lega.
 *
 * Le prove che contano non sono quelle del caso buono — quello è tre campi e un
 * redirect — ma quelle attorno al gettone: è l'unica cosa che separa la lega da
 * Internet, e ogni modo in cui potrebbe non tenere è un modo in cui chiunque
 * entra nel gruppo.
 */
class RegistrazioneTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    // ───────────────────────── il caso buono ─────────────────────────

    public function test_col_link_giusto_ci_si_iscrive_ed_entra(): void
    {
        $league = $this->makeLeague()->league;
        $gettone = $league->apriIscrizioni();

        $this->get(route('registra', $gettone))
            ->assertOk()
            ->assertSee($league->name);

        $this->post(route('registra', $gettone), [
            'name' => 'Atletico Volontari',
            'coach_name' => 'Giulia',
            'email' => 'Giulia@Esempio.IT',
            'password' => 'unapasswordlunga',
        ])->assertRedirect(route('home'));

        $manager = Manager::where('name', 'Atletico Volontari')->firstOrFail();

        $this->assertAuthenticatedAs($manager);
        $this->assertSame($league->id, $manager->league_id);

        // L'email si normalizza come nella creazione dall'admin: entrarci con
        // una maiuscola di differenza deve funzionare comunque.
        $this->assertSame('giulia@esempio.it', $manager->email);
    }

    public function test_chi_si_iscrive_non_diventa_amministratore(): void
    {
        // ⚠️ Il link circola in una chat: se bastasse passarlo a qualcuno per
        // dargli la gestione del gruppo, non sarebbe un invito ma una delega.
        $gettone = $this->makeLeague()->league->apriIscrizioni();

        $this->post(route('registra', $gettone), [
            'name' => 'Nuova', 'email' => 'nuova@esempio.it', 'password' => 'unapasswordlunga',
        ]);

        $manager = Manager::where('name', 'Nuova')->firstOrFail();

        $this->assertFalse($manager->is_admin);
        $this->assertFalse($manager->is_bot);
        $this->assertTrue($manager->active);
    }

    // ───────────────────────── il gettone ─────────────────────────

    public function test_senza_gettone_valido_la_pagina_non_esiste(): void
    {
        $this->makeLeague();

        $this->get(route('registra', 'inventato'))->assertNotFound();
        $this->post(route('registra', 'inventato'), [
            'name' => 'Abusiva', 'email' => 'abusiva@esempio.it', 'password' => 'unapasswordlunga',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing('managers', ['name' => 'Abusiva']);
    }

    public function test_a_iscrizioni_chiuse_non_si_entra(): void
    {
        // Una lega appena creata ha il gettone a null, e null vuol dire chiuso:
        // è il caso in cui si trova ogni gruppo subito dopo la migrazione.
        $league = $this->makeLeague()->league;

        $this->assertFalse($league->iscrizioniAperte());
        $this->assertNull($league->linkIscrizione());

        // Nessun indirizzo da provare: senza gettone la rotta non esiste
        // proprio, e `/registra` da solo non è una pagina.
        $this->get('/registra')->assertNotFound();
    }

    public function test_chiudere_le_iscrizioni_spegne_il_link_ma_non_chi_e_gia_dentro(): void
    {
        $league = $this->makeLeague()->league;
        $gettone = $league->apriIscrizioni();

        $this->post(route('registra', $gettone), [
            'name' => 'Prima', 'email' => 'prima@esempio.it', 'password' => 'unapasswordlunga',
        ]);
        $this->post(route('logout'));

        $league->chiudiIscrizioni();

        $this->get(route('registra', $gettone))->assertNotFound();

        // Chi è entrato prima della chiusura non viene cacciato: il link si
        // strappa, le squadre già create restano.
        $this->post(route('login'), ['email' => 'prima@esempio.it', 'password' => 'unapasswordlunga'])
            ->assertRedirect(route('home'));
    }

    public function test_rigenerare_revoca_il_link_di_prima(): void
    {
        // È il modo con cui si rimedia a un link finito nel gruppo sbagliato:
        // se il vecchio indirizzo continuasse a funzionare non sarebbe una
        // revoca, sarebbe un secondo invito.
        $league = $this->makeLeague()->league;
        $vecchio = $league->apriIscrizioni();
        $nuovo = $league->apriIscrizioni();

        $this->assertNotSame($vecchio, $nuovo);
        $this->get(route('registra', $vecchio))->assertNotFound();
        $this->get(route('registra', $nuovo))->assertOk();
    }

    public function test_il_gettone_di_un_gruppo_non_apre_l_altro(): void
    {
        // Su un sito con più leghe è la garanzia che regge tutto: sbagliare
        // gruppo significherebbe far vedere a un estraneo rose e mercato.
        $una = $this->makeLeague('Una')->league;
        $altra = $this->makeLeague('Altra')->league;

        $una->apriIscrizioni();
        $gettoneAltra = $altra->apriIscrizioni();

        $this->post(route('registra', $gettoneAltra), [
            'name' => 'Ospite', 'email' => 'ospite@esempio.it', 'password' => 'unapasswordlunga',
        ]);

        $this->assertSame($altra->id, Manager::where('name', 'Ospite')->firstOrFail()->league_id);
    }

    public function test_il_gettone_non_esce_serializzando_la_lega(): void
    {
        // Vale quanto una password: se finisse in un `toJson()` — un dump, una
        // risposta di debug — sarebbe la porta aperta che dovrebbe chiudere.
        $league = $this->makeLeague()->league;
        $league->apriIscrizioni();

        $this->assertStringNotContainsString($league->registration_token, $league->toJson());
    }

    // ───────────────────────── i dati ─────────────────────────

    public function test_un_email_gia_in_uso_non_ruba_la_squadra_a_nessuno(): void
    {
        $stagione = $this->makeLeague();
        $esistente = $this->makeManager($stagione, 'Marco');
        $gettone = $stagione->league->apriIscrizioni();

        $this->post(route('registra', $gettone), [
            'name' => 'Furba', 'email' => $esistente->email, 'password' => 'unapasswordlunga',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();

        // La squadra di prima è ancora la sua: l'iscrizione respinta non deve
        // aver riscritto niente addosso a chi c'era già.
        $this->assertSame('Marco', $esistente->fresh()->name);
        $this->assertDatabaseMissing('managers', ['name' => 'Furba']);
    }

    public function test_la_password_corta_non_passa(): void
    {
        // `min:8` come nel cambio password: qui la password è già quella
        // definitiva della persona, non una provvisoria da cambiare all'ingresso.
        $gettone = $this->makeLeague()->league->apriIscrizioni();

        $this->post(route('registra', $gettone), [
            'name' => 'Corta', 'email' => 'corta@esempio.it', 'password' => 'corta',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('managers', ['name' => 'Corta']);
    }

    public function test_chi_e_gia_dentro_non_passa_di_qui(): void
    {
        $stagione = $this->makeLeague();
        $gettone = $stagione->league->apriIscrizioni();

        $this->actingAs($this->makeManager($stagione, 'Marco'))
            ->get(route('registra', $gettone))
            ->assertRedirect();
    }

    // ───────────────────────── la gestione ─────────────────────────

    public function test_l_admin_apre_e_chiude_le_iscrizioni(): void
    {
        $stagione = $this->makeLeague();
        $admin = $this->makeManager($stagione, 'Admin');
        $admin->update(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.squadre.invito.apri'))->assertRedirect();
        $this->assertTrue($stagione->league->fresh()->iscrizioniAperte());

        $this->actingAs($admin)
            ->get(route('admin.squadre.index'))
            ->assertOk()
            ->assertSee($stagione->league->fresh()->linkIscrizione());

        $this->actingAs($admin)->delete(route('admin.squadre.invito.chiudi'))->assertRedirect();
        $this->assertFalse($stagione->league->fresh()->iscrizioniAperte());
    }

    public function test_un_manager_qualunque_non_apre_le_iscrizioni(): void
    {
        $stagione = $this->makeLeague();

        $this->actingAs($this->makeManager($stagione, 'Marco'))
            ->post(route('admin.squadre.invito.apri'))
            ->assertForbidden();

        $this->assertFalse($stagione->league->fresh()->iscrizioniAperte());
    }
}
