<?php

namespace Tests\Feature;

use App\Models\Card;
use App\Models\Draft;
use App\Models\LeagueSeason;
use App\Models\Manager;
use App\Models\PlayerScore;
use App\Models\PlayerStat;
use App\Models\Standing;
use App\Services\Calendar\CalendarBuilder;
use App\Services\Lineup\LineupSaver;
use App\Services\Season\SeasonRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * La giornata nei suoi tre momenti, invece che in un colpo solo.
 *
 * ⚠️ Prima «gioca la giornata» faceva tutto insieme: statistiche, voti, sfide,
 * classifica, tornei e draft. Ma i risultati veri arrivano alla spicciolata —
 * sabato alle 15, domenica sera, il lunedì — e fra «è cominciata» e «è finita»
 * c'è una finestra lunga giorni che nel modello non esisteva. Non avendola, due
 * cose non avevano un posto dove stare: il congelamento delle formazioni, che
 * veniva dedotto dalla scadenza del draft, e i voti parziali, che non si
 * potevano guardare senza far muovere la classifica.
 *
 * E c'era il rovescio peggiore: il draft si apriva con DUE giornate di
 * anticipo, quindi le carte della giornata dopo esistevano prima che questa
 * fosse giocata — e comparivano in formazione, elencate per nome, di una
 * giornata che il manager credeva ancora da pescare.
 */
class GiornataInCorsoTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private LeagueSeason $league;

    /** @var Collection<int,Manager> */
    private Collection $squadre;

    private SeasonRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->league = $this->makeLeague();
        $this->runner = app(SeasonRunner::class);

        $this->squadre = collect(['Ada', 'Bruno'])
            ->map(fn (string $n) => $this->makeManager($this->league, $n));

        foreach (range(1, 4) as $g) {
            $this->makeFixture($g, now()->subYears(2)->addWeeks($g)->toDateTimeString())
                ->update(['status' => 'finished', 'home_goals' => 2, 'away_goals' => 1]);
        }

        collect(str_split(str_repeat('P', 20).str_repeat('D', 40).str_repeat('C', 40).str_repeat('A', 30)))
            ->each(function (string $ruolo) {
                $player = $this->makePlayer($ruolo);

                foreach (range(1, 4) as $g) {
                    $this->makePlayerPower($player, $g, stagione: $this->league);
                }
            });

        app(CalendarBuilder::class)->generate($this->league, 1, 1);

        foreach ($this->squadre as $manager) {
            $this->makeRoster($manager, 'PDDDDCCCCAA', matchday: 1);
        }
    }

    /** Una statistica scaricata sul serio, come ne resta dopo un azzeramento. */
    private function statisticaVera(int $matchday, int $squadra = 500): PlayerStat
    {
        return PlayerStat::create([
            'player_id' => $this->makePlayer('A', teamId: $squadra)->id,
            'season' => self::ANNATA,
            'matchday' => $matchday,
            'minutes' => 90,
            'rating' => 7.0,
            'source' => 'reale',
        ]);
    }

    // ───────────────────────── ① il via ─────────────────────────

    public function test_iniziare_congela_le_formazioni(): void
    {
        $saver = app(LineupSaver::class);

        $this->assertFalse($saver->isLocked($this->league, 1), 'prima del via si schiera');

        $this->runner->iniziaGiornata($this->league, 1);

        $this->assertTrue($saver->isLocked($this->league->refresh(), 1));
    }

    public function test_iniziare_apre_il_draft_della_giornata_successiva(): void
    {
        // ⚠️ La N+1, non la N+2. Lo sfasamento di due è ciò che faceva
        // comparire in formazione le carte non ancora pescate.
        $this->runner->iniziaGiornata($this->league, 1);

        $this->assertNotNull(Draft::where('league_season_id', $this->league->id)->where('matchday', 2)->first());
        $this->assertNull(Draft::where('league_season_id', $this->league->id)->where('matchday', 3)->first());
    }

    public function test_una_giornata_non_comincia_due_volte(): void
    {
        $this->runner->iniziaGiornata($this->league, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('è già cominciata');

        $this->runner->iniziaGiornata($this->league->refresh(), 1);
    }

    public function test_la_giornata_in_corso_si_sa_qual_e(): void
    {
        $this->assertNull($this->runner->inCorso($this->league));

        $this->runner->iniziaGiornata($this->league, 1);

        $this->assertSame(1, $this->runner->inCorso($this->league->refresh()));
    }

    // ───────────────────────── ② i parziali ─────────────────────────

    public function test_i_parziali_scrivono_i_voti_ma_non_la_classifica(): void
    {
        // ⚠️ È il punto di tutta la separazione. Un punteggio calcolato a metà
        // giornata non è un risultato provvisorio: è un risultato sbagliato,
        // perché mezza lega ha ancora i titolari in campo.
        $this->runner->iniziaGiornata($this->league, 1);

        $esito = $this->runner->parziali($this->league->refresh(), 1, simula: true);

        $this->assertGreaterThan(0, $esito['voti']);
        $this->assertGreaterThan(0, PlayerScore::where('league_season_id', $this->league->id)->count());

        $this->assertSame(0, Standing::where('league_season_id', $this->league->id)->count());
        $this->assertSame([], $this->runner->giocate($this->league));
    }

    public function test_i_parziali_si_rilanciano_quante_volte_si_vuole(): void
    {
        // Arrivano alla spicciolata: questo è il passo che si ripete.
        $this->runner->iniziaGiornata($this->league, 1);

        $primo = $this->runner->parziali($this->league->refresh(), 1, simula: true);
        $secondo = $this->runner->parziali($this->league->refresh(), 1, simula: true);

        $this->assertSame($primo['voti'], $secondo['voti']);
        $this->assertSame(0, Standing::where('league_season_id', $this->league->id)->count());
    }

    // ───────────────────────── i dati che c'erano già ─────────────────────────

    public function test_le_statistiche_vere_bloccano_la_simulazione(): void
    {
        // ⚠️ Il caso capita da sé, senza che nessuno sbagli niente: le
        // statistiche vere sopravvivono all'azzeramento di una stagione, quindi
        // una giornata scaricata mesi fa resta scaricata anche dopo aver
        // riportato la lega al foglio bianco.
        $this->statisticaVera(2);

        $this->runner->iniziaGiornata($this->league, 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('riscrivi sopra le statistiche vere');

        $this->runner->parziali($this->league->refresh(), 2, simula: true);
    }

    public function test_si_puo_forzare_la_riscrittura(): void
    {
        // ⚠️ Il motore lo sapeva fare da sempre, ma il controller non glielo
        // chiedeva mai: il messaggio di rifiuto diceva «forza la
        // sovrascrittura» e in tutta l'interfaccia non c'era un modo per farlo.
        $this->statisticaVera(2);

        $this->runner->iniziaGiornata($this->league, 2);

        $esito = $this->runner->parziali(
            $this->league->refresh(), 2, simula: true, sovrascriviReali: true,
        );

        $this->assertSame('simulata', $esito['fonte']);
        $this->assertSame(0, PlayerStat::where('matchday', 2)->where('source', 'reale')->count());
    }

    public function test_la_forzatura_arriva_dalla_pagina(): void
    {
        $admin = tap($this->squadre->first())->update(['is_admin' => true]);

        $this->statisticaVera(2);
        $this->runner->iniziaGiornata($this->league, 2);

        // Senza spunta si viene respinti, col motivo scritto.
        $this->actingAs($admin)
            ->post(route('admin.stagione.parziali', $this->league), ['giornata' => 2, 'simula' => 1])
            ->assertSessionHasErrors('stagione');

        $this->actingAs($admin)
            ->post(route('admin.stagione.parziali', $this->league), [
                'giornata' => 2, 'simula' => 1, 'sovrascrivi' => 1,
            ])
            ->assertSessionHas('successo');

        $this->assertSame(0, PlayerStat::where('matchday', 2)->where('source', 'reale')->count());
    }

    public function test_uno_scarico_a_meta_si_riconosce(): void
    {
        // ⚠️ È il «all'Atalanta manca mezza squadra»: un download interrotto —
        // per il tetto di chiamate, o perché le partite non erano finite —
        // lascia una giornata che ESISTE ma copre solo una parte del campo. Il
        // tabellone mostra mezze squadre e niente dice perché.
        $this->statisticaVera(2, squadra: 500);

        $stato = $this->runner->statoStatistiche($this->league, 2);

        $this->assertSame(1, $stato['reali']);
        $this->assertSame(1, $stato['partite']);
        $this->assertSame(0, $stato['partiteConDati'], 'con una squadra sola la partita non è coperta');
    }

    public function test_una_giornata_intera_risulta_coperta(): void
    {
        foreach ([500, 501] as $squadra) {
            $this->statisticaVera(2, squadra: $squadra);
        }

        $stato = $this->runner->statoStatistiche($this->league, 2);

        $this->assertSame(1, $stato['partiteConDati']);
        $this->assertSame(1, $stato['partite']);
    }

    // ───────────────────────── ③ la chiusura ─────────────────────────

    public function test_chiudere_scrive_la_classifica(): void
    {
        $this->runner->iniziaGiornata($this->league, 1);
        $this->runner->parziali($this->league->refresh(), 1, simula: true);

        $esito = $this->runner->chiudiGiornata($this->league->refresh(), 1);

        $this->assertSame(2, $esito['formazioni']);
        $this->assertSame(2, $esito['classifica']);
        $this->assertSame([1], $this->runner->giocate($this->league));
    }

    public function test_a_giornata_chiusa_non_c_e_piu_niente_in_corso(): void
    {
        $this->runner->iniziaGiornata($this->league, 1);
        $this->runner->parziali($this->league->refresh(), 1, simula: true);
        $this->runner->chiudiGiornata($this->league->refresh(), 1);

        $this->assertNull($this->runner->inCorso($this->league->refresh()));
        $this->assertSame(2, $this->runner->prossima($this->league));
    }

    // ───────────────────────── il giro intero ─────────────────────────

    public function test_una_giornata_dopo_l_altra_senza_anticipi(): void
    {
        // ⚠️ Il test che tiene chiuso lo spoiler: a ogni passo esiste al
        // massimo la rosa della giornata in corso e di quella dopo — mai una
        // terza, che è quello che si vedeva comparire in formazione.
        foreach ([1, 2] as $g) {
            $this->runner->iniziaGiornata($this->league->refresh(), $g);
            $this->runner->concludiDraft($this->league->refresh());

            $this->assertSame(
                0,
                Card::where('league_season_id', $this->league->id)->where('matchday', $g + 2)->count(),
                "alla {$g}ª non devono esistere carte della ".($g + 2).'ª',
            );

            $this->runner->parziali($this->league->refresh(), $g, simula: true);
            $this->runner->chiudiGiornata($this->league->refresh(), $g);
        }

        $this->assertSame([1, 2], $this->runner->giocate($this->league));
    }

    public function test_l_acceleratore_fa_i_tre_passi_nell_ordine_giusto(): void
    {
        // `gioca()` resta, ma adesso è la composizione dei tre momenti: serve a
        // rigiocare un'annata archiviata, dove non c'è nessuna attesa da
        // rispettare.
        $esito = $this->runner->gioca($this->league, 1, simula: true);

        $this->assertSame('simulata', $esito['fonte']);
        $this->assertGreaterThan(0, $esito['voti']);
        $this->assertSame(2, $esito['classifica']);
        $this->assertSame(1, $this->league->refresh()->started_matchday);
        $this->assertNotNull(Draft::where('league_season_id', $this->league->id)->where('matchday', 2)->first());
    }

    // ───────────────────────── dalla pagina ─────────────────────────

    public function test_i_tre_pulsanti_dalla_gestione(): void
    {
        $admin = tap($this->squadre->first())->update(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.stagione.inizia', $this->league), ['giornata' => 1, 'ore' => 24])
            ->assertSessionHas('successo');

        $this->assertSame(1, $this->runner->inCorso($this->league->refresh()));

        $this->actingAs($admin)
            ->post(route('admin.stagione.parziali', $this->league), ['giornata' => 1, 'simula' => 1])
            ->assertSessionHas('successo');

        $this->assertSame(0, Standing::where('league_season_id', $this->league->id)->count());

        $this->actingAs($admin)
            ->post(route('admin.stagione.chiudi', $this->league), ['giornata' => 1])
            ->assertSessionHas('successo');

        $this->assertSame([1], $this->runner->giocate($this->league));
    }
}
