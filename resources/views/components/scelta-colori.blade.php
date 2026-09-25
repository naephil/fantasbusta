@props(['campo', 'bersaglio', 'palette', 'colori', 'etichette' => ['Base', 'Motivo', 'Dettaglio']])

{{--
    Tre colori liberi, con le palette come scorciatoie.

    ⚠️ Le palette non sono sparite ed è deliberato: partire da tre selettori
    vuoti produce dodici squadre pasticciate: la palette curata dà il punto di
    partenza dignitoso, e da lì ognuno ritocca. Erano una gabbia, adesso sono
    un trampolino — chi ha in testa i colori della propria squadra li mette.

    I tre ruoli sono fissi e vale la pena saperli, perché sono quello che il
    disegno si aspetta: base è la tinta della maglia, motivo è il colore di
    righe/quarti/banda, dettaglio è colletto, polsini e orlo.
--}}
<div data-gruppo-colori="{{ $bersaglio }}">
    <p class="eyebrow mb-2 text-paper-dim">Colori</p>

    <div class="flex flex-wrap gap-4">
        @foreach ($etichette as $i => $etichetta)
            <label class="block">
                <span class="mb-1.5 block font-mono text-[10px] uppercase tracking-[0.2em] text-paper-dim">
                    {{ $etichetta }}
                </span>
                <input type="color" name="{{ $campo }}[]"
                       value="{{ $colori[$i] ?? '#000000' }}"
                       data-colore="{{ $bersaglio }}" data-indice="{{ $i }}"
                       class="h-10 w-16 cursor-pointer border border-ink-4 bg-ink-2 p-1">
            </label>
        @endforeach
    </div>

    @error($campo)
        <p class="mt-2 text-sm text-rosso">{{ $message }}</p>
    @enderror
    @error($campo.'.*')
        <p class="mt-2 text-sm text-rosso">{{ $message }}</p>
    @enderror

    <p class="eyebrow mb-2 mt-5 text-paper-dim">Oppure parti da una di queste</p>

    <div class="flex flex-wrap gap-2">
        @foreach ($palette as $voce)
            <button type="button" title="{{ $voce['nome'] }}"
                    data-preset="{{ $bersaglio }}" data-colori='@json($voce['colori'])'
                    class="flex items-center gap-2 border border-ink-4 py-1.5 pl-1.5 pr-3 transition hover:border-rosso">
                <span class="flex overflow-hidden rounded-sm">
                    @foreach ($voce['colori'] as $colore)
                        <span class="block size-5" style="background: {{ $colore }}"></span>
                    @endforeach
                </span>
                <span class="text-sm">{{ $voce['nome'] }}</span>
            </button>
        @endforeach
    </div>
</div>
