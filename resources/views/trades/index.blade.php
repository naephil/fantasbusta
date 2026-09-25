<x-layouts.app title="Mercato — Fantasbusta">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Mercato · giornata {{ $matchday }}</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">Scambi</h1>
        <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Sette carte per una si può. L'unico limite è che nessuno dei due resti inschierabile,
            e il controllo scatta all'accettazione: fra la proposta e la risposta la controparte
            può aver concluso altri scambi.
        </p>
    </header>

    @error('scambio')
        <p class="mb-6 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    {{-- ── Il mercato non è ancora aperto ──

         §4 dice da sempre che il trading apre a draft concluso, ma la pagina
         non lo diceva: si sceglievano le carte e ogni proposta veniva respinta
         dal pavimento delle undici, perché a metà draft una rosa di undici
         carte non esiste ancora per definizione. Il messaggio parlava di rose
         troppo corte — la conseguenza — e il motivo vero non compariva da
         nessuna parte. --}}
    @if ($draftInCorso)
        <p class="mb-8 border border-rosso bg-ink-2 px-5 py-4 text-sm leading-relaxed">
            <span class="eyebrow mb-1 block text-rosso">Mercato chiuso</span>
            Il draft della {{ $matchday }}ª non è ancora finito. Si scambia quando tutti hanno
            pescato: prima di allora le rose non sono complete, e nessuna proposta potrebbe
            essere accettata senza lasciare qualcuno inschierabile.
        </p>
    @endif

    {{-- ── Proposte ricevute ── --}}
    <section class="mb-10">
        <p class="eyebrow mb-3 text-paper-dim">Ricevute · {{ $ricevute->count() }}</p>

        @forelse ($ricevute as $trade)
            <x-scambio :trade="$trade" :io="auth()->id()" class="mb-3">
                <form method="POST" action="{{ route('trades.accept', $trade) }}" class="inline">
                    @csrf
                    <button class="bg-rosso px-4 py-2 font-display text-base font-black uppercase tracking-wide text-paper transition hover:bg-rosso-vivo">
                        Accetta
                    </button>
                </form>
                <form method="POST" action="{{ route('trades.reject', $trade) }}" class="inline">
                    @csrf
                    <button class="border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-paper-dim hover:text-paper">
                        Rifiuta
                    </button>
                </form>
            </x-scambio>
        @empty
            <p class="border border-ink-4 bg-ink-2 px-4 py-6 text-sm text-paper-dim">Nessuna proposta in arrivo.</p>
        @endforelse
    </section>

    {{-- ── Proposte inviate ── --}}
    @if ($inviate->isNotEmpty())
        <section class="mb-10">
            <p class="eyebrow mb-3 text-paper-dim">Inviate · {{ $inviate->count() }}</p>

            @foreach ($inviate as $trade)
                <x-scambio :trade="$trade" :io="auth()->id()" class="mb-3">
                    <form method="POST" action="{{ route('trades.cancel', $trade) }}" class="inline">
                        @csrf
                        <button class="border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-paper-dim hover:text-paper">
                            Ritira
                        </button>
                    </form>
                </x-scambio>
            @endforeach
        </section>
    @endif

    {{-- ── Con chi trattare ──
         Sparisce a draft aperto: un elenco di avversari cliccabili è un invito
         a fare una cosa che non si può ancora fare. Il feed sotto resta, che è
         lettura e non azione. --}}
    <section class="mb-10 {{ $draftInCorso ? 'hidden' : '' }}">
        <p class="eyebrow mb-3 text-paper-dim">Proponi uno scambio</p>

        <div class="flex flex-wrap gap-2">
            @foreach ($avversari as $avversario)
                <a href="{{ route('trades.create', $avversario) }}"
                   class="flex items-center gap-2.5 border border-ink-4 py-2 pl-2 pr-4 text-sm transition hover:border-rosso hover:text-paper">
                    <x-stemma :stemma="$avversario->crest ?? []" :file="$avversario->crest_path"
                              :iniziali="$avversario->initials()" :dimensione="28" class="shrink-0" />
                    <span>
                        {{ $avversario->name }}
                        <span class="block font-mono text-[10px] text-paper-dim">{{ $avversario->cards_count }} carte</span>
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    {{-- ── Feed pubblico ── --}}
    <section>
        <p class="eyebrow mb-3 text-paper-dim">Cos'è successo</p>
        <p class="mb-4 max-w-xl text-xs leading-relaxed text-paper-dim">
            Ogni scambio è visibile a tutta la lega. Col sette-per-uno permesso il rischio non è
            l'equilibrio ma la collusione, e la trasparenza è il rimedio proporzionato.
        </p>

        @forelse ($feed as $trade)
            <x-scambio :trade="$trade" :io="auth()->id()" class="mb-2" :storico="true" />
        @empty
            <p class="border border-ink-4 bg-ink-2 px-4 py-6 text-sm text-paper-dim">Ancora nessuno scambio.</p>
        @endforelse
    </section>
</x-layouts.app>
