<?php

namespace App\Services\Ingest;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use Illuminate\Support\Arr;

/**
 * Statistiche di giornata, partita per partita.
 *
 * Costa **una chiamata per partita**, quindi dieci per giornata. `/fixtures?id=`
 * restituisce in un colpo solo sia le prestazioni sia gli eventi, mentre
 * `/fixtures/players` e `/fixtures/events` ne vorrebbero una a testa: a parità
 * di dati è la metà della spesa, e su un piano da cento chiamate al giorno la
 * metà è la differenza fra dieci giornate e cinque.
 *
 * ⚠️ Accorpare oltre non si può: `?ids=` accetta più partite insieme ma è
 * riservato ai piani a pagamento, e interrogare il turno
 * (`?league=&season=&round=`) restituisce solo lo scheletro delle partite,
 * senza eventi né voti. Verificato sull'API vera, non dedotto.
 */
class StatSync
{
    /** La posizione come la scrive il tabellino: una lettera, non la parola. */
    private const POSIZIONE_A_RUOLO = ['G' => 'P', 'D' => 'D', 'M' => 'C', 'F' => 'A'];

    /** Quanti giocatori sono stati aggiunti strada facendo, per dirlo. */
    private int $aggiunti = 0;

    public function __construct(private ApiFootball $api) {}

    /**
     * @param  bool  $includiNonFinite  forza anche le partite ancora in corso
     * @return array{partite: int, saltate: int, giocatori: int, aggiunti: int, ripulite: int}
     */
    public function matchday(int $season, int $matchday, bool $includiNonFinite = false): array
    {
        $fixtures = Fixture::where('season', $season)->where('matchday', $matchday)->get();

        $partite = 0;
        $saltate = 0;
        $giocatori = 0;
        $this->aggiunti = 0;

        // Prima di scrivere i voti veri si fa piazza pulita di quelli finti:
        // altrimenti restano in giro righe di giocatori mai convocati.
        $ripulite = $this->spazzaSimulate($season, $matchday);

        foreach ($fixtures as $fixture) {
            // Una partita in corso dà statistiche parziali, e parziale qui è
            // peggio di assente: un giocatore ancora senza rating verrebbe
            // scritto come senza voto e farebbe scattare una sostituzione che
            // non serviva.
            if ($fixture->status !== 'finished' && ! $includiNonFinite) {
                $saltate++;

                continue;
            }

            $giocatori += $this->fixture($fixture, $season, $matchday);
            $partite++;
        }

        return compact('partite', 'saltate', 'giocatori')
            + ['aggiunti' => $this->aggiunti, 'ripulite' => $ripulite];
    }

    /**
     * Butta via le righe simulate della giornata.
     *
     * ⚠️ Non basta sovrascriverle. Il simulatore compone la distinta per
     * quotazione, l'allenatore vero fa un'altra scelta: chi era convocato per
     * finta e non per davvero non compare nel tabellino, quindi la sua riga
     * sopravviverebbe all'aggiornamento — voti inventati addosso a gente che
     * quel giorno non era nemmeno in panchina — e il calcolo la prenderebbe
     * per buona.
     *
     * @return int righe finte rimosse
     */
    private function spazzaSimulate(int $season, int $matchday): int
    {
        return PlayerStat::where('season', $season)
            ->where('matchday', $matchday)
            ->where('source', 'simulata')
            ->delete();
    }

    /** @return int righe di statistica scritte */
    private function fixture(Fixture $fixture, int $season, int $matchday): int
    {
        $dettaglio = Arr::first($this->api->get('/fixtures', ['id' => $fixture->id])) ?? [];

        $autoreti = $this->ownGoals(Arr::get($dettaglio, 'events', []) ?? []);
        $scritte = 0;

        foreach (Arr::get($dettaglio, 'players', []) ?? [] as $squadra) {
            foreach (Arr::get($squadra, 'players', []) as $riga) {
                $playerId = (int) Arr::get($riga, 'player.id');
                $stat = Arr::first(Arr::get($riga, 'statistics', [])) ?? [];

                if ($playerId === 0) {
                    continue;
                }

                // ⚠️ Chi è sceso in campo ESISTE, punto: se non è in anagrafica
                // è colpa nostra, non sua. Il piano gratuito tronca le rose
                // alla terza pagina — una sessantina di giocatori per squadra —
                // e le squadre lunghe ci sbattono contro.
                //
                // Prima qui si faceva `continue`, e il risultato era una
                // perdita silenziosa: sulla 1ª giornata 2023/24 sparivano tre
                // gol su ventisei, cioè tre bonus mai assegnati a chi aveva
                // quelle carte in rosa. Un punteggio sbagliato che nessuna
                // schermata avrebbe segnalato.
                //
                // Aggiungerlo qui non costa nessuna chiamata: nome, squadra e
                // posizione sono già dentro il tabellino che stiamo leggendo.
                if (! Player::whereKey($playerId)->exists()) {
                    $this->anagrafaDalTabellino($riga, $stat, $squadra, $season);
                }

                PlayerStat::updateOrCreate(
                    ['player_id' => $playerId, 'season' => $season, 'matchday' => $matchday],
                    [
                        'minutes' => (int) Arr::get($stat, 'games.minutes', 0),
                        'rating' => $this->rating(Arr::get($stat, 'games.rating')),
                        'goals' => (int) Arr::get($stat, 'goals.total', 0),
                        'assists' => (int) Arr::get($stat, 'goals.assists', 0),
                        'goals_conceded' => (int) Arr::get($stat, 'goals.conceded', 0),
                        'yellow' => (int) Arr::get($stat, 'cards.yellow', 0),
                        'red' => (int) Arr::get($stat, 'cards.red', 0),
                        'pen_scored' => (int) Arr::get($stat, 'penalty.scored', 0),
                        'pen_missed' => (int) Arr::get($stat, 'penalty.missed', 0),
                        'pen_saved' => (int) Arr::get($stat, 'penalty.saved', 0),
                        'own_goals' => $autoreti[$playerId] ?? 0,
                        'source' => 'reale',
                    ],
                );

                $scritte++;
            }
        }

        return $scritte;
    }

    /**
     * Crea al volo chi ha giocato ma non risultava in anagrafica.
     *
     * Il ruolo arriva dalla posizione del tabellino e resta NON confermato,
     * come per chiunque altro entri senza passare dal listone: è un'ipotesi
     * dell'API, non una decisione umana.
     *
     * @param  array<string,mixed>  $riga
     * @param  array<string,mixed>  $stat
     * @param  array<string,mixed>  $squadra
     */
    private function anagrafaDalTabellino(array $riga, array $stat, array $squadra, int $season): void
    {
        $id = (int) Arr::get($riga, 'player.id');
        $nome = trim((string) Arr::get($riga, 'player.name'));

        Player::updateOrCreate(['id' => $id], [
            'last_name' => $nome !== '' ? $nome : 'Ignoto',
            'photo_url' => Arr::get($riga, 'player.photo'),
        ]);

        $teamId = (int) Arr::get($squadra, 'team.id');

        if ($teamId === 0) {
            return;
        }

        PlayerSeason::updateOrCreate(
            ['player_id' => $id, 'season' => $season],
            [
                'team_id' => $teamId,
                'role' => self::POSIZIONE_A_RUOLO[Arr::get($stat, 'games.position')] ?? 'C',
                'active' => true,
                'last_seen_at' => now(),
            ],
        );

        $this->aggiunti++;
    }

    /**
     * Le autoreti stanno solo negli eventi.
     *
     * Le statistiche per giocatore non le riportano — c'è `goals.total`, che
     * conta le reti fatte, non quelle regalate — quindi senza guardare gli
     * eventi un −2 sparirebbe dal fantavoto senza lasciare traccia. Verificato
     * su un caso vero: Grassi in Roma-Empoli, `goals.total` a null e l'autorete
     * solo fra gli eventi.
     *
     * ⚠️ `evento.team` è la squadra che BENEFICIA del gol, non quella di chi lo
     * segna: attribuire il malus da lì lo darebbe alla squadra sbagliata. Si usa
     * `player.id` e basta.
     *
     * @param  list<array<string,mixed>>  $eventi
     * @return array<int,int> player_id => autoreti
     */
    private function ownGoals(array $eventi): array
    {
        $conteggio = [];

        foreach ($eventi as $evento) {
            // Il confronto resta tollerante: costa nulla e regge sia «Own Goal»
            // — la stringa confermata sul campo — sia eventuali varianti.
            if (Arr::get($evento, 'type') !== 'Goal'
                || ! str_contains(strtolower((string) Arr::get($evento, 'detail')), 'own goal')) {
                continue;
            }

            $playerId = (int) Arr::get($evento, 'player.id');
            $conteggio[$playerId] = ($conteggio[$playerId] ?? 0) + 1;
        }

        return $conteggio;
    }

    /** Il rating arriva come stringa, e assente significa senza voto. */
    private function rating(mixed $valore): ?float
    {
        return is_numeric($valore) ? round((float) $valore, 1) : null;
    }
}
