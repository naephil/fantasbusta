<?php

namespace App\Services\Season;

use App\Models\Fixture;
use App\Models\LeagueSeason;
use App\Models\PlayerSeason;
use App\Models\PlayerStat;
use App\Services\Ingest\ReferenceSync;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Porta in casa i dati di riferimento di un'annata di Serie A.
 *
 * Il piano gratuito di API-Football copre le stagioni passate con i rating
 * veri: rigiocare il 2023/24 non è una simulazione, sono i voti di allora.
 *
 * Le annate convivono. Squadre, listone, calendario e statistiche sono tutti
 * marcati con l'anno, quindi caricare il 2024 non tocca il 2023 e due gruppi
 * di amici possono giocare anni diversi nello stesso momento. È il motivo per
 * cui questo servizio non cancella più niente per fare spazio.
 */
class SeasonLoader
{
    public function __construct(private ReferenceSync $sync) {}

    /**
     * Le annate già in casa, dalla più recente.
     *
     * @return Collection<int,int>
     */
    public function disponibili(): Collection
    {
        return Fixture::select('season')
            ->distinct()
            ->orderByDesc('season')
            ->pluck('season')
            ->map(fn ($s) => (int) $s);
    }

    /** Un'annata è giocabile se ha listone e calendario. */
    public function pronta(int $season): bool
    {
        return PlayerSeason::where('season', $season)->exists()
            && Fixture::where('season', $season)->exists();
    }

    /**
     * Scarica squadre, listone e calendario di un'annata.
     *
     * Ripetibile: `ReferenceSync` lavora in updateOrCreate, quindi rilanciarlo
     * su un'annata già caricata la aggiorna invece di duplicarla. Serve, perché
     * un caricamento può interrompersi a metà per il tetto di chiamate.
     *
     * @return array{squadre: int, giocatori: int, partite: int, giornate: int}
     */
    public function carica(int $season): array
    {
        $squadre = $this->sync->teams($season);
        $giocatori = $this->sync->players($season);
        $partite = $this->sync->fixtures($season);

        return [
            'squadre' => $squadre,
            'giocatori' => $giocatori['sincronizzati'],
            'partite' => $partite,
            'giornate' => (int) Fixture::where('season', $season)->max('matchday'),
        ];
    }

    /** Quante giornate ha il calendario di quell'annata. */
    public function giornate(int $season): int
    {
        return (int) Fixture::where('season', $season)->max('matchday');
    }

    /**
     * Butta via i dati di riferimento di un'annata.
     *
     * ⚠️ Si rifiuta se qualcuno ci sta giocando: cancellare il listone sotto a
     * una stagione di lega in corso lascerebbe carte che puntano a giocatori
     * senza ruolo, cioè rose che non si possono più schierare. Prima si
     * cancella la stagione di lega, poi eventualmente i suoi dati.
     */
    public function scarta(int $season): void
    {
        $inUso = LeagueSeason::where('season', $season)->count();

        if ($inUso > 0) {
            throw new RuntimeException(
                "Ci sono {$inUso} stagioni di lega che giocano il {$season}: cancellale prima di buttare i dati.",
            );
        }

        DB::transaction(function () use ($season) {
            // Voti e power se ne vanno con le stagioni di lega che li hanno
            // prodotti — e a questo punto non ce ne sono più, perché il
            // controllo qui sopra si rifiuta di procedere altrimenti.
            PlayerStat::where('season', $season)->delete();
            PlayerSeason::where('season', $season)->delete();
            Fixture::where('season', $season)->delete();
        });
    }
}
