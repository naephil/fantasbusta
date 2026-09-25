@props([
    'carta',
    'larghezza' => null,
    'sigillata' => false,
    'coperta' => false,
])

{{--
    La carta. Markup unico: lo usa la rosa, lo usa il ventaglio finale, e lo usa
    l'apertura della busta — che se lo fa mandare dal server già reso invece di
    ricostruirselo in JavaScript. Con due renderer si finisce sempre per
    aggiustarne uno solo.

    Le misure interne sono in `cqw`, quindi basta cambiare la larghezza dello
    `.slot` perché tutto si riscali senza tagliare niente.
--}}
<div class="slot @if ($sigillata) sealed @endif"
     @if ($larghezza) style="--card-w: {{ $larghezza }}" @endif>
    <article class="card @if ($coperta) flipped @endif"
             data-tier="{{ $carta['tier'] }}"
             style="--role: var(--r-{{ $carta['role'] }}); --club: {{ $carta['clubColor'] }}">

        <div class="face front">
            <div class="shot">
                @if ($carta['photo'])
                    {{-- ⚠️ Il ritratto sta a SINISTRA e non a tutta larghezza, e
                         non è una scelta estetica: i ritratti dell'API sono
                         quadrati, e un riquadro più largo che alto li ritagliava
                         in verticale — con `object-position: top` il taglio
                         cadeva tutto sul mento. Su un riquadro più alto che largo
                         il ritaglio è orizzontale e prende le spalle, che non
                         servono a nessuno. La faccia resta intera. --}}
                    <img src="{{ $carta['photo'] }}"
                         alt="{{ $carta['first'] }} {{ $carta['last'] }}"
                         loading="lazy"
                         onerror="this.remove()">

                @else
                    {{-- Identità non verificata: solo iniziali. Vedi CardPresenter. --}}
                    <div class="fallback"><span>{{ $carta['initials'] }}</span></div>
                @endif
            </div>

            {{-- ── La colonna di destra ──
                 Power, iniziali, ruolo e rarità incolonnati nella fascia che il
                 ritratto lascia libera. Prima ruolo e rarità stavano in alto a
                 sinistra, cioè sopra la faccia: la coprivano proprio dove si
                 guarda. Qui non si sovrappone più niente, e ogni elemento ha lo
                 spazio per essere grande abbastanza da leggersi. --}}
            <div class="rail">
                <div class="power">
                    <div class="n">{{ $carta['power'] }}</div>
                    <div class="l">Power</div>
                </div>

                <div class="sigla" aria-hidden="true">{{ $carta['initials'] }}</div>

                <div class="badges">
                    <div class="role">{{ $carta['role'] }}</div>
                    <div class="tier">{{ $carta['tier'] }}</div>
                </div>
            </div>

            <div class="id">
                <div class="band"></div>
                <div class="first">{{ $carta['first'] }}</div>
                <div class="last" data-len="{{ mb_strlen($carta['last']) > 10 ? 'long' : (mb_strlen($carta['last']) > 8 ? 'med' : 'reg') }}">
                    {{ $carta['last'] }}
                </div>
                <div class="club">
                    <i></i><span class="name">{{ $carta['club'] }}</span>
                    <span class="trend {{ $carta['trend'] }}">{{ $carta['trendLabel'] }}</span>
                </div>
            </div>

            <div class="stats">
                <div class="stat"><div class="v">{{ $carta['fm'] }}</div><div class="k">Fantamedia</div></div>
                <div class="stat"><div class="v {{ $carta['formTrend'] }}">{{ $carta['form'] }}</div><div class="k">Forma</div></div>
                <div class="stat"><div class="v">{{ $carta['quot'] }}</div><div class="k">Quot.</div></div>
            </div>

            <div class="holo"></div><div class="foil"></div><div class="frame"></div>
        </div>

        {{-- Il verso è identico su tutti i tier: altrimenti si saprebbe cosa
             c'è sotto prima di girarla, e l'apertura non varrebbe niente. --}}
        <div class="face back">
            <div class="rings"></div>

            {{-- ── La rosetta ──

                 Il cerchio centrale era vuoto: due anelli con dentro il nulla, e
                 il monogramma che ci galleggiava sopra senza appoggiarsi a
                 niente. Qui dentro va il mestiere della stampa di pregio —
                 guilloche, raggi, micro-testo circolare — che è lo stesso
                 linguaggio di una figurina, di un francobollo e di una
                 banconota: cose fatte per essere guardate da vicino.

                 In SVG e non in CSS perché il testo curvo lungo un cerchio in
                 CSS non esiste, e sono proprio quelle lettere in giro a fare la
                 differenza fra «una texture» e «una cosa stampata». --}}
            <svg class="rosetta" viewBox="0 0 100 100" aria-hidden="true">
                {{-- I raggi: fitti e sottili, come l'incisione di un fondo di
                     sicurezza. Vanno sotto a tutto il resto. --}}
                <g class="raggi">
                    @for ($i = 0; $i < 36; $i++)
                        <line x1="50" y1="50" x2="50" y2="14"
                              transform="rotate({{ $i * 10 }} 50 50)" />
                    @endfor
                </g>

                <circle class="anello" cx="50" cy="50" r="36" />
                <circle class="campo" cx="50" cy="50" r="25.5" />
                <circle class="anello sottile" cx="50" cy="50" r="25.5" />

                {{-- Il micro-testo che gira: si legge solo avvicinandosi, ed è
                     esattamente il motivo per cui c'è. Il tracciato sta nello
                     sprite condiviso — un `id` solo per tutta la pagina. --}}
                <text class="giro">
                    <textPath href="#fb-giro" startOffset="0">
                        FANTASBUSTA · SERIE A · FANTASBUSTA · SERIE A ·
                    </textPath>
                </text>
            </svg>

            {{-- ⚠️ Ogni angolo dice DOVE sta, invece di lasciarlo dedurre alla
                 posizione fra i fratelli. Il CSS li pescava con `nth-child(1..4)`,
                 ma il primo figlio è la raggiera: i quattro angoli sono figli
                 dal secondo al quinto, quindi tre prendevano la regola del
                 vicino e il quarto non ne prendeva nessuna — restava appoggiato
                 in alto a sinistra, sopra a un altro. Con una classe per angolo
                 il legame non dipende più dall'ordine del markup. --}}
            <div class="corner tl"></div><div class="corner tr"></div>
            <div class="corner bl"></div><div class="corner br"></div>

            <div class="mono"><b>F<em>B</em></b></div>
        </div>
    </article>
</div>
