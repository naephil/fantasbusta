<?php

namespace App\Services\Ingest;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Team;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Anagrafiche: squadre, giocatori, calendario di Serie A.
 *
 * Rieseguibile quante volte si vuole. Le chiavi primarie sono gli id
 * API-Football, quindi ogni sincronizzazione è un aggiornamento in loco e non
 * una ricostruzione: gli id restano stabili e tutto ciò che li referenzia —
 * carte, voti, statistiche — resta valido.
 *
 * ⚠️ Ogni metodo vuole la stagione, e non è una formalità: squadra, ruolo e
 * quotazione di un giocatore appartengono all'anno, non a lui. Scaricare il
 * 2024 non deve toccare di una virgola il 2022 di un gruppo che ci sta ancora
 * giocando.
 *
 * Il corollario meno ovvio è che l'endpoint delle rose va scelto di
 * conseguenza: `/players/squads` è più economico ma non sa cosa sia una
 * stagione, e per un'annata passata darebbe la rosa di oggi. Vedi players().
 */
class ReferenceSync
{
    /** Traduzione della posizione API, che NON è il ruolo fantacalcio. */
    private const POSITION_TO_ROLE = [
        'Goalkeeper' => 'P',
        'Defender' => 'D',
        'Midfielder' => 'C',
        'Attacker' => 'A',
    ];

    public function __construct(private ApiFootball $api) {}

    /**
     * Le squadre di una stagione.
     *
     * Restano globali e senza anno: una squadra è la stessa da un campionato
     * all'altro, e le promosse si aggiungono senza cancellare nessuno.
     *
     * @return int squadre sincronizzate
     */
    public function teams(int $season): int
    {
        $risposta = $this->api->get('/teams', [
            'league' => config('apifootball.league'),
            'season' => $season,
        ]);

        foreach ($risposta as $riga) {
            $team = Arr::get($riga, 'team', []);

            Team::updateOrCreate(
                ['id' => (int) $team['id']],
                [
                    'name' => $team['name'] ?? 'Sconosciuta',
                    'code' => $team['code'] ?? null,
                    'logo_url' => $team['logo'] ?? null,
                ],
            );
        }

        return count($risposta);
    }

    /**
     * Le rose di una stagione, squadra per squadra.
     *
     * ⚠️ Si passa da `/players/squads` e non da `/players`: il piano gratuito
     * limita quest'ultimo a tre pagine da venti, cioè sessanta giocatori su
     * circa mille. Non fallisce — restituisce l'errore a metà scaricamento — e
     * senza accorgersene si finirebbe con un listone monco che sembra completo.
     *
     * ⚠️ Il ruolo e la quotazione NON si toccano se sono già confermati dal
     * listone di QUELL'ANNO. È l'invariante che tiene in piedi il resto: una
     * sincronizzazione di routine non deve poter riclassificare mezzo
     * campionato perché l'API chiama Midfielder un terzino.
     *
     * @return array{sincronizzati: int, nuovi: int, disattivati: int}
     */
    public function players(int $season): array
    {
        $adesso = now();
        $nuovi = 0;
        $visti = 0;

        foreach (Team::pluck('id') as $teamId) {
            // ⚠️ NON `/players/squads`: quell'endpoint non accetta la stagione e
            // restituisce sempre la rosa di OGGI. Usarlo per un'annata passata
            // scriverebbe i giocatori attuali dentro un listone vecchio — e il
            // replay di quella stagione si giocherebbe con gente che allora non
            // c'era, senza che niente lo segnali.
            //
            // Il tetto a tre pagine è quello del piano gratuito: la quarta non
            // torna vuota, torna un errore che ferma tutto. Sono ~60 giocatori
            // per squadra, cioè tutta la rosa registrata meno le ultime leve.
            $righe = $this->api->getPaged(
                '/players',
                ['team' => $teamId, 'season' => $season],
                maxPages: 3,
            );

            foreach ($righe as $riga) {
                $dati = Arr::get($riga, 'player', []);
                $id = (int) ($dati['id'] ?? 0);

                if ($id === 0) {
                    continue;
                }

                // L'identità: si aggiorna sempre, non appartiene all'anno.
                Player::updateOrCreate(['id' => $id], [
                    'first_name' => $dati['firstname'] ?: null,
                    'last_name' => $dati['lastname'] ?: 'Ignoto',
                    'photo_url' => $dati['photo'] ?? null,
                ]);

                $esistente = PlayerSeason::where('player_id', $id)->where('season', $season)->first();
                $nuovi += $esistente ? 0 : 1;
                $visti++;

                $attributi = [
                    'active' => true,
                    'last_seen_at' => $adesso,
                ];

                // ⚠️ Ruolo E SQUADRA restano al listone, quando c'è.
                //
                // La squadra ci è finita dopo, per un caso che si vede solo in
                // partita: l'API dà la rosa in cui il giocatore è REGISTRATO,
                // che a stagione iniziata può non essere quella in cui gioca.
                // Nel 2023/24 Lukaku risultava all'Inter, che ne deteneva il
                // cartellino, mentre giocava alla Roma — ed è alla Roma che il
                // listone lo quota. Lasciando decidere l'API, ogni
                // sincronizzazione di routine se lo riportava indietro, e nella
                // giornata di Serie A compariva sotto la partita sbagliata.
                if (! $esistente || ! $esistente->role_confirmed) {
                    $attributi['role'] = $this->guessRole(Arr::get($riga, 'statistics.0.games.position'));
                    $attributi['team_id'] = $teamId;
                }

                PlayerSeason::updateOrCreate(
                    ['player_id' => $id, 'season' => $season],
                    $attributi,
                );
            }
        }

        // Chi non compare più in nessuna rosa di quest'anno è uscito dalla
        // Serie A. Non si cancella: le sue carte e i suoi voti devono restare
        // leggibili, e le altre stagioni non lo riguardano.
        $disattivati = PlayerSeason::where('season', $season)
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $adesso))
            ->update(['active' => false]);

        return [
            'sincronizzati' => $visti,
            'nuovi' => $nuovi,
            'disattivati' => $disattivati,
        ];
    }

    /** @return int partite sincronizzate */
    public function fixtures(int $season): int
    {
        $risposta = $this->api->get('/fixtures', [
            'league' => config('apifootball.league'),
            'season' => $season,
        ]);

        $fatte = 0;

        foreach ($risposta as $riga) {
            $matchday = $this->matchdayFromRound(Arr::get($riga, 'league.round'));

            if ($matchday === null) {
                continue;   // coppe e spareggi: non fanno parte del nostro calendario
            }

            Fixture::updateOrCreate(
                ['id' => (int) Arr::get($riga, 'fixture.id')],
                [
                    'season' => $season,
                    'matchday' => $matchday,
                    'home_team_id' => (int) Arr::get($riga, 'teams.home.id'),
                    'away_team_id' => (int) Arr::get($riga, 'teams.away.id'),
                    'kickoff_at' => Carbon::parse(Arr::get($riga, 'fixture.date')),
                    'status' => $this->status(Arr::get($riga, 'fixture.status.short')),
                    'home_goals' => Arr::get($riga, 'goals.home'),
                    'away_goals' => Arr::get($riga, 'goals.away'),
                ],
            );

            $fatte++;
        }

        return $fatte;
    }

    /**
     * «Regular Season - 12» diventa 12.
     *
     * Tutto ciò che non ha questa forma è fuori dal campionato regolare e va
     * ignorato, altrimenti una semifinale di coppa finirebbe a fare da
     * giornata fantacalcio.
     */
    private function matchdayFromRound(?string $round): ?int
    {
        if ($round === null || ! preg_match('/(\d+)\s*$/', $round, $m)) {
            return null;
        }

        return str_contains(strtolower($round), 'regular season') ? (int) $m[1] : null;
    }

    private function status(?string $short): string
    {
        return match ($short) {
            'FT', 'AET', 'PEN' => 'finished',
            '1H', '2H', 'HT', 'ET', 'BT', 'P', 'LIVE' => 'live',
            default => 'scheduled',
        };
    }

    /**
     * Ipotesi di ruolo da usare solo in mancanza di listone.
     *
     * Vale quanto vale: l'API distingue quattro reparti, il fantacalcio no.
     * Chi entra da qui resta con `role_confirmed` falso finché un umano non
     * decide.
     */
    private function guessRole(?string $position): string
    {
        return self::POSITION_TO_ROLE[$position] ?? 'C';
    }
}
