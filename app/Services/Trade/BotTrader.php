<?php

namespace App\Services\Trade;

use App\Models\Manager;
use App\Models\Trade;

/**
 * I bot rispondono alle proposte di scambio.
 *
 * Serve a collaudare il mercato. Senza, una lega mezza di bot ha un mercato
 * morto: si può proporre, e poi non succede niente per sempre — la proposta
 * resta appesa finché non scade con la giornata, e l'unico modo di provare uno
 * scambio è avere due persone vere collegate insieme.
 *
 * ⚠️ **Non è un'intelligenza di mercato, ed è deliberato.** Tira una moneta:
 * accetta circa metà delle proposte e rifiuta il resto, senza guardare cosa gli
 * stanno dando. Valutare la convenienza sarebbe un altro mestiere — vorrebbe
 * dire pesare power, ruoli e profondità di rosa — e soprattutto renderebbe i
 * bot avversari di mercato invece che sparring partner. Qui l'obiettivo è che
 * il mercato *risponda*, non che giochi bene.
 *
 * La protezione contro gli scambi rovinosi non manca comunque: `accept()`
 * ricontrolla il pavimento della rosa sotto lock e rifiuta da sé se lo
 * scambio lascerebbe qualcuno inschierabile. Un bot non può quindi accettare
 * qualcosa che lo renderebbe incapace di scendere in campo.
 */
class BotTrader
{
    public function __construct(private TradeService $trades) {}

    /**
     * Risponde a tutte le proposte in attesa indirizzate a un bot.
     *
     * @param  int|null  $leagueSeasonId  solo una stagione, o tutte se nullo
     * @return array{accettate: int, rifiutate: int}
     */
    public function rispondi(?int $leagueSeasonId = null, int $percentuale = 50): array
    {
        $proposte = Trade::pending()
            ->when($leagueSeasonId, fn ($q) => $q->where('league_season_id', $leagueSeasonId))
            ->whereIn('receiver_id', Manager::where('is_bot', true)->select('id'))
            ->get();

        $accettate = 0;
        $rifiutate = 0;

        foreach ($proposte as $proposta) {
            // La moneta si tira per proposta e non una volta per giro: un giro
            // che accetta tutto o rifiuta tutto non somiglia a un mercato.
            if (random_int(1, 100) > $percentuale) {
                $this->trades->reject($proposta);
                $rifiutate++;

                continue;
            }

            // ⚠️ `accept()` può comunque chiudere in `rejected` se lo scambio
            // violerebbe il pavimento della rosa: il conteggio si legge
            // dall'esito e non dall'intenzione, altrimenti direbbe una bugia.
            $esito = $this->trades->accept($proposta);

            $esito->state === 'accepted' ? $accettate++ : $rifiutate++;
        }

        return ['accettate' => $accettate, 'rifiutate' => $rifiutate];
    }
}
