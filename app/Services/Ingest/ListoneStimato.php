<?php

namespace App\Services\Ingest;

use App\Models\PlayerSeason;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Deduce le quotazioni dalle statistiche, quando il listone vero non c'è.
 *
 * Senza listone il sync lascia tutti a quotazione 1, e la conseguenza non è
 * ovvia finché non la si guarda: alla prima giornata di una stagione ricaricata
 * nessuno ha ancora giocato, quindi anche fantamedia, forma e titolarità sono
 * a zero. Il power è identico per tutti e la classifica la decide lo spareggio
 * sull'id — la piramide dei tier resta giusta nei numeri, ma *chi* è Leggendaria
 * è sorteggiato. Le prime due o tre giornate diventano una lotteria pura.
 *
 * La quotazione è l'unica componente che esiste prima che si giochi, ed è
 * esattamente il mestiere che fa nel listone vero: dare un ordine di partenza.
 *
 * ⚠️ **Da quale annata si prendono i dati cambia il gioco.**
 *
 *  - L'anno PRECEDENTE è ciò che fa il listone vero: le quotazioni si scrivono
 *    prima che la stagione cominci, guardando com'è andata quella prima. Il
 *    limite è la copertura — chi ha cambiato squadra fra i due anni non compare
 *    nella rosa vecchia e resta al minimo.
 *  - Lo STESSO anno che si sta per giocare copre tutti, ma è preveggenza: alla
 *    prima giornata si saprebbe già chi chiuderà con ventiquattro gol. Non è
 *    solo irrealistico, cambia che gioco è — il draft diventa una fila ordinata
 *    e la caccia al giocatore in forma, che è il motivo per cui il power esiste,
 *    sparisce.
 *
 * Chi chiama sceglie, e la pagina lo dice a chiare lettere.
 */
class ListoneStimato
{
    /**
     * Fin dove può arrivare la quotazione, per ruolo.
     *
     * Le fasce ricalcano quelle del listone vero: un portiere non costa come un
     * attaccante, e appiattirle renderebbe il draft cieco alla differenza fra
     * reparti — che è metà del gioco, visto che la busta ha una garanzia di
     * composizione.
     */
    private const FASCE = ['P' => 16.0, 'D' => 20.0, 'C' => 35.0, 'A' => 45.0];

    /** Il rating sotto il quale una prestazione non dice più niente. */
    private const PAVIMENTO_RATING = 5.5;

    /** Le presenze oltre le quali si è comunque un titolare fisso. */
    private const PRESENZE_PIENE = 30;

    public function __construct(private ApiFootball $api) {}

    /**
     * Scrive le quotazioni stimate sul listone di un'annata.
     *
     * Non tocca chi ha `role_confirmed`: quella è una decisione umana, e una
     * stima non ha titolo per sovrascriverla. Vale sia per chi è passato dal
     * listone ufficiale sia per chi è stato sistemato a mano nella pagina di
     * verifica.
     *
     * @param  int  $annoListone  l'annata da quotare
     * @param  int  $annoDati  da dove prendere le statistiche
     * @return array{quotati: int, senzaDati: int, chiamate: int}
     */
    public function stima(int $annoListone, int $annoDati): array
    {
        $listone = PlayerSeason::where('season', $annoListone)
            ->where('active', true)
            ->where('role_confirmed', false)
            ->get();

        if ($listone->isEmpty()) {
            return ['quotati' => 0, 'senzaDati' => 0, 'chiamate' => 0];
        }

        [$rendimenti, $chiamate] = $this->rendimenti(
            $listone->pluck('team_id')->unique()->filter()->all(),
            $annoDati,
        );

        $quotazioni = $this->quotaPerRuolo($listone, $rendimenti);

        $quotati = 0;

        foreach ($listone as $riga) {
            $riga->update(['quotazione_iniziale' => $quotazioni[$riga->player_id] ?? 1.0]);

            if (isset($rendimenti[$riga->player_id])) {
                $quotati++;
            }
        }

        return [
            'quotati' => $quotati,
            'senzaDati' => $listone->count() - $quotati,
            'chiamate' => $chiamate,
        ];
    }

    /**
     * Il rendimento stagionale di ogni giocatore, squadra per squadra.
     *
     * ⚠️ Si interroga per SQUADRA e non per campionato: il piano gratuito
     * blocca `/players` oltre la terza pagina, e la Serie A intera ne occupa
     * cinquantaquattro — se ne importerebbe un ventesimo senza accorgersene.
     * Per squadra le pagine sono tre scarse, quindi ci si sta dentro, al prezzo
     * di una chiamata in più a testa.
     *
     * @param  list<int>  $squadre
     * @return array{array<int,array<string,float>>, int}
     */
    private function rendimenti(array $squadre, int $anno): array
    {
        $per = [];
        $chiamate = 0;

        foreach ($squadre as $teamId) {
            // Tre pagine, cioè sessanta giocatori: più di qualunque rosa di
            // Serie A, e sul piano gratuito è anche il tetto oltre il quale la
            // richiesta non torna vuota ma con un errore che ferma tutto.
            $righe = $this->api->getPaged('/players', ['team' => $teamId, 'season' => $anno], maxPages: 3);
            $chiamate += 3;

            foreach ($righe as $riga) {
                $id = (int) Arr::get($riga, 'player.id', 0);
                $stat = Arr::get($riga, 'statistics.0', []);
                $presenze = (int) (Arr::get($stat, 'games.appearences') ?? 0);

                // Chi non è mai sceso in campo non ha un rendimento: resterà al
                // minimo, che è esattamente quanto vale nel listone vero.
                if ($id === 0 || $presenze === 0) {
                    continue;
                }

                $per[$id] = [
                    'presenze' => (float) $presenze,
                    'rating' => (float) (Arr::get($stat, 'games.rating') ?? 0),
                    'gol' => (float) (Arr::get($stat, 'goals.total') ?? 0),
                    'assist' => (float) (Arr::get($stat, 'goals.assists') ?? 0),
                ];
            }
        }

        return [$per, $chiamate];
    }

    /**
     * Da rendimento a quotazione, dentro il proprio ruolo.
     *
     * Si passa per la POSIZIONE in classifica e non per il valore grezzo: le
     * scale grezze non sono confrontabili fra reparti — un attaccante accumula
     * gol, un difensore no — e mapparle direttamente schiaccerebbe interi
     * reparti sul minimo. Il rango invece dà sempre una distribuzione piena
     * dentro la fascia del ruolo, che è come si legge un listone vero.
     *
     * @param  Collection<int,PlayerSeason>  $listone
     * @param  array<int,array<string,float>>  $rendimenti
     * @return array<int,float>
     */
    private function quotaPerRuolo(Collection $listone, array $rendimenti): array
    {
        $quotazioni = [];

        foreach ($listone->groupBy(fn (PlayerSeason $r) => $r->role->value) as $ruolo => $righe) {
            $tetto = self::FASCE[$ruolo] ?? 20.0;

            $classifica = $righe
                ->filter(fn (PlayerSeason $r) => isset($rendimenti[$r->player_id]))
                ->map(fn (PlayerSeason $r) => [
                    'id' => $r->player_id,
                    'punteggio' => $this->punteggio($rendimenti[$r->player_id]),
                ])
                // A parità decide l'id: senza un criterio secondario la stima
                // ballerebbe a ogni rilancio senza che nulla sia cambiato.
                ->sortBy([['punteggio', 'desc'], ['id', 'asc']])
                ->values();

            $totale = $classifica->count();

            foreach ($classifica as $i => $voce) {
                // Dal tetto del ruolo giù fino a 1, linearmente sul rango.
                $quota = $totale > 1 ? 1 - ($i / ($totale - 1)) : 1;

                $quotazioni[$voce['id']] = round(1 + ($tetto - 1) * $quota, 1);
            }
        }

        return $quotazioni;
    }

    /**
     * Quanto è valso un giocatore in una stagione.
     *
     * Produzione e qualità si sommano; la disponibilità MOLTIPLICA. È la
     * differenza che conta in un gioco dove la rosa si schiera ogni settimana:
     * un fuoriclasse che ha giocato quattro partite non vale quasi niente, e
     * sommare la presenza invece di moltiplicarla lo terrebbe artificialmente
     * in alto.
     *
     * @param  array<string,float>  $r
     */
    private function punteggio(array $r): float
    {
        $qualita = max(0.0, $r['rating'] - self::PAVIMENTO_RATING);
        $produzione = $r['gol'] * 3 + $r['assist'] * 1.5;
        $disponibilita = min(1.0, $r['presenze'] / self::PRESENZE_PIENE);

        return ($qualita * 2.5 + $produzione) * (0.4 + 0.6 * $disponibilita);
    }
}
