<x-layouts.app title="Risultati — Fantasbusta">
    <header class="mb-6 sm:mb-8">
        <p class="eyebrow mb-2 text-rosso">Risultati · giornata {{ $matchday ?? '—' }}</p>

        <h1 class="font-display text-4xl font-black uppercase leading-none tracking-tight sm:text-5xl">
            Com'è andata
        </h1>

        <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Tutto quello che si è giocato in una giornata, campionato e tornei insieme.
            Prima stavano in tre posti diversi — in fondo alla classifica, e uno per tabellone —
            e per sapere com'era finito il weekend bisognava aprirli tutti.
        </p>
    </header>

    @if ($giornate->isNotEmpty())
        {{-- La fila delle giornate scorre invece di andare a capo: su un telefono
             trentotto pastiglie occuperebbero mezza schermata prima ancora dei
             risultati. --}}
        <div class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:mb-8 sm:px-0">
            <div class="flex w-max gap-1.5">
                @foreach ($giornate as $g)
                    <a href="{{ route('risultati.index', ['giornata' => $g]) }}"
                       class="shrink-0 border px-3 py-1.5 font-mono text-xs transition
                              {{ (int) $g === (int) $matchday ? 'border-rosso bg-rosso text-paper' : 'border-ink-4 text-paper-dim hover:border-paper-dim hover:text-paper' }}">
                        {{ $g }}ª
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($gruppi->isEmpty())
        <p class="border border-ink-4 bg-ink-2 px-6 py-12 text-center text-paper-dim">
            Nessuna sfida in calendario. Compaiono quando la stagione ha un calendario
            e almeno una giornata è stata chiusa.
        </p>
    @else
        @foreach ($gruppi as $gruppo)
            <section class="mb-8 sm:mb-10">
                <div class="mb-3 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <p class="eyebrow text-paper-dim">{{ $gruppo['nome'] }}</p>

                    @if ($gruppo['torneo'])
                        <a href="{{ route('tornei.show', $gruppo['torneo']) }}"
                           class="font-mono text-[11px] text-rosso underline-offset-4 hover:underline">
                            tabellone
                        </a>
                    @endif
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($gruppo['sfide'] as $sfida)
                        <x-sfida :sfida="$sfida" :io="auth()->id()" />
                    @endforeach
                </div>
            </section>
        @endforeach

        <p class="max-w-2xl text-xs leading-relaxed text-paper-dim">
            @if ($settings->golAttivi())
                I fantapunti diventano gol: il primo a
                {{ number_format($settings->primaSogliaGol(), 0, ',', '') }}, poi uno ogni
                {{ number_format($settings->passoGol(), 0, ',', '') }}. Sotto la soglia, zero —
                quindi una giornata storta di entrambi finisce 0–0.
            @else
                Sotto i {{ number_format($settings->sogliaPareggio(), 1, ',', '') }} fantapunti
                di scarto la sfida è pari: non deve deciderla mezzo punto di arrotondamento.
            @endif
            Quanto ha fruttato in graduatoria si legge nella
            <a href="{{ route('standings.index') }}" class="text-rosso underline-offset-4 hover:underline">classifica</a>.
        </p>
    @endif
</x-layouts.app>
