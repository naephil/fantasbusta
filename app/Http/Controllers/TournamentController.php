<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\Tournament\TournamentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * Tornei paralleli al campionato.
 *
 * Li crea l'admin: decidere chi partecipa e con che formato è una scelta di
 * lega, non individuale.
 */
class TournamentController extends Controller
{
    public function __construct(private TournamentService $tornei) {}

    public function index(Request $request): View
    {
        $stagione = $this->stagioneOAbort($request->user());

        return view('tornei.index', [
            'stagione' => $stagione,
            'tornei' => Tournament::with('winner')
                ->where('league_season_id', $stagione->id)
                ->withCount('entries')
                ->latest('id')
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $stagione = $this->stagioneOAbort($request->user());

        return view('tornei.create', [
            'stagione' => $stagione,
            'squadre' => $stagione->partecipanti()->sortBy('name')->values(),
            'formati' => collect(config('tornei.formati'))->map(fn ($classe) => app($classe)),
            'parametri' => config('tornei.parametri'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $stagione = $this->stagioneOAbort($request->user());

        $dati = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'format' => ['required', Rule::in(array_keys(config('tornei.formati')))],
            'start_matchday' => ['required', 'integer', 'min:1', 'max:38'],
            'partecipanti' => ['required', 'array', 'min:2'],
            'partecipanti.*' => ['integer'],
            'settings' => ['array'],
        ], [], ['partecipanti' => 'partecipanti']);

        try {
            $torneo = $this->tornei->crea(
                $stagione,
                $dati['name'],
                $dati['format'],
                array_map('intval', $dati['partecipanti']),
                (int) $dati['start_matchday'],
                array_map('intval', $dati['settings'][$dati['format']] ?? []),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['torneo' => $e->getMessage()]);
        }

        return redirect()
            ->route('tornei.show', $torneo)
            ->with('successo', "Torneo «{$torneo->name}» creato. Avvialo quando vuoi.");
    }

    public function show(Request $request, Tournament $tournament): View
    {
        abort_unless($tournament->league_season_id === $this->stagioneOAbort($request->user())->id, 404);

        return view('tornei.show', [
            'torneo' => $tournament->load('entries.manager', 'winner'),
            'formato' => $tournament->formato(),
            'stato' => $tournament->state === 'bozza' ? [] : $tournament->formato()->stato($tournament),
        ]);
    }

    public function start(Request $request, Tournament $tournament): RedirectResponse
    {
        abort_unless($tournament->league_season_id === $this->stagioneOAbort($request->user())->id, 404);

        try {
            $this->tornei->avvia($tournament);
        } catch (RuntimeException $e) {
            return back()->withErrors(['torneo' => $e->getMessage()]);
        }

        return back()->with('successo', 'Torneo avviato.');
    }

    public function destroy(Request $request, Tournament $tournament): RedirectResponse
    {
        abort_unless($tournament->league_season_id === $this->stagioneOAbort($request->user())->id, 404);

        $this->tornei->elimina($tournament);

        return redirect()->route('tornei.index')->with('successo', 'Torneo eliminato.');
    }
}
