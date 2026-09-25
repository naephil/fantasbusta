<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Team;
use App\Services\Ingest\ListoneImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Import del listone: ruoli e quotazioni, abbinati agli id API-Football.
 */
class ListoneImportTest extends TestCase
{
    use RefreshDatabase;

    /** L'annata su cui girano questi test. */
    private const ANNATA = 2026;

    /**
     * Un giocatore e la sua riga di listone per l'annata di prova.
     *
     * Nello schema le due cose sono separate — l'identità è permanente, ruolo e
     * squadra appartengono all'anno — ma un test che ne crea una sola
     * costruirebbe uno scenario muto: senza riga di listone l'import non ha
     * niente da abbinare.
     */
    private function giocatore(int $id, int $teamId, string $cognome, string $ruolo): Player
    {
        $player = Player::create(['id' => $id, 'last_name' => $cognome]);

        PlayerSeason::create([
            'player_id' => $id,
            'season' => self::ANNATA,
            'team_id' => $teamId,
            'role' => $ruolo,
        ]);

        return $player;
    }

    /** La riga di listone di quell'annata: è lì che vivono ruolo e quotazione. */
    private function listoneDi(int $playerId): PlayerSeason
    {
        return PlayerSeason::where('player_id', $playerId)
            ->where('season', self::ANNATA)
            ->firstOrFail();
    }

    /** @var list<string> */
    private array $temporanei = [];

    protected function tearDown(): void
    {
        foreach ($this->temporanei as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function csv(string $contenuto): string
    {
        $path = tempnam(sys_get_temp_dir(), 'listone').'.csv';
        file_put_contents($path, $contenuto);
        $this->temporanei[] = $path;

        return $path;
    }

    private function anagrafica(): void
    {
        Team::create(['id' => 1, 'name' => 'Inter']);
        Team::create(['id' => 2, 'name' => 'AC Milan']);

        $this->giocatore(10, 1, 'Dimarco', 'C');
        $this->giocatore(11, 1, 'Barella', 'C');
        $this->giocatore(20, 2, 'Leao', 'A');
    }

    private function import(string $csv): array
    {
        return (new ListoneImport)->fromCsv($this->csv($csv), self::ANNATA);
    }

    // ───────────────────────── abbinamento ─────────────────────────

    public function test_il_ruolo_del_listone_vince_su_quello_dell_api(): void
    {
        // Dimarco è Midfielder per l'API e difensore per il listone: è
        // esattamente il caso per cui il listone è la fonte autoritativa.
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            D;Dimarco;Inter;18,5
            CSV);

        $dimarco = $this->listoneDi(10);

        $this->assertSame(1, $esito['abbinati']);
        $this->assertSame('D', $dimarco->role->value);
        $this->assertSame(18.5, $dimarco->quotazione_iniziale);
        $this->assertTrue($dimarco->role_confirmed);
    }

    public function test_un_id_dichiarato_api_batte_ogni_altro_criterio(): void
    {
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            id_api;ruolo;nome;squadra;quotazione
            20;A;NomeSbagliato;SquadraSbagliata;25
            CSV);

        $this->assertSame(1, $esito['abbinati']);
        $this->assertTrue($this->listoneDi(20)->role_confirmed);
    }

    public function test_la_colonna_id_del_listone_ufficiale_viene_ignorata(): void
    {
        // Nel listone di Fantacalcio.it la colonna «Id» contiene il LORO
        // identificativo, non quello di API-Football. Sono due spazi diversi:
        // crederci abbinerebbe giocatori a caso, e in silenzio, perché un id
        // valido nell'altro spazio esiste quasi sempre.
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            Id;R;Nome;Squadra;Qt.A
            20;P;Dimarco;Inter;18
            CSV);

        // L'abbinamento è avvenuto sul cognome, non sull'id: la riga dice 20
        // (che sarebbe Leao) ma il giocatore giusto è Dimarco.
        $this->assertSame(1, $esito['abbinati']);
        $this->assertTrue($this->listoneDi(10)->role_confirmed);
        $this->assertFalse($this->listoneDi(20)->role_confirmed);
    }

    public function test_l_intestazione_puo_stare_sotto_un_titolo(): void
    {
        // Il listone ufficiale apre con «Quotazioni Fantacalcio Stagione …»
        // su una riga a sé.
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            Quotazioni Fantacalcio Stagione 2026 27
            Id;R;RM;Nome;Squadra;Qt.A;Qt.I
            5841;D;Dc;Dimarco;Inter;18;17
            CSV);

        $this->assertSame(1, $esito['abbinati']);
        // Qt.I, non Qt.A: la colonna è «quotazione iniziale».
        $this->assertSame(17.0, $this->listoneDi(10)->quotazione_iniziale);
    }

    public function test_la_squadra_scioglie_l_omonimia(): void
    {
        Team::create(['id' => 1, 'name' => 'Inter']);
        Team::create(['id' => 2, 'name' => 'AC Milan']);
        $this->giocatore(10, 1, 'Rossi', 'C');
        $this->giocatore(20, 2, 'Rossi', 'C');

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            P;Rossi;Milan;5
            CSV);

        $this->assertSame(1, $esito['abbinati']);
        $this->assertSame('P', $this->listoneDi(20)->role->value);
        $this->assertSame('C', $this->listoneDi(10)->role->value);   // l'altro non si tocca
    }

    public function test_l_omonimia_senza_squadra_resta_ambigua(): void
    {
        // Meglio segnalarli che indovinare: un abbinamento sbagliato assegna i
        // voti di un giocatore a un altro per tutta la stagione.
        Team::create(['id' => 1, 'name' => 'Inter']);
        Team::create(['id' => 2, 'name' => 'AC Milan']);
        $this->giocatore(10, 1, 'Rossi', 'C');
        $this->giocatore(20, 2, 'Rossi', 'C');

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            P;Rossi;;5
            CSV);

        $this->assertSame(0, $esito['abbinati']);
        $this->assertCount(1, $esito['ambigui']);
        $this->assertFalse($this->listoneDi(10)->role_confirmed);
    }

    public function test_chi_non_ha_candidati_finisce_fra_i_mancanti(): void
    {
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            A;Inesistente;Inter;10
            CSV);

        $this->assertSame(0, $esito['abbinati']);
        $this->assertCount(1, $esito['mancanti']);
    }

    public function test_il_nome_della_squadra_regge_le_differenze_di_forma(): void
    {
        // Il listone scrive «Milan», l'API «AC Milan».
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            A;Leao;Milan;28
            CSV);

        $this->assertSame(1, $esito['abbinati']);
    }

    public function test_la_squadra_del_listone_vince_su_quella_dell_api(): void
    {
        // ⚠️ Il caso vero: nel 2023/24 Lukaku risultava all'Inter — che ne
        // deteneva il cartellino — mentre il listone lo dà alla Roma, dove ha
        // giocato ed è quotato. Non è una didascalia: la giornata di Serie A
        // raggruppa per `team_id`, quindi compariva sotto la partita sbagliata.
        Team::create(['id' => 1, 'name' => 'Inter']);
        Team::create(['id' => 2, 'name' => 'AS Roma']);

        $this->giocatore(10, 1, 'Lukaku', 'A');

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            A;Lukaku;Roma;31
            CSV);

        $this->assertSame(1, $esito['abbinati']);
        $this->assertSame(2, $this->listoneDi(10)->team_id);
    }

    public function test_senza_squadra_riconoscibile_quella_di_prima_resta(): void
    {
        // Meglio la squadra dell'API che nessuna squadra: senza `team_id` il
        // giocatore sparirebbe da ogni raggruppamento per partita.
        Team::create(['id' => 1, 'name' => 'Inter']);
        $this->giocatore(10, 1, 'Lukaku', 'A');

        $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            A;Lukaku;;31
            CSV);

        $this->assertSame(1, $this->listoneDi(10)->team_id);
    }

    public function test_un_cognome_dentro_un_altro_non_e_lo_stesso_cognome(): void
    {
        // ⚠️ Il difetto che ha lasciato a spasso mezza Serie A. Cercando
        // SOTTOSTRINGHE, «Nandez» sta dentro «Hernández» e «Martin» dentro
        // «Álvarez Martínez»: la riga giusta diventava ambigua e veniva
        // scartata, oppure — peggio — finiva addosso al giocatore sbagliato.
        Team::create(['id' => 1, 'name' => 'AC Milan']);
        Team::create(['id' => 2, 'name' => 'Cagliari']);

        $this->giocatore(10, 1, 'Hernández', 'D');
        $this->giocatore(20, 2, 'Nandez', 'C');

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            D;Hernandez T.;Milan;22
            CSV);

        $this->assertSame(1, $esito['abbinati']);
        $this->assertTrue($this->listoneDi(10)->role_confirmed);
        $this->assertFalse($this->listoneDi(20)->role_confirmed, 'Nandez non c\'entra niente');
    }

    public function test_un_cognome_corto_non_ruba_quello_lungo(): void
    {
        // «Dest» stava dentro «Destro», e il listone di Destro si portava via
        // la riga di Dest.
        Team::create(['id' => 1, 'name' => 'Empoli']);
        $this->giocatore(10, 1, 'Dest', 'D');

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            A;Destro;Empoli;9
            CSV);

        $this->assertSame(0, $esito['abbinati']);
        $this->assertFalse($this->listoneDi(10)->role_confirmed);
    }

    public function test_i_cognomi_composti_si_agganciano_alla_parola_giusta(): void
    {
        // È il caso vero da coprire: l'API scrive il cognome per intero, il
        // listone la sola parola con cui il giocatore è conosciuto.
        Team::create(['id' => 1, 'name' => 'AC Milan']);
        Team::create(['id' => 2, 'name' => 'Cagliari']);

        $this->giocatore(10, 1, 'Kalulu Kyatengwa', 'C');
        $this->giocatore(20, 2, 'Azamat Shomurodov', 'C');

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            D;Kalulu;Milan;12
            A;Shomurodov;Cagliari;14
            CSV);

        $this->assertSame(2, $esito['abbinati']);
        $this->assertSame('D', $this->listoneDi(10)->role->value);
        $this->assertSame('A', $this->listoneDi(20)->role->value);
    }

    public function test_gli_accenti_slavi_e_nordici_non_fermano_l_abbinamento(): void
    {
        // Senza una tabella che li traduca, `preg_replace` li CANCELLA invece
        // di sostituirli: «Vlašić» diventava «vlai» e non somigliava più a
        // niente. Sono decine di giocatori in Serie A.
        Team::create(['id' => 1, 'name' => 'Torino']);
        $this->giocatore(10, 1, 'Vlašić', 'C');

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            C;Vlasic;Torino;16
            CSV);

        $this->assertSame(1, $esito['abbinati']);
    }

    public function test_gli_accenti_e_gli_apostrofi_non_fermano_l_abbinamento(): void
    {
        Team::create(['id' => 1, 'name' => 'Inter']);
        $this->giocatore(10, 1, 'DAmbrosio', 'C');

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            D;D'Ambrosio;Inter;7
            CSV);

        $this->assertSame(1, $esito['abbinati']);
    }

    // ───────────────────────── il formato ─────────────────────────

    public function test_la_virgola_come_delimitatore_funziona_uguale(): void
    {
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            ruolo,nome,squadra,quotazione
            A,Leao,AC Milan,28.5
            CSV);

        $this->assertSame(1, $esito['abbinati']);
        $this->assertSame(28.5, $this->listoneDi(20)->quotazione_iniziale);
    }

    public function test_le_intestazioni_abbreviate_del_listone_sono_riconosciute(): void
    {
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            R;Nome;Squadra;Qt.A
            A;Leao;Milan;28
            CSV);

        $this->assertSame(1, $esito['abbinati']);
    }

    public function test_senza_le_colonne_essenziali_l_import_si_ferma(): void
    {
        $this->anagrafica();

        $this->expectExceptionMessage('ruolo');

        $this->import("squadra;quotazione\nInter;10");
    }

    public function test_un_file_illeggibile_lo_dice(): void
    {
        $this->expectException(RuntimeException::class);

        (new ListoneImport)->fromCsv('/percorso/che/non/esiste.csv', self::ANNATA);
    }

    // ───────────────────────── il listone ufficiale ─────────────────────────

    /**
     * Il file vero di Fantacalcio.it, non una sua imitazione scritta a mano.
     *
     * Sei fogli — «Tutti» più uno per reparto più i ceduti — tredici colonne, e
     * un titolo sopra le intestazioni. Ogni imitazione in CSV semplifica
     * qualcosa, e ciò che semplifica è esattamente dove l'import si rompe.
     */
    private const LISTONE = __DIR__.'/../Quotazioni_Fantacalcio_Stagione_2026_27.xlsx';

    public function test_il_listone_ufficiale_si_legge_intero(): void
    {
        Team::create(['id' => 1, 'name' => 'AS Roma']);
        Team::create(['id' => 2, 'name' => 'Inter']);

        $this->giocatore(100, 1, 'Svilar', 'C');       // per l'API è un centrocampista
        $this->giocatore(101, 2, 'Bastoni', 'C');

        $esito = (new ListoneImport)->fromFile(self::LISTONE, self::ANNATA);

        // Solo due giocatori in anagrafica, quindi due abbinati: gli altri
        // cinquecento finiscono fra i mancanti, ed è l'esito giusto.
        $this->assertSame(2, $esito['abbinati']);

        $svilar = $this->listoneDi(100);
        $this->assertSame('P', $svilar->role->value);
        $this->assertTrue($svilar->role_confirmed);
        $this->assertSame(18.0, $svilar->quotazione_iniziale);
    }

    public function test_si_legge_il_foglio_tutti_e_non_quello_dei_portieri(): void
    {
        // ⚠️ È il guasto silenzioso che conta: leggendo «Portieri» l'import
        // riuscirebbe lo stesso e riporterebbe un numero plausibile, ma
        // importerebbe un ottavo del campionato — e nessuno va a contare.
        $imp = new ListoneImport;
        $parse = new \ReflectionMethod($imp, 'parse');
        $parse->setAccessible(true);

        $righe = $parse->invoke($imp, self::LISTONE);

        $ruoli = $righe->countBy('ruolo');

        $this->assertGreaterThan(400, $righe->count());
        $this->assertGreaterThan(0, $ruoli['P'] ?? 0);
        $this->assertGreaterThan(0, $ruoli['D'] ?? 0);
        $this->assertGreaterThan(0, $ruoli['C'] ?? 0);
        $this->assertGreaterThan(0, $ruoli['A'] ?? 0);

        // Venti squadre: se si fosse letto un foglio di reparto ce ne sarebbero
        // comunque venti, ma le righe sarebbero una frazione.
        $this->assertSame(20, $righe->pluck('squadra')->unique()->count());
    }

    public function test_dal_listone_ufficiale_si_prende_la_quotazione_iniziale(): void
    {
        // Il file porta sia `Qt.A` sia `Qt.I`, e quella che conta è la seconda
        // anche se sta più a destra.
        Team::create(['id' => 1, 'name' => 'Inter']);
        $this->giocatore(100, 1, 'Calhanoglu', 'D');

        (new ListoneImport)->fromFile(self::LISTONE, self::ANNATA);

        $riga = $this->listoneDi(100);

        $this->assertSame('C', $riga->role->value);
        $this->assertGreaterThan(0.0, $riga->quotazione_iniziale);
    }

    public function test_il_listone_si_importa_anche_arrivando_da_un_upload(): void
    {
        // ⚠️ La regressione che è costata di più: dalla pagina di gestione il
        // file arriva come temporaneo di PHP — `php1A2B.tmp` — e di `.xlsx` non
        // ha più traccia, perché `getRealPath()` dà il temporaneo e non il nome
        // scelto da chi carica. Decidendo il formato dall'estensione lo zip
        // finiva nel lettore CSV, e l'errore che ne usciva — «non trovo le
        // colonne ruolo e nome» — mandava a cercare il guasto dentro il file.
        //
        // Da riga di comando funzionava: è il motivo per cui il test deve
        // riprodurre il NOME, non solo il contenuto.
        Team::create(['id' => 1, 'name' => 'AS Roma']);
        $this->giocatore(100, 1, 'Svilar', 'C');

        $caricato = tempnam(sys_get_temp_dir(), 'php').'.tmp';
        copy(self::LISTONE, $caricato);
        $this->temporanei[] = $caricato;

        $esito = (new ListoneImport)->fromFile($caricato, self::ANNATA);

        $this->assertSame(1, $esito['abbinati']);
        $this->assertSame('P', $this->listoneDi(100)->role->value);
    }

    public function test_un_csv_senza_estensione_resta_un_csv(): void
    {
        // L'altro verso della stessa medaglia: il riconoscimento dal contenuto
        // non deve rompere i file di testo, che di byte magici non ne hanno.
        $this->anagrafica();

        $path = tempnam(sys_get_temp_dir(), 'php').'.tmp';
        file_put_contents($path, "ruolo;nome;squadra;quotazione\nA;Leao;Milan;28");
        $this->temporanei[] = $path;

        $esito = (new ListoneImport)->fromFile($path, self::ANNATA);

        $this->assertSame(1, $esito['abbinati']);
    }

    public function test_il_vecchio_xls_binario_lo_dice_invece_di_confondere(): void
    {
        // Il modulo accetta anche `.xls`, ma è un contenitore OLE2 che
        // XlsxReader non sa aprire. Meglio dirlo con la soluzione in mano che
        // lasciar credere che il listone sia sbagliato.
        $path = tempnam(sys_get_temp_dir(), 'vecchio').'.xls';
        file_put_contents($path, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 64));
        $this->temporanei[] = $path;

        $this->expectExceptionMessage('.xlsx');

        (new ListoneImport)->fromFile($path, self::ANNATA);
    }

    public function test_la_colonna_del_ruolo_sbagliata_si_ferma_invece_di_scrivere(): void
    {
        // `RM` è il ruolo Mantra: «Por», «Dc», «W». Finirebbe dritto in una
        // colonna enum del database, con mezzo listone già scritto e un errore
        // SQL incomprensibile al posto di una spiegazione.
        $this->anagrafica();

        $this->expectExceptionMessage('RM');

        $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            Por;Sommer;Inter;15
            Dc;Bastoni;Inter;12
            CSV);
    }

    public function test_un_ruolo_estraneo_non_confonde_quelli_buoni(): void
    {
        // Nemmeno una riga sola: se la colonna è giusta e un valore no, è il
        // file a essere sporco, e importarlo a metà è peggio che non importarlo.
        $this->anagrafica();

        $this->expectExceptionMessage('«X»');

        $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            A;Leao;Milan;28
            X;Dimarco;Inter;18
            CSV);
    }

    public function test_il_ruolo_minuscolo_del_listone_va_bene_lo_stesso(): void
    {
        $this->anagrafica();

        $esito = $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            a;Leao;Milan;28
            CSV);

        $this->assertSame(1, $esito['abbinati']);
        $this->assertSame('A', $this->listoneDi(20)->role->value);
    }

    // ───────────────────────── il limite dell'import ─────────────────────────

    public function test_l_abbinamento_non_conta_come_verifica_dell_identita(): void
    {
        // Un id che punta al giocatore *sbagliato* restituisce una faccia
        // plausibile e supera ogni controllo automatico. Il listone conferma
        // il ruolo, non l'identità: quella la conferma solo un occhio umano.
        $this->anagrafica();

        $this->import(<<<'CSV'
            ruolo;nome;squadra;quotazione
            D;Dimarco;Inter;18,5
            CSV);

        $this->assertTrue($this->listoneDi(10)->role_confirmed);
        $this->assertFalse(Player::find(10)->photo_verified);
    }
}
