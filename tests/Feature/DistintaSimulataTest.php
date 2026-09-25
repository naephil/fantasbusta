<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerStat;
use App\Services\Simulation\MatchdaySimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Chi finisce nel tabellino di una giornata simulata.
 *
 * ⚠️ I convocati e basta, non tutta la rosa — che è la forma di un tabellino
 * vero, perché l'API riporta la distinta e nessun altro. Scrivere anche gli
 * altri costava due cose. La prima si vedeva: «Il campo» elencava sessanta nomi
 * a squadra, l'organico al completo, invece dei ventitré che sono scesi in
 * campo o si sono seduti in panchina. La seconda no: quelle righe di troppo
 * sopravvivevano all'aggiornamento coi dati veri, con minuti inventati addosso
 * a gente che quel giorno non era nemmeno convocata.
 */
class DistintaSimulataTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    /** Undici titolari più dodici di panchina. */
    private const DISTINTA = 23;

    /** @var Collection<int,Player> in ordine di quotazione, dal più caro */
    private Collection $rosa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeLeague();
        $this->makeFixture(1, '2026-08-23 18:30:00')->update(['home_goals' => 2, 'away_goals' => 1]);

        // Un organico da squadra vera: molto più largo di una distinta, così
        // «tutti» e «i convocati» non possono coincidere per caso. Le
        // quotazioni sono tutte diverse e in ordine calante perché la scelta
        // sia decidibile — la formazione si compone per quotazione, e a parità
        // a decidere sarebbe l'ordine in cui il database restituisce le righe.
        $this->rosa = collect(str_split(
            str_repeat('P', 3).str_repeat('D', 10).str_repeat('C', 10).str_repeat('A', 7),
        ))->map(fn (string $ruolo, int $i) => $this->makePlayer($ruolo, quotazione: 60 - $i, teamId: 500));
    }

    /** @return Collection<int,int> gli id con una statistica alla 1ª */
    private function conStatistica(): Collection
    {
        return PlayerStat::where('season', self::ANNATA)
            ->where('matchday', 1)
            ->pluck('player_id')
            ->map(fn ($id) => (int) $id);
    }

    private function simula(): void
    {
        app(MatchdaySimulator::class)->simulate(self::ANNATA, 1);
    }

    // ───────────────────────── la distinta ─────────────────────────

    public function test_la_statistica_e_solo_per_i_convocati(): void
    {
        $this->simula();

        $this->assertCount(30, $this->rosa, 'la rosa deve essere più larga della distinta');
        $this->assertCount(self::DISTINTA, $this->conStatistica());
    }

    public function test_chi_resta_a_casa_non_ha_nemmeno_una_riga_a_zero(): void
    {
        // Una riga a zero minuti non è innocua: è indistinguibile da un
        // panchinaro mai entrato, e nella schermata della giornata compariva
        // esattamente come lui.
        $ultimo = $this->rosa->last();

        $this->simula();

        $this->assertFalse($this->conStatistica()->contains($ultimo->id));
    }

    public function test_i_piu_quotati_sono_quelli_che_giocano(): void
    {
        // Se giocassero a caso, la titolarità nel power score sarebbe rumore.
        $this->simula();

        $convocati = $this->conStatistica();

        $this->assertTrue($convocati->contains($this->rosa->first()->id));
    }

    public function test_la_panchina_c_e_anche_quando_non_entra(): void
    {
        // Sono i «subentrati e non»: gente che il tabellino elenca senza voto,
        // e che sparirebbe se si tenessero solo quelli con minuti addosso.
        $this->simula();

        $seduti = PlayerStat::where('season', self::ANNATA)
            ->where('matchday', 1)
            ->where('minutes', 0)
            ->count();

        $this->assertGreaterThan(0, $seduti);
        $this->assertLessThan(self::DISTINTA, $seduti);
    }

    // ───────────────────────── risimulare ─────────────────────────

    public function test_risimulare_non_lascia_in_giro_le_righe_di_prima(): void
    {
        // ⚠️ La distinta cambia a ogni simulazione. Sovrascrivendo invece di
        // ripartire da zero, la riga della volta prima resterebbe lì addosso a
        // chi stavolta non era convocato — coi minuti della simulazione
        // precedente, che il calcolo prenderebbe per buoni.
        $rimastoACasa = $this->rosa->last();

        PlayerStat::create([
            'player_id' => $rimastoACasa->id,
            'season' => self::ANNATA,
            'matchday' => 1,
            'minutes' => 90,
            'rating' => 7.0,
            'source' => 'simulata',
        ]);

        $this->simula();

        $this->assertCount(self::DISTINTA, $this->conStatistica());
        $this->assertFalse($this->conStatistica()->contains($rimastoACasa->id));
    }

    public function test_le_altre_giornate_restano_dove_sono(): void
    {
        $this->makeFixture(2, '2026-08-30 18:30:00');
        $this->makePerformance($this->rosa->first(), 2, 7.0);

        $this->simula();

        $this->assertSame(1, PlayerStat::where('matchday', 2)->count());
    }
}
