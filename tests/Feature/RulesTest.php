<?php

namespace Tests\Feature;

use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\PlayerPower;
use App\Models\PlayerScore;
use App\Models\PlayerStat;
use App\Services\Power\PowerUpdater;
use App\Services\Scoring\MatchdayScorer;
use App\Services\Scoring\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Le regole si tarano per gruppo e per stagione.
 *
 * È la libertà che il gioco promette — ogni lega decide quanto vale un gol e
 * quanto pesa la forma — e insieme la ragione per cui voti e power NON sono
 * condivisibili fra leghe. I test qui sotto difendono le due facce della stessa
 * cosa: che la taratura arrivi davvero al calcolo, e che il calcolo di una lega
 * non si veda in casa dell'altra.
 */
class RulesTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private function statistica(int $playerId, int $matchday = 1, array $extra = []): void
    {
        PlayerStat::create($extra + [
            'player_id' => $playerId,
            'season' => self::ANNATA,
            'matchday' => $matchday,
            'minutes' => 90,
            'rating' => 6.0,
        ]);
    }

    private function admin(LeagueSeason $stagione): Manager
    {
        return tap($this->makeManager($stagione, 'Admin'))->update(['is_admin' => true]);
    }

    // ───────────────────────── i tre livelli ─────────────────────────

    public function test_senza_taratura_valgono_i_default(): void
    {
        $stagione = $this->makeLeague();

        $this->assertSame(3, Settings::for($stagione)->puntiSfida()['vittoria']);
    }

    public function test_le_regole_del_gruppo_valgono_per_le_sue_stagioni(): void
    {
        $stagione = $this->makeLeague();
        $stagione->league->update(['settings' => ['sfida' => ['vittoria' => 5]]]);

        $seconda = $this->makeStagione($stagione, 2022);

        Settings::dimentica();

        $this->assertSame(5, Settings::for($stagione)->puntiSfida()['vittoria']);
        $this->assertSame(5, Settings::for($seconda)->puntiSfida()['vittoria']);
    }

    public function test_la_stagione_puo_scostarsi_dal_gruppo(): void
    {
        // Cambiare idea per un anno solo non deve riscrivere il passato: la
        // classifica del 2023 resta quella che tutti hanno letto allora.
        $stagione = $this->makeLeague();
        $stagione->league->update(['settings' => ['sfida' => ['vittoria' => 5]]]);

        $seconda = $this->makeStagione($stagione, 2022);
        $seconda->update(['settings' => ['sfida' => ['vittoria' => 2]]]);

        Settings::dimentica();

        $this->assertSame(5, Settings::for($stagione)->puntiSfida()['vittoria']);
        $this->assertSame(2, Settings::for($seconda)->puntiSfida()['vittoria']);
    }

    public function test_lo_scostamento_e_per_foglia_non_per_blocco(): void
    {
        // Ritoccare il gol non deve azzerare l'assist: chi cambia un
        // coefficiente non sta ricopiando tutta la tabella.
        $stagione = $this->makeLeague('Lega', ['eventi' => ['gol' => ['default' => 5]]]);

        $settings = Settings::for($stagione);

        $this->assertSame(5.0, $settings->coefficient('gol', 'A'));
        $this->assertSame(1.0, $settings->coefficient('assist', 'A'));
    }

    // ───────────────────────── i voti non si mescolano ─────────────────────────

    public function test_due_gruppi_sulla_stessa_annata_hanno_fantavoti_diversi(): void
    {
        // È il cuore della faccenda: stesso giocatore, stessa partita, stesso
        // rating, ma un gol che vale 3 da una parte e 10 dall'altra. Se i voti
        // fossero condivisi, l'ultimo a calcolare cancellerebbe l'altro.
        $mite = $this->makeLeague('Mite');
        $generosa = $this->makeLeague('Generosa', ['eventi' => ['gol' => ['default' => 10]]]);

        $bomber = $this->makePlayer('A');
        $this->statistica($bomber->id, extra: ['goals' => 1]);

        $scorer = app(MatchdayScorer::class);
        $scorer->scorePlayers($mite, 1);
        $scorer->scorePlayers($generosa, 1);

        $voto = fn (LeagueSeason $s) => PlayerScore::where('league_season_id', $s->id)
            ->where('player_id', $bomber->id)
            ->value('fantavoto');

        $this->assertSame(9.0, (float) $voto($mite));        // 6 + 3
        $this->assertSame(16.0, (float) $voto($generosa));   // 6 + 10
    }

    public function test_ricalcolare_una_lega_non_tocca_l_altra(): void
    {
        $prima = $this->makeLeague('Prima');
        $seconda = $this->makeLeague('Seconda');

        $player = $this->makePlayer('C');
        $this->statistica($player->id);

        $scorer = app(MatchdayScorer::class);
        $scorer->scorePlayers($prima, 1);
        $scorer->scorePlayers($seconda, 1);
        $scorer->scorePlayers($prima, 1);   // il ricalcolo è rieseguibile

        $this->assertSame(2, PlayerScore::where('player_id', $player->id)->count());
    }

    public function test_il_power_di_due_gruppi_resta_separato(): void
    {
        $prima = $this->makeLeague('Prima');
        $seconda = $this->makeLeague('Seconda');

        $this->makePlayer('A');
        $this->makePlayer('D');

        $updater = app(PowerUpdater::class);
        $updater->update($prima, 1);
        $updater->update($seconda, 1);

        $this->assertSame(2, PlayerPower::where('league_season_id', $prima->id)->count());
        $this->assertSame(2, PlayerPower::where('league_season_id', $seconda->id)->count());
    }

    public function test_pesi_diversi_danno_classifiche_di_power_diverse(): void
    {
        // Una lega che guarda solo la quotazione e una che guarda solo la forma
        // non possono avere gli stessi tier: il gioco è un altro.
        $quotazione = $this->makeLeague('Listino', ['power' => ['pesi' => [
            'baseline' => 1.0, 'fantamedia' => 0, 'forma' => 0, 'titolarita' => 0, 'rischio' => 0,
        ]]]);

        $forma = $this->makeLeague('Forma', ['power' => ['pesi' => [
            'baseline' => 0, 'fantamedia' => 0, 'forma' => 1.0, 'titolarita' => 0, 'rischio' => 0,
        ]]]);

        $caro = $this->makePlayer('A', quotazione: 40.0);
        $economico = $this->makePlayer('A', quotazione: 1.0);

        // L'economico gioca meglio; il caro non ha ancora fatto niente.
        $this->makePerformance($caro, 1, 5.0, stagione: $quotazione);
        $this->makePerformance($economico, 1, 9.0, stagione: $quotazione);
        $this->makePerformance($caro, 1, 5.0, stagione: $forma);
        $this->makePerformance($economico, 1, 9.0, stagione: $forma);

        $updater = app(PowerUpdater::class);
        $updater->update($quotazione, 3);
        $updater->update($forma, 3);

        $primo = fn (LeagueSeason $s) => PlayerPower::where('league_season_id', $s->id)
            ->where('matchday', 3)
            ->orderBy('rank')
            ->value('player_id');

        $this->assertSame($caro->id, $primo($quotazione));
        $this->assertSame($economico->id, $primo($forma));
    }

    // ───────────────────────── la pagina ─────────────────────────

    public function test_la_pagina_e_riservata_all_admin(): void
    {
        $stagione = $this->makeLeague();

        $this->actingAs($this->makeManager($stagione, 'Marco'))
            ->get(route('admin.regole.edit'))
            ->assertForbidden();
    }

    public function test_l_admin_ritocca_un_coefficiente(): void
    {
        $stagione = $this->makeLeague();

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form(['eventi' => ['gol' => ['default' => 7]]]))
            ->assertRedirect()
            ->assertSessionHas('successo');

        Settings::dimentica();

        $this->assertSame(7.0, Settings::for($stagione->fresh())->coefficient('gol', 'A'));
    }

    public function test_si_salvano_solo_gli_scostamenti(): void
    {
        // Congelare tutta la tabella significherebbe che un parametro aggiunto
        // domani resta al valore di oggi, per sempre e senza dirlo.
        $stagione = $this->makeLeague();

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form(['eventi' => ['gol' => ['default' => 7]]]));

        $salvato = $stagione->fresh()->settings;

        // Solo il gol, e nient'altro: il resto ricade sui default del codice.
        $this->assertSame(['eventi'], array_keys($salvato));
        $this->assertSame(['gol'], array_keys($salvato['eventi']));
        $this->assertEquals(7, $salvato['eventi']['gol']['default']);
    }

    public function test_un_bonus_si_puo_ribaltare_in_malus(): void
    {
        // Nessun coefficiente ha un segno obbligato: è una scelta di gioco
        // legittima, per quanto bizzarra.
        $stagione = $this->makeLeague();

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form(['eventi' => ['gol' => ['default' => -3]]]))
            ->assertSessionHasNoErrors();

        $bomber = $this->makePlayer('A');
        $this->statistica($bomber->id, extra: ['goals' => 1]);

        Settings::dimentica();
        app(MatchdayScorer::class)->scorePlayers($stagione->fresh(), 1);

        $score = PlayerScore::where('player_id', $bomber->id)->firstOrFail();

        $this->assertSame(3.0, $score->malus);
        $this->assertSame(0.0, $score->bonus);
        $this->assertSame(3.0, $score->fantavoto);
    }

    public function test_i_pesi_del_power_devono_sommare_a_uno(): void
    {
        // Sono quote di una normalizzazione: se non fanno uno, i tier ballano
        // senza che nessuno abbia cambiato prestazione.
        $stagione = $this->makeLeague();

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form(['power' => ['pesi' => [
                'baseline' => 0.5, 'fantamedia' => 0.5, 'forma' => 0.5, 'titolarita' => 0.5, 'rischio' => 0.5,
            ]]]))
            ->assertSessionHasErrors('regole');
    }

    public function test_un_override_di_ruolo_vuoto_non_azzera_l_evento(): void
    {
        // Vuoto significa «non c'è», non «zero»: deve ricadere sul default.
        $stagione = $this->makeLeague();

        $dati = $this->form();
        $dati['eventi']['gol']['D'] = '';

        $this->actingAs($this->admin($stagione))->post(route('admin.regole.update'), $dati);

        Settings::dimentica();

        $this->assertSame(3.0, Settings::for($stagione->fresh())->coefficient('gol', 'D'));
    }

    public function test_le_regole_si_copiano_su_un_altra_stagione(): void
    {
        $stagione = $this->makeLeague('Gruppo', ['eventi' => ['gol' => ['default' => 9]]]);
        $seconda = $this->makeStagione($stagione, 2022);

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.copia'), ['verso' => $seconda->id])
            ->assertSessionHas('successo');

        Settings::dimentica();

        $this->assertSame(9.0, Settings::for($seconda->fresh())->coefficient('gol', 'A'));
    }

    public function test_non_si_copiano_le_regole_su_un_altro_gruppo(): void
    {
        $stagione = $this->makeLeague('Mio');
        $altrui = $this->makeLeague('Altrui');

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.copia'), ['verso' => $altrui->id])
            ->assertSessionHasErrors('regole');
    }

    public function test_la_durata_del_turno_di_draft_si_tara(): void
    {
        // Il minimo di venti minuti è una cortesia nel gioco vero e un muro in
        // una prova: sessanta turni non si aspettano.
        $stagione = $this->makeLeague();

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form([
                'draft' => ['turno_min_minuti' => 1, 'turno_max_minuti' => 5],
            ]))
            ->assertSessionHasNoErrors();

        Settings::dimentica();

        $this->assertSame(60, Settings::for($stagione->fresh())->turnoMinSecondi());
        $this->assertSame(300, Settings::for($stagione->fresh())->turnoMaxSecondi());
    }

    public function test_il_turno_minimo_non_puo_superare_il_massimo(): void
    {
        // A estremi invertiti la stretta restituirebbe sempre il minimo, e il
        // massimo scritto nel form non varrebbe niente senza dirlo a nessuno.
        $stagione = $this->makeLeague();

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form([
                'draft' => ['turno_min_minuti' => 90, 'turno_max_minuti' => 30],
            ]))
            ->assertSessionHasErrors('regole');
    }

    public function test_le_soglie_del_fondo_si_tarano(): void
    {
        $stagione = $this->makeLeague();

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form([
                'power' => ['soglie' => ['pacco' => 25, 'monnezza' => 5]],
            ]))
            ->assertSessionHasNoErrors();

        Settings::dimentica();

        $this->assertSame(25.0, Settings::for($stagione->fresh())->sogliaPacco());
        $this->assertSame(5.0, Settings::for($stagione->fresh())->sogliaMonnezza());
    }

    public function test_salvare_le_regole_non_azzera_le_soglie_gia_tarate(): void
    {
        // ⚠️ `soloScostamenti()` riscrive l'intero blocco salvato: un parametro
        // che il form non invia sparisce al primo salvataggio, anche se si stava
        // ritoccando tutt'altro. Una taratura che si azzera da sola premendo
        // «salva» su un'altra sezione è il genere di guasto che si scopre tre
        // giornate dopo, quando la piramide non torna più.
        $stagione = $this->makeLeague('Gruppo', ['power' => ['soglie' => ['pacco' => 30]]]);

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form([
                'eventi' => ['gol' => ['default' => 4]],
                'power' => ['soglie' => ['pacco' => 30, 'monnezza' => 2]],
            ]))
            ->assertSessionHasNoErrors();

        Settings::dimentica();

        $this->assertSame(30.0, Settings::for($stagione->fresh())->sogliaPacco());
    }

    public function test_la_monnezza_non_puo_stare_sopra_il_pacco(): void
    {
        $stagione = $this->makeLeague();

        $this->actingAs($this->admin($stagione))
            ->post(route('admin.regole.update'), $this->form([
                'power' => ['soglie' => ['pacco' => 5, 'monnezza' => 40]],
            ]))
            ->assertSessionHasErrors('regole');
    }

    public function test_si_torna_ai_valori_di_partenza(): void
    {
        $stagione = $this->makeLeague('Gruppo', ['eventi' => ['gol' => ['default' => 9]]]);

        $this->actingAs($this->admin($stagione))->post(route('admin.regole.azzera'));

        Settings::dimentica();

        $this->assertSame([], $stagione->fresh()->settings);
        $this->assertSame(3.0, Settings::for($stagione->fresh())->coefficient('gol', 'A'));
    }

    /**
     * Il modulo completo, coi default, più eventuali ritocchi.
     *
     * La pagina invia tutto insieme — è un form solo — quindi un test che
     * mandasse il solo campo modificato proverebbe una richiesta che non esiste.
     *
     * @param  array<string,mixed>  $ritocchi
     * @return array<string,mixed>
     */
    private function form(array $ritocchi = []): array
    {
        $d = Settings::DEFAULTS;

        return array_replace_recursive([
            'eventi' => $d['eventi'],
            'voto_base' => $d['voto_base'],
            'senza_voto' => $d['senza_voto'],
            'sostituzioni' => $d['sostituzioni'],
            'formazione_mancante' => $d['formazione_mancante'],
            'power' => $d['power'],
            'draft' => $d['draft'],
            'sfida' => $d['sfida'],
            'calendario' => $d['calendario'],
        ], $ritocchi);
    }
}
