<?php

namespace App\Services\Tournament\Formats;

use App\Models\Tournament;

/**
 * Tabellone a eliminazione diretta.
 *
 * A differenza del girone, il calendario **non si può conoscere in anticipo**:
 * il secondo turno dipende da chi vince il primo. Ogni turno si genera quando
 * il precedente si è chiuso.
 *
 * Con un numero di partecipanti che non è una potenza di due, chi ha la testa
 * di serie migliore salta il primo turno. È il modo classico di riempire un
 * tabellone, e premia la posizione in classifica invece di sorteggiare un
 * ripescaggio.
 */
class EliminazioneDiretta extends FormatoBase
{
    public function nome(): string
    {
        return 'Eliminazione diretta';
    }

    public function descrizione(): string
    {
        return 'Chi perde è fuori. Con numeri dispari, le teste di serie migliori saltano il primo turno.';
    }

    public function durata(int $partecipanti, array $settings): int
    {
        return max(1, (int) ceil(log(max(2, $partecipanti), 2)));
    }

    public function avvia(Tournament $torneo): void
    {
        $this->avviaDa($torneo, $torneo->start_matchday);
    }

    /**
     * Apre il tabellone a partire da una giornata qualunque.
     *
     * Pubblico perché serve anche a «gironi + eliminazione», che arriva qui
     * dopo la prima fase: il tabellone è lo stesso, cambia solo da dove parte.
     */
    public function avviaDa(Tournament $torneo, int $matchday): void
    {
        $turno = ($torneo->matchups()->max('round') ?? 0) + 1;

        $this->generaTurno($torneo, $matchday, $turno);
    }

    public function avanza(Tournament $torneo, int $matchday): void
    {
        $sfide = $this->risolviSfide($torneo, $matchday);

        foreach ($sfide as $sfida) {
            $vincitore = $this->vincitore($sfida, $torneo);
            $perdente = $vincitore === $sfida->home_manager_id
                ? $sfida->away_manager_id
                : $sfida->home_manager_id;

            $torneo->entries()->where('manager_id', $perdente)->first()?->elimina($matchday);
        }

        $superstiti = $this->attivi($torneo);

        if ($superstiti->count() <= 1) {
            $this->concludi($torneo, $superstiti->first()?->manager_id);

            return;
        }

        $turno = ($torneo->matchups()->max('round') ?? 0) + 1;
        $this->generaTurno($torneo, $matchday + 1, $turno);
    }

    /**
     * Accoppia i superstiti: primo contro ultimo, secondo contro penultimo.
     *
     * È l'accoppiamento che tiene lontane le teste di serie migliori fino in
     * fondo — altrimenti la finale rischia di giocarsi al primo turno.
     */
    private function generaTurno(Tournament $torneo, int $matchday, int $round): void
    {
        $superstiti = $this->attivi($torneo)->values();
        $quanti = $superstiti->count();

        if ($quanti < 2) {
            return;
        }

        // Chi entra in gioco: se il numero non è una potenza di due, i migliori
        // aspettano e gli altri si dimezzano fino a farlo diventare tale.
        $inGara = $this->quantiGiocano($quanti);
        $giocano = $superstiti->slice($quanti - $inGara)->values();

        $coppie = [];

        for ($i = 0; $i < intdiv($inGara, 2); $i++) {
            $coppie[] = [
                $giocano[$i]->manager_id,
                $giocano[$inGara - 1 - $i]->manager_id,
            ];
        }

        // Un turno in cui qualcuno riposa non è «gli ottavi»: è il preliminare
        // che serve a rendere il tabellone divisibile.
        $stage = $inGara < $quanti ? 'preliminare' : $this->nomeTurno($quanti);

        $this->creaSfide($torneo, $coppie, $matchday, $round, $stage);
    }

    /**
     * Quanti scendono in campo in questo turno.
     *
     * Si portano i superstiti alla potenza di due immediatamente inferiore: da
     * lì in poi il tabellone si dimezza pulito fino alla finale.
     */
    private function quantiGiocano(int $superstiti): int
    {
        $potenza = 2 ** (int) floor(log($superstiti, 2));

        return $superstiti === $potenza ? $superstiti : ($superstiti - $potenza) * 2;
    }

    private function nomeTurno(int $superstiti): string
    {
        return match (true) {
            $superstiti <= 2 => 'finale',
            $superstiti <= 4 => 'semifinali',
            $superstiti <= 8 => 'quarti',
            $superstiti <= 16 => 'ottavi',
            default => 'turno preliminare',
        };
    }

    public function stato(Tournament $torneo): array
    {
        return [
            'vista' => 'tornei.formati.tabellone',
            'turni' => $torneo->matchups()->with(['home', 'away'])->orderBy('round')->get()->groupBy('round'),
            'superstiti' => $this->attivi($torneo),
            'eliminati' => $torneo->entries()->where('state', 'eliminato')
                ->with('manager')->orderByDesc('eliminated_matchday')->get(),
        ];
    }
}
