<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftTurn;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Services\Draft\CardPresenter;
use App\Services\Draft\PackGenerator;
use App\Services\Draft\PackOpener;
use App\Services\Scoring\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Il palco del draft: la coda, il proprio turno, la rosa che si forma.
 */
class DraftController extends Controller
{
    /**
     * Quante buste si vedono senza chiederlo.
     *
     * Otto sono le ultime scelte, cioè quello che serve per il proprio turno:
     * chi sta per pescare vuole sapere che cosa è appena sparito dal pool, non
     * ripercorrere il draft dall'inizio. Il resto c'è lo stesso, ma si chiede.
     */
    private const STORICO_BREVE = 8;

    public function __construct(private CardPresenter $presenter) {}

    public function show(Request $request): View
    {
        $manager = $request->user();
        $stagione = $this->stagione($manager);
        $draft = $stagione ? $this->currentDraft($stagione) : null;

        if (! $draft) {
            // La forma della busta si legge dalle regole invece di scriverla
            // nel testo: è una taratura per stagione, e una pagina che dicesse
            // «cinque buste da cinque» diventerebbe una bugia il giorno che
            // qualcuno la cambia.
            return view('draft.nessuno', [
                'settings' => $stagione ? Settings::for($stagione) : new Settings,
                // ⚠️ Anche qui, e qui soprattutto: a draft concluso è questa la
                // pagina che si trova, ed è il caso in cui la busta si è aperta
                // senza di te per definizione.
                'daRivedere' => $stagione ? $this->daRivedereInSospeso($stagione, $manager) : collect(),
            ]);
        }

        $turnoAttivo = $draft->activeTurn();
        $rosa = $this->roster($manager, $stagione, $draft->matchday);

        // ⚠️ L'apertura della cronologia sta NELL'INDIRIZZO e non in una
        // variabile del browser, ed è una conseguenza del controllo periodico:
        // questa pagina si ricarica da sola ogni volta che qualcuno pesca, e
        // `location.reload()` si porta dietro la query. Tenuta lato client,
        // l'intera cronologia si richiuderebbe da sé al primo turno che scorre
        // — cioè proprio mentre la si sta leggendo.
        $cronologiaAperta = $request->query('cronologia') === 'tutta';

        return view('draft.show', [
            'draft' => $draft,
            'turnoAttivo' => $turnoAttivo,
            'eIlMioTurno' => $turnoAttivo?->manager_id === $manager->id,
            'coda' => $this->coda($draft),
            'storico' => $this->storico($draft, $cronologiaAperta ? null : self::STORICO_BREVE),
            'cronologiaAperta' => $cronologiaAperta,
            'storicoBreve' => self::STORICO_BREVE,
            'rosa' => $this->presenter->present($rosa, $stagione, $draft->matchday),
            'fatti' => $draft->turns()->where('state', 'done')->count(),
            'totali' => $draft->turns()->count(),
            'firma' => $this->firma($draft),
            // ⚠️ Le buste di QUESTO draft, non «le più recenti della stagione».
            // All'avvio ne sono aperti due insieme — la giornata di partenza e
            // quella dopo — e la pagina mostra il più vecchio: pescare le buste
            // dal più recente faceva comparire, sotto il titolo del draft della
            // 1ª, i bottoni delle buste della 2ª. Si cliccava e uscivano carte
            // che non c'entravano niente con quello che si stava guardando.
            'daRivedere' => $this->daRivedereDi($draft, $manager),
        ]);
    }

    /**
     * Le proprie buste già aperte che nessuno ha visto uscire.
     *
     * Sono il caso che il draft produce da sé, senza che nessuno sbagli niente:
     * il turno arriva alle tre di notte e scade, oppure lo sbustamento
     * automatico è acceso apposta perché non si vuole stare lì ad aspettare. In
     * entrambi i casi si torna sulla pagina e le carte sono in rosa da un pezzo
     * — corrette, le stesse che sarebbero uscite comunque — ma la busta si è
     * aperta per nessuno.
     *
     * ⚠️ Il draft va detto, mai indovinato. Una lega ne ha più d'uno aperto
     * nello stesso momento — all'avvio partono insieme quello della giornata di
     * partenza e quello dopo — quindi «le buste più recenti del gruppo» e «le
     * buste di ciò che sto guardando» sono due insiemi diversi, e scambiarli
     * mette sotto il titolo di un draft i bottoni di un altro. Cliccandoli
     * uscivano carte che non c'entravano niente, e la busta giusta compariva
     * solo al giro dopo.
     *
     * @return Collection<int,DraftTurn>
     */
    private function daRivedereDi(Draft $draft, Manager $manager): Collection
    {
        return $draft->turns()
            ->withCount('cards')
            ->with('draft:id,matchday')
            ->where('manager_id', $manager->id)
            ->where('state', 'done')
            ->whereNull('revealed_at')
            ->orderBy('pick_index')
            ->get();
    }

    /**
     * Le buste mai viste quando NON c'è più un draft da guardare.
     *
     * È l'altra metà del caso: a draft concluso la pagina diventa «nessuna
     * busta all'orizzonte», ed è lì che si arriva dopo essere stati via tutto
     * il tempo. Senza questo l'invito sparirebbe proprio a chi ne ha bisogno.
     *
     * Qui il draft si sceglie — l'ultimo che ne ha lasciate indietro — perché
     * non ce n'è nessuno in vista a dirlo. Uno solo: chi tiene acceso lo
     * sbustamento automatico e non guarda mai ne accumulerebbe una fila lunga
     * tutta la stagione, e il richiamo diventerebbe un archivio, cioè una cosa
     * che non si clicca.
     *
     * @return Collection<int,DraftTurn>
     */
    private function daRivedereInSospeso(LeagueSeason $stagione, Manager $manager): Collection
    {
        $turni = DraftTurn::query()
            ->withCount('cards')
            ->with('draft:id,matchday')
            ->whereIn('draft_id', Draft::where('league_season_id', $stagione->id)->select('id'))
            ->where('manager_id', $manager->id)
            ->where('state', 'done')
            ->whereNull('revealed_at')
            ->get();

        $ultimo = $turni->max('draft_id');

        return $turni->where('draft_id', $ultimo)->sortBy('pick_index')->values();
    }

    /**
     * Lo stato del draft in una riga, per chi guarda la pagina.
     *
     * Esiste perché il draft è l'unica pagina in cui **si aspetta**: il proprio
     * turno arriva quando arriva, e senza questo l'unico modo di accorgersene
     * era ricaricare a mano. Al primo test coi volontari è stata la cosa più
     * segnalata — si restava fermi su «aspetta il tuo turno» mentre era già il
     * proprio.
     *
     * Torna una FIRMA e non lo stato intero: al client non serve sapere cosa è
     * cambiato, gli basta sapere CHE è cambiato per ricaricare. Confrontare una
     * stringa evita di dover tenere due rappresentazioni della stessa pagina in
     * accordo fra loro — che è il modo in cui questi aggiornamenti marciscono.
     */
    public function stato(Request $request): JsonResponse
    {
        $manager = $request->user();
        $stagione = $this->stagione($manager);
        $draft = $stagione ? $this->currentDraft($stagione) : null;

        if (! $draft) {
            return response()->json(['firma' => 'nessuno', 'mio' => false]);
        }

        return response()->json([
            'firma' => $this->firma($draft),
            // Il client lo usa solo per decidere se avvisare: la pagina
            // ricaricata rifà comunque il conto per conto suo.
            'mio' => $draft->activeTurn()?->manager_id === $manager->id,
        ]);
    }

    /**
     * Cosa deve cambiare perché la pagina non sia più attuale.
     *
     * Turno attivo e turni conclusi bastano: se si muove uno dei due, o è
     * cambiato il giro o qualcuno ha pescato. Lo stato del draft entra perché
     * `pending → open` non muove nessuno dei due e cambia tutta la pagina.
     */
    private function firma(Draft $draft): string
    {
        return implode('-', [
            $draft->id,
            $draft->state,
            $draft->activeTurn()?->id ?? 0,
            $draft->turns()->where('state', 'done')->count(),
        ]);
    }

    /**
     * Sbustamento su richiesta del manager.
     *
     * Passa dallo stesso PackOpener del cron, e non è un dettaglio: le due
     * strade possono arrivare nello stesso istante — è la corsa di §7.1 — e
     * duplicare qui il lock significherebbe riaprirla.
     */
    public function open(Request $request, PackOpener $opener): JsonResponse
    {
        $manager = $request->user();
        $stagione = $this->stagione($manager);
        $draft = $stagione ? $this->currentDraft($stagione) : null;
        $turno = $draft?->state === 'open' ? $draft->activeTurn() : null;

        if (! $turno || $turno->manager_id !== $manager->id) {
            return response()->json(['errore' => 'Non è il tuo turno.'], 403);
        }

        $pack = $opener->open($turno, 'manager');

        // Nullo significa che il cron è arrivato un istante prima e ha già
        // sbustato d'ufficio. Le carte ci sono comunque: sono le sue.
        if ($pack === null) {
            return response()->json([
                'errore' => 'Il turno è appena stato chiuso: la busta è già stata aperta d\'ufficio.',
            ], 409);
        }

        // ⚠️ Il timbro del «vista» si mette QUI e non dentro `PackOpener`, che
        // pure sa benissimo chi ha aperto. Il motivo è che da lì passa anche il
        // cron dello sbustamento automatico, e quello apre come 'manager' — la
        // scelta è stata sua — senza che ci sia nessun browser dall'altra
        // parte. Questo controller è l'unico punto del codice in cui ci sono
        // davvero degli occhi che stanno per guardare.
        $turno->update(['revealed_at' => now()]);

        return response()->json(['carte' => $this->carteDaMostrare($pack, $stagione, $draft->matchday)]);
    }

    /**
     * Rivedere una busta già aperta.
     *
     * Le carte sono in rosa da un pezzo e questa strada non ne muove nessuna:
     * rende la stessa busta nello stesso ordine, e serve a una cosa sola —
     * l'animazione. Che sembra un vezzo e non lo è: è il momento in cui il
     * gioco succede, e chi trova la busta già aperta perché il turno è scaduto
     * o perché aveva acceso l'automatico si ritrova le carte in rosa senza
     * averle viste uscire. Il valore del gioco gli è passato accanto.
     *
     * ⚠️ L'ordine si ricostruisce con lo stesso `orderForReveal()` dello
     * sbustamento vero, non con un ordinamento inventato qui. È deterministico
     * a partire dall'ordine di pescata — che è quello degli id — quindi la
     * replica è identica all'originale, carta per carta e sorpresa al quarto
     * posto compresa. Riscriverlo a mano darebbe una versione simile e non
     * uguale, e la busta «rivista» non sarebbe più quella uscita.
     */
    public function rivedi(Request $request, DraftTurn $draftTurn, PackGenerator $packs): JsonResponse
    {
        // Si rivedono le proprie e basta: la busta di un altro è informazione
        // di gioco, e il draft è già una fila al buio per costruzione.
        abort_unless($draftTurn->manager_id === $request->user()->id, 403);
        abort_unless($draftTurn->state === 'done', 404);

        $draft = $draftTurn->draft;

        $carte = $packs->orderForReveal(
            $draftTurn->cards()->orderBy('id')->get()->collect(),
        );

        $draftTurn->update(['revealed_at' => now()]);

        return response()->json([
            'carte' => $this->carteDaMostrare($carte, $draft->leagueSeason, $draft->matchday),
        ]);
    }

    /**
     * Le carte come le vuole l'animazione, già rese dal server.
     *
     * Sta in un posto solo perché le strade che ci arrivano sono due — la busta
     * che si apre adesso e quella che si rivede — e due versioni di questa
     * forma vorrebbero dire che prima o poi la busta rivista si disegna in modo
     * leggermente diverso da come era uscita.
     *
     * @param  Collection<int,Card>  $carte
     * @return Collection<int,array<string,mixed>>
     */
    private function carteDaMostrare(Collection $carte, LeagueSeason $stagione, int $matchday): Collection
    {
        return $this->presenter->present($carte, $stagione, $matchday)
            ->map(fn (array $carta) => [
                'tier' => $carta['tier'],
                'role' => $carta['role'],
                'last' => $carta['last'],
                'html' => view('components.carta', ['carta' => $carta, 'coperta' => true])->render(),
                'htmlScoperta' => view('components.carta', ['carta' => $carta])->render(),
            ])
            ->values();
    }

    private function currentDraft(LeagueSeason $stagione): ?Draft
    {
        return Draft::where('league_season_id', $stagione->id)
            ->whereIn('state', ['pending', 'open'])
            ->orderBy('matchday')
            ->first();
    }

    /** @return Collection<int,Card> */
    private function roster(Manager $manager, LeagueSeason $stagione, int $matchday): Collection
    {
        return Card::where('league_season_id', $stagione->id)
            ->where('owner_manager_id', $manager->id)
            ->where('matchday', $matchday)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Le buste aperte, dalla più recente.
     *
     * Serve a una cosa sola ma importante: sapere cosa hanno preso quelli
     * prima di te. Senza, il draft è una fila al buio — si aspetta il proprio
     * turno senza nessuna idea di cosa sia già stato portato via, che è
     * esattamente l'informazione su cui si decide.
     *
     * Si ordina per `pick_index` e non per `opened_at`: l'ordine dello snake è
     * quello vero del draft, mentre gli istanti di apertura possono coincidere
     * — una fila di bot sbusta dentro lo stesso secondo — e darebbero un ordine
     * arbitrario proprio dove serve quello giusto.
     *
     * @param  int|null  $quante  null per tutte: è la cronologia completa
     * @return Collection<int,DraftTurn>
     */
    private function storico(Draft $draft, ?int $quante = self::STORICO_BREVE): Collection
    {
        $query = $draft->turns()
            ->with(['manager', 'cards.player'])
            ->where('state', 'done')
            ->orderByDesc('pick_index');

        return ($quante === null ? $query : $query->limit($quante))->get();
    }

    /**
     * I prossimi turni, per far vedere quanto manca al proprio.
     *
     * @return Collection<int,DraftTurn>
     */
    private function coda(Draft $draft): Collection
    {
        return $draft->turns()
            ->with('manager')
            ->whereIn('state', ['active', 'waiting'])
            ->orderBy('pick_index')
            ->limit(8)
            ->get();
    }
}
