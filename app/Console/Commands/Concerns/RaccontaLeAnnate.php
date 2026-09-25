<?php

namespace App\Console\Commands\Concerns;

use App\Models\Fixture;
use App\Models\PlayerStat;
use Illuminate\Support\Collection;

/**
 * Che annate ci sono davvero in casa.
 *
 * Un'annata assente e un'annata completa producono lo stesso silenzio: nessun
 * errore, nessuna riga da scaricare, niente da esportare. Chi legge «non c'è
 * niente da fare» non ha modo di sapere quale dei due casi sia, e la differenza
 * è enorme — nel primo manca tutto, nel secondo c'è tutto.
 *
 * Quindi quando un comando non trova quello che cerca, dice anche cosa c'è.
 */
trait RaccontaLeAnnate
{
    /** @return Collection<int,int> gli anni con almeno una partita in calendario */
    protected function annateInCasa(): Collection
    {
        return Fixture::select('season')->distinct()->orderBy('season')->pluck('season');
    }

    protected function calendarioMancante(int $anno): bool
    {
        return ! Fixture::where('season', $anno)->exists();
    }

    /**
     * Il messaggio da dare quando l'annata chiesta non c'è.
     *
     * ⚠️ Dice come rimediare e quanto costa: `stagione:carica` sono una
     * sessantina di chiamate su un tetto di cento al giorno, e scoprirlo dopo
     * averle spese è tardi.
     */
    protected function spiegaAnnataAssente(int $anno): void
    {
        $this->error("Dell'annata {$anno} non c'è nemmeno il calendario.");
        $this->newLine();

        $ci = $this->annateInCasa();

        $this->line($ci->isEmpty()
            ? '  In casa non c\'è nessuna annata.'
            : '  In casa ci sono: '.$ci->implode(', '));

        $this->newLine();
        $this->line("  Per portarla in casa:  php artisan stagione:carica {$anno}");
        $this->line('  Sono ~62 chiamate su 100 al giorno. Poi le statistiche,');
        $this->line("  10 per giornata:       php artisan stagione:scarica {$anno}");
    }

    /**
     * Quante giornate REALI ci sono di un'annata: le simulate non contano,
     * sono segnaposto e spacciarle per dati fa credere di avere ciò che manca.
     */
    protected function giornateReali(int $anno): int
    {
        return PlayerStat::where('season', $anno)
            ->where('source', 'reale')
            ->distinct()
            ->count('matchday');
    }
}
