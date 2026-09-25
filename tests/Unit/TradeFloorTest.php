<?php

namespace Tests\Unit;

use App\Services\Trade\RosterFloor;
use PHPUnit\Framework\TestCase;

/**
 * Il pavimento rigido, verificato a tavolino.
 *
 * Nessun database: gli scambi si giudicano sugli effettivi per ruolo, e
 * tenerli in logica pura permette di scrivere i casi limite come si leggono.
 */
class TradeFloorTest extends TestCase
{
    // ───────────────────────── il pavimento ─────────────────────────

    public function test_una_rosa_da_undici_esatti_e_ancora_schierabile(): void
    {
        // Il 4-4-2 secco: nessun margine, ma nessuna violazione.
        $this->assertNull(RosterFloor::violation(['P' => 1, 'D' => 4, 'C' => 4, 'A' => 2]));
    }

    public function test_sotto_gli_undici_lo_scambio_e_rifiutato(): void
    {
        $reason = RosterFloor::violation(['P' => 1, 'D' => 4, 'C' => 3, 'A' => 2]);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('10 carte', $reason);
    }

    public function test_cedere_l_unico_portiere_e_rifiutato(): void
    {
        // Rosa lunga e ben distribuita, ma senza estremo difensore.
        $reason = RosterFloor::violation(['P' => 0, 'D' => 6, 'C' => 6, 'A' => 4]);

        $this->assertSame('resterebbe senza portiere', $reason);
    }

    public function test_rosa_numerosa_ma_senza_modulo_componibile(): void
    {
        // Dodici carte, un portiere, tutti i minimi generici rispettati —
        // e nessuno dei sette moduli canonici arriva a dieci di movimento.
        // È il contro-esempio che giustifica la garanzia della busta.
        $reason = RosterFloor::violation(['P' => 1, 'D' => 3, 'C' => 3, 'A' => 5]);

        $this->assertSame('non potrebbe comporre nessuno dei sette moduli', $reason);
    }

    public function test_il_motivo_piu_utile_vince_sugli_altri(): void
    {
        // Rosa che viola tutto insieme: deve parlare del conteggio, che è
        // l'informazione che il manager può usare.
        $reason = RosterFloor::violation(['P' => 0, 'D' => 1, 'C' => 1]);

        $this->assertStringContainsString('2 carte', $reason);
    }

    // ───────────────────────── effetto dello scambio ─────────────────────────

    public function test_lo_scambio_sbilanciato_sette_per_uno_e_permesso(): void
    {
        // Chi cede sette carte per una Leggendaria si assottiglia ma resta
        // sopra il pavimento: non c'è nessuna regola che glielo impedisca.
        $prima = ['P' => 2, 'D' => 6, 'C' => 6, 'A' => 4];

        $dopo = RosterFloor::apply(
            $prima,
            out: ['D', 'D', 'C', 'C', 'A', 'P', 'D'],
            in: ['A'],
        );

        $this->assertSame(['P' => 1, 'D' => 3, 'C' => 4, 'A' => 4], $dopo);
        $this->assertNull(RosterFloor::violation($dopo));
    }

    public function test_lo_stesso_scambio_su_una_rosa_corta_sfonda_il_pavimento(): void
    {
        $dopo = RosterFloor::apply(
            ['P' => 1, 'D' => 5, 'C' => 5, 'A' => 3],
            out: ['D', 'D', 'C', 'C', 'A', 'D', 'C'],
            in: ['A'],
        );

        $this->assertNotNull(RosterFloor::violation($dopo));
    }

    public function test_apply_regge_i_ruoli_mai_visti(): void
    {
        // Una rosa senza portieri non ha la chiave 'P': ricevere un portiere
        // non deve inciampare sulla chiave assente.
        $dopo = RosterFloor::apply(['D' => 4, 'C' => 4, 'A' => 2], out: [], in: ['P']);

        $this->assertSame(1, $dopo['P']);
        $this->assertNull(RosterFloor::violation($dopo));
    }

    public function test_ricevere_carte_puo_sbloccare_uno_scambio_altrimenti_vietato(): void
    {
        // Cedere il portiere è fatale solo se non ne arriva un altro:
        // il pavimento guarda il risultato, non il gesto.
        $counts = ['P' => 1, 'D' => 4, 'C' => 4, 'A' => 2];

        $this->assertNotNull(RosterFloor::violation(
            RosterFloor::apply($counts, out: ['P'], in: ['A']),
        ));

        $this->assertNull(RosterFloor::violation(
            RosterFloor::apply($counts, out: ['P'], in: ['P']),
        ));
    }
}
