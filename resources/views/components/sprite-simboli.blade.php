{{--
    I simboli dello stemma, una volta sola per pagina.

    Stanno qui e non dentro `x-stemma` perché una classifica ne mostra dodici:
    inlineare otto tracciati per ogni squadra significherebbe ripetere lo
    stesso disegno cento volte. Così ogni stemma è un `<use>` di tre byte.
--}}
<svg width="0" height="0" aria-hidden="true" style="position:absolute">
    <defs>
        {{-- Il cerchio su cui gira il micro-testo del verso delle carte.

             ⚠️ Sta QUI e non dentro la carta, e non è un'ottimizzazione: un
             `id` dev'essere unico nel documento, e una pagina di carte ne
             mostra venticinque. Definendolo nella carta ci sarebbero
             venticinque `giro` identici, e la pagina della formazione — che
             clona le carte sul campo — ne farebbe altri undici. Qui è uno solo,
             e la geometria è la stessa per tutti perché il verso è identico
             su ogni carta per definizione. --}}
        <path id="fb-giro" d="M50,50 m-31,0 a31,31 0 1,1 62,0 a31,31 0 1,1 -62,0" />
        <symbol id="sim-stella" viewBox="0 0 100 100">
            <path d="M50,8 L61,38 L93,38 L67,57 L77,88 L50,69 L23,88 L33,57 L7,38 L39,38 Z"/>
        </symbol>

        <symbol id="sim-fulmine" viewBox="0 0 100 100">
            <path d="M58,6 L26,55 L45,55 L38,94 L74,42 L54,42 Z"/>
        </symbol>

        <symbol id="sim-corona" viewBox="0 0 100 100">
            <path d="M14,72 L8,26 L29,45 L50,14 L71,45 L92,26 L86,72 Z"/>
            <rect x="14" y="78" width="72" height="10"/>
        </symbol>

        <symbol id="sim-pallone" viewBox="0 0 100 100">
            <circle cx="50" cy="50" r="42"/>
            <path d="M50,22 L67,35 L61,56 L39,56 L33,35 Z" fill="#fff" fill-opacity=".85"/>
        </symbol>

        <symbol id="sim-fiamma" viewBox="0 0 100 100">
            <path d="M50,6 Q66,30 62,44 Q74,38 74,26 Q90,48 84,66 Q76,92 50,94
                     Q24,92 16,66 Q10,48 26,26 Q26,38 38,44 Q34,30 50,6 Z"/>
        </symbol>

        <symbol id="sim-ala" viewBox="0 0 100 100">
            <path d="M6,28 L52,40 L14,44 L58,56 L24,60 L64,72 L34,76 L94,88 L92,66
                     Q78,34 44,20 Z"/>
        </symbol>

        <symbol id="sim-artiglio" viewBox="0 0 100 100">
            <path d="M18,8 Q34,42 28,90 L40,90 Q44,44 30,8 Z"/>
            <path d="M44,4 Q58,42 50,94 L62,94 Q68,42 56,4 Z"/>
            <path d="M70,10 Q84,44 76,88 L88,88 Q92,44 82,10 Z"/>
        </symbol>

        <symbol id="sim-torre" viewBox="0 0 100 100">
            <path d="M16,20 H30 V30 H42 V20 H58 V30 H70 V20 H84 V44 H76 V88 H24 V44 H16 Z"/>
            <rect x="42" y="58" width="16" height="30" fill="#000" fill-opacity=".35"/>
        </symbol>
    </defs>
</svg>
