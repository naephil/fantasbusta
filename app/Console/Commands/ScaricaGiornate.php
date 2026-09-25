<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\PlayerStat;
use App\Services\Ingest\ApiFootball;
use App\Services\Ingest\StatSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scarica in anticipo le statistiche di più giornate.
 *
 * Serve perché il tetto giornaliero è la vera scadenza del progetto: con cento
 * chiamate al giorno una stagione intera si porta in casa in cinque o sei
 * giorni, e conviene cominciare prima che i volontari siano davanti allo
 * schermo. Le statistiche sono condivise fra tutti i gruppi, quindi si scarica
 * una volta e poi giocare una giornata non costa più niente.
 *
 * ⚠️ Si ferma **prima** di sfondare il tetto, non dopo: una giornata scaricata
 * a metà ha i voti di sei partite su dieci, e quella è peggio di una giornata
 * intonsa — il calcolo la prenderebbe per buona e assegnerebbe dei senza voto
 * a chi invece aveva giocato.
 */
class ScaricaGiornate extends Command
{
    use Concerns\RaccontaLeAnnate;

    protected $signature = 'stagione:scarica
        {anno : Annata di Serie A, es. 2023}
        {da? : Prima giornata; senza, la prima che manca}
        {a? : Ultima giornata; senza, fin dove arriva la quota}
        {--riserva=5 : Chiamate da lasciare libere per il resto}
        {--forza : Riscarica anche le giornate già in casa}';

    protected $description = 'Scarica le statistiche di più giornate rispettando il tetto giornaliero';

    public function handle(ApiFootball $api, StatSync $sync): int
    {
        $anno = (int) $this->argument('anno');

        // ⚠️ Prima di tutto il resto: senza calendario non c'è nemmeno la lista
        // delle giornate, quindi «niente da fare» sarebbe vero e completamente
        // fuorviante — dice «ce l'hai già» proprio a chi non ha niente. Sono
        // due situazioni opposte e vanno dette con due frasi diverse.
        if ($this->calendarioMancante($anno)) {
            $this->spiegaAnnataAssente($anno);

            return self::FAILURE;
        }

        $giornate = $this->daFare($anno);

        if ($giornate === []) {
            $this->info("Non c'è niente da scaricare: le "
                .$this->giornateReali($anno).' giornate dell\'annata '.$anno.' sono già in casa.');

            return self::SUCCESS;
        }

        try {
            $quota = $api->quota();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $budget = max(0, $quota['restano'] - (int) $this->option('riserva'));

        $this->line("Quota: {$quota['usate']}/{$quota['tetto']} usate, {$quota['restano']} libere.");
        $this->line("Budget di questa passata: {$budget} chiamate (riserva ".$this->option('riserva').').');
        $this->newLine();

        $fatte = 0;
        $spese = 0;

        foreach ($giornate as $g) {
            $costo = Fixture::where('season', $anno)->where('matchday', $g)->count();

            if ($costo === 0) {
                continue;
            }

            if ($spese + $costo > $budget) {
                $this->warn("Mi fermo alla {$g}ª: costerebbe {$costo} e ne restano ".($budget - $spese).'.');
                break;
            }

            try {
                $esito = $sync->matchday($anno, $g);
            } catch (Throwable $e) {
                $this->error("Giornata {$g}: {$e->getMessage()}");
                break;
            }

            $spese += $costo;
            $fatte++;

            $this->line(sprintf(
                '  %2dª — %d partite, %d righe%s',
                $g,
                $esito['partite'],
                $esito['giocatori'],
                ($esito['saltate'] ? " · ⚠ {$esito['saltate']} non ancora finite, saltate" : '')
                    .($esito['aggiunti'] ? " · {$esito['aggiunti']} giocatori aggiunti in anagrafica" : '')
                    .($esito['ripulite'] ? " · {$esito['ripulite']} righe simulate sostituite" : ''),
            ));
        }

        $this->newLine();
        $this->info("{$fatte} giornate scaricate, ~{$spese} chiamate spese.");

        // Senza spegnere `--forza` il conteggio finale rielencherebbe proprio
        // le giornate appena rifatte, dicendo che mancano quando non mancano.
        $this->input->setOption('forza', false);
        $mancano = count($this->daFare($anno));

        if ($mancano > 0) {
            $this->line("Ne restano {$mancano} da fare: rilancia domani, la quota si azzera a mezzanotte UTC.");
        }

        // ⚠️ Queste righe sono costate chiamate, e le chiamate hanno un tetto
        // giornaliero: un `migrate:fresh` distratto le porta via e ricomprarle
        // sono giorni, non minuti. L'esportazione è anche il formato con cui si
        // portano sul server, quindi non è lavoro in più.
        if ($fatte > 0) {
            $this->newLine();
            $this->line("Mettile al sicuro:  php artisan annata:esporta {$anno}");
        }

        return self::SUCCESS;
    }

    /**
     * Le giornate ancora da scaricare, in ordine.
     *
     * «Da scaricare» significa senza nemmeno una statistica: una giornata
     * arrivata a metà — succede se il tetto si esaurisce nel mezzo, o se una
     * partita non era ancora finita — va rifatta, e `updateOrCreate` la
     * completa senza duplicare niente.
     *
     * @return list<int>
     */
    private function daFare(int $anno): array
    {
        $inCalendario = Fixture::where('season', $anno)
            ->select('matchday')
            ->distinct()
            ->orderBy('matchday')
            ->pluck('matchday');

        $da = $this->argument('da') ? (int) $this->argument('da') : 1;
        $a = $this->argument('a') ? (int) $this->argument('a') : 38;

        // Quante partite ha ciascuna giornata, e per quante ci sono già voti:
        // il confronto fra i due dice chi è completa e chi è a metà.
        $partite = Fixture::where('season', $anno)
            ->selectRaw('matchday, count(*) as n')
            ->groupBy('matchday')
            ->pluck('n', 'matchday');

        // ⚠️ Solo le REALI contano come «già in casa». Una giornata simulata è
        // un segnaposto, non un dato: contarla farebbe rifiutare lo scarico di
        // ciò che ancora manca, ed è il modo più sicuro di credere di avere
        // un'annata che invece è per metà inventata.
        $conStat = PlayerStat::where('season', $anno)
            ->where('source', 'reale')
            ->select('matchday')
            ->distinct()
            ->pluck('matchday')
            ->flip();

        return $inCalendario
            ->filter(fn (int $g) => $g >= $da && $g <= $a)
            ->reject(fn (int $g) => ! $this->option('forza')
                && $conStat->has($g)
                && $this->completa($anno, $g, (int) $partite[$g]))
            ->values()
            ->all();
    }

    /** Una giornata è completa se ogni sua partita ha lasciato righe VERE. */
    private function completa(int $anno, int $matchday, int $partite): bool
    {
        $conRighe = Fixture::where('season', $anno)
            ->where('matchday', $matchday)
            ->whereIn('status', ['finished'])
            ->count();

        return $conRighe === $partite
            && PlayerStat::where('season', $anno)
                ->where('matchday', $matchday)
                ->where('source', 'reale')
                ->count() >= $partite * 20;
    }
}
