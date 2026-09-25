{{--
    L'elenco delle sfide di un torneo, condiviso fra i formati.

    ⚠️ Sta in «components/» e non accanto ai formati che lo usano: Blade cerca
    i componenti anonimi SOLO lì. Tenuto in views/tornei/formati/ si compilava
    senza protestare e falliva a schermo con «Unable to locate a class or view
    for component», cioè un 500 sulla pagina di ogni torneo a gironi.
--}}
@props(['sfide', 'titolo' => null])

@if ($titolo)
    <p class="eyebrow mb-3 text-paper-dim">{{ $titolo }}</p>
@endif

<div class="mb-6 grid gap-2 md:grid-cols-2">
    @foreach ($sfide as $sfida)
        @php $giocata = $sfida->state === 'played'; @endphp
        <article class="border border-ink-4 bg-ink-2 p-3">
            <div class="flex items-center gap-3">
                <x-squadra-identita :manager="$sfida->home" :dimensione="30" :con-allenatore="false"
                                    class="flex-1 justify-end text-right" />
                <span class="shrink-0 font-mono text-sm font-bold">
                    @if ($giocata)
                        {{ number_format($sfida->home_points, 1, ',', '') }}<span class="mx-1 text-paper-dim">–</span>{{ number_format($sfida->away_points, 1, ',', '') }}
                    @else
                        <span class="text-paper-dim">vs</span>
                    @endif
                </span>
                <x-squadra-identita :manager="$sfida->away" :dimensione="30" :con-allenatore="false" class="flex-1" />
            </div>
            <p class="mt-2 text-center font-mono text-[10px] tracking-[0.18em] uppercase text-paper-dim">
                {{ $sfida->matchday }}ª giornata @if ($sfida->stage) · {{ $sfida->stage }} @endif
            </p>
        </article>
    @endforeach
</div>
