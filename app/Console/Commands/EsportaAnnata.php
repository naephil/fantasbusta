<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Impacchetta un'annata scaricata, per portarla su un'altra installazione.
 *
 * Serve perché il tetto dell'API è per CHIAVE e non per macchina: si può
 * scaricare comodamente in locale — dove il tempo di esecuzione non ha limiti e
 * si può lasciar macinare per giorni — e poi caricare il pacchetto sul server
 * senza spendere una sola chiamata in più.
 *
 * Si esportano solo i **dati del mondo**: squadre, giocatori, listone,
 * calendario e statistiche. Quello che appartiene a una lega — voti, power,
 * carte, sfide — resta fuori di proposito: dipende dalle regole del gruppo e si
 * ricalcola da solo giocando le giornate. Portarselo dietro significherebbe
 * trapiantare la partita di qualcun altro.
 *
 * Il formato è JSON per righe (una per riga, niente array gigante): si legge a
 * blocchi senza tenere in memoria quarantamila statistiche, e si può guardare
 * con un editor se qualcosa non torna.
 */
class EsportaAnnata extends Command
{
    use Concerns\RaccontaLeAnnate;

    protected $signature = 'annata:esporta
        {anno : Annata da impacchettare, es. 2023}
        {--out= : Dove scrivere, altrimenti in storage/app}
        {--con-simulate : Includi anche le giornate simulate}';

    protected $description = 'Impacchetta squadre, listone, calendario e statistiche di un\'annata';

    public function handle(): int
    {
        $anno = (int) $this->argument('anno');

        if ($this->calendarioMancante($anno)) {
            $this->spiegaAnnataAssente($anno);

            return self::FAILURE;
        }

        $file = $this->option('out') ?: storage_path("app/serie-a-{$anno}.jsonl");
        File::ensureDirectoryExists(dirname($file));

        $maniglia = fopen($file, 'w');

        $scrivi = function (string $tipo, array $riga) use ($maniglia) {
            fwrite($maniglia, json_encode(['t' => $tipo] + $riga, JSON_UNESCAPED_UNICODE)."\n");
        };

        $scrivi('meta', [
            'annata' => $anno,
            'versione' => 1,
            'generato' => now()->toIso8601String(),
        ]);

        $conteggi = [];

        // Le squadre non hanno annata: si esportano solo quelle che compaiono
        // nel listone di quest'anno, per non trascinarsi dietro campionati
        // interi che l'altra installazione non sta giocando.
        $squadre = PlayerSeason::where('season', $anno)->distinct()->pluck('team_id')
            ->merge(Fixture::where('season', $anno)->pluck('home_team_id'))
            ->merge(Fixture::where('season', $anno)->pluck('away_team_id'))
            ->unique()->filter();

        $conteggi['squadre'] = 0;

        Team::whereIn('id', $squadre)->each(function (Team $t) use ($scrivi, &$conteggi) {
            $scrivi('team', $t->only(['id', 'name', 'code', 'logo_url', 'color']));
            $conteggi['squadre']++;
        });

        // L'identità dei giocatori: permanente, ma si esportano solo quelli che
        // servono a quest'annata.
        $ids = PlayerSeason::where('season', $anno)->pluck('player_id')
            ->merge(PlayerStat::where('season', $anno)->pluck('player_id'))
            ->unique();

        $conteggi['giocatori'] = 0;

        Player::whereIn('id', $ids)->chunkById(1000, function ($blocco) use ($scrivi, &$conteggi) {
            foreach ($blocco as $p) {
                $scrivi('player', $p->only(['id', 'first_name', 'last_name', 'photo_url', 'photo_verified']));
                $conteggi['giocatori']++;
            }
        });

        $conteggi['listone'] = 0;

        PlayerSeason::where('season', $anno)->chunkById(1000, function ($blocco) use ($scrivi, &$conteggi) {
            foreach ($blocco as $r) {
                $scrivi('player_season', $r->only([
                    'player_id', 'season', 'team_id', 'role',
                    'role_confirmed', 'quotazione_iniziale', 'active',
                ]));
                $conteggi['listone']++;
            }
        });

        $conteggi['partite'] = 0;

        Fixture::where('season', $anno)->chunkById(1000, function ($blocco) use ($scrivi, &$conteggi) {
            foreach ($blocco as $f) {
                $scrivi('fixture', $f->only([
                    'id', 'season', 'matchday', 'home_team_id', 'away_team_id',
                    'status', 'home_goals', 'away_goals',
                ]) + [
                    // ⚠️ Formattata a mano e non lasciata a json_encode: un
                    // Carbon si serializza in ISO-8601 coi microsecondi e la Z
                    // finale, e MariaDB rifiuta quella forma con un errore
                    // secco. SQLite invece la accetterebbe — è testo — quindi
                    // il pacchetto sembrerebbe buono fino al giorno in cui lo
                    // si importa sul server vero.
                    'kickoff_at' => $f->kickoff_at?->format('Y-m-d H:i:s'),
                ]);
                $conteggi['partite']++;
            }
        });

        $conteggi['statistiche'] = 0;

        PlayerStat::where('season', $anno)
            // Le simulate restano fuori se non le si chiede: sono dati inventati
            // e trapiantarli su un'altra installazione vorrebbe dire spacciarli
            // per veri proprio dove nessuno sa che non lo sono.
            ->when(! $this->option('con-simulate'), fn ($q) => $q->where('source', 'reale'))
            ->chunkById(2000, function ($blocco) use ($scrivi, &$conteggi) {
                foreach ($blocco as $s) {
                    $scrivi('player_stat', $s->only([
                        'player_id', 'season', 'matchday', 'source', 'minutes', 'rating',
                        'goals', 'assists', 'goals_conceded', 'yellow', 'red',
                        'own_goals', 'pen_scored', 'pen_missed', 'pen_saved',
                    ]));
                    $conteggi['statistiche']++;
                }
            });

        fclose($maniglia);

        $giornate = PlayerStat::where('season', $anno)
            ->when(! $this->option('con-simulate'), fn ($q) => $q->where('source', 'reale'))
            ->distinct()->count('matchday');

        $this->info('Scritto in '.$file.' ('.$this->peso($file).')');
        $this->newLine();

        foreach ($conteggi as $cosa => $quante) {
            $this->line(sprintf('  %-13s %6d', $cosa, $quante));
        }

        $this->line(sprintf('  %-13s %6d', 'giornate', $giornate));
        $this->newLine();
        $this->line('Caricalo sul server e lancia: php artisan annata:importa <file>');

        return self::SUCCESS;
    }

    private function peso(string $file): string
    {
        $byte = filesize($file) ?: 0;

        return $byte > 1048576
            ? round($byte / 1048576, 1).' MB'
            : round($byte / 1024).' KB';
    }
}
