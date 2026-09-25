<?php

namespace App\Enums;

/**
 * La rarità di una carta.
 *
 * Sei fasce, ma di due specie diverse, e la distinzione è il cuore di questo
 * file: quattro si assegnano per PERCENTILE dentro il ruolo, due per SOGLIA
 * ASSOLUTA sul power score.
 *
 * ⚠️ Le due soglie non sono un vezzo, sono un rimedio. Il listone vero ha
 * centinaia di giocatori a quotazione 1 che non hanno mai giocato: terzi
 * portieri, primavera, ceduti a gennaio. Con la sola piramide a percentili
 * quella massa entrava nel conto e lo deformava — occupava tutta la fascia
 * Comune e spingeva in alto tutti gli altri, così che «la maggior parte delle
 * carte è Rara» diventava vero senza che nessuna di quelle carte fosse
 * diventata più forte. La piramide misurava una popolazione diversa da quella
 * che gioca.
 *
 * Sotto le soglie si sta a prescindere dalla posizione: una carta da power 1
 * è Monnezza anche se nel suo ruolo è la meno peggio.
 */
enum Tier: string
{
    case Monnezza = 'monnezza';
    case Pacco = 'pacco';
    case Comune = 'comune';
    case Rara = 'rara';
    case Epica = 'epica';
    case Leggendaria = 'leggendaria';

    /** Ordine di pregio. Serve a ordinare la busta e a scegliere la carta clou. */
    public function rank(): int
    {
        return match ($this) {
            self::Monnezza => -2,
            self::Pacco => -1,
            self::Comune => 0,
            self::Rara => 1,
            self::Epica => 2,
            self::Leggendaria => 3,
        };
    }

    public function color(): string
    {
        return match ($this) {
            // Fango e ruggine: si distinguono a colpo d'occhio dal grigio
            // argenteo delle Comuni, che sono carte oneste e non scarti.
            self::Monnezza => '#4a4640',
            self::Pacco => '#7a6a4f',
            self::Comune => '#8a929e',
            self::Rara => '#2ec4f1',
            self::Epica => '#f0157a',
            self::Leggendaria => '#ffb703',
        };
    }

    /** Come si chiama in interfaccia, quando serve scriverlo per esteso. */
    public function etichetta(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Quota della piramide, per percentile sul power score.
     *
     * ⚠️ Le quote si applicano SOLO a chi sta sopra le soglie di fondo: sono
     * percentuali di chi è davvero in gioco, non del listone intero. È la
     * correzione che rende di nuovo vero il «55% Comuni» — contato su tutti,
     * comprese le centinaia di carte da power 1, quella fascia si riempiva di
     * gente che non scende mai in campo e le Comuni vere sparivano.
     *
     * @return array<string,float> tier => quota, dal più pregiato
     */
    public static function distribution(): array
    {
        return [
            self::Leggendaria->value => 0.03,
            self::Epica->value => 0.12,
            self::Rara->value => 0.30,
            self::Comune->value => 0.55,
        ];
    }

    /**
     * Tutte le fasce dalla più pregiata alla peggiore.
     *
     * Serve alla cascata dei drop rate, che assegna il budget di slot partendo
     * dall'alto: senza le due di fondo in coda, le carte sotto soglia non
     * avrebbero nessun peso di estrazione e non uscirebbero mai dalle buste —
     * pur essendo la fetta più grossa del pool.
     *
     * @return list<string>
     */
    public static function dallAltoInBasso(): array
    {
        return [
            self::Leggendaria->value,
            self::Epica->value,
            self::Rara->value,
            self::Comune->value,
            self::Pacco->value,
            self::Monnezza->value,
        ];
    }

    /**
     * La fascia di fondo che spetta a questo power, se ce n'è una.
     *
     * `null` significa «sta sopra le soglie»: la sua rarità la decide la
     * posizione nel ruolo, non il valore assoluto.
     */
    public static function sottoSoglia(float $power, float $pacco, float $monnezza): ?self
    {
        if ($power < $monnezza) {
            return self::Monnezza;
        }

        return $power < $pacco ? self::Pacco : null;
    }
}
