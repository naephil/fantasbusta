<?php

namespace App\Models;

use App\Services\Trade\RosterFloor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

class Manager extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'auto_draft' => 'boolean',
        'is_admin' => 'boolean',
        'is_bot' => 'boolean',
        'active' => 'boolean',
        'password' => 'hashed',
        'jersey' => 'array',
        'crest' => 'array',
        'sponsor' => 'array',
    ];

    /**
     * Le iniziali della squadra: la scorciatoia dello stemma senza simbolo.
     *
     * «Athletic Bilbao Nord» diventa «ABN», «Sporting» diventa «SPO»: due o
     * tre lettere leggibili a quaranta pixel, che è la misura a cui uno
     * stemma vive davvero.
     */
    public function initials(): string
    {
        $parole = preg_split('/\s+/', trim($this->name)) ?: [];

        $iniziali = count($parole) > 1
            ? implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parole, 0, 3)))
            : mb_substr($this->name, 0, 3);

        return mb_strtoupper($iniziali);
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class, 'owner_manager_id');
    }

    public function lineups(): HasMany
    {
        return $this->hasMany(Lineup::class);
    }

    /**
     * La giornata per cui si gioca adesso, dentro una stagione.
     *
     * ⚠️ La PIÙ VECCHIA fra quelle non ancora giocate, non la più recente in
     * rosa. I due draft aperti insieme sono la norma e non un caso limite — il
     * ciclo è sfasato di due apposta, quindi appena avviata la stagione uno ha
     * già le carte della 1ª e della 2ª. Prendendo il massimo, la pagina della
     * formazione saltava dritta alla 2ª mentre la 1ª era ancora da schierare, e
     * chi giocava si ritrovava a preparare una giornata che non era la sua
     * senza nessun modo di tornare indietro.
     *
     * «Giocata» si legge dalle classifiche di QUESTA lega e non dalle
     * statistiche dell'annata: quelle sono condivise fra i gruppi, quindi un
     * altro gruppo che rigioca lo stesso anno farebbe saltare le giornate a
     * questo. Stesso criterio di SeasonRunner::giocate().
     *
     * A stagione finita ricade sull'ultima: la pagina resta leggibile invece di
     * dire che non c'è niente.
     */
    public function currentMatchday(LeagueSeason $stagione): ?int
    {
        $mie = $this->cards()->where('league_season_id', $stagione->id);

        $giocate = Standing::where('league_season_id', $stagione->id)
            ->distinct()
            ->pluck('matchday')
            ->all();

        $daGiocare = fn () => (clone $mie)
            ->when($giocate !== [], fn ($q) => $q->whereNotIn('matchday', $giocate));

        /*
         * ⚠️ Si salta anche la giornata GIÀ COMINCIATA, non solo quelle
         * giocate.
         *
         * Fra il fischio d'inizio e la chiusura c'è una finestra lunga giorni
         * in cui la giornata non è «giocata» — la classifica non si è ancora
         * mossa — ma la formazione è congelata da un pezzo. Prendendo la prima
         * non giocata, la pagina restava ferma lì per tutto quel tempo: si
         * apriva «Giornata chiusa», non c'era niente da fare, e intanto la
         * giornata successiva — quella su cui si poteva ancora decidere
         * qualcosa — non la mostrava nessuno.
         *
         * Vale identico per il mercato, che legge da qui: durante una giornata
         * in corso si tratta per la prossima, non per quella che sta finendo.
         */
        $aperta = $daGiocare()
            ->when($stagione->started_matchday !== null,
                fn ($q) => $q->where('matchday', '>', $stagione->started_matchday))
            ->min('matchday');

        // I due ripieghi, in ordine: la giornata in corso in sola lettura, e a
        // stagione finita l'ultima che si è avuta. Meglio una pagina che non si
        // può toccare di una che dice che non c'è niente.
        return $aperta ?? $daGiocare()->min('matchday') ?? $mie->max('matchday');
    }

    /** @return array<string,int> effettivi per ruolo nella giornata indicata */
    public function roleCounts(int $matchday): array
    {
        return $this->cards()
            ->where('matchday', $matchday)
            ->select('role', DB::raw('count(*) as n'))
            ->groupBy('role')
            ->pluck('n', 'role')
            ->all();
    }

    /**
     * Il test che blocca uno scambio. Va rivalutato all'accettazione,
     * non alla proposta: nel frattempo la controparte può aver concluso
     * altri scambi e ritrovarsi sotto la soglia.
     *
     * Comoda per le viste, ma non è la strada dell'accettazione: quella passa
     * da TradeService, che rilegge gli effettivi sotto lock invece di fidarsi
     * di una query fatta fuori dalla transazione.
     */
    public function canFieldLineup(int $matchday): bool
    {
        return RosterFloor::violation($this->roleCounts($matchday)) === null;
    }
}
