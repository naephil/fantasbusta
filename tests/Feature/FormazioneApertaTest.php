<?php

namespace Tests\Feature;

use App\Models\Draft;
use App\Models\Standing;
use App\Services\Lineup\LineupSaver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLeague;
use Tests\TestCase;

/**
 * Quando la formazione si può ancora toccare.
 *
 * ⚠️ Il bug che questi test tengono chiuso ha reso il gioco INGIOCABILE a un
 * test coi volontari: `isLocked` guardava il primo fischio vero della giornata
 * di Serie A, ma le annate si giocano ricaricate — il calendario è nel passato,
 * quindi ogni fischio era già suonato e ogni formazione nasceva bloccata.
 * Nessuno poteva schierare, e il messaggio «il primo fischio è passato» mandava
 * a cercare il guasto nell'orologio invece che nella regola.
 *
 * Il rimedio di allora fu guardare la scadenza del draft. Meglio, ma ancora un
 * conto sulle date: quella finestra è una quota calcolata fra due primi fischi,
 * quindi «da quando non posso più schierare» restava una deduzione.
 *
 * La regola di adesso è un FATTO: la giornata è cominciata perché qualcuno l'ha
 * dichiarata. È l'unica che funziona uguale su una stagione in diretta e su una
 * rigiocata dieci anni dopo, perché non dipende dall'orologio.
 */
class FormazioneApertaTest extends TestCase
{
    use BuildsLeague, RefreshDatabase;

    private function saver(): LineupSaver
    {
        return app(LineupSaver::class);
    }

    /** Il draft di una giornata, con la finestra che si vuole. */
    private function draft(int $leagueSeasonId, int $matchday, string $scadenza): Draft
    {
        return Draft::create([
            'league_season_id' => $leagueSeasonId,
            'matchday' => $matchday,
            'state' => 'open',
            'opens_at' => now()->subHour(),
            'deadline_at' => $scadenza,
            'rounds' => 5,
            'pack_size' => 5,
        ]);
    }

    // ───────────────────────── il blocco ─────────────────────────

    public function test_un_annata_passata_non_nasce_gia_bloccata(): void
    {
        // È il caso reale: si gioca il 2023/24 nel 2026, quindi il primo
        // fischio vero è passato da due anni. La finestra però è aperta adesso.
        $stagione = $this->makeLeague();
        $this->makeFixture(1, '2023-08-20 18:00:00');
        $this->draft($stagione->id, 1, now()->addHours(48));

        $this->assertFalse($this->saver()->isLocked($stagione, 1));
    }

    public function test_la_giornata_cominciata_blocca_la_formazione(): void
    {
        // ⚠️ La regola è questa e non più la scadenza del draft. Le due
        // sembravano equivalenti — il draft chiude poco prima delle partite —
        // ma la finestra del draft è una QUOTA calcolata fra due primi fischi:
        // «da quando non posso più schierare» finiva per dipendere da un conto
        // sulle date invece che da un fatto.
        $stagione = $this->makeLeague();
        $this->makeFixture(1, '2023-08-20 18:00:00');
        $this->draft($stagione->id, 1, now()->subMinute());

        $this->assertFalse($this->saver()->isLocked($stagione, 1), 'il draft scaduto da solo non blocca niente');

        $stagione->update(['started_matchday' => 1]);

        $this->assertTrue($this->saver()->isLocked($stagione, 1));
    }

    public function test_una_giornata_cominciata_blocca_anche_le_precedenti(): void
    {
        // «Iniziata» lo preme una persona, e le persone saltano dei passaggi:
        // senza questo, una giornata dimenticata resterebbe schierabile per
        // sempre e ci si potrebbe mettere la formazione a partite finite.
        $stagione = $this->makeLeague();
        $this->makeFixture(3, now()->addWeek()->toDateTimeString());

        $stagione->update(['started_matchday' => 3]);

        $this->assertTrue($this->saver()->isLocked($stagione, 1));
        $this->assertTrue($this->saver()->isLocked($stagione, 3));
        $this->assertFalse($this->saver()->isLocked($stagione, 4));
    }

    public function test_una_giornata_gia_chiusa_non_si_ritocca(): void
    {
        // Rete di sicurezza: i punti sono in classifica da un pezzo, e
        // riscrivere la formazione che li ha prodotti cambierebbe la storia
        // sotto gli occhi di chi l'ha già letta.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        Standing::create([
            'league_season_id' => $stagione->id,
            'manager_id' => $marco->id,
            'matchday' => 1,
            'punti' => 3,
            'fantapunti' => 70,
            'posizione' => 1,
        ]);

        $this->assertTrue($this->saver()->isLocked($stagione, 1));
    }

    public function test_il_primo_fischio_resta_un_informazione_non_una_scadenza(): void
    {
        // Serve a dire «a che ora conviene aver deciso», non a bloccare: su
        // un'annata ricaricata è una data del passato, e prenderla per una
        // scadenza è il bug che rendeva il gioco ingiocabile.
        $stagione = $this->makeLeague();
        $this->makeFixture(1, now()->addHours(48)->toDateTimeString());

        $this->assertEqualsWithDelta(
            48,
            now()->diffInHours($this->saver()->lockAt($stagione, 1)),
            0.1,
        );

        $this->assertFalse($this->saver()->isLocked($stagione, 1));
    }

    // ───────────────────────── le partite di Serie A ─────────────────────────

    public function test_la_pagina_mostra_le_partite_della_giornata_che_si_schiera(): void
    {
        // ⚠️ Quelle della giornata per cui vale la formazione, non di quella in
        // corso: chi apre questa pagina decide per il weekend che deve ancora
        // arrivare.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        $this->makeFixture(1, now()->addDay()->toDateTimeString());
        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);

        $this->actingAs($marco)
            ->get(route('lineup.show'))
            ->assertOk()
            ->assertSee('Serie A · la 1ª')
            ->assertSee('Squadra di prova');
    }

    public function test_accanto_alla_partita_ci_sono_le_proprie_carte(): void
    {
        // È il calendario della PROPRIA ROSA, non quello di Serie A: senza i
        // nomi sarebbe un'informazione che si trova ovunque, e per ricostruire
        // chi dei tuoi gioca contro chi bisognava aprire il listone e cercare
        // squadra per squadra.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        $this->makeFixture(1, now()->addDay()->toDateTimeString());
        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);

        $risposta = $this->actingAs($marco)->get(route('lineup.show'))->assertOk();

        // Le carte portano anche l'anteprima, come ovunque compaia un nome.
        $risposta->assertSee('data-carta="'.$rosa->first()->id.'"', escape: false);
    }

    public function test_senza_calendario_la_sezione_non_compare(): void
    {
        // Un riquadro vuoto fa sembrare che manchi qualcosa.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);

        $this->actingAs($marco)
            ->get(route('lineup.show'))
            ->assertOk()
            ->assertDontSee('Serie A · la');
    }

    // ───────────────────────── quale giornata ─────────────────────────

    public function test_si_schiera_la_giornata_da_giocare_non_l_ultima_pescata(): void
    {
        // Si prende la PIÙ VECCHIA da giocare, non l'ultima pescata: prendendo
        // il massimo la pagina salterebbe alla giornata dopo mentre questa è
        // ancora da schierare. Capita ogni volta che una giornata comincia,
        // perché è in quel momento che apre il draft della successiva.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);
        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 2);

        $this->assertSame(1, $marco->currentMatchday($stagione));
    }

    public function test_a_giornata_cominciata_si_passa_a_quella_dopo(): void
    {
        /*
         * ⚠️ Fra il fischio d'inizio e la chiusura c'è una finestra lunga
         * giorni in cui la giornata non è ancora «giocata» — la classifica non
         * si è mossa — ma la formazione è congelata da un pezzo.
         *
         * Prendendo la prima non giocata, la pagina restava ferma lì per tutto
         * quel tempo: si apriva «Giornata chiusa», non c'era niente da fare, e
         * intanto la giornata successiva — l'unica su cui si potesse ancora
         * decidere qualcosa — non la mostrava nessuno.
         */
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);
        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 2);

        $this->assertSame(1, $marco->currentMatchday($stagione));

        $stagione->update(['started_matchday' => 1]);

        $this->assertSame(2, $marco->currentMatchday($stagione->refresh()));
    }

    public function test_senza_una_giornata_aperta_resta_leggibile_quella_in_corso(): void
    {
        // Il ripiego: se la sola giornata che ho è già cominciata, meglio
        // vederla in sola lettura che una pagina che dice che non c'è niente.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);
        $stagione->update(['started_matchday' => 1]);

        $this->assertSame(1, $marco->currentMatchday($stagione->refresh()));
    }

    public function test_giocata_la_prima_si_passa_alla_seconda(): void
    {
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);
        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 2);

        Standing::create([
            'league_season_id' => $stagione->id,
            'manager_id' => $marco->id,
            'matchday' => 1,
            'punti' => 3,
            'fantapunti' => 74.5,
            'posizione' => 1,
        ]);

        $this->assertSame(2, $marco->currentMatchday($stagione));
    }

    public function test_a_stagione_finita_resta_leggibile_l_ultima(): void
    {
        // Meglio l'ultima giornata in sola lettura che una pagina che dice
        // «non c'è niente» quando l'archivio invece c'è.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');

        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);

        Standing::create([
            'league_season_id' => $stagione->id,
            'manager_id' => $marco->id,
            'matchday' => 1,
            'punti' => 3,
            'fantapunti' => 74.5,
            'posizione' => 1,
        ]);

        $this->assertSame(1, $marco->currentMatchday($stagione));
    }

    public function test_le_giornate_di_un_altro_gruppo_non_spostano_le_mie(): void
    {
        // Le classifiche si guardano per lega: un altro gruppo che rigioca la
        // stessa annata non deve far saltare una giornata a questo.
        $mia = $this->makeLeague('Mia');
        $altra = $this->makeLeague('Altra');

        $marco = $this->makeManager($mia, 'Marco');
        $giulia = $this->makeManager($altra, 'Giulia');

        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1, stagione: $mia);
        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 2, stagione: $mia);

        Standing::create([
            'league_season_id' => $altra->id,
            'manager_id' => $giulia->id,
            'matchday' => 1,
            'punti' => 3,
            'fantapunti' => 70.0,
            'posizione' => 1,
        ]);

        $this->assertSame(1, $marco->currentMatchday($mia));
    }

    // ───────────────────────── fino allo schermo ─────────────────────────

    public function test_la_pagina_porta_il_campo_e_la_rosa(): void
    {
        // ⚠️ Prima si spuntavano le carte da un elenco: era facilissimo
        // ritrovarsi con dodici titolari o tre difensori di troppo, scoprirlo
        // solo al salvataggio e non capire dove. Il campo dispone i posti dal
        // modulo, quindi le quote sono rispettate per costruzione.
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAAC', matchday: 1);
        $this->draft($stagione->id, 1, now()->addHours(48));

        $risposta = $this->actingAs($marco)->get(route('lineup.show'))->assertOk();

        $risposta->assertSee('data-campo', false);
        $risposta->assertSee('Salva formazione');

        // La rosa viaggia al client: senza, il campo non ha nessuno da mettere
        // nelle tendine e resterebbe undici caselle vuote.
        $risposta->assertSee('data-rosa', false);
        $risposta->assertSee($rosa->first()->player->last_name);
    }

    public function test_a_giornata_chiusa_non_c_e_niente_da_salvare(): void
    {
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);

        $stagione->update(['started_matchday' => 1]);

        $this->actingAs($marco)
            ->get(route('lineup.show'))
            ->assertOk()
            ->assertSee('Giornata chiusa')
            ->assertDontSee('Salva formazione');
    }

    public function test_la_pagina_lascia_schierare_e_salva(): void
    {
        $stagione = $this->makeLeague();
        $marco = $this->makeManager($stagione, 'Marco');
        $rosa = $this->makeRoster($marco, 'PDDDDCCCCAA', matchday: 1);

        $this->makeFixture(1, '2023-08-20 18:00:00');   // fischio vero, nel passato
        $this->draft($stagione->id, 1, now()->addHours(48));

        $this->actingAs($marco)
            ->get(route('lineup.show'))
            ->assertOk()
            ->assertSee('Schiera')
            ->assertDontSee('non si tocca più');

        $this->actingAs($marco)->post(route('lineup.store'), [
            'module' => '4-4-2',
            'titolari' => $rosa->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('lineups', [
            'manager_id' => $marco->id,
            'matchday' => 1,
            'module' => '4-4-2',
        ]);
    }
}
