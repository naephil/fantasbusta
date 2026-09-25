<?php

use App\Http\Controllers\Admin\ManagerController;
use App\Http\Controllers\Admin\PlayerReviewController;
use App\Http\Controllers\Admin\RulesController;
use App\Http\Controllers\Admin\SeasonController;
use App\Http\Controllers\Auth\RegistrazioneController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CartaController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\LineupController;
use App\Http\Controllers\MatchdayController;
use App\Http\Controllers\MatchupController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\RisultatiController;
use App\Http\Controllers\StagioneController;
use App\Http\Controllers\StandingsController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\TradeController;
use App\Models\PlayerSeason;
use App\Support\StagioneAttiva;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);

    /*
     * Iscriversi da sé, ma solo col link giusto in mano.
     *
     * Il gettone nell'indirizzo è insieme la chiave e l'indicazione della lega:
     * senza, non ci sarebbe risposta alla domanda «in quale gruppo entra chi si
     * iscrive», e con più leghe sullo stesso sito la domanda si pone davvero.
     *
     * ⚠️ Il freno vale su GET quanto su POST: la GET è il punto da cui si
     * proverebbero i gettoni a raffica, e lasciarla libera renderebbe il
     * gettone indovinabile a forza bruta invece che segreto.
     */
    Route::middleware('throttle:20,1')->group(function () {
        Route::get('/registra/{gettone}', [RegistrazioneController::class, 'create'])->name('registra');
        Route::post('/registra/{gettone}', [RegistrazioneController::class, 'store']);
    });
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::get('/', function () {
        $stagione = app(StagioneAttiva::class)->per(auth()->user());

        return view('home', [
            'stagione' => $stagione,
            // Il cartellino rosso sul listone riguarda l'annata che si sta
            // giocando: quella di un'altra non è un lavoro che aspetta.
            'daRivedere' => auth()->user()->is_admin && $stagione
                ? PlayerSeason::daConfermare($stagione->season)->count()
                : 0,
        ]);
    })->name('home');

    // Il cambio di stagione è di tutti: le annate concluse restano leggibili.
    Route::post('/stagione', StagioneController::class)->name('stagione.scegli');

    Route::get('/draft', [DraftController::class, 'show'])->name('draft.show');
    Route::post('/draft/apri', [DraftController::class, 'open'])->name('draft.open');

    // Rivedere una busta già aperta. Non muove niente — le carte sono in rosa
    // da un pezzo — e serve solo all'animazione: chi trova il turno scaduto, o
    // ha lo sbustamento automatico acceso, si ritrova le carte senza averle mai
    // viste uscire, che è il momento in cui il gioco succede.
    Route::get('/draft/busta/{draftTurn}', [DraftController::class, 'rivedi'])->name('draft.rivedi');
    // Interrogata ogni pochi secondi da chi sta col draft aperto: è l'unica
    // pagina in cui si aspetta, e senza non ci si accorge del proprio turno.
    Route::get('/draft/stato', [DraftController::class, 'stato'])->name('draft.stato');

    Route::get('/giornata', [MatchdayController::class, 'show'])->name('matchday.show');

    // Una carta sola, resa a richiesta: è l'anteprima che compare passando il
    // mouse su un nome. Renderle tutte nella pagina significherebbe cinquecento
    // SVG in un documento che deve solo mostrare dei voti.
    Route::get('/carta/{card}', [CartaController::class, 'show'])->name('carta.show');

    Route::get('/formazione', [LineupController::class, 'show'])->name('lineup.show');
    Route::post('/formazione', [LineupController::class, 'store'])->name('lineup.store');

    Route::get('/mercato', [TradeController::class, 'index'])->name('trades.index');
    Route::get('/mercato/{manager}', [TradeController::class, 'create'])->name('trades.create');
    Route::post('/mercato/{manager}', [TradeController::class, 'store'])->name('trades.store');
    Route::post('/scambi/{trade}/accetta', [TradeController::class, 'accept'])->name('trades.accept');
    Route::post('/scambi/{trade}/rifiuta', [TradeController::class, 'reject'])->name('trades.reject');
    Route::post('/scambi/{trade}/ritira', [TradeController::class, 'cancel'])->name('trades.cancel');

    Route::get('/squadra', [TeamController::class, 'edit'])->name('team.edit');
    // Chi entra si cambia la password da solo: altrimenti la saprebbe per
    // sempre chi l'ha invitato, essendo lui a inventarla.
    Route::post('/squadra/password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('/squadra', [TeamController::class, 'update'])->name('team.update');

    Route::get('/classifica', [StandingsController::class, 'index'])->name('standings.index');

    // Tutti i risultati di una giornata, campionato e tornei insieme. Mancava
    // un posto dove vedere «com'è andata»: le sfide di campionato stavano in
    // fondo alla classifica, quelle dei tornei ciascuna nel proprio tabellone.
    Route::get('/risultati', [RisultatiController::class, 'index'])->name('risultati.index');

    // Il tabellino della singola sfida. Senza, la classifica dice «74,5 – 71,0»
    // e non c'è modo di sapere perché: un fantacalcio in cui non si apre il
    // tabellino è un generatore di numeri.
    Route::get('/sfida/{matchup}', [MatchupController::class, 'show'])->name('matchup.show');

    Route::get('/tornei', [TournamentController::class, 'index'])->name('tornei.index');
    Route::get('/tornei/{tournament}', [TournamentController::class, 'show'])->name('tornei.show');

    Route::get('/statistiche', [StatsController::class, 'index'])->name('stats.index');

    /*
     * Il listone è di tutti.
     *
     * Era una pagina d'amministrazione, e non aveva senso che lo fosse: dice
     * chi c'è, con che ruolo e a quanto è quotato — cioè le informazioni su cui
     * si decide al draft e al mercato. Tenerla chiusa dava all'admin un
     * vantaggio che nessuno aveva voluto dargli.
     *
     * ⚠️ E la verifica dell'identità è proprio il lavoro che conviene dividere:
     * sono ~550 facce da guardare a occhio e nessuna automazione può farlo. In
     * dodici è mezz'ora, da soli è un pomeriggio — ed è il motivo per cui
     * l'admin arrivava alla prima giornata senza averla fatta.
     */
    Route::get('/listone', [PlayerReviewController::class, 'index'])->name('listone.index');
    Route::post('/listone/{playerSeason}/identita', [PlayerReviewController::class, 'verify'])->name('listone.verify');

    // Creare un torneo e deciderne i partecipanti è una scelta di lega.
    Route::middleware('admin')->group(function () {
        Route::get('/tornei-nuovo', [TournamentController::class, 'create'])->name('tornei.create');
        Route::post('/tornei', [TournamentController::class, 'store'])->name('tornei.store');
        Route::post('/tornei/{tournament}/avvia', [TournamentController::class, 'start'])->name('tornei.start');
        Route::delete('/tornei/{tournament}', [TournamentController::class, 'destroy'])->name('tornei.destroy');
    });

    /*
     * La gestione, tutta dentro l'interfaccia.
     *
     * Due piani distinti e non intercambiabili: le ANNATE di Serie A sono dati
     * del mondo — si scaricano una volta e le usano tutti i gruppi — mentre le
     * STAGIONI di lega sono la partita, e ognuna sceglie che annata giocare.
     *
     * Il listone è roba dell'admin: ruoli e quotazioni sono decisioni umane e
     * restano tali, il sync non le scrive mai da solo. Ma «umana» non vuol dire
     * «da terminale», ed è il motivo per cui l'.xlsx si carica da questa pagina.
     */
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/gestione', [SeasonController::class, 'index'])->name('gestione');

        Route::post('/annate', [SeasonController::class, 'loadAnnata'])->name('annata.load');
        Route::post('/annate/listone', [SeasonController::class, 'importListone'])->name('annata.listone');
        Route::post('/annate/stima', [SeasonController::class, 'stimaListone'])->name('annata.stima');
        Route::delete('/annate', [SeasonController::class, 'scartaAnnata'])->name('annata.scarta');

        Route::post('/stagioni', [SeasonController::class, 'creaStagione'])->name('stagione.crea');
        Route::post('/stagioni/{leagueSeason}/calendario', [SeasonController::class, 'calendario'])->name('stagione.calendario');
        Route::post('/stagioni/{leagueSeason}/avvia', [SeasonController::class, 'avvia'])->name('stagione.avvia');
        Route::post('/stagioni/{leagueSeason}/draft', [SeasonController::class, 'concludiDraft'])->name('stagione.draft');
        Route::post('/stagioni/{leagueSeason}/gioca', [SeasonController::class, 'gioca'])->name('stagione.gioca');

        // I tre momenti di una giornata, che prima erano un pulsante solo. I
        // risultati veri arrivano alla spicciolata, e fra «è cominciata» e «è
        // finita» c'è una finestra lunga giorni in cui i voti si guardano ma la
        // classifica non si muove.
        Route::post('/stagioni/{leagueSeason}/inizia', [SeasonController::class, 'iniziaGiornata'])->name('stagione.inizia');
        Route::post('/stagioni/{leagueSeason}/parziali', [SeasonController::class, 'parziali'])->name('stagione.parziali');
        Route::post('/stagioni/{leagueSeason}/chiudi', [SeasonController::class, 'chiudiGiornata'])->name('stagione.chiudi');

        // Azzerare non è cancellare: la stagione resta, con le sue regole e la
        // sua giornata di partenza, e se ne va solo ciò che è stato giocato.
        Route::post('/stagioni/{leagueSeason}/azzera', [SeasonController::class, 'azzera'])->name('stagione.azzera');
        Route::delete('/stagioni/{leagueSeason}', [SeasonController::class, 'elimina'])->name('stagione.elimina');
        Route::post('/gruppo', [SeasonController::class, 'rinomina'])->name('gruppo.rinomina');

        // I due azzeramenti grossi, separati apposta: uno riporta il gruppo al
        // foglio bianco tenendo le squadre, l'altro toglie le squadre. Chi
        // vuole ripulire una prova non deve ritrovarsi a rifare dodici maglie.
        Route::post('/gruppo/azzera', [SeasonController::class, 'azzeraTutto'])->name('gruppo.azzera');
        Route::delete('/gruppo/iscritti', [SeasonController::class, 'azzeraIscritti'])->name('gruppo.iscritti');

        // Le regole si tarano per stagione: ogni gruppo gioca come vuole, e può
        // cambiare idea da un anno all'altro senza riscrivere il passato.
        Route::get('/regole', [RulesController::class, 'edit'])->name('regole.edit');
        Route::post('/regole', [RulesController::class, 'update'])->name('regole.update');
        Route::post('/regole/azzera', [RulesController::class, 'reset'])->name('regole.azzera');
        Route::post('/regole/copia', [RulesController::class, 'copia'])->name('regole.copia');

        // Le squadre le crea chi tiene la lega, a mano o aprendo le iscrizioni:
        // il link d'invito nasce e muore qui, ed è l'unica porta da cui si entra
        // senza passare da lui.
        Route::get('/squadre', [ManagerController::class, 'index'])->name('squadre.index');
        Route::post('/squadre/invito', [ManagerController::class, 'apriInviti'])->name('squadre.invito.apri');
        Route::delete('/squadre/invito', [ManagerController::class, 'chiudiInviti'])->name('squadre.invito.chiudi');
        Route::post('/squadre', [ManagerController::class, 'store'])->name('squadre.store');
        Route::post('/squadre/bot', [ManagerController::class, 'bot'])->name('squadre.bot');
        Route::patch('/squadre/{manager}', [ManagerController::class, 'update'])->name('squadre.update');
        Route::delete('/squadre/{manager}', [ManagerController::class, 'destroy'])->name('squadre.destroy');

        // Ruolo e quotazione restano dell'admin: sono decisioni di gioco, e
        // scriverle dichiara il dato autorevole per tutta l'annata.
        Route::patch('/listone/{playerSeason}', [PlayerReviewController::class, 'update'])->name('players.update');
    });
});
