<?php

namespace Tests\Feature;

use App\Services\Ingest\ListoneImport;
use App\Services\Ingest\XlsxReader;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * I listoni ufficiali veri, cinque annate di fila.
 *
 * Non provano l'abbinamento — quello vuole un'anagrafica — ma qualcosa che i
 * casi scritti a mano non sanno provare: che il **formato regga**. Il listone
 * lo pubblica un sito che ogni estate può spostare una colonna, rinominare un
 * foglio o cambiare un'intestazione, e l'import si romperebbe una volta l'anno
 * a stagione appena cominciata — nel momento peggiore possibile.
 *
 * Cinque annate danno la varianza vera: 515-560 righe, sempre venti squadre,
 * sempre i quattro reparti. Un file che esce da quei limiti è un file che è
 * cambiato, e vale la pena saperlo prima che lo carichi qualcuno.
 */
class ListoniVeriTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function listoni(): array
    {
        $casi = [];

        foreach (glob(__DIR__.'/../*.xlsx') as $file) {
            $casi[basename($file, '.xlsx')] = [$file];
        }

        return $casi;
    }

    private function righe(string $file)
    {
        $import = new ListoneImport;
        $parse = new ReflectionMethod($import, 'parse');
        $parse->setAccessible(true);

        return $parse->invoke($import, $file);
    }

    #[DataProvider('listoni')]
    public function test_il_listone_si_legge_intero(string $file): void
    {
        $righe = $this->righe($file);

        // Una Serie A sta fra le cinquecento e le seicento righe. Molto meno
        // vuol dire che si è letto un foglio di reparto invece di «Tutti», che
        // è il guasto silenzioso per eccellenza: riesce, e importa un ottavo
        // del campionato.
        $this->assertGreaterThan(450, $righe->count(), 'troppe poche righe: foglio sbagliato?');
        $this->assertLessThan(700, $righe->count());

        $this->assertSame(20, $righe->pluck('squadra')->unique()->count(), 'la Serie A ha venti squadre');
    }

    #[DataProvider('listoni')]
    public function test_ci_sono_tutti_e_quattro_i_reparti(string $file): void
    {
        $ruoli = $this->righe($file)->countBy('ruolo');

        foreach (['P', 'D', 'C', 'A'] as $ruolo) {
            $this->assertGreaterThan(50, $ruoli[$ruolo] ?? 0, "pochi giocatori di ruolo {$ruolo}");
        }

        // I portieri sono il reparto più piccolo, i difensori il più grande:
        // se si ribalta, la colonna del ruolo non è quella che pensiamo.
        $this->assertLessThan($ruoli['D'], $ruoli['P']);
    }

    #[DataProvider('listoni')]
    public function test_le_quotazioni_sono_plausibili(string $file): void
    {
        $righe = $this->righe($file);

        // Prese da `Qt.I` e non da `Qt.A`: se si leggesse la colonna sbagliata
        // i numeri sarebbero comunque plausibili, quindi qui si controlla solo
        // la scala. L'invariante su quale colonna sta in ListoneImportTest.
        $this->assertGreaterThanOrEqual(1, $righe->min('quotazione'));
        $this->assertLessThan(60, $righe->max('quotazione'));
        $this->assertSame(0, $righe->where('quotazione', 0.0)->count(), 'nessuna quotazione a zero');
    }

    #[DataProvider('listoni')]
    public function test_i_sei_fogli_ci_sono_e_tutti_e_il_primo(string $file): void
    {
        $fogli = XlsxReader::sheetNames($file);

        $this->assertContains('Tutti', $fogli);
        $this->assertCount(6, $fogli, 'il listone ufficiale ha sei fogli');

        // «Tutti» è anche il più capiente: è il controllo che dice che stiamo
        // leggendo l'elenco completo e non un reparto.
        $tutti = count(XlsxReader::rows($file, 'Tutti'));

        foreach (array_diff($fogli, ['Tutti']) as $altro) {
            $this->assertGreaterThan(count(XlsxReader::rows($file, $altro)), $tutti);
        }
    }

    #[DataProvider('listoni')]
    public function test_nessun_nome_resta_vuoto(string $file): void
    {
        // Una riga senza nome non è abbinabile a nessuno, e passerebbe il
        // filtro se il parser sbagliasse colonna.
        $righe = $this->righe($file);

        $this->assertSame(0, $righe->filter(fn (array $r) => trim($r['nome']) === '')->count());
        $this->assertSame(
            $righe->count(),
            $righe->pluck('nome')->unique()->count(),
            'il listone non ripete mai un nome: se succede, la disambiguazione è saltata',
        );
    }
}
