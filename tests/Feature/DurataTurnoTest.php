<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\LeagueSeason;
use App\Services\Draft\DraftBuilder;
use App\Services\Scoring\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Quanto dura un turno di draft.
 *
 * La durata vera è il tempo che resta diviso per i turni che mancano: è ciò che
 * fa auto-comprimere il draft senza che nessuno rifaccia i conti. Quello che si
 * tara per lega sono i due estremi entro cui quel conto può muoversi, e il
 * minimo è il più interessante dei due — nel gioco vero evita turni che nessuno
 * vedrebbe mai passare, in una stagione di prova è l'unica cosa fra chi
 * collauda e venti ore di attesa per una giornata.
 */
class DurataTurnoTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    /**
     * Un draft con dieci turni: due manager per cinque giri.
     *
     * @param  array<string,mixed>|null  $settings
     */
    private function draft(?array $settings = null): Draft
    {
        $stagione = $this->makeLeague('Lega di prova', $settings);

        $this->makeManager($stagione, 'Ada');
        $this->makeManager($stagione, 'Bruno');

        $this->makeFixture(2, now()->subDay()->toDateTimeString());
        $this->makeFixture(3, now()->addDays(6)->toDateTimeString());

        // Listone abbondante: il pool deve bastare a rendere schierabili tutti.
        collect(str_split(str_repeat('P', 12).str_repeat('D', 28).str_repeat('C', 28).str_repeat('A', 20)))
            ->each(fn (string $ruolo) => $this->makePlayerPower($this->makePlayer($ruolo), 3, stagione: $stagione));

        Settings::dimentica();

        return (new DraftBuilder)->build($stagione, 3);
    }

    /** La finestra è così stretta che il conto finisce sotto il minimo. */
    private function draftStretto(?array $settings = null): Draft
    {
        return tap($this->draft($settings), fn (Draft $d) => $d->update([
            'deadline_at' => now()->addMinutes(5),
        ]));
    }

    protected function tearDown(): void
    {
        Settings::dimentica();

        parent::tearDown();
    }

    public function test_di_base_un_turno_non_scende_sotto_i_venti_minuti(): void
    {
        // Cinque minuti divisi per dieci turni farebbero trenta secondi a testa:
        // il gioco è asincrono, e un turno di trenta secondi nessuno lo vede.
        $this->assertSame(20 * 60, $this->draftStretto()->turnDuration());
    }

    public function test_il_minimo_si_puo_portare_a_un_minuto_per_collaudare(): void
    {
        $draft = $this->draftStretto(['draft' => ['turno_min_minuti' => 1]]);

        $this->assertSame(60, $draft->turnDuration());
    }

    public function test_di_base_un_turno_non_supera_le_quattro_ore(): void
    {
        // La finestra qui è larga giorni: senza tetto, un solo assente terrebbe
        // fermi tutti gli altri per mezza giornata.
        $this->assertSame(4 * 3600, $this->draft()->turnDuration());
    }

    public function test_il_massimo_si_puo_abbassare(): void
    {
        $draft = $this->draft(['draft' => ['turno_max_minuti' => 30]]);

        $this->assertSame(30 * 60, $draft->turnDuration());
    }

    public function test_la_stagione_puo_scostarsi_dalle_regole_del_gruppo(): void
    {
        $draft = $this->draftStretto();
        $stagione = LeagueSeason::findOrFail($draft->league_season_id);

        $stagione->league->update(['settings' => ['draft' => ['turno_min_minuti' => 10]]]);
        $stagione->update(['settings' => ['draft' => ['turno_min_minuti' => 2]]]);

        Settings::dimentica();

        $this->assertSame(120, $draft->fresh()->turnDuration());
    }
}
