<?php

namespace App\Services\Tournament\Formats;

use App\Models\Tournament;

/**
 * Il contratto di un formato di torneo.
 *
 * Aggiungerne uno costa una classe e una riga in `config/tornei.php`: nessuna
 * migration, nessun `match` da aggiornare in giro. È il motivo per cui il
 * formato è una stringa sul database e non un enum.
 *
 * L'astrazione NON presuppone che si giochino partite. Il royal rumble non ne
 * ha nessuna — elimina chi ha totalizzato meno — e deve stare comodo qui dentro
 * quanto un tabellone a eliminazione diretta.
 */
interface FormatoTorneo
{
    /** Come si chiama, in interfaccia. */
    public function nome(): string;

    /** Una riga per spiegarlo a chi sta creando il torneo. */
    public function descrizione(): string;

    /**
     * Quanti partecipanti accetta.
     *
     * @return array{0:int,1:int} minimo e massimo
     */
    public function partecipanti(): array;

    /**
     * Quante giornate occupa.
     *
     * @param  array<string,mixed>  $settings
     */
    public function durata(int $partecipanti, array $settings): int;

    /**
     * Prepara la struttura iniziale: gironi, primo turno, teste di serie.
     *
     * Alcuni formati sanno già tutto adesso — un girone all'italiana genera
     * l'intero calendario — altri no: un tabellone conosce solo il primo turno,
     * perché il secondo dipende da chi vince.
     */
    public function avvia(Tournament $torneo): void;

    /**
     * Chiude una giornata: assegna esiti, elimina, genera il turno seguente.
     *
     * Viene chiamato dopo che i punteggi della giornata esistono, mai prima.
     */
    public function avanza(Tournament $torneo, int $matchday): void;

    /**
     * Lo stato attuale, pronto per la vista.
     *
     * @return array<string,mixed>
     */
    public function stato(Tournament $torneo): array;
}
