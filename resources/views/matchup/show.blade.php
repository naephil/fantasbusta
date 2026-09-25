<x-layouts.app title="Sfida — Fantasbusta">
    @php
        $giocata = $sfida->state === 'played';

        /*
         * Quanto ha fruttato la sfida, detto in parole.
         *
         * ⚠️ Si calcola qui e non in mezzo al markup con la forma breve di
         * `@php`: quella non digerisce un'espressione come questa e, invece di
         * protestare, emette un tag PHP aperto senza spazio e senza chiusura.
         * Da lì in poi tutto l'HTML del file finisce dentro il PHP: la pagina
         * si apre bianca, e l'errore parla di un `@if` senza `@endif` alla fine
         * del file — duecentocinquanta righe lontano dalla causa.
         *
         * `VisteTest` adesso monta la guardia su quel tag: vedi il commento lì.
         */
        $esito = null;

        if ($giocata) {
            $pari = $sfida->home_score === $sfida->away_score;
            $quanti = max((int) $sfida->home_score, (int) $sfida->away_score);
            $parola = $quanti === 1 ? 'punto' : 'punti';

            $esito = $pari
                ? "{$sfida->home_score} ".((int) $sfida->home_score === 1 ? 'punto' : 'punti').' a testa'
                : "{$quanti} {$parola} a ".($sfida->home_score > $sfida->away_score ? $sfida->home->name : $sfida->away->name);
        }
    @endphp

    <header class="mb-6 sm:mb-8">
        <p class="eyebrow mb-2 text-rosso">Giornata {{ $sfida->matchday }}</p>

        <div class="flex items-center justify-center gap-3 border border-ink-4 bg-ink-2 p-4 sm:gap-6 sm:p-6">
            <x-squadra-identita :manager="$sfida->home" :dimensione="44" con-maglia
                                class="min-w-0 flex-1 justify-end text-right" />

            <div class="shrink-0 text-center">
                @if ($giocata && $sfida->aGol())
                    <p class="font-display text-4xl font-black leading-none sm:text-5xl">
                        {{ $sfida->home_goals }}<span class="mx-2 text-paper-dim">–</span>{{ $sfida->away_goals }}
                    </p>
                    <p class="mt-2 font-mono text-[11px] tracking-[0.2em] uppercase text-paper-dim">
                        {{ number_format($sfida->home_points, 1, ',', '') }} –
                        {{ number_format($sfida->away_points, 1, ',', '') }} fantapunti
                    </p>
                @elseif ($giocata)
                    <p class="font-display text-3xl font-black leading-none sm:text-4xl">
                        {{ number_format($sfida->home_points, 1, ',', '') }}<span class="mx-2 text-paper-dim">–</span>{{ number_format($sfida->away_points, 1, ',', '') }}
                    </p>
                @else
                    <p class="font-display text-3xl font-black uppercase text-paper-dim">vs</p>
                @endif

                {{-- ⚠️ Una frase, non un secondo punteggio.

                     Diceva «3 – 0 in classifica», e messo sotto al risultato
                     sembrava l'altro risultato della stessa partita: si finiva
                     per chiedersi quale dei due fosse quello vero. Non è un
                     punteggio, è quanto la sfida ha fruttato in graduatoria, e
                     detto in parole non si può confondere con niente. --}}
                @if ($esito)
                    <p class="mt-1 font-mono text-[10px] tracking-[0.16em] uppercase text-paper-dim">
                        {{ $esito }}
                    </p>
                @endif
            </div>

            <x-squadra-identita :manager="$sfida->away" :dimensione="44" con-maglia class="min-w-0 flex-1" />
        </div>
    </header>

    @unless ($giocata)
        <p class="border border-ink-4 bg-ink-2 px-4 py-3 text-sm text-paper-dim">
            La giornata non è ancora stata giocata: qui compariranno i voti.
        </p>
    @endunless

    @if ($giocata)
        @php
            $lati = [
                ['tabellino' => $casa, 'manager' => $sfida->home],
                ['tabellino' => $fuori, 'manager' => $sfida->away],
            ];
        @endphp

        <div class="grid gap-8 lg:grid-cols-2">
            @foreach ($lati as $lato)
                @php
                    $t = $lato['tabellino'];
                @endphp

                <section>
                    <p class="eyebrow mb-3 text-paper-dim">
                        {{ $lato['manager']->name }}
                        @if ($t)
                            · {{ $t['lineup']->module }}
                            @if ($t['lineup']->auto_generated)
                                <span class="text-rosso">· d'ufficio</span>
                            @endif
                        @endif
                    </p>

                    @if (! $t)
                        {{-- Nessuna formazione salvata E nessuna d'ufficio: succede
                             solo se la giornata è stata chiusa senza rose. --}}
                        <p class="border border-ink-4 bg-ink-2 px-4 py-6 text-center text-sm text-paper-dim">
                            Nessuna formazione per questa giornata.
                        </p>
                    @else
                        <div class="border border-ink-4 bg-ink-2">
                            @foreach ($t['righe'] as $riga)
                                @php
                                    $carta = ($riga['entrato'] ?? $riga['slot'])->card;
                                    $uscito = $riga['entrato'] ? $riga['slot']->card : null;
                                @endphp

                                <div class="flex items-baseline gap-3 border-b border-ink-4/50 px-4 py-2.5 last:border-b-0">
                                    <span class="ruolo-pill w-6 shrink-0 text-center font-display text-sm font-black"
                                          data-ruolo="{{ $carta->role }}">{{ $carta->role }}</span>

                                    {{-- ⚠️ Una riga sola, non due.

                                         Il nome era `block` e i segnalini stavano in un
                                         contenitore `flex`, che è anche lui di livello
                                         blocco: gol e cartellini finivano SEMPRE sotto il
                                         nome, una riga a testa, anche con mezzo tabellino
                                         di spazio vuoto a destra. Un tabellino si legge in
                                         verticale scorrendo i voti, e ogni giocatore alto
                                         il doppio raddoppiava la strada.

                                         Adesso nome ed eventi stanno sulla stessa riga e
                                         vanno a capo solo quando lo schermo lo impone. --}}
                                    <span class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <x-nome-giocatore :carta="$carta->id"
                                                          class="min-w-0 truncate font-display text-lg font-bold uppercase leading-none">
                                            {{ $carta->player->last_name }}
                                        </x-nome-giocatore>

                                        <x-segnalini :stat="$riga['eventi']" :ruolo="$carta->role" class="shrink-0" />

                                        @if ($uscito)
                                            {{-- Il pezzo che spiega i totali che «non tornano»:
                                                 il draft chiude prima delle formazioni ufficiali,
                                                 quindi entrare dalla panchina è la norma. --}}
                                            <span class="font-mono text-[10px] tracking-wide text-paper-dim">
                                                ↑ entrato per {{ $uscito->player->last_name }}
                                            </span>
                                        @elseif ($riga['ufficio'] !== null)
                                            <span class="font-mono text-[10px] tracking-wide text-rosso">
                                                senza voto, nessun cambio possibile
                                            </span>
                                        @endif
                                    </span>

                                    {{-- Voto base e fantavoto affiancati: da solo, un 9,5 non
                                         dice se è un 6 con tre gol o un 9 asciutto. --}}
                                    <span class="shrink-0 text-right">
                                        @if ($riga['base'] !== null && $riga['voto'] !== null)
                                            <span class="font-mono text-[11px] text-paper-dim"
                                                  title="voto in pagella">{{ number_format($riga['base'], 1, ',', '') }}</span>
                                            <span class="mx-0.5 text-[10px] text-paper-dim">→</span>
                                        @endif

                                        <x-voto :valore="$riga['voto']" :ufficio="$riga['ufficio']" class="text-sm" />
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-3 flex items-baseline justify-between border-t border-ink-4 pt-3">
                            <span class="eyebrow text-paper-dim">Totale</span>
                            <span class="font-display text-2xl font-black">
                                {{ $t['totale'] !== null ? number_format($t['totale'], 1, ',', '') : '—' }}
                            </span>
                        </div>

                        @if ($t['penalita'])
                            <p class="mt-1 text-right font-mono text-[10px] tracking-wide text-rosso">
                                {{ number_format($t['penalita'], 1, ',', '') }} di penalità: formazione non schierata
                            </p>
                        @endif

                        @if ($t['panchina']->isNotEmpty())
                            <p class="mt-4 text-xs leading-relaxed text-paper-dim">
                                <span class="eyebrow">In panchina</span>
                                @foreach ($t['panchina'] as $p)
                                    <x-nome-giocatore :carta="$p->card->id">{{ $p->card->player->last_name }}</x-nome-giocatore>@if (! $loop->last),@endif
                                @endforeach
                            </p>
                        @endif
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    <p class="mt-10">
        <a href="{{ route('standings.index') }}" class="text-sm text-rosso underline-offset-4 hover:underline">
            ← Torna alla classifica
        </a>
    </p>
</x-layouts.app>
