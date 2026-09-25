<?php

use App\Support\Battito;
use Illuminate\Support\Facades\Schedule;

/*
 * Il battito del gioco.
 *
 * `draft:tick` è l'unico lavoro periodico che serve, e serve davvero: apre i
 * draft quando maturano, sbusta d'ufficio i turni scaduti e fa scorrere la
 * coda snake. Senza, un draft resta fermo al primo che non clicca — e a quel
 * punto la giornata si gioca con le rose a metà.
 *
 * Ogni minuto e non ogni cinque: i turni durano fra venti minuti e quattro ore,
 * quindi la granularità è invisibile, ma su una prova di pochi giorni un minuto
 * di attesa in meno si nota. In produzione su Hostpoint il cron minimo è cinque
 * minuti e va benissimo lo stesso.
 *
 * In locale si tiene vivo con:
 *
 *     php artisan schedule:work
 *
 * Su Hostpoint basta una riga sola di crontab, che chiama lo scheduler e non i
 * singoli comandi — così aggiungere un lavoro qui non richiede di toccare il
 * server. N.B. niente line break DOS: Hostpoint rifiuta i cron con `\r\n`.
 *
 *     * * * * * /usr/local/php82/bin/php /home/UTENTE/www/artisan schedule:run >/dev/null 2>&1
 */
/*
 * I bot rispondono alle proposte di scambio.
 *
 * Sta qui e non dentro `draft:tick` perché è un'altra cosa: il mercato vive
 * fra un draft e l'altro, e infilarlo lì significherebbe che un lock appeso sul
 * draft spegne anche il mercato. Ogni cinque minuti basta — una proposta non è
 * un turno che scade.
 */
Schedule::command('bot:scambi')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('draft:tick')
    ->everyMinute()
    // Se un tick è ancora in corso — capita quando una lega intera è in
    // automatico e smaltisce sessanta turni — il successivo non deve
    // sovrapporsi: aprirebbe la stessa busta due volte se non fosse per il
    // lock, e comunque non serve a niente.
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Il segno che il cron è passato di qui.
 *
 * ⚠️ Deliberatamente separato da `draft:tick` e senza `withoutOverlapping`: se
 * il battito fosse un effetto del tick, un lock rimasto appeso lo spegnerebbe e
 * si leggerebbe «cron fermo» mentre il cron gira benissimo. Qui l'unica cosa
 * che si sta misurando è se `schedule:run` viene chiamato, ed è quella che
 * manca quando manca.
 *
 * Gira nel processo dello scheduler — niente `runInBackground` — così il segno
 * c'è già quando il comando ritorna, e `php artisan controlla` lanciato subito
 * dopo lo vede.
 */
Schedule::call(fn () => Battito::segna())
    ->everyMinute()
    ->name('battito')
    ->description('Segna che lo scheduler è passato');
