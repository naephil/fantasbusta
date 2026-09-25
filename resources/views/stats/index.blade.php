<x-layouts.app title="Statistiche — Fantasbusta">
    <header class="mb-10">
        <p class="eyebrow mb-2 text-rosso">Statistiche</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            Chi sale, chi scende, chi capita sempre a chi
        </h1>
    </header>

    {{-- ── Movimento di valutazione ── --}}
    <section class="mb-12">
        <p class="eyebrow mb-1 text-paper-dim">
            Valutazione · giornata {{ $giornataPower ?? '—' }}
        </p>
        <p class="mb-5 max-w-2xl text-xs leading-relaxed text-paper-dim">
            Il movimento si misura in posizioni di classifica, non in punti di power: il power è
            un numero senza unità, mentre «sei posizioni» si capisce e si confronta fra giornate.
        </p>

        @if ($salgono->isEmpty() && $scendono->isEmpty())
            <p class="border border-ink-4 bg-ink-2 px-4 py-6 text-sm text-paper-dim">
                Serve almeno un secondo ricalcolo del power per avere un movimento da mostrare.
            </p>
        @else
            <div class="grid gap-6 md:grid-cols-2">
                @foreach ([['In rialzo', $salgono, 'up'], ['In ribasso', $scendono, 'down']] as [$titolo, $righe, $verso])
                    <div>
                        <p class="mb-2 font-mono text-[11px] tracking-[0.18em] uppercase
                                  {{ $verso === 'up' ? 'text-ruolo-d' : 'text-rosso' }}">{{ $titolo }}</p>

                        <ol class="divide-y divide-ink-4/60 border border-ink-4 bg-ink-2">
                            @foreach ($righe as $riga)
                                <li class="flex items-baseline gap-3 px-3 py-2">
                                    <span class="w-12 shrink-0 font-mono text-sm font-bold
                                                 {{ $verso === 'up' ? 'text-ruolo-d' : 'text-rosso' }}">
                                        {{ $riga->rank_delta > 0 ? '▲ +' : '▼ −' }}{{ abs($riga->rank_delta) }}
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-display text-lg font-bold uppercase leading-none">
                                            {{ $riga->player->last_name }}
                                        </span>
                                        <span class="font-mono text-[10px] tracking-wide text-paper-dim">
                                            {{ $riga->squadra ?? '—' }} · {{ $riga->tier }} · {{ $riga->rank }}º
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($cambiFascia->isNotEmpty())
            <div class="mt-6">
                <p class="mb-2 font-mono text-[11px] tracking-[0.18em] uppercase text-paper-dim">
                    Hanno cambiato fascia
                </p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($cambiFascia as $riga)
                        <span class="border border-ink-4 bg-ink-2 px-3 py-1.5 text-sm">
                            {{ $riga->player->last_name }}
                            <span class="ml-1 font-mono text-[10px] uppercase tracking-wide"
                                  style="color: {{ \App\Enums\Tier::from($riga->tier)->color() }}">
                                {{ $riga->tier }}
                            </span>
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    {{-- ── Migliore in campo ── --}}
    <section class="mb-12">
        <p class="eyebrow mb-1 text-paper-dim">
            Migliore in campo · giornata {{ $giornataMvp ?? '—' }}
        </p>
        <p class="mb-5 max-w-2xl text-xs leading-relaxed text-paper-dim">
            Il migliore fra chi ha davvero giocato, sostituzioni automatiche comprese: premiare
            un titolare rimasto senza voto non avrebbe senso.
        </p>

        @if ($mvp->isEmpty())
            <p class="border border-ink-4 bg-ink-2 px-4 py-6 text-sm text-paper-dim">
                Nessuna giornata ancora calcolata.
            </p>
        @else
            <ol class="divide-y divide-ink-4/60 border border-ink-4 bg-ink-2">
                @foreach ($mvp as $riga)
                    <li class="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3">
                        <span class="w-32 shrink-0 font-mono text-[11px] tracking-[0.18em] uppercase text-paper-dim">
                            {{ $riga['manager'] }}
                            @if ($riga['auto'])
                                <span class="text-rosso">·auto</span>
                            @endif
                        </span>

                        <span class="w-6 shrink-0 text-center font-display text-lg font-black"
                              style="color: var(--r-{{ $riga['ruolo'] }})">{{ $riga['ruolo'] }}</span>

                        <span class="flex-1 font-display text-xl font-bold uppercase leading-none">
                            {{ $riga['giocatore'] }}
                        </span>

                        <span class="font-mono text-sm font-bold">{{ number_format($riga['fantavoto'], 2, ',', '') }}</span>
                        <span class="w-20 text-right font-mono text-[11px] text-paper-dim">
                            tot {{ number_format($riga['totale'], 1, ',', '') }}
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    {{-- ── Storico delle pescate ── --}}
    <section>
        <p class="eyebrow mb-1 text-paper-dim">Chi capita sempre a chi</p>
        <p class="mb-5 max-w-2xl text-xs leading-relaxed text-paper-dim">
            Conta chi ha <em class="not-italic text-paper">pescato</em> la carta, non chi la
            possiede adesso: una carta pescata e subito scambiata conta lo stesso.
        </p>

        <div class="grid gap-6 md:grid-cols-2">
            <div>
                <p class="mb-2 font-mono text-[11px] tracking-[0.18em] uppercase text-paper-dim">Affinità</p>

                @if ($affinita->isEmpty())
                    <p class="border border-ink-4 bg-ink-2 px-4 py-6 text-sm text-paper-dim">
                        Servono almeno due draft perché una ricorrenza significhi qualcosa.
                    </p>
                @else
                    <ol class="divide-y divide-ink-4/60 border border-ink-4 bg-ink-2">
                        @foreach ($affinita as $riga)
                            <li class="flex items-baseline gap-3 px-3 py-2 text-sm">
                                <span class="w-8 shrink-0 font-mono font-bold text-rosso">{{ $riga->volte }}×</span>
                                <span class="flex-1 truncate">
                                    {{ $riga->giocatore }}
                                    <span class="text-paper-dim">→ {{ $riga->manager }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            <div>
                <p class="mb-2 font-mono text-[11px] tracking-[0.18em] uppercase text-paper-dim">Più pescati</p>

                @if ($piuPescati->isEmpty())
                    <p class="border border-ink-4 bg-ink-2 px-4 py-6 text-sm text-paper-dim">
                        Nessuna carta ancora pescata.
                    </p>
                @else
                    <ol class="divide-y divide-ink-4/60 border border-ink-4 bg-ink-2">
                        @foreach ($piuPescati as $riga)
                            <li class="flex items-baseline gap-3 px-3 py-2 text-sm">
                                <span class="w-8 shrink-0 font-mono font-bold">{{ $riga->volte }}×</span>
                                <span class="w-5 shrink-0 text-center font-display font-black"
                                      style="color: var(--r-{{ $riga->role }})">{{ $riga->role }}</span>
                                <span class="flex-1 truncate">{{ $riga->giocatore }}</span>
                                <span class="font-mono text-[10px] text-paper-dim">{{ $riga->mani }} mani</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </div>
    </section>
</x-layouts.app>
