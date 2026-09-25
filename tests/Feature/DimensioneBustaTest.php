<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Services\Draft\DraftBuilder;
use App\Services\Draft\PackGenerator;
use App\Services\Draft\PackOpener;
use App\Services\Lineup\ModuleValidator;
use App\Services\Scoring\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * La forma del draft è una taratura, non una struttura.
 *
 * Cinque buste da cinque sono i valori di partenza, ma tre da otto — o
 * qualunque altra combinazione — devono funzionare allo stesso modo: stessa
 * garanzia di composizione, stessa esclusività del pool, stessa drammaturgia.
 *
 * Questi test esistono perché «è configurabile» è facile da dire e facile da
 * rompere: basta un `min(3, ...)` da qualche parte, scritto quando le buste
 * erano da cinque, e la carta migliore finisce a metà invece che in fondo.
 */
class DimensioneBustaTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    /**
     * Un draft pronto con la forma richiesta.
     *
     * @return array{LeagueSeason, Draft, Collection<int,Manager>}
     */
    private function draft(int $giri, int $cartePerBusta, int $manager = 3): array
    {
        $stagione = $this->makeLeague('Lega', [
            'draft' => ['giri' => $giri, 'carte_per_busta' => $cartePerBusta],
        ]);

        $squadre = collect(range(1, $manager))
            ->map(fn (int $i) => $this->makeManager($stagione, "Squadra {$i}"));

        $this->makeFixture(2, now()->subDay()->toDateTimeString());
        $this->makeFixture(3, now()->addDays(2)->toDateTimeString());

        // Un listone abbondante: qui si prova la forma della busta, non cosa
        // succede quando il pool si esaurisce.
        $composizione = str_repeat('P', 20).str_repeat('D', 40).str_repeat('C', 40).str_repeat('A', 30);

        foreach (str_split($composizione) as $ruolo) {
            $this->makePlayerPower($this->makePlayer($ruolo), 3, stagione: $stagione);
        }

        $draft = app(DraftBuilder::class)->build($stagione, 3);
        $draft->update(['state' => 'open']);
        app(PackOpener::class)->activateNext($draft);

        return [$stagione, $draft->fresh(), $squadre];
    }

    // ───────────────────────── la forma arriva dove serve ─────────────────────────

    public function test_la_taratura_della_stagione_governa_il_draft(): void
    {
        [, $draft] = $this->draft(giri: 3, cartePerBusta: 8);

        // ⚠️ Scritti sul draft e non riletti dalle impostazioni a ogni pesca:
        // ritoccare le regole a draft aperto non deve cambiare la forma di una
        // busta a metà strada.
        $this->assertSame(3, $draft->rounds);
        $this->assertSame(8, $draft->pack_size);
    }

    public function test_i_turni_sono_giri_per_manager(): void
    {
        [, $draft, $squadre] = $this->draft(giri: 3, cartePerBusta: 8);

        $this->assertSame(3 * $squadre->count(), $draft->turns()->count());
    }

    public function test_una_busta_da_otto_da_otto_carte(): void
    {
        [, $draft] = $this->draft(giri: 3, cartePerBusta: 8);

        $turno = $draft->activeTurn();
        $busta = app(PackOpener::class)->open($turno, 'manager');

        $this->assertCount(8, $busta);
        $this->assertSame(8, Card::where('draft_turn_id', $turno->id)->count());
    }

    public function test_la_rosa_finale_e_giri_per_carte(): void
    {
        [$stagione, $draft, $squadre] = $this->draft(giri: 3, cartePerBusta: 8);

        $opener = app(PackOpener::class);

        while ($turno = $draft->fresh()->activeTurn()) {
            $opener->open($turno, 'auto');
        }

        foreach ($squadre as $manager) {
            $this->assertSame(
                24,
                Card::where('league_season_id', $stagione->id)
                    ->where('owner_manager_id', $manager->id)
                    ->where('matchday', 3)
                    ->count(),
                "«{$manager->name}» non ha ricevuto ventiquattro carte",
            );
        }
    }

    public function test_la_garanzia_regge_anche_con_tre_buste(): void
    {
        // Con cinque buste ci sono cinque occasioni per correggere il tiro,
        // con tre ce ne sono tre: è il caso in cui il riempimento a deficit
        // deve lavorare di più, ed è il motivo per cui questo test esiste.
        [$stagione, $draft, $squadre] = $this->draft(giri: 3, cartePerBusta: 8);

        $opener = app(PackOpener::class);

        while ($turno = $draft->fresh()->activeTurn()) {
            $opener->open($turno, 'auto');
        }

        foreach ($squadre as $manager) {
            $conteggi = Card::where('league_season_id', $stagione->id)
                ->where('owner_manager_id', $manager->id)
                ->where('matchday', 3)
                ->get()
                ->countBy('role')
                ->all();

            foreach (ModuleValidator::GUARANTEE as $ruolo => $minimo) {
                $this->assertGreaterThanOrEqual(
                    $minimo,
                    $conteggi[$ruolo] ?? 0,
                    "«{$manager->name}» non arriva al minimo di {$ruolo}",
                );
            }

            $this->assertNotEmpty(
                ModuleValidator::playable($conteggi),
                "«{$manager->name}» non compone nessun modulo",
            );
        }
    }

    public function test_il_pool_resta_esclusivo_a_qualunque_dimensione(): void
    {
        [$stagione, $draft] = $this->draft(giri: 3, cartePerBusta: 8);

        $opener = app(PackOpener::class);

        while ($turno = $draft->fresh()->activeTurn()) {
            $opener->open($turno, 'auto');
        }

        $carte = Card::where('league_season_id', $stagione->id)
            ->where('matchday', 3)
            ->pluck('player_id');

        $this->assertSame($carte->count(), $carte->unique()->count());
    }

    // ───────────────────────── la drammaturgia ─────────────────────────

    public function test_la_carta_migliore_sta_in_penultima_posizione(): void
    {
        // La regola è «penultima», non «quarta»: con buste da cinque le due
        // cose coincidevano, e un `min(3, ...)` scritto allora avrebbe messo il
        // colpo a metà di una busta da otto.
        foreach ([2, 5, 8, 10] as $quante) {
            $busta = $this->bustaFinta($quante);

            $ordinata = app(PackGenerator::class)->orderForReveal($busta);

            $this->assertCount($quante, $ordinata);

            $posizione = $ordinata->search(fn (Card $c) => $c->tier === 'leggendaria');

            $this->assertSame(
                max(0, $quante - 2),
                $posizione,
                "con {$quante} carte la migliore non è in penultima posizione",
            );
        }
    }

    public function test_il_riordino_non_perde_ne_duplica_carte(): void
    {
        $busta = $this->bustaFinta(8);

        $ordinata = app(PackGenerator::class)->orderForReveal($busta);

        $this->assertSame(
            $busta->pluck('id')->sort()->values()->all(),
            $ordinata->pluck('id')->sort()->values()->all(),
        );
    }

    // ───────────────────────── le altre tarature ─────────────────────────

    public function test_cinque_per_cinque_continua_a_funzionare(): void
    {
        // I valori di partenza restano quelli: cambiare la forma non deve
        // rompere chi non la cambia.
        [$stagione, $draft, $squadre] = $this->draft(giri: 5, cartePerBusta: 5);

        $this->assertSame(5, $draft->pack_size);

        $opener = app(PackOpener::class);

        while ($turno = $draft->fresh()->activeTurn()) {
            $opener->open($turno, 'auto');
        }

        $this->assertSame(25, Card::where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $squadre->first()->id)
            ->where('matchday', 3)
            ->count());
    }

    public function test_una_busta_sola_da_dodici_carte_regge_la_garanzia(): void
    {
        // Il caso estremo: un giro solo, nessuna seconda occasione. Se il
        // riempimento a deficit sbaglia qui, sbaglia dappertutto.
        [$stagione, $draft, $squadre] = $this->draft(giri: 1, cartePerBusta: 12, manager: 2);

        $opener = app(PackOpener::class);

        while ($turno = $draft->fresh()->activeTurn()) {
            $opener->open($turno, 'auto');
        }

        foreach ($squadre as $manager) {
            $conteggi = Card::where('league_season_id', $stagione->id)
                ->where('owner_manager_id', $manager->id)
                ->get()
                ->countBy('role')
                ->all();

            $this->assertNotEmpty(ModuleValidator::playable($conteggi));
        }
    }

    public function test_la_pagina_delle_regole_accetta_otto_per_tre(): void
    {
        $stagione = $this->makeLeague();
        $admin = tap($this->makeManager($stagione, 'Admin'))->update(['is_admin' => true]);

        $d = Settings::DEFAULTS;

        $this->actingAs($admin)->post(route('admin.regole.update'), [
            'eventi' => $d['eventi'],
            'voto_base' => $d['voto_base'],
            'senza_voto' => $d['senza_voto'],
            'sostituzioni' => $d['sostituzioni'],
            'formazione_mancante' => $d['formazione_mancante'],
            'power' => $d['power'],
            // Il resto del blocco viene dai default: la pagina è un form solo e
            // li invia tutti, quindi elencarne a mano una parte proverebbe una
            // richiesta che non esiste — e si romperebbe a ogni parametro nuovo.
            'draft' => ['giri' => 3, 'carte_per_busta' => 8] + $d['draft'],
            'sfida' => $d['sfida'],
            'calendario' => $d['calendario'],
        ])->assertSessionHasNoErrors();

        Settings::dimentica();

        $settings = Settings::for($stagione->fresh());

        $this->assertSame(3, $settings->giriDraft());
        $this->assertSame(8, $settings->cartePerBusta());
    }

    /**
     * Una busta finta con una sola Leggendaria, per seguirla nel riordino.
     *
     * @return Collection<int,Card>
     */
    private function bustaFinta(int $quante): Collection
    {
        return collect(range(1, $quante))->map(fn (int $i) => new Card([
            'id' => $i,
            'tier' => $i === 1 ? 'leggendaria' : 'comune',
            'role' => 'C',
        ]))->each(fn (Card $c, int $i) => $c->id = $i + 1);
    }
}
