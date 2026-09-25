<?php

namespace Tests\Concerns;

use App\Models\Card;
use App\Models\Fixture;
use App\Models\League;
use App\Models\LeagueSeason;
use App\Models\Lineup;
use App\Models\LineupResult;
use App\Models\LineupSlot;
use App\Models\Manager;
use App\Models\Player;
use App\Models\PlayerPower;
use App\Models\PlayerScore;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Models\Team;
use App\Services\Lineup\ModuleValidator;
use Illuminate\Support\Collection;

/**
 * Impalcatura minima per i test che toccano il database.
 *
 * ⚠️ Dove una stagione non viene passata si prende la PIU' VECCHIA del gruppo,
 * con un `orderBy` esplicito. Senza, `first()` restituisce una riga qualsiasi:
 * su SQLite capitava sempre quella giusta per come sono fatti i rowid, su
 * MariaDB no — e un test che allestisce due stagioni finiva per scrivere le
 * carte tutte nella seconda, fallendo solo sul database di produzione.
 *
 * Le rose si scrivono come stringhe di ruoli — 'PDDDDCCCCAA' è un 4-4-2 secco —
 * così il caso di prova si legge in una riga e la differenza fra due scenari
 * salta all'occhio.
 *
 * `makeLeague()` restituisce una **stagione di lega**, non il gruppo: è quello
 * che i servizi vogliono, e passare il gruppo da solo non basterebbe mai —
 * senza anno non si sa che listone leggere né a che carte appartengono le
 * sfide. Il gruppo resta raggiungibile con `->league`.
 */
trait BuildsLeague
{
    /** L'annata su cui girano i test, se non se ne chiede un'altra. */
    protected const ANNATA = 2026;

    private int $nextPlayerId = 1000;

    /** @param  array<string,mixed>|null  $settings  taratura di lega, sovrascrive i default */
    protected function makeLeague(
        string $name = 'Lega di prova',
        ?array $settings = null,
        int $season = self::ANNATA,
    ): LeagueSeason {
        $league = League::create(['name' => $name]);

        // `state` esplicito e non lasciato al default della tabella: quello vive
        // nel database, non sull'istanza appena creata, e un test che legge
        // `$stagione->state` subito dopo troverebbe null.
        return LeagueSeason::create([
            'league_id' => $league->id,
            'season' => $season,
            'state' => 'in_corso',
            'start_matchday' => 1,
            // La taratura va sulla stagione: è lì che i servizi la cercano, ed
            // è il livello più stretto — un test che ritocca una regola non
            // deve sporcare le altre stagioni dello stesso gruppo.
            'settings' => $settings,
        ])->load('league');
    }

    /** Una seconda stagione per lo stesso gruppo: serve a provare che non si mescolano. */
    protected function makeStagione(LeagueSeason $stagione, int $season): LeagueSeason
    {
        return LeagueSeason::create([
            'league_id' => $stagione->league_id,
            'season' => $season,
            'state' => 'in_corso',
            'start_matchday' => 1,
        ])->load('league');
    }

    protected function makeManager(LeagueSeason $stagione, string $name): Manager
    {
        return Manager::create([
            'league_id' => $stagione->league_id,
            'name' => $name,
            'email' => strtolower($name).'@fantasbusta.test',
            'password' => 'segreta',
        ]);
    }

    /**
     * Assegna una rosa a partire dai ruoli, nell'ordine in cui sono scritti.
     *
     * @return Collection<int,Card>
     */
    protected function makeRoster(
        Manager $manager,
        string $roles,
        int $matchday = 1,
        ?LeagueSeason $stagione = null,
    ): Collection {
        $stagione ??= LeagueSeason::where('league_id', $manager->league_id)->orderBy('id')->firstOrFail();

        return collect(str_split($roles))
            ->map(fn (string $role) => Card::create([
                'league_season_id' => $stagione->id,
                'matchday' => $matchday,
                'player_id' => $this->makePlayer($role, season: $stagione->season)->id,
                'tier' => 'comune',
                'role' => $role,
                'owner_manager_id' => $manager->id,
                'original_owner_id' => $manager->id,
            ]));
    }

    /** Prima carta della rosa con quel ruolo. */
    protected function firstOfRole(Collection $roster, string $role): Card
    {
        return $roster->firstWhere('role', $role);
    }

    /**
     * Id delle carte da cedere, contati per ruolo: ['D' => 3, 'P' => 1].
     *
     * Prendere «le prime sette qualunque» non va bene per i casi di confine:
     * la rosa è ordinata per ruolo, quindi si finisce per svuotare un reparto
     * solo e a sbagliare è il test, non il codice.
     *
     * @param  Collection<int,Card>  $roster
     * @param  array<string,int>  $spec
     * @return list<int>
     */
    protected function pickByRole(Collection $roster, array $spec): array
    {
        return collect($spec)
            ->flatMap(fn (int $n, string $role) => $roster->where('role', $role)->take($n))
            ->pluck('id')
            ->all();
    }

    /**
     * Formazione con i titolari presi in ordine di rosa e il resto in panchina.
     *
     * L'ordine della panchina ricalca quello della rosa, così il caso di prova
     * si governa scrivendo la stringa dei ruoli nell'ordine giusto.
     */
    protected function makeLineup(
        Manager $manager,
        Collection $roster,
        string $module = '4-4-2',
        int $matchday = 1,
        ?LeagueSeason $stagione = null,
    ): Lineup {
        $stagione ??= LeagueSeason::where('league_id', $manager->league_id)->orderBy('id')->firstOrFail();

        $titolari = collect(['P' => 1] + ModuleValidator::MODULES[$module])
            ->flatMap(fn (int $n, string $role) => $roster->where('role', $role)->take($n));

        $lineup = Lineup::create([
            'league_season_id' => $stagione->id,
            'manager_id' => $manager->id,
            'matchday' => $matchday,
            'module' => $module,
            'state' => 'locked',
            'locked_at' => now(),
        ]);

        foreach ($titolari as $card) {
            LineupSlot::create([
                'lineup_id' => $lineup->id,
                'card_id' => $card->id,
                'is_starter' => true,
            ]);
        }

        $ids = $titolari->pluck('id')->all();

        $roster->reject(fn (Card $c) => in_array($c->id, $ids, true))
            ->values()
            ->each(fn (Card $c, int $i) => LineupSlot::create([
                'lineup_id' => $lineup->id,
                'card_id' => $c->id,
                'is_starter' => false,
                'bench_order' => $i + 1,
            ]));

        return $lineup->load('slots.card');
    }

    /** Fantavoto già calcolato. `null` significa senza voto. */
    protected function makeScore(
        Card $card,
        ?float $fantavoto,
        int $matchday = 1,
    ): PlayerScore {
        return PlayerScore::create([
            'league_season_id' => $card->league_season_id,
            'player_id' => $card->player_id,
            'matchday' => $matchday,
            'voto_base' => $fantavoto,
            'fantavoto' => $fantavoto,
        ]);
    }

    /** @param  Collection<int,Card>  $cards */
    protected function scoreAll(Collection $cards, ?float $fantavoto, int $matchday = 1): void
    {
        $cards->each(fn (Card $c) => $this->makeScore($c, $fantavoto, $matchday));
    }

    /** Ritocca un voto già assegnato: comodo per scolpire il caso su una rosa uniforme. */
    protected function rescore(Card $card, ?float $fantavoto, int $matchday = 1): void
    {
        PlayerScore::where('player_id', $card->player_id)
            ->where('league_season_id', $card->league_season_id)
            ->where('matchday', $matchday)
            ->update(['voto_base' => $fantavoto, 'fantavoto' => $fantavoto]);
    }

    protected function makePower(Card $card, float $power, int $matchday = 1): PlayerPower
    {
        return PlayerPower::create([
            'league_season_id' => $card->league_season_id,
            'player_id' => $card->player_id,
            'matchday' => $matchday,
            'power' => $power,
            'tier' => $card->tier,
        ]);
    }

    /**
     * Totale di formazione già calcolato, senza passare dal motore di scoring.
     *
     * Serve ai test di classifica, che devono provare il confronto fra due
     * totali e non come quei totali sono nati.
     */
    protected function makeLineupResult(
        Manager $manager,
        int $matchday,
        float $totale,
        ?LeagueSeason $stagione = null,
    ): LineupResult {
        $stagione ??= LeagueSeason::where('league_id', $manager->league_id)->orderBy('id')->firstOrFail();

        $lineup = Lineup::create([
            'league_season_id' => $stagione->id,
            'manager_id' => $manager->id,
            'matchday' => $matchday,
            'module' => '4-4-2',
            'state' => 'locked',
            'locked_at' => now(),
        ]);

        return LineupResult::create([
            'lineup_id' => $lineup->id,
            'totale' => $totale,
            'computed_at' => now(),
        ]);
    }

    /** Power e tier di un giocatore, come li congela il pool alla creazione del draft. */
    protected function makePlayerPower(
        Player $player,
        int $matchday,
        float $power = 50.0,
        string $tier = 'comune',
        ?LeagueSeason $stagione = null,
    ): PlayerPower {
        return PlayerPower::create([
            'league_season_id' => ($stagione ?? LeagueSeason::query()->orderBy('id')->firstOrFail())->id,
            'player_id' => $player->id,
            'matchday' => $matchday,
            'power' => $power,
            'tier' => $tier,
        ]);
    }

    /** Una partita di Serie A: serve solo a datare il primo fischio della giornata. */
    protected function makeFixture(int $matchday, string $kickoffAt, int $season = self::ANNATA): Fixture
    {
        $casa = Team::firstOrCreate(['id' => 500], ['name' => 'Squadra di prova']);
        $fuori = Team::firstOrCreate(['id' => 501], ['name' => 'Altra squadra']);

        return Fixture::create([
            'id' => $season * 1000 + $matchday,
            'season' => $season,
            'matchday' => $matchday,
            'home_team_id' => $casa->id,
            'away_team_id' => $fuori->id,
            'kickoff_at' => $kickoffAt,
        ]);
    }

    /**
     * Un giocatore e la sua riga di listone per l'annata.
     *
     * Le due cose sono separate nello schema — l'identità è permanente, ruolo e
     * quotazione appartengono all'anno — ma nei test servono quasi sempre
     * insieme: un giocatore senza riga di listone non è pescabile e non prende
     * voti, quindi crearne uno solo produrrebbe scenari muti.
     */
    /**
     * @param  bool  $nelListone  se la riga è agganciata al listone
     *
     * ⚠️ `nelListone` è vero di default perché è lo stato normale: il draft
     * pesca SOLO dal listone, quindi un giocatore creato senza sarebbe
     * invisibile al gioco e quasi ogni scenario costruirebbe un pool vuoto.
     * Si passa `false` solo quando la prova riguarda proprio chi il listone non
     * l'ha ancora agganciato — la schermata di verifica, l'import, la stima.
     */
    protected function makePlayer(
        string $role,
        float $quotazione = 10.0,
        int $season = self::ANNATA,
        ?int $teamId = null,
        bool $nelListone = true,
    ): Player {
        $team = $teamId
            ? Team::firstOrCreate(['id' => $teamId], ['name' => "Squadra {$teamId}"])
            : Team::firstOrCreate(['id' => 500], ['name' => 'Squadra di prova']);

        $player = Player::create([
            'id' => $this->nextPlayerId++,
            'last_name' => "Giocatore {$role}",
        ]);

        PlayerSeason::create([
            'player_id' => $player->id,
            'season' => $season,
            'team_id' => $team->id,
            'role' => $role,
            'quotazione_iniziale' => $quotazione,
            'role_confirmed' => $nelListone,
        ]);

        return $player;
    }

    /**
     * Una prestazione completa: statistica grezza e fantavoto già calcolato.
     *
     * Il power legge entrambe le tabelle — i minuti da `player_stats` per la
     * titolarità, il voto da `player_scores` per fantamedia e forma — quindi
     * scriverne una sola darebbe un calcolo a metà.
     */
    protected function makePerformance(
        Player $player,
        int $matchday,
        ?float $fantavoto,
        int $minutes = 90,
        int $season = self::ANNATA,
        ?LeagueSeason $stagione = null,
    ): void {
        // La statistica è un fatto e resta dell'annata; il voto è
        // un'interpretazione e appartiene alla stagione di lega. Per questo la
        // prima si scrive in updateOrCreate: due leghe che allestiscono lo
        // stesso scenario stanno dichiarando lo stesso fatto, non due.
        PlayerStat::updateOrCreate(
            ['player_id' => $player->id, 'season' => $season, 'matchday' => $matchday],
            ['minutes' => $minutes, 'rating' => $fantavoto],
        );

        PlayerScore::create([
            'league_season_id' => ($stagione ?? LeagueSeason::query()->orderBy('id')->firstOrFail())->id,
            'player_id' => $player->id,
            'matchday' => $matchday,
            'voto_base' => $fantavoto,
            'fantavoto' => $fantavoto,
        ]);
    }
}
