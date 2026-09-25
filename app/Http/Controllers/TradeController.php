<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\Trade;
use App\Services\Draft\CardPresenter;
use App\Services\Trade\TradeException;
use App\Services\Trade\TradeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Il mercato.
 *
 * Apre a draft chiuso e si chiude al primo fischio. Gli scambi sbilanciati
 * sono permessi e non c'è tetto di rosa: l'unico vincolo è che nessuna delle
 * due parti resti inschierabile — e quel controllo avviene all'ACCETTAZIONE,
 * mai alla proposta, perché nel frattempo la controparte può aver concluso
 * altri scambi. Vedi docs/DESIGN.md §4.
 */
class TradeController extends Controller
{
    public function __construct(
        private TradeService $trades,
        private CardPresenter $presenter,
    ) {}

    public function index(Request $request): View
    {
        $manager = $request->user();
        $stagione = $this->stagione($manager);
        $matchday = $stagione ? $manager->currentMatchday($stagione) : null;

        if ($matchday === null) {
            return view('trades.nessuno');
        }

        return view('trades.index', [
            'stagione' => $stagione,
            'matchday' => $matchday,
            // Il mercato apre a draft concluso. Dirlo qui evita che la pagina
            // si comporti da aperta e poi respinga ogni singola proposta con un
            // messaggio sul pavimento delle undici — che è la conseguenza, non
            // il motivo.
            'draftInCorso' => $this->trades->draftInCorso($stagione, $matchday),
            'ricevute' => $this->pending($manager, 'receiver_id', $matchday),
            'inviate' => $this->pending($manager, 'proposer_id', $matchday),
            'feed' => Trade::with(['proposer', 'receiver', 'items.card.player'])
                ->feed($stagione->id, $matchday)
                ->limit(15)
                ->get(),
            'avversari' => $stagione->partecipanti()
                ->reject(fn (Manager $m) => $m->id === $manager->id)
                ->each(fn (Manager $m) => $m->cards_count = $m->cards()
                    ->where('league_season_id', $stagione->id)
                    ->where('matchday', $matchday)
                    ->count())
                ->sortBy('name')
                ->values(),
        ]);
    }

    public function create(Request $request, Manager $manager): View
    {
        $io = $request->user();

        abort_unless($manager->league_id === $io->league_id && $manager->id !== $io->id, 404);

        $stagione = $this->stagioneOAbort($io);
        $matchday = $io->currentMatchday($stagione);
        abort_if($matchday === null, 404);

        return view('trades.create', [
            'stagione' => $stagione,
            'matchday' => $matchday,
            'controparte' => $manager,
            'mieCarte' => $this->presenter->present($this->roster($io, $stagione, $matchday), $stagione, $matchday),
            'sueCarte' => $this->presenter->present($this->roster($manager, $stagione, $matchday), $stagione, $matchday),
        ]);
    }

    public function store(Request $request, Manager $manager): RedirectResponse
    {
        $dati = $request->validate([
            'offerte' => ['array'],
            'offerte.*' => ['integer'],
            'richieste' => ['array'],
            'richieste.*' => ['integer'],
        ]);

        $io = $request->user();
        abort_unless($manager->league_id === $io->league_id, 404);

        $stagione = $this->stagioneOAbort($io);

        try {
            $this->trades->propose(
                $stagione,
                $io,
                $manager,
                array_map('intval', $dati['offerte'] ?? []),
                array_map('intval', $dati['richieste'] ?? []),
                $io->currentMatchday($stagione),
            );
        } catch (TradeException $e) {
            return back()->withInput()->withErrors(['scambio' => $e->getMessage()]);
        }

        return redirect()->route('trades.index')->with('successo', "Proposta inviata a {$manager->name}.");
    }

    /**
     * L'accettazione può finire in rifiuto automatico: non è un errore, è
     * l'esito normale di una proposta invecchiata male.
     */
    public function accept(Request $request, Trade $trade): RedirectResponse
    {
        abort_unless($trade->receiver_id === $request->user()->id, 403);

        $esito = $this->trades->accept($trade);

        return back()->with(
            'successo',
            $esito->state === 'accepted'
                ? 'Scambio concluso.'
                : "Scambio rifiutato: {$esito->reject_reason}",
        );
    }

    public function reject(Request $request, Trade $trade): RedirectResponse
    {
        abort_unless($trade->receiver_id === $request->user()->id, 403);

        $this->trades->reject($trade);

        return back()->with('successo', 'Proposta rifiutata.');
    }

    public function cancel(Request $request, Trade $trade): RedirectResponse
    {
        abort_unless($trade->proposer_id === $request->user()->id, 403);

        $this->trades->cancel($trade);

        return back()->with('successo', 'Proposta ritirata.');
    }

    /** @return Collection<int,Trade> */
    private function pending(Manager $manager, string $colonna, int $matchday): Collection
    {
        return Trade::with(['proposer', 'receiver', 'items.card.player'])
            ->where($colonna, $manager->id)
            ->where('matchday', $matchday)
            ->pending()
            ->latest()
            ->get();
    }

    /** @return Collection<int,Card> */
    private function roster(Manager $manager, LeagueSeason $stagione, int $matchday): Collection
    {
        return Card::with('player')
            ->where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $manager->id)
            ->where('matchday', $matchday)
            ->get()
            ->sortBy(fn (Card $c) => [array_search($c->role, ['P', 'D', 'C', 'A']), $c->player->last_name])
            ->values();
    }
}
