<?php

namespace App\Console\Commands\Concerns;

use App\Models\LeagueSeason;
use Illuminate\Database\Eloquent\Collection;

/**
 * Su quali stagioni di lega deve agire un comando.
 *
 * Da quando i gruppi sono più d'uno e ognuno può giocare più annate, «la lega»
 * non identifica più niente: due gruppi possono rigiocare lo stesso 2023/24, e
 * lo stesso gruppo può avere il 2023/24 concluso e il 2024/25 in corso. Il
 * bersaglio di un comando è quindi sempre una riga di `league_seasons`.
 *
 * Senza `--stagione` si agisce su tutte quelle in corso: è il caso normale del
 * cron, che deve muovere ogni partita viva senza sapere quali siano.
 */
trait RisolveStagione
{
    /** @return Collection<int,LeagueSeason> */
    protected function stagioni(): Collection
    {
        return LeagueSeason::with('league')
            ->when(
                $this->option('stagione'),
                fn ($q, $id) => $q->whereKey($id),
                fn ($q) => $q->where('state', 'in_corso'),
            )
            ->orderBy('id')
            ->get();
    }

    /** Come si chiama in output: «Gli Sbustatori · 2023/24». */
    protected function etichetta(LeagueSeason $stagione): string
    {
        return $stagione->league->name.' · '.$stagione->etichetta();
    }

    /** @return Collection<int,LeagueSeason>|null null se non c'è niente su cui lavorare */
    protected function stagioniOFallisci(): ?Collection
    {
        $stagioni = $this->stagioni();

        if ($stagioni->isEmpty()) {
            $this->error(
                $this->option('stagione')
                    ? 'Stagione di lega inesistente.'
                    : 'Nessuna stagione in corso. Creane una, o indicane una con --stagione=<id>.',
            );

            return null;
        }

        return $stagioni;
    }
}
