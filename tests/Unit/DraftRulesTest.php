<?php

namespace Tests\Unit;

use App\Services\Draft\SlotPlanner;
use App\Services\Lineup\ModuleValidator;
use PHPUnit\Framework\TestCase;

class DraftRulesTest extends TestCase
{
    // ───────────────────────── moduli ─────────────────────────

    public function test_rosa_che_rispetta_i_minimi_ma_resta_inschierabile(): void
    {
        // Il contro-esempio che ha determinato la garanzia della busta:
        // D≥3, C≥3, A≥1 sono tutti soddisfatti, eppure nessuno dei sette
        // moduli canonici arriva a dieci uomini di movimento.
        $counts = ['P' => 1, 'D' => 3, 'C' => 3, 'A' => 4];

        $this->assertSame([], ModuleValidator::playable($counts));
        $this->assertFalse(ModuleValidator::isFieldable($counts));
    }

    public function test_la_garanzia_della_busta_assicura_sempre_il_4_4_2(): void
    {
        $this->assertTrue(ModuleValidator::isFieldable(ModuleValidator::GUARANTEE));
        $this->assertContains('4-4-2', ModuleValidator::playable(ModuleValidator::GUARANTEE));
    }

    public function test_senza_portiere_non_si_schiera_nulla(): void
    {
        $this->assertFalse(ModuleValidator::isFieldable(['P' => 0, 'D' => 9, 'C' => 9, 'A' => 9]));
    }

    public function test_rosa_lunga_apre_tutti_i_moduli(): void
    {
        $playable = ModuleValidator::playable(['P' => 2, 'D' => 6, 'C' => 6, 'A' => 4]);

        $this->assertCount(7, $playable);
    }

    // ──────────────────── riempimento a deficit ────────────────────

    private function planner(): SlotPlanner
    {
        return new SlotPlanner(ModuleValidator::GUARANTEE, packSize: 5, totalPacks: 5);
    }

    public function test_la_prima_busta_e_interamente_casuale(): void
    {
        // Rosa vuota: mancano 11 carte garantite ma ci sono 25 slot davanti.
        // Margine ampio, nessuno slot viene forzato.
        $slots = $this->planner()->plan([], packIndex: 1);

        $this->assertSame([null, null, null, null, null], $slots);
    }

    public function test_il_portiere_viene_forzato_solo_sull_ultimo_slot_utile(): void
    {
        // Venti carte pescate, tutte di movimento: manca solo il portiere.
        // Resta un'unica busta, quindi il vincolo scatta all'ultimo slot
        // e i primi quattro restano liberi.
        $owned = ['P' => 0, 'D' => 8, 'C' => 9, 'A' => 3];

        $slots = $this->planner()->plan($owned, packIndex: 5);

        $this->assertSame([null, null, null, null, 'P'], $slots);
    }

    public function test_quando_il_margine_si_esaurisce_ogni_slot_e_vincolato(): void
    {
        // Caso degenere: ultima busta e garanzia ancora tutta da coprire.
        // Gli slot disponibili non bastano, quindi vengono forzati tutti.
        $slots = $this->planner()->plan([], packIndex: 5);

        $this->assertNotContains(null, $slots);
        $this->assertCount(5, $slots);
    }

    public function test_il_deficit_non_va_mai_sotto_zero(): void
    {
        // Rosa già oltre la garanzia: nessun debito residuo.
        $deficit = $this->planner()->deficit(['P' => 3, 'D' => 7, 'C' => 8, 'A' => 5]);

        $this->assertSame(['P' => 0, 'D' => 0, 'C' => 0, 'A' => 0], $deficit);
    }

    public function test_uno_slot_libero_si_restringe_quando_il_margine_finisce(): void
    {
        $planner = $this->planner();
        $owned = ['P' => 0, 'D' => 4, 'C' => 4, 'A' => 2];

        // Due slot residui e un solo ruolo mancante: ancora libero.
        $this->assertCount(4, $planner->allowedForFreeSlot($owned, slotsLeftAfterThis: 2));

        // Un solo slot residuo e un ruolo mancante: obbligato.
        $this->assertSame(['P'], $planner->allowedForFreeSlot($owned, slotsLeftAfterThis: 1));
    }

    public function test_a_garanzia_coperta_anche_l_ultimo_slot_resta_libero(): void
    {
        // L'ultimo slot dell'ultima busta: zero ruoli mancanti e zero slot
        // residui. Senza trattare a parte il caso «nessun debito», il confronto
        // `0 < 0` mandava nel ramo dei ruoli in deficit — vuoto, a garanzia
        // coperta — e la carta spariva senza errore.
        $planner = $this->planner();

        $this->assertCount(
            4,
            $planner->allowedForFreeSlot(['P' => 2, 'D' => 6, 'C' => 6, 'A' => 4], slotsLeftAfterThis: 0),
        );
    }
}
