<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\PlayerSeason;
use App\Rules\TestoValido;
use App\Services\Calendar\CalendarBuilder;
use App\Services\Ingest\ListoneImport;
use App\Services\Ingest\ListoneStimato;
use App\Services\Season\LeagueReset;
use App\Services\Season\SeasonLoader;
use App\Services\Season\SeasonReset;
use App\Services\Season\SeasonRunner;
use App\Support\Battito;
use App\Support\StagioneAttiva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * La console di gestione: tutto quello che prima si faceva da riga di comando.
 *
 * Due piani distinti, ed è la distinzione che regge tutto il resto:
 *
 *  - **le annate di Serie A** — squadre, listone, calendario, statistiche. Sono
 *    dati del mondo, non del gruppo: si scaricano una volta e li usano tutti.
 *  - **le stagioni di lega** — la partita vera, con carte, sfide e classifica.
 *    Ognuna sceglie un'annata da giocare, e più stagioni possono scegliere la
 *    stessa senza pestarsi i piedi.
 *
 * Da qui si fa il ciclo intero senza mai aprire un terminale: si carica
 * un'annata, si importa il listone, si crea una stagione, si genera il
 * calendario e si gioca giornata per giornata.
 */
class SeasonController extends Controller
{
    public function __construct(
        private SeasonLoader $loader,
        private SeasonRunner $runner,
    ) {}

    public function index(Request $request): View
    {
        $league = $request->user()->league;

        return view('admin.gestione', [
            'league' => $league,
            'annate' => $this->annate(),
            'caricabili' => config('apifootball.stagioni_disponibili'),
            'stagioni' => $league->seasons()->orderByDesc('season')->get()->map(
                fn (LeagueSeason $s) => $this->riassunto($s),
            ),
            'attiva' => $this->stagione($request->user())?->id,
            // Il cron è l'unico pezzo che vive fuori di qui, e quando manca non
            // dà errori: il draft semplicemente non avanza e sembra lentezza.
            'battito' => ['stato' => Battito::stato(), 'eta' => Battito::eta()],
        ]);
    }

    /**
     * Scarica squadre, listone e calendario di un'annata.
     *
     * Può volerci un minuto abbondante: sono ~20 chiamate per le rose più una
     * per il calendario, col freno da 6,5 secondi che evita il 429. La pagina
     * lo dice prima, perché un'attesa muta sembra un blocco.
     */
    public function loadAnnata(Request $request): RedirectResponse
    {
        $this->senzaFretta();

        $dati = $request->validate([
            'anno' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        try {
            $esito = $this->loader->carica((int) $dati['anno']);
        } catch (Throwable $e) {
            return back()->withErrors(['annata' => $e->getMessage()]);
        }

        return back()->with('successo', sprintf(
            'Annata %d: %d squadre, %d giocatori, %d partite su %d giornate.',
            $dati['anno'], $esito['squadre'], $esito['giocatori'], $esito['partite'], $esito['giornate'],
        ));
    }

    /**
     * Il listone, caricato come file dalla pagina.
     *
     * Ruoli e quotazioni restano una decisione umana — il sync non li scrive
     * mai da solo — ma «umana» non deve voler dire «da terminale»: chi tiene
     * la lega scarica l'.xlsx ufficiale e lo trascina qui.
     */
    public function importListone(Request $request, ListoneImport $import): RedirectResponse
    {
        $this->senzaFretta();

        $dati = $request->validate([
            'anno' => ['required', 'integer', 'min:2000', 'max:2100'],
            'listone' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:8192'],
        ]);

        try {
            $esito = $import->fromFile(
                $request->file('listone')->getRealPath(),
                (int) $dati['anno'],
            );
        } catch (Throwable $e) {
            return back()->withErrors(['listone' => $e->getMessage()]);
        }

        $messaggio = "{$esito['abbinati']} giocatori abbinati";

        if ($esito['ambigui'] !== [] || $esito['mancanti'] !== []) {
            $messaggio .= sprintf(
                ' · %d ambigui, %d senza corrispondenza.',
                count($esito['ambigui']),
                count($esito['mancanti']),
            );
        }

        // ⚠️ I NOMI, non solo il conteggio. «61 senza corrispondenza» non si
        // può usare per niente: non dice se manca mezzo Sassuolo o sessanta
        // riserve sparse, e le due cose vogliono rimedi opposti. Con l'elenco
        // sotto gli occhi si riconosce in due secondi se è un'annata sbagliata,
        // un'anagrafica incompleta o solo la coda di giocatori mai convocati.
        return back()
            ->with('successo', $messaggio)
            ->with('listoneAmbigui', $esito['ambigui'])
            ->with('listoneMancanti', $esito['mancanti']);
    }

    /**
     * Deduce le quotazioni dalle statistiche, quando il listone vero non c'è.
     *
     * Senza, alla prima giornata di una stagione ricaricata tutti hanno lo
     * stesso power — nessuno ha ancora giocato e la quotazione è 1 per tutti —
     * quindi «chi è Leggendaria» lo decide lo spareggio sull'id. La piramide
     * resta giusta nei numeri e sbagliata nei nomi.
     */
    public function stimaListone(Request $request, ListoneStimato $stima): RedirectResponse
    {
        $this->senzaFretta();

        $dati = $request->validate([
            'anno' => ['required', 'integer', 'min:2000', 'max:2100'],
            'dati' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        try {
            $esito = $stima->stima((int) $dati['anno'], (int) $dati['dati']);
        } catch (Throwable $e) {
            return back()->withErrors(['listone' => $e->getMessage()]);
        }

        if ($esito['quotati'] === 0) {
            return back()->withErrors([
                'listone' => "Nessuna quotazione dedotta: sull'annata {$dati['dati']} non risultano statistiche.",
            ]);
        }

        return back()->with('successo', sprintf(
            '%d giocatori quotati sui dati %d, %d senza riscontro (restano al minimo). %d chiamate spese.',
            $esito['quotati'], $dati['dati'], $esito['senzaDati'], $esito['chiamate'],
        ));
    }

    /** Butta via i dati di un'annata che non serve più. */
    public function scartaAnnata(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'anno' => ['required', 'integer'],
        ]);

        try {
            $this->loader->scarta((int) $dati['anno']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['annata' => $e->getMessage()]);
        }

        return back()->with('successo', "Annata {$dati['anno']} rimossa.");
    }

    /** Una stagione nuova per il proprio gruppo. */
    public function creaStagione(Request $request): RedirectResponse
    {
        $league = $request->user()->league;

        $dati = $request->validate([
            'season' => ['required', 'integer', 'min:2000', 'max:2100'],
            'start_matchday' => ['required', 'integer', 'min:1', 'max:38'],
        ]);

        if (LeagueSeason::where('league_id', $league->id)->where('season', $dati['season'])->exists()) {
            return back()->withErrors(['stagione' => 'Questa annata è già in gioco per il gruppo.']);
        }

        if (! $this->loader->pronta((int) $dati['season'])) {
            return back()->withErrors([
                'stagione' => "L'annata {$dati['season']} non è in casa: caricala prima, e importane il listone.",
            ]);
        }

        $stagione = LeagueSeason::create([
            'league_id' => $league->id,
            'season' => (int) $dati['season'],
            // Esplicito e non lasciato al default della tabella: quello vive
            // nel database, non sull'istanza appena creata, e rileggerlo da qui
            // darebbe null proprio mentre si decide cosa mostrare.
            'state' => 'preparazione',
            'start_matchday' => (int) $dati['start_matchday'],
        ]);

        app(StagioneAttiva::class)->scegli($request->user(), $stagione->id);

        return back()->with('successo', "Stagione {$stagione->etichetta()} creata. Ora genera il calendario.");
    }

    /** Il calendario degli scontri diretti: si fa una volta, prima di cominciare. */
    public function calendario(Request $request, LeagueSeason $leagueSeason, CalendarBuilder $builder): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);

        try {
            $turni = $builder->generate($leagueSeason);
        } catch (RuntimeException $e) {
            return back()->withErrors(['stagione' => $e->getMessage()]);
        }

        return back()->with('successo', "{$turni} turni generati dalla {$leagueSeason->start_matchday}ª.");
    }

    /** Da «preparazione» a «in corso»: da qui in poi si gioca. */
    public function avvia(Request $request, LeagueSeason $leagueSeason): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);
        $this->senzaFretta();

        if ($leagueSeason->matchups()->count() === 0) {
            return back()->withErrors(['stagione' => 'Senza calendario non c\'è niente da giocare.']);
        }

        $leagueSeason->update(['state' => 'in_corso']);

        // I primi due draft vanno aperti qui, perché il ciclo normale è sfasato
        // di due e non li produrrebbe mai: senza, si arriverebbe alla prima
        // giornata con le rose vuote e «giocarla» non farebbe niente.
        try {
            $aperti = $this->runner->apriPrimoDraft($leagueSeason);
        } catch (Throwable $e) {
            return back()->withErrors([
                'stagione' => "Stagione avviata, ma il primo draft non parte: {$e->getMessage()}",
            ]);
        }

        return back()->with('successo', sprintf(
            'Stagione %s avviata. %s',
            $leagueSeason->etichetta(),
            $aperti === [] ? 'Nessun draft da aprire.' : 'Draft aperti: '.implode(' · ', $aperti).'.',
        ));
    }

    /**
     * Sbusta d'ufficio tutto il draft in corso.
     *
     * È l'acceleratore della stagione di prova. Nel gioco vero i turni li
     * smaltisce il cron man mano che scadono, e aspettare è parte del gioco;
     * qui aspettare non serve a niente.
     */
    public function concludiDraft(Request $request, LeagueSeason $leagueSeason): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);
        $this->senzaFretta();

        try {
            $esito = $this->runner->concludiDraft($leagueSeason);
        } catch (Throwable $e) {
            return back()->withErrors(['stagione' => $e->getMessage()]);
        }

        if ($esito['draft'] === 0) {
            return back()->with('successo', 'Nessun draft da concludere.');
        }

        return back()->with('successo', sprintf(
            '%d buste aperte, %d draft chiusi. Le rose sono pronte.',
            $esito['buste'], $esito['draft'],
        ));
    }

    /**
     * Gioca una giornata: statistiche, voti, sfide, classifica, prossimo draft.
     *
     * È il pulsante «avanti di una giornata». Con `simula` non tocca l'API e
     * inventa statistiche plausibili — serve per collaudare senza bruciare il
     * tetto di cento chiamate al giorno.
     */
    public function gioca(Request $request, LeagueSeason $leagueSeason): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);
        $this->senzaFretta();

        $dati = $request->validate([
            'giornata' => ['nullable', 'integer', 'min:1', 'max:38'],
            'simula' => ['nullable', 'boolean'],
            'ore' => ['required', 'integer', 'min:1', 'max:336'],
        ]);

        $giornata = $dati['giornata'] ?: $this->runner->prossima($leagueSeason);

        if (! $giornata) {
            return back()->withErrors(['stagione' => 'Nessuna giornata da giocare: sono finite.']);
        }

        try {
            $esito = $this->runner->gioca(
                $leagueSeason,
                (int) $giornata,
                $request->boolean('simula'),
                (int) $dati['ore'],
            );
        } catch (Throwable $e) {
            return back()->withErrors(['stagione' => $e->getMessage()]);
        }

        return back()->with('successo', sprintf(
            'Giornata %d giocata (%s): %d fantavoti, %d formazioni di cui %d d\'ufficio. Draft: %s.',
            $esito['giornata'], $esito['fonte'], $esito['voti'], $esito['formazioni'], $esito['ufficio'],
            $esito['draft'] ?? 'nessuno, stagione finita',
        ));
    }

    /**
     * ① «La giornata è cominciata»: congela le rose e apre il draft dopo.
     *
     * Lo preme una persona e non l'orologio: le annate si giocano ricaricate,
     * quindi ogni primo fischio è già suonato e un innesco automatico sulla
     * data farebbe partire tutte le giornate insieme al primo giro di cron.
     */
    public function iniziaGiornata(Request $request, LeagueSeason $leagueSeason): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);
        $this->senzaFretta();

        $dati = $request->validate([
            'giornata' => ['required', 'integer', 'min:1', 'max:38'],
            'ore' => ['required', 'integer', 'min:1', 'max:336'],
        ]);

        try {
            $esito = $this->runner->iniziaGiornata($leagueSeason, (int) $dati['giornata'], (int) $dati['ore']);
        } catch (Throwable $e) {
            return back()->withErrors(['stagione' => $e->getMessage()]);
        }

        return back()->with('successo', sprintf(
            'Giornata %d cominciata: le formazioni sono congelate. Draft: %s.',
            $esito['giornata'],
            $esito['draft'] ?? 'nessuno, stagione finita',
        ));
    }

    /**
     * ② I voti che ci sono, senza toccare la classifica.
     *
     * Si rilancia quante volte si vuole mentre i risultati arrivano: sabato
     * alle 15, domenica sera, il lunedì. Con `tutte` completa la giornata in un
     * colpo, che è quello che serve per collaudare.
     */
    public function parziali(Request $request, LeagueSeason $leagueSeason): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);
        $this->senzaFretta();

        $dati = $request->validate([
            'giornata' => ['required', 'integer', 'min:1', 'max:38'],
            'simula' => ['nullable', 'boolean'],
            'tutte' => ['nullable', 'boolean'],
            // ⚠️ Senza questo il messaggio di rifiuto del simulatore era un
            // vicolo cieco: diceva «forza la sovrascrittura se è quello che
            // vuoi» e in tutta l'interfaccia non c'era un modo per farlo. Il
            // motore lo sapeva fare da sempre, il controller non glielo
            // chiedeva mai.
            'sovrascrivi' => ['nullable', 'boolean'],
        ]);

        try {
            $esito = $this->runner->parziali(
                $leagueSeason,
                (int) $dati['giornata'],
                $request->boolean('simula'),
                $request->boolean('tutte'),
                $request->boolean('sovrascrivi'),
            );
        } catch (Throwable $e) {
            return back()->withErrors(['stagione' => $e->getMessage()]);
        }

        return back()->with('successo', sprintf(
            'Giornata %d (%s): %d fantavoti calcolati. Classifica e sfide restano ferme finché non la chiudi.',
            $esito['giornata'], $esito['fonte'], $esito['voti'],
        ));
    }

    /** ③ La giornata si chiude: da qui i punti sono punti. */
    public function chiudiGiornata(Request $request, LeagueSeason $leagueSeason): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);
        $this->senzaFretta();

        $dati = $request->validate([
            'giornata' => ['required', 'integer', 'min:1', 'max:38'],
        ]);

        try {
            $esito = $this->runner->chiudiGiornata($leagueSeason, (int) $dati['giornata']);
        } catch (Throwable $e) {
            return back()->withErrors(['stagione' => $e->getMessage()]);
        }

        return back()->with('successo', sprintf(
            'Giornata %d chiusa: %d fantavoti, %d formazioni di cui %d d\'ufficio, %d in classifica.%s',
            $esito['giornata'], $esito['voti'], $esito['formazioni'], $esito['ufficio'], $esito['classifica'],
            ($esito['conclusa'] ?? false) ? ' La stagione è finita.' : '',
        ));
    }

    /**
     * Riporta una stagione al giorno zero, senza cancellarla.
     *
     * È la via di mezzo che mancava fra «avanti di una giornata» e «cancella».
     * Una prova andata storta si ripulisce senza perdere le regole tarate e la
     * giornata di partenza, che altrimenti andrebbero reimpostate a mano ogni
     * volta. Le statistiche dell'annata restano: sono dati del mondo, e le usano
     * anche gli altri gruppi.
     */
    public function azzera(Request $request, LeagueSeason $leagueSeason, SeasonReset $reset): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);
        $this->senzaFretta();

        $fatto = $reset->azzera($leagueSeason);

        return back()->with('successo', sprintf(
            'Stagione %s azzerata: via %d giornate di classifica, %d sfide, %d carte, %d draft, '
            .'%d tornei, %d formazioni, %d scambi. Regole e giornata di partenza restano. '
            .'Rigenera il calendario per ricominciare.',
            $leagueSeason->etichetta(),
            $fatto['giornate'], $fatto['sfide'], $fatto['carte'], $fatto['draft'],
            $fatto['tornei'], $fatto['formazioni'], $fatto['scambi'],
        ));
    }

    /**
     * Foglio bianco: via tutte le stagioni del gruppo, le squadre restano.
     *
     * È il pulsante da premere fra due prove: quello che nessuno vuole rifare a
     * mano sono le squadre — nomi, maglie, stemmi, allenatori — e infatti sono
     * l'unica cosa che sopravvive.
     */
    public function azzeraTutto(Request $request, LeagueReset $reset): RedirectResponse
    {
        $this->senzaFretta();

        $league = $request->user()->league;
        $fatto = $reset->tutto($league);

        app(StagioneAttiva::class)->dimentica();

        return back()->with('successo', sprintf(
            'Gruppo «%s» riportato al foglio bianco: via %d stagioni, %d carte, %d draft, %d sfide, '
            .'%d tornei, %d formazioni, %d scambi e %d statistiche simulate. '
            .'Le squadre iscritte e le annate di Serie A restano.',
            $league->name,
            $fatto['stagioni'], $fatto['carte'] ?? 0, $fatto['draft'] ?? 0, $fatto['sfide'] ?? 0,
            $fatto['tornei'] ?? 0, $fatto['formazioni'] ?? 0, $fatto['scambi'] ?? 0,
            $fatto['statistiche_finte'],
        ));
    }

    /**
     * Via le squadre iscritte. Separato, e apposta.
     *
     * È l'unico azzeramento che tocca le persone: nasconderlo dentro «azzera
     * tutto» significherebbe che chi voleva ripulire una prova si ritrova a
     * ricostruire dodici identità visive che nessuno gli aveva chiesto di
     * buttare.
     */
    public function azzeraIscritti(Request $request, LeagueReset $reset): RedirectResponse
    {
        $manager = $request->user();
        $quante = $reset->iscritti($manager->league, tranne: $manager);

        return back()->with('successo', sprintf(
            '%d %s rimosse. Sei rimasto tu: senza un amministratore il gruppo non avrebbe più una porta d\'ingresso.',
            $quante,
            $quante === 1 ? 'squadra' : 'squadre',
        ));
    }

    /** Cancella una stagione: carte, draft, sfide, classifica. Il gruppo resta. */
    public function elimina(Request $request, LeagueSeason $leagueSeason): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);

        $etichetta = $leagueSeason->etichetta();
        $leagueSeason->delete();
        app(StagioneAttiva::class)->dimentica();

        return back()->with('successo', "Stagione {$etichetta} cancellata.");
    }

    /** Le regole di casa, per stagione: si scrivono sopra a quelle del gruppo. */
    public function rinomina(Request $request, LeagueSeason $leagueSeason): RedirectResponse
    {
        $this->assertSua($request, $leagueSeason);

        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:60', new TestoValido],
        ]);

        $leagueSeason->league->update(['name' => $dati['nome']]);

        return back()->with('successo', 'Gruppo rinominato.');
    }

    /**
     * Cosa c'è in casa, annata per annata.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function annate(): Collection
    {
        return $this->loader->disponibili()->map(fn (int $anno) => [
            'anno' => $anno,
            'etichetta' => $anno.'/'.substr((string) ($anno + 1), 2),
            'giocatori' => PlayerSeason::where('season', $anno)->where('active', true)->count(),
            'daConfermare' => PlayerSeason::where('season', $anno)
                ->where('active', true)
                ->where('role_confirmed', false)
                ->count(),
            // Se nessuno vale più di 1, il listone non è mai stato né importato
            // né stimato: la rarità della prima giornata sarebbe un sorteggio.
            'quotato' => PlayerSeason::where('season', $anno)
                ->where('active', true)
                ->where('quotazione_iniziale', '>', 1)
                ->exists(),
            'giornate' => (int) Fixture::where('season', $anno)->max('matchday'),
            'inUso' => LeagueSeason::where('season', $anno)->count(),
        ]);
    }

    /** @return array<string,mixed> */
    private function riassunto(LeagueSeason $stagione): array
    {
        $giocate = $this->runner->giocate($stagione);

        return [
            'stagione' => $stagione,
            'giocate' => $giocate,
            'ultima' => $giocate === [] ? null : max($giocate),
            'prossima' => $this->runner->prossima($stagione),
            // La giornata cominciata e non ancora chiusa: lo stato che prima
            // non esisteva da nessuna parte, e che decide quali dei tre
            // pulsanti ha senso mostrare.
            'inCorso' => $inCorso = $this->runner->inCorso($stagione),
            // Che dati ha già addosso: le statistiche vere sopravvivono a ogni
            // azzeramento, quindi una giornata può arrivare qui già scaricata —
            // e magari a metà — senza che niente lo dicesse.
            'dati' => $inCorso ? $this->runner->statoStatistiche($stagione, $inCorso) : null,
            'totali' => $this->loader->giornate($stagione->season),
            'turni' => $stagione->matchups()->whereNull('tournament_id')->count(),
            'draft' => $this->statoDraft($stagione),
            'scoperte' => $this->giornateScoperte($stagione),
            'costo' => ($p = $this->runner->prossima($stagione))
                ? $this->runner->costoChiamate($stagione->season, $p)
                : 0,
        ];
    }

    /**
     * Quante giornate restano senza sfide in calendario.
     *
     * Con dodici squadre e due gironi il campionato dura ventidue turni, ma
     * l'annata ne ha trentotto: dalla ventitreesima in poi si pesca, si scambia
     * e si schiera senza che nulla sia in palio, e la classifica resta ferma.
     *
     * È previsto dal design — il margine serve a chi parte a stagione iniziata,
     * e ciò che avanza si riempie con un torneo — ma **invisibile**: senza
     * dirlo, ci si accorge del vuoto solo quando i punti smettono di muoversi.
     * Con dodici squadre bastano tre gironi per arrivare alla trentatreesima.
     */
    private function giornateScoperte(LeagueSeason $stagione): int
    {
        $ultimoTurno = (int) $stagione->matchups()->whereNull('tournament_id')->max('matchday');

        if ($ultimoTurno === 0) {
            return 0;   // il calendario non c'è ancora: lo dice già un altro avviso
        }

        return max(0, $this->loader->giornate($stagione->season) - $ultimoTurno);
    }

    /**
     * Il draft ancora aperto, con quanto gli manca.
     *
     * Serve a rendere visibile la cosa che altrimenti blocca una stagione senza
     * dirlo: se il draft della prossima giornata non è concluso, giocarla
     * produce formazioni vuote e nessuno capisce perché.
     *
     * @return array<string,mixed>|null
     */
    private function statoDraft(LeagueSeason $stagione): ?array
    {
        $draft = $this->runner->draftInSospeso($stagione);

        if (! $draft) {
            return null;
        }

        return [
            'matchday' => $draft->matchday,
            'state' => $draft->state,
            'fatti' => $draft->turns()->where('state', 'done')->count(),
            'totali' => $draft->turns()->count(),
        ];
    }

    private function assertSua(Request $request, LeagueSeason $stagione): void
    {
        abort_unless($stagione->league_id === $request->user()->league_id, 404);
    }
}
