<?php

namespace Tests\Feature;

use App\Models\League;
use App\Models\Manager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Il seeder gira a ogni pubblicazione, non solo la prima volta.
 *
 * Deve quindi essere ripetibile senza duplicare niente, e deve saper rimediare
 * a uno stato che si incontra davvero: un gruppo rimasto senza amministratori,
 * da cui non si esce con nessuna schermata perché per amministrare bisogna
 * essere amministratori.
 */
class SeederTest extends TestCase
{
    use RefreshDatabase;

    private function semina(): void
    {
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
    }

    public function test_da_zero_crea_gruppo_e_amministratore(): void
    {
        $this->semina();

        $this->assertSame(1, League::count());

        $admin = Manager::where('email', 'admin@fantasbusta.test')->firstOrFail();

        $this->assertTrue($admin->is_admin);
    }

    public function test_rilanciarlo_non_duplica_niente(): void
    {
        // Gira a ogni pubblicazione: se duplicasse, dopo tre aggiornamenti ci
        // sarebbero tre gruppi e tre amministratori.
        $this->semina();
        $this->semina();
        $this->semina();

        $this->assertSame(1, League::count());
        $this->assertSame(1, Manager::count());
    }

    public function test_promuove_chi_c_e_gia_se_nessuno_e_amministratore(): void
    {
        // È lo stato che si incontra quando una squadra viene creata prima del
        // seeder, o quando qualcuno si toglie il flag: l'installazione resta
        // in piedi ma ingestibile, e nessuna pagina permette di rimediare.
        $league = League::create(['name' => 'Fantasbusta']);

        Manager::create([
            'league_id' => $league->id,
            'name' => 'Uno',
            'email' => 'admin@fantasbusta.test',
            'password' => 'segretissima',
            'is_admin' => false,
        ]);

        $this->semina();

        $this->assertTrue(Manager::where('email', 'admin@fantasbusta.test')->firstOrFail()->is_admin);
        $this->assertSame(1, Manager::count());
    }

    public function test_non_tocca_la_password_di_chi_c_e_gia(): void
    {
        // Ripristinare «password» a ogni pubblicazione sarebbe un modo
        // silenzioso di riaprire la porta a chiunque legga la documentazione.
        $league = League::create(['name' => 'Fantasbusta']);

        $manager = Manager::create([
            'league_id' => $league->id,
            'name' => 'Admin',
            'email' => 'admin@fantasbusta.test',
            'password' => 'unaSceltaMia',
            'is_admin' => true,
        ]);

        $prima = $manager->password;

        $this->semina();

        $this->assertSame($prima, $manager->fresh()->password);
    }

    public function test_non_promuove_nessuno_se_un_amministratore_c_e_gia(): void
    {
        $league = League::create(['name' => 'Fantasbusta']);

        Manager::create([
            'league_id' => $league->id, 'name' => 'Capo',
            'email' => 'capo@esempio.it', 'password' => 'x', 'is_admin' => true,
        ]);

        $this->semina();

        // Non aggiunge nulla: mettere un account con credenziali scritte nella
        // documentazione dentro un gruppo già amministrato sarebbe un regalo
        // a chiunque quella documentazione la legga.
        $this->assertSame(1, Manager::count());
        $this->assertNull(Manager::where('email', 'admin@fantasbusta.test')->first());
    }
}
