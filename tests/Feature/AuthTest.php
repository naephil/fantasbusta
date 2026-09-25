<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Accesso dei manager. Le iscrizioni stanno in RegistrazioneTest: qui si entra
 * soltanto, con un profilo che esiste già.
 */
class AuthTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    public function test_la_pagina_di_accesso_si_apre(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Entra');
    }

    public function test_un_manager_entra_con_le_sue_credenziali(): void
    {
        $manager = $this->makeManager($this->makeLeague(), 'Marco');

        $this->post(route('login'), [
            'email' => $manager->email,
            'password' => 'segreta',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($manager);
    }

    public function test_la_password_sbagliata_non_dice_di_piu_del_necessario(): void
    {
        // Un messaggio unico per credenziali errate ed email inesistente:
        // distinguerli confermerebbe a chiunque chi è iscritto alla lega.
        $manager = $this->makeManager($this->makeLeague(), 'Marco');

        $this->post(route('login'), ['email' => $manager->email, 'password' => 'sbagliata'])
            ->assertSessionHasErrors(['email' => 'Credenziali non valide.']);

        $this->post(route('login'), ['email' => 'nessuno@fantasbusta.test', 'password' => 'qualunque'])
            ->assertSessionHasErrors(['email' => 'Credenziali non valide.']);

        $this->assertGuest();
    }

    public function test_si_esce(): void
    {
        $manager = $this->makeManager($this->makeLeague(), 'Marco');

        $this->actingAs($manager)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_la_home_e_riservata(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_la_home_segnala_all_admin_cosa_resta_da_fare(): void
    {
        $admin = $this->makeManager($this->makeLeague(), 'Admin');
        $admin->update(['is_admin' => true]);

        // Fuori dal listone: è proprio chi aspetta una decisione dell'admin.
        $this->makePlayer('C', nelListone: false);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('1 giocatori aspettano una decisione');
    }
}
