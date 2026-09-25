<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\League;
use App\Models\Manager;
use App\Rules\TestoValido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Iscriversi da sé, con l'indirizzo giusto in mano.
 *
 * Serve a far entrare in fretta un gruppo di volontari: l'admin apre le
 * iscrizioni da /admin/squadre, incolla il link nella chat, e ognuno si crea la
 * squadra con la password che vuole. Senza, ogni persona costa all'admin una
 * riga a mano più una password da inventare e da recapitare a voce.
 *
 * ⚠️ Chi arriva qui col link entra **subito**: nessuna verifica dell'email,
 * nessuna approvazione, e da quel momento vede draft, mercato e rose di tutti.
 * L'unica cosa che separa la lega da Internet è che il gettone nell'indirizzo
 * non si indovina — quindi il link vale quanto una password condivisa, e
 * quando i volontari sono dentro le iscrizioni si chiudono.
 *
 * Il gettone dice anche IN QUALE lega si entra, ed è il motivo per cui sta
 * nell'URL invece che in configurazione: su un sito con più gruppi non esiste
 * una risposta sensata alla domanda «in quale lega finisce chi si iscrive», e
 * farla scegliere da una tendina significherebbe pubblicare l'elenco dei gruppi
 * a chiunque passi di lì.
 */
class RegistrazioneController extends Controller
{
    public function create(string $gettone): View
    {
        $league = $this->lega($gettone);

        return view('auth.registra', ['league' => $league, 'gettone' => $gettone]);
    }

    public function store(Request $request, string $gettone): RedirectResponse
    {
        // Ricontrollato qui e non solo all'apertura del modulo: fra il momento
        // in cui la pagina si è aperta e l'invio l'admin può aver chiuso le
        // iscrizioni, e una pagina lasciata aperta in una scheda non deve
        // continuare a funzionare dopo che il link è stato revocato.
        $league = $this->lega($gettone);

        $dati = $request->validate([
            'name' => ['required', 'string', 'max:60', new TestoValido],
            'coach_name' => ['nullable', 'string', 'max:60', new TestoValido],
            // L'unicità è su tutta la tabella, non sul gruppo: l'email è
            // l'identificativo con cui si entra, e due squadre con la stessa in
            // leghe diverse renderebbero l'accesso ambiguo.
            'email' => ['required', 'email', 'max:120', Rule::unique('managers', 'email')],
            // `min:8` come nel cambio password, non `min:6` come nella creazione
            // dell'admin: lì la password è provvisoria e si cambia all'ingresso,
            // qui è già quella definitiva della persona.
            'password' => ['required', 'string', 'min:8', 'max:60'],
        ], [], [
            'name' => 'il nome della squadra',
            'coach_name' => 'il nome dell\'allenatore',
            'email' => 'l\'email',
            'password' => 'la password',
        ]);

        $manager = Manager::create([
            'league_id' => $league->id,
            'name' => $dati['name'],
            'coach_name' => $dati['coach_name'] ?? null,
            'email' => Str::lower($dati['email']),
            'password' => $dati['password'],
            // Mai amministratore e mai bot da qui: il link circola in una chat,
            // e chi ce l'ha non deve poter arrivare alla gestione del gruppo.
            'is_admin' => false,
            'is_bot' => false,
        ]);

        Auth::login($manager);

        // Come dopo l'accesso: l'identificativo di sessione con cui si è
        // arrivati da sconosciuti non deve restare valido da collegati.
        $request->session()->regenerate();

        return redirect()->route('home')
            ->with('successo', "Benvenuto in «{$league->name}». La squadra è «{$manager->name}».");
    }

    /**
     * La lega dietro al gettone, o un 404.
     *
     * 404 e non 403: un gettone sbagliato e uno revocato devono rispondere
     * identici a un gettone mai esistito, altrimenti la differenza fra le due
     * risposte diventa il modo di scoprire quali link sono validi.
     */
    private function lega(string $gettone): League
    {
        return League::where('registration_token', $gettone)->firstOr(fn () => abort(404));
    }
}
