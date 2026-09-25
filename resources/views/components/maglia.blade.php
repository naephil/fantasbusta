@props([
    'maglia' => [],
    'sponsor' => [],
    'sponsorFile' => null,
    'dimensione' => 96,
])

@php
    $colori = $maglia['colori'] ?? config('squadra.palette.fantasbusta.colori');
    $stile = $maglia['stile'] ?? 'tinta';
    $id = 'm'.substr(md5(json_encode($maglia).spl_object_id($__env)), 0, 8);

    $sponsorTesto = $sponsor['testo'] ?? '';
    $sponsorStile = $sponsor['stile'] ?? 'blocco';
    $conImmagine = (bool) $sponsorFile;

    // Dove cade il marchio sul petto. Tre altezze e non un valore libero: sulla
    // maglia c'è spazio per tre posizioni sensate, e un cursore continuo
    // servirebbe solo a farlo finire sul colletto o sull'orlo.
    $sponsorY = match ($sponsor['posizione'] ?? 'centro') {
        'alto' => 53,
        'basso' => 73,
        default => 63,
    };

    $sponsorRiquadro = (bool) ($sponsor['riquadro'] ?? false);
    $sponsorColore = $sponsor['colore'] ?? null;
    $coloreRiquadro = $sponsor['colore_riquadro'] ?? null;
@endphp

{{--
    La maglia. Un solo disegno per tutti gli stili: i motivi stanno tutti nel
    markup e li accende il CSS in base a `data-stile`.

    È ciò che permette all'anteprima dal vivo di funzionare senza un secondo
    renderer in JavaScript: cambiare stile significa cambiare un attributo,
    cambiare colore significa cambiare una variabile CSS. Il disegno resta uno.
--}}
@php
    // ⚠️ La sagoma è stata riproporzionata: prima il busto era 48 unità largo
    // per 70 alte — rapporto 1,46 — e la maglia si leggeva «allungata», più da
    // tunica che da maglia da calcio. Una maglia vera sta attorno a 1,4 fra
    // spalle e orlo, con le maniche che scendono a metà busto e non a un terzo.
    //
    // Anche il riquadro è cambiato: era 100×112, cioè più alto che largo,
    // quindi accanto a uno stemma quadrato la maglia sporgeva sempre in basso e
    // le due identità non si allineavano mai. Adesso è quadrato come lo stemma.
    $sagoma = 'M31,17 L43,10 Q50,18 57,10 L69,17 L95,31 L86,51 L75,44 L75,88 L25,88 L25,44 L14,51 L5,31 Z';
@endphp

<svg viewBox="0 0 100 100" width="{{ $dimensione }}" height="{{ $dimensione }}"
     class="maglia" data-stile="{{ $stile }}" data-sponsor-stile="{{ $sponsorStile }}" role="img"
     style="--m1: {{ $colori[0] }}; --m2: {{ $colori[1] }}; --m3: {{ $colori[2] }}"
     {{ $attributes }}>

    <title>Maglia {{ config("squadra.stili_maglia.{$stile}", $stile) }}</title>

    <defs>
        <clipPath id="{{ $id }}">
            <path d="{{ $sagoma }}"/>
        </clipPath>
    </defs>

    {{-- Corpo --}}
    <path d="{{ $sagoma }}" fill="var(--m1)"/>

    <g clip-path="url(#{{ $id }})">
        <g class="p-righe">
            @foreach ([6, 24, 42, 60, 78, 96] as $x)
                <rect x="{{ $x }}" y="0" width="9" height="100" fill="var(--m2)"/>
            @endforeach
        </g>

        <g class="p-cerchiati">
            @foreach ([16, 36, 56, 76] as $y)
                <rect x="0" y="{{ $y }}" width="100" height="10" fill="var(--m2)"/>
            @endforeach
        </g>

        <g class="p-banda">
            <rect x="0" y="44" width="100" height="20" fill="var(--m2)"/>
        </g>

        <g class="p-sash">
            <rect x="-30" y="38" width="160" height="22" fill="var(--m2)"
                  transform="rotate(-38 50 50)"/>
        </g>

        <g class="p-quarti">
            <rect x="0" y="0" width="50" height="50" fill="var(--m2)"/>
            <rect x="50" y="50" width="50" height="50" fill="var(--m2)"/>
        </g>

        <g class="p-meta">
            <rect x="50" y="0" width="50" height="100" fill="var(--m2)"/>
        </g>
    </g>

    {{-- Colletto e polsini: sempre il colore di dettaglio, così anche la tinta
         unita ha un punto di contrasto e non sembra una sagoma vuota. --}}
    <path d="M43,10 Q50,18 57,10 L55,18 Q50,23 45,18 Z" fill="var(--m3)"/>
    <path d="M95,31 L86,51 L80,47 L89,28 Z" fill="var(--m3)"/>
    <path d="M5,31 L14,51 L20,47 L11,28 Z" fill="var(--m3)"/>
    <rect x="25" y="82" width="50" height="6" fill="var(--m3)" opacity=".85"/>

    {{-- ── Sponsor sul petto ──
         Testo e immagine convivono, uno dei due nascosto: all'anteprima basta
         scambiare `hidden`, come per lo stemma.

         Il testo è forzato a una larghezza fissa con `textLength`, così un
         marchio da quattro lettere e uno da tredici occupano lo stesso spazio
         e nessuno dei due sborda dal petto. La spaziatura larga sui nomi corti
         non è un effetto collaterale: è come sono fatti i marchi veri. --}}
    {{-- Il riquadro dietro alla scritta: su una maglia a righe o a quarti un
         testo nudo cade a metà fra due colori e diventa illeggibile. È lo
         stesso rimedio che usano le maglie vere. --}}
    <rect class="sponsor-box" x="26" y="{{ $sponsorY - 9 }}" width="48" height="13" rx="1.5"
          fill="{{ $coloreRiquadro ?: 'var(--m1)' }}"
          @unless ($sponsorRiquadro && ! $conImmagine && $sponsorTesto !== '') hidden @endunless />

    <text class="sponsor" x="50" y="{{ $sponsorY }}" text-anchor="middle"
          fill="{{ $sponsorColore ?: 'var(--m3)' }}"
          textLength="40" lengthAdjust="spacingAndGlyphs"
          @if ($conImmagine || $sponsorTesto === '') hidden @endif>{{ $sponsorTesto }}</text>

    <image class="sponsor-img" x="28" y="{{ $sponsorY - 15 }}" width="44" height="18"
           preserveAspectRatio="xMidYMid meet"
           href="{{ $sponsorFile ? Storage::url($sponsorFile) : '' }}"
           @unless ($conImmagine) hidden @endunless />

    {{-- ⚠️ Niente numero, ed è una rimozione voluta. Stava in basso al centro,
         dove su una maglia vera non c'è niente — il numero sta sulla schiena, e
         questa è la vista frontale. Oltre a non avere senso tirava l'occhio
         verso il fondo e faceva leggere la maglia come più lunga di quanto è:
         era metà del motivo per cui sembrava «sformata». --}}

    {{-- Contorno: tiene insieme il disegno anche su maglie chiare. --}}
    <path d="{{ $sagoma }}" fill="none" stroke="rgba(0,0,0,.45)" stroke-width="1.5"/>
</svg>
