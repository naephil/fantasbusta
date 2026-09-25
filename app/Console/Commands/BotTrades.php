<?php

namespace App\Console\Commands;

use App\Services\Trade\BotTrader;
use Illuminate\Console\Command;

/**
 * Fa rispondere i bot alle proposte di scambio.
 *
 * Gira dal cron insieme al draft: una proposta a un bot che resta appesa per
 * ore è indistinguibile da un mercato rotto, e chi sta provando il gioco non ha
 * modo di sapere quale delle due cose sia.
 */
class BotTrades extends Command
{
    protected $signature = 'bot:scambi
        {--percentuale=50 : Quante proposte accettare, in percentuale}';

    protected $description = 'I bot accettano o rifiutano le proposte di scambio in attesa';

    public function handle(BotTrader $bot): int
    {
        $esito = $bot->rispondi(percentuale: (int) $this->option('percentuale'));

        if ($esito['accettate'] || $esito['rifiutate']) {
            $this->info("Scambi: {$esito['accettate']} accettati, {$esito['rifiutate']} rifiutati.");
        }

        return self::SUCCESS;
    }
}
