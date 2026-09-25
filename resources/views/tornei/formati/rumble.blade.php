<section class="grid gap-6 md:grid-cols-2">
    <div>
        <p class="eyebrow mb-3 text-ruolo-d">In gioco · {{ $stato['superstiti']->count() }}</p>
        <div class="space-y-2">
            @foreach ($stato['superstiti'] as $entry)
                <div class="border border-ink-4 bg-ink-2 p-3">
                    <x-squadra-identita :manager="$entry->manager" :dimensione="30" :con-allenatore="false" />
                </div>
            @endforeach
        </div>
    </div>

    <div>
        <p class="eyebrow mb-3 text-rosso">Eliminati · {{ $stato['eliminati']->count() }}</p>
        <div class="space-y-2">
            @forelse ($stato['eliminati'] as $entry)
                <div class="flex items-center gap-3 border border-ink-4/60 bg-ink-2/50 p-3 opacity-60">
                    <x-squadra-identita :manager="$entry->manager" :dimensione="26" :con-allenatore="false" class="flex-1" />
                    <span class="shrink-0 font-mono text-[10px] text-paper-dim">{{ $entry->eliminated_matchday }}ª</span>
                </div>
            @empty
                <p class="border border-ink-4 bg-ink-2 px-4 py-6 text-sm text-paper-dim">Ancora nessuno.</p>
            @endforelse
        </div>
    </div>
</section>
