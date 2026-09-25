<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Models\Draft;
use App\Models\League;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\PlayerSeason;
use App\Models\Standing;
use App\Services\Calendar\CalendarBuilder;
use App\Services\Calendar\StandingsUpdater;
use App\Services\Draft\DraftBuilder;
use App\Services\Draft\PackOpener;
use App\Services\Power\PowerUpdater;
use App\Services\Scoring\MatchdayScorer;
use App\Services\Simulation\MatchdaySimulator;
use Illuminate\Console\Command;

/**
 * Fa girare una stagione finta su dati veri.
 *
 * Dodici manager inventati, il listone e il calendario di Serie A veri, e le
 * statistiche generate dal simulatore. Serve a vedere il ciclo completo — voti,
 * power, draft, sbustamento d'ufficio, punteggi, classifica — senza aspettare
 * che il campionato vada avanti e senza bruciare il tetto di chiamate all'API.
 *
 * È il collaudo che nessun test unitario può sostituire: i test dimostrano che
 * i pezzi sono giusti, questo dimostra che stanno insieme.
 */
class DemoRun extends Command
{
    /**
     * Le dodici squadre di prova.
     *
     * Ognuna con la sua palette — sono dodici come le palette, quindi nessuna
     * si ripete — e con stile, forma, simbolo e sponsor diversi. Serve a
     * vedere la lega con addosso i vestiti veri: dodici righe grigie non
     * dicono niente su come sarà davvero.
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:string,7:string}>
     *                                                                                           nome, allenatore, palette maglia, stile, palette stemma, forma, simbolo, sponsor
     */
    private const SQUADRE = [
        ['Atletico Sbustamento', 'Ada Fornaciari', 'fantasbusta', 'sash', 'fantasbusta', 'scudo', 'fulmine', 'BUSTAPIÙ'],
        ['Real Fornello', 'Bruno Tessari', 'granata', 'righe', 'sabbia', 'cerchio', 'fiamma', 'TERMOSUD'],
        ['Dinamo Divano', 'Carla Pezzi', 'celeste', 'cerchiati', 'ghiaccio', 'rombo', 'stella', 'IDROBAR'],
        ['Sporting Tapiro', 'Dario Belli', 'verdeoro', 'banda', 'militare', 'esagono', 'artiglio', 'VETRERIE NOVA'],
        ['Bustese 1908', 'Elena Riva', 'bianconera', 'righe', 'bianconera', 'scudo', 'torre', 'PANIFICIO 900'],
        ['Olympique Cinghiale', 'Fabio Neri', 'militare', 'meta', 'granata', 'cerchio', 'artiglio', ''],
        ['Virtus Pantofola', 'Giulia Monti', 'viola', 'quarti', 'viola', 'rombo', 'corona', 'GELATI POLO'],
        ['Pro Sbadiglio', 'Hugo Marani', 'sabbia', 'tinta', 'nerazzurra', 'scudo', 'nessuno', ''],
        ['Unione Grattachecca', 'Irene Sala', 'giallorossa', 'sash', 'giallorossa', 'esagono', 'pallone', 'TRIS CAFFÈ'],
        ['Ideal Tortellino', 'Luca Verri', 'rossonera', 'righe', 'rossonera', 'scudo', 'ala', 'MOTOSCAFI RE'],
        ['Audace Zanzara', 'Marta Conti', 'nerazzurra', 'cerchiati', 'celeste', 'cerchio', 'fulmine', ''],
        ['Libertas Ombrellone', 'Nico Gallo', 'ghiaccio', 'banda', 'verdeoro', 'rombo', 'stella', 'GELATI POLO'],
    ];

    /** I caratteri dello sponsor, a rotazione: servono a vederli tutti. */
    private const CARATTERI = ['blocco', 'mono', 'corsivo', 'contorno'];

    protected $signature = 'demo:run
        {--anno= : Annata di Serie A da usare, altrimenti la più recente in casa}
        {--giornate=3 : Quante giornate simulare}
        {--da=1 : Prima giornata di Serie A}
        {--fresh : Cancella la lega di prova e ricomincia}';

    protected $description = 'Simula una stagione di prova con dodici manager inventati';

    public function handle(
        CalendarBuilder $calendario,
        PowerUpdater $power,
        DraftBuilder $draftBuilder,
        PackOpener $opener,
        MatchdaySimulator $simulatore,
        MatchdayScorer $scorer,
        StandingsUpdater $standings,
    ): int {
        $anno = $this->option('anno') !== null
            ? (int) $this->option('anno')
            : (int) PlayerSeason::max('season');

        if (PlayerSeason::where('season', $anno)->where('active', true)->count() < 100) {
            $this->error("Listone {$anno} troppo scarno: lancia prima `stagione:carica {$anno}` e `listone:import`.");

            return self::FAILURE;
        }

        $da = (int) $this->option('da');
        $giornate = (int) $this->option('giornate');
        $stagione = $this->stagione($anno, $da);

        $this->line("Lega «{$stagione->league->name}» · ".$stagione->etichetta().' · '.$stagione->partecipanti()->count().' manager');
        $this->line($calendario->generate($stagione, $da).' turni di calendario dalla '.$da.'ª');
        $this->newLine();

        foreach (range($da, $da + $giornate - 1) as $giornata) {
            $this->components->task("Giornata {$giornata}", function () use (
                $stagione, $giornata, $power, $draftBuilder, $opener, $simulatore, $scorer, $standings
            ) {
                // 1. Il power per questa giornata, dai dati fino alla precedente-1.
                $power->update($stagione, $giornata);

                // 2. Draft: pool, turni snake, e tutti in automatico.
                if (! Draft::where('league_season_id', $stagione->id)->where('matchday', $giornata)->exists()) {
                    $draft = $draftBuilder->build(
                        $stagione,
                        $giornata,
                        opensAt: now()->subDay(),
                        deadlineAt: now()->addDay(),
                    );

                    $draft->update(['state' => 'open']);
                    $opener->activateNext($draft);

                    while ($turno = $draft->fresh()->activeTurn()) {
                        $opener->open($turno, 'auto');
                    }

                    $draft->update(['state' => 'closed']);
                }

                // 3. La giornata si gioca.
                $simulatore->simulate($stagione->season, $giornata);

                // 4. Voti, formazioni d'ufficio, sfide, classifica.
                $scorer->run($stagione, $giornata);
                $standings->update($stagione, $giornata);

                return true;
            });
        }

        $this->newLine();
        $this->classifica($stagione, $da + $giornate - 1);

        return self::SUCCESS;
    }

    /**
     * Il gruppo di prova e la sua stagione.
     *
     * Il gruppo è permanente e le sue squadre pure: si ricrea solo con
     * `--fresh`. La stagione invece è per annata, quindi rilanciare la demo su
     * un altro anno aggiunge una partita nuova allo stesso gruppo — che è
     * esattamente ciò che il modello deve saper fare.
     */
    private function stagione(int $anno, int $da): LeagueSeason
    {
        if ($this->option('fresh')) {
            League::where('name', 'Lega di prova')->each(fn (League $l) => $l->delete());
        }

        $league = League::firstOrCreate(['name' => 'Lega di prova']);

        foreach (self::SQUADRE as $i => [$nome, $allenatore, $pMaglia, $stile, $pStemma, $forma, $simbolo, $sponsor]) {
            Manager::updateOrCreate(
                ['email' => $this->email($allenatore)],
                [
                    'league_id' => $league->id,
                    'name' => $nome,
                    'coach_name' => $allenatore,
                    'password' => 'password',
                    'auto_draft' => true,
                    'is_admin' => $i === 0,
                    'jersey' => [
                        'colori' => config("squadra.palette.{$pMaglia}.colori"),
                        'stile' => $stile,
                    ],
                    'crest' => [
                        'colori' => config("squadra.palette.{$pStemma}.colori"),
                        'forma' => $forma,
                        'simbolo' => $simbolo,
                    ],
                    'sponsor' => [
                        'testo' => $sponsor,
                        'stile' => self::CARATTERI[$i % count(self::CARATTERI)],
                        // Un terzo delle squadre col riquadro: la demo deve far
                        // vedere anche quella possibilità, non solo la scritta nuda.
                        'posizione' => ['alto', 'centro', 'basso'][$i % 3],
                        'riquadro' => $i % 3 === 1,
                        'colore' => null,
                        'colore_riquadro' => null,
                    ],
                ],
            );
        }

        return LeagueSeason::updateOrCreate(
            ['league_id' => $league->id, 'season' => $anno],
            ['state' => 'in_corso', 'start_matchday' => $da],
        )->load('league');
    }

    /** L'email dell'allenatore: nome puntato, minuscolo, senza accenti. */
    private function email(string $allenatore): string
    {
        $pulito = preg_replace('/[^a-z]+/', '.', mb_strtolower($allenatore));

        return trim((string) $pulito, '.').'@demo.test';
    }

    private function classifica(LeagueSeason $stagione, int $matchday): void
    {
        $righe = Standing::with('manager')
            ->where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->orderBy('posizione')
            ->get();

        if ($righe->isEmpty()) {
            $this->warn('Nessuna classifica prodotta.');

            return;
        }

        $this->info("Classifica dopo la {$matchday}ª");

        $this->table(
            ['#', 'manager', 'punti', 'fantapunti', 'carte'],
            $righe->map(fn (Standing $s) => [
                $s->posizione,
                $s->manager->name,
                $s->punti,
                number_format($s->fantapunti, 1, ',', ''),
                Card::where('owner_manager_id', $s->manager_id)->where('matchday', $matchday)->count(),
            ])->all(),
        );
    }
}
