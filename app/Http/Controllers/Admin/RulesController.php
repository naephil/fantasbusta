<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\LeagueSeason;
use App\Services\Scoring\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Le regole del gioco, modificabili senza toccare il codice.
 *
 * Si tarano **per stagione**: ogni gruppo gioca come vuole, e può cambiare idea
 * da un anno all'altro senza riscrivere il passato. Quello che si salva qui
 * vale per la stagione che si sta guardando; le altre restano com'erano.
 *
 * ⚠️ Salvare **non ricalcola** le giornate già chiuse. È deliberato: riscrivere
 * a sorpresa una classifica che tutti hanno già letto sarebbe peggio di una
 * lieve incoerenza storica. Per applicare una modifica al passato si rilancia
 * la giornata dalla pagina di gestione, che è rieseguibile apposta.
 */
class RulesController extends Controller
{
    /** Gli eventi che compongono il fantavoto, nell'ordine in cui si leggono. */
    private const EVENTI = [
        'gol' => 'Gol',
        'assist' => 'Assist',
        'rigore_segnato' => 'Rigore segnato',
        'rigore_parato' => 'Rigore parato',
        'rigore_sbagliato' => 'Rigore sbagliato',
        'ammonizione' => 'Ammonizione',
        'espulsione' => 'Espulsione',
        'autorete' => 'Autorete',
        'gol_subito' => 'Gol subito',
    ];

    public function edit(Request $request): View
    {
        $stagione = $this->stagioneOAbort($request->user());

        return view('admin.regole', [
            'stagione' => $stagione,
            'valori' => Settings::for($stagione)->tutto(),
            'eventi' => self::EVENTI,
            'ruoli' => Role::cases(),
            'ritoccati' => Settings::scostamenti($stagione) !== [],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $stagione = $this->stagioneOAbort($request->user());

        $dati = $request->validate([
            // Nessun coefficiente ha un segno obbligato: portare `gol` a −3 lo
            // trasforma in un malus, ed è una taratura legittima. L'unico
            // limite è la scala, perché un valore assurdo non è una scelta di
            // gioco ma un dito scivolato sulla tastiera.
            'eventi' => ['array'],
            'eventi.*.default' => ['nullable', 'numeric', 'between:-20,20'],
            'eventi.*.P' => ['nullable', 'numeric', 'between:-20,20'],
            'eventi.*.D' => ['nullable', 'numeric', 'between:-20,20'],
            'eventi.*.C' => ['nullable', 'numeric', 'between:-20,20'],
            'eventi.*.A' => ['nullable', 'numeric', 'between:-20,20'],

            'voto_base.centro' => ['required', 'numeric', 'between:1,10'],
            'voto_base.k' => ['required', 'numeric', 'between:0.1,5'],
            'voto_base.min' => ['required', 'numeric', 'between:0,10'],
            'voto_base.max' => ['required', 'numeric', 'between:0,15'],

            'senza_voto.default' => ['required', 'numeric', 'between:0,10'],
            'senza_voto.P' => ['required', 'numeric', 'between:0,10'],

            'sostituzioni.max' => ['required', 'integer', 'between:0,11'],
            'formazione_mancante.penalita' => ['required', 'numeric', 'between:-50,0'],

            'power.pesi.baseline' => ['required', 'numeric', 'between:0,1'],
            'power.pesi.fantamedia' => ['required', 'numeric', 'between:0,1'],
            'power.pesi.forma' => ['required', 'numeric', 'between:0,1'],
            'power.pesi.titolarita' => ['required', 'numeric', 'between:0,1'],
            'power.pesi.rischio' => ['required', 'numeric', 'between:0,1'],
            'power.giornate_forma' => ['required', 'integer', 'between:1,10'],

            // ⚠️ Devono stare nel form anche se quasi nessuno le toccherà:
            // `soloScostamenti()` riscrive tutto il blocco salvato, quindi un
            // parametro validato ma non inviato sparirebbe al primo salvataggio
            // della pagina. Una taratura che si azzera da sola premendo «salva»
            // su tutt'altro è il genere di guasto che si scopre tre giornate
            // dopo, quando la piramide non torna più.
            'power.soglie.pacco' => ['required', 'numeric', 'between:0,100'],
            'power.soglie.monnezza' => ['required', 'numeric', 'between:0,100'],

            'draft.giri' => ['required', 'integer', 'between:1,10'],
            'draft.carte_per_busta' => ['required', 'integer', 'between:1,10'],
            'draft.quota_finestra' => ['required', 'numeric', 'between:0.05,0.95'],

            // Da un minuto — che è la taratura da collaudo — a una giornata
            // intera, che è il massimo oltre cui un draft non è più un draft.
            'draft.turno_min_minuti' => ['required', 'integer', 'between:1,1440'],
            'draft.turno_max_minuti' => ['required', 'integer', 'between:1,1440'],

            // `passo` non può essere zero: sarebbe una rete ogni zero punti,
            // cioè infinite reti appena si tocca la prima soglia.
            'sfida.gol.attivo' => ['nullable', 'boolean'],
            'sfida.gol.prima_soglia' => ['required', 'numeric', 'between:0,200'],
            'sfida.gol.passo' => ['required', 'numeric', 'between:0.5,50'],

            'sfida.soglia_pareggio' => ['required', 'numeric', 'between:0,20'],
            'sfida.vittoria' => ['required', 'integer', 'between:0,10'],
            'sfida.pareggio' => ['required', 'integer', 'between:0,10'],
            'sfida.sconfitta' => ['required', 'integer', 'between:0,10'],

            'calendario.gironi' => ['required', 'integer', 'between:1,6'],
            'modificatore_difesa.attivo' => ['nullable', 'boolean'],
        ]);

        if ($errore = $this->incoerenze($dati)) {
            return back()->withInput()->withErrors(['regole' => $errore]);
        }

        // Le caselle non spuntate non arrivano affatto nella richiesta, quindi
        // il valore va preso da `boolean()` e non da ciò che la validazione ha
        // trovato: senza, togliere la spunta non spegnerebbe niente.
        $dati['modificatore_difesa'] = ['attivo' => $request->boolean('modificatore_difesa.attivo')];
        $dati['sfida']['gol']['attivo'] = $request->boolean('sfida.gol.attivo');
        $dati['eventi'] = $this->ripulisciEventi($dati['eventi'] ?? []);

        // Si salvano solo gli scostamenti: un parametro aggiunto al codice in
        // futuro entrerà in vigore da solo, invece di restare congelato al
        // valore che aveva il giorno in cui qualcuno ha premuto «salva».
        Settings::salva($stagione, $this->soloScostamenti($dati, Settings::DEFAULTS));

        return back()->with(
            'successo',
            "Regole di {$stagione->etichetta()} aggiornate. Le giornate già chiuse restano come sono.",
        );
    }

    /** Torna alle regole di fabbrica, per questa stagione sola. */
    public function reset(Request $request): RedirectResponse
    {
        $stagione = $this->stagioneOAbort($request->user());

        Settings::salva($stagione, []);

        return back()->with('successo', 'Regole riportate ai valori di partenza.');
    }

    /** Copia la taratura di questa stagione su un'altra dello stesso gruppo. */
    public function copia(Request $request): RedirectResponse
    {
        $stagione = $this->stagioneOAbort($request->user());

        $dati = $request->validate([
            'verso' => ['required', 'integer'],
        ]);

        $destinazione = LeagueSeason::where('league_id', $stagione->league_id)
            ->whereKeyNot($stagione->id)
            ->find($dati['verso']);

        if (! $destinazione) {
            return back()->withErrors(['regole' => 'Quella stagione non è del tuo gruppo.']);
        }

        Settings::salva($destinazione, Settings::scostamenti($stagione));

        return back()->with('successo', "Regole copiate su {$destinazione->etichetta()}.");
    }

    /**
     * I controlli che nessuna regola di validazione può fare da sola.
     *
     * @param  array<string,mixed>  $dati
     */
    private function incoerenze(array $dati): ?string
    {
        if ($dati['voto_base']['min'] >= $dati['voto_base']['max']) {
            return 'Il minimo del voto base deve stare sotto il massimo.';
        }

        // A estremi invertiti la durata del turno non è più un intervallo: la
        // stretta fra i due limiti restituirebbe sempre il minimo, e il massimo
        // scritto nel form non varrebbe niente senza dirlo a nessuno.
        if ($dati['draft']['turno_min_minuti'] > $dati['draft']['turno_max_minuti']) {
            return 'Il turno di draft più corto non può durare più di quello più lungo.';
        }

        // A soglie invertite la fascia «pacco» non esisterebbe più: tutto
        // quello che le spetterebbe sarebbe già stato preso da «monnezza».
        if ($dati['power']['soglie']['monnezza'] > $dati['power']['soglie']['pacco']) {
            return 'La soglia della Monnezza deve stare sotto quella del Pacco: è la fascia peggiore.';
        }

        // I pesi del power sono quote di una normalizzazione: se non fanno uno,
        // il punteggio non è più confrontabile fra giornate e i tier ballano
        // senza che nessuno abbia cambiato prestazione.
        //
        // ⚠️ Il rischio resta fuori dalla somma perché si SOTTRAE: non è una
        // quota del valore, è una penalità che gli si toglie. Contarlo qui
        // dentro renderebbe impossibile tarare i default, che infatti fanno
        // 1.05 in totale e 1.00 fra le quattro voci positive.
        $positivi = array_diff_key($dati['power']['pesi'], ['rischio' => null]);
        $somma = array_sum($positivi);

        if (abs($somma - 1.0) > 0.001) {
            return 'I quattro pesi positivi del power devono sommare a 1: adesso fanno '
                .round($somma, 3).'. Il rischio non conta, perché si sottrae.';
        }

        return null;
    }

    /**
     * Butta via le caselle lasciate vuote.
     *
     * Un override di ruolo vuoto non è «zero», è «non c'è»: deve ricadere sul
     * valore di default, non azzerare l'evento per quel reparto.
     *
     * @param  array<string,array<string,mixed>>  $eventi
     * @return array<string,array<string,float>>
     */
    private function ripulisciEventi(array $eventi): array
    {
        return collect($eventi)
            ->map(fn (array $scala) => collect($scala)
                ->filter(fn ($v) => $v !== null && $v !== '')
                ->map(fn ($v) => (float) $v)
                ->all())
            ->filter(fn (array $scala) => $scala !== [])
            ->all();
    }

    /**
     * Solo ciò che differisce dai default, foglia per foglia.
     *
     * @param  array<string,mixed>  $dati
     * @param  array<string,mixed>  $default
     * @return array<string,mixed>
     */
    private function soloScostamenti(array $dati, array $default): array
    {
        $diverso = [];

        foreach ($dati as $chiave => $valore) {
            $base = $default[$chiave] ?? null;

            if (is_array($valore) && is_array($base)) {
                if ($sotto = $this->soloScostamenti($valore, $base)) {
                    $diverso[$chiave] = $sotto;
                }

                continue;
            }

            // Confronto sul valore e non sul tipo: dal form arrivano stringhe,
            // e '3' contro 3 è la stessa regola scritta due volte.
            if ($base === null || (string) $valore !== (string) $base) {
                $diverso[$chiave] = $valore;
            }
        }

        return $diverso;
    }
}
