<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Cambiarsi la password da soli.
 *
 * Senza questo, l'unica strada era chiederlo all'amministratore — che quindi
 * conoscerebbe per sempre la password di tutti, essendo lui a inventarla al
 * momento dell'invito. In una lega di amici non è un dramma, ma è una brutta
 * abitudine che non costa niente togliere: chi entra la cambia e da lì in poi
 * la sa solo lui.
 */
class PasswordController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'attuale' => ['required', 'string'],
            'nuova' => ['required', 'string', 'min:8', 'max:60', 'confirmed'],
        ], [], [
            'attuale' => 'la password attuale',
            'nuova' => 'la nuova password',
        ]);

        // ⚠️ Si chiede quella attuale anche se l'utente è già collegato: senza,
        // chiunque trovasse una sessione aperta — un portatile lasciato lì —
        // potrebbe prendersi l'accesso in modo permanente.
        if (! Hash::check($dati['attuale'], $request->user()->password)) {
            return back()->withErrors(['attuale' => 'La password attuale non è quella.']);
        }

        $request->user()->update(['password' => $dati['nuova']]);

        // La sessione corrente resta valida, le altre no: se qualcuno era
        // entrato con la vecchia password, da qui in poi non ci entra più.
        $request->session()->regenerate();

        return back()->with('successo', 'Password cambiata.');
    }
}
