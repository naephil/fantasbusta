<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Manager;
use App\Rules\TestoValido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Chi gioca: le persone invitate e i bot che riempiono i posti vuoti.
 *
 * Due strade per far entrare qualcuno, e non sono intercambiabili:
 *
 *   a mano       l'admin crea la squadra e consegna a voce una password
 *                provvisoria. Una persona alla volta, ma nessun link in giro.
 *   col link     l'admin apre le iscrizioni e incolla l'indirizzo nella chat;
 *                ognuno si crea la squadra da sé, con la password che vuole.
 *
 * La seconda esiste perché dodici volontari a mano sono dodici password da
 * inventare e recapitare. ⚠️ Ma il link vale quanto una password condivisa: chi
 * ce l'ha entra senza che nessuno approvi. Si apre per il tempo che serve e si
 * chiude — o si rigenera, se è finito dove non doveva.
 */
class ManagerController extends Controller
{
    /** Nomi di comodo per i bot: si distinguono a colpo d'occhio dai veri. */
    private const NOMI_BOT = [
        'Automatica Bergamo', 'Ingranaggio FC', 'Real Pilota', 'Sporting Cinghia',
        'Dinamo Bulloni', 'Virtus Molla', 'Olympique Leva', 'Pro Rotella',
        'Unione Camme', 'Ideal Perno', 'Audace Biella', 'Libertas Volano',
    ];

    public function index(Request $request): View
    {
        $league = $request->user()->league;

        return view('admin.squadre', [
            'league' => $league,
            'squadre' => $league->managers()->orderByDesc('active')->orderBy('name')->get(),
            'stagione' => $this->stagione($request->user()),
        ]);
    }

    /**
     * Apre le iscrizioni, o sposta il link altrove.
     *
     * Lo stesso pulsante fa entrambe le cose: il caso «rigenera» è quello che
     * conta davvero — un link scappato nel gruppo sbagliato si revoca solo
     * facendone uno nuovo, e il vecchio indirizzo muore all'istante.
     */
    public function apriInviti(Request $request): RedirectResponse
    {
        $request->user()->league->apriIscrizioni();

        return back()->with('successo', 'Iscrizioni aperte. Il link qui sotto vale come una password: dallo solo a chi deve giocare.');
    }

    public function chiudiInviti(Request $request): RedirectResponse
    {
        $request->user()->league->chiudiIscrizioni();

        return back()->with('successo', 'Iscrizioni chiuse. Chi si è già iscritto resta dentro.');
    }

    /** Una squadra per una persona vera. */
    public function store(Request $request): RedirectResponse
    {
        $league = $request->user()->league;

        $dati = $request->validate([
            'name' => ['required', 'string', 'max:60', new TestoValido],
            'coach_name' => ['nullable', 'string', 'max:60', new TestoValido],
            'email' => ['required', 'email', 'max:120', Rule::unique('managers', 'email')],
            'password' => ['required', 'string', 'min:6', 'max:60'],
            'auto_draft' => ['nullable', 'boolean'],
        ]);

        Manager::create([
            'league_id' => $league->id,
            'name' => $dati['name'],
            'coach_name' => $dati['coach_name'] ?? null,
            'email' => Str::lower($dati['email']),
            'password' => $dati['password'],
            'auto_draft' => $request->boolean('auto_draft'),
        ]);

        return back()->with('successo', "«{$dati['name']}» può entrare con {$dati['email']}.");
    }

    /**
     * Riempie i posti vuoti con squadre governate dal sistema.
     *
     * Un bot sbusta appena tocca a lui e schiera sempre la miglior formazione
     * possibile, quindi non prende la penalità da dimenticanza: quella punisce
     * chi si scorda, e un bot non si scorda. Va saputo leggendo la classifica —
     * è un avversario un filo più ostico della media di una lega di amici.
     */
    public function bot(Request $request): RedirectResponse
    {
        $league = $request->user()->league;

        $dati = $request->validate([
            'quanti' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $esistenti = $league->managers()->where('is_bot', true)->count();
        $creati = 0;

        foreach (range(1, (int) $dati['quanti']) as $i) {
            $n = $esistenti + $i;
            $nome = self::NOMI_BOT[($n - 1) % count(self::NOMI_BOT)];

            // Il nome si ripete solo oltre la dodicesima: a quel punto il
            // numero lo disambigua, invece di far fallire l'inserimento.
            if ($n > count(self::NOMI_BOT)) {
                $nome .= ' '.$n;
            }

            Manager::create([
                'league_id' => $league->id,
                'name' => $nome,
                'coach_name' => 'Pilota automatico',
                'email' => "bot{$n}.".Str::slug($league->name).'@fantasbusta.local',
                'password' => Str::random(32),   // nessuno ci entrerà mai
                'auto_draft' => true,
                'is_bot' => true,
            ] + $this->guardaroba($n));

            $creati++;
        }

        return back()->with('successo', "{$creati} bot aggiunti. Sbustano da soli e schierano sempre.");
    }

    /**
     * Maglia, stemma e sponsor per un bot appena nato.
     *
     * Un bot senza identità visiva è una riga grigia in mezzo a undici squadre
     * vestite, e in classifica si legge peggio delle altre proprio mentre gioca
     * come le altre. Vestirlo non è decorazione: è quello che lo rende un
     * avversario invece di un segnaposto.
     *
     * La palette si prende a rotazione e non a caso puro: su dodici bot il caso
     * puro produce due o tre coppie identiche — è il paradosso dei compleanni —
     * e due nerazzurre di fila sono esattamente ciò che si voleva evitare.
     * Il resto sì, a sorte: lì le collisioni non danno fastidio.
     *
     * @return array<string,mixed>
     */
    private function guardaroba(int $n): array
    {
        $palette = array_keys(config('squadra.palette'));
        $chiave = $palette[($n - 1) % count($palette)];

        $sponsor = collect(config('squadra.sponsor_modelli'))->random();

        return [
            'jersey' => [
                'colori' => config("squadra.palette.{$chiave}.colori"),
                'stile' => collect(array_keys(config('squadra.stili_maglia')))->random(),
            ],
            'crest' => [
                'colori' => config("squadra.palette.{$chiave}.colori"),
                'forma' => collect(array_keys(config('squadra.forme_stemma')))->random(),
                // «nessuno» resta fuori: un bot senza simbolo si confonde con
                // chi non ha ancora scelto, e qui la scelta l'abbiamo fatta noi.
                'simbolo' => collect(array_keys(config('squadra.simboli')))
                    ->reject(fn (string $s) => $s === 'nessuno')
                    ->random(),
            ],
            'sponsor' => [
                'testo' => $sponsor['testo'],
                'stile' => $sponsor['stile'],
                'posizione' => 'centro',
                'riquadro' => false,
                'colore' => null,
                'colore_riquadro' => null,
            ],
        ];
    }

    public function update(Request $request, Manager $manager): RedirectResponse
    {
        $this->assertSua($request, $manager);

        $dati = $request->validate([
            'name' => ['required', 'string', 'max:60', new TestoValido],
            'password' => ['nullable', 'string', 'min:6', 'max:60'],
            'auto_draft' => ['nullable', 'boolean'],
            'is_admin' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        // L'ultimo amministratore non si può degradare: resterebbe un gruppo
        // che nessuno può più gestire, e non c'è nessuna schermata per uscirne.
        $restaSenzaAdmin = $manager->is_admin
            && ! $request->boolean('is_admin')
            && $manager->league->managers()->where('is_admin', true)->count() === 1;

        if ($restaSenzaAdmin) {
            return back()->withErrors(['squadra' => 'È l\'unico amministratore: promuovine un altro prima.']);
        }

        $manager->update(array_filter([
            'name' => $dati['name'],
            'password' => $dati['password'] ?? null,
        ]) + [
            'auto_draft' => $request->boolean('auto_draft'),
            'is_admin' => $request->boolean('is_admin'),
            'active' => $request->boolean('active'),
        ]);

        return back()->with('successo', "«{$manager->name}» aggiornata.");
    }

    /**
     * Toglie una squadra dal giro.
     *
     * Si cancella solo se non ha mai giocato: altrimenti si disattiva, perché
     * le sue carte e i suoi risultati devono restare leggibili — una classifica
     * con un buco al posto di un avversario non si capisce più.
     */
    public function destroy(Request $request, Manager $manager): RedirectResponse
    {
        $this->assertSua($request, $manager);

        abort_if($manager->id === $request->user()->id, 403, 'Non puoi cancellare la tua squadra.');

        if ($manager->cards()->exists() || $manager->lineups()->exists()) {
            $manager->update(['active' => false]);

            return back()->with('successo', "«{$manager->name}» disattivata: ha già giocato, quindi resta negli archivi.");
        }

        $nome = $manager->name;
        $manager->delete();

        return back()->with('successo', "«{$nome}» cancellata.");
    }

    private function assertSua(Request $request, Manager $manager): void
    {
        abort_unless($manager->league_id === $request->user()->league_id, 404);
    }
}
