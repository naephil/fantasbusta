<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use Illuminate\Console\Command;

/**
 * Che cosa c'è in casa, in una schermata.
 *
 * Serve perché le annate sono dati del mondo, si accumulano in giorni diversi e
 * un pezzo alla volta — il calendario oggi, cinque giornate domani — e a
 * distanza di una settimana nessuno si ricorda a che punto era rimasto. Senza
 * questa risposta si finisce a chiederla ai comandi che scaricano, che però
 * rispondono solo «niente da fare» e non distinguono «ce l'hai già» da «non
 * l'hai mai avuto».
 *
 * ⚠️ Reali e simulate si contano separate. Una giornata simulata è un
 * segnaposto, e sommarla al totale è il modo più sicuro di credere di avere
 * un'annata che invece è per metà inventata.
 */
class Annate extends Command
{
    protected $signature = 'annate';

    protected $description = 'Elenca le annate di Serie A presenti e quanto ne è stato scaricato';

    public function handle(): int
    {
        $anni = Fixture::select('season')->distinct()->orderBy('season')->pluck('season');

        if ($anni->isEmpty()) {
            $this->newLine();
            $this->warn('  In casa non c\'è nessuna annata.');
            $this->newLine();
            $this->line('  Si comincia dal calendario, ~62 chiamate su 100 al giorno:');
            $this->line('    php artisan stagione:carica 2023');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();

        $righe = $anni->map(function (int $anno) {
            $giornate = Fixture::where('season', $anno)->distinct()->count('matchday');
            $reali = $this->giornate($anno, 'reale');
            $simulate = $this->giornate($anno, 'simulata');

            return [
                $anno.'/'.substr((string) ($anno + 1), 2),
                Fixture::where('season', $anno)->count(),
                $giornate,
                $reali.($giornate ? " su {$giornate}" : ''),
                $simulate ?: '—',
                PlayerSeason::where('season', $anno)->count(),
            ];
        });

        $this->table(
            ['Annata', 'Partite', 'Giornate', 'Scaricate', 'Simulate', 'Listone'],
            $righe->all(),
        );

        // Il passo successivo, per l'annata più indietro: è sempre quello che
        // si sta cercando quando si lancia questo comando.
        $incompleta = $anni->first(fn (int $a) => $this->giornate($a, 'reale')
            < Fixture::where('season', $a)->distinct()->count('matchday'));

        if ($incompleta) {
            $this->line("  Per continuare:  php artisan stagione:scarica {$incompleta}");
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function giornate(int $anno, string $fonte): int
    {
        return PlayerStat::where('season', $anno)
            ->where('source', $fonte)
            ->distinct()
            ->count('matchday');
    }
}
