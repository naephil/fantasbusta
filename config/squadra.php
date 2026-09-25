<?php

/**
 * Il guardaroba: palette, stili di maglia, forme e simboli dello stemma.
 *
 * Stili, forme e simboli sono insiemi CHIUSI, e non a caso: sono le cose che il
 * disegno sa rendere, e una voce inventata non produrrebbe una maglia brutta,
 * produrrebbe una maglia vuota.
 *
 * ⚠️ Le **palette** invece non lo sono più. I colori si scelgono uno per uno —
 * tre valori liberi — e le terne qui sotto restano come punti di partenza nella
 * pagina della squadra. Il motivo per cui erano chiuse resta vero (dodici
 * squadre che scelgono da zero fanno dodici pastrocchi, e una palette curata
 * tiene dignitosa anche la scelta frettolosa), ma non giustificava impedire a
 * qualcuno di mettere i colori della propria squadra. Adesso sono un
 * trampolino, non una gabbia.
 *
 * Le chiavi di stili, forme e simboli finiscono nel JSON di `managers.jersey` e
 * `managers.crest`, quindi vanno trattate come dati salvati: si aggiungono
 * voci, non si rinominano. Le chiavi delle palette no — quelle non si salvano
 * più, si salvano i colori — quindi lì si può cambiare idea liberamente.
 */
return [

    /*
    | Terne di colori: base, motivo, dettaglio. Sono ispirate ai classici del
    | calcio italiano senza copiarne nessuno — servono a dare un punto di
    | partenza riconoscibile, non a fare il verso a una squadra vera.
    */
    'palette' => [
        'nerazzurra' => ['nome' => 'Nerazzurra', 'colori' => ['#0d1b33', '#1c6fd4', '#f2ede1']],
        'rossonera' => ['nome' => 'Rossonera', 'colori' => ['#0b0b0e', '#c8102e', '#f2ede1']],
        'bianconera' => ['nome' => 'Bianconera', 'colori' => ['#f2ede1', '#0b0b0e', '#c8a24a']],
        'giallorossa' => ['nome' => 'Giallorossa', 'colori' => ['#8b1b2b', '#f0b323', '#f2ede1']],
        'verdeoro' => ['nome' => 'Verdeoro', 'colori' => ['#0f6b3d', '#f0c419', '#f2ede1']],
        'celeste' => ['nome' => 'Celeste', 'colori' => ['#7fc4e8', '#0b3a5c', '#f2ede1']],
        'granata' => ['nome' => 'Granata', 'colori' => ['#6b1220', '#d4a04a', '#f2ede1']],
        'viola' => ['nome' => 'Viola', 'colori' => ['#4b2a7b', '#d8c7f0', '#f2ede1']],
        'fantasbusta' => ['nome' => 'Fantasbusta', 'colori' => ['#0b0b0e', '#e02718', '#f2ede1']],
        'ghiaccio' => ['nome' => 'Ghiaccio', 'colori' => ['#dfe6ec', '#2e8fe0', '#0b0b0e']],
        'sabbia' => ['nome' => 'Sabbia', 'colori' => ['#d9c9a3', '#7a4a1e', '#0b0b0e']],
        'militare' => ['nome' => 'Militare', 'colori' => ['#3d4a33', '#b7c48a', '#f2ede1']],
    ],

    /*
    | Come il colore del motivo si dispone sulla maglia. Il primo è la tinta
    | unita, che deve restare la scelta più ovvia.
    */
    'stili_maglia' => [
        'tinta' => 'Tinta unita',
        'righe' => 'Righe verticali',
        'cerchiati' => 'Cerchiati',
        'banda' => 'Banda sul petto',
        'sash' => 'Fascia diagonale',
        'quarti' => 'Quarti',
        'meta' => 'Metà campo',
    ],

    'forme_stemma' => [
        'scudo' => 'Scudo',
        'cerchio' => 'Cerchio',
        'rombo' => 'Rombo',
        'esagono' => 'Esagono',
    ],

    'simboli' => [
        'stella' => 'Stella',
        'fulmine' => 'Fulmine',
        'corona' => 'Corona',
        'pallone' => 'Pallone',
        'fiamma' => 'Fiamma',
        'ala' => 'Ala',
        'artiglio' => 'Artiglio',
        'torre' => 'Torre',
        'nessuno' => 'Solo iniziali',
    ],

    /*
    | Lo sponsor sul petto.
    |
    | I modelli sono marchi inventati di sana pianta: servono a dare
    | l'atmosfera giusta senza appropriarsi di nomi veri. Sono scorciatoie,
    | non un elenco chiuso — chiunque può scrivere il proprio.
    */
    'sponsor_modelli' => [
        'bustapiu' => ['testo' => 'BUSTAPIÙ', 'stile' => 'blocco'],
        'idrobar' => ['testo' => 'IDROBAR', 'stile' => 'mono'],
        'tris' => ['testo' => 'TRIS CAFFÈ', 'stile' => 'corsivo'],
        'termosud' => ['testo' => 'TERMOSUD', 'stile' => 'contorno'],
        'panificio' => ['testo' => 'PANIFICIO 900', 'stile' => 'mono'],
        'vetrerie' => ['testo' => 'VETRERIE NOVA', 'stile' => 'blocco'],
        'gelati' => ['testo' => 'GELATI POLO', 'stile' => 'corsivo'],
        'motoscafi' => ['testo' => 'MOTOSCAFI RE', 'stile' => 'contorno'],
    ],

    'sponsor_stili' => [
        'blocco' => 'Blocco',
        'mono' => 'Tecnico',
        'corsivo' => 'Corsivo',
        'contorno' => 'Contorno',
    ],

    /*
    | Lo stemma caricato: piccolo di proposito. È un distintivo da mostrare a
    | 40 pixel accanto a un nome, non un poster.
    */
    'upload' => [
        'max_kb' => 512,
        'formati' => ['png', 'jpg', 'jpeg', 'svg', 'webp'],
        'cartella' => 'stemmi',
    ],

    /*
    | Lo sponsor caricato vuole la trasparenza: finisce sopra il colore della
    | maglia, e un rettangolo bianco attorno al marchio rovinerebbe tutto.
    | Niente JPEG, che la trasparenza non ce l'ha proprio.
    */
    'upload_sponsor' => [
        'max_kb' => 256,
        'formati' => ['png', 'webp', 'svg'],
        'cartella' => 'sponsor',
    ],
];
