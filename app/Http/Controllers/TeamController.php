<?php

namespace App\Http\Controllers;

use App\Rules\TestoValido;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * L'identità della squadra: nome, allenatore, maglia, sponsor, stemma.
 *
 * Colori e forme si scelgono da insiemi chiusi. Non è pigrizia: con la
 * libertà totale escono dodici squadre indistinguibili e tre orrende, mentre
 * con una palette curata anche la scelta più frettolosa resta dignitosa.
 */
class TeamController extends Controller
{
    public function edit(Request $request): View
    {
        $manager = $request->user();

        return view('team.edit', [
            'manager' => $manager,
            'maglia' => $this->jersey($manager->jersey),
            'stemma' => $this->crest($manager->crest),
            'sponsor' => $this->sponsor($manager->sponsor),
            'palette' => config('squadra.palette'),
            'stili' => config('squadra.stili_maglia'),
            'forme' => config('squadra.forme_stemma'),
            'simboli' => config('squadra.simboli'),
            'sponsorModelli' => config('squadra.sponsor_modelli'),
            'sponsorStili' => config('squadra.sponsor_stili'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $manager = $request->user();
        $upload = config('squadra.upload');
        $sponsorUpload = config('squadra.upload_sponsor');

        $dati = $request->validate([
            'name' => ['required', 'string', 'max:40', new TestoValido],
            'coach_name' => ['nullable', 'string', 'max:40', new TestoValido],

            // ⚠️ I colori sono tre valori liberi, non più il nome di una palette.
            // Le palette restano nella pagina come punti di partenza — dodici
            // squadre che scelgono da zero fanno dodici pastrocchi — ma da
            // scorciatoie, non da gabbia: chi ha i colori della propria squadra
            // in testa deve poterli mettere.
            'maglia_colori' => ['required', 'array', 'size:3'],
            'maglia_colori.*' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'maglia_stile' => ['required', Rule::in(array_keys(config('squadra.stili_maglia')))],

            'stemma_colori' => ['required', 'array', 'size:3'],
            'stemma_colori.*' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'stemma_forma' => ['required', Rule::in(array_keys(config('squadra.forme_stemma')))],
            'stemma_simbolo' => ['required', Rule::in(array_keys(config('squadra.simboli')))],

            'stemma_file' => ['nullable', 'file', 'max:'.$upload['max_kb'], 'mimes:'.implode(',', $upload['formati'])],
            'rimuovi_stemma' => ['nullable', 'boolean'],

            'sponsor_testo' => ['nullable', 'string', 'max:18', new TestoValido],
            'sponsor_stile' => ['required', Rule::in(array_keys(config('squadra.sponsor_stili')))],
            'sponsor_posizione' => ['required', Rule::in(['alto', 'centro', 'basso'])],
            'sponsor_riquadro' => ['nullable', 'boolean'],
            'sponsor_colore' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sponsor_colore_riquadro' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sponsor_file' => ['nullable', 'file', 'max:'.$sponsorUpload['max_kb'], 'mimes:'.implode(',', $sponsorUpload['formati'])],
            'rimuovi_sponsor' => ['nullable', 'boolean'],
        ], [], [
            'name' => 'nome della squadra',
            'maglia_colori' => 'i colori della maglia',
            'stemma_colori' => 'i colori dello stemma',
            'stemma_file' => 'file dello stemma',
            'sponsor_testo' => 'testo dello sponsor',
            'sponsor_colore' => 'il colore della scritta',
            'sponsor_file' => 'file dello sponsor',
        ]);

        $manager->fill([
            'name' => $dati['name'],
            'coach_name' => $dati['coach_name'] ?? null,
            'jersey' => [
                'colori' => array_map('strtolower', $dati['maglia_colori']),
                'stile' => $dati['maglia_stile'],
            ],
            'crest' => [
                'colori' => array_map('strtolower', $dati['stemma_colori']),
                'forma' => $dati['stemma_forma'],
                'simbolo' => $dati['stemma_simbolo'],
            ],
            'sponsor' => [
                'testo' => trim($dati['sponsor_testo'] ?? ''),
                'stile' => $dati['sponsor_stile'],
                'posizione' => $dati['sponsor_posizione'],
                'riquadro' => $request->boolean('sponsor_riquadro'),
                'colore' => strtolower($dati['sponsor_colore']),
                'colore_riquadro' => strtolower($dati['sponsor_colore_riquadro']),
            ],
        ]);

        if ($request->boolean('rimuovi_stemma')) {
            $this->deleteFile($manager->crest_path);
            $manager->crest_path = null;
        }

        if ($file = $request->file('stemma_file')) {
            $this->deleteFile($manager->crest_path);
            $manager->crest_path = $file->store($upload['cartella'], 'public');
        }

        if ($request->boolean('rimuovi_sponsor')) {
            $this->deleteFile($manager->sponsor_path);
            $manager->sponsor_path = null;
        }

        if ($file = $request->file('sponsor_file')) {
            $this->deleteFile($manager->sponsor_path);
            $manager->sponsor_path = $file->store($sponsorUpload['cartella'], 'public');
        }

        $manager->save();

        return back()->with('successo', 'Squadra aggiornata.');
    }

    /** Vale per stemma e sponsor: sostituire un file significa cancellare il vecchio. */
    private function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * I valori con cui aprire il modulo.
     *
     * ⚠️ I `+` sul salvato non riempiono le chiavi che ci sono ma sono nulle,
     * solo quelle assenti — e va bene così: le squadre salvate prima dei colori
     * liberi hanno `colori` già valorizzato dalla palette di allora, che è
     * esattamente ciò che devono continuare a mostrare. Il campo `palette` che
     * portano dietro non serve più a nessuno e resta lì innocuo.
     *
     * @return array<string,mixed>
     */
    private function jersey(?array $salvata): array
    {
        return ($salvata ?? []) + [
            'colori' => config('squadra.palette.fantasbusta.colori'),
            'stile' => 'tinta',
        ];
    }

    /** @return array<string,mixed> */
    private function sponsor(?array $salvato): array
    {
        return ($salvato ?? []) + [
            'testo' => '',
            'stile' => 'blocco',
            'posizione' => 'centro',
            'riquadro' => false,
            // Il default cade sul terzo colore della maglia, che è quello di
            // dettaglio: è il valore che il disegno usava prima che il colore
            // fosse scegliibile, quindi nessuna squadra cambia aspetto.
            'colore' => null,
            'colore_riquadro' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function crest(?array $salvato): array
    {
        return ($salvato ?? []) + [
            'colori' => config('squadra.palette.fantasbusta.colori'),
            'forma' => 'scudo',
            'simbolo' => 'stella',
        ];
    }
}
