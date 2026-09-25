<?php

namespace App\Http\Controllers;

use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Support\StagioneAttiva;
use Symfony\Component\HttpKernel\Exception\HttpException;

abstract class Controller
{
    /**
     * La stagione che l'utente sta guardando.
     *
     * Quasi ogni schermata ne ha bisogno e nessuna può tirare a indovinare:
     * carte, formazioni, classifiche e sfide sono tutte figlie di una stagione
     * precisa, e sbagliarla significa mostrare la rosa dell'anno scorso senza
     * che niente lo segnali.
     */
    protected function stagione(Manager $manager): ?LeagueSeason
    {
        return app(StagioneAttiva::class)->per($manager);
    }

    /**
     * Toglie il guinzaglio al tempo di esecuzione.
     *
     * Alcune azioni di amministrazione sono lunghe per natura e non c'è modo di
     * renderle istantanee: scaricare un'annata sono sessanta chiamate distanziate
     * di sei secondi e mezzo, e giocare una giornata muove qualche migliaio di
     * righe. Sull'hosting condiviso il limite di default è trenta secondi, e
     * scadere a metà lascia il lavoro fatto per metà — una giornata coi voti di
     * sei partite su dieci, che è peggio di una giornata intonsa.
     *
     * ⚠️ Non è un permesso di essere lenti: è la rete sotto a operazioni che si
     * fanno una volta e di cui l'utente sa già che devono attendere, perché la
     * pagina glielo dice prima di cominciare.
     */
    protected function senzaFretta(int $secondi = 900): void
    {
        // In alcune configurazioni la funzione è disabilitata: in quel caso non
        // c'è niente da fare se non sperare che il limite sia già generoso.
        if (function_exists('set_time_limit')) {
            @set_time_limit($secondi);
        }

        @ini_set('memory_limit', '512M');
    }

    /**
     * Come sopra, ma per le schermate che senza stagione non hanno senso.
     *
     * @throws HttpException
     */
    protected function stagioneOAbort(Manager $manager): LeagueSeason
    {
        return $this->stagione($manager) ?? abort(
            404,
            'Nessuna stagione aperta: chiedi all\'amministratore di crearne una.',
        );
    }
}
