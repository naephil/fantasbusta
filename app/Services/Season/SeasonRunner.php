<?php

namespace App\Services\Season;

use App\Models\Draft;
use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\PlayerStat;
use App\Models\Standing;
use App\Services\Calendar\StandingsUpdater;
use App\Services\Draft\DraftBuilder;
use App\Services\Draft\PackOpener;
use App\Services\Ingest\StatSync;
use App\Services\Power\PowerUpdater;
use App\Services\Scoring\MatchdayScorer;
use App\Services\Simulation\MatchdaySimulator;
use App\Services\Tournament\TournamentService;
use App\Services\Trade\TradeService;
use RuntimeException;

/**
 * Gioca una giornata della stagione caricata, dall'inizio alla fine.
 *
 * È il pulsante «avanti di una giornata»: scarica i dati, calcola i voti,
 * chiude le sfide, aggiorna la classifica, fa avanzare i tornei e prepara il
 * draft successivo. Quello che in una stagione vera è sparso su cinque giorni,
 * qui succede quando lo si decide.
 *
 * ⚠️ Il calendario della stagione caricata è nel passato, quindi le date
 * delle partite non servono a scandire il draft: le finestre si aprono
 * ADESSO, per il tempo che si decide. Usare i primi fischi veri
 * significherebbe generare draft già scaduti alla nascita.
 */
class SeasonRunner
{
    public function __construct(
        private StatSync $stats,
        private MatchdaySimulator $simulatore,
        private MatchdayScorer $scorer,
        private StandingsUpdater $standings,
        private TournamentService $tornei,
        private PowerUpdater $power,
        private DraftBuilder $drafts,
        private PackOpener $opener,
        private TradeService $trades,
    ) {}

    /**
     * ① La giornata comincia: si congelano le rose e si apre il draft dopo.
     *
     * ⚠️ Lo dichiara una persona, non l'orologio. Le annate si giocano
     * ricaricate — il calendario di Serie A è nel passato e ogni primo fischio
     * è già suonato — quindi un innesco automatico sulla data farebbe partire
     * tutte le giornate insieme al primo giro di cron. È lo stesso motivo per
     * cui le finestre del draft si aprono ADESSO e non ai fischi veri.
     *
     * Da questo momento e non prima: le formazioni della giornata sono quelle,
     * e il draft della SUCCESSIVA apre. Non quello di due giornate dopo — che
     * era lo sfasamento di prima, ed è ciò che faceva comparire in formazione
     * le carte di una giornata che il manager credeva ancora da pescare.
     *
     * Il conto del power torna identico: il draft di N+1 guarda i dati fino
     * alla N−1, che a giornata N appena cominciata sono esattamente quelli
     * completi.
     *
     * @return array<string,mixed>
     */
    public function iniziaGiornata(LeagueSeason $stagione, int $matchday, int $oreDraft = 48): array
    {
        $this->assertInCalendario($stagione, $matchday);

        if ($stagione->giornataIniziata($matchday)) {
            throw new RuntimeException("La giornata {$matchday} è già cominciata.");
        }

        $stagione->update(['started_matchday' => $matchday]);

        return [
            'giornata' => $matchday,
            'draft' => $this->preparaDraft($stagione->refresh(), $matchday + 1, $oreDraft),
        ];
    }

    /**
     * ② I voti che ci sono, senza toccare la classifica.
     *
     * I risultati veri arrivano alla spicciolata — sabato alle 15, domenica
     * sera, il lunedì — e questo è il passo che si può rilanciare quante volte
     * si vuole mentre arrivano. Scrive statistiche e fantavoti, cioè quello che
     * si può già guardare in Serie A e nel tabellino.
     *
     * ⚠️ Sfide, classifica e tornei NON si muovono. Un punteggio calcolato a
     * metà giornata non è un risultato provvisorio: è un risultato sbagliato,
     * perché mezza lega ha ancora i titolari in campo. Aggiornarlo a ogni
     * scarico farebbe ballare la classifica per un motivo che non è mai «hanno
     * giocato meglio».
     *
     * @param  bool  $tutte  completa la giornata invece di prendere solo le partite finite
     * @return array<string,mixed>
     */
    public function parziali(
        LeagueSeason $stagione,
        int $matchday,
        bool $simula = false,
        bool $tutte = false,
        bool $sovrascriviReali = false,
    ): array {
        $this->assertInCalendario($stagione, $matchday);

        $season = $stagione->season;

        $statistiche = $simula
            ? $this->simulatore->simulate($season, $matchday, sovrascriviReali: $sovrascriviReali)
            : $this->stats->matchday($season, $matchday, includiNonFinite: $tutte);

        return [
            'giornata' => $matchday,
            'fonte' => $simula ? 'simulata' : 'reale',
            'statistiche' => $statistiche,
            // Solo i fantavoti dei giocatori: le formazioni si calcolano alla
            // chiusura, quando c'è tutto e le sostituzioni hanno senso.
            'voti' => $this->scorer->scorePlayers($stagione, $matchday),
        ];
    }

    /**
     * ③ La giornata si chiude: da qui i punti sono punti.
     *
     * Il passo che scrive la storia — formazioni, sfide, classifica, tornei — e
     * l'unico che non conviene rilanciare a cuor leggero.
     *
     * @return array<string,mixed>
     */
    public function chiudiGiornata(LeagueSeason $stagione, int $matchday): array
    {
        $this->assertInCalendario($stagione, $matchday);

        $esito = ['giornata' => $matchday];

        // Voti e formazioni d'ufficio. `run()` ricalcola anche i fantavoti:
        // costa poco ed è la garanzia che si chiuda su quello che c'è adesso e
        // non su un parziale scaricato tre ore fa.
        $esito += $this->scorer->run($stagione, $matchday);

        $esito['classifica'] = $this->standings->update($stagione, $matchday);
        $esito['tornei'] = $this->tornei->avanzaTutti($stagione, $matchday);

        // Il mercato di questa giornata è chiuso: le proposte rimaste appese
        // non possono restare pendenti per sempre, o riapparirebbero come
        // accettabili quando le carte non sono più nemmeno in rosa.
        $esito['scambi_scaduti'] = $this->trades->expirePending($stagione->id, $matchday);

        // Se non resta più niente da giocare la stagione si chiude da sola.
        // Lasciarla «in corso» per sempre la terrebbe nel mirino del cron e
        // non direbbe a nessuno che è finita.
        if ($this->prossima($stagione->refresh()) === null) {
            $stagione->update(['state' => 'conclusa']);
            $esito['conclusa'] = true;
        }

        return $esito;
    }

    /**
     * Tutti e tre i passi in un colpo: l'acceleratore.
     *
     * Nel gioco vero i tre momenti sono distanti giorni — la giornata comincia
     * il sabato, i voti arrivano fino al lunedì, e solo allora si chiude. Qui
     * succedono nell'ordine giusto e subito, che è ciò che serve per collaudare
     * una stagione o per rigiocare un'annata archiviata, dove non c'è nessuna
     * attesa da rispettare.
     *
     * @param  bool  $simula  genera statistiche invece di scaricarle
     * @param  int  $oreDraft  quanto dura la finestra del prossimo draft
     * @return array<string,mixed>
     */
    public function gioca(
        LeagueSeason $stagione,
        int $matchday,
        bool $simula = false,
        int $oreDraft = 48,
        bool $sovrascriviReali = false,
    ): array {
        $esito = [];

        if (! $stagione->giornataIniziata($matchday)) {
            $esito += $this->iniziaGiornata($stagione, $matchday, $oreDraft);
        }

        $esito += $this->parziali($stagione->refresh(), $matchday, $simula, tutte: true, sovrascriviReali: $sovrascriviReali);

        return $esito + $this->chiudiGiornata($stagione->refresh(), $matchday);
    }

    private function assertInCalendario(LeagueSeason $stagione, int $matchday): void
    {
        if (! Fixture::where('season', $stagione->season)->where('matchday', $matchday)->exists()) {
            throw new RuntimeException(
                "La giornata {$matchday} non è nel calendario {$stagione->etichetta()}: carica prima quell'annata.",
            );
        }
    }

    /**
     * Che dati ci sono già addosso a una giornata, e di che specie.
     *
     * ⚠️ Serve a rendere visibile una cosa che prima si scopriva solo
     * sbattendoci contro: le statistiche VERE sopravvivono a ogni azzeramento
     * di stagione — costano chiamate all'API e sono condivise fra i gruppi —
     * quindi una giornata scaricata mesi fa resta scaricata anche dopo aver
     * riportato la lega al foglio bianco. Chi poi prova a simularla si sente
     * rispondere che ci sono già i voti veri, e non ha modo di sapere né da
     * dove vengano né quanto siano completi.
     *
     * `partiteConDati` è la voce che conta: un download interrotto a metà — per
     * il tetto di chiamate, o perché metà partite non erano ancora finite —
     * lascia una giornata che ESISTE ma è mezza vuota, ed è esattamente quella
     * che fa dire «all'Atalanta manca mezza squadra».
     *
     * @return array{reali: int, simulate: int, partite: int, partiteConDati: int}
     */
    public function statoStatistiche(LeagueSeason $stagione, int $matchday): array
    {
        $season = $stagione->season;

        $righe = PlayerStat::where('season', $season)
            ->where('matchday', $matchday)
            ->selectRaw('source, count(*) as n')
            ->groupBy('source')
            ->pluck('n', 'source');

        $squadreConDati = PlayerStat::where('player_stats.season', $season)
            ->where('player_stats.matchday', $matchday)
            ->join('player_seasons', function ($j) use ($season) {
                $j->on('player_seasons.player_id', '=', 'player_stats.player_id')
                    ->where('player_seasons.season', '=', $season);
            })
            ->distinct()
            ->pluck('player_seasons.team_id');

        $partite = Fixture::where('season', $season)->where('matchday', $matchday)->get();

        return [
            'reali' => (int) ($righe['reale'] ?? 0),
            'simulate' => (int) ($righe['simulata'] ?? 0),
            'partite' => $partite->count(),
            // Una partita è «coperta» se entrambe le squadre hanno qualcuno in
            // tabellino: con una sola, il tabellone mostra metà campo vuoto.
            'partiteConDati' => $partite
                ->filter(fn (Fixture $f) => $squadreConDati->contains($f->home_team_id)
                    && $squadreConDati->contains($f->away_team_id))
                ->count(),
        ];
    }

    /**
     * La giornata cominciata e non ancora chiusa, se ce n'è una.
     *
     * È lo stato che prima non esisteva da nessuna parte: le partite sono in
     * corso, i voti arrivano alla spicciolata, la classifica non si muove.
     */
    public function inCorso(LeagueSeason $stagione): ?int
    {
        $iniziata = $stagione->started_matchday;

        if ($iniziata === null || in_array($iniziata, $this->giocate($stagione), true)) {
            return null;
        }

        return $iniziata;
    }

    /** La prossima giornata da giocare: la prima senza statistiche. */
    public function prossima(LeagueSeason $stagione): ?int
    {
        $giocate = $this->giocate($stagione);

        return Fixture::where('season', $stagione->season)
            ->select('matchday')
            ->distinct()
            ->orderBy('matchday')
            ->pluck('matchday')
            ->first(fn (int $g) => $g >= $stagione->start_matchday && ! in_array($g, $giocate, true));
    }

    /**
     * Le giornate che QUESTA lega ha già chiuso, in ordine.
     *
     * ⚠️ Si guardano le classifiche della lega, non le statistiche dell'annata.
     * Sembra equivalente e non lo è: le statistiche sono condivise fra i gruppi
     * — si scaricano una volta sola, ed è giusto così — quindi se un altro
     * gruppo che rigioca lo stesso anno ha già giocato la 5ª, guardando lì
     * questa lega crederebbe di averla giocata anche lei e la salterebbe.
     *
     * @return list<int>
     */
    public function giocate(LeagueSeason $stagione): array
    {
        return Standing::where('league_season_id', $stagione->id)
            ->select('matchday')
            ->distinct()
            ->orderBy('matchday')
            ->pluck('matchday')
            ->all();
    }

    /**
     * Quante chiamate all'API costa una giornata.
     *
     * Una per partita: `/fixtures?id=` porta prestazioni ed eventi insieme.
     * Serve saperlo prima e non a metà scaricamento, perché con cento chiamate
     * al giorno il tetto si tocca in fretta.
     */
    public function costoChiamate(int $season, int $matchday): int
    {
        return Fixture::where('season', $season)->where('matchday', $matchday)->count();
    }

    /**
     * Il primo draft della stagione, l'unico che nessuna giornata può creare.
     *
     * Da qui in poi la catena si regge da sola: ogni «giornata N cominciata»
     * apre il draft della N+1. Ma la giornata di partenza non ha nessuna
     * giornata prima di sé a farlo, quindi senza questo passaggio si arriverebbe
     * alla prima con le rose vuote, la si «giocherebbe» e non succederebbe
     * niente. Non è un caso limite: è ogni stagione, al primo avvio.
     *
     * ⚠️ UNO, non due. Prima se ne aprivano due insieme — la giornata di
     * partenza e quella dopo — perché il ciclo era sfasato di due. Il rovescio
     * era che le carte della seconda giornata esistevano prima ancora di giocare
     * la prima, e la pagina della formazione le mostrava: una lista di nomi di
     * una giornata che il manager credeva ancora da pescare, e una busta che
     * quando si apriva non rivelava più niente.
     *
     * @return list<string> cosa è stato aperto, per il messaggio all'utente
     */
    public function apriPrimoDraft(LeagueSeason $stagione, int $ore = 48): array
    {
        return collect([$stagione->start_matchday])
            ->map(fn (int $g) => $this->preparaDraft($stagione, $g, $ore))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Sbusta d'ufficio tutto quello che resta, e chiude i draft.
     *
     * Nel gioco vero questo lavoro lo fa `draft:tick` dal cron, un turno alla
     * volta man mano che scadono. In una stagione di prova aspettare non ha
     * senso: si vuole arrivare a fine giornata adesso.
     *
     * Passa dallo stesso PackOpener della strada del manager — le due possono
     * arrivare nello stesso istante, ed è la ragione per cui il lock non va
     * duplicato.
     *
     * @return array{buste: int, draft: int}
     */
    public function concludiDraft(LeagueSeason $stagione): array
    {
        $buste = 0;
        $chiusi = 0;

        /*
         * ⚠️ UN draft per volta: quello in gioco, non tutti quelli aperti.
         *
         * All'avvio di una stagione ne partono due insieme — la giornata di
         * partenza e quella dopo, perché il ciclo normale è sfasato di due e le
         * prime due non le preparerebbe nessuno. Prendendoli tutti, un click su
         * «sbusta tutto d'ufficio» ne consumava DUE, e il danno non era la
         * fretta: erano le carte della giornata seguente, assegnate prima che la
         * precedente fosse giocata.
         *
         * Da lì lo spoiler. La pagina della formazione mostra la rosa della
         * prossima giornata da giocare, quindi quelle carte comparivano lì —
         * elencate per nome, di una giornata che il manager credeva ancora da
         * pescare — e la busta, quando finalmente si apriva, non rivelava più
         * niente: si era già letta la lista giorni prima.
         *
         * La pagina di gestione dice «Draft della Nª», al singolare, e prende
         * il primo con `draftInSospeso()`. Adesso il pulsante fa esattamente
         * quello che il titolo sopra di lui promette.
         */
        $aperti = Draft::where('league_season_id', $stagione->id)
            ->whereIn('state', ['pending', 'open'])
            ->orderBy('matchday')
            ->limit(1)
            ->get();

        foreach ($aperti as $draft) {
            $draft->update(['state' => 'open']);
            $this->opener->activateNext($draft);

            // Il limite è una cintura contro un turno che non si chiude: senza,
            // un bug altrove diventerebbe un ciclo infinito dentro una
            // richiesta HTTP, che è il modo peggiore di scoprirlo.
            for ($guardia = 0; $guardia < 1000; $guardia++) {
                $turno = $draft->fresh()->activeTurn();

                if (! $turno) {
                    if (! $this->opener->activateNext($draft)) {
                        break;
                    }

                    continue;
                }

                if ($this->opener->open($turno, 'auto') !== null) {
                    $buste++;
                }
            }

            $draft->update(['state' => 'closed']);
            $chiusi++;
        }

        return ['buste' => $buste, 'draft' => $chiusi];
    }

    /** Il draft di quella giornata è ancora da concludere? */
    public function draftInSospeso(LeagueSeason $stagione): ?Draft
    {
        return Draft::where('league_season_id', $stagione->id)
            ->whereIn('state', ['pending', 'open'])
            ->orderBy('matchday')
            ->first();
    }

    private function preparaDraft(LeagueSeason $stagione, int $matchday, int $ore): ?string
    {
        if (! Fixture::where('season', $stagione->season)->where('matchday', $matchday)->exists()) {
            return null;   // stagione finita, niente più da preparare
        }

        if (Draft::where('league_season_id', $stagione->id)->where('matchday', $matchday)->exists()) {
            return 'già pronto';
        }

        $this->power->update($stagione, $matchday);

        $draft = $this->drafts->build(
            $stagione,
            $matchday,
            opensAt: now(),
            deadlineAt: now()->addHours($ore),
        );

        // Aperto e col primo turno già attivo: senza cron nessuno lo farebbe
        // partire, e chi apre la pagina troverebbe un draft fermo.
        $draft->update(['state' => 'open']);
        $this->opener->activateNext($draft);

        return "giornata {$matchday}, aperto per {$ore}h";
    }
}
