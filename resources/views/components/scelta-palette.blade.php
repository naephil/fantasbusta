@props(['campo', 'bersaglio', 'palette', 'attuale'])

{{-- Le terne di colori. Ogni pastiglia porta con sé i propri colori in un
     data-attribute, così l'anteprima li applica senza doverli conoscere. --}}
<p class="eyebrow mb-2 text-paper-dim">Colori</p>

<div class="flex flex-wrap gap-2">
    @foreach ($palette as $chiave => $voce)
        <label class="cursor-pointer" title="{{ $voce['nome'] }}">
            <input type="radio" name="{{ $campo }}" value="{{ $chiave }}" class="peer sr-only"
                   data-palette="{{ $bersaglio }}"
                   data-colori='@json($voce['colori'])'
                   @checked($attuale === $chiave)>

            <span class="flex items-center gap-2 border border-ink-4 py-1.5 pl-1.5 pr-3 transition peer-checked:border-rosso peer-checked:bg-rosso/15 hover:border-paper-dim">
                <span class="flex overflow-hidden rounded-sm">
                    @foreach ($voce['colori'] as $colore)
                        <span class="block size-6" style="background: {{ $colore }}"></span>
                    @endforeach
                </span>
                <span class="text-sm">{{ $voce['nome'] }}</span>
            </span>
        </label>
    @endforeach
</div>
