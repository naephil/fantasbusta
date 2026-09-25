<?php

namespace Tests\Feature;

use App\Enums\Tier;
use App\Models\LeagueSeason;
use App\Models\Player;
use App\Models\PlayerPower;
use App\Services\Power\PowerUpdater;
use App\Services\Scoring\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Le due fasce di fondo: Pacco e Monnezza.
 *
 * ⚠️ Nascono da una deformazione vera, vista in partita: «la maggior parte
 * delle carte è Rara, di Comuni non ce n'è quasi nessuna». Non era un bug del
 * sorteggio, era la popolazione. Il listone porta centinaia di giocatori a
 * quotazione 1 che non hanno mai giocato — terzi portieri, primavera, ceduti a
 * gennaio — e contati nei percentili occupavano tutta la fascia Comune,
 * spingendo in alto tutti gli altri. Metà listone usciva Rara senza che
 * nessuna carta fosse migliorata di un punto: la piramide misurava una
 * popolazione diversa da quella che gioca.
 *
 * Il rimedio ha due metà che vanno insieme: una fascia sua per chi sta sotto
 * soglia, e i percentili contati SOLO su chi resta.
 */
class FondoPiramideTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $stagione;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagione = $this->makeLeague();
    }

    /**
     * ⚠️ Il power NON si può scrivere a mano per provare le soglie.
     *
     * `PowerUpdater::update()` lo RICALCOLA da capo a ogni giro — è il suo
     * mestiere — quindi una riga di `player_power` scritta prima viene
     * semplicemente sovrascritta. Ci sono cascato: i primi test qui sotto
     * imponevano il power e passavano su una regola che non era quella in
     * prova.
     *
     * L'unica leva vera è l'ingresso: per chi non ha ancora giocato il power è
     * la sola quotazione, normalizzata DENTRO il ruolo. Il più economico del
     * reparto finisce quindi a zero e il più caro in cima, e fra i due la scala
     * è continua: basta sparpagliare le quotazioni per avere giocatori sopra e
     * sotto qualunque soglia.
     */
    private function conQuotazione(string $ruolo, float $quotazione, bool $gioca = false): Player
    {
        $player = $this->makePlayer($ruolo, quotazione: $quotazione);

        /*
         * ⚠️ Serve che QUALCUNO abbia giocato, altrimenti le soglie non possono
         * mordere e il test proverebbe il contrario di quello che crede.
         *
         * `Normalizer::minMax` restituisce 0.5 a tutti quando i valori sono
         * tutti uguali — «nessuno si distingue, quindi nessuno deve
         * guadagnarci né rimetterci» — quindi a stagione vergine fantamedia e
         * forma valgono mezzo punto per chiunque, e il power minimo possibile
         * è già intorno a 27. Sotto 10 non ci arriva nessuno.
         *
         * Non è un difetto: è la ragione per cui le due fasce di fondo
         * compaiono man mano che la stagione produce dati, che è anche quando
         * servono. Alla 1ª giornata non si sa ancora chi è uno scarto.
         */
        if ($gioca) {
            foreach ([1, 2] as $g) {
                $this->makePerformance($player, $g, 7.0, stagione: $this->stagione);
            }
        }

        return $player;
    }

    /** @return array{power: float, tier: string} come li ha scritti il calcolo */
    private function esitoDi(Player $player): array
    {
        $riga = PlayerPower::where('league_season_id', $this->stagione->id)
            ->where('player_id', $player->id)
            ->where('matchday', 4)
            ->firstOrFail();

        return ['power' => (float) $riga->power, 'tier' => $riga->tier];
    }

    // ───────────────────────── le soglie ─────────────────────────

    public function test_le_soglie_si_leggono_dalle_regole(): void
    {
        $settings = Settings::for($this->stagione);

        $this->assertSame(10.0, $settings->sogliaPacco());
        $this->assertSame(2.0, $settings->sogliaMonnezza());
    }

    public function test_sotto_due_e_monnezza_sotto_dieci_e_pacco(): void
    {
        $this->assertSame(Tier::Monnezza, Tier::sottoSoglia(1.9, 10, 2));
        $this->assertSame(Tier::Pacco, Tier::sottoSoglia(2.0, 10, 2));
        $this->assertSame(Tier::Pacco, Tier::sottoSoglia(9.9, 10, 2));
        $this->assertNull(Tier::sottoSoglia(10.0, 10, 2), 'a soglia esatta si torna nella piramide');
    }

    public function test_la_soglia_monnezza_non_puo_superare_quella_pacco(): void
    {
        // A soglie invertite «pacco» non esisterebbe più, e la taratura direbbe
        // una cosa diversa da quella che si legge nel form.
        $stagione = $this->makeLeague('Storta', ['power' => ['soglie' => ['pacco' => 5, 'monnezza' => 40]]]);

        Settings::dimentica();

        $this->assertSame(5.0, Settings::for($stagione)->sogliaMonnezza());
    }

    // ───────────────────────── a prescindere dalla posizione ─────────────────────────

    public function test_la_regola_vale_riga_per_riga(): void
    {
        // ⚠️ L'invariante, provato su tutto il listone invece che su un caso
        // scelto: qualunque sia il power calcolato, la fascia che gli tocca è
        // decisa dalle soglie e da nient'altro. Assertare valori assoluti
        // sarebbe fragile — il power è NORMALIZZATO, quindi dipende da chi
        // altro c'è nel reparto — mentre la regola è vera sempre.
        foreach (['P', 'D', 'C', 'A'] as $ruolo) {
            foreach (range(1, 25) as $i) {
                // Metà hanno giocato, metà no: è la forma che ha un listone
                // vero a stagione avviata, ed è l'unica in cui le soglie
                // possono distinguere qualcosa.
                $this->conQuotazione($ruolo, $i * 2.0, gioca: $i % 2 === 0);
            }
        }

        app(PowerUpdater::class)->update($this->stagione, 4);

        $righe = PlayerPower::where('league_season_id', $this->stagione->id)
            ->where('matchday', 4)
            ->get();

        $fondo = 0;

        foreach ($righe as $riga) {
            $power = (float) $riga->power;

            if ($power < 2.0) {
                $this->assertSame(Tier::Monnezza->value, $riga->tier, "power {$power} doveva essere Monnezza");
                $fondo++;
            } elseif ($power < 10.0) {
                $this->assertSame(Tier::Pacco->value, $riga->tier, "power {$power} doveva essere Pacco");
                $fondo++;
            } else {
                $this->assertNotContains($riga->tier, [Tier::Pacco->value, Tier::Monnezza->value],
                    "power {$power} sta sopra soglia e non può essere di fondo");
            }
        }

        $this->assertGreaterThan(0, $fondo, 'con le quotazioni sparpagliate qualcuno sotto soglia ci deve essere');
    }

    public function test_il_fondo_vince_sulla_posizione_nel_ruolo(): void
    {
        // ⚠️ È il senso di «fisse a prescindere dal resto»: in un reparto di
        // soli scarti, il meno peggio resta uno scarto. Coi soli percentili
        // sarebbe uscito Leggendario, perché la piramide non guarda i valori —
        // guarda le posizioni.
        //
        // Due portieri: uno gioca ed è quotato, l'altro è il classico secondo
        // che non scende mai in campo. Su due soli, il peggiore è comunque
        // primo del 50% del reparto.
        $migliore = $this->conQuotazione('P', 40.0, gioca: true);
        $peggiore = $this->conQuotazione('P', 1.0);

        app(PowerUpdater::class)->update($this->stagione, 4);

        $esito = $this->esitoDi($peggiore);

        $this->assertLessThan(2.0, $esito['power'], 'il fondo del reparto deve stare sotto soglia');
        $this->assertSame(Tier::Monnezza->value, $esito['tier'],
            'il peggiore di due è comunque primo del 50%: coi soli percentili sarebbe uscito pregiato');

        // E il migliore, che sta sopra soglia, prende la fascia che gli spetta.
        $this->assertNotContains($this->esitoDi($migliore)['tier'], [Tier::Pacco->value, Tier::Monnezza->value]);
    }

    // ───────────────────────── i percentili contano solo chi gioca ─────────────────────────

    public function test_il_fondo_non_diluisce_piu_la_piramide(): void
    {
        /*
         * ⚠️ Il test che spiega tutto il lavoro.
         *
         * Venti giocatori quotati e ottanta a quotazione minima, che è lo
         * squilibrio del listone vero. Contando i percentili su tutti e cento,
         * la fascia Comune (55%) si riempirebbe di scarti e i venti veri
         * finirebbero quasi tutti nel 45% di sopra: uscirebbero Rari, Epici e
         * Leggendari quasi tutti, e le Comuni sparirebbero. È la cosa vista in
         * partita.
         *
         * Contandoli su chi resta, la piramide torna a dire quello che promette.
         */
        $veri = collect(range(1, 20))
            ->map(fn (int $i) => $this->conQuotazione('A', 20.0 + $i, gioca: true))
            ->all();

        collect(range(1, 80))->each(fn () => $this->conQuotazione('A', 1.0));

        app(PowerUpdater::class)->update($this->stagione, 4);

        $tier = collect($veri)->map(fn (Player $p) => $this->esitoDi($p)['tier'])->countBy();

        // Gli ottanta scarti sono tutti di fondo e non occupano nessuna fascia
        // della piramide.
        $scarti = PlayerPower::where('league_season_id', $this->stagione->id)
            ->where('matchday', 4)
            ->whereIn('tier', [Tier::Pacco->value, Tier::Monnezza->value])
            ->count();

        $this->assertGreaterThanOrEqual(80, $scarti);

        // E fra i venti veri le Comuni restano la maggioranza, invece di
        // sparire schiacciate dalla massa che non gioca.
        $this->assertGreaterThan(
            ($tier[Tier::Rara->value] ?? 0) + ($tier[Tier::Epica->value] ?? 0) + ($tier[Tier::Leggendaria->value] ?? 0),
            $tier[Tier::Comune->value] ?? 0,
            'le Comuni devono tornare a essere la fascia più numerosa fra chi gioca',
        );
    }

    public function test_a_stagione_vergine_nessuno_e_ancora_uno_scarto(): void
    {
        /*
         * ⚠️ Comportamento voluto, e va saputo perché sembra un buco.
         *
         * Prima che si giochi, fantamedia e forma sono uguali per tutti — e
         * `Normalizer::minMax` in quel caso restituisce 0.5 a chiunque, perché
         * «nessuno si distingue, quindi nessuno deve guadagnarci né
         * rimetterci». Il power minimo possibile parte quindi da ~27, e sotto
         * le soglie non ci arriva nessuno.
         *
         * È giusto così: alla 1ª giornata non si sa ancora chi è uno scarto, e
         * marchiare come Monnezza un giocatore che non ha ancora avuto modo di
         * giocare sarebbe una condanna scritta sul nulla. Le due fasce
         * compaiono man mano che la stagione produce dati, cioè quando servono.
         */
        foreach (range(1, 30) as $i) {
            $this->conQuotazione('D', $i * 1.0);
        }

        app(PowerUpdater::class)->update($this->stagione, 1);

        $fondo = PlayerPower::where('league_season_id', $this->stagione->id)
            ->where('matchday', 1)
            ->whereIn('tier', [Tier::Pacco->value, Tier::Monnezza->value])
            ->count();

        $this->assertSame(0, $fondo);
    }

    // ───────────────────────── l'ordine di pregio ─────────────────────────

    public function test_il_fondo_sta_sotto_la_comune(): void
    {
        // Serve alla busta: la carta clou è la più pregiata, e senza un ordine
        // che comprenda le fasce nuove una Monnezza poteva finire in
        // penultima posizione a fare da colpo di scena.
        $this->assertLessThan(Tier::Comune->rank(), Tier::Pacco->rank());
        $this->assertLessThan(Tier::Pacco->rank(), Tier::Monnezza->rank());
    }

    public function test_la_cascata_dei_drop_rate_le_conosce(): void
    {
        // ⚠️ Restando fuori dall'elenco avrebbero peso di estrazione zero: non
        // uscirebbero mai da una busta, pur essendo la fetta più grossa del
        // pool, e il draft si fermerebbe finite le carte buone.
        $ordine = Tier::dallAltoInBasso();

        $this->assertContains(Tier::Pacco->value, $ordine);
        $this->assertContains(Tier::Monnezza->value, $ordine);
        $this->assertSame(Tier::Monnezza->value, end($ordine), 'la peggiore va in fondo alla cascata');
        $this->assertSame(Tier::Leggendaria->value, $ordine[0]);
    }
}
