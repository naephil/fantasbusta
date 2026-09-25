@props(['trade', 'io', 'storico' => false])

@php
    $offerte = $trade->items->where('direction', 'offered');
    $richieste = $trade->items->where('direction', 'requested');
@endphp

<article {{ $attributes->merge(['class' => 'border border-ink-4 bg-ink-2 p-4']) }}>
    <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-2">
        <x-squadra-identita :manager="$trade->proposer" :dimensione="26" :con-allenatore="false" />
        <span class="font-mono text-paper-dim">→</span>
        <x-squadra-identita :manager="$trade->receiver" :dimensione="26" :con-allenatore="false" />

        @if ($storico)
            <span class="font-mono text-[10px] tracking-wide
                {{ $trade->state === 'accepted' ? 'text-ruolo-d' : 'text-rosso' }}">
                {{ $trade->state === 'accepted' ? 'concluso' : 'rifiutato' }}
                @if ($trade->reject_reason)
                    · {{ $trade->reject_reason }}
                @endif
            </span>
        @endif
    </div>

    {{-- ⚠️ I nomi non si uniscono in una stringa sola.

         Sembrava innocuo — «Lautaro, Bastoni» si legge benissimo — ma è proprio
         qui che si valuta se uno scambio conviene, e per deciderlo bisogna
         sapere CHE carte sono: tier, power, fantamedia. Fusi in un testo unico
         non c'era niente a cui appendere l'anteprima, e l'unico modo di
         guardarle era cercarsele in rosa una per una. Adesso ogni nome è il
         proprio, e mostra la propria carta. --}}
    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ([
            [$trade->proposer_id === $io ? 'Cedi' : 'Ricevi', $offerte],
            [$trade->proposer_id === $io ? 'Ricevi' : 'Cedi', $richieste],
        ] as [$titolo, $items])
            <div>
                <p class="eyebrow mb-1 text-paper-dim">{{ $titolo }}</p>

                <p class="text-sm leading-relaxed">
                    @forelse ($items as $item)
                        <x-nome-giocatore :carta="$item->card_id">{{ $item->card->player->last_name }}</x-nome-giocatore>@if (! $loop->last),@endif
                    @empty
                        —
                    @endforelse
                </p>
            </div>
        @endforeach
    </div>

    @if (isset($slot) && trim($slot))
        <div class="mt-4 flex flex-wrap gap-2">{{ $slot }}</div>
    @endif
</article>
