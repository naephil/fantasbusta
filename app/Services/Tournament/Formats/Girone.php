<?php

namespace App\Services\Tournament\Formats;

use App\Models\Tournament;
use App\Services\Calendar\ScheduleGenerator;
use Illuminate\Support\Collection;

/**
 * Girone all'italiana: tutti contro tutti, una o più volte.
 *
 * È lo stesso motore del campionato — `ScheduleGenerator` col metodo del
 * cerchio — applicato a un sottoinsieme di squadre e a una finestra di
 * giornate. Nessuna eliminazione: si sommano i punti e vince chi ne ha di più.
 */
class Girone extends FormatoBase
{
    public function nome(): string
    {
        return 'Girone all\'italiana';
    }

    public function descrizione(): string
    {
        return 'Tutti contro tutti. Vince chi fa più punti; a parità, i fantapunti totali.';
    }

    public function durata(int $partecipanti, array $settings): int
    {
        $turni = $partecipanti % 2 === 0 ? $partecipanti - 1 : $partecipanti;

        return $turni * max(1, (int) ($settings['gironi'] ?? 1));
    }

    /** Il calendario si conosce tutto adesso: nessun turno dipende dai risultati. */
    public function avvia(Tournament $torneo): void
    {
        $ids = $this->attivi($torneo)->pluck('manager_id')->all();
        $calendario = ScheduleGenerator::build($ids, (int) $torneo->impostazione('gironi', 1));

        foreach ($calendario as $i => $turno) {
            $this->creaSfide($torneo, $turno, $torneo->start_matchday + $i, $i + 1, 'girone');
        }
    }

    public function avanza(Tournament $torneo, int $matchday): void
    {
        $this->risolviSfide($torneo, $matchday);

        if ($matchday >= $torneo->ultimaGiornata()) {
            $classifica = $this->classifica($torneo);

            $this->concludi($torneo, $classifica->first()['manager']->id ?? null);
        }
    }

    public function stato(Tournament $torneo): array
    {
        return [
            'vista' => 'tornei.formati.girone',
            'classifica' => $this->classifica($torneo),
            'sfide' => $torneo->matchups()->with(['home', 'away'])->orderBy('matchday')->get()->groupBy('matchday'),
        ];
    }

    /**
     * La classifica del torneo, dalle sue sole sfide.
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function classifica(Tournament $torneo)
    {
        $sfide = $torneo->matchups()->where('state', 'played')->get();

        $tabella = $this->attivi($torneo)->mapWithKeys(fn ($e) => [$e->manager_id => [
            'manager' => $e->manager,
            'punti' => 0,
            'fantapunti' => 0.0,
            'giocate' => 0,
        ]])->all();

        foreach ($sfide as $sfida) {
            foreach ([$sfida->home_manager_id, $sfida->away_manager_id] as $id) {
                if (! isset($tabella[$id])) {
                    continue;
                }

                $quota = $sfida->pointsFor($id);
                $tabella[$id]['punti'] += $quota['punti'];
                $tabella[$id]['fantapunti'] += $quota['fantapunti'];
                $tabella[$id]['giocate']++;
            }
        }

        return collect($tabella)
            ->sortBy([['punti', 'desc'], ['fantapunti', 'desc']])
            ->values();
    }
}
