<?php

/*
 * I messaggi di validazione, in italiano.
 *
 * Senza questo file Laravel ripiega sull'inglese, e in un'applicazione dove
 * ogni singola parola è in italiano un «The email field is required» salta
 * all'occhio come un errore di programma invece che come una correzione da
 * fare. Chi lo legge pensa che si sia rotto qualcosa.
 *
 * Sono tradotte solo le regole che l'applicazione usa davvero, più quelle che
 * un modulo può far scattare per sbaglio. Le altre restano in inglese: meglio
 * un elenco corto e vero che uno lungo e mai verificato.
 */

return [
    'accepted' => 'Devi accettare :attribute.',
    'array' => ':Attribute deve essere un elenco.',
    'between' => [
        'numeric' => ':Attribute deve stare fra :min e :max.',
        'file' => ':Attribute deve pesare fra :min e :max kilobyte.',
        'string' => ':Attribute deve essere lungo fra :min e :max caratteri.',
        'array' => ':Attribute deve contenere fra :min e :max elementi.',
    ],
    'boolean' => ':Attribute può essere solo sì o no.',
    'confirmed' => 'La conferma di :attribute non coincide.',
    'current_password' => 'La password non è corretta.',
    'date' => ':Attribute non è una data valida.',
    'email' => ':Attribute non è un indirizzo valido.',
    'file' => ':Attribute deve essere un file.',
    'image' => ':Attribute deve essere un\'immagine.',
    'in' => ':Attribute non è fra i valori ammessi.',
    'integer' => ':Attribute deve essere un numero intero.',
    'max' => [
        'numeric' => ':Attribute non può superare :max.',
        'file' => ':Attribute non può superare :max kilobyte.',
        'string' => ':Attribute non può superare :max caratteri.',
        'array' => ':Attribute non può contenere più di :max elementi.',
    ],
    'mimes' => ':Attribute deve essere un file di tipo :values.',
    'min' => [
        'numeric' => ':Attribute deve essere almeno :min.',
        'file' => ':Attribute deve pesare almeno :min kilobyte.',
        'string' => ':Attribute deve essere lungo almeno :min caratteri.',
        'array' => ':Attribute deve contenere almeno :min elementi.',
    ],
    'numeric' => ':Attribute deve essere un numero.',
    // Girata in modo che il genere lo porti :attribute: con un segnaposto
    // solo, «non può restare vuoto/vuota» non si può accordare.
    'required' => 'Manca :attribute.',
    'required_if' => ':Attribute serve quando :other vale :value.',
    'string' => ':Attribute deve essere del testo.',
    'unique' => ':Attribute risulta già in uso.',
    'uploaded' => 'Il caricamento di :attribute non è riuscito: forse il file è troppo grande.',

    /*
     * Come si chiamano i campi quando l'errore li nomina.
     *
     * Senza questo, il messaggio direbbe «Il campo start_matchday non può
     * restare vuoto», che è il nome della colonna e non della cosa.
     */
    'attributes' => [
        'name' => 'il nome',
        'nome' => 'il nome',
        'coach_name' => 'l\'allenatore',
        'email' => 'l\'email',
        'password' => 'la password',
        'anno' => 'l\'annata',
        'dati' => 'l\'annata dei dati',
        'season' => 'l\'annata',
        'start_matchday' => 'la prima giornata',
        'giornata' => 'la giornata',
        'ore' => 'le ore',
        'listone' => 'il listone',
        'quanti' => 'il numero',
        'quotazione_iniziale' => 'la quotazione',
        'role' => 'il ruolo',
        'module' => 'il modulo',
        'titolari' => 'i titolari',
        'panchina' => 'la panchina',
        'partecipanti' => 'i partecipanti',
        'format' => 'il formato',
        'stagione' => 'la stagione',
        'verso' => 'la stagione di destinazione',
        'offerte' => 'le carte offerte',
        'richieste' => 'le carte richieste',
    ],
];
