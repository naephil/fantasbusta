<x-layouts.app title="Giornata — Fantasbusta">
    <header class="mb-6 sm:mb-8">
        <p class="eyebrow mb-2 text-rosso">Serie A · giornata {{ $matchday ?? '—' }}</p>

        <h1 class="font-display text-4xl font-black uppercase leading-none tracking-tight sm:text-5xl">
            Il campo
        </h1>

        <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Le partite vere, con accanto a ogni giocatore il suo fantavoto e di chi è la carta.
            Le <span class="text-rosso">tue</span> sono in evidenza.
        </p>
    </header>

    @if ($giornate->isNotEmpty())
        <div class="-mx-4 mb-6 overflow-x-auto px-4 sm:mx-0 sm:mb-8 sm:px-0">
            <div class="flex w-max gap-1.5">
                @foreach ($giornate as $g)
                    <a href="{{ route('matchday.show', ['giornata' => $g]) }}"
                       class="shrink-0 border px-3 py-1.5 font-mono text-xs transition
                              {{ (int) $g === (int) $matchday ? 'border-rosso bg-rosso text-paper' : 'border-ink-4 text-paper-dim hover:border-paper-dim hover:text-paper' }}">
                        {{ $g }}ª
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($partite->isEmpty())
        <p class="border border-ink-4 bg-ink-2 px-6 py-12 text-center text-paper-dim">
            Nessuna statistica per questa giornata. Servono <code class="font-mono text-paper">sync:stats</code>
            oppure <code class="font-mono text-paper">simula:giornata</code>.
        </p>
    @else
        <div class="space-y-6">
            @foreach ($partite as $partita)
                @php
                    $f = $partita['fixture'];

                    /*
                     * I marcatori, per lato.
                     *
                     * ⚠️ Stanno nell'intestazione e non dentro i dettagli: sono
                     * la sola cosa che di una partita si vuole sapere subito, e
                     * finché il tabellino era sempre aperto si perdevano in
                     * mezzo a cinquanta righe di voti. Adesso che i dettagli si
                     * chiudono, senza di loro una partita chiusa direbbe solo
                     * «2:1» — cioè il risultato che si sapeva già.
                     *
                     * Rigori compresi, e contati: un giocatore con due gol si
                     * scrive una volta sola con accanto il numero, come in ogni
                     * tabellone di calcio.
                     */
                    $marcatori = fn ($lato) => collect($lato)
                        ->map(fn (array $g) => [
                            'nome' => $g['nome'],
                            'carta' => $g['carta'],
                            'quanti' => $g['eventi']['gol'] + $g['eventi']['rigore_segnato'],
                        ])
                        ->filter(fn (array $g) => $g['quanti'] > 0)
                        ->values();

                    $golCasa = $marcatori($partita['casa']);
                    $golFuori = $marcatori($partita['fuori']);
                @endphp

                <article class="border border-ink-4 bg-ink-2">
                    {{-- ── Il tabellone, sempre visibile ── --}}
                    <div class="border-b border-ink-4/60 px-3 py-3 sm:px-4">
                        <div class="flex items-center gap-2 sm:gap-4">
                            <span class="min-w-0 flex-1 text-right font-display text-lg font-bold uppercase leading-tight sm:text-2xl">
                                {{ $f->homeTeam?->name ?? '—' }}
                            </span>

                            <span class="shrink-0 font-mono text-lg font-bold sm:text-xl">
                                {{ $f->home_goals ?? '–' }}<span class="mx-1 text-paper-dim">:</span>{{ $f->away_goals ?? '–' }}
                            </span>

                            <span class="min-w-0 flex-1 font-display text-lg font-bold uppercase leading-tight sm:text-2xl">
                                {{ $f->awayTeam?->name ?? '—' }}
                            </span>
                        </div>

                        {{-- I marcatori sotto la propria squadra, sinistra e destra. --}}
                        @if ($golCasa->isNotEmpty() || $golFuori->isNotEmpty())
                            <div class="mt-2 flex items-start gap-2 text-[11px] leading-snug text-paper-dim sm:gap-4 sm:text-xs">
                                @foreach ([[$golCasa, 'text-right'], [$golFuori, '']] as [$gol, $allineamento])
                                    <span class="min-w-0 flex-1 {{ $allineamento }}">
                                        @foreach ($gol as $g)
                                            <span class="block truncate">
                                                <span class="text-paper-dim/70">⚽</span>
                                                <x-nome-giocatore :carta="$g['carta']">{{ $g['nome'] }}</x-nome-giocatore>@if ($g['quanti'] > 1)<span class="font-mono"> ×{{ $g['quanti'] }}</span>@endif
                                            </span>
                                        @endforeach
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <p class="mt-2 text-center font-mono text-[10px] tracking-[0.2em] uppercase
                                  {{ $f->status === 'live' ? 'text-rosso' : 'text-paper-dim' }}">
                            {{ $f->kickoff_at?->format('d/m H:i') }} ·
                            {{ ['scheduled' => 'da giocare', 'live' => 'in corso', 'finished' => 'finita'][$f->status] ?? $f->status }}
                        </p>
                    </div>

                    {{-- ── I voti, richiudibili ──

                         Dieci partite × una cinquantina di righe fanno cinquecento
                         nomi in una pagina sola: sul telefono si scorreva per
                         minuti senza mai vedere due partite insieme. Chiusi di
                         default si guarda la giornata; aperti si guarda la
                         partita, che è una cosa che si fa una alla volta. --}}
                    <details class="group">
                        <summary class="flex cursor-pointer items-center justify-center gap-2 px-4 py-2 font-mono text-[10px] uppercase tracking-[0.2em] text-paper-dim transition hover:text-paper">
                            <span class="group-open:hidden">Voti e tabellino</span>
                            <span class="hidden group-open:inline">Chiudi il tabellino</span>
                            <svg viewBox="0 0 10 6" class="h-1.5 w-2.5 transition group-open:rotate-180" aria-hidden="true">
                                <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" />
                            </svg>
                        </summary>

                    {{-- Le due rose --}}
                    <div class="grid border-t border-ink-4/60 md:grid-cols-2 md:divide-x md:divide-ink-4/60">
                        @foreach ([$partita['casa'], $partita['fuori']] as $lato)
                            <div class="divide-y divide-ink-4/40">
                                @forelse ($lato as $g)
                                    <div class="flex items-center gap-2.5 px-3 py-1.5 text-sm
                                                {{ $g['mio'] ? 'bg-rosso/10' : '' }}
                                                {{ $g['minuti'] === 0 ? 'opacity-40' : '' }}">

                                        <span class="w-4 shrink-0 text-center font-display font-black"
                                              style="color: var(--r-{{ $g['ruolo'] }})">{{ $g['ruolo'] }}</span>

                                        <span class="min-w-0 flex-1 truncate">
                                            <x-nome-giocatore :carta="$g['carta']">{{ $g['nome'] }}</x-nome-giocatore>
                                            <x-segnalini :eventi="$g['eventi']" :ruolo="$g['ruolo']" class="ml-1" />
                                        </span>

                                        @if ($g['proprietario'])
                                            <span class="shrink-0 font-mono text-[10px] tracking-wide
                                                         {{ $g['mio'] ? 'text-rosso' : 'text-paper-dim' }}">
                                                {{ $g['mio'] ? 'TU' : $g['proprietario'] }}
                                            </span>
                                        @endif

                                        <span class="w-8 shrink-0 text-right font-mono text-[11px] text-paper-dim">
                                            {{ $g['minuti'] > 0 ? $g['minuti']."'" : '—' }}
                                        </span>

                                        <x-voto :valore="$g['fantavoto']" class="w-10 shrink-0 text-right text-sm" />
                                    </div>
                                @empty
                                    <p class="px-3 py-4 text-sm text-paper-dim">Nessuna statistica.</p>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                    </details>
                </article>
            @endforeach
        </div>
    @endif
</x-layouts.app>
