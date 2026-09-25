<?php

namespace Tests\Unit;

use App\Services\Calendar\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Il girone all'italiana, verificato a tavolino.
 */
class ScheduleTest extends TestCase
{
    /** @return list<int> */
    private function managers(int $n): array
    {
        return range(1, $n);
    }

    /** Tutte le coppie del calendario, normalizzate senza casa e trasferta. */
    private function pairs(array $calendario): array
    {
        $out = [];

        foreach ($calendario as $turno) {
            foreach ($turno as [$a, $b]) {
                $coppia = [$a, $b];
                sort($coppia);
                $out[] = implode('-', $coppia);
            }
        }

        return $out;
    }

    public function test_dodici_manager_fanno_undici_turni(): void
    {
        // È l'aritmetica che decide il formato: con 12 manager un girone dura
        // 11 turni, quindi due gironi ne occupano 22 delle 38 giornate.
        $calendario = ScheduleGenerator::build($this->managers(12), gironi: 1);

        $this->assertCount(11, $calendario);
        $this->assertCount(6, $calendario[0]);
    }

    public function test_in_un_girone_tutti_incontrano_tutti_una_volta_sola(): void
    {
        $coppie = $this->pairs(ScheduleGenerator::build($this->managers(12), gironi: 1));

        // 12 su 2 = 66 accoppiamenti possibili, tutti presenti, nessuno doppio.
        $this->assertCount(66, $coppie);
        $this->assertCount(66, array_unique($coppie));
    }

    public function test_nessuno_gioca_due_volte_nello_stesso_turno(): void
    {
        $calendario = ScheduleGenerator::build($this->managers(12), gironi: 1);

        foreach ($calendario as $turno => $coppie) {
            $impegnati = array_merge(...array_map(fn (array $c) => $c, $coppie));

            $this->assertCount(12, $impegnati, "turno {$turno}");
            $this->assertCount(12, array_unique($impegnati), "turno {$turno}");
        }
    }

    public function test_due_gironi_raddoppiano_gli_incontri_invertendo_i_lati(): void
    {
        $calendario = ScheduleGenerator::build($this->managers(12), gironi: 2);

        $this->assertCount(22, $calendario);

        $conteggio = array_count_values($this->pairs($calendario));

        $this->assertCount(66, $conteggio);
        $this->assertSame([2], array_values(array_unique($conteggio)));

        // Il ritorno è l'andata a lati scambiati.
        $this->assertSame(
            array_map(fn (array $c) => [$c[1], $c[0]], $calendario[0]),
            $calendario[11],
        );
    }

    public function test_tre_gironi_stanno_nelle_trentotto_giornate(): void
    {
        // Tre gironi sono 33 turni: ci stanno. Quattro ne vorrebbero 44 e no.
        $this->assertCount(33, ScheduleGenerator::build($this->managers(12), gironi: 3));
        $this->assertCount(44, ScheduleGenerator::build($this->managers(12), gironi: 4));
    }

    public function test_con_un_dispari_ogni_turno_qualcuno_riposa(): void
    {
        // Non dovrebbe servire, ma un abbandono a stagione in corso non deve
        // rompere il generatore.
        $calendario = ScheduleGenerator::build($this->managers(11), gironi: 1);

        $this->assertCount(11, $calendario);

        foreach ($calendario as $turno => $coppie) {
            $this->assertCount(5, $coppie, "turno {$turno}");
        }

        // Anche così ognuno incontra tutti gli altri una volta: 11 su 2 = 55.
        $this->assertCount(55, array_unique($this->pairs($calendario)));
    }

    public function test_una_lega_senza_avversari_non_ha_calendario(): void
    {
        $this->assertSame([], ScheduleGenerator::build([1], gironi: 2));
        $this->assertSame([], ScheduleGenerator::build([], gironi: 2));
    }
}
