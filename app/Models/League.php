<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Il gruppo di amici. Permanente.
 *
 * Non ha una stagione: ne gioca molte, una dopo l'altra, e ognuna è una
 * LeagueSeason. Qui restano il nome e le regole di casa — bonus, malus, pesi
 * del power — che ogni stagione può poi ritoccare per conto suo.
 */
class League extends Model
{
    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    /**
     * Il gettone non esce mai insieme al resto.
     *
     * Chiunque lo legga può iscriversi al gruppo, quindi vale quanto una
     * password: nascosto qui, non finisce per distrazione dentro un `toJson()`
     * o un dump di debug.
     */
    protected $hidden = ['registration_token'];

    // ───────────────────────── iscrizioni ─────────────────────────

    public function iscrizioniAperte(): bool
    {
        return $this->registration_token !== null;
    }

    /**
     * Apre le iscrizioni, o le riapre altrove.
     *
     * Rigenerare è anche il modo di revocare un link finito dove non doveva:
     * il vecchio indirizzo smette di funzionare all'istante, e chi si era già
     * iscritto resta dentro — è un invito che si strappa, non un'espulsione.
     *
     * 32 caratteri da `Str::random`, che è un generatore crittografico: un
     * indirizzo indovinabile a tentativi sarebbe la stessa porta aperta che il
     * gettone dovrebbe chiudere.
     */
    public function apriIscrizioni(): string
    {
        $this->update(['registration_token' => Str::random(32)]);

        return $this->registration_token;
    }

    public function chiudiIscrizioni(): void
    {
        $this->update(['registration_token' => null]);
    }

    public function linkIscrizione(): ?string
    {
        return $this->iscrizioniAperte()
            ? route('registra', $this->registration_token)
            : null;
    }

    public function managers(): HasMany
    {
        return $this->hasMany(Manager::class);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(LeagueSeason::class);
    }

    /** La stagione che si sta giocando, o l'ultima aperta. */
    public function stagioneCorrente(): ?LeagueSeason
    {
        return $this->seasons()
            ->orderByRaw("case state when 'in_corso' then 0 when 'preparazione' then 1 else 2 end")
            ->orderByDesc('season')
            ->first();
    }
}
