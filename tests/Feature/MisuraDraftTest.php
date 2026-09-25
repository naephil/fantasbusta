<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\PlayerPower;
use App\Models\PlayerStat;
use App\Services\Draft\DraftBuilder;
use App\Services\Power\PowerUpdater;
use App\Services\Season\SeasonRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Non è una prova: è un banco di misura, e stampa numeri.
 *
 * Serve a rispondere alla domanda che i test non sanno porre — «com'è fatta
 * davvero una lega dopo il draft» — con un listone della forma di quello vero,
 * dove i titolari giocano e i panchinari no. È la differenza fra sapere che i
 * Leggendari escono tutti e sapere se in rosa ci finiscono giocatori che
 * scendono in campo.
 */
class MisuraDraftTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    /**
     * Un campionato con la forma giusta: venti squadre, e in ognuna undici che
     * giocano quasi sempre più una dozzina che non gioca quasi mai.
     *
     * @return array{list<int>, list<int>} id dei titolari, id dei panchinari
     */
    private function campionato(int $matchday): array
    {
        $titolari = [];
        $panchinari = [];

        foreach (range(1, 20) as $squadra) {
            // 11 titolari: 1P 4D 4C 2A. 12 panchinari: 1P 4D 4C 3A.
            foreach (['P', 'D', 'D', 'D', 'D', 'C', 'C', 'C', 'C', 'A', 'A'] as $ruolo) {
                $titolari[] = $this->giocatore($ruolo, $matchday, gioca: true, squadra: $squadra);
            }

            foreach (['P', 'D', 'D', 'D', 'D', 'C', 'C', 'C', 'C', 'A', 'A', 'A'] as $ruolo) {
                $panchinari[] = $this->giocatore($ruolo, $matchday, gioca: false, squadra: $squadra);
            }
        }

        return [$titolari, $panchinari];
    }

    private function giocatore(string $ruolo, int $matchday, bool $gioca, int $squadra): int
    {
        // La quotazione separa i due gruppi come farebbe il listone vero: un
        // titolare costa, un panchinaro sta sul fondo della fascia.
        $player = $this->makePlayer(
            $ruolo,
            quotazione: $gioca ? mt_rand(12, 40) : mt_rand(1, 6),
            teamId: 600 + $squadra,
        );

        // Le giornate precedenti, che è da dove escono fantamedia, forma e
        // titolarità: il titolare gioca tutte, il panchinaro quasi nessuna.
        foreach (range(1, max(1, $matchday - 2)) as $g) {
            $minuti = $gioca ? 90 : (mt_rand(1, 10) > 8 ? 12 : 0);

            PlayerStat::updateOrCreate(
                ['player_id' => $player->id, 'season' => self::ANNATA, 'matchday' => $g],
                ['minutes' => $minuti, 'rating' => $minuti > 0 ? mt_rand(50, 80) / 10 : null],
            );
        }

        return $player->id;
    }

    public function test_misura_la_composizione_delle_rose(): void
    {
        $matchday = 6;

        [$stagione, $managers] = [$this->makeLeague(), []];

        foreach (range(1, 12) as $i) {
            $managers[] = $this->makeManager($stagione, "Manager{$i}");
        }

        [$titolari, $panchinari] = $this->campionato($matchday);

        $this->makeFixture($matchday - 1, '2026-09-04 20:45:00');
        $this->makeFixture($matchday, '2026-09-11 20:45:00');

        app(PowerUpdater::class)->update($stagione, $matchday);
        app(DraftBuilder::class)->build($stagione, $matchday);
        app(SeasonRunner::class)->concludiDraft($stagione);

        $carte = Card::where('league_season_id', $stagione->id)->get();
        $possedutiTitolari = $carte->pluck('player_id')->intersect($titolari)->count();
        $possedutiPanchinari = $carte->pluck('player_id')->intersect($panchinari)->count();

        // Quanti titolari del campionato sono rimasti liberi.
        $liberiTitolari = count($titolari) - $possedutiTitolari;

        fwrite(STDERR, "\n\n──────── COMPOSIZIONE DELLE ROSE ────────\n");
        fwrite(STDERR, sprintf(
            "listone: %d (%d titolari, %d panchinari)\ncarte distribuite: %d\n\n",
            count($titolari) + count($panchinari), count($titolari), count($panchinari), $carte->count(),
        ));
        fwrite(STDERR, sprintf(
            "titolari in rosa:    %3d su %3d  (%.0f%%)  — ne restano liberi %d\n",
            $possedutiTitolari, count($titolari), 100 * $possedutiTitolari / count($titolari), $liberiTitolari,
        ));
        fwrite(STDERR, sprintf(
            "panchinari in rosa:  %3d su %3d  (%.0f%%)\n\n",
            $possedutiPanchinari, count($panchinari), 100 * $possedutiPanchinari / count($panchinari),
        ));

        fwrite(STDERR, "per manager (titolari / totale carte):\n");
        foreach ($managers as $m) {
            $sue = $carte->where('owner_manager_id', $m->id);
            $suoiTitolari = $sue->pluck('player_id')->intersect($titolari)->count();

            fwrite(STDERR, sprintf(
                "  %-12s %2d / %2d\n", $m->name, $suoiTitolari, $sue->count(),
            ));
        }

        // Il tier dei titolari: se la piramide non li riconosce, il draft non
        // può pescarli anche volendo.
        $tierTitolari = PlayerPower::where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->whereIn('player_id', $titolari)
            ->pluck('tier')
            ->countBy();

        fwrite(STDERR, "\ntier dei titolari veri: ".json_encode($tierTitolari->all())."\n");

        $tierPanchinari = PlayerPower::where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->whereIn('player_id', $panchinari)
            ->pluck('tier')
            ->countBy();

        fwrite(STDERR, 'tier dei panchinari:    '.json_encode($tierPanchinari->all())."\n");
        fwrite(STDERR, "─────────────────────────────────────────\n\n");

        $this->assertTrue(true);   // è una misura, non un giudizio
    }
}
