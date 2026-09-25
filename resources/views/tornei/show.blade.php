<x-layouts.app :title="$torneo->name.' — Fantasbusta'">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">{{ $formato->nome() }}</p>

        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            {{ $torneo->name }}
        </h1>

        <p class="mt-4 font-mono text-[11px] tracking-[0.2em] uppercase text-paper-dim">
            {{ $torneo->entries->count() }} squadre ·
            dalla {{ $torneo->start_matchday }}ª alla {{ $torneo->ultimaGiornata() }}ª ·
            <span class="{{ ['bozza' => '', 'in_corso' => 'text-rosso', 'concluso' => 'text-ruolo-d'][$torneo->state] }}">
                {{ str_replace('_', ' ', $torneo->state) }}
            </span>
        </p>

        @if ($torneo->winner)
            <p class="mt-4 border-l-2 border-ruolo-d bg-ink-2 px-4 py-3">
                <span class="eyebrow block text-ruolo-d">Vincitore</span>
                <span class="mt-1 block font-display text-3xl font-black uppercase leading-none">
                    🏆 {{ $torneo->winner->name }}
                </span>
            </p>
        @endif
    </header>

    @error('torneo')
        <p class="mb-6 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    {{-- ── Bozza: partecipanti e avvio ── --}}
    @if ($torneo->state === 'bozza')
        <section class="mb-8">
            <p class="mb-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
                {{ $formato->descrizione() }}
                Il torneo non è ancora partito: gli accoppiamenti si generano all'avvio.
            </p>

            <p class="eyebrow mb-3 text-paper-dim">Teste di serie</p>

            <div class="mb-6 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($torneo->entries->sortBy('seed') as $entry)
                    <div class="flex items-center gap-3 border border-ink-4 bg-ink-2 px-3 py-2">
                        <span class="w-6 shrink-0 text-center font-display text-lg font-black text-paper-dim">
                            {{ $entry->seed }}
                        </span>
                        <x-squadra-identita :manager="$entry->manager" :dimensione="28" :con-allenatore="false" />
                    </div>
                @endforeach
            </div>

            @if (auth()->user()->is_admin)
                <form method="POST" action="{{ route('tornei.start', $torneo) }}">
                    @csrf
                    <button class="bg-rosso px-6 py-3 font-display text-xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                        Avvia il torneo
                    </button>
                </form>
            @endif
        </section>
    @else
        {{-- ── In corso o concluso: lo stato lo disegna il formato ── --}}
        @include($stato['vista'])
    @endif

    {{-- ── Eliminare, in qualunque stato ──
         Stava dentro il ramo «bozza», e voleva dire che un torneo avviato non
         si poteva più togliere da nessuna parte: un tabellone sbagliato — teste
         di serie invertite, formato che non era quello — restava lì per il
         resto della stagione. La rotta accettava già qualunque stato; a mancare
         era soltanto il pulsante.

         Un torneo si porta via le sue sfide e nient'altro: la classifica di
         campionato non lo ha mai visto passare, ed è la ragione per cui questo
         non è un azzeramento di stagione. --}}
    @if (auth()->user()->is_admin)
        <form method="POST" action="{{ route('tornei.destroy', $torneo) }}"
              class="mt-10 border-t border-ink-4/60 pt-5"
              onsubmit="return confirm('Eliminare «{{ $torneo->name }}» e tutte le sue sfide? La classifica di campionato non cambia.')">
            @csrf
            @method('DELETE')
            <button class="border border-ink-4 px-6 py-3 text-sm text-paper-dim transition hover:border-rosso hover:text-rosso">
                Elimina il torneo
            </button>

            <span class="ml-3 text-sm text-paper-dim">
                Via il tabellone e le sue sfide. Il campionato resta com'è.
            </span>
        </form>
    @endif
</x-layouts.app>
