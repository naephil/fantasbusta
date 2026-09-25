<?php

namespace App\Console\Commands;

use App\Models\Draft;
use App\Services\Draft\PackOpener;
use Illuminate\Console\Command;

// Battito del draft. Unico punto di ingresso del cron su Hostpoint, ogni 5 minuti
// (intervallo minimo consentito, e più che sufficiente: i turni durano fra 20
// minuti e 4 ore, quindi la granularità è invisibile).
//
//   */5 * * * * /usr/local/php82/bin/php /home/UTENTE/www/artisan draft:tick >/dev/null 2>&1
//
// N.B. la riga di crontab sta in commenti di riga e non in un docblock: la
// sequenza `*/` di `*/5` chiuderebbe il commento a blocco e romperebbe il file.

/**
 * Apre i draft maturi, sbusta d'ufficio i turni scaduti (o quelli di chi ha
 * attivato la modalità automatica), e fa avanzare la coda snake.
 *
 * Lo sbustamento vero sta in PackOpener, condiviso con la strada del manager
 * che clicca «apri»: le due possono arrivare nello stesso istante, ed è la
 * ragione per cui il lock non va duplicato.
 */
class DraftTick extends Command
{
    protected $signature = 'draft:tick';

    protected $description = 'Apre i draft, sbusta i turni scaduti e avanza la coda snake';

    public function handle(PackOpener $opener): int
    {
        $this->openDueDrafts($opener);

        Draft::where('state', 'open')->each(function (Draft $draft) use ($opener) {
            $this->advance($draft, $opener);
        });

        return self::SUCCESS;
    }

    private function openDueDrafts(PackOpener $opener): void
    {
        Draft::where('state', 'pending')
            ->where('opens_at', '<=', now())
            ->each(function (Draft $draft) use ($opener) {
                $draft->update(['state' => 'open']);
                $opener->activateNext($draft);
                $this->info("Draft {$draft->id} aperto (giornata {$draft->matchday}).");
            });
    }

    private function advance(Draft $draft, PackOpener $opener): void
    {
        // Un giro solo per tick non basta: se la lega è mezza in modalità
        // automatica bisogna poter smaltire più turni di fila. Il limite
        // evita che un draft intero si consumi dentro un singolo cron.
        for ($guard = 0; $guard < 60; $guard++) {
            $turn = $draft->activeTurn();

            if (! $turn) {
                if (! $opener->activateNext($draft)) {
                    $draft->update(['state' => 'closed']);
                    $this->info("Draft {$draft->id} concluso.");
                }

                continue;
            }

            $auto = $turn->manager->auto_draft;
            $scaduto = $turn->expires_at?->isPast() ?? false;

            if (! $auto && ! $scaduto) {
                return;   // il turno è ancora legittimamente in corso
            }

            $by = $auto && ! $scaduto ? 'manager' : 'auto';

            if ($opener->open($turn, $by) !== null) {
                $this->line("Turno {$turn->pick_index} sbustato ({$by}).");
            }
        }
    }
}
