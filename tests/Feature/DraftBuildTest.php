<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPoolEntry;
use App\Models\DraftTurn;
use App\Models\Player;
use App\Models\PlayerPower;
use App\Models\PlayerSeason;
use App\Models\Standing;
use App\Services\Draft\DraftBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Preparazione del draft: pool esclusivo e coda dei turni snake.
 */
class DraftBuildTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private DraftBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new DraftBuilder;
    }

    /**
     * Listone di prova col power già calcolato per la giornata.
     *
     * @return Collection<int,Player>
     */
    /**
     * ⚠️ La composizione di partenza copre la garanzia di TRE manager —
     * 3 × {P:1 D:4 C:4 A:2} — perché sotto quella soglia il draft si rifiuta
     * ora di partire: un pool che non rende tutti schierabili è un guasto, non
     * uno scenario. Chi prova altro passa la propria stringa.
     */
    private function listone(int $matchday, string $composizione = 'PPPPDDDDDDDDDDDDCCCCCCCCCCCCAAAAAAAA'): Collection
    {
        return collect(str_split($composizione))
            ->map(function (string $ruolo) use ($matchday) {
                $player = $this->makePlayer($ruolo);
                $this->makePlayerPower($player, $matchday);

                return $player;
            });
    }

    /** Le due date che delimitano la finestra del draft. */
    private function fixtures(int $matchday): void
    {
        $this->makeFixture($matchday - 1, '2026-09-04 20:45:00');
        $this->makeFixture($matchday, '2026-09-11 20:45:00');
    }

    // ───────────────────────── il pool ─────────────────────────

    public function test_il_pool_contiene_tutto_il_listone_con_i_tier_congelati(): void
    {
        $league = $this->makeLeague();
        $this->makeManager($league, 'Marco');
        $this->fixtures(3);

        $listone = $this->listone(3);

        PlayerPower::where('player_id', $listone->first()->id)
            ->where('matchday', 3)
            ->update(['tier' => 'leggendaria']);

        $draft = $this->builder->build($league, 3);

        // Un solo giocatore è stato promosso a Leggendaria nel power:
        // il pool deve riportare il tier tale e quale.
        $this->assertSame($listone->count(), $draft->pool()->count());
        $this->assertSame(1, $draft->pool()->where('tier', 'leggendaria')->count());
        $this->assertSame($listone->count(), $draft->pool()->where('status', 'available')->count());
    }

    public function test_i_giocatori_disattivati_restano_fuori_dal_pool(): void
    {
        $league = $this->makeLeague();
        $this->makeManager($league, 'Marco');
        $this->fixtures(3);

        $listone = $this->listone(3);
        PlayerSeason::where('player_id', $listone->first()->id)->update(['active' => false]);

        $draft = $this->builder->build($league, 3);

        $this->assertSame($listone->count() - 1, $draft->pool()->count());
    }

    public function test_senza_power_score_il_draft_non_si_prepara(): void
    {
        // Il pool congela i tier dal power: senza, non c'è rarità da
        // distribuire e il draft sarebbe una pesca alla cieca.
        $league = $this->makeLeague();
        $this->makeManager($league, 'Marco');
        $this->fixtures(3);

        collect(str_split('PDDCCA'))->each(fn (string $r) => $this->makePlayer($r));

        $this->expectExceptionMessage('power:compute 3');

        $this->builder->build($league, 3);
    }

    public function test_un_draft_non_si_prepara_due_volte(): void
    {
        $league = $this->makeLeague();
        $this->makeManager($league, 'Marco');
        $this->fixtures(3);
        $this->listone(3);

        $this->builder->build($league, 3);

        $this->expectException(RuntimeException::class);

        $this->builder->build($league, 3);
    }

    // ───────────────────────── i turni snake ─────────────────────────

    public function test_il_serpente_inverte_l_ordine_a_ogni_giro(): void
    {
        $league = $this->makeLeague();
        $a = $this->makeManager($league, 'Ada');
        $b = $this->makeManager($league, 'Bruno');
        $c = $this->makeManager($league, 'Carla');
        $this->fixtures(3);
        $this->listone(3);

        $draft = $this->builder->build($league, 3, order: [$a->id, $b->id, $c->id]);

        $ordine = DraftTurn::where('draft_id', $draft->id)
            ->orderBy('pick_index')
            ->pluck('manager_id')
            ->all();

        $this->assertSame([
            $a->id, $b->id, $c->id,   // giro 1, in ordine
            $c->id, $b->id, $a->id,   // giro 2, al contrario
            $a->id, $b->id, $c->id,
            $c->id, $b->id, $a->id,
            $a->id, $b->id, $c->id,
        ], $ordine);
    }

    public function test_tutti_i_turni_nascono_in_attesa(): void
    {
        $league = $this->makeLeague();
        collect(['Ada', 'Bruno'])->each(fn (string $n) => $this->makeManager($league, $n));
        $this->fixtures(3);
        $this->listone(3);

        $draft = $this->builder->build($league, 3);

        // 2 manager × 5 giri.
        $this->assertSame(10, $draft->turns()->count());
        $this->assertSame(10, $draft->turns()->where('state', 'waiting')->count());
        $this->assertNull($draft->activeTurn());
    }

    public function test_l_ordine_del_primo_giro_e_l_inverso_della_classifica(): void
    {
        // Il draft della 3ª guarda la classifica dopo la 1ª: quando parte, la
        // 2ª è ancora in corso.
        $league = $this->makeLeague();
        $primo = $this->makeManager($league, 'Ada');
        $ultimo = $this->makeManager($league, 'Bruno');
        $this->fixtures(3);
        $this->listone(3);

        Standing::create(['league_season_id' => $league->id, 'manager_id' => $primo->id, 'matchday' => 1, 'punti' => 3, 'posizione' => 1]);
        Standing::create(['league_season_id' => $league->id, 'manager_id' => $ultimo->id, 'matchday' => 1, 'punti' => 0, 'posizione' => 2]);

        $draft = $this->builder->build($league, 3);

        // Chi sta peggio pesca per primo.
        $this->assertSame(
            $ultimo->id,
            DraftTurn::where('draft_id', $draft->id)->where('pick_index', 1)->firstOrFail()->manager_id,
        );
    }

    public function test_alla_prima_giornata_vale_l_ordine_di_iscrizione(): void
    {
        $league = $this->makeLeague();
        $primo = $this->makeManager($league, 'Ada');
        $this->makeManager($league, 'Bruno');
        $this->fixtures(1);
        $this->listone(1);

        $draft = $this->builder->build($league, 1);

        $this->assertSame(
            $primo->id,
            DraftTurn::where('draft_id', $draft->id)->where('pick_index', 1)->firstOrFail()->manager_id,
        );
    }

    // ───────────────────────── la finestra ─────────────────────────

    public function test_la_finestra_si_ricava_dai_primi_fischi(): void
    {
        $league = $this->makeLeague();
        $this->makeManager($league, 'Marco');
        $this->fixtures(3);
        $this->listone(3);

        $draft = $this->builder->build($league, 3);

        // Venerdì sera più sette giorni, col 43% della finestra al draft:
        // la chiusura cade il lunedì sera, come nel ciclo di §1.
        $this->assertSame('2026-09-04 20:45:00', $draft->opens_at->toDateTimeString());
        $this->assertSame('2026-09-07 20:59:24', $draft->deadline_at->toDateTimeString());
        $this->assertSame('Monday', $draft->deadline_at->format('l'));
    }

    public function test_senza_calendario_di_serie_a_serve_una_data_esplicita(): void
    {
        $league = $this->makeLeague();
        $this->makeManager($league, 'Marco');
        $this->listone(3);

        $this->expectExceptionMessage('manca il calendario');

        $this->builder->build($league, 3);
    }

    // ───────────────────────── il ciclo completo ─────────────────────────

    public function test_dal_draft_preparato_alle_rose_pescate(): void
    {
        // La prova che il ciclo gira da capo a fondo: si prepara il draft, il
        // cron lo apre e lo consuma, e alla fine ognuno ha la sua rosa.
        $league = $this->makeLeague();

        collect(['Ada', 'Bruno', 'Carla'])->each(
            fn (string $n) => $this->makeManager($league, $n)->update(['auto_draft' => true]),
        );

        $this->makeFixture(2, now()->subDay()->toDateTimeString());
        $this->makeFixture(3, now()->addDays(6)->toDateTimeString());

        // 3 manager × 5 giri × 5 carte = 75 carte da pescare.
        $this->listone(3, str_repeat('P', 15).str_repeat('D', 35).str_repeat('C', 35).str_repeat('A', 25));

        $draft = $this->builder->build($league, 3);

        $this->artisan('draft:tick')->assertSuccessful();

        $draft->refresh();

        $this->assertSame('closed', $draft->state);
        $this->assertSame(15, $draft->turns()->where('state', 'done')->count());
        $this->assertSame(75, Card::where('league_season_id', $league->id)->where('matchday', 3)->count());

        // Pool esclusivo: nessun giocatore pescato due volte.
        $pescati = DraftPoolEntry::where('draft_id', $draft->id)->where('status', 'drawn')->count();
        $this->assertSame(75, $pescati);
        $this->assertSame(75, Card::where('matchday', 3)->distinct()->count('player_id'));
    }

    public function test_ogni_manager_esce_dal_draft_con_una_rosa_schierabile(): void
    {
        // È la garanzia della busta vista dall'altro capo: chi ha pescato
        // venticinque carte deve poter comporre almeno il 4-4-2.
        $league = $this->makeLeague();

        collect(['Ada', 'Bruno', 'Carla'])->each(
            fn (string $n) => $this->makeManager($league, $n)->update(['auto_draft' => true]),
        );

        $this->makeFixture(2, now()->subDay()->toDateTimeString());
        $this->makeFixture(3, now()->addDays(6)->toDateTimeString());
        $this->listone(3, str_repeat('P', 15).str_repeat('D', 35).str_repeat('C', 35).str_repeat('A', 25));

        $this->builder->build($league, 3);
        $this->artisan('draft:tick');

        // ⚠️ Il gruppo si raggiunge dalla relazione, non ripescandolo con l'id
        // della stagione: `makeLeague()` restituisce una stagione di lega, e i
        // due id coincidono soltanto finché le due tabelle contano allo stesso
        // passo. Su MariaDB gli autoincrementi non si azzerano fra un test e
        // l'altro, quindi basta un test nuovo che crei una stagione in più
        // perché qui si vada a cercare un gruppo che non esiste.
        foreach ($league->league->managers as $manager) {
            $this->assertTrue(
                $manager->canFieldLineup(3),
                "{$manager->name} è uscito dal draft con una rosa inschierabile.",
            );
        }
    }
}
