<?php

namespace App\Services\Draft;

use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftTurn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sbustamento e avanzamento della coda, in un solo posto.
 *
 * ⚠️ Esiste una strada sola perché le strade sono due: il manager che clicca
 * «apri» e il cron che sbusta d'ufficio possono arrivare nello stesso istante.
 * È la prima delle due corse reali di docs/DESIGN.md §7. Se il controller
 * riscrivesse la propria versione del lock, la corsa tornerebbe a esistere e si
 * manifesterebbe come una busta doppia — cioè come dieci carte a un manager e
 * un pool più povero per tutti gli altri.
 *
 * ⚠️ In locale il database è SQLite, dove `lockForUpdate()` non fa nulla e
 * Laravel non protesta: i test passano senza aver mai esercitato un lock. Le
 * prove di concorrenza vanno rifatte su MariaDB.
 */
class PackOpener
{
    public function __construct(private PackGenerator $packs) {}

    /**
     * Apre la busta del turno e fa scorrere la coda.
     *
     * @param  string  $by  'manager' se l'ha chiesto lui, 'auto' se è scaduto
     * @return Collection<int,Card>|null null se il turno era già chiuso
     */
    public function open(DraftTurn $turn, string $by): ?Collection
    {
        return DB::transaction(function () use ($turn, $by) {
            $fresh = DraftTurn::whereKey($turn->getKey())->lockForUpdate()->first();

            // Chi arriva secondo trova il turno già fatto ed esce a mani vuote.
            // Non è un errore: è esattamente ciò che il lock deve produrre.
            if (! $fresh || $fresh->state !== 'active') {
                return null;
            }

            $pack = $this->packs->generate($fresh);

            $fresh->update([
                'state' => 'done',
                'opened_at' => now(),
                'opened_by' => $by,
            ]);

            // Attivare subito il turno seguente evita che il prossimo manager
            // resti fermo fino al giro di cron successivo: cinque minuti di
            // attesa su un turno che dura venti sono un quarto del suo tempo.
            $this->activateNext($fresh->draft);

            return $pack;
        });
    }

    /**
     * Fa scorrere la coda di un posto.
     *
     * ⚠️ Va serializzata sul DRAFT, non sul turno, e la differenza è tutta qui:
     * due processi che chiamano questo metodo insieme leggono entrambi «il
     * prossimo in attesa», ma se il primo fa in tempo ad attivarlo il secondo
     * vede quello DOPO e attiva anche lui — ritrovandosi con due turni attivi
     * sullo stesso draft. Da lì in poi l'ordine dello snake è saltato e
     * `activeTurn()` restituisce uno dei due a caso.
     *
     * Non è un caso di scuola: il cron gira ogni minuto e nel frattempo
     * l'amministratore può premere «sbusta tutto», oppure un manager può aprire
     * la sua busta. Sono tre strade che arrivano qui.
     *
     * @return bool false quando non restano turni: il draft è finito.
     */
    public function activateNext(Draft $draft): bool
    {
        return DB::transaction(function () use ($draft) {
            // Il lock sulla riga del draft è il turnello che mette in fila
            // tutti quelli che vogliono far scorrere questa coda.
            Draft::whereKey($draft->getKey())->lockForUpdate()->first();

            // Riletto DENTRO il lock: chi è arrivato un istante prima può aver
            // già attivato il turno, e in quel caso non c'è niente da fare.
            if ($draft->activeTurn()) {
                return true;
            }

            $next = $draft->nextWaitingTurn();

            if (! $next) {
                return false;
            }

            $next->update([
                'state' => 'active',
                'expires_at' => now()->addSeconds($draft->turnDuration()),
            ]);

            return true;
        });
    }
}
