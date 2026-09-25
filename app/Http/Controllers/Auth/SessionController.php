<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Accesso dei manager.
 *
 * Da qui si entra soltanto: un profilo nasce o dall'admin, che lo crea da
 * /admin/squadre, o da RegistrazioneController, che chiede il link d'invito
 * della lega. Questa pagina non ne fa mai — ed è il motivo per cui non porta
 * nessun collegamento a «iscriviti»: l'indirizzo per farlo è segreto, e
 * pubblicarlo qui vanificherebbe il gettone che lo protegge.
 */
class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credenziali = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credenziali, $request->boolean('ricordami'))) {
            // Un solo messaggio per entrambi i casi: dire «questa email non
            // esiste» significa confermare a chiunque chi è iscritto.
            throw ValidationException::withMessages([
                'email' => 'Credenziali non valide.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
