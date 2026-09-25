<?php

namespace App\Http\Controllers;

use App\Models\Matchup;
use App\Models\Tournament;
use App\Services\Scoring\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Tutti i risultati di una giornata, campionato e tornei insieme.
 *
 * ⚠️ Mancava un posto dove vedere «com'è andata». Le sfide di campionato
 * stavano in fondo alla classifica, quelle dei tornei ciascuna dentro il
 * proprio tabellone: per sapere cosa era successo in una giornata bisognava
 * aprire tre pagine e ricordarsi la quarta. E la classifica non è il posto
 * giusto per cercarle — lì si va per la posizione, non per i risultati.
 *
 * Il taglio è la GIORNATA DI SERIE A e non il turno di calendario, perché è
 * l'unica unità che campionato e tornei hanno in comune: la 12ª di coppa non
 * esiste, la 12ª di Serie A sì, e tutto quello che si è giocato quel weekend
 * sta lì sotto.
 */
class RisultatiController extends Controller
{
    public function index(Request $request): View
    {
        $stagione = $this->stagioneOAbort($request->user());

        $giornate = Matchup::where('league_season_id', $stagione->id)
            ->distinct()
            ->orderByDesc('matchday')
            ->pluck('matchday');

        $matchday = $request->integer('giornata') ?: $giornate->first();

        return view('risultati.index', [
            'stagione' => $stagione,
            'matchday' => $matchday,
            'giornate' => $giornate,
            'gruppi' => $matchday ? $this->gruppi($stagione->id, (int) $matchday) : collect(),
            'settings' => Settings::for($stagione),
        ]);
    }

    /**
     * Le sfide della giornata, divise per competizione.
     *
     * Il campionato per primo — è quello che tutti giocano ogni giornata — e i
     * tornei dopo, nell'ordine in cui sono stati creati. Una competizione senza
     * sfide in questa giornata non compare affatto: un titolo con sotto il
     * vuoto fa sembrare che manchi qualcosa.
     *
     * @return Collection<int,array{nome: string, sfide: Collection<int,Matchup>, torneo: Tournament|null}>
     */
    private function gruppi(int $leagueSeasonId, int $matchday): Collection
    {
        $sfide = Matchup::with(['home', 'away'])
            ->where('league_season_id', $leagueSeasonId)
            ->where('matchday', $matchday)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Matchup $m) => $m->tournament_id ?? 0);

        $tornei = Tournament::where('league_season_id', $leagueSeasonId)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        return collect($sfide)
            ->map(fn (Collection $gruppo, int|string $torneoId) => [
                'nome' => (int) $torneoId === 0
                    ? 'Campionato'
                    : ($tornei[$torneoId]->name ?? 'Torneo'),
                'torneo' => (int) $torneoId === 0 ? null : ($tornei[$torneoId] ?? null),
                'sfide' => $gruppo,
            ])
            // Il campionato ha chiave 0 e resta in cima da solo.
            ->sortKeys()
            ->values();
    }
}
