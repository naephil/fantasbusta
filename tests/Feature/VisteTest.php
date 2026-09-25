<?php

namespace Tests\Feature;

use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Services\Tournament\TournamentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Che le pagine si aprano davvero.
 *
 * Nasce da un difetto che nessun test prendeva: un componente Blade tenuto
 * accanto alle viste che lo usavano invece che in `components/`. Si compilava
 * senza protestare e falliva a schermo, e siccome i test dei tornei provavano
 * solo i SERVIZI, la pagina di ogni torneo a gironi sarebbe stata un 500
 * scoperto dal primo che ci cliccava sopra.
 */
class VisteTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    /**
     * ⚠️ Il test che vale per tutte: `view:cache` compila OGNI template del
     * progetto, quindi intercetta componenti irrisolvibili e direttive rotte
     * dovunque siano, comprese le viste che nessun test apre.
     *
     * È anche il comando che gira in produzione: se fallisce qui, la
     * pubblicazione si ferma là.
     */
    public function test_tutte_le_viste_si_compilano(): void
    {
        $this->artisan('view:clear')->assertSuccessful();
        $this->artisan('view:cache')->assertSuccessful();

        /*
         * ⚠️ `view:cache` compila e basta: che il risultato abbia un senso non
         * lo guarda nessuno, e restava verde su una pagina irrimediabilmente
         * rotta.
         *
         * È successo davvero, con un `@php(...)` che Blade non ha digerito. Non
         * ha protestato: ha emesso `<?php(` — un tag aperto senza spazio e
         * senza chiusura — e da lì in poi tutto l'HTML del file è finito dentro
         * il PHP. La pagina si apriva bianca, e l'errore parlava di un `@if`
         * senza `@endif` duecentocinquanta righe più in là.
         *
         * ⚠️ Cercare errori di sintassi NON basta a beccarlo, ed è il motivo
         * per cui questo controllo è scritto così: `<?php(` è sintatticamente
         * legittimo — è un'espressione fra parentesi — quindi anche `php -l` lo
         * dà per buono. A essere rotto è il senso, non la grammatica.
         *
         * Quella sequenza invece è inequivocabile: Blade non la produce mai di
         * proposito, perché ogni sua direttiva emette `<?php ` con lo spazio.
         * Trovarla vuol dire che una direttiva non è stata riconosciuta.
         *
         * ⚠️ La seconda trappola, incontrata subito dopo la prima e di natura
         * diversa: NOMINARE una direttiva di blocco dentro un commento Blade.
         * I blocchi grezzi vengono estratti PRIMA che i commenti spariscano,
         * quindi un'apertura scritta dentro `{{-- --}}` viene agganciata alla
         * chiusura vera più sotto, e tutto ciò che sta in mezzo — codice
         * compreso — se ne va senza un fiato. Lì il PHP prodotto è invalido, e
         * il controllo della sintassi qui sotto lo prende.
         *
         * Le due insieme coprono le due facce: una produce codice valido ma
         * senza senso, l'altra codice che non compila.
         */
        $rotte = [];

        foreach (File::files(config('view.compiled')) as $compilata) {
            if ($compilata->getExtension() !== 'php') {
                continue;
            }

            $codice = File::get($compilata->getRealPath());

            if (str_contains($codice, '<?php(')) {
                $rotte[] = $compilata->getFilename().' contiene «<?php(»: una direttiva non è stata compilata';
            }

            try {
                token_get_all($codice, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $rotte[] = $compilata->getFilename().': '.$e->getMessage();
            }
        }

        $this->assertSame([], $rotte, "viste compilate male:\n".implode("\n", $rotte));

        $this->artisan('view:clear')->assertSuccessful();
    }

    // ───────────────────────── le pagine dei tornei ─────────────────────────

    /**
     * @return array{LeagueSeason, Manager, Collection<int,Manager>}
     */
    private function lega(int $quanti = 4): array
    {
        $stagione = $this->makeLeague();

        $squadre = collect(range(1, $quanti))
            ->map(fn (int $i) => $this->makeManager($stagione, "Squadra {$i}"));

        $admin = tap($squadre->first())->update(['is_admin' => true]);

        return [$stagione, $admin, $squadre];
    }

    public static function formati(): array
    {
        return [
            'girone' => ['girone', 4, []],
            'eliminazione diretta' => ['eliminazione', 4, []],
            'gironi piu eliminazione' => ['gironi_eliminazione', 8, ['gruppi' => 2, 'qualificate' => 2]],
            'royal rumble' => ['royal_rumble', 4, []],
        ];
    }

    /**
     * Ogni formato disegna il proprio stato con una vista diversa: provarne
     * uno solo lascerebbe scoperti gli altri tre.
     *
     * @dataProvider formati
     */
    public function test_la_pagina_del_torneo_si_apre(string $formato, int $quanti, array $settings): void
    {
        [$stagione, $admin, $squadre] = $this->lega($quanti);

        $torneo = app(TournamentService::class)->crea(
            $stagione,
            'Coppa di prova',
            $formato,
            $squadre->pluck('id')->all(),
            1,
            $settings,
        );

        // Da avviato in poi la pagina passa dalla vista del formato: prima
        // mostrerebbe solo l'elenco degli iscritti, che non prova niente.
        app(TournamentService::class)->avvia($torneo);

        $this->actingAs($admin)
            ->get(route('tornei.show', $torneo))
            ->assertOk()
            ->assertSee('Coppa di prova');
    }

    public function test_l_elenco_dei_tornei_si_apre(): void
    {
        [, $admin] = $this->lega();

        $this->actingAs($admin)->get(route('tornei.index'))->assertOk();
    }

    public function test_la_pagina_di_creazione_si_apre(): void
    {
        [, $admin] = $this->lega();

        $this->actingAs($admin)
            ->get(route('tornei.create'))
            ->assertOk()
            ->assertSee('Squadra 1');
    }

    // ───────────────────────── il resto delle schermate ─────────────────────────

    /**
     * Le pagine che un manager qualunque apre nel giro normale.
     *
     * Non provano il contenuto — quello lo fanno i test dedicati — ma che si
     * aprano senza esplodere anche quando non c'e' ancora niente da mostrare,
     * che e' lo stato in cui le troveranno tutti il primo giorno.
     */
    public function test_le_pagine_si_aprono_anche_a_stagione_vuota(): void
    {
        [$stagione, $admin] = $this->lega();

        $pagine = [
            'home', 'draft.show', 'lineup.show', 'trades.index', 'team.edit',
            // Le pagine di lettura vanno provate a vuoto quanto le altre: sono
            // proprio quelle che si aprono per prime in una stagione appena
            // creata, quando non c'è ancora niente da leggere.
            'risultati.index', 'standings.index', 'matchday.show', 'stats.index',
        ];

        foreach ($pagine as $rotta) {
            $this->actingAs($admin)
                ->get(route($rotta))
                ->assertOk("la pagina «{$rotta}» non si apre a stagione vuota");
        }
    }

    public function test_le_pagine_di_amministrazione_si_aprono(): void
    {
        [, $admin] = $this->lega();

        // Il listone non è più qui: adesso è di tutti, e la prova che si apra
        // a chiunque sta in PlayerReviewTest.
        foreach (['admin.gestione', 'admin.squadre.index', 'admin.regole.edit'] as $rotta) {
            $this->actingAs($admin)
                ->get(route($rotta))
                ->assertOk("la pagina «{$rotta}» non si apre");
        }
    }
}
