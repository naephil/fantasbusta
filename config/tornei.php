<?php

use App\Services\Tournament\Formats\EliminazioneDiretta;
use App\Services\Tournament\Formats\Girone;
use App\Services\Tournament\Formats\GironiPiuEliminazione;
use App\Services\Tournament\Formats\RoyalRumble;

/**
 * Il registro dei formati di torneo.
 *
 * Aggiungerne uno costa due cose e basta: una classe che implementa
 * `FormatoTorneo`, e una riga qui sotto. Nessuna migration, nessun `match` da
 * inseguire nel codice — la chiave finisce così com'è nella colonna `format`.
 *
 * Le chiavi sono dati salvati: se ne aggiungono, non se ne rinominano.
 */
return [
    'formati' => [
        'girone' => Girone::class,
        'eliminazione' => EliminazioneDiretta::class,
        'gironi_eliminazione' => GironiPiuEliminazione::class,
        'royal_rumble' => RoyalRumble::class,
    ],

    /*
    | I parametri che ciascun formato accetta, per costruire il modulo di
    | creazione senza doverlo scrivere a mano ogni volta.
    */
    'parametri' => [
        'girone' => [
            'gironi' => ['etichetta' => 'Quante volte tutti contro tutti', 'min' => 1, 'max' => 4, 'default' => 1],
        ],
        'eliminazione' => [],
        'gironi_eliminazione' => [
            'gruppi' => ['etichetta' => 'Quanti gruppi', 'min' => 2, 'max' => 8, 'default' => 2],
            'qualificate' => ['etichetta' => 'Quante passano per gruppo', 'min' => 1, 'max' => 4, 'default' => 2],
        ],
        'royal_rumble' => [
            'eliminati_per_giornata' => ['etichetta' => 'Quanti escono a giornata', 'min' => 1, 'max' => 4, 'default' => 1],
        ],
    ],
];
