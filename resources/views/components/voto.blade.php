@props(['valore' => null, 'ufficio' => null])

{{--
    Un fantavoto, con la sua tinta.

    ⚠️ La regola sta qui e in un posto solo. Era scritta a mano nella giornata
    di Serie A e da nessun'altra parte, quindi il tabellino della sfida mostrava
    gli stessi numeri in grigio: due pagine che raccontano lo stesso weekend con
    due linguaggi diversi, e chi le confronta pensa che una delle due sbagli.

    Le soglie sono quelle con cui si legge una pagella: dal 7 in su è una buona
    giornata, sotto il 5,5 è un'insufficienza. In mezzo non serve colore — se
    tutto è colorato, niente lo è.
--}}
@php
    $tinta = match (true) {
        $valore === null => 'text-paper-dim',
        $valore >= 7 => 'text-ruolo-d',
        $valore < 5.5 => 'text-rosso',
        default => '',
    };
@endphp

<span {{ $attributes->merge(['class' => 'font-mono font-bold tabular-nums '.$tinta]) }}>
    @if ($valore !== null)
        {{ number_format($valore, 1, ',', '') }}
    @elseif ($ufficio !== null)
        {{-- Senza voto e senza sostituto: il valore d'ufficio va mostrato,
             altrimenti il totale in fondo non torna con le righe sopra. --}}
        {{ number_format($ufficio, 1, ',', '') }}
    @else
        sv
    @endif
</span>
