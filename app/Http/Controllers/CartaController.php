<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Services\Draft\CardPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Una carta sola, resa, per l'anteprima al passaggio del mouse.
 *
 * Le carte sono la cosa più bella del gioco e si vedono soltanto al draft: per
 * il resto della settimana i giocatori sono righe di testo in una tabella. Un
 * modo per rivederle senza tornare al draft mancava del tutto.
 *
 * ⚠️ Si serve una carta per volta e a richiesta, invece di renderle tutte nella
 * pagina. La giornata di Serie A ne elencherebbe cinquecento, la maggior parte
 * delle quali nessuno passerà mai col mouse: sarebbero cinquecento SVG e
 * cinquecento immagini in un documento che deve solo mostrare dei voti.
 */
class CartaController extends Controller
{
    public function __construct(private CardPresenter $presenter) {}

    public function show(Request $request, Card $card): Response
    {
        $card->load('leagueSeason');

        // Le carte di un altro gruppo non riguardano nessuno, e l'id è
        // indovinabile a tentativi.
        abort_unless(
            $card->leagueSeason->league_id === $request->user()->league_id,
            404,
        );

        $carta = $this->presenter
            ->present(collect([$card]), $card->leagueSeason, $card->matchday)
            ->first();

        return response(view('components.carta', [
            'carta' => $carta,
            'larghezza' => '210px',
        ])->render())
            // Una carta non cambia più: tier e ruolo sono congelati alla
            // pescata. Farla richiedere una volta sola per sessione toglie di
            // mezzo il grosso del traffico di questa funzione.
            ->header('Cache-Control', 'private, max-age=3600');
    }
}
