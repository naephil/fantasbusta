<?php

namespace App\Services\Draft;

use App\Models\Draft;
use App\Models\DraftPoolEntry;
use App\Models\DraftTurn;
use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\PlayerPower;
use App\Models\Standing;
use App\Services\Lineup\ModuleValidator;
use App\Services\Scoring\Settings;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Prepara un draft: pool esclusivo e coda dei turni snake.
 *
 * È il pezzo che rende eseguibile il ciclo di gioco. `DraftTick` sa far
 * avanzare una coda e `PackGenerator` sa pescare da un pool, ma né l'una né
 * l'altro esistono finché qualcuno non li crea — ed è questo.
 *
 * Il draft della giornata M apre al primo fischio della M−1 e usa la classifica
 * dopo la M−2, lo stesso sfasamento del power score: quando il draft parte, la
 * giornata precedente è ancora in corso.
 */
class DraftBuilder
{
    /**
     * @param  list<int>|null  $order  ordine del primo giro; altrimenti l'inverso della classifica
     *
     * @throws RuntimeException
     */
    public function build(
        LeagueSeason $stagione,
        int $matchday,
        ?DateTimeInterface $opensAt = null,
        ?DateTimeInterface $deadlineAt = null,
        ?array $order = null,
    ): Draft {
        if (Draft::where('league_season_id', $stagione->id)->where('matchday', $matchday)->exists()) {
            throw new RuntimeException("Il draft della giornata {$matchday} esiste già.");
        }

        $settings = Settings::for($stagione);
        $pool = $this->poolRows($stagione, $matchday);

        if ($pool === []) {
            throw new RuntimeException(
                "Nessun power score per la giornata {$matchday}: lancia prima `power:compute {$matchday}`.",
            );
        }

        $ordine = $order ?? $this->pickOrder($stagione, $matchday);

        if ($ordine === []) {
            throw new RuntimeException("La lega {$stagione->league->name} non ha manager attivi.");
        }

        // ⚠️ Il pool deve bastare almeno a rendere ognuno SCHIERABILE.
        //
        // Il minimo non sono le carte per busta ma la garanzia — undici a
        // testa, {P:1 D:4 C:4 A:2} — perché è lì che il difetto smette di
        // essere una questione di qualità e diventa un guasto: `PackGenerator`
        // salta gli slot che non può riempire senza protestare, e la rosa corta
        // si scopre solo quando `AutoLineup` non compone nessun modulo, a
        // giornata in corso. Una rosa più magra del previsto è invece
        // legittima: capita con leghe piccole o listoni corti, e si gioca.
        $minimo = count($ordine) * array_sum(ModuleValidator::GUARANTEE);

        if (count($pool) < $minimo) {
            throw new RuntimeException(sprintf(
                'Il listone della giornata %d ha %d giocatori: con %d manager non bastano '
                .'nemmeno gli %d che servono a farli scendere in campo tutti. '
                .'Rilancia l\'import del listone e guarda quanti finiscono fra «mancanti».',
                $matchday, count($pool), count($ordine), $minimo,
            ));
        }

        [$apre, $chiude] = $this->window($stagione->season, $matchday, $settings, $opensAt, $deadlineAt);

        return DB::transaction(function () use ($stagione, $matchday, $apre, $chiude, $pool, $ordine, $settings) {
            $draft = Draft::create([
                'league_season_id' => $stagione->id,
                'matchday' => $matchday,
                'state' => 'pending',
                'opens_at' => $apre,
                'deadline_at' => $chiude,
                // Espliciti e non lasciati al default della tabella: quello
                // vive nel database, non sull'istanza appena creata, e leggerlo
                // da qui darebbe null proprio mentre si costruisce lo snake.
                'rounds' => $settings->giriDraft(),
                'pack_size' => $settings->cartePerBusta(),
            ]);

            $this->fillPool($draft, $pool);
            $this->buildSnake($draft, $ordine);

            return $draft;
        });
    }

    /**
     * Il listone della giornata, col tier congelato dal power score.
     *
     * Il ruolo esce dal listone di QUELL'ANNO: lo stesso giocatore puo' essere
     * stato difensore nel 2022 e centrocampista adesso, e una carta pescata in
     * una stagione replay deve riportare il ruolo che aveva allora.
     *
     * @return list<array{player_id: int, tier: string, role: string}>
     */
    private function poolRows(LeagueSeason $stagione, int $matchday): array
    {
        return PlayerPower::query()
            ->join('player_seasons', function ($join) use ($stagione) {
                $join->on('player_seasons.player_id', '=', 'player_power.player_id')
                    ->where('player_seasons.season', '=', $stagione->season);
            })
            ->where('player_power.league_season_id', $stagione->id)
            ->where('player_power.matchday', $matchday)
            ->where('player_seasons.active', true)
            // ⚠️ Solo chi sta nel listone, e questa riga vale una spiegazione.
            //
            // L'anagrafica di API-Football non è il listone: restituisce le rose
            // REGISTRATE per intero — primavera, ceduti a gennaio, terzi
            // portieri mai convocati — e `StatSync` ne aggiunge altri pescandoli
            // dai tabellini. Su un'annata vera fanno un migliaio di righe contro
            // le ~540 del listone.
            //
            // Lasciarli nel pool non aggiungeva scelta, aggiungeva rumore: quei
            // seicento non hanno quotazione (restano a 1) e non hanno giocato,
            // quindi il loro power è zero e sono tutti in parità fra loro — la
            // classifica fra pari la decide l'id, cioè il caso. Ma occupavano
            // metà della piramide, e ogni pescata aveva una probabilità concreta
            // di finire su uno di loro. È il «ci ritroviamo giocatori che non
            // giocano mai» visto in partita, e non era il pescaggio: era chi
            // c'era dentro l'urna.
            //
            // Il listone è per definizione l'elenco di chi è in gioco. Chi non
            // c'è, non c'è.
            ->where('player_seasons.role_confirmed', true)
            ->get(['player_power.player_id', 'player_power.tier', 'player_seasons.role'])
            ->map(fn ($r) => [
                'player_id' => (int) $r->player_id,
                'tier' => $r->tier,
                'role' => $r->role,
            ])
            ->all();
    }

    /**
     * Ogni giocatore in una sola copia: è la garanzia di esclusività del pool.
     *
     * @param  list<array{player_id: int, tier: string, role: string}>  $rows
     */
    private function fillPool(Draft $draft, array $rows): void
    {
        $righe = array_map(fn (array $r) => $r + [
            'draft_id' => $draft->id,
            'status' => 'available',
        ], $rows);

        // A colpi da 500: il listone è di ~550 righe e un solo INSERT gigante
        // sbatterebbe contro il limite di placeholder di SQLite.
        foreach (array_chunk($righe, 500) as $blocco) {
            DraftPoolEntry::insert($blocco);
        }
    }

    /**
     * La coda dei turni, con l'ordine snake già risolto.
     *
     * Il serpente inverte l'ordine a ogni giro: chi sceglie per ultimo nel
     * primo giro sceglie per primo nel secondo. Risolvere l'ordine adesso e
     * salvarlo in `pick_index` significa che al momento della pesca non c'è
     * più niente da calcolare — e niente da sbagliare.
     *
     * @param  list<int>  $ordine
     */
    private function buildSnake(Draft $draft, array $ordine): void
    {
        $pickIndex = 1;
        $righe = [];

        for ($round = 1; $round <= $draft->rounds; $round++) {
            $giro = $round % 2 === 1 ? $ordine : array_reverse($ordine);

            foreach ($giro as $managerId) {
                $righe[] = [
                    'draft_id' => $draft->id,
                    'manager_id' => $managerId,
                    'round' => $round,
                    'pick_index' => $pickIndex++,
                    'state' => 'waiting',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DraftTurn::insert($righe);
    }

    /**
     * Ordine del primo giro: l'inverso della classifica dopo la M−2.
     *
     * Alla prima giornata non c'è classifica e l'ordine è quello di iscrizione,
     * come previsto da §1. Non è arbitrario più di quanto lo sarebbe un
     * sorteggio, ed è ripetibile.
     *
     * @return list<int>
     */
    private function pickOrder(LeagueSeason $stagione, int $matchday): array
    {
        $daClassifica = Standing::draftOrder($stagione->id, $matchday - 2);

        return $daClassifica !== []
            ? $daClassifica
            : $stagione->partecipanti()->pluck('id')->all();
    }

    /**
     * Apertura e chiusura del draft.
     *
     * Si ricavano dai primi fischi delle due giornate: il draft apre quando
     * comincia la precedente e si prende una quota della finestra, lasciando il
     * resto agli scambi. Fissare le ore a mano non funzionerebbe, perché la
     * finestra passa da ~48h infrasettimanali a una settimana piena.
     *
     * @return array{Carbon, Carbon}
     */
    private function window(
        int $season,
        int $matchday,
        Settings $settings,
        ?DateTimeInterface $opensAt,
        ?DateTimeInterface $deadlineAt,
    ): array {
        $apre = $opensAt ? Carbon::instance(Carbon::parse($opensAt)) : Fixture::firstKickoff($season, $matchday - 1);
        $prossima = Fixture::firstKickoff($season, $matchday);

        if (! $apre) {
            throw new RuntimeException(
                "Non so quando aprire il draft della giornata {$matchday}: manca il calendario di Serie A della ".
                ($matchday - 1).'ª. Passa una data esplicita.',
            );
        }

        if ($deadlineAt) {
            return [$apre, Carbon::parse($deadlineAt)];
        }

        if (! $prossima) {
            throw new RuntimeException(
                "Non so quando chiudere il draft della giornata {$matchday}: manca il primo fischio della {$matchday}ª. ".
                'Passa una scadenza esplicita.',
            );
        }

        $finestra = $apre->diffInSeconds($prossima);

        return [$apre, $apre->copy()->addSeconds((int) ($finestra * $settings->quotaFinestraDraft()))];
    }
}
