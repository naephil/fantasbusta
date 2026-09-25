@foreach ($stato['turni'] as $round => $sfide)
    <x-tornei.sfide :sfide="$sfide"
        :titolo="'Turno '.$round.($sfide->first()->stage ? ' · '.$sfide->first()->stage : '')" />
@endforeach

<section class="mt-8 grid gap-6 md:grid-cols-2">
    <div>
        <p class="eyebrow mb-3 text-ruolo-d">Ancora in corsa · {{ $stato['superstiti']->count() }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($stato['superstiti'] as $entry)
                <span class="border border-ink-4 bg-ink-2 px-3 py-2">
                    <x-squadra-identita :manager="$entry->manager" :dimensione="24" :con-allenatore="false" />
                </span>
            @endforeach
        </div>
    </div>

    <div>
        <p class="eyebrow mb-3 text-rosso">Fuori · {{ $stato['eliminati']->count() }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($stato['eliminati'] as $entry)
                <span class="border border-ink-4/60 bg-ink-2/50 px-3 py-2 opacity-60">
                    <x-squadra-identita :manager="$entry->manager" :dimensione="24" :con-allenatore="false" />
                </span>
            @endforeach
        </div>
    </div>
</section>
