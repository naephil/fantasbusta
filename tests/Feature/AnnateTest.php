<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerStat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Un'annata che manca non deve somigliare a un'annata completa.
 *
 * I due casi producono lo stesso silenzio — niente da scaricare, niente da
 * esportare — ma vogliono dire l'opposto: nel primo manca tutto, nel secondo
 * c'è tutto. Confonderli è costato una serata a cercare di esportare roba che
 * non c'era, con `stagione:scarica` che rispondeva «sono già in casa».
 */
class AnnateTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private const ANNO = 2023;

    private function calendario(int $giornate = 2, string $stato = 'scheduled'): void
    {
        foreach (range(1, $giornate) as $g) {
            $this->makeFixture($g, '2023-09-01 15:00:00', self::ANNO)->update(['status' => $stato]);
        }
    }

    private function statistiche(int $matchday, string $fonte = 'reale', int $quante = 25): void
    {
        foreach (range(1, $quante) as $i) {
            $player = Player::create(['id' => 900000 + $matchday * 100 + $i, 'last_name' => "Tale {$i}"]);

            PlayerStat::create([
                'player_id' => $player->id,
                'season' => self::ANNO,
                'matchday' => $matchday,
                'source' => $fonte,
                'minutes' => 90,
                'rating' => 6.0,
            ]);
        }
    }

    // ───────────────────── quando non c'è niente ─────────────────────

    public function test_scaricare_un_annata_assente_non_dice_che_e_gia_in_casa(): void
    {
        // ⚠️ È il messaggio che ha mandato fuori strada: senza calendario non
        // c'è nemmeno la lista delle giornate, quindi «non c'è niente da fare»
        // era vero e completamente fuorviante.
        $this->artisan('stagione:scarica', ['anno' => self::ANNO])
            ->expectsOutputToContain('non c\'è nemmeno il calendario')
            ->assertFailed();
    }

    public function test_esportare_un_annata_assente_dice_come_rimediare(): void
    {
        $this->artisan('annata:esporta', ['anno' => self::ANNO])
            ->expectsOutputToContain('stagione:carica '.self::ANNO)
            ->assertFailed();
    }

    public function test_quando_manca_dice_cosa_c_e_invece(): void
    {
        // Sapere cosa c'è è metà della risposta: quasi sempre l'annata è
        // un'altra, e senza l'elenco si va a tentativi.
        $this->makeFixture(1, '2024-09-01 15:00:00', 2024);

        $this->artisan('stagione:scarica', ['anno' => self::ANNO])
            ->expectsOutputToContain('In casa ci sono: 2024');
    }

    public function test_annate_su_una_casa_vuota_lo_dice(): void
    {
        $this->artisan('annate')
            ->expectsOutputToContain('nessuna annata')
            ->assertSuccessful();
    }

    // ───────────────────── quando c'è ─────────────────────

    public function test_annate_elenca_quello_che_c_e(): void
    {
        $this->calendario();
        $this->statistiche(1);

        $this->artisan('annate')
            ->expectsOutputToContain('2023/24')
            ->assertSuccessful();
    }

    public function test_le_simulate_non_contano_come_scaricate(): void
    {
        // Una giornata simulata è un segnaposto: sommarla al totale farebbe
        // credere di avere un'annata che invece è per metà inventata.
        $this->calendario();
        $this->statistiche(1, 'reale');
        $this->statistiche(2, 'simulata');

        $this->artisan('annate')->expectsOutputToContain('1 su 2');
    }

    public function test_con_tutto_in_casa_dice_che_e_gia_tutto_in_casa(): void
    {
        // Una sola partita, finita, con venticinque righe reali: la giornata è
        // completa e non c'è davvero più niente da scaricare.
        $this->calendario(1, 'finished');
        $this->statistiche(1);

        $this->artisan('stagione:scarica', ['anno' => self::ANNO])
            ->expectsOutputToContain('già in casa')
            ->assertSuccessful();
    }
}
