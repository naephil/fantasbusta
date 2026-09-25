<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\Draft;
use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\Manager;
use App\Models\PlayerSeason;
use App\Models\Standing;
use App\Models\Trade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Raccoglie in un file solo tutto ciò che serve a capire un inghippo.
 *
 * Il problema di «mandami il log» è che il log da solo non basta quasi mai: un
 * errore dice cosa si è rotto ma non in che stato era la partita, e la domanda
 * successiva è sempre la stessa — a che giornata siete, il draft era aperto,
 * quante carte c'erano in giro. Questo comando mette le due cose nello stesso
 * foglio.
 *
 * ⚠️ Le email dei manager vengono oscurate: il file è fatto per essere spedito,
 * e chi lo riceve non ha bisogno degli indirizzi di nessuno.
 */
class Diagnostica extends Command
{
    protected $signature = 'diagnostica
        {--righe=80 : Quante righe di log finali includere}
        {--out= : Dove scrivere, altrimenti in storage/logs}';

    protected $description = 'Raccoglie stato e log in un file da allegare a una segnalazione';

    public function handle(): int
    {
        $righe = [];

        $righe[] = '# Diagnostica Fantasbusta — '.now()->toDateTimeString();
        $righe[] = '';
        $righe = array_merge($righe, $this->ambiente(), $this->stagioni(), $this->log());

        $testo = implode("\n", $righe)."\n";

        $file = $this->option('out')
            ?: storage_path('logs/diagnostica-'.now()->format('Ymd-His').'.md');

        File::put($file, $testo);

        $this->newLine();
        $this->info('Scritto in '.$file);
        $this->line('Allegalo alla segnalazione: contiene stato della partita e ultimi errori.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function ambiente(): array
    {
        return [
            '## Ambiente',
            '',
            '- PHP '.PHP_VERSION.' su '.PHP_OS_FAMILY,
            '- Laravel '.app()->version(),
            '- database: '.config('database.default'),
            '- debug: '.(config('app.debug') ? 'acceso' : 'spento'),
            '- chiave API-Football: '.(config('apifootball.key') ? 'presente' : 'ASSENTE'),
            '',
        ];
    }

    /** @return list<string> */
    private function stagioni(): array
    {
        $righe = ['## Stagioni', ''];

        $stagioni = LeagueSeason::with('league')->orderBy('id')->get();

        if ($stagioni->isEmpty()) {
            return array_merge($righe, ['(nessuna stagione)', '']);
        }

        foreach ($stagioni as $s) {
            $righe[] = "### {$s->league->name} · {$s->etichetta()} · {$s->state}";
            $righe[] = '';
            $righe[] = '- parte dalla '.$s->start_matchday.'ª · annata '.$s->season;
            $righe[] = '- listone dell\'annata: '.PlayerSeason::where('season', $s->season)->where('active', true)->count()
                .' attivi, '.PlayerSeason::daConfermare($s->season)->count().' da confermare';
            $righe[] = '- squadre: '.Manager::where('league_id', $s->league_id)->where('active', true)->count()
                .' attive, di cui '.Manager::where('league_id', $s->league_id)->where('is_bot', true)->count().' bot';
            $righe[] = '- giornate in classifica: '.Standing::where('league_season_id', $s->id)->distinct()->count('matchday');
            $righe[] = '- carte: '.Card::where('league_season_id', $s->id)->count()
                .' · formazioni: '.Lineup::where('league_season_id', $s->id)->count()
                .' · scambi: '.Trade::where('league_season_id', $s->id)->count();

            // Lo stato dei draft è la domanda che si fa sempre per prima: un
            // draft fermo spiega da solo mezza segnalazione.
            foreach (Draft::where('league_season_id', $s->id)->orderBy('matchday')->get() as $d) {
                $fatti = $d->turns()->where('state', 'done')->count();
                $totali = $d->turns()->count();
                $attivo = $d->activeTurn();

                $righe[] = "- draft {$d->matchday}ª: {$d->state}, {$fatti}/{$totali} turni"
                    .($attivo ? ", turno di «{$attivo->manager->name}» fino a {$attivo->expires_at}" : '')
                    .', chiude '.$d->deadline_at;
            }

            $righe[] = '';
        }

        // Il ritocco delle regole è la prima cosa da guardare quando «i punti
        // non tornano», e non lascia traccia nel log.
        foreach ($stagioni as $s) {
            if ($s->settings || $s->league->settings) {
                $righe[] = "Regole ritoccate su {$s->league->name} · {$s->etichetta()}:";
                $righe[] = '```json';
                $righe[] = json_encode(
                    ['gruppo' => $s->league->settings, 'stagione' => $s->settings],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                );
                $righe[] = '```';
                $righe[] = '';
            }
        }

        return $righe;
    }

    /** @return list<string> */
    private function log(): array
    {
        $quante = (int) $this->option('righe');
        $righe = ['## Ultime '.$quante.' righe di log', ''];

        $file = collect(File::glob(storage_path('logs/laravel*.log')))
            ->sortByDesc(fn (string $f) => File::lastModified($f))
            ->first();

        if (! $file) {
            return array_merge($righe, ['(nessun file di log)', '']);
        }

        $contenuto = collect(explode("\n", File::get($file)))
            ->filter(fn (string $r) => trim($r) !== '')
            ->take(-$quante)
            ->map(fn (string $r) => $this->oscura($r));

        return array_merge($righe, ['`'.basename($file).'`', '', '```'], $contenuto->all(), ['```', '']);
    }

    /**
     * Toglie gli indirizzi email e la chiave API.
     *
     * Il file nasce per essere spedito: chi lo legge deve poter capire cosa si
     * è rotto senza ricevere in regalo gli indirizzi di tutta la lega.
     */
    private function oscura(string $riga): string
    {
        $riga = (string) preg_replace('/[\w.+-]+@[\w.-]+\.\w+/', '<email>', $riga);

        if ($chiave = config('apifootball.key')) {
            $riga = str_replace($chiave, '<chiave>', $riga);
        }

        return Str::limit($riga, 400);
    }
}
