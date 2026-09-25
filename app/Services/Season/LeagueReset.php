<?php

namespace App\Services\Season;

use App\Models\League;
use App\Models\LeagueSeason;
use App\Models\Manager;
use Illuminate\Support\Facades\DB;

/**
 * Azzeramenti al livello del GRUPPO, sopra la singola stagione.
 *
 * Ce ne sono tre di taglia diversa e non sono intercambiabili — è il punto per
 * cui esistono pulsanti separati invece di uno solo che chiede conferma:
 *
 *   `SeasonReset`      una stagione torna al giorno zero, ma resta lei, con le
 *                      sue regole e la sua giornata di partenza.
 *   `tutto()`          via ogni stagione del gruppo. Restano il gruppo, le
 *                      squadre iscritte e le loro identità — maglie, stemmi,
 *                      nomi — che sono la cosa che nessuno vuole rifare.
 *   `iscritti()`       via anche le squadre. È l'unico che tocca le persone, e
 *                      per questo non lo si trova mai per sbaglio dentro un
 *                      altro pulsante.
 *
 * ⚠️ Le annate di Serie A non le tocca nessuno dei tre. Sono dati del mondo:
 * costano chiamate all'API e le usano gli altri gruppi. Per quelle c'è «Butta»,
 * che sta su un altro piano della pagina apposta.
 */
class LeagueReset
{
    public function __construct(private SeasonReset $stagioni) {}

    /**
     * Via tutte le stagioni del gruppo. Le squadre restano.
     *
     * Ogni stagione viene prima azzerata e poi cancellata, invece di lasciar
     * fare tutto al cascade del database. Non è pignoleria: l'azzeramento è
     * anche il punto in cui si buttano le statistiche simulate dell'annata, e
     * quella pulizia il cascade non la conosce — sono righe di un'altra
     * tabella, agganciate all'anno e non alla lega.
     *
     * @return array<string,int> quanto è stato portato via, per dirlo
     */
    public function tutto(League $league): array
    {
        $stagioni = LeagueSeason::where('league_id', $league->id)->get();

        $fatto = ['stagioni' => $stagioni->count(), 'statistiche_finte' => 0];

        foreach ($stagioni as $stagione) {
            $esito = $this->stagioni->azzera($stagione);

            $fatto['statistiche_finte'] += $esito['statistiche_finte'];

            foreach (['sfide', 'carte', 'draft', 'tornei', 'formazioni', 'scambi'] as $voce) {
                $fatto[$voce] = ($fatto[$voce] ?? 0) + $esito[$voce];
            }

            $stagione->delete();
        }

        // Anche le regole di casa: «tutto» vuol dire tornare al foglio bianco,
        // e una taratura sopravvissuta a un azzeramento totale è una sorpresa
        // che si scopre tre giornate dopo, quando i conti non tornano.
        $league->update(['settings' => null]);

        return $fatto;
    }

    /**
     * Via le squadre iscritte, tranne chi sta premendo il pulsante.
     *
     * ⚠️ L'eccezione non è una cortesia: è l'unica cosa che tiene in piedi il
     * gruppo. Cancellando anche l'admin non resterebbe nessuno con cui
     * entrare, e la lega diventerebbe irraggiungibile dalla sua stessa
     * interfaccia — recuperabile solo da riga di comando sul server.
     *
     * Tutto ciò che una squadra si porta dietro — carte, formazioni, sfide,
     * scambi, iscrizioni ai tornei — se ne va col cascade. È il motivo per cui
     * ha senso premere «azzera tutto» prima: così questo pulsante rimuove
     * persone, non partite.
     *
     * @return int squadre rimosse
     */
    public function iscritti(League $league, Manager $tranne): int
    {
        return DB::transaction(fn () => Manager::where('league_id', $league->id)
            ->whereKeyNot($tranne->id)
            ->delete());
    }
}
