<x-layouts.app title="Tornei — Fantasbusta">
    <header class="mb-8 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="eyebrow mb-2 text-rosso">Competizioni</p>
            <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">Tornei</h1>
            <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
                Corrono in parallelo al campionato e usano gli stessi punteggi di giornata.
                Una coppa non muove la classifica di lega, e viceversa.
            </p>
        </div>

        @if (auth()->user()->is_admin)
            <a href="{{ route('tornei.create') }}"
               class="bg-rosso px-6 py-3 font-display text-xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                Nuovo torneo
            </a>
        @endif
    </header>

    @error('torneo')
        <p class="mb-6 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    @forelse ($tornei as $torneo)
        <a href="{{ route('tornei.show', $torneo) }}"
           class="mb-3 block border border-ink-4 bg-ink-2 p-4 transition hover:border-rosso">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span class="font-display text-2xl font-bold uppercase leading-none">{{ $torneo->name }}</span>

                <span class="font-mono text-[10px] tracking-[0.2em] uppercase
                             {{ ['bozza' => 'text-paper-dim', 'in_corso' => 'text-rosso', 'concluso' => 'text-ruolo-d'][$torneo->state] }}">
                    {{ str_replace('_', ' ', $torneo->state) }}
                </span>

                <span class="ml-auto font-mono text-[11px] text-paper-dim">
                    {{ $torneo->formato()->nome() }} · {{ $torneo->entries_count }} squadre · dalla {{ $torneo->start_matchday }}ª
                </span>
            </div>

            @if ($torneo->winner)
                <p class="mt-3 border-t border-ink-4/50 pt-2 font-mono text-[11px] tracking-wide text-ruolo-d">
                    🏆 {{ $torneo->winner->name }}
                </p>
            @endif
        </a>
    @empty
        <p class="border border-ink-4 bg-ink-2 px-6 py-12 text-center text-paper-dim">
            Nessun torneo. @if (auth()->user()->is_admin) Creane uno. @else Li crea l'admin. @endif
        </p>
    @endforelse
</x-layouts.app>
