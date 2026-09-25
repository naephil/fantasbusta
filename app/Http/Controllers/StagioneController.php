<?php

namespace App\Http\Controllers;

use App\Support\StagioneAttiva;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Il cambio di stagione, dalla barra in alto.
 *
 * Non è una funzione da amministratore: un gruppo che ha già giocato due anni
 * vuole poter riaprire la classifica dell'anno scorso senza chiedere permesso,
 * e le stagioni concluse sono in sola lettura per costruzione — non c'è niente
 * da proteggere.
 */
class StagioneController extends Controller
{
    public function __invoke(Request $request, StagioneAttiva $stagioni): RedirectResponse
    {
        $dati = $request->validate([
            'stagione' => ['required', 'integer'],
        ]);

        if (! $stagioni->scegli($request->user(), (int) $dati['stagione'])) {
            return back()->withErrors(['stagione' => 'Quella stagione non è del tuo gruppo.']);
        }

        return back();
    }
}
