@props(['sfida', 'io' => null])

@php
    $mia = in_array($io, [$sfida->home_manager_id, $sfida->away_manager_id], true);
    $giocata = $sfida->state === 'played';
@endphp

{{--
    Una sfida, come si legge ovunque compaia.

    Sta in un componente perché i posti che la mostrano sono due — i risultati e
    la classifica — e due copie dello stesso riquadro divergono al primo
    ritocco: una col punteggio giusto e una con quello di sei mesi fa.

    ⚠️ Il punteggio DI CLASSIFICA non compare più. C'era, scritto «3 – 0 in
    classifica» accanto ai fantapunti, e non voleva dire niente di leggibile:
    non è un risultato della sfida, è quanto quella sfida ha fruttato in
    graduatoria — un'informazione che ha senso nella colonna «punti» della
    classifica, dove infatti c'è già. Messo qui sembrava un secondo punteggio
    della stessa partita, e faceva chiedere quale dei due fosse quello vero.
--}}
<a href="{{ route('matchup.show', $sfida) }}"
   {{ $attributes->merge(['class' => 'block border p-3 transition hover:border-rosso sm:p-4'])
        ->class([$mia ? 'border-rosso bg-rosso/5' : 'border-ink-4 bg-ink-2']) }}>

    <div class="flex items-center gap-2 sm:gap-3">
        <x-squadra-identita :manager="$sfida->home" :dimensione="32"
                            :con-allenatore="false" con-maglia
                            class="min-w-0 flex-1 justify-end text-right" />

        <span class="shrink-0 text-center font-mono text-base font-bold sm:text-lg">
            @if ($giocata && $sfida->aGol())
                {{-- A gol il risultato è il titolo, i fantapunti la riga sotto:
                     «2 – 1» si legge a colpo d'occhio, «74,5 – 71,0» no. --}}
                {{ $sfida->home_goals }}<span class="mx-1 text-paper-dim">–</span>{{ $sfida->away_goals }}
            @elseif ($giocata)
                {{ number_format($sfida->home_points, 1, ',', '') }}<span class="mx-1 text-paper-dim">–</span>{{ number_format($sfida->away_points, 1, ',', '') }}
            @else
                <span class="text-paper-dim">vs</span>
            @endif
        </span>

        <x-squadra-identita :manager="$sfida->away" :dimensione="32"
                            :con-allenatore="false" con-maglia
                            class="min-w-0 flex-1" />
    </div>

    @if ($giocata)
        <p class="mt-2.5 border-t border-ink-4/50 pt-2 text-center font-mono text-[10px] tracking-[0.16em] uppercase text-paper-dim sm:mt-3">
            @if ($sfida->aGol())
                {{ number_format($sfida->home_points, 1, ',', '') }} –
                {{ number_format($sfida->away_points, 1, ',', '') }} fantapunti
                @if ($sfida->home_goals === $sfida->away_goals) · pari @endif
            @else
                {{ $sfida->home_points === $sfida->away_points ? 'pari' : 'fantapunti' }}
            @endif
            <span class="text-rosso">· tabellino</span>
        </p>
    @endif
</a>
