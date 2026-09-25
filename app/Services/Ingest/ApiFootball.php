<?php

namespace App\Services\Ingest;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client di API-Football v3.
 *
 * ⚠️ Il tranello di questa API: un errore arriva con HTTP 200 e il messaggio
 * dentro `errors`. Fidarsi del codice di stato significa scrivere in database
 * una risposta vuota credendo che la giornata non abbia avuto partite. Qui
 * `errors` non vuoto è un fallimento, punto.
 *
 * Ogni modo di fallire — chiave mancante, rete giù, 500, errore applicativo —
 * esce da qui come `RuntimeException`. È ciò che permette ai comandi di
 * mostrare una riga di errore leggibile invece di una traccia di stack: il
 * `RequestException` di Laravel non discende da RuntimeException e sarebbe
 * sfuggito a ogni catch.
 */
class ApiFootball
{
    /** Istante dell'ultima chiamata, condiviso: il tetto è per chiave, non per istanza. */
    private static ?float $ultimaChiamata = null;

    /** Il tetto giornaliero visto per ultimo: identifica il piano, e quindi il freno. */
    private const TETTO = 'apifootball-tetto-giornaliero';

    /**
     * Una chiamata, già scartata dell'involucro.
     *
     * @param  array<string,mixed>  $query
     * @return array<int,mixed> il contenuto di `response`
     */
    public function get(string $path, array $query = []): array
    {
        return $this->call($path, $query)['response'] ?? [];
    }

    /**
     * Come get(), ma seguendo la paginazione.
     *
     * Il listone dei giocatori arriva a blocchi di venti: senza seguire le
     * pagine se ne importerebbe un ventesimo senza accorgersene, perché la
     * prima pagina è una risposta perfettamente valida.
     *
     * ⚠️ `$maxPages` non è un'ottimizzazione: su alcuni endpoint il piano
     * gratuito blocca le pagine oltre la terza, e la pagina di troppo non torna
     * vuota — torna un errore che fa fallire tutta la sincronizzazione. Dove il
     * tetto è noto va dichiarato, invece di scoprirlo a metà strada.
     *
     * @param  array<string,mixed>  $query
     * @return array<int,mixed>
     */
    public function getPaged(string $path, array $query = [], ?int $maxPages = null): array
    {
        $tutte = [];
        $pagina = 1;
        $massimo = $maxPages ?? (int) config('apifootball.max_pages');

        do {
            $payload = $this->call($path, $query + ['page' => $pagina]);
            $tutte = array_merge($tutte, $payload['response'] ?? []);

            $ultima = (int) ($payload['paging']['total'] ?? 1);
            $pagina++;
        } while ($pagina <= $ultima && $pagina <= $massimo);

        return $tutte;
    }

    /**
     * Quante chiamate restano oggi.
     *
     * Costa una chiamata a sua volta, ed e' un affare: sapere in anticipo che
     * il tetto non basta evita di fermarsi a meta' scaricamento, che lascia una
     * giornata con i voti a pezzi — peggio che non averla toccata.
     *
     * @return array{usate: int, tetto: int, restano: int}
     */
    public function quota(): array
    {
        $r = $this->call('/status', [])['response']['requests'] ?? [];

        $usate = (int) ($r['current'] ?? 0);
        $tetto = (int) ($r['limit_day'] ?? 0);

        // Il tetto dice anche quanto si può correre: da qui in poi il freno si
        // tara da solo, e passare a un piano più largo non richiede di
        // ricordarsi di toccare nessuna configurazione.
        if ($tetto > 0) {
            Cache::put(self::TETTO, $tetto, now()->addDay());
        }

        return ['usate' => $usate, 'tetto' => $tetto, 'restano' => max(0, $tetto - $usate)];
    }

    /**
     * Ogni quanto si può chiamare, in millisecondi.
     *
     * Con lo scavalco in `.env` vince quello, sempre: se qualcuno l'ha scritto
     * è perché sa una cosa che questo codice non sa. Altrimenti si guarda il
     * tetto giornaliero visto per ultimo — che identifica il piano — e finché
     * non se ne è visto nessuno si va piano.
     */
    public function intervallo(): int
    {
        if (($scavalco = config('apifootball.min_interval_ms')) !== null) {
            return (int) $scavalco;
        }

        $tetto = Cache::get(self::TETTO);

        if (! $tetto) {
            return (int) config('apifootball.intervallo_prudente');
        }

        // Il piano è quello col tetto più alto fra quelli che non superano il
        // nostro: un tetto sconosciuto ricade così sul piano più stretto sotto
        // di lui, invece che sul più largo.
        $piani = collect(config('apifootball.al_minuto'))->sortKeys();
        $alMinuto = $piani->filter(fn ($q, $limite) => $limite <= $tetto)->last()
            ?? $piani->first();

        $utilizzabili = max(1, $alMinuto * (float) config('apifootball.margine'));

        return (int) ceil(60000 / $utilizzabili);
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed> l'involucro completo
     */
    private function call(string $path, array $query): array
    {
        $this->throttle();

        try {
            $payload = $this->request()->get($path, $query)->throw()->json();
        } catch (RequestException $e) {
            throw new RuntimeException(
                "API-Football non ha risposto su {$path} (HTTP {$e->response->status()}).",
                previous: $e,
            );
        }

        // `errors` è un array vuoto quando va bene e una mappa quando no,
        // quindi non basta guardare se la chiave è definita.
        if (! empty($payload['errors'])) {
            throw new RuntimeException(
                "API-Football ha risposto con un errore su {$path} — ".$this->describe($payload['errors']),
            );
        }

        return $payload ?? [];
    }

    /**
     * Distanzia le chiamate.
     *
     * Il tetto non è solo giornaliero: ce n'è uno al MINUTO, e sul piano
     * gratuito è basso. Una sincronizzazione delle rose fa venti chiamate di
     * fila e se ne prende un 429 a metà strada — lasciando l'anagrafica
     * riempita a metà, che è peggio di non averla toccata affatto.
     *
     * L'attesa è deliberatamente sincrona: questi comandi girano da cron o a
     * mano, nessuno sta guardando, e due minuti di sincronizzazione lenta
     * valgono più di un errore veloce.
     */
    private function throttle(): void
    {
        $intervallo = $this->intervallo();

        if ($intervallo <= 0) {
            return;
        }

        if (self::$ultimaChiamata !== null) {
            $trascorso = (microtime(true) - self::$ultimaChiamata) * 1000;

            if ($trascorso < $intervallo) {
                usleep((int) (($intervallo - $trascorso) * 1000));
            }
        }

        self::$ultimaChiamata = microtime(true);
    }

    private function describe(mixed $errors): string
    {
        if (! is_array($errors)) {
            return (string) $errors;
        }

        return implode('; ', array_map(
            fn ($valore, $chiave) => is_string($chiave) ? "{$chiave}: {$valore}" : (string) $valore,
            $errors,
            array_keys($errors),
        ));
    }

    private function request(): PendingRequest
    {
        $key = config('apifootball.key');

        if (blank($key)) {
            throw new RuntimeException('Manca API_FOOTBALL_KEY: senza chiave non si sincronizza niente.');
        }

        return Http::baseUrl(config('apifootball.base_url'))
            ->withHeaders(['x-apisports-key' => $key])
            ->withOptions($this->sslOptions())
            ->timeout((int) config('apifootball.timeout'))
            ->retry(3, 1000, throw: false);
    }

    /**
     * PHP su Windows non porta con sé un bundle di CA.
     *
     * Senza questo, in locale ogni chiamata HTTPS muore con «unable to get
     * local issuer certificate» — che sembra un problema di rete e non lo è.
     * I certificati ci sono, stanno nello store di sistema: basta dire a curl
     * di usarlo. Su Linux, dove il bundle è al suo posto, non serve.
     *
     * @return array<string,mixed>
     */
    private function sslOptions(): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! defined('CURLSSLOPT_NATIVE_CA')) {
            return [];
        }

        return ['curl' => [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA]];
    }
}
