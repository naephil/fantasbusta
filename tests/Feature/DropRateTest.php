<?php

namespace Tests\Feature;

use App\Enums\Tier;
use App\Models\Card;
use App\Models\DraftPoolEntry;
use App\Models\PlayerPower;
use App\Models\PlayerSeason;
use App\Services\Draft\DraftBuilder;
use App\Services\Power\PowerUpdater;
use App\Services\Season\SeasonRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Che i giocatori forti finiscano davvero in rosa.
 *
 * ⚠️ Al primo test coi volontari «molti big sono rimasti liberi», e non era
 * sfortuna: era aritmetica. Le probabilità di estrazione erano costanti fisse
 * (2% Leggendaria) e non parlavano né con la piramide del listone né con quante
 * carte il draft distribuisce. Con 300 slot su un pool da ~540, il 2% produceva
 * sei Leggendarie estratte su sedici esistenti — dieci big a spasso, ogni volta.
 *
 * In più i tier erano assegnati per percentile GLOBALE, quindi la piramide era
 * quasi solo attaccanti: il miglior portiere del campionato usciva Comune.
 */
class DropRateTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚠️ Il caso, qui, va inchiodato — e per un po' non si poteva.
         *
         * Queste sono misure statistiche: quanto in alto pesca il migliore
         * contro il peggiore, se i pregiati si spartiscono fra i dodici. Senza
         * seme diventavano rosse a caso qualche volta su dieci, e un test che
         * lampeggia insegna a ignorare i rossi — a quel punto ha smesso di
         * difendere qualcosa.
         *
         * Le fonti di rumore erano due. Il listone di prova, costruito con
         * `mt_rand()`, dipendeva dallo stato globale del generatore, cioè da
         * quanto caso avevano consumato i test girati prima: bastava aggiungere
         * un file che viene prima in ordine alfabetico. E la pescata vera
         * passava da `inRandomOrder()`, cioè da un `ORDER BY RANDOM()` eseguito
         * dal DATABASE, che nessun seme PHP può raggiungere e che SQLite non
         * lascia seminare in nessun modo.
         *
         * La seconda è stata tolta alla radice: `PackGenerator` adesso sorteggia
         * in PHP. Con questo seme il draft è riproducibile — stesso seme, stesse
         * buste — e un rosso qui torna a voler dire che è cambiato il codice.
         */
        mt_srand(20260825);
    }

    /**
     * Un listone della forma giusta: tanti giocatori quanti ne ha la Serie A,
     * con la proporzione fra reparti che si trova davvero.
     *
     * @return array<string,int> quanti per ruolo
     */
    private function listone(int $matchday, array $composizione = ['P' => 60, 'D' => 190, 'C' => 180, 'A' => 105]): array
    {
        foreach ($composizione as $ruolo => $quanti) {
            foreach (range(1, $quanti) as $i) {
                // ⚠️ Quotazioni sparpagliate, non tutte uguali. Il power le usa
                // come unica componente per chi non ha ancora giocato: con la
                // quotazione di default identica per tutti il listone di prova
                // esce tutto in parità, e ogni misura sulla piramide
                // misurerebbe l'impalcatura invece del codice.
                $player = $this->makePlayer($ruolo, quotazione: mt_rand(10, 400) / 10);

                $this->makePlayerPower($player, $matchday, power: mt_rand(1, 10000) / 100);
            }
        }

        return $composizione;
    }

    private function stagioneConManager(int $quanti): array
    {
        $stagione = $this->makeLeague();

        $managers = collect(range(1, $quanti))
            ->map(fn (int $i) => $this->makeManager($stagione, "Manager{$i}"))
            ->all();

        return [$stagione, $managers];
    }

    // ───────────────────────── la piramide per ruolo ─────────────────────────

    public function test_ogni_reparto_ha_la_sua_piramide(): void
    {
        // Assegnati in globale, i tier alti finivano quasi tutti agli
        // attaccanti — sono loro ad accumulare fantapunti — e un portiere
        // Leggendario non esisteva. Al draft un portiere si sceglie fra
        // portieri, quindi la rarità va contata lì dentro.
        $stagione = $this->makeLeague();
        $this->listone(3);

        app(PowerUpdater::class)->update($stagione, 3);

        foreach (['P', 'D', 'C', 'A'] as $ruolo) {
            $ids = PlayerSeason::where('season', self::ANNATA)
                ->where('role', $ruolo)
                ->pluck('player_id');

            $tier = PlayerPower::where('league_season_id', $stagione->id)
                ->where('matchday', 3)
                ->whereIn('player_id', $ids)
                ->pluck('tier')
                ->countBy();

            $this->assertGreaterThan(0, $tier[Tier::Leggendaria->value] ?? 0, "nessun Leggendario fra i {$ruolo}");
            $this->assertGreaterThan(0, $tier[Tier::Epica->value] ?? 0, "nessun Epico fra i {$ruolo}");
        }
    }

    public function test_la_piramide_resta_una_piramide(): void
    {
        // Per ruolo non vuol dire più carte pregiate: le quote sono le stesse,
        // solo contate dentro il reparto.
        //
        // ⚠️ E contate su CHI È IN GIOCO, non sul listone intero: le carte
        // sotto soglia prendono una fascia loro e restano fuori dai percentili.
        // Dividere per il totale darebbe quote sempre più basse man mano che il
        // listone si riempie di gente che non gioca — che è esattamente la
        // deformazione per cui le due fasce di fondo esistono.
        $stagione = $this->makeLeague();
        $this->listone(3);

        app(PowerUpdater::class)->update($stagione, 3);

        $tutti = PlayerPower::where('league_season_id', $stagione->id)
            ->where('matchday', 3)
            ->pluck('tier')
            ->countBy();

        // ⚠️ Qui il fondo è vuoto, e non è una svista: questo listone non ha
        // nessuna partita giocata, quindi fantamedia e forma valgono 0.5 per
        // tutti e il power minimo possibile è già sopra le soglie. Il conto
        // resta comunque scritto sul denominatore giusto, così il giorno che
        // qualcuno aggiunge delle prestazioni qui il test non comincia a
        // mentire in silenzio.
        $fondo = ($tutti[Tier::Pacco->value] ?? 0) + ($tutti[Tier::Monnezza->value] ?? 0);
        $inGioco = array_sum($tutti->all()) - $fondo;

        $this->assertEqualsWithDelta(0.03, ($tutti[Tier::Leggendaria->value] ?? 0) / $inGioco, 0.015);
        $this->assertEqualsWithDelta(0.12, ($tutti[Tier::Epica->value] ?? 0) / $inGioco, 0.03);
    }

    public function test_chi_non_gioca_resta_ordinato_per_quotazione(): void
    {
        // ⚠️ La panchina del campionato è la fascia più numerosa del listone:
        // quotazione bassa e nessun minuto. Sottraendo il rischio finivano
        // tutti sotto zero e venivano schiacciati su 0.000 da un `max()`, e in
        // parità la classifica la decide lo spareggio sull'id — «chi è Rara e
        // chi è Comune» diventava un sorteggio proprio lì dentro.
        $stagione = $this->makeLeague();

        // Nessuno di questi ha statistiche: differiscono solo per quotazione.
        $caro = $this->makePlayer('C', quotazione: 30.0);
        $medio = $this->makePlayer('C', quotazione: 15.0);
        $economico = $this->makePlayer('C', quotazione: 3.0);

        app(PowerUpdater::class)->update($stagione, 3);

        $power = fn ($p) => (float) PlayerPower::where('league_season_id', $stagione->id)
            ->where('matchday', 3)
            ->where('player_id', $p->id)
            ->value('power');

        $this->assertGreaterThan($power($medio), $power($caro));
        $this->assertGreaterThan($power($economico), $power($medio));

        // E nessuno finisce a zero solo perché non ha ancora giocato.
        $this->assertGreaterThan(0, $power($economico));
    }

    public function test_nessun_blocco_di_pari_merito_in_fondo_alla_classifica(): void
    {
        // ⚠️ Non si conta quanti valori distinti ci sono — due giocatori con la
        // stessa quotazione e nessuna partita SONO equivalenti, e va bene che
        // pareggino. Quello che non deve esistere è il BLOCCO: centinaia di
        // giocatori schiacciati sullo stesso numero da un `max()`, dove la
        // classifica la decide lo spareggio sull'id.
        $stagione = $this->makeLeague();
        $this->listone(3);

        app(PowerUpdater::class)->update($stagione, 3);

        $valori = PlayerPower::where('league_season_id', $stagione->id)
            ->where('matchday', 3)
            ->pluck('power');

        $ripetuti = $valori->countBy()->max();

        // Una soglia al 10% e non più stretta: qualche pari merito resta
        // legittimo — giocatori con la stessa quotazione e nessuna partita — e
        // in questo listone di prova le quotazioni stanno su una griglia da un
        // decimo, quindi le collisioni sono aritmetica. Il difetto vero era
        // un ordine di grandezza sopra: quasi metà del listone su un valore solo.
        $this->assertLessThan(
            $valori->count() * 0.1,
            $ripetuti,
            'un blocco di giocatori in parità: la classifica lì dentro la decide l\'id',
        );

        // E nessuno finisce a zero solo perché non ha ancora giocato: era
        // proprio il fondo schiacciato dalla sottrazione del rischio.
        $this->assertGreaterThan(0, $valori->min());
    }

    // ───────────────────────── i top finiscono in rosa ─────────────────────────

    public function test_i_leggendari_non_restano_a_spasso(): void
    {
        // La prova che riproduce il difetto: draft intero, dodici manager,
        // e si conta quanti Leggendari sono rimasti nel pool alla fine.
        [$stagione, $managers] = $this->stagioneConManager(12);
        $this->listone(3);
        $this->makeFixture(2, '2026-09-04 20:45:00');
        $this->makeFixture(3, '2026-09-11 20:45:00');

        app(PowerUpdater::class)->update($stagione, 3);

        $draft = app(DraftBuilder::class)->build($stagione, 3);
        app(SeasonRunner::class)->concludiDraft($stagione);

        $nelPool = fn (string $tier) => DraftPoolEntry::where('draft_id', $draft->id)
            ->where('tier', $tier)
            ->count();

        $liberi = fn (string $tier) => DraftPoolEntry::where('draft_id', $draft->id)
            ->where('tier', $tier)
            ->where('status', 'available')
            ->count();

        $leggendari = $nelPool(Tier::Leggendaria->value);
        $this->assertGreaterThan(10, $leggendari, 'il listone di prova deve avere abbastanza Leggendari');

        // Col difetto ne restavano liberi due terzi. Adesso il budget di slot
        // li copre tutti, quindi ne deve restare al massimo qualcuno.
        $this->assertLessThanOrEqual(
            (int) ceil($leggendari * 0.15),
            $liberi(Tier::Leggendaria->value),
            'troppi Leggendari rimasti liberi',
        );

        $this->assertLessThanOrEqual(
            (int) ceil($nelPool(Tier::Epica->value) * 0.25),
            $liberi(Tier::Epica->value),
            'troppi Epici rimasti liberi',
        );
    }

    public function test_i_top_si_spartiscono_fra_i_manager(): void
    {
        // ⚠️ Il test precedente contava solo quante carte pregiate USCIVANO dal
        // pool, e passava anche quando finivano tutte a due o tre manager. È il
        // buco da cui è passato un bug vero: il budget della cascata erano le
        // carte residue del singolo manager invece che quelle di tutto il
        // draft, quindi chi pescava per primo si portava via la cima del
        // listone e agli altri restavano i Comuni.
        [$stagione, $managers] = $this->stagioneConManager(12);
        $this->listone(3);
        $this->makeFixture(2, '2026-09-04 20:45:00');
        $this->makeFixture(3, '2026-09-11 20:45:00');

        app(PowerUpdater::class)->update($stagione, 3);
        app(DraftBuilder::class)->build($stagione, 3);
        app(SeasonRunner::class)->concludiDraft($stagione);

        $pregiatePer = collect($managers)->map(fn ($m) => Card::where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $m->id)
            ->whereIn('tier', [Tier::Leggendaria->value, Tier::Epica->value])
            ->count());

        // Nessuno deve restare a secco, e nessuno deve prendersene un quarto:
        // la fortuna della busta va bene, il saccheggio no.
        $this->assertGreaterThan(0, $pregiatePer->min(), 'un manager è rimasto senza nessuna carta pregiata');

        $totale = $pregiatePer->sum();
        $this->assertLessThan(
            $totale * 0.25,
            $pregiatePer->max(),
            'un solo manager si è preso troppa parte delle carte pregiate',
        );
    }

    public function test_le_rose_hanno_tutte_la_stessa_sostanza(): void
    {
        // L'altra faccia: non basta spartire i Leggendari se poi metà lega
        // riempie la rosa di Comuni. Si guarda il rango medio della rosa —
        // quanto in alto pesca ciascuno nel listone — e non deve divergere.
        [$stagione, $managers] = $this->stagioneConManager(12);
        $this->listone(3);
        $this->makeFixture(2, '2026-09-04 20:45:00');
        $this->makeFixture(3, '2026-09-11 20:45:00');

        app(PowerUpdater::class)->update($stagione, 3);
        app(DraftBuilder::class)->build($stagione, 3);
        app(SeasonRunner::class)->concludiDraft($stagione);

        $ranghi = PlayerPower::where('league_season_id', $stagione->id)
            ->where('matchday', 3)
            ->pluck('rank', 'player_id');

        $medie = collect($managers)->map(fn ($m) => Card::where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $m->id)
            ->pluck('player_id')
            ->map(fn (int $id) => $ranghi[$id] ?? 0)
            ->avg());

        // Il migliore non deve pescare mediamente il doppio più in alto del
        // peggiore: sotto quella soglia è varianza, sopra è un altro gioco.
        //
        // La soglia regge stretta perché la pescata è riproducibile: misurata
        // su otto semi il rapporto sta fra 1,54 e 2,04, quasi sempre intorno a
        // 1,6. Se la cascata dei tier si rompe, il migliore pesca quattro o
        // cinque volte più in alto — non 2,1 — quindi il margine non nasconde
        // niente di quello che questo test deve vedere.
        $this->assertLessThan(2.0, $medie->max() / max(1, $medie->min()));
    }

    public function test_i_top_si_spartiscono_fra_i_reparti(): void
    {
        // Non basta che escano: devono uscire in tutti i ruoli, altrimenti si
        // torna a dodici rose piene di attaccanti pregiati e portieri Comuni.
        [$stagione] = $this->stagioneConManager(12);
        $this->listone(3);
        $this->makeFixture(2, '2026-09-04 20:45:00');
        $this->makeFixture(3, '2026-09-11 20:45:00');

        app(PowerUpdater::class)->update($stagione, 3);
        app(DraftBuilder::class)->build($stagione, 3);
        app(SeasonRunner::class)->concludiDraft($stagione);

        $pregiate = Card::where('league_season_id', $stagione->id)
            ->whereIn('tier', [Tier::Leggendaria->value, Tier::Epica->value])
            ->pluck('role')
            ->countBy();

        foreach (['P', 'D', 'C', 'A'] as $ruolo) {
            $this->assertGreaterThan(0, $pregiate[$ruolo] ?? 0, "nessuna carta pregiata di ruolo {$ruolo}");
        }
    }

    public function test_un_pool_piu_piccolo_degli_slot_non_rompe_niente(): void
    {
        // Il caso opposto: se il listone è più corto di quello che il draft
        // distribuirebbe, le quote non devono andare in negativo né azzerarsi.
        // Resta sopra la garanzia — 12 × 11 = 132 — perché sotto quella soglia
        // il draft si rifiuta di partire, ed è un'altra prova.
        [$stagione] = $this->stagioneConManager(12);
        $this->listone(3, ['P' => 20, 'D' => 50, 'C' => 50, 'A' => 30]);
        $this->makeFixture(2, '2026-09-04 20:45:00');
        $this->makeFixture(3, '2026-09-11 20:45:00');

        app(PowerUpdater::class)->update($stagione, 3);
        $draft = app(DraftBuilder::class)->build($stagione, 3);

        app(SeasonRunner::class)->concludiDraft($stagione);

        // Tutto quello che c'era è stato distribuito, e nessuna carta è finita
        // a due manager: l'unique sul pool resta la rete finale.
        $this->assertSame(0, DraftPoolEntry::where('draft_id', $draft->id)->where('status', 'available')->count());

        $carte = Card::where('league_season_id', $stagione->id)->pluck('player_id');
        $this->assertSame($carte->count(), $carte->unique()->count());
    }
}
