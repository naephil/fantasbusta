<?php

namespace App\Console\Commands;

use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Support\Battito;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Il giro di controllo prima di dare l'indirizzo a qualcuno.
 *
 * Esiste perché gli errori di messa in linea non si annunciano: `APP_DEBUG`
 * lasciato acceso non rompe niente, funziona benissimo — mostra solo il
 * contenuto di `.env` alla prima pagina che va storta. Lo stesso vale per il
 * link di storage mancante (gli stemmi non si vedono e sembra un problema di
 * grafica) e per la password dell'admin lasciata a «password».
 *
 * Ogni controllo dice cosa fare, non solo cosa non va: a chi sta pubblicando
 * serve il rimedio, non la diagnosi.
 */
class ControllaProduzione extends Command
{
    protected $signature = 'controlla';

    protected $description = 'Verifica che tutto sia a posto prima di aprire il sito agli altri';

    private int $problemi = 0;

    private int $avvisi = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>Controllo prima della pubblicazione</>');
        $this->newLine();

        $this->ambiente();
        $this->database();
        $this->fileEStorage();
        $this->accessi();
        $this->gioco();

        $this->newLine();

        if ($this->problemi > 0) {
            $this->error("  {$this->problemi} cose da sistemare prima di aprire il sito.");

            return self::FAILURE;
        }

        if ($this->avvisi > 0) {
            $this->warn("  Tutto in piedi, ma ci sono {$this->avvisi} cose da guardare.");

            return self::SUCCESS;
        }

        $this->info('  Tutto a posto. Puoi dare l\'indirizzo.');

        return self::SUCCESS;
    }

    // ───────────────────────── i controlli ─────────────────────────

    private function ambiente(): void
    {
        $this->titolo('Ambiente');

        $this->esito(
            ! config('app.debug'),
            'APP_DEBUG spento',
            'APP_DEBUG è ACCESO: il primo errore mostrerà .env — password del database e chiave API — a chi lo incontra. Mettilo a false e rilancia `php artisan config:cache`.',
        );

        $this->esito(
            config('app.env') === 'production',
            'APP_ENV = '.config('app.env'),
            'APP_ENV è «'.config('app.env').'» invece di «production».',
            grave: false,
        );

        $this->esito(
            (bool) config('app.key'),
            'APP_KEY presente',
            'APP_KEY vuota: le sessioni non funzioneranno. Lancia `php artisan key:generate`.',
        );

        $this->esito(
            str_starts_with((string) config('app.url'), 'https://'),
            'APP_URL = '.config('app.url'),
            'APP_URL è «'.config('app.url').'»: senza https i link generati nelle email e nei redirect punteranno al posto sbagliato.',
            grave: false,
        );

        $this->esito(
            config('app.timezone') === 'Europe/Rome',
            'Fuso orario '.config('app.timezone'),
            'Il fuso è «'.config('app.timezone').'»: le scadenze del draft appariranno sfasate. Metti APP_TIMEZONE=Europe/Rome.',
            grave: false,
        );

        $this->esito(
            config('app.locale') === 'it',
            'Lingua italiana',
            'La lingua è «'.config('app.locale').'»: gli errori dei moduli usciranno in inglese. Metti APP_LOCALE=it.',
            grave: false,
        );

        $this->esito(
            version_compare(PHP_VERSION, '8.2', '>='),
            'PHP '.PHP_VERSION,
            'PHP '.PHP_VERSION.' è troppo vecchio: serve almeno 8.2.',
        );
    }

    private function database(): void
    {
        $this->titolo('Database');

        try {
            DB::connection()->getPdo();
            $driver = DB::connection()->getDriverName();

            $this->esito(true, 'Connesso ('.$driver.')', '');

            $this->esito(
                in_array($driver, ['mysql', 'mariadb'], true),
                'Driver adatto alla produzione',
                'Il driver è «'.$driver.'»: SQLite non regge più persone che scrivono insieme — i lock non fanno niente. Passa a mariadb.',
            );

            $this->esito(
                Schema::hasTable('league_seasons') && Schema::hasTable('player_stats'),
                'Migrazioni eseguite',
                'Mancano delle tabelle: lancia `php artisan migrate --force`.',
            );

            $this->esito(
                Schema::hasTable('sessions'),
                'Tabella delle sessioni presente',
                'Manca la tabella `sessions` e SESSION_DRIVER è «'.config('session.driver').'»: nessuno riuscirà a restare collegato.',
            );
        } catch (Throwable $e) {
            $this->esito(false, '', 'Il database non risponde: '.$e->getMessage());
        }
    }

    /**
     * Che un file caricato sia davvero servibile, non solo presente.
     *
     * ⚠️ Il link simbolico che c'è non basta, ed è la lezione di un logo che
     * rispondeva «Forbidden» mentre il file stava tranquillo al suo posto. Due
     * modi diversi di rompersi, che dal browser si somigliano:
     *
     *   - `.htaccess` di radice che sbarra tutto il ramo `storage/`. Ma
     *     `/storage/…` è anche l'indirizzo PUBBLICO dei caricati, quindi la
     *     regola che protegge i log seppellisce anche gli stemmi.
     *   - Apache che non segue i link simbolici (`Options -FollowSymLinks`, o
     *     `SymLinksIfOwnerMatch` con proprietari diversi). Frequente su hosting
     *     condiviso, e il messaggio è lo stesso identico: Forbidden.
     *
     * Nessuno dei due si vede finché qualcuno non carica un logo — cioè dopo
     * che il sito è già in mano alle persone.
     */
    private function stemmiRaggiungibili(): void
    {
        $link = public_path('storage');

        if (! file_exists($link)) {
            return;   // già segnalato sopra: manca proprio il link
        }

        $regole = @file_get_contents(base_path('.htaccess')) ?: '';

        // La riga vecchia sbarrava `storage` insieme al resto. Quella nuova
        // nomina i tre rami, quindi la presenza di `storage` fra le alternative
        // di quel gruppo è esattamente il sintomo.
        $sbarraTutto = (bool) preg_match('/RewriteRule \^\([^)]*\bstorage\b[^)]*\)\//', $regole);

        $this->esito(
            ! $sbarraTutto,
            'Gli stemmi caricati non sono sbarrati da .htaccess',
            'Il file .htaccess di radice blocca tutto il ramo `storage/`, che è anche l\'indirizzo pubblico '
                .'degli stemmi: ogni logo caricato risponde «Forbidden». Ricarica la versione aggiornata di .htaccess.',
            grave: false,
        );

        // Il target deve essere leggibile anche da chi non è il proprietario:
        // Apache gira come un altro utente e legge da lì.
        $bersaglio = storage_path('app/public');

        $this->esito(
            is_dir($bersaglio) && (fileperms($bersaglio) & 0o004),
            'La cartella dei caricati è leggibile',
            "La cartella {$bersaglio} non è leggibile dagli altri utenti: Apache non riuscirà a servire "
                .'stemmi e sponsor. Serve permesso 755.',
            grave: false,
        );
    }

    private function fileEStorage(): void
    {
        $this->titolo('File');

        // `file_exists` e non `is_link`: su Windows un symlink creato da Git
        // Bash non viene riconosciuto come tale, e il controllo darebbe un
        // falso allarme proprio a chi sta provando in locale prima di caricare.
        $this->esito(
            file_exists(public_path('storage')),
            'Link di storage presente',
            'Manca `public/storage`: stemmi e sponsor caricati non si vedranno. Lancia `php artisan storage:link`.',
        );

        $this->stemmiRaggiungibili();

        foreach (['storage/logs', 'storage/framework', 'bootstrap/cache'] as $cartella) {
            $this->esito(
                is_writable(base_path($cartella)),
                "Scrivibile: {$cartella}",
                "La cartella {$cartella} non è scrivibile: l'applicazione non potrà nemmeno scrivere un log. Serve permesso 755 e proprietario giusto.",
            );
        }

        // ⚠️ Il controllo che conta davvero, e che nessun altro fa: se .env è
        // raggiungibile dal web, tutto il resto non serve a niente.
        $this->esito(
            ! file_exists(public_path('.env')),
            'Nessun .env dentro public/',
            'C\'è un .env DENTRO public/: è scaricabile da chiunque. Toglilo subito.',
        );

        $this->esito(
            ! is_dir(public_path('vendor')) && ! is_dir(public_path('app')),
            'Il codice non è dentro public/',
            'Ci sono cartelle di progetto dentro public/: la radice del sito deve puntare a public/, non sopra.',
        );

        // ⚠️ Su hosting condiviso con suExec, Apache si rifiuta di servire file
        // scrivibili da tutti e risponde «Forbidden» senza spiegare perché. È
        // il regalo di un archivio creato su Windows, che i permessi unix non
        // ce li ha e ci mette 777 su tutto.
        $scrivibiliDaTutti = collect([base_path(), public_path(), public_path('index.php')])
            ->filter(fn (string $p) => file_exists($p) && (fileperms($p) & 0o002));

        $this->esito(
            $scrivibiliDaTutti->isEmpty(),
            'Permessi ragionevoli',
            'Ci sono file scrivibili da chiunque ('.$scrivibiliDaTutti->map('basename')->implode(', ')
                .'): su hosting condiviso Apache li rifiuta con «Forbidden». Rilancia deploy/pubblica.sh, che li sistema.',
        );

        $this->esito(
            ! file_exists(base_path('.env')) || ! (fileperms(base_path('.env')) & 0o044),
            '.env non leggibile dagli altri utenti',
            'Il .env è leggibile dagli altri utenti della macchina, e contiene password e chiave API. Rimedio: chmod 600 .env',
            grave: false,
        );
    }

    private function accessi(): void
    {
        $this->titolo('Accessi');

        $admin = Manager::where('is_admin', true)->get();

        $this->esito(
            $admin->isNotEmpty(),
            $admin->count().' amministratore/i',
            'Nessun amministratore: nessuno potrà gestire le stagioni. Lancia `php artisan db:seed`.',
        );

        // La password del seeder sta scritta in chiaro nella documentazione:
        // lasciarla è come lasciare la chiave sotto lo zerbino.
        $deboli = $admin->filter(fn (Manager $m) => Hash::check('password', $m->password));

        $this->esito(
            $deboli->isEmpty(),
            'Password di partenza cambiate',
            $deboli->count().' amministratore/i ha ancora la password «password», che sta scritta nella documentazione. Cambiala da /squadra prima di dare l\'indirizzo.',
        );

        $this->esito(
            Manager::count() > 1,
            Manager::count().' squadre',
            'C\'è una sola squadra: aggiungi le persone e i bot da /admin/squadre.',
            grave: false,
        );
    }

    private function gioco(): void
    {
        $this->titolo('Gioco');

        $this->esito(
            (bool) config('apifootball.key'),
            'Chiave API-Football presente',
            'Manca API_FOOTBALL_KEY: non si potranno scaricare né statistiche né listone.',
            grave: false,
        );

        $stagioni = LeagueSeason::where('state', 'in_corso')->count();

        $this->esito(
            $stagioni > 0,
            $stagioni.' stagione/i in corso',
            'Nessuna stagione in corso: creane una da /admin/gestione, altrimenti chi entra non vede niente.',
            grave: false,
        );

        $this->cron();
    }

    /**
     * Il cron, che è l'unico pezzo che vive fuori dall'applicazione.
     *
     * Non si controlla guardando il crontab — da qui non si vede, e comunque
     * una riga presente ma col percorso di PHP sbagliato è indistinguibile da
     * una riga assente. Si controlla dal segno che lo scheduler lascia a ogni
     * giro: quello dice se è passato davvero.
     */
    private function cron(): void
    {
        $this->titolo('Cron');

        match (Battito::stato()) {
            'vivo' => $this->riga('ok', 'Il cron gira: ultimo giro '.Battito::eta()),

            // ⚠️ Non è un problema grave: al primo avvio è normalissimo, ed è
            // proprio quando `pubblica.sh` lancia questo controllo. Segnalarlo
            // come rosso farebbe leggere «pubblicazione fallita» a chi deve
            // solo aspettare un minuto.
            'mai' => $this->esito(false, '', 'Il cron non è mai passato. Se l\'hai appena installato, aspetta un minuto e rilancia `php artisan controlla`. Se invece è già passato un po\', la riga di crontab non fa quello che sembra: controlla il percorso di PHP e che non ci siano ritorni a capo di Windows.', grave: false),

            default => $this->esito(false, '', 'Il cron era attivo ma si è fermato: ultimo giro '.Battito::eta()
                .'. Il draft non avanza più da solo. Controlla che la riga di crontab ci sia ancora.'),
        };
    }

    // ───────────────────────── stampa ─────────────────────────

    private function titolo(string $testo): void
    {
        $this->newLine();
        $this->line("  <fg=gray>── {$testo}</>");
    }

    private function esito(bool $bene, string $ok, string $rimedio, bool $grave = true): void
    {
        if ($bene) {
            $this->riga('ok', $ok);

            return;
        }

        $grave ? $this->problemi++ : $this->avvisi++;
        $this->riga($grave ? 'no' : 'forse', $rimedio);
    }

    private function riga(string $tipo, string $testo): void
    {
        $segno = match ($tipo) {
            'ok' => '<fg=green>  ✓</>',
            'no' => '<fg=red>  ✗</>',
            'forse' => '<fg=yellow>  !</>',
            default => '<fg=gray>   </>',
        };

        $this->line("{$segno} {$testo}");
    }
}
