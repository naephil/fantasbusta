<x-layouts.app title="Fantasbusta">
    <header class="mb-10">
        <p class="eyebrow mb-2 text-rosso">Ciao {{ auth()->user()->name }}</p>

        <h1 class="font-display text-6xl font-black uppercase leading-[0.85] tracking-tight">
            Fanta<span class="text-rosso">busta</span>
        </h1>

        @if ($stagione)
            <p class="mt-4 font-mono text-[11px] uppercase tracking-[0.18em] text-paper-dim">
                {{ $stagione->league->name }} · {{ $stagione->etichetta() }} ·
                {{ str_replace('_', ' ', $stagione->state) }}
            </p>
        @endif
    </header>

    @if (! $stagione)
        <div class="border border-ink-4 bg-ink-2 p-6">
            <p class="font-display text-3xl font-black uppercase leading-none">Nessuna stagione aperta</p>
            <p class="mt-3 max-w-xl text-sm leading-relaxed text-paper-dim">
                @if (auth()->user()->is_admin)
                    Carica un'annata di Serie A, importane il listone e crea una stagione dalla
                    <a href="{{ route('admin.gestione') }}" class="text-rosso underline-offset-4 hover:underline">pagina di gestione</a>.
                @else
                    Chiedi all'amministratore del gruppo di aprirne una.
                @endif
            </p>
        </div>
    @elseif (auth()->user()->is_admin && $daRivedere > 0)
        <a href="{{ route('listone.index', ['anno' => $stagione->season]) }}"
           class="block border border-rosso bg-ink-2 p-6 transition hover:bg-ink-3">
            <p class="eyebrow mb-2 text-rosso">Da fare</p>
            <p class="font-display text-3xl font-black uppercase leading-none">
                {{ $daRivedere }} giocatori aspettano una decisione
            </p>
            <p class="mt-3 max-w-xl text-sm leading-relaxed text-paper-dim">
                Ruolo ipotizzato dall'API, oppure identità mai verificata a occhio.
                Il listone {{ $stagione->etichetta() }} va sistemato prima che si cominci.
            </p>
        </a>
    @endif
</x-layouts.app>
