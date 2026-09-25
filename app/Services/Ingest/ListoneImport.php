<?php

namespace App\Services\Ingest;

use App\Models\PlayerSeason;
use App\Models\Team;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Importa il listone di un'annata: ruolo e quotazione iniziale.
 *
 * Il listone è la fonte autoritativa per queste due colonne, perché l'API non
 * conosce il ruolo fantacalcio. L'abbinamento con l'id API-Football si fa una
 * volta a inizio stagione, su cognome e squadra.
 *
 * ⚠️ Nessun abbinamento fatto qui va considerato verificato. Un id sbagliato
 * non si annuncia: se punta a un giocatore inesistente dà 404 e lo vedi
 * subito, ma se punta a un giocatore *diverso* restituisce una faccia
 * plausibile e supera qualunque controllo automatico. Per questo l'import
 * scrive `role_confirmed` ma non tocca `photo_verified`: il primo dice che il
 * ruolo viene dal listone, il secondo che un umano ha guardato la faccia. Vedi
 * docs/DESIGN.md §6.1.
 *
 * Legge sia CSV sia XLSX: il listone ufficiale è un Excel e chiedere una
 * conversione manuale a ogni stagione sarebbe un attrito inutile.
 */
class ListoneImport
{
    /**
     * Intestazioni accettate, in ordine di PREFERENZA e non di colonna.
     *
     * L'ordine conta: il listone ufficiale ha sia `Qt.A` (attuale) sia `Qt.I`
     * (iniziale), e la colonna che ci interessa è la seconda anche se sta
     * più a destra.
     *
     * ⚠️ La colonna `Id` del listone ufficiale NON è qui, e l'omissione è
     * deliberata: quell'id è di Fantacalcio.it, non di API-Football. Sono due
     * spazi di identificativi diversi, e usarlo come chiave abbinerebbe
     * giocatori a caso — in silenzio, perché un id valido nell'altro spazio
     * esiste quasi sempre. Solo un'intestazione che dichiara esplicitamente
     * l'origine API viene creduta.
     */
    private const COLONNE = [
        'id' => ['id_api', 'api_id', 'id_apifootball'],
        'ruolo' => ['ruolo', 'r'],
        'nome' => ['nome', 'giocatore', 'cognome'],
        'squadra' => ['squadra', 'team'],
        'quotazione' => ['qt.i', 'quotazione_iniziale', 'qti', 'quotazione', 'qt.a', 'qt', 'qta'],
    ];

    /** I soli ruoli che la colonna `role` accetta. */
    private const RUOLI = ['P', 'D', 'C', 'A'];

    /**
     * Il foglio da leggere quando il file ne ha più d'uno.
     *
     * Il listone ufficiale è diviso in sei fogli: «Tutti» più uno per reparto,
     * più i ceduti. Leggerne uno qualsiasi darebbe un listone parziale che
     * sembra riuscito — «64 abbinati» è un numero plausibile, e nessuno va a
     * contare che i portieri di Serie A non sono l'intero campionato.
     */
    private const FOGLIO = 'Tutti';

    /**
     * @return array{abbinati: int, ambigui: list<string>, mancanti: list<string>}
     */
    public function fromCsv(string $path, int $season): array
    {
        return $this->fromFile($path, $season);
    }

    /**
     * @return array{abbinati: int, ambigui: list<string>, mancanti: list<string>}
     */
    public function fromFile(string $path, int $season): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Listone illeggibile: {$path}");
        }

        $righe = $this->parse($path);

        if ($righe->isEmpty()) {
            throw new RuntimeException('Il listone non contiene righe utilizzabili.');
        }

        // Si lavora sul listone di QUELL'ANNO: ruolo e quotazione appartengono
        // all'annata, non alla persona. Chi non è in `player_seasons` non c'era
        // e finisce fra i mancanti — il che è l'informazione utile, perché
        // significa che il sync anagrafico va rifatto prima dell'import.
        $giocatori = PlayerSeason::with('player')->where('season', $season)->get();
        $squadre = Team::all();

        $abbinati = 0;
        $ambigui = [];
        $mancanti = [];

        foreach ($righe as $riga) {
            $esito = $this->match($riga, $giocatori, $squadre);

            if ($esito instanceof PlayerSeason) {
                $dati = [
                    'role' => $riga['ruolo'],
                    'role_confirmed' => true,
                    'quotazione_iniziale' => $riga['quotazione'] ?: 1,
                ];

                // ⚠️ Anche la SQUADRA viene dal listone, e non è un dettaglio.
                // L'anagrafica di API-Football dà la rosa in cui il giocatore è
                // registrato, che a stagione iniziata può non essere quella in
                // cui gioca: nel 2023/24 Lukaku risultava all'Inter — che ne
                // deteneva il cartellino — mentre il listone lo dà alla Roma,
                // dove ha giocato ed è quotato.
                //
                // Non è solo una didascalia sbagliata sulla carta: la giornata
                // di Serie A raggruppa i giocatori per `team_id`, quindi con la
                // squadra dell'API Lukaku compariva sotto la partita dell'Inter.
                // Il listone è la fonte autoritativa dell'annata, e lo è per
                // tutte e tre le colonne insieme.
                if ($squadraId = $this->teamId($riga['squadra'], $squadre)) {
                    $dati['team_id'] = $squadraId;
                }

                $esito->update($dati);

                $abbinati++;

                continue;
            }

            $etichetta = "{$riga['nome']} ({$riga['squadra']})";
            $esito === 'ambiguo' ? $ambigui[] = $etichetta : $mancanti[] = $etichetta;
        }

        return compact('abbinati', 'ambigui', 'mancanti');
    }

    /**
     * L'abbinamento, dal criterio più sicuro al più fragile.
     *
     * @param  Collection<int,PlayerSeason>  $giocatori
     * @param  Collection<int,Team>  $squadre
     * @return PlayerSeason|string la riga di listone, oppure 'ambiguo' | 'mancante'
     */
    private function match(array $riga, Collection $giocatori, Collection $squadre): PlayerSeason|string
    {
        // 1. L'id, quando il listone ce l'ha già: nessuna ambiguità possibile.
        if ($riga['id'] && $trovato = $giocatori->firstWhere('player_id', (int) $riga['id'])) {
            return $trovato;
        }

        $cognome = $this->normalize($riga['nome']);

        if ($cognome === '') {
            return 'mancante';
        }

        $parole = $this->parole($riga['nome']);

        $perCognome = $giocatori->filter(
            fn (PlayerSeason $p) => $this->stessoCognome($parole, $p->player->last_name),
        );

        // 2. Cognome più squadra: è il criterio che regge i casi di omonimia,
        //    che in Serie A non sono rari.
        $squadraId = $this->teamId($riga['squadra'], $squadre);

        if ($squadraId) {
            $conSquadra = $perCognome->where('team_id', $squadraId);

            if ($conSquadra->count() === 1) {
                return $conSquadra->first();
            }
        }

        // 3. Cognome da solo, ma solo se non lascia scelta.
        return match ($perCognome->count()) {
            0 => 'mancante',
            1 => $perCognome->first(),
            default => 'ambiguo',
        };
    }

    /** @param  Collection<int,Team>  $squadre */
    private function teamId(string $nome, Collection $squadre): ?int
    {
        $cercato = $this->normalize($nome);

        if ($cercato === '') {
            return null;
        }

        // Il listone scrive «Inter», l'API «Inter» ma anche «AC Milan» dove il
        // listone dice «Milan»: il confronto per contenimento copre entrambi.
        $trovata = $squadre->first(
            fn (Team $t) => str_contains($this->normalize($t->name), $cercato)
                || str_contains($cercato, $this->normalize($t->name)),
        );

        return $trovata?->id;
    }

    /**
     * Due cognomi sono lo stesso cognome.
     *
     * ⚠️ Si confrontano PAROLE INTERE, e la differenza non è teorica. Prima
     * bastava che una stringa contenesse l'altra, e su un'anagrafica da mille
     * righe quel criterio produceva abbinamenti sbagliati in silenzio:
     *
     *     «Nandez»  dentro «Hernández»          → due candidati, riga scartata
     *     «Martin»  dentro «Álvarez Martínez»   → il Martin del Genoa preso
     *                                             per l'Álvarez del Sassuolo
     *     «Dest»    dentro «Destro»             → Dest abbinato a Destro
     *
     * Il caso vero da coprire sono i cognomi composti: l'API scrive «Selvaag
     * Solbakken» e «Kalulu Kyatengwa» dove il listone scrive «Solbakken» e
     * «Kalulu». Quello è un pezzo intero del nome, non una sottostringa — e per
     * riconoscerlo basta guardare le parole.
     *
     * Le parole da tre lettere in giù restano fuori: sono le particelle («van»,
     * «de», «dos») e le iniziali puntate che il listone usa per disambiguare
     * («Hernandez T.»), e da sole non identificano nessuno.
     *
     * @param  list<string>  $parole  del listone, già normalizzate
     */
    private function stessoCognome(array $parole, string $altro): bool
    {
        $sue = $this->parole($altro);

        if ($sue === [] || $parole === []) {
            return false;
        }

        // Il nome intero identico passa comunque, anche se corto: copre un
        // cognome di tre lettere, che le parole da sole scarterebbero.
        if (implode($parole) === implode($sue)) {
            return true;
        }

        $comuni = array_intersect($parole, $sue);

        return collect($comuni)->contains(fn (string $p) => mb_strlen($p) >= 4);
    }

    /**
     * Le parole di un nome, normalizzate.
     *
     * «D'Ambrosio» → ['dambrosio'], «Hernandez T.» → ['hernandez', 't'].
     *
     * @return list<string>
     */
    private function parole(string $valore): array
    {
        // Lo spazio separa, il resto della punteggiatura sparisce: l'apostrofo
        // sta DENTRO il cognome («D'Ambrosio» è una parola sola), il punto no.
        $pulito = preg_replace('/[^a-z ]/', '', str_replace("'", '', $this->senzaAccenti($valore)));

        return array_values(array_filter(explode(' ', $pulito ?? ''), fn (string $p) => $p !== ''));
    }

    /** Minuscolo, senza accenti e senza punteggiatura: «D'Ambrosio» e «dambrosio». */
    private function normalize(string $valore): string
    {
        return preg_replace('/[^a-z]/', '', $this->senzaAccenti($valore)) ?? '';
    }

    /**
     * Minuscolo e senza segni diacritici.
     *
     * L'elenco copre l'italiano e le lingue da cui arrivano i cognomi della
     * Serie A: senza, `preg_replace` cancellerebbe il carattere accentato
     * invece di sostituirlo — «Vlašić» diventerebbe «vlai» e non somiglierebbe
     * più a «Vlasic» del listone.
     */
    private function senzaAccenti(string $valore): string
    {
        return strtr(mb_strtolower(trim($valore)), [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ő' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
            'ç' => 'c', 'ć' => 'c', 'č' => 'c',
            'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
            'š' => 's', 'ś' => 's', 'ş' => 's', 'ș' => 's',
            'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
            'ł' => 'l', 'ð' => 'd', 'đ' => 'd', 'ď' => 'd',
            'ť' => 't', 'ţ' => 't', 'ț' => 't',
            'ř' => 'r', 'ý' => 'y', 'ÿ' => 'y', 'ğ' => 'g', 'ħ' => 'h', 'ı' => 'i',
            'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
        ]);
    }

    /**
     * @return Collection<int,array{id: string, ruolo: string, nome: string, squadra: string, quotazione: float}>
     */
    private function parse(string $path): Collection
    {
        $grezze = match ($this->formato($path)) {
            'xlsx' => XlsxReader::rows($path, $this->foglio($path)),
            'xls' => throw new RuntimeException(
                'Questo è un .xls del vecchio formato binario, che non so leggere. '
                .'Riaprilo e salvalo come .xlsx — «Salva con nome» → «Cartella di lavoro di Excel».',
            ),
            default => $this->csvRows($path),
        };

        [$intestazione, $dallaRiga] = $this->findHeader($grezze);
        $righe = collect();

        foreach (array_slice($grezze, $dallaRiga) as $campi) {
            $riga = $this->row($campi, $intestazione);

            if ($riga['ruolo'] !== '' && $riga['nome'] !== '') {
                $righe->push($riga);
            }
        }

        $this->assertRuoli($righe);

        return $righe;
    }

    /**
     * Che file è, guardandoci dentro invece che al nome.
     *
     * ⚠️ **Dal nome non si può dedurre.** Un listone caricato dalla pagina di
     * gestione arriva come file temporaneo di PHP — `C:\…\Temp\php1A2B.tmp` —
     * e di `.xlsx` non ha più traccia: `getRealPath()` dà il temporaneo, non il
     * nome scelto dall'utente. Decidendo dall'estensione ogni upload finiva nel
     * lettore CSV, che macinava lo zip come se fosse testo e produceva righe di
     * byte a caso; l'errore che ne usciva — «non trovo le colonne ruolo e
     * nome» — mandava a cercare il guasto dentro il file, che era invece
     * perfetto. Da riga di comando funzionava, il che rendeva la cosa
     * irriproducibile proprio dove si provava a riprodurla.
     *
     * I primi byte invece non mentono: un xlsx è uno zip e comincia per `PK`,
     * un .xls del vecchio formato è un contenitore OLE2.
     */
    private function formato(string $path): string
    {
        $magia = (string) file_get_contents($path, false, null, 0, 8);

        return match (true) {
            str_starts_with($magia, "PK\x03\x04") => 'xlsx',
            str_starts_with($magia, "\xD0\xCF\x11\xE0") => 'xls',
            default => 'csv',
        };
    }

    /**
     * Quale foglio leggere: «Tutti» se c'è, altrimenti il primo.
     *
     * Il ripiego sul primo non è pigrizia — un listone esportato a mano ha un
     * foglio solo, chiamato «Foglio1» o peggio — ma la preferenza va dichiarata
     * qui e non lasciata all'ordine dei fogli, che non è una garanzia.
     */
    private function foglio(string $path): ?string
    {
        foreach (XlsxReader::sheetNames($path) as $nome) {
            if (strcasecmp($nome, self::FOGLIO) === 0) {
                return $nome;
            }
        }

        return null;
    }

    /**
     * I ruoli devono essere P, D, C o A.
     *
     * Si controlla prima di scrivere perché `role` è una enum del database: un
     * valore fuori elenco non produce un errore comprensibile, produce un
     * errore SQL a metà import, con metà listone già scritto.
     *
     * Il caso che capita davvero è la colonna sbagliata. Il listone ufficiale
     * ne ha due — `R` col ruolo classico e `RM` col ruolo Mantra — e quella
     * giusta è la prima; se si finisce sulla seconda arrivano «Por», «Dc»,
     * «W», e il messaggio deve dirlo invece di far indovinare.
     *
     * @param  Collection<int,array{ruolo: string}>  $righe
     */
    private function assertRuoli(Collection $righe): void
    {
        $estranei = $righe->pluck('ruolo')->unique()->diff(self::RUOLI)->values();

        if ($estranei->isEmpty()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Ruoli non riconosciuti nel listone: %s. Attesi solo P, D, C, A — '
            .'nel listone ufficiale è la colonna «R», non «RM», che porta il ruolo Mantra.',
            $estranei->take(5)->map(fn (string $r) => "«{$r}»")->implode(', '),
        ));
    }

    /**
     * Trova la riga di intestazione, anche se non è la prima.
     *
     * Il listone ufficiale apre con un titolo su riga singola — «Quotazioni
     * Fantacalcio Stagione 2026 27» — e le intestazioni vere stanno sotto.
     * Dare per scontata la prima riga significherebbe leggere il titolo come
     * intestazione e non trovare nessuna colonna.
     *
     * @param  list<list<string>>  $righe
     * @return array{array<string,int>, int} mappa colonne, indice della prima riga di dati
     */
    private function findHeader(array $righe): array
    {
        foreach ($righe as $i => $campi) {
            $mappa = $this->mapHeader($campi);

            if (isset($mappa['ruolo'], $mappa['nome'])) {
                return [$mappa, $i + 1];
            }

            if ($i > 10) {
                break;   // se non è nelle prime righe, non c'è
            }
        }

        throw new RuntimeException(
            'Non trovo le colonne «ruolo» e «nome» nelle prime righe del listone.',
        );
    }

    /**
     * @param  list<string>  $campi
     * @return array<string,int> nome logico => indice di colonna
     */
    private function mapHeader(array $campi): array
    {
        $titoli = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $campi);
        $mappa = [];

        // Si scorrono gli ALIAS in ordine, non le colonne: il primo alias
        // trovato vince, così `Qt.I` batte `Qt.A` anche se sta più a destra.
        foreach (self::COLONNE as $logico => $alias) {
            foreach ($alias as $nome) {
                $indice = array_search($nome, $titoli, true);

                if ($indice !== false) {
                    $mappa[$logico] = $indice;

                    break;
                }
            }
        }

        return $mappa;
    }

    /** @return list<list<string>> */
    private function csvRows(string $path): array
    {
        $handle = fopen($path, 'r');
        $delimitatore = $this->delimiter($path);
        $righe = [];

        while (($campi = fgetcsv($handle, 0, $delimitatore)) !== false) {
            if ($campi !== [null] && $campi !== []) {
                $righe[] = array_map(fn ($c) => (string) $c, $campi);
            }
        }

        fclose($handle);

        return $righe;
    }

    /** @param  array<string,int>  $intestazione */
    private function row(array $campi, array $intestazione): array
    {
        $prendi = fn (string $chiave) => isset($intestazione[$chiave])
            ? trim((string) ($campi[$intestazione[$chiave]] ?? ''))
            : '';

        return [
            'id' => $prendi('id'),
            // Normalizzato qui e non al momento di scrivere: così il controllo
            // sui ruoli ammessi e la scrittura guardano lo stesso valore.
            'ruolo' => mb_strtoupper($prendi('ruolo')),
            'nome' => $prendi('nome'),
            'squadra' => $prendi('squadra'),
            // I listoni italiani usano la virgola decimale.
            'quotazione' => (float) str_replace(',', '.', $prendi('quotazione')),
        ];
    }

    /**
     * Excel esporta col punto e virgola in locale italiano, con la virgola altrove.
     *
     * Si guardano le prime righe e non solo la prima: il listone apre con un
     * titolo che non contiene nessun separatore, e dedurlo da lì significa
     * sceglierne uno a caso e leggere l'intero file come una colonna sola.
     */
    private function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $campione = '';

        for ($i = 0; $i < 5 && ($riga = fgets($handle)) !== false; $i++) {
            $campione .= $riga;
        }

        fclose($handle);

        return substr_count($campione, ';') > substr_count($campione, ',') ? ';' : ',';
    }
}
