<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * La schermata di verifica del listone.
 *
 * §6.1 la dà per obbligatoria prima dell'avvio stagione, e la ragione è che
 * nessun controllo automatico può sostituirla: un id API-Football che punta a
 * un giocatore *inesistente* dà 404 e lo vedi subito, ma uno che punta a un
 * giocatore *diverso* restituisce una faccia plausibile e passa qualunque
 * validazione. Con ~550 righe l'unico strumento affidabile è l'occhio umano —
 * da qui l'impaginazione a foto grandi, pensata per scorrere in fretta.
 *
 * Le due conferme restano separate perché rispondono a domande diverse, e le
 * due domande vivono in posti diversi: il ruolo («che ruolo ha») appartiene
 * all'annata e si rivede a ogni listone, l'identità («è davvero lui») appartiene
 * alla persona e si verifica una volta sola per sempre.
 *
 * Non c'è nessuna conferma di massa per l'identità, e l'assenza è voluta: un
 * pulsante «conferma tutti» annullerebbe il senso della schermata.
 */
class PlayerReviewController extends Controller
{
    private const PER_PAGINA = 24;

    public function index(Request $request): View
    {
        $anno = $this->anno($request);
        $filtro = $request->string('filtro', 'ruolo')->toString();
        $ordine = $this->ordine($request);

        $giocatori = PlayerSeason::query()
            ->with(['player', 'team'])
            ->where('season', $anno)
            ->where('active', true)
            ->when($filtro === 'ruolo', fn ($q) => $q->where('role_confirmed', false))
            ->when($filtro === 'identita', fn ($q) => $q->whereHas('player', fn ($p) => $p->where('photo_verified', false)))
            ->when($request->filled('q'), fn ($q) => $q->whereHas(
                'player',
                fn ($p) => $p->where('last_name', 'like', '%'.$request->string('q').'%'),
            ))
            ->when($request->filled('squadra'), fn ($q) => $q->where('team_id', $request->integer('squadra')))
            ->tap(fn ($q) => $this->ordina($q, $ordine))
            ->paginate(self::PER_PAGINA)
            ->withQueryString();

        return view('admin.players.index', [
            'anno' => $anno,
            'annate' => PlayerSeason::select('season')->distinct()->orderByDesc('season')->pluck('season'),
            'giocatori' => $giocatori,
            'squadre' => Team::orderBy('name')->get(),
            'ruoli' => Role::cases(),
            'filtro' => $filtro,
            'ordine' => $ordine,
            'ordini' => self::ORDINI,
            'conteggi' => $this->conteggi($anno),
        ]);
    }

    /**
     * Come ordinare l'elenco.
     *
     * ⚠️ La quotazione è il criterio di PARTENZA, e non è una preferenza
     * estetica: su cinquecento righe si verifica quello che si riesce, e ciò
     * che conta è aver guardato in faccia chi verrà davvero pescato. Un errore
     * di identità sul terzo portiere del Lecce non lo nota nessuno; lo stesso
     * errore su un attaccante da quaranta è la faccia sbagliata su una carta
     * Leggendaria, che è l'errore più visibile del gioco.
     *
     * La squadra resta perché è l'ordine giusto per l'altro modo di lavorare:
     * verificare una rosa alla volta, con le facce di quella squadra in mente.
     */
    private const ORDINI = [
        'quotazione' => 'Più quotati',
        'squadra' => 'Per squadra',
        'cognome' => 'Alfabetico',
    ];

    private function ordine(Request $request): string
    {
        $chiesto = $request->string('ordine')->toString();

        return isset(self::ORDINI[$chiesto]) ? $chiesto : 'quotazione';
    }

    private function ordina($query, string $ordine): void
    {
        match ($ordine) {
            // A parità di quotazione decide il ruolo e poi l'id: senza un
            // criterio secondario l'elenco ballerebbe fra una pagina e l'altra,
            // e in un lavoro a scorrimento vuol dire rivedere due volte gli
            // stessi e saltarne altri.
            'squadra' => $query->orderBy('team_id')->orderByDesc('quotazione_iniziale')->orderBy('player_id'),
            'cognome' => $query->orderBy(
                Player::select('last_name')->whereColumn('players.id', 'player_seasons.player_id'),
            )->orderBy('player_id'),
            default => $query->orderByDesc('quotazione_iniziale')->orderBy('role')->orderBy('player_id'),
        };
    }

    /**
     * Ruolo e quotazione, decisi a mano.
     *
     * Scrivere qui significa dichiarare il dato autorevole: da questo momento
     * nessuna sincronizzazione lo tocca più — per quell'annata.
     */
    public function update(Request $request, PlayerSeason $playerSeason): RedirectResponse
    {
        $dati = $request->validate([
            'role' => ['required', 'string', 'in:P,D,C,A'],
            'quotazione_iniziale' => ['required', 'numeric', 'min:0', 'max:999.9'],
        ]);

        $playerSeason->update($dati + ['role_confirmed' => true]);

        return back()->with(
            'successo',
            "{$playerSeason->player->last_name}: ruolo {$dati['role']} confermato.",
        );
    }

    /** «È davvero lui»: l'unica cosa che solo un umano può dire. */
    public function verify(PlayerSeason $playerSeason): RedirectResponse
    {
        $player = $playerSeason->player;
        $player->update(['photo_verified' => ! $player->photo_verified]);

        return back()->with(
            'successo',
            $player->photo_verified
                ? "{$player->last_name}: identità verificata."
                : "{$player->last_name}: verifica revocata.",
        );
    }

    /** L'annata che si sta rivedendo: quella chiesta, o l'ultima in casa. */
    private function anno(Request $request): int
    {
        return $request->integer('anno') ?: (int) PlayerSeason::max('season');
    }

    /** @return array<string,int> */
    private function conteggi(int $anno): array
    {
        $base = fn () => PlayerSeason::where('season', $anno)->where('active', true);

        return [
            'ruolo' => $base()->where('role_confirmed', false)->count(),
            'identita' => $base()->whereHas('player', fn ($p) => $p->where('photo_verified', false))->count(),
            'tutti' => $base()->count(),
        ];
    }
}
