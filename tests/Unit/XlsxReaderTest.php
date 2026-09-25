<?php

namespace Tests\Unit;

use App\Services\Ingest\XlsxReader;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Il lettore di xlsx, sul punto in cui è facile sbagliare.
 *
 * Un xlsx dichiara i suoi fogli in due posti che possono non essere d'accordo:
 * `workbook.xml` ne dà l'ORDINE e il nome, i `_rels` il file vero. Dedurre il
 * file dalla posizione — «il terzo foglio è sheet3.xml» — funziona sui file
 * appena generati e sbaglia su quelli rimaneggiati, che è il caso in cui si
 * finisce leggendo un foglio per un altro senza nessun errore.
 */
class XlsxReaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporanei = [];

    protected function tearDown(): void
    {
        foreach ($this->temporanei as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /**
     * Un xlsx minimo in cui l'ordine dei fogli e i nomi dei file NON coincidono.
     *
     * È il file che Excel produce dopo che un foglio è stato spostato: la
     * posizione dice una cosa, il `Target` dei `_rels` un'altra.
     *
     * @param  array<string,string>  $fogli  nome del foglio => file dentro lo zip
     * @param  array<string,list<list<string>>>  $contenuti  file => righe
     */
    private function xlsx(array $fogli, array $contenuti): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $this->temporanei[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheets = '';
        $rels = '';
        $i = 1;

        foreach ($fogli as $nome => $file) {
            $sheets .= sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', $nome, $i, $i);
            $rels .= sprintf(
                '<Relationship Id="rId%d" Target="%s" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/>',
                $i,
                $file,
            );
            $i++;
        }

        $zip->addFromString('xl/workbook.xml', sprintf(
            '<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>%s</sheets></workbook>',
            $sheets,
        ));

        $zip->addFromString('xl/_rels/workbook.xml.rels', sprintf(
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">%s</Relationships>',
            $rels,
        ));

        foreach ($contenuti as $file => $righe) {
            $xml = '';

            foreach ($righe as $r => $celle) {
                $xml .= '<row r="'.($r + 1).'">';

                foreach ($celle as $c => $valore) {
                    $xml .= sprintf(
                        '<c r="%s%d" t="inlineStr"><is><t>%s</t></is></c>',
                        chr(65 + $c),
                        $r + 1,
                        htmlspecialchars($valore),
                    );
                }

                $xml .= '</row>';
            }

            $zip->addFromString('xl/'.$file, sprintf(
                '<?xml version="1.0"?><worksheet><sheetData>%s</sheetData></worksheet>',
                $xml,
            ));
        }

        $zip->close();

        return $path;
    }

    public function test_il_foglio_si_trova_dal_nome_non_dalla_posizione(): void
    {
        // ⚠️ Il caso che il vecchio codice sbagliava: «Tutti» è il PRIMO foglio
        // ma sta in sheet2.xml. Chi deduce il file dalla posizione legge
        // «Portieri» e non se ne accorge, perché ottiene comunque delle righe.
        $path = $this->xlsx(
            ['Tutti' => 'worksheets/sheet2.xml', 'Portieri' => 'worksheets/sheet1.xml'],
            [
                'worksheets/sheet2.xml' => [['R', 'Nome'], ['D', 'Bastoni'], ['A', 'Lautaro']],
                'worksheets/sheet1.xml' => [['R', 'Nome'], ['P', 'Sommer']],
            ],
        );

        $this->assertSame([['R', 'Nome'], ['D', 'Bastoni'], ['A', 'Lautaro']], XlsxReader::rows($path, 'Tutti'));
        $this->assertSame([['R', 'Nome'], ['P', 'Sommer']], XlsxReader::rows($path, 'Portieri'));
    }

    public function test_senza_nome_si_legge_il_primo_foglio_dichiarato(): void
    {
        // «Primo» vuol dire primo nel workbook, non `sheet1.xml`.
        $path = $this->xlsx(
            ['Tutti' => 'worksheets/sheet2.xml', 'Portieri' => 'worksheets/sheet1.xml'],
            [
                'worksheets/sheet2.xml' => [['io sono Tutti']],
                'worksheets/sheet1.xml' => [['io sono Portieri']],
            ],
        );

        $this->assertSame([['io sono Tutti']], XlsxReader::rows($path));
    }

    public function test_i_nomi_dei_fogli_si_leggono_in_ordine(): void
    {
        $path = $this->xlsx(
            ['Tutti' => 'worksheets/sheet2.xml', 'Ceduti' => 'worksheets/sheet1.xml'],
            ['worksheets/sheet2.xml' => [['x']], 'worksheets/sheet1.xml' => [['y']]],
        );

        $this->assertSame(['Tutti', 'Ceduti'], XlsxReader::sheetNames($path));
    }

    public function test_un_foglio_che_non_c_e_lo_dice(): void
    {
        $path = $this->xlsx(
            ['Tutti' => 'worksheets/sheet1.xml'],
            ['worksheets/sheet1.xml' => [['x']]],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Inesistente');

        XlsxReader::rows($path, 'Inesistente');
    }

    public function test_un_file_che_non_e_uno_zip_lo_dice(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'finto').'.xlsx';
        file_put_contents($path, 'non sono uno zip');
        $this->temporanei[] = $path;

        $this->expectException(RuntimeException::class);

        XlsxReader::rows($path);
    }

    public function test_le_celle_vuote_non_fanno_slittare_la_riga(): void
    {
        // Excel omette le celle vuote: senza guardare il riferimento `r="C1"`
        // la C finirebbe in colonna B e le quotazioni si sposterebbero di uno.
        $path = tempnam(sys_get_temp_dir(), 'buchi').'.xlsx';
        $this->temporanei[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Tutti" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>a</t></is></c><c r="D1" t="inlineStr"><is><t>d</t></is></c></row></sheetData></worksheet>');
        $zip->close();

        $this->assertSame([['a', '', '', 'd']], XlsxReader::rows($path));
    }
}
