<?php

return [
    // API-Football v3 (api-sports.io). La chiave viaggia come header
    // `x-apisports-key`, non come parametro in query.
    'key' => env('API_FOOTBALL_KEY'),

    'base_url' => env('API_FOOTBALL_URL', 'https://v3.football.api-sports.io'),

    // 135 è la Serie A.
    //
    // Non c'è nessuna «stagione corrente» qui, ed è voluto: in casa ce n'è più
    // d'una — due gruppi possono giocare anni diversi nello stesso momento — e
    // un valore di configurazione ne sceglierebbe una per tutti. L'annata si
    // passa esplicitamente a ogni chiamata, così non si può sbagliare in
    // silenzio.
    'league' => (int) env('API_FOOTBALL_LEAGUE', 135),

    'timeout' => (int) env('API_FOOTBALL_TIMEOUT', 20),

    // Il piano gratuito concede 100 chiamate al giorno. Una giornata di
    // statistiche ne costa una ventina (due per partita), quindi il margine
    // c'è ma non è largo: meglio saperlo prima di scoprirlo a metà sync.
    'max_pages' => (int) env('API_FOOTBALL_MAX_PAGES', 60),

    // ⚠️ Oltre al tetto giornaliero c'è un tetto al MINUTO, e ignorarlo si
    // paga caro: venti chiamate di fila si prendono un 429 a metà strada e
    // lasciano una giornata coi voti a pezzi, che è peggio di non averla
    // toccata. Distanziarle è l'unico rimedio.
    //
    // Il valore giusto dipende dal piano, e cambiarlo a mano quando il piano
    // cambia è esattamente il genere di cosa che non si fa: si scopre il freno
    // sbagliato dopo, da un 429 o da mezz'ora di attesa inutile. Quindi qui
    // sta solo lo scavalco manuale — vuoto vuol dire «taratelo da solo»,
    // leggendo il tetto vero da /status.
    'min_interval_ms' => env('API_FOOTBALL_INTERVAL') === null
        ? null
        : (int) env('API_FOOTBALL_INTERVAL'),

    // Quante chiamate al minuto concede ciascun piano, per tetto giornaliero.
    // Sono i numeri di api-sports.io: gratuito, Pro, Ultra, Mega.
    'al_minuto' => [
        100 => 10,
        7500 => 300,
        75000 => 450,
        150000 => 900,
    ],

    // ⚠️ Non si usa tutto il minuto: il tetto è per chiave e conta anche ciò
    // che gira altrove — un'altra finestra, il cron, una prova a mano. Stare
    // all'80% lascia il margine per non trovarsi il 429 proprio a metà di una
    // sincronizzazione lunga.
    'margine' => 0.8,

    // Il freno di sicurezza di quando il tetto non si conosce ancora: il primo
    // /status della giornata parte da qui. Vale il piano più stretto, perché
    // sbagliare per eccesso di prudenza costa qualche secondo, sbagliare
    // nell'altro verso costa una sincronizzazione a pezzi.
    'intervallo_prudente' => 6500,

    // Le annate offerte nella pagina di gestione. Le passate arrivano coi
    // rating veri — per rigiocarle è anzi meglio, non si simula niente — e dal
    // piano Pro in su c'è anche quella in corso.
    'stagioni_disponibili' => [2022, 2023, 2024, 2025, 2026],
];
