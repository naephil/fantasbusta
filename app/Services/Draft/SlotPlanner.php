<?php

namespace App\Services\Draft;

use App\Enums\Role;

/**
 * Riempimento a deficit: decide QUALI ruoli devono essere forzati in una busta.
 *
 * Le buste sono casuali finché la garanzia non rischia di saltare. Solo quando
 * gli slot ancora da pescare bastano appena a coprire i ruoli mancanti il
 * generatore comincia a forzare — così nella grande maggioranza dei casi tutte
 * e cinque le buste restano pura fortuna, che è il punto del gioco.
 *
 * Logica pura, senza database: è la parte che vale la pena testare da sola.
 */
final class SlotPlanner
{
    /**
     * @param  array<string,int>  $minimums  garanzia di ruolo sull'intera rosa
     */
    public function __construct(
        private readonly array $minimums,
        private readonly int $packSize,
        private readonly int $totalPacks,
    ) {}

    /**
     * Ruoli imposti agli slot della busta corrente.
     *
     * @param  array<string,int>  $owned  quante carte già possedute per ruolo
     * @param  int  $packIndex  1-based
     * @return list<string|null> un elemento per slot: ruolo forzato, o null se libero
     */
    public function plan(array $owned, int $packIndex): array
    {
        $have = $owned + Role::tally();
        $slotsLeft = ($this->totalPacks - $packIndex + 1) * $this->packSize;
        $slots = [];

        for ($i = 0; $i < $this->packSize; $i++) {
            $deficit = $this->deficit($have);
            $missing = array_sum($deficit);

            // Finché restano più slot che ruoli mancanti, lo slot resta libero.
            // Quando i due numeri si pareggiano ogni slot successivo è vincolato.
            if ($missing > 0 && $missing >= $slotsLeft) {
                arsort($deficit);
                $role = (string) array_key_first($deficit);
                $slots[] = $role;
                $have[$role]++;
            } else {
                $slots[] = null;
            }

            $slotsLeft--;
        }

        return $slots;
    }

    /**
     * Quante carte mancano per ruolo al raggiungimento della garanzia.
     *
     * @param  array<string,int>  $have
     * @return array<string,int>
     */
    public function deficit(array $have): array
    {
        $out = [];
        foreach ($this->minimums as $role => $min) {
            $out[$role] = max(0, $min - ($have[$role] ?? 0));
        }

        return $out;
    }

    /**
     * Un ruolo libero viene pescato comunque, ma non deve sabotare la garanzia:
     * se prendere questo ruolo renderebbe impossibile coprire i mancanti,
     * l'estrazione va ristretta. Usato dal PackGenerator sugli slot liberi.
     *
     * @param  array<string,int>  $have
     * @return list<string> ruoli ammessi per uno slot libero
     */
    public function allowedForFreeSlot(array $have, int $slotsLeftAfterThis): array
    {
        $all = array_keys(Role::tally());
        $missing = array_sum($this->deficit($have));

        // Il caso «nessun debito» va tenuto separato e non è pignoleria: sull'
        // ultimo slot dell'ultima busta i mancanti sono zero e gli slot residui
        // pure, quindi `0 < 0` è falso e si finiva nel ramo dei ruoli in
        // deficit — che a garanzia coperta è vuoto. Con la lista vuota il
        // PackGenerator non trova nulla da pescare e salta lo slot senza
        // protestare: ogni manager chiudeva il draft con 24 carte invece di 25.
        if ($missing === 0 || $missing < $slotsLeftAfterThis) {
            return $all;
        }

        // Margine esaurito: solo ruoli ancora in deficit.
        return array_values(array_keys(array_filter($this->deficit($have))));
    }
}
