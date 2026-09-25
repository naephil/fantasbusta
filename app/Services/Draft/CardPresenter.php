<?php

namespace App\Services\Draft;

use App\Models\Card;
use App\Models\LeagueSeason;
use App\Models\PlayerPower;
use App\Models\PlayerScore;
use App\Models\PlayerSeason;
use App\Services\Scoring\Settings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Prepara i dati che stanno sulla faccia di una carta.
 *
 * Le statistiche mostrate usano la stessa finestra del power score — fino alla
 * giornata `matchday − 2` — e non i dati più recenti disponibili. Sembra un
 * dettaglio e non lo è: la carta mostra il Power accanto a fantamedia e forma,
 * e se i tre numeri venissero da momenti diversi la carta si contraddirebbe da
 * sola sotto gli occhi di chi la pesca.
 */
class CardPresenter
{
    /**
     * @param  Collection<int,Card>  $cards
     * @return Collection<int,array<string,mixed>>
     */
    public function present(Collection $cards, LeagueSeason $stagione, int $matchday): Collection
    {
        $season = $stagione->season;
        $settings = Settings::for($stagione);

        // PackGenerator restituisce una collection base, non una Eloquent:
        // `loadMissing` vive solo sulla seconda, e senza questo passaggio ogni
        // carta si tirerebbe dietro una query per giocatore.
        $cards = EloquentCollection::make($cards->all())->loadMissing('player');

        $playerIds = $cards->pluck('player_id')->unique()->all();
        $upTo = $matchday - 2;

        $power = PlayerPower::whereIn('player_id', $playerIds)
            ->where('league_season_id', $stagione->id)
            ->where('matchday', $matchday)
            ->get()
            ->keyBy('player_id');

        // Squadra e quotazione appartengono al listone di quell'anno, non al
        // giocatore: in una stagione replay la carta deve riportare il club in
        // cui militava allora e il prezzo che aveva allora, non quelli di oggi.
        $listone = PlayerSeason::with('team')
            ->whereIn('player_id', $playerIds)
            ->where('season', $season)
            ->get()
            ->keyBy('player_id');

        $fantamedia = $this->media($playerIds, $stagione->id, 1, $upTo);
        $forma = $this->media($playerIds, $stagione->id, $upTo - $settings->giornateForma() + 1, $upTo);

        return $cards->map(fn (Card $card) => $this->one(
            $card,
            $power->get($card->player_id),
            $fantamedia,
            $forma,
            $listone->get($card->player_id),
        ));
    }

    /**
     * @param  array<int,float>  $fantamedia
     * @param  array<int,float>  $forma
     * @return array<string,mixed>
     */
    private function one(Card $card, ?PlayerPower $power, array $fantamedia, array $forma, ?PlayerSeason $listone): array
    {
        $player = $card->player;
        $media = $fantamedia[$card->player_id] ?? null;
        $recente = $forma[$card->player_id] ?? null;

        // Lo scarto fra forma e media stagionale: è il numero che dice se sta
        // salendo o calando, ed è anche il segno che colora la casella.
        $scarto = $media !== null && $recente !== null ? round($recente - $media, 2) : null;

        return [
            'id' => $card->id,
            'first' => $player->first_name ?? '',
            'last' => $player->last_name,
            'club' => $listone?->team?->name ?? '—',
            'clubColor' => $listone?->team?->color ?? '#8a929e',
            'role' => $card->role,
            'tier' => $card->tier,
            'power' => $power?->power !== null ? (int) round($power->power) : 0,
            'fm' => $media !== null ? number_format($media, 2, ',', '') : '—',
            'form' => $scarto !== null ? ($scarto >= 0 ? '+' : '−').number_format(abs($scarto), 1, ',', '') : '—',
            'quot' => $listone?->quotazione_iniziale !== null
                ? rtrim(rtrim(number_format((float) $listone->quotazione_iniziale, 1, ',', ''), '0'), ',')
                : '—',
            'formTrend' => $scarto === null ? 'flat' : ($scarto > 0 ? 'up' : ($scarto < 0 ? 'down' : 'flat')),
            ...$this->trend($power),

            // ⚠️ La foto compare SOLO se un umano ha verificato che quell'id
            // corrisponde davvero a quel giocatore. Un id sbagliato restituisce
            // una faccia plausibile, e una faccia sbagliata su una carta da
            // collezione è l'errore più visibile e più imbarazzante possibile.
            // Senza verifica si mostrano le iniziali: brutte ma oneste.
            'photo' => $player->photo_verified ? $player->photoUrl() : null,
            'initials' => mb_strtoupper(mb_substr($player->first_name ?? $player->last_name, 0, 1).mb_substr($player->last_name, 0, 1)),
        ];
    }

    /** @return array{trend: string, trendLabel: string} */
    private function trend(?PlayerPower $power): array
    {
        $delta = $power?->rank_delta;

        if ($power?->tier_changed && $delta !== null && $delta !== 0) {
            return $delta > 0
                ? ['trend' => 'up', 'trendLabel' => '▲ sale di tier']
                : ['trend' => 'down', 'trendLabel' => '▼ scende di tier'];
        }

        return match (true) {
            $delta === null || $delta === 0 => ['trend' => 'flat', 'trendLabel' => '= stabile'],
            $delta > 0 => ['trend' => 'up', 'trendLabel' => "▲ +{$delta} pos."],
            default => ['trend' => 'down', 'trendLabel' => '▼ −'.abs($delta).' pos.'],
        };
    }

    /**
     * @param  list<int>  $playerIds
     * @return array<int,float>
     */
    private function media(array $playerIds, int $leagueSeasonId, int $from, int $to): array
    {
        if ($to < 1 || $playerIds === []) {
            return [];
        }

        return PlayerScore::whereIn('player_id', $playerIds)
            ->where('league_season_id', $leagueSeasonId)
            ->whereBetween('matchday', [max(1, $from), $to])
            ->whereNotNull('fantavoto')
            ->selectRaw('player_id, avg(fantavoto) as media')
            ->groupBy('player_id')
            ->pluck('media', 'player_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
