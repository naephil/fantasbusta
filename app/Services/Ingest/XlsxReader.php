<?php

namespace App\Services\Ingest;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lettore minimo di file .xlsx.
 *
 * Un xlsx è uno zip di XML, e per leggere una tabella piatta bastano due
 * pezzi: il foglio e la tabella delle stringhe condivise. Sessanta righe di
 * codice contro una dipendenza da un lettore di fogli di calcolo completo, per
 * un import che si fa una volta l'anno — non regge il confronto.
 *
 * Legge valori, non formule né formati: al listone serve esattamente questo.
 */
final class XlsxReader
{
    /** Lo spazio dei nomi in cui vive l'attributo `r:id` di ogni foglio. */
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * I nomi dei fogli, nell'ordine in cui stanno nel file.
     *
     * Serve a chi deve SCEGLIERE un foglio senza sapere in anticipo se c'è: il
     * listone ufficiale ne ha sei — «Tutti» più uno per reparto più i ceduti —
     * mentre un export fatto in casa ne ha uno solo e senza nome utile.
     *
     * @return list<string>
     */
    public static function sheetNames(string $path): array
    {
        $zip = self::open($path);
        $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml') ?: '');
        $nomi = [];

        foreach ($workbook->sheets->sheet ?? [] as $foglio) {
            $nomi[] = (string) $foglio['name'];
        }

        $zip->close();

        return $nomi;
    }

    /**
     * @param  string|null  $foglio  nome del foglio; il primo se non indicato
     * @return list<list<string>> righe, ciascuna con le celle come stringhe
     */
    public static function rows(string $path, ?string $foglio = null): array
    {
        $zip = self::open($path);

        $stringhe = self::sharedStrings($zip);
        $xml = self::sheetXml($zip, $foglio);
        $righe = [];

        foreach ($xml->sheetData->row as $riga) {
            $righe[] = self::cells($riga, $stringhe);
        }

        $zip->close();

        return $righe;
    }

    /**
     * Le celle di una riga, con i buchi riempiti.
     *
     * Excel omette del tutto le celle vuote: una riga con A, B e D scrive tre
     * celle, non quattro. Senza guardare il riferimento (`r="D3"`) la D
     * finirebbe nella colonna C e tutta la riga slitterebbe di uno — che è il
     * modo più silenzioso possibile di importare quotazioni sbagliate.
     *
     * @param  list<string>  $stringhe
     * @return list<string>
     */
    private static function cells(SimpleXMLElement $riga, array $stringhe): array
    {
        $celle = [];

        foreach ($riga->c as $cella) {
            $indice = self::columnIndex((string) $cella['r']);
            $tipo = (string) $cella['t'];

            $celle[$indice] = match ($tipo) {
                's' => $stringhe[(int) $cella->v] ?? '',
                'inlineStr' => trim((string) $cella->is->t),
                default => (string) $cella->v,
            };
        }

        if ($celle === []) {
            return [];
        }

        // Riempie i buchi e riordina: le celle arrivano in ordine di colonna,
        // ma solo quelle che esistono.
        return array_values(array_replace(
            array_fill(0, max(array_keys($celle)) + 1, ''),
            $celle,
        ));
    }

    /** «AB12» → 27. Le colonne sono in base 26 con lettere. */
    private static function columnIndex(string $riferimento): int
    {
        preg_match('/^([A-Z]+)/', $riferimento, $m);

        $indice = 0;

        foreach (str_split($m[1] ?? 'A') as $lettera) {
            $indice = $indice * 26 + (ord($lettera) - 64);
        }

        return $indice - 1;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $grezzo = $zip->getFromName('xl/sharedStrings.xml');

        if ($grezzo === false) {
            return [];
        }

        $stringhe = [];

        foreach (simplexml_load_string($grezzo)->si as $si) {
            // Il testo può essere in un solo nodo `t`, oppure spezzato in più
            // frammenti `r` quando dentro la cella cambia la formattazione.
            $stringhe[] = isset($si->t)
                ? (string) $si->t
                : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r)));
        }

        return $stringhe;
    }

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException("Non riesco ad aprire {$path}: non sembra un file xlsx.");
        }

        return $zip;
    }

    /**
     * Il foglio richiesto, o il primo del file.
     *
     * ⚠️ La posizione nel workbook **non** dice il nome del file: il terzo
     * foglio non è necessariamente `sheet3.xml`. Il legame passa per l'`r:id`
     * di ciascun foglio, che i `_rels` traducono nel percorso vero, e Excel lo
     * rimescola volentieri quando un foglio viene spostato o cancellato.
     *
     * Dedurlo dalla posizione funziona sui file appena generati — dove i due
     * ordini coincidono — e sbaglia in silenzio su quelli rimaneggiati: al
     * posto di «Tutti» si leggerebbe «Portieri», cioè un listone di sessanta
     * righe che sembra riuscito. È il motivo per cui vale la deviazione dai
     * `_rels` invece della stringa costruita a mano.
     */
    private static function sheetXml(ZipArchive $zip, ?string $foglio): SimpleXMLElement
    {
        $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml') ?: '');
        $percorsi = self::relationships($zip);
        $primo = null;

        foreach ($workbook->sheets->sheet ?? [] as $s) {
            $rid = (string) $s->attributes(self::NS_REL)['id'];
            $percorso = $percorsi[$rid] ?? null;

            if ($percorso === null) {
                continue;
            }

            $primo ??= $percorso;

            if ($foglio !== null && strcasecmp((string) $s['name'], $foglio) === 0) {
                return self::caricaFoglio($zip, $percorso);
            }
        }

        if ($foglio !== null) {
            throw new RuntimeException("Il file non contiene un foglio «{$foglio}».");
        }

        if ($primo === null) {
            throw new RuntimeException('Il file xlsx non contiene fogli leggibili.');
        }

        return self::caricaFoglio($zip, $primo);
    }

    /**
     * `r:id` → percorso dentro lo zip.
     *
     * @return array<string,string>
     */
    private static function relationships(ZipArchive $zip): array
    {
        $grezzo = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($grezzo === false) {
            return [];
        }

        $percorsi = [];

        foreach (simplexml_load_string($grezzo)->Relationship as $r) {
            $target = (string) $r['Target'];

            // Il target è di norma relativo a `xl/`, ma la specifica ammette
            // anche la forma assoluta dalla radice del pacchetto.
            $percorsi[(string) $r['Id']] = str_starts_with($target, '/')
                ? ltrim($target, '/')
                : 'xl/'.$target;
        }

        return $percorsi;
    }

    private static function caricaFoglio(ZipArchive $zip, string $percorso): SimpleXMLElement
    {
        $grezzo = $zip->getFromName($percorso);

        if ($grezzo === false) {
            throw new RuntimeException("Il file xlsx dichiara un foglio in {$percorso}, che però non contiene.");
        }

        return simplexml_load_string($grezzo);
    }
}
