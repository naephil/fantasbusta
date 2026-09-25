<?php

namespace Tests\Feature;

use App\Models\Manager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Identità della squadra: nome, allenatore, maglia, stemma.
 */
class TeamIdentityTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private Manager $marco;

    protected function setUp(): void
    {
        parent::setUp();

        $this->marco = $this->makeManager($this->makeLeague(), 'Marco');
    }

    /** @param  array<string,mixed>  $sovrascrivi */
    private function valido(array $sovrascrivi = []): array
    {
        return $sovrascrivi + [
            'name' => 'Atletico Sbustamento',
            'coach_name' => 'Marco',
            'maglia_colori' => ['#6b1220', '#d4a04a', '#f2ede1'],
            'maglia_stile' => 'righe',
            'stemma_colori' => ['#4b2a7b', '#d8c7f0', '#f2ede1'],
            'stemma_forma' => 'rombo',
            'stemma_simbolo' => 'fulmine',
            'sponsor_testo' => 'IDROBAR',
            'sponsor_stile' => 'mono',
            'sponsor_posizione' => 'centro',
            'sponsor_colore' => '#f2ede1',
            'sponsor_colore_riquadro' => '#0b0b0e',
        ];
    }

    // ───────────────────────── la pagina ─────────────────────────

    public function test_la_pagina_e_riservata(): void
    {
        $this->get(route('team.edit'))->assertRedirect(route('login'));
    }

    public function test_la_pagina_si_apre_con_i_valori_predefiniti(): void
    {
        $this->actingAs($this->marco)
            ->get(route('team.edit'))
            ->assertOk()
            ->assertSee('La mia squadra')
            ->assertSee('Tinta unita')
            ->assertSee('Nerazzurra');
    }

    public function test_la_pagina_mostra_quello_che_e_gia_salvato(): void
    {
        $this->actingAs($this->marco)->post(route('team.update'), $this->valido());

        $this->actingAs($this->marco)
            ->get(route('team.edit'))
            ->assertOk()
            ->assertSee('Atletico Sbustamento')
            ->assertSee('data-stile="righe"', escape: false)
            ->assertSee('data-forma="rombo"', escape: false);
    }

    // ───────────────────────── il salvataggio ─────────────────────────

    public function test_si_salvano_nome_allenatore_maglia_e_stemma(): void
    {
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido())
            ->assertRedirect()
            ->assertSessionHas('successo');

        $this->marco->refresh();

        $this->assertSame('Atletico Sbustamento', $this->marco->name);
        $this->assertSame('Marco', $this->marco->coach_name);
        $this->assertSame('righe', $this->marco->jersey['stile']);
        $this->assertSame('fulmine', $this->marco->crest['simbolo']);
    }

    public function test_i_tre_colori_si_salvano_come_sono_stati_scelti(): void
    {
        // I colori sono valori liberi e non il nome di una palette: le palette
        // restano nella pagina come punti di partenza, ma chi ha in testa i
        // colori della propria squadra deve poterli mettere.
        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'maglia_colori' => ['#123456', '#ABCDEF', '#0F0F0F'],
        ]));

        $this->assertSame(
            ['#123456', '#abcdef', '#0f0f0f'],
            $this->marco->fresh()->jersey['colori'],
        );
    }

    public function test_un_colore_che_non_e_un_colore_viene_rifiutato(): void
    {
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido([
                'maglia_colori' => ['arcobaleno', '#ABCDEF', '#0F0F0F'],
            ]))
            ->assertSessionHasErrors('maglia_colori.0');

        $this->assertNull($this->marco->fresh()->jersey);
    }

    public function test_uno_stile_inventato_viene_rifiutato(): void
    {
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido(['maglia_stile' => 'fantasia']))
            ->assertSessionHasErrors('maglia_stile');
    }

    public function test_il_nome_della_squadra_e_obbligatorio(): void
    {
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_servono_esattamente_tre_colori(): void
    {
        // Il disegno ne vuole tre — base, motivo, dettaglio — e con due o
        // quattro le variabili CSS resterebbero scoperte o in avanzo.
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido(['maglia_colori' => ['#123456', '#abcdef']]))
            ->assertSessionHasErrors('maglia_colori');
    }

    // ───────────────────────── lo sponsor ─────────────────────────

    public function test_lo_sponsor_si_posiziona_e_si_colora(): void
    {
        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'sponsor_posizione' => 'alto',
            'sponsor_riquadro' => '1',
            'sponsor_colore' => '#FF0000',
            'sponsor_colore_riquadro' => '#00FF00',
        ]));

        $sponsor = $this->marco->fresh()->sponsor;

        $this->assertSame('alto', $sponsor['posizione']);
        $this->assertTrue($sponsor['riquadro']);
        $this->assertSame('#ff0000', $sponsor['colore']);
        $this->assertSame('#00ff00', $sponsor['colore_riquadro']);
    }

    public function test_la_spunta_del_riquadro_tolta_lo_spegne(): void
    {
        // Le caselle non spuntate non arrivano nella richiesta: senza leggerle
        // da `boolean()` togliere la spunta non spegnerebbe niente.
        $this->actingAs($this->marco)->post(route('team.update'), $this->valido(['sponsor_riquadro' => '1']));
        $this->assertTrue($this->marco->fresh()->sponsor['riquadro']);

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido());
        $this->assertFalse($this->marco->fresh()->sponsor['riquadro']);
    }

    public function test_una_posizione_inventata_viene_rifiutata(): void
    {
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido(['sponsor_posizione' => 'sulla-schiena']))
            ->assertSessionHasErrors('sponsor_posizione');
    }

    // ───────────────────────── lo stemma caricato ─────────────────────────

    public function test_si_carica_uno_stemma_proprio(): void
    {
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'stemma_file' => UploadedFile::fake()->create('logo.png', 40, 'image/png'),
        ]));

        $path = $this->marco->fresh()->crest_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_lo_stemma_caricato_ha_la_precedenza_su_quello_composto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'stemma_file' => UploadedFile::fake()->create('logo.png', 40, 'image/png'),
        ]));

        // Niente SVG generato in pagina: al suo posto l'immagine.
        $this->actingAs($this->marco)
            ->get(route('trades.index'))
            ->assertOk();

        $this->assertNotNull($this->marco->fresh()->crest_path);
    }

    public function test_si_puo_tornare_allo_stemma_composto(): void
    {
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'stemma_file' => UploadedFile::fake()->create('logo.png', 40, 'image/png'),
        ]));

        $vecchio = $this->marco->fresh()->crest_path;

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'rimuovi_stemma' => 1,
        ]));

        $this->assertNull($this->marco->fresh()->crest_path);
        Storage::disk('public')->assertMissing($vecchio);
    }

    public function test_un_file_troppo_grande_viene_rifiutato(): void
    {
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'stemma_file' => UploadedFile::fake()->create('enorme.png', 2048, 'image/png'),
        ]))->assertSessionHasErrors('stemma_file');

        $this->assertNull($this->marco->fresh()->crest_path);
    }

    public function test_un_formato_non_ammesso_viene_rifiutato(): void
    {
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'stemma_file' => UploadedFile::fake()->create('script.php', 10),
        ]))->assertSessionHasErrors('stemma_file');
    }

    // ───────────────────────── lo sponsor ─────────────────────────

    public function test_lo_sponsor_testuale_finisce_sulla_maglia(): void
    {
        $this->actingAs($this->marco)->post(route('team.update'), $this->valido());

        $this->marco->refresh();

        $this->assertSame('IDROBAR', $this->marco->sponsor['testo']);
        $this->assertSame('mono', $this->marco->sponsor['stile']);

        $this->actingAs($this->marco)
            ->get(route('team.edit'))
            ->assertSee('IDROBAR')
            ->assertSee('data-sponsor-stile="mono"', escape: false);
    }

    public function test_lo_sponsor_puo_non_esserci(): void
    {
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido(['sponsor_testo' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('', $this->marco->fresh()->sponsor['testo']);
    }

    public function test_uno_sponsor_troppo_lungo_viene_rifiutato(): void
    {
        // Il petto di una maglia è largo così: oltre, il marchio sborda.
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido([
                'sponsor_testo' => 'CONSORZIO NAZIONALE TRASPORTI',
            ]))
            ->assertSessionHasErrors('sponsor_testo');
    }

    public function test_un_carattere_inventato_viene_rifiutato(): void
    {
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido(['sponsor_stile' => 'gotico']))
            ->assertSessionHasErrors('sponsor_stile');
    }

    public function test_si_carica_un_marchio_con_trasparenza(): void
    {
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'sponsor_file' => UploadedFile::fake()->create('marchio.png', 30, 'image/png'),
        ]));

        $path = $this->marco->fresh()->sponsor_path;

        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_il_jpeg_non_e_ammesso_per_lo_sponsor(): void
    {
        // Finisce sopra il colore della maglia: senza trasparenza si vedrebbe
        // il rettangolo bianco attorno al marchio.
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'sponsor_file' => UploadedFile::fake()->create('marchio.jpg', 30, 'image/jpeg'),
        ]))->assertSessionHasErrors('sponsor_file');

        $this->assertNull($this->marco->fresh()->sponsor_path);
    }

    public function test_si_torna_dal_marchio_caricato_al_testo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'sponsor_file' => UploadedFile::fake()->create('marchio.png', 30, 'image/png'),
        ]));

        $vecchio = $this->marco->fresh()->sponsor_path;

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'rimuovi_sponsor' => 1,
        ]));

        $this->assertNull($this->marco->fresh()->sponsor_path);
        Storage::disk('public')->assertMissing($vecchio);
    }

    public function test_stemma_e_sponsor_non_si_calpestano(): void
    {
        // Due file distinti, due colonne distinte: sostituirne uno non deve
        // toccare l'altro.
        Storage::fake('public');

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'stemma_file' => UploadedFile::fake()->create('logo.png', 30, 'image/png'),
            'sponsor_file' => UploadedFile::fake()->create('marchio.png', 30, 'image/png'),
        ]));

        $manager = $this->marco->fresh();
        $stemma = $manager->crest_path;

        $this->assertNotNull($stemma);
        $this->assertNotNull($manager->sponsor_path);
        $this->assertNotSame($stemma, $manager->sponsor_path);

        $this->actingAs($this->marco)->post(route('team.update'), $this->valido([
            'rimuovi_sponsor' => 1,
        ]));

        $this->assertSame($stemma, $this->marco->fresh()->crest_path);
        Storage::disk('public')->assertExists($stemma);
    }

    public function test_il_testo_non_utf8_diventa_un_errore_non_un_cinquecento(): void
    {
        // I campi liberi finiscono in una colonna JSON, e `json_encode` su
        // byte malformati solleva un'eccezione che arriva all'utente come una
        // pagina bianca. Meglio dirglielo.
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido([
                // Il byte 0xC8 da solo: è la È del latin-1, che in UTF-8 non
                // vuol dire niente. Costruito a runtime perché scritto come
                // sequenza di escape verrebbe normalizzato dall'editor.
                'sponsor_testo' => 'TRIS CAFF'.chr(0xC8),
            ]))
            ->assertSessionHasErrors('sponsor_testo');

        $this->assertNull($this->marco->fresh()->sponsor);
    }

    public function test_gli_accenti_veri_passano_senza_problemi(): void
    {
        $this->actingAs($this->marco)
            ->post(route('team.update'), $this->valido(['sponsor_testo' => 'TRIS CAFFÈ']))
            ->assertSessionHasNoErrors();

        $this->assertSame('TRIS CAFFÈ', $this->marco->fresh()->sponsor['testo']);
    }

    // ───────────────────────── le iniziali ─────────────────────────

    public function test_le_iniziali_vengono_dalle_parole_del_nome(): void
    {
        $this->marco->update(['name' => 'Atletico Sbustamento Nord']);
        $this->assertSame('ASN', $this->marco->initials());

        $this->marco->update(['name' => 'Sporting']);
        $this->assertSame('SPO', $this->marco->initials());
    }
}
