@props([
    'stemma' => [],
    'file' => null,
    'iniziali' => '',
    'dimensione' => 96,
])

@php
    $colori = $stemma['colori'] ?? config('squadra.palette.fantasbusta.colori');
    $forma = $stemma['forma'] ?? 'scudo';
    $simbolo = $stemma['simbolo'] ?? 'stella';
@endphp

@if ($file)
    {{-- Lo stemma caricato vince su quello composto: chi porta il proprio
         logo non se lo vuole vedere sostituito da un disegno generato. --}}
    <img src="{{ Storage::url($file) }}" alt="Stemma"
         width="{{ $dimensione }}" height="{{ $dimensione }}"
         class="object-contain" {{ $attributes }}>
@else
    <svg viewBox="0 0 100 100" width="{{ $dimensione }}" height="{{ $dimensione }}"
         class="stemma" data-forma="{{ $forma }}" role="img"
         style="--s1: {{ $colori[0] }}; --s2: {{ $colori[1] }}; --s3: {{ $colori[2] }}"
         {{ $attributes }}>

        <title>Stemma</title>

        {{-- Le quattro sagome convivono nel markup e le accende il CSS, come
             per la maglia: un renderer solo, anteprima dal vivo gratis. --}}
        <g class="f-scudo">
            <path d="M50,4 L92,16 V52 Q92,80 50,96 Q8,80 8,52 V16 Z" fill="var(--s1)"/>
            <path d="M50,4 L92,16 V52 Q92,80 50,96 Q8,80 8,52 V16 Z" fill="none"
                  stroke="var(--s3)" stroke-width="3"/>
        </g>

        <g class="f-cerchio">
            <circle cx="50" cy="50" r="46" fill="var(--s1)"/>
            <circle cx="50" cy="50" r="46" fill="none" stroke="var(--s3)" stroke-width="3"/>
            <circle cx="50" cy="50" r="39" fill="none" stroke="var(--s3)" stroke-width="1.5" opacity=".6"/>
        </g>

        <g class="f-rombo">
            <path d="M50,3 L95,50 L50,97 L5,50 Z" fill="var(--s1)"/>
            <path d="M50,3 L95,50 L50,97 L5,50 Z" fill="none" stroke="var(--s3)" stroke-width="3"/>
        </g>

        <g class="f-esagono">
            <path d="M50,3 L91,26 V74 L50,97 L9,74 V26 Z" fill="var(--s1)"/>
            <path d="M50,3 L91,26 V74 L50,97 L9,74 V26 Z" fill="none" stroke="var(--s3)" stroke-width="3"/>
        </g>

        {{-- Simbolo e iniziali convivono, uno dei due nascosto: all'anteprima
             basta scambiare l'attributo `hidden` invece di ricostruire l'SVG. --}}
        <text x="50" y="63" text-anchor="middle" fill="var(--s2)" @if ($simbolo !== 'nessuno') hidden @endif
              style="font-family: var(--font-display); font-weight: 900; font-size: 40px; letter-spacing: -2px">
            {{ mb_strtoupper(mb_substr($iniziali, 0, 3)) }}
        </text>

        <g class="simbolo" fill="var(--s2)" transform="translate(50 50) scale(0.62) translate(-50 -50)"
           @if ($simbolo === 'nessuno') hidden @endif>
            <use href="#sim-{{ $simbolo === 'nessuno' ? 'stella' : $simbolo }}"/>
        </g>
    </svg>
@endif
