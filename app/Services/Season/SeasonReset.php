<?php

namespace App\Services\Season;

use App\Models\Card;
use App\Models\Draft;
use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\Matchup;
use App\Models\PlayerPower;
use App\Models\PlayerScore;
use App\Models\PlayerStat;
use App\Models\Standing;
use App\Models\Tournament;
use App\Models\Trade;
use Illuminate\Support\Facades\DB;

/**
 * Riporta una stagione al giorno zero senza cancellarla.
 *
 * Serve perché «ricomincio da capo» e «cancello e rifaccio» non sono la stessa
 * cosa. Cancellare una stagione porta via anche ciò che si era tarato prima di
 * cominciare — le regole di casa, la giornata di partenza — e rifarla da zero
 * significa reimpostare tutto a mano ogni volta che una prova va storta. Qui
 * resta in piedi l'impalcatura e se ne va soltanto ciò che è stato giocato.
 *
 * ⚠️ Si ferma al confine della lega, e il confine è quello di sempre. Le
 * statistiche di Serie A — minuti, gol, cartellini — sono dati del mondo:
 * condivisi con gli altri gruppi che rigiocano la stessa annata, e non nostri
 * da buttare. Se ne vanno solo le loro INTERPRETAZIONI, cioè fantavoti e power
 * score, che sono per stagione di lega perché i coefficienti li decide il
 * gruppo. Per liberarsi anche dei dati dell'annata c'è «Butta», che è un'altra
 * cosa e sta apposta su un altro piano della pagina.
 */
class SeasonReset
{
    /**
     * Cancella tutto il giocato e riporta la stagione in preparazione.
     *
     * @return array<string,int> quanto è stato portato via, per dirlo
     */
    public function azzera(LeagueSeason $stagione): array
    {
        $id = $stagione->id;

        // Contati PRIMA: dopo non c'è più niente da contare, e un messaggio che
        // dice soltanto «fatto» non permette di accorgersi di aver azzerato la
        // stagione sbagliata.
        $fatto = [
            'giornate' => Standing::where('league_season_id', $id)->distinct()->count('matchday'),
            'sfide' => Matchup::where('league_season_id', $id)->count(),
            'carte' => Card::where('league_season_id', $id)->count(),
            'draft' => Draft::where('league_season_id', $id)->count(),
            'tornei' => Tournament::where('league_season_id', $id)->count(),
            'formazioni' => Lineup::where('league_season_id', $id)->count(),
            'scambi' => Trade::where('league_season_id', $id)->count(),
        ];

        $fatto['statistiche_finte'] = $this->spazzaSimulate($stagione);

        DB::transaction(function () use ($id, $stagione) {
            /*
             * L'ordine non è casuale: si smonta partendo da chi PUNTA alle
             * carte, non dalle carte. Scambi e formazioni le tengono per id, e
             * toglierle da sotto significherebbe affidarsi al cascade del
             * database per una cosa che qui si può semplicemente fare nel verso
             * giusto. I figli veri — righe di scambio, slot di formazione,
             * buste, turni, iscrizioni ai tornei — se ne vanno invece da soli:
             * hanno tutti un cascade sulla chiave del padre.
             */
            Trade::where('league_season_id', $id)->delete();
            Lineup::where('league_season_id', $id)->delete();

            // Le sfide prima dei tornei: quelle di coppa appartengono al
            // torneo, e sparirebbero comunque con lui.
            Matchup::where('league_season_id', $id)->delete();
            Tournament::where('league_season_id', $id)->delete();

            Card::where('league_season_id', $id)->delete();
            Draft::where('league_season_id', $id)->delete();
            Standing::where('league_season_id', $id)->delete();

            // Voti e rarità sono interpretazioni di questa lega, non fatti
            // dell'annata: se ne vanno con lei. Senza, un ricalcolo troverebbe
            // il power di una stagione che non è più stata giocata.
            PlayerScore::where('league_season_id', $id)->delete();
            PlayerPower::where('league_season_id', $id)->delete();

            // Il calendario se n'è andato con le sfide, quindi si torna al
            // punto in cui va rigenerato. Lasciarla «in corso» le farebbe
            // offrire un «gioca la 1ª» che non ha più niente contro cui
            // giocare.
            $stagione->update(['state' => 'preparazione']);
        });

        return $fatto;
    }

    /**
     * Le statistiche INVENTATE dell'annata, che sopravvivevano all'azzeramento.
     *
     * ⚠️ È la ragione per cui dopo un reset la pagina «Serie A» restava ferma
     * alla giornata dove ci si era arrivati prima: quell'elenco mostra le
     * giornate che hanno almeno una statistica, e le righe finte erano ancora
     * tutte lì. La stagione ripartiva davvero da capo — classifica e carte se
     * n'erano andate — ma il campionato sembrava a metà, e ricominciare a
     * simulare da una giornata già «giocata» era un rebus.
     *
     * Le statistiche VERE non si toccano mai: sono dati del mondo, costano
     * chiamate all'API e le usano anche gli altri gruppi. Le finte non valgono
     * niente per nessuno.
     *
     * ⚠️ Ma anche le finte sono condivise — `player_stats` porta l'annata, non
     * la lega — quindi si buttano solo se questa era l'ultima stagione a
     * giocare quell'anno. Un altro gruppo nel mezzo di una prova sullo stesso
     * 2023 si ritroverebbe altrimenti le giornate svuotate sotto i piedi.
     *
     * @return int righe finte rimosse
     */
    private function spazzaSimulate(LeagueSeason $stagione): int
    {
        $altri = LeagueSeason::where('season', $stagione->season)
            ->whereKeyNot($stagione->id)
            ->exists();

        if ($altri) {
            return 0;
        }

        return PlayerStat::where('season', $stagione->season)
            ->where('source', 'simulata')
            ->delete();
    }
}
