<section class="mb-10">
    <p class="eyebrow mb-3 text-paper-dim">Classifica del torneo</p>

    <div class="overflow-x-auto border border-ink-4 bg-ink-2">
        <table class="w-full">
            <tbody class="divide-y divide-ink-4/40">
                @foreach ($stato['classifica'] as $i => $riga)
                    <tr class="{{ $riga['manager']->id === auth()->id() ? 'bg-rosso/10' : '' }}">
                        <td class="w-12 px-3 py-2.5 text-center font-display text-xl font-black {{ $i === 0 ? 'text-rosso' : 'text-paper-dim' }}">
                            {{ $i + 1 }}
                        </td>
                        <td class="px-3 py-2.5">
                            <x-squadra-identita :manager="$riga['manager']" :dimensione="30" :con-allenatore="false" />
                        </td>
                        <td class="px-3 py-2.5 text-right font-mono text-lg font-bold">{{ $riga['punti'] }}</td>
                        <td class="px-3 py-2.5 text-right font-mono text-xs text-paper-dim">
                            {{ number_format($riga['fantapunti'], 1, ',', '') }}
                        </td>
                        <td class="px-3 py-2.5 text-right font-mono text-[10px] text-paper-dim">
                            {{ $riga['giocate'] }} gare
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

@foreach ($stato['sfide'] as $matchday => $sfide)
    <x-tornei.sfide :sfide="$sfide" :titolo="$matchday.'ª giornata'" />
@endforeach
