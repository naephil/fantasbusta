<x-layouts.app title="Classifica — Fantasbusta">
    <header class="mb-6 sm:mb-8">
        <p class="eyebrow mb-2 text-rosso">Classifica · giornata {{ $matchday ?? '—' }}</p>
        <h1 class="font-display text-4xl font-black uppercase leading-none tracking-tight sm:text-5xl">
            Come siamo messi
        </h1>
    </header>

    @if ($giornate->isNotEmpty())
        {{-- Scorre invece di andare a capo: su un telefono trentotto pastiglie
             occuperebbero mezza schermata prima ancora della classifica. --}}
        <div class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:mb-8 sm:px-0">
            <div class="flex w-max gap-1.5">
                @foreach ($giornate as $g)
                    <a href="{{ route('standings.index', ['giornata' => $g]) }}"
                       class="shrink-0 border px-3 py-1.5 font-mono text-xs transition
                              {{ (int) $g === (int) $matchday ? 'border-rosso bg-rosso text-paper' : 'border-ink-4 text-paper-dim hover:border-paper-dim hover:text-paper' }}">
                        {{ $g }}ª
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($classifica->isEmpty())
        <p class="border border-ink-4 bg-ink-2 px-6 py-12 text-center text-paper-dim">
            Nessuna giornata ancora conclusa. La classifica compare dopo il primo
            <code class="font-mono text-paper">score:matchday</code>.
        </p>
    @else
        {{-- ── Classifica ──

             ⚠️ Niente `<table>` e niente scorrimento laterale.

             Era una tabella con larghezza minima fissa dentro un contenitore
             che scorreva: su un telefono si vedevano posizione e mezzo nome, e
             i punti — cioè la ragione per cui si apre questa pagina —
             bisognava andarli a cercare trascinando di lato. Una classifica che
             non mostra i punti non è una classifica.

             In griglia le stesse quattro colonne si ricompongono: su schermo
             stretto il nome prende tutta la riga e i numeri stanno sotto,
             allineati; da sm in su tornano in fila come prima. --}}
        <section class="mb-10 sm:mb-12">
            <div class="border border-ink-4 bg-ink-2">
                <div class="hidden border-b border-ink-4/60 px-3 py-3 sm:grid sm:grid-cols-[2.5rem_1fr_4rem_6rem] sm:gap-3">
                    <span class="eyebrow text-paper-dim">#</span>
                    <span class="eyebrow text-paper-dim">Squadra</span>
                    <span class="eyebrow text-right text-paper-dim">Punti</span>
                    <span class="eyebrow text-right text-paper-dim">Fantapunti</span>
                </div>

                <div class="divide-y divide-ink-4/40">
                    @foreach ($classifica as $riga)
                        <div class="grid grid-cols-[2.25rem_1fr_auto] items-center gap-x-2 gap-y-1 px-3 py-2.5
                                    sm:grid-cols-[2.5rem_1fr_4rem_6rem] sm:gap-3
                                    {{ $riga->manager_id === auth()->id() ? 'bg-rosso/10' : '' }}">
                            <span class="text-center font-display text-2xl font-black
                                         {{ $riga->posizione === 1 ? 'text-rosso' : 'text-paper-dim' }}">
                                {{ $riga->posizione }}
                            </span>

                            <span class="min-w-0">
                                <x-squadra-identita :manager="$riga->manager" :dimensione="30" />
                            </span>

                            {{-- Su schermo stretto i due numeri stanno insieme a
                                 destra, coi punti in evidenza e i fantapunti
                                 sotto: sono la stessa informazione, e separarli
                                 in due colonne strette li spezzerebbe. --}}
                            <span class="text-right sm:hidden">
                                <span class="block font-mono text-lg font-bold leading-none">{{ $riga->punti }}</span>
                                <span class="block font-mono text-[11px] text-paper-dim">
                                    {{ number_format($riga->fantapunti, 1, ',', '') }}
                                </span>
                            </span>

                            <span class="hidden text-right font-mono text-lg font-bold sm:block">{{ $riga->punti }}</span>
                            <span class="hidden text-right font-mono text-sm text-paper-dim sm:block">
                                {{ number_format($riga->fantapunti, 1, ',', '') }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>

            <p class="mt-3 max-w-2xl text-xs leading-relaxed text-paper-dim">
                @php $punti = $settings->puntiSfida(); @endphp
                Vittoria {{ $punti['vittoria'] }} punti, pareggio {{ $punti['pareggio'] }},
                sconfitta {{ $punti['sconfitta'] }}.
                @if ($settings->golAttivi())
                    I fantapunti diventano gol: il primo a
                    {{ number_format($settings->primaSogliaGol(), 0, ',', '') }}, poi uno ogni
                    {{ number_format($settings->passoGol(), 0, ',', '') }}. Sotto la soglia, zero —
                    quindi una giornata storta di entrambi finisce 0–0.
                @else
                    Sotto i {{ number_format($settings->sogliaPareggio(), 1, ',', '') }} fantapunti
                    di scarto la sfida è pari: non deve deciderla mezzo punto di arrotondamento.
                @endif
                A parità di punti contano i fantapunti totali.
            </p>
        </section>

        {{-- ── Sfide della giornata ──
             Solo il campionato: tutto il resto — coppe comprese — sta nei
             risultati, che è la pagina fatta per quello. --}}
        @if ($sfide->isNotEmpty())
            <section>
                <div class="mb-4 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <p class="eyebrow text-paper-dim">Le sfide della {{ $matchday }}ª</p>
                    <a href="{{ route('risultati.index', ['giornata' => $matchday]) }}"
                       class="font-mono text-[11px] text-rosso underline-offset-4 hover:underline">
                        anche i tornei
                    </a>
                </div>

                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ($sfide as $sfida)
                        <x-sfida :sfida="$sfida" :io="auth()->id()" />
                    @endforeach
                </div>
            </section>
        @endif
    @endif
</x-layouts.app>
