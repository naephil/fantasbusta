<?php

namespace Tests\Feature;

use App\Models\Manager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Chi entra si cambia la password da solo.
 *
 * Senza, l'unica strada sarebbe chiederlo all'amministratore — che quindi
 * conoscerebbe per sempre la password di tutti, essendo lui a inventarla al
 * momento dell'invito.
 */
class PasswordTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private function manager(string $password = 'quellaVecchia'): Manager
    {
        return tap($this->makeManager($this->makeLeague(), 'Marco'))
            ->update(['password' => $password]);
    }

    public function test_si_cambia_la_password(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('password.update'), [
                'attuale' => 'quellaVecchia',
                'nuova' => 'unaNuovaLunga',
                'nuova_confirmation' => 'unaNuovaLunga',
            ])
            ->assertRedirect()
            ->assertSessionHas('successo');

        $this->assertTrue(Hash::check('unaNuovaLunga', $manager->fresh()->password));
    }

    public function test_serve_la_password_attuale(): void
    {
        // ⚠️ Si chiede anche a chi è già collegato: senza, chiunque trovasse
        // una sessione aperta — un portatile lasciato lì — si prenderebbe
        // l'accesso in modo permanente.
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('password.update'), [
                'attuale' => 'tiroACaso',
                'nuova' => 'unaNuovaLunga',
                'nuova_confirmation' => 'unaNuovaLunga',
            ])
            ->assertSessionHasErrors('attuale');

        $this->assertTrue(Hash::check('quellaVecchia', $manager->fresh()->password));
    }

    public function test_la_conferma_deve_coincidere(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('password.update'), [
                'attuale' => 'quellaVecchia',
                'nuova' => 'unaNuovaLunga',
                'nuova_confirmation' => 'unAltraCosa',
            ])
            ->assertSessionHasErrors('nuova');

        $this->assertTrue(Hash::check('quellaVecchia', $manager->fresh()->password));
    }

    public function test_una_password_troppo_corta_viene_rifiutata(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('password.update'), [
                'attuale' => 'quellaVecchia',
                'nuova' => 'corta',
                'nuova_confirmation' => 'corta',
            ])
            ->assertSessionHasErrors('nuova');
    }

    public function test_senza_collegarsi_non_si_cambia_niente(): void
    {
        $this->post(route('password.update'), [
            'attuale' => 'x', 'nuova' => 'unaNuovaLunga', 'nuova_confirmation' => 'unaNuovaLunga',
        ])->assertRedirect(route('login'));
    }

    public function test_il_modulo_sta_nella_pagina_della_squadra(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->get(route('team.edit'))
            ->assertOk()
            ->assertSee('La tua password');
    }
}
