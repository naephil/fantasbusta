<?php

namespace App\Console\Commands;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Models\Team;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

/**
 * Rimette in casa un'annata impacchettata altrove.
 *
 * È il gemello di `annata:esporta`: si scarica dove fa comodo — di solito in
 * locale, dove il tempo non ha limiti — e si carica dove si gioca, senza
 * spendere una chiamata all'API.
 *
 * Ripetibile: tutto passa da upsert, quindi reimportare un pacchetto più
 * ricco del precedente aggiunge le giornate nuove senza duplicare nulla. È il
 * modo previsto di lavorare, visto che sul piano gratuito l'annata arriva un
 * pezzo al giorno.
 *
 * ⚠️ Non tocca ruolo e quotazione già confermati da un umano: il listone è una
 * decisione, e un pacchetto non ha titolo per sovrascriverla. Se sul server
 * qualcuno ha già sistemato dei ruoli a mano, restano suoi.
 */
class ImportaAnnata extends Command
{
    protected $signature = 'annata:importa
        {file : Il pacchetto prodotto da annata:esporta}
        {--sovrascrivi-listone : Riscrive anche i ruoli confermati a mano}';

    protected $description = 'Rimette in casa squadre, listone, calendario e statistiche impacchettati';

    /** @var array<string,int> */
    private array $conteggi = [];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_readable($file)) {
            $this->error("Pacchetto illeggibile: {$file}");

            return self::FAILURE;
        }

        // ⚠️ Due passate, e non è un vezzo: le chiavi esterne pretendono che
        // squadre e giocatori esistano PRIMA del listone e delle statistiche.
        // Con una passata sola i padri — che sono pochi e non riempiono mai un
        // blocco — resterebbero in coda e verrebbero scritti per ultimi, dopo
        // i figli che li referenziano. Su MariaDB è un errore secco; su SQLite
        // senza vincoli attivi passerebbe, lasciando righe orfane.
        try {
            $meta = $this->passata($file, ['meta', 'team', 'player']);
            $this->passata($file, ['player_season', 'fixture', 'player_stat']);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($meta === null) {
            $this->error('Il pacchetto non ha un\'intestazione: probabilmente non è un file di annata:esporta.');

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($this->conteggi as $cosa => $quante) {
            $this->line(sprintf('  %-14s %6d', $cosa, $quante));
        }

        $anno = (int) $meta['annata'];

        $this->newLine();
        $this->info(sprintf(
            'Annata %d in casa: %d giornate con statistiche su %d in calendario.',
            $anno,
            PlayerStat::where('season', $anno)->distinct()->count('matchday'),
            (int) Fixture::where('season', $anno)->max('matchday'),
        ));

        return self::SUCCESS;
    }

    /**
     * Legge il file scrivendo solo i tipi indicati.
     *
     * Si scorre il file due volte invece di tenerlo in memoria: una stagione
     * intera sono quarantamila statistiche, e caricarle tutte per riordinarle
     * costerebbe decine di megabyte su un hosting che non ne regala.
     *
     * @param  list<string>  $tipi
     * @return array<string,mixed>|null l'intestazione, se richiesta e trovata
     */
    private function passata(string $file, array $tipi): ?array
    {
        $maniglia = fopen($file, 'r');
        $blocchi = [];
        $meta = null;
        $numero = 0;

        try {
            while (($riga = fgets($maniglia)) !== false) {
                $numero++;
                $riga = trim($riga);

                if ($riga === '') {
                    continue;
                }

                try {
                    $dati = json_decode($riga, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    // Il caso tipico non è un file inventato: è un pacchetto
                    // troncato perché il caricamento sul server si è interrotto.
                    // Dirlo con la riga esatta fa risparmiare mezz'ora.
                    throw new RuntimeException(
                        "Il pacchetto non si legge alla riga {$numero}: ".$e->getMessage().
                        ' Se il caricamento sul server si è interrotto, ricaricalo.',
                    );
                }

                $tipo = $dati['t'] ?? null;
                unset($dati['t']);

                if (! in_array($tipo, $tipi, true)) {
                    continue;
                }

                if ($tipo === 'meta') {
                    $meta = $dati;
                    $this->line("Pacchetto: annata {$dati['annata']}, generato il ".substr($dati['generato'], 0, 10));

                    continue;
                }

                // A blocchi e non riga per riga: sono decine di migliaia di
                // statistiche, e una scrittura a testa metterebbe minuti dove
                // bastano secondi.
                $blocchi[$tipo][] = $dati;

                if (count($blocchi[$tipo]) >= 1000) {
                    $this->scarica($tipo, $blocchi[$tipo]);
                    $blocchi[$tipo] = [];
                }
            }

            foreach ($blocchi as $tipo => $righe) {
                if ($righe !== []) {
                    $this->scarica($tipo, $righe);
                }
            }
        } finally {
            fclose($maniglia);
        }

        return $meta;
    }

    /** @param  list<array<string,mixed>>  $righe */
    private function scarica(string $tipo, array $righe): void
    {
        match ($tipo) {
            'team' => Team::upsert($righe, ['id'], ['name', 'code', 'logo_url', 'color']),

            // L'identità si aggiorna, ma `photo_verified` no: dire «è davvero
            // lui» è una verifica fatta su questa installazione, e il pacchetto
            // non sa se qui è già stata fatta.
            'player' => Player::upsert($righe, ['id'], ['first_name', 'last_name', 'photo_url']),

            'player_season' => $this->listone($righe),

            'fixture' => Fixture::upsert($righe, ['id'], [
                'season', 'matchday', 'home_team_id', 'away_team_id',
                'kickoff_at', 'status', 'home_goals', 'away_goals',
            ]),

            'player_stat' => PlayerStat::upsert($righe, ['player_id', 'season', 'matchday'], [
                'source', 'minutes', 'rating', 'goals', 'assists', 'goals_conceded',
                'yellow', 'red', 'own_goals', 'pen_scored', 'pen_missed', 'pen_saved',
            ]),

            default => throw new RuntimeException("Riga di tipo sconosciuto nel pacchetto: {$tipo}"),
        };

        $this->conteggi[$tipo] = ($this->conteggi[$tipo] ?? 0) + count($righe);
    }

    /**
     * Il listone, senza calpestare le decisioni umane.
     *
     * @param  list<array<string,mixed>>  $righe
     */
    private function listone(array $righe): void
    {
        $colonne = ['team_id', 'role', 'role_confirmed', 'quotazione_iniziale', 'active'];

        if ($this->option('sovrascrivi-listone')) {
            PlayerSeason::upsert($righe, ['player_id', 'season'], $colonne);

            return;
        }

        // Chi è già confermato qui non si tocca: ruolo e quotazione sono una
        // decisione dell'amministratore di QUESTA installazione.
        $confermati = PlayerSeason::whereIn('player_id', array_column($righe, 'player_id'))
            ->where('season', $righe[0]['season'] ?? 0)
            ->where('role_confirmed', true)
            ->pluck('player_id')
            ->flip();

        $daScrivere = array_values(array_filter(
            $righe,
            fn (array $r) => ! $confermati->has($r['player_id']),
        ));

        if ($daScrivere !== []) {
            PlayerSeason::upsert($daScrivere, ['player_id', 'season'], $colonne);
        }
    }
}
