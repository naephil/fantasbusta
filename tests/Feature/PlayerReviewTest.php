<?php

namespace Tests\Feature;

use App\Models\Manager;
use App\Models\PlayerSeason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * La schermata di verifica del listone.
 *
 * §6.1 la dà per obbligatoria prima dell'avvio stagione: nessun controllo
 * automatico può sostituirla, perché un id che punta al giocatore sbagliato
 * mostra una faccia plausibile e passa qualunque validazione.
 */
class PlayerReviewTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private function admin(): Manager
    {
        $manager = $this->makeManager($this->makeLeague(), 'Admin');
        $manager->update(['is_admin' => true]);

        return $manager;
    }

    /**
     * Una riga di listone da rivedere.
     *
     * Gli attributi si smistano da soli fra le due tabelle, perché è lì che il
     * modello li tiene: cognome e «è davvero lui» stanno sulla persona, ruolo e
     * quotazione sull'annata. Scriverli tutti insieme nel caso di prova tiene
     * il test leggibile senza mentire sullo schema.
     */
    private function giocatore(array $attributi = []): PlayerSeason
    {
        $dellaPersona = array_intersect_key($attributi, array_flip(['last_name', 'first_name', 'photo_verified']));
        $dellAnnata = array_diff_key($attributi, $dellaPersona);

        // Fuori dal listone: questa schermata esiste proprio per chi il listone
        // non ha agganciato, e il filtro di partenza è «ruolo da decidere».
        $player = $this->makePlayer('C', nelListone: false);

        if ($dellaPersona !== []) {
            $player->update($dellaPersona);
        }

        $riga = PlayerSeason::where('player_id', $player->id)->firstOrFail();

        if ($dellAnnata !== []) {
            $riga->update($dellAnnata);
        }

        return $riga->fresh()->load('player');
    }

    // ───────────────────────── accesso ─────────────────────────

    public function test_senza_login_si_finisce_alla_pagina_di_accesso(): void
    {
        $this->get(route('listone.index'))->assertRedirect(route('login'));
    }

    public function test_il_listone_lo_legge_chiunque(): void
    {
        // Era una pagina d'amministrazione e non aveva senso che lo fosse: dice
        // chi c'è, con che ruolo e a quanto è quotato — le informazioni su cui
        // si decide al draft. Tenerla chiusa dava all'admin un vantaggio che
        // nessuno aveva voluto dargli.
        $this->giocatore();
        $manager = $this->makeManager($this->makeLeague(), 'Marco');

        $this->actingAs($manager)
            ->get(route('listone.index'))
            ->assertOk()
            ->assertSee('Chi è chi');
    }

    public function test_chiunque_puo_verificare_un_identita(): void
    {
        // ⚠️ È il lavoro che conviene dividere: ~550 facce da guardare a
        // occhio, che nessuna automazione sa fare. In dodici è mezz'ora, da
        // soli è un pomeriggio — ed è il motivo per cui non veniva mai fatto.
        $giocatore = $this->giocatore();
        $manager = $this->makeManager($this->makeLeague(), 'Marco');

        $this->actingAs($manager)
            ->post(route('listone.verify', $giocatore))
            ->assertRedirect();

        $this->assertTrue($giocatore->player->fresh()->photo_verified);
    }

    public function test_ruolo_e_quotazione_restano_dell_admin(): void
    {
        // Quelle sono decisioni di gioco e valgono punti: chi poi gioca con
        // quelle carte non se le riscrive da sé.
        $giocatore = $this->giocatore();
        $manager = $this->makeManager($this->makeLeague(), 'Marco');

        $this->actingAs($manager)
            ->patch(route('admin.players.update', $giocatore), [
                'role' => 'A',
                'quotazione_iniziale' => 99,
            ])
            ->assertForbidden();

        $this->assertNotSame('A', $giocatore->fresh()->role->value);
    }

    public function test_di_partenza_i_piu_quotati_vengono_per_primi(): void
    {
        // ⚠️ Su cinquecento righe si verifica quello che si riesce, e conta
        // aver guardato in faccia chi verrà davvero pescato: un'identità
        // sbagliata sul terzo portiere non la nota nessuno, la stessa su un
        // attaccante da quaranta è la faccia sbagliata su una Leggendaria.
        $scarso = $this->giocatore(['last_name' => 'Riserva', 'quotazione_iniziale' => 2]);
        $forte = $this->giocatore(['last_name' => 'Bomber', 'quotazione_iniziale' => 40]);

        $html = $this->actingAs($this->admin())
            ->get(route('listone.index', ['filtro' => 'tutti']))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($html, $scarso->player->last_name),
            strpos($html, $forte->player->last_name),
        );
    }

    public function test_si_puo_ordinare_per_squadra(): void
    {
        // L'altro modo di lavorare: una rosa alla volta, con le facce di
        // quella squadra in mente.
        $this->giocatore(['last_name' => 'Bomber', 'quotazione_iniziale' => 40]);

        $this->actingAs($this->admin())
            ->get(route('listone.index', ['ordine' => 'squadra', 'filtro' => 'tutti']))
            ->assertOk()
            ->assertSee('Bomber');
    }

    public function test_un_ordine_inventato_ricade_su_quello_di_partenza(): void
    {
        // L'ordine arriva dalla query string: un valore a caso non deve
        // arrivare fino a `orderBy`.
        $this->giocatore(['last_name' => 'Bomber']);

        $this->actingAs($this->admin())
            ->get(route('listone.index', ['ordine' => 'a-caso', 'filtro' => 'tutti']))
            ->assertOk()
            ->assertSee('Bomber');
    }

    public function test_a_chi_non_e_admin_il_modulo_del_ruolo_non_si_mostra(): void
    {
        // Non basta che la rotta rifiuti: un pulsante che non funziona è una
        // promessa rotta, e chi lo preme pensa che il sito sia guasto.
        $this->giocatore();
        $manager = $this->makeManager($this->makeLeague(), 'Marco');

        $this->actingAs($manager)
            ->get(route('listone.index'))
            ->assertOk()
            ->assertDontSee('Salva e conferma ruolo')
            ->assertSee('verifica identità', false);
    }

    public function test_l_admin_entra(): void
    {
        $this->giocatore();

        $this->actingAs($this->admin())
            ->get(route('listone.index'))
            ->assertOk()
            ->assertSee('Chi è chi');
    }

    // ───────────────────────── l'elenco ─────────────────────────

    public function test_il_filtro_predefinito_mostra_chi_ha_il_ruolo_da_decidere(): void
    {
        $daDecidere = $this->giocatore(['last_name' => 'Ipotizzato', 'role_confirmed' => false]);
        $deciso = $this->giocatore(['last_name' => 'Confermato', 'role_confirmed' => true]);

        $this->actingAs($this->admin())
            ->get(route('listone.index'))
            ->assertSee($daDecidere->player->last_name)
            ->assertDontSee($deciso->player->last_name);
    }

    public function test_il_filtro_identita_mostra_chi_non_e_stato_guardato(): void
    {
        $daGuardare = $this->giocatore(['last_name' => 'Sconosciuto', 'role_confirmed' => true, 'photo_verified' => false]);
        $guardato = $this->giocatore(['last_name' => 'Riconosciuto', 'role_confirmed' => true, 'photo_verified' => true]);

        $this->actingAs($this->admin())
            ->get(route('listone.index', ['filtro' => 'identita']))
            ->assertSee($daGuardare->player->last_name)
            ->assertDontSee($guardato->player->last_name);
    }

    public function test_la_ricerca_per_cognome_restringe_l_elenco(): void
    {
        $cercato = $this->giocatore(['last_name' => 'Barella']);
        $altro = $this->giocatore(['last_name' => 'Dimarco']);

        $this->actingAs($this->admin())
            ->get(route('listone.index', ['q' => 'Barel']))
            ->assertSee($cercato->player->last_name)
            ->assertDontSee($altro->player->last_name);
    }

    public function test_i_giocatori_disattivati_non_compaiono(): void
    {
        $ritirato = $this->giocatore(['last_name' => 'Ritirato', 'active' => false]);

        $this->actingAs($this->admin())
            ->get(route('listone.index', ['filtro' => 'tutti']))
            ->assertDontSee($ritirato->player->last_name);
    }

    public function test_la_foto_e_il_nome_stanno_affiancati(): void
    {
        // È tutto il senso della schermata: senza la foto accanto al nome non
        // si verifica niente.
        $giocatore = $this->giocatore(['last_name' => 'Barella']);

        $this->actingAs($this->admin())
            ->get(route('listone.index'))
            ->assertSee($giocatore->player->photoUrl())
            ->assertSee('Barella');
    }

    // ───────────────────────── le due conferme ─────────────────────────

    public function test_l_admin_decide_ruolo_e_quotazione(): void
    {
        $giocatore = $this->giocatore(['role' => 'C', 'role_confirmed' => false]);

        $this->actingAs($this->admin())
            ->patch(route('admin.players.update', $giocatore), [
                'role' => 'D',
                'quotazione_iniziale' => 18.5,
            ])
            ->assertRedirect();

        $giocatore->refresh();

        $this->assertSame('D', $giocatore->role->value);
        $this->assertSame(18.5, $giocatore->quotazione_iniziale);
        $this->assertTrue($giocatore->role_confirmed);
    }

    public function test_confermare_il_ruolo_non_verifica_l_identita(): void
    {
        // Due domande diverse: «che ruolo ha» e «è davvero lui». Confonderle
        // renderebbe la verifica un passaggio automatico, cioè inutile.
        $giocatore = $this->giocatore();

        $this->actingAs($this->admin())->patch(route('admin.players.update', $giocatore), [
            'role' => 'A',
            'quotazione_iniziale' => 20,
        ]);

        $this->assertTrue($giocatore->fresh()->role_confirmed);
        $this->assertFalse($giocatore->player->fresh()->photo_verified);
    }

    public function test_la_verifica_dell_identita_si_puo_anche_revocare(): void
    {
        // Ci si accorge di uno scambio di persona anche dopo averlo confermato.
        $giocatore = $this->giocatore(['photo_verified' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('listone.verify', $giocatore));
        $this->assertTrue($giocatore->player->fresh()->photo_verified);

        $this->actingAs($admin)->post(route('listone.verify', $giocatore));
        $this->assertFalse($giocatore->player->fresh()->photo_verified);
    }

    public function test_un_ruolo_inventato_viene_rifiutato(): void
    {
        $giocatore = $this->giocatore(['role' => 'C']);

        $this->actingAs($this->admin())
            ->patch(route('admin.players.update', $giocatore), [
                'role' => 'X',
                'quotazione_iniziale' => 10,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('C', $giocatore->fresh()->role->value);
        $this->assertFalse($giocatore->fresh()->role_confirmed);
    }

    public function test_una_quotazione_negativa_viene_rifiutata(): void
    {
        $giocatore = $this->giocatore();

        $this->actingAs($this->admin())
            ->patch(route('admin.players.update', $giocatore), [
                'role' => 'A',
                'quotazione_iniziale' => -5,
            ])
            ->assertSessionHasErrors('quotazione_iniziale');
    }
}
