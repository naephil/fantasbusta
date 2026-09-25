<?php

namespace App\Services\Tournament\Formats;

use App\Models\Tournament;
use App\Services\Calendar\ScheduleGenerator;
use Illuminate\Support\Collection;

/**
 * Gironi e poi tabellone: la vecchia Champions.
 *
 * Due fasi con regole diverse dentro lo stesso torneo. La prima si conosce
 * tutta in anticipo — sono gironi all'italiana — la seconda no, perché dipende
 * da chi si qualifica.
 *
 * Il passaggio fra le due è il punto delicato: la fase a gironi finisce a una
 * giornata precisa, e da quella in poi il torneo cambia mestiere. Le
 * qualificate diventano le teste di serie del tabellone, riordinate per
 * rendimento nel girone — così chi ha fatto bene non incontra subito un'altra
 * prima classificata.
 */
class GironiPiuEliminazione extends FormatoBase
{
    public function nome(): string
    {
        return 'Gironi + eliminazione';
    }

    public function descrizione(): string
    {
        return 'Prima fase a gironi, poi tabellone fra le qualificate. Come la Champions di una volta.';
    }

    /** @return array{0:int,1:int} */
    public function partecipanti(): array
    {
        return [4, 32];
    }

    public function durata(int $partecipanti, array $settings): int
    {
        $gruppi = max(2, (int) ($settings['gruppi'] ?? 2));
        $qualificate = max(1, (int) ($settings['qualificate'] ?? 2));

        $perGruppo = (int) ceil($partecipanti / $gruppi);
        $faseGironi = $perGruppo % 2 === 0 ? $perGruppo - 1 : $perGruppo;

        $ammesse = $gruppi * $qualificate;
        $faseFinale = max(1, (int) ceil(log(max(2, $ammesse), 2)));

        return $faseGironi + $faseFinale;
    }

    public function avvia(Tournament $torneo): void
    {
        $gruppi = max(2, (int) $torneo->impostazione('gruppi', 2));
        $iscritti = $this->attivi($torneo)->values();

        // Distribuzione a serpentina: la prima testa di serie nel gruppo A, la
        // seconda nel B, e via, poi si torna indietro. Riempire i gruppi in
        // ordine metterebbe le quattro migliori tutte insieme.
        foreach ($iscritti as $i => $entry) {
            $giro = intdiv($i, $gruppi);
            $posizione = $i % $gruppi;
            $indice = $giro % 2 === 0 ? $posizione : $gruppi - 1 - $posizione;

            $entry->update(['girone' => chr(65 + $indice)]);
        }

        foreach ($torneo->entries()->get()->groupBy('girone') as $lettera => $gruppo) {
            $calendario = ScheduleGenerator::build($gruppo->pluck('manager_id')->all(), 1);

            foreach ($calendario as $i => $turno) {
                $this->creaSfide($torneo, $turno, $torneo->start_matchday + $i, $i + 1, "gruppo {$lettera}");
            }
        }
    }

    public function avanza(Tournament $torneo, int $matchday): void
    {
        $this->risolviSfide($torneo, $matchday);

        if ($matchday < $this->fineGironi($torneo)) {
            return;   // la fase a gironi non è ancora finita
        }

        if ($matchday === $this->fineGironi($torneo)) {
            $this->apriTabellone($torneo, $matchday);

            return;
        }

        $this->avanzaTabellone($torneo, $matchday);
    }

    /** Dove finisce la prima fase. */
    private function fineGironi(Tournament $torneo): int
    {
        $gruppi = max(2, (int) $torneo->impostazione('gruppi', 2));
        $perGruppo = (int) ceil($torneo->entries()->count() / $gruppi);
        $turni = $perGruppo % 2 === 0 ? $perGruppo - 1 : $perGruppo;

        return $torneo->start_matchday + $turni - 1;
    }

    /** Chiude i gironi, elimina i non qualificati, apre il tabellone. */
    private function apriTabellone(Tournament $torneo, int $matchday): void
    {
        $qualificate = max(1, (int) $torneo->impostazione('qualificate', 2));
        $ammesse = collect();

        foreach ($torneo->entries()->get()->groupBy('girone') as $gruppo) {
            $ordinato = $this->classificaGruppo($torneo, $gruppo);

            $ammesse = $ammesse->concat($ordinato->take($qualificate));

            foreach ($ordinato->slice($qualificate) as $riga) {
                $riga['entry']->elimina($matchday);
            }
        }

        // Le teste di serie si riscrivono sul rendimento: chi ha vinto il
        // proprio girone parte davanti nel tabellone.
        $ammesse->sortBy([['punti', 'desc'], ['fantapunti', 'desc']])
            ->values()
            ->each(fn ($riga, $i) => $riga['entry']->update(['seed' => $i + 1]));

        app(EliminazioneDiretta::class)->avviaDa($torneo, $matchday + 1);
    }

    private function avanzaTabellone(Tournament $torneo, int $matchday): void
    {
        app(EliminazioneDiretta::class)->avanza($torneo, $matchday);
    }

    /**
     * @param  Collection  $gruppo
     * @return Collection<int,array<string,mixed>>
     */
    private function classificaGruppo(Tournament $torneo, $gruppo)
    {
        $ids = $gruppo->pluck('manager_id')->all();

        $sfide = $torneo->matchups()
            ->where('state', 'played')
            ->whereIn('home_manager_id', $ids)
            ->get();

        $tabella = $gruppo->mapWithKeys(fn ($e) => [$e->manager_id => [
            'entry' => $e,
            'punti' => 0,
            'fantapunti' => 0.0,
        ]])->all();

        foreach ($sfide as $sfida) {
            foreach ([$sfida->home_manager_id, $sfida->away_manager_id] as $id) {
                if (! isset($tabella[$id])) {
                    continue;
                }

                $quota = $sfida->pointsFor($id);
                $tabella[$id]['punti'] += $quota['punti'];
                $tabella[$id]['fantapunti'] += $quota['fantapunti'];
            }
        }

        return collect($tabella)->sortBy([['punti', 'desc'], ['fantapunti', 'desc']])->values();
    }

    public function stato(Tournament $torneo): array
    {
        return [
            'vista' => 'tornei.formati.gironi',
            'gruppi' => $torneo->entries()->with('manager')->get()->groupBy('girone'),
            'sfide' => $torneo->matchups()->with(['home', 'away'])->orderBy('matchday')->get()->groupBy('stage'),
            'superstiti' => $this->attivi($torneo),
        ];
    }
}
