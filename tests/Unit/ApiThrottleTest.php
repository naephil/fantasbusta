<?php

namespace Tests\Unit;

use App\Services\Ingest\ApiFootball;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Il freno sulle chiamate si tara da solo sul piano.
 *
 * Il tetto al minuto cambia col piano, e tenerlo scritto a mano significa
 * scoprire il valore sbagliato dopo: o un 429 in mezzo a una sincronizzazione
 * lunga, che lascia una giornata coi voti a pezzi, o mezz'ora di attesa inutile
 * su un piano che permetteva di correre.
 */
class ApiThrottleTest extends TestCase
{
    private const TETTO = 'apifootball-tetto-giornaliero';

    private function api(): ApiFootball
    {
        return app(ApiFootball::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(self::TETTO);
        config(['apifootball.min_interval_ms' => null]);
    }

    public function test_senza_tetto_noto_va_piano(): void
    {
        // ⚠️ La prudenza va dalla parte giusta: sbagliare per eccesso costa
        // qualche secondo, sbagliare nell'altro verso costa una
        // sincronizzazione a pezzi.
        $this->assertSame(
            (int) config('apifootball.intervallo_prudente'),
            $this->api()->intervallo(),
        );
    }

    public function test_sul_gratuito_distanzia_le_chiamate(): void
    {
        Cache::put(self::TETTO, 100);

        // 10 al minuto, usandone l'80%: una ogni 7,5 secondi.
        $this->assertSame(7500, $this->api()->intervallo());
    }

    public function test_sul_pro_corre(): void
    {
        Cache::put(self::TETTO, 7500);

        // 300 al minuto, usandone l'80%: una ogni quarto di secondo.
        $this->assertSame(250, $this->api()->intervallo());
    }

    public function test_un_tetto_piu_alto_e_ancora_piu_veloce(): void
    {
        Cache::put(self::TETTO, 75000);

        $this->assertSame(167, $this->api()->intervallo());
    }

    public function test_un_tetto_sconosciuto_ricade_sul_piano_piu_stretto_sotto(): void
    {
        // Un piano intermedio, o cambiato dal fornitore: si sceglie quello
        // sotto e non quello sopra, perché sbagliare verso l'alto vuol dire
        // chiamare più in fretta di quanto sia concesso.
        Cache::put(self::TETTO, 5000);

        $this->assertSame(7500, $this->api()->intervallo());
    }

    public function test_lo_scavalco_a_mano_vince_sempre(): void
    {
        // Chi lo scrive in .env sa una cosa che questo codice non sa.
        Cache::put(self::TETTO, 7500);
        config(['apifootball.min_interval_ms' => 3000]);

        $this->assertSame(3000, $this->api()->intervallo());
    }

    public function test_lo_scavalco_a_zero_toglie_il_freno(): void
    {
        // È quello che fa la suite: senza, ogni test che tocca l'API
        // aspetterebbe davvero.
        config(['apifootball.min_interval_ms' => 0]);

        $this->assertSame(0, $this->api()->intervallo());
    }
}
