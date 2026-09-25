<?php

namespace Tests\Feature;

use App\Support\Battito;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Il cron è l'unico pezzo che vive fuori dall'applicazione.
 *
 * Ed è l'unico che, quando manca, non produce nessun errore: il draft smette
 * di avanzare da solo e sembra soltanto che il gioco sia lento. Il battito
 * serve a distinguere «non c'è» da «è lento», e questi test servono a evitare
 * che il battito stesso menta — un indicatore verde su un cron fermo sarebbe
 * peggio di nessun indicatore.
 */
class CronTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Battito::dimentica();
    }

    private function eventoBattito(): Event
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn (Event $e) => str_contains((string) $e->description, 'scheduler è passato'));

        $this->assertNotNull($evento, 'Lo scheduler non registra il battito.');

        return $evento;
    }

    public function test_lo_scheduler_registra_il_battito(): void
    {
        $this->assertSame('* * * * *', $this->eventoBattito()->expression);
    }

    public function test_il_battito_e_separato_dal_tick_del_draft(): void
    {
        // ⚠️ Se il battito fosse un effetto di `draft:tick`, un lock rimasto
        // appeso lo spegnerebbe e si leggerebbe «cron fermo» mentre il cron
        // gira benissimo: si andrebbe a cercare il guasto dalla parte sbagliata.
        //
        // ⚠️ Si controlla che siano DUE COSE DISTINTE, non che lo scheduler ne
        // abbia due in tutto: contarli legava il test al numero di lavori
        // registrati, e si rompeva ogni volta che se ne aggiungeva uno — cosa
        // che non ha niente a che vedere con quello che deve garantire.
        $eventi = collect(app(Schedule::class)->events());

        $tick = $eventi->first(fn (Event $e) => str_contains((string) $e->command, 'draft:tick'));

        $this->assertNotNull($tick, 'Lo scheduler non registra `draft:tick`.');
        $this->assertNotSame($tick, $this->eventoBattito());

        // E il battito non deve avere un lock: se ce l'avesse, un lock appeso
        // lo spegnerebbe ed è proprio il caso che deve saper segnalare.
        $this->assertNull($this->eventoBattito()->mutexName ?? null);
    }

    public function test_un_giro_dello_scheduler_lascia_il_segno(): void
    {
        $this->assertSame('mai', Battito::stato());

        $this->eventoBattito()->run(app());

        $this->assertSame('vivo', Battito::stato());
    }

    public function test_senza_nessun_giro_risulta_mai_partito(): void
    {
        $this->assertSame('mai', Battito::stato());
        $this->assertNull(Battito::eta());
    }

    public function test_un_battito_vecchio_risulta_fermo(): void
    {
        // È il caso che conta: il cron c'era e ha smesso. Senza distinguerlo da
        // «mai partito» si finirebbe a riscrivere una riga di crontab giusta.
        CarbonImmutable::setTestNow(now()->subHours(2));
        Battito::segna();
        CarbonImmutable::setTestNow();

        $this->assertSame('fermo', Battito::stato());
        $this->assertFalse(Battito::vivo());
    }

    public function test_cinque_minuti_di_ritardo_non_sono_un_allarme(): void
    {
        // Su Hostpoint il cron minimo è ogni cinque minuti: una soglia stretta
        // sarebbe un allarme perennemente acceso, cioè un allarme ignorato.
        CarbonImmutable::setTestNow(now()->subMinutes(6));
        Battito::segna();
        CarbonImmutable::setTestNow();

        $this->assertSame('vivo', Battito::stato());
    }

    // ───────────────────────── come si legge ─────────────────────────

    public function test_controlla_dice_che_il_cron_gira(): void
    {
        Battito::segna();

        $this->artisan('controlla')->expectsOutputToContain('Il cron gira');
    }

    public function test_controlla_distingue_mai_partito_da_fermo(): void
    {
        $this->artisan('controlla')->expectsOutputToContain('non è mai passato');

        CarbonImmutable::setTestNow(now()->subHours(2));
        Battito::segna();
        CarbonImmutable::setTestNow();

        $this->artisan('controlla')->expectsOutputToContain('si è fermato');
    }

    public function test_il_cron_mai_partito_non_blocca_la_pubblicazione(): void
    {
        // `pubblica.sh` lancia `controlla` subito dopo aver caricato, quando il
        // cron non può ancora essere passato: farne un errore grave farebbe
        // leggere «pubblicazione fallita» a chi deve solo aspettare un minuto.
        $this->artisan('controlla')->expectsOutputToContain('Se l\'hai appena installato');
    }

    public function test_la_pagina_di_gestione_mostra_il_battito(): void
    {
        // Verificare il cron non deve richiedere di collegarsi al server.
        $admin = tap($this->makeManager($this->makeLeague(), 'Capo'))->update(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.gestione'))
            ->assertOk()
            ->assertSee('Cron mai partito');

        Battito::segna();

        $this->actingAs($admin)
            ->get(route('admin.gestione'))
            ->assertOk()
            ->assertSee('Cron attivo');
    }
}
