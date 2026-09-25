<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\PlayerSeason;
use App\Services\Draft\CardPresenter;
use App\Services\Lineup\LineupSaver;
use App\Services\Lineup\ModuleValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

class LineupController extends Controller
{
    public function __construct(
        private CardPresenter $presenter,
        private LineupSaver $saver,
    ) {}

    public function show(Request $request): View
    {
        $manager = $request->user();
        $stagione = $this->stagione($manager);
        $matchday = $stagione ? $manager->currentMatchday($stagione) : null;

        if ($matchday === null) {
            return view('lineup.nessuna');
        }

        $rosa = $this->roster($manager, $stagione, $matchday);
        $lineup = $manager->lineups()
            ->where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->with('slots')
            ->first();

        $counts = $rosa->countBy('role')->all();

        return view('lineup.show', [
            'matchday' => $matchday,
            'rosa' => $this->presenter->present($rosa, $stagione, $matchday)->keyBy('id'),
            'carte' => $rosa->keyBy('id'),
            'lineup' => $lineup,
            'titolari' => $lineup?->slots->where('is_starter', true)->pluck('card_id')->all() ?? [],
            'panchina' => $lineup?->slots->where('is_starter', false)->sortBy('bench_order')->pluck('card_id')->all() ?? [],
            'moduli' => ModuleValidator::MODULES,
            'schierabili' => ModuleValidator::playable($counts),
            'conteggi' => $counts,
            'bloccata' => $this->saver->isLocked($stagione, $matchday),
            'scadenza' => $this->saver->lockAt($stagione, $matchday),
            'partite' => $this->partite($stagione, $matchday, $rosa),
        ]);
    }

    /**
     * Le partite di Serie A per cui vale questa formazione.
     *
     * ⚠️ Quelle della giornata che si sta schierando, non di quella in corso.
     * È la distinzione che rende la sezione utile invece che decorativa: chi
     * apre questa pagina sta decidendo per il weekend che deve ancora arrivare,
     * e sapere che il proprio portiere va a giocare in casa della capolista è
     * esattamente ciò su cui si sceglie.
     *
     * Accanto a ogni partita ci sono LE PROPRIE carte, divise per lato. Senza,
     * sarebbe il calendario di Serie A — che si trova ovunque — invece del
     * calendario della propria rosa, che non esiste da nessun'altra parte: per
     * ricostruirlo bisognava aprire il listone e cercare squadra per squadra.
     *
     * @param  Collection<int,Card>  $rosa
     * @return Collection<int,array<string,mixed>>
     */
    private function partite(LeagueSeason $stagione, int $matchday, Collection $rosa): Collection
    {
        $fixtures = Fixture::with(['homeTeam', 'awayTeam'])
            ->where('season', $stagione->season)
            ->where('matchday', $matchday)
            ->orderBy('kickoff_at')
            ->get();

        if ($fixtures->isEmpty() || $rosa->isEmpty()) {
            return collect();
        }

        // La squadra viene da `player_seasons` e non dalla carta: è un attributo
        // dell'anno, e in una stagione replay il giocatore deve comparire nella
        // squadra in cui militava allora.
        $squadraDi = PlayerSeason::where('season', $stagione->season)
            ->whereIn('player_id', $rosa->pluck('player_id'))
            ->pluck('team_id', 'player_id');

        $miei = $rosa->groupBy(fn (Card $c) => (int) ($squadraDi[$c->player_id] ?? 0));

        return $fixtures
            ->map(fn (Fixture $f) => [
                'fixture' => $f,
                'casa' => $miei->get($f->home_team_id, collect()),
                'fuori' => $miei->get($f->away_team_id, collect()),
            ])
            // Prima le partite che mi riguardano: con dieci incontri e due
            // carte coinvolte, l'ordine di calendario nasconde proprio le due
            // righe per cui si è aperta la sezione.
            ->sortByDesc(fn (array $p) => $p['casa']->count() + $p['fuori']->count())
            ->values();
    }

    public function store(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'module' => ['required', 'string'],
            'titolari' => ['required', 'array'],
            'titolari.*' => ['integer'],
            'panchina' => ['array'],
            'panchina.*' => ['integer'],
        ]);

        $manager = $request->user();
        $stagione = $this->stagioneOAbort($manager);
        $matchday = $manager->currentMatchday($stagione);

        abort_if($matchday === null, 404);

        try {
            $this->saver->save(
                $manager,
                $stagione,
                $matchday,
                $dati['module'],
                array_map('intval', $dati['titolari']),
                array_map('intval', $dati['panchina'] ?? []),
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['formazione' => $e->getMessage()]);
        }

        return back()->with('successo', 'Formazione salvata.');
    }

    /** @return Collection<int,Card> */
    private function roster(Manager $manager, LeagueSeason $stagione, int $matchday): Collection
    {
        return Card::with('player')
            ->where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $manager->id)
            ->where('matchday', $matchday)
            ->get()
            ->sortBy([
                fn (Card $a, Card $b) => array_search($a->role, ['P', 'D', 'C', 'A']) <=> array_search($b->role, ['P', 'D', 'C', 'A']),
                fn (Card $a, Card $b) => $a->player->last_name <=> $b->player->last_name,
            ])
            ->values();
    }
}
