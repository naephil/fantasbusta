<?php

namespace Tests\Feature;

use App\Models\Trade;
use App\Services\Trade\BotTrader;
use App\Services\Trade\TradeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * I bot rispondono al mercato.
 *
 * Senza, una lega mezza di bot ha un mercato morto: si propone e non succede
 * niente finché la proposta non scade con la giornata, il che è
 * indistinguibile da un mercato rotto per chi sta provando il gioco.
 */
class BotTraderTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    /** Due rose piene: senza, il pavimento rifiuterebbe tutto per altri motivi. */
    private function mercato(): array
    {
        $stagione = $this->makeLeague();

        $persona = $this->makeManager($stagione, 'Marco');
        $bot = $this->makeManager($stagione, 'Automatica Bergamo');
        $bot->update(['is_bot' => true, 'auto_draft' => true]);

        $rosaPersona = $this->makeRoster($persona, 'PPDDDDDCCCCCAAA');
        $rosaBot = $this->makeRoster($bot, 'PPDDDDDCCCCCAAA');

        return [$stagione, $persona, $bot, $rosaPersona, $rosaBot];
    }

    private function proponi($stagione, $da, $a, $rosaDa, $rosaA): Trade
    {
        return app(TradeService::class)->propose(
            $stagione,
            $da,
            $a,
            [$this->firstOfRole($rosaDa, 'C')->id],
            [$this->firstOfRole($rosaA, 'C')->id],
            1,
        );
    }

    public function test_a_percentuale_piena_il_bot_accetta(): void
    {
        [$stagione, $persona, $bot, $rp, $rb] = $this->mercato();
        $proposta = $this->proponi($stagione, $persona, $bot, $rp, $rb);

        $esito = app(BotTrader::class)->rispondi($stagione->id, percentuale: 100);

        $this->assertSame(1, $esito['accettate']);
        $this->assertSame('accepted', $proposta->fresh()->state);
    }

    public function test_a_percentuale_zero_il_bot_rifiuta(): void
    {
        [$stagione, $persona, $bot, $rp, $rb] = $this->mercato();
        $proposta = $this->proponi($stagione, $persona, $bot, $rp, $rb);

        $esito = app(BotTrader::class)->rispondi($stagione->id, percentuale: 0);

        $this->assertSame(1, $esito['rifiutate']);
        $this->assertSame('rejected', $proposta->fresh()->state);
    }

    public function test_le_carte_si_spostano_davvero(): void
    {
        // Accettare senza muovere le carte sarebbe il modo più silenzioso di
        // avere un mercato che sembra funzionare e non funziona.
        [$stagione, $persona, $bot, $rp, $rb] = $this->mercato();

        $mia = $this->firstOfRole($rp, 'C');
        $sua = $this->firstOfRole($rb, 'C');

        app(TradeService::class)->propose($stagione, $persona, $bot, [$mia->id], [$sua->id], 1);
        app(BotTrader::class)->rispondi($stagione->id, percentuale: 100);

        $this->assertSame($bot->id, $mia->fresh()->owner_manager_id);
        $this->assertSame($persona->id, $sua->fresh()->owner_manager_id);
    }

    public function test_non_tocca_le_proposte_fra_persone(): void
    {
        // ⚠️ Il bot risponde solo per sé: rispondere al posto di una persona
        // le toglierebbe di mano la propria squadra.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $giulia = $this->makeManager($stagione, 'Giulia');

        $rm = $this->makeRoster($marco, 'PPDDDDDCCCCCAAA');
        $rg = $this->makeRoster($giulia, 'PPDDDDDCCCCCAAA');

        $proposta = $this->proponi($stagione, $marco, $giulia, $rm, $rg);

        app(BotTrader::class)->rispondi($stagione->id, percentuale: 100);

        $this->assertSame('pending', $proposta->fresh()->state);
    }

    public function test_un_bot_non_accetta_uno_scambio_che_lo_lascia_inschierabile(): void
    {
        // La moneta dice sì, il pavimento dice no — e vince il pavimento.
        // `accept()` lo ricontrolla sotto lock, quindi il bot non può
        // svuotarsi la rosa nemmeno volendo.
        $stagione = $this->makeLeague();
        $persona = $this->makeManager($stagione, 'Marco');
        $bot = $this->makeManager($stagione, 'Automatica Bergamo');
        $bot->update(['is_bot' => true]);

        $rosaPersona = $this->makeRoster($persona, 'PPDDDDDCCCCCAAA');
        $rosaBot = $this->makeRoster($bot, 'PDDDDCCCCAA');   // esattamente gli undici

        // Il bot cederebbe il suo unico portiere.
        app(TradeService::class)->propose(
            $stagione,
            $persona,
            $bot,
            [$this->firstOfRole($rosaPersona, 'C')->id],
            [$this->firstOfRole($rosaBot, 'P')->id],
            1,
        );

        $esito = app(BotTrader::class)->rispondi($stagione->id, percentuale: 100);

        $this->assertSame(0, $esito['accettate']);
        $this->assertSame(1, $esito['rifiutate']);
        $this->assertSame($bot->id, $this->firstOfRole($rosaBot, 'P')->fresh()->owner_manager_id);
    }

    public function test_il_comando_gira_senza_proposte(): void
    {
        $this->artisan('bot:scambi')->assertSuccessful();
    }
}
