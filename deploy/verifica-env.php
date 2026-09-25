<?php

/*
 * Controlla che un file .env sia leggibile, prima di caricarlo sul server.
 *
 *   php deploy/verifica-env.php deploy/env-produzione
 *
 * Usa lo STESSO parser che girerà là: un controllo scritto a mano — una regex,
 * un'occhiata — sbaglia proprio i casi che contano. Il primo `.env` rotto di
 * questo progetto era «APP_KEY=<spazi>← si genera al primo avvio», e nessuna
 * regola improvvisata lo intercettava, perché dopo l'uguale c'erano spazi e
 * sembrava un valore vuoto legittimo.
 *
 * Esce con 1 e spiega cosa non va, così chi lo chiama può fermarsi.
 *
 * ⚠️ Scrive su STDOUT e non su STDERR di proposito: PowerShell impacchetta lo
 * stderr di un programma esterno dentro un NativeCommandError e lo stampa in
 * mezzo a righe di diagnostica, rendendo illeggibile proprio il messaggio che
 * deve essere letto. Il codice d'uscita basta a dire com'è andata.
 */

$file = $argv[1] ?? null;

if ($file === null || ! is_readable($file)) {
    fwrite(STDOUT, '  [!!] File non leggibile: '.($file ?? '(nessuno)')."\n");
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';

try {
    $valori = Dotenv\Dotenv::createArrayBacked(dirname($file) ?: '.', basename($file))->load();
} catch (Throwable $e) {
    fwrite(STDOUT, "  [!!] Il .env non si legge:\n");
    fwrite(STDOUT, '       '.$e->getMessage()."\n");
    fwrite(STDOUT, "\n       In un .env tutto ciò che segue «=» è il valore, spazi\n");
    fwrite(STDOUT, "       compresi. Le note vanno sopra la riga, precedute da «#».\n");
    exit(1);
}

// Oltre alla sintassi: le cose senza le quali il server non parte comunque.
$obbligatori = [
    'APP_ENV' => 'l\'ambiente',
    'APP_URL' => 'l\'indirizzo del sito',
    'DB_CONNECTION' => 'il tipo di database',
    'DB_HOST' => 'l\'host del database',
    'DB_DATABASE' => 'il nome del database',
    'DB_USERNAME' => 'l\'utente del database',
    'DB_PASSWORD' => 'la password del database',
];

$vuoti = [];

foreach ($obbligatori as $chiave => $cosa) {
    if (trim((string) ($valori[$chiave] ?? '')) === '') {
        $vuoti[] = "{$chiave} ({$cosa})";
    }
}

if ($vuoti !== []) {
    fwrite(STDOUT, "  [!!] Nel .env mancano dei valori:\n");

    foreach ($vuoti as $v) {
        fwrite(STDOUT, "       {$v}\n");
    }

    exit(1);
}

// ⚠️ «localhost» su un hosting condiviso manda PDO a cercare un socket unix in
// locale, e l'errore che ne esce — «[2002] No such file or directory» — non
// parla di rete e fa cercare dalla parte sbagliata per un pezzo.
if (in_array(strtolower((string) $valori['DB_HOST']), ['localhost', ''], true)) {
    fwrite(STDOUT, "  [!!] DB_HOST è «localhost»: su un hosting condiviso il database\n");
    fwrite(STDOUT, "       sta su un'altra macchina, e con questo nome PDO cerca un\n");
    fwrite(STDOUT, "       socket locale che non esiste. Metti il nome del pannello,\n");
    fwrite(STDOUT, "       del tipo UTENTE.mysql.db.internal.\n");
    exit(1);
}

if (($valori['APP_DEBUG'] ?? 'false') !== 'false') {
    fwrite(STDOUT, "  [!!] APP_DEBUG non è «false»: il primo errore mostrerebbe il\n");
    fwrite(STDOUT, "       contenuto di .env — password e chiave API — a chi lo incontra.\n");
    exit(1);
}

echo "  [ok] .env leggibile e completo\n";
exit(0);
