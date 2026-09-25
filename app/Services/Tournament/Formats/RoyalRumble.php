<?php

namespace App\Services\Tournament\Formats;

use App\Models\Tournament;

/**
 * Royal rumble: ogni giornata esce chi ha totalizzato meno.
 *
 * L'unico formato senza partite. Non ci sono accoppiamenti da generare né
 * sfide da risolvere: si guarda la giornata, si ordina, si elimina la coda.
 * È la ragione per cui il contratto dei formati non presuppone `matchups`.
 *
 * Chi è già eliminato non conta più, anche se quella giornata avrebbe fatto il
 * punteggio più alto della lega. È crudele ed è il punto.
 */
class RoyalRumble extends FormatoBase
{
    public function nome(): string
    {
        return 'Royal rumble';
    }

    public function descrizione(): string
    {
        return 'Nessuna sfida: ogni giornata esce chi ha fatto meno fantapunti. Sopravvive uno.';
    }

    /** @return array{0:int,1:int} */
    public function partecipanti(): array
    {
        return [3, 32];
    }

    public function durata(int $partecipanti, array $settings): int
    {
        $perGiornata = max(1, (int) ($settings['eliminati_per_giornata'] ?? 1));

        return (int) ceil(($partecipanti - 1) / $perGiornata);
    }

    /** Niente da preparare: si parte tutti insieme e si vede chi resta. */
    public function avvia(Tournament $torneo): void
    {
        //
    }

    public function avanza(Tournament $torneo, int $matchday): void
    {
        $superstiti = $this->attivi($torneo);

        if ($superstiti->count() <= 1) {
            $this->concludi($torneo, $superstiti->first()?->manager_id);

            return;
        }

        $totali = $this->totali->forMatchday($torneo->league_season_id, $matchday);

        // A parità di fantapunti esce la testa di serie peggiore: senza un
        // criterio secondario, due squadre appaiate in coda bloccherebbero il
        // torneo per sempre.
        $classifica = $superstiti
            ->map(fn ($e) => [
                'entry' => $e,
                'totale' => (float) $totali->get($e->manager_id, 0),
                'seed' => $e->seed,
            ])
            ->sortBy([['totale', 'asc'], ['seed', 'desc']])
            ->values();

        $quanti = min(
            max(1, (int) $torneo->impostazione('eliminati_per_giornata', 1)),
            $superstiti->count() - 1,
        );

        foreach ($classifica->take($quanti) as $riga) {
            $riga['entry']->elimina($matchday);
        }

        $rimasti = $this->attivi($torneo);

        if ($rimasti->count() <= 1) {
            $this->concludi($torneo, $rimasti->first()?->manager_id);
        }
    }

    public function stato(Tournament $torneo): array
    {
        return [
            'vista' => 'tornei.formati.rumble',
            'superstiti' => $this->attivi($torneo)->load('manager'),
            'eliminati' => $torneo->entries()
                ->where('state', 'eliminato')
                ->with('manager')
                ->orderByDesc('eliminated_matchday')
                ->get(),
        ];
    }
}
