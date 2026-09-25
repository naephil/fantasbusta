<x-layouts.app title="Proponi uno scambio — Fantasbusta">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Mercato · giornata {{ $matchday }}</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            Scambio con {{ $controparte->name }}
        </h1>
    </header>

    @error('scambio')
        <p class="mb-6 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ route('trades.store', $controparte) }}">
        @csrf

        <div class="grid gap-8 lg:grid-cols-2">
            @foreach ([
                ['titolo' => 'Cedi tu', 'campo' => 'offerte', 'carte' => $mieCarte],
                ['titolo' => "Chiedi a {$controparte->name}", 'campo' => 'richieste', 'carte' => $sueCarte],
            ] as $lato)
                <section>
                    <p class="eyebrow mb-3 text-paper-dim">{{ $lato['titolo'] }}</p>

                    <div class="max-h-[28rem] space-y-1.5 overflow-y-auto pr-1">
                        @foreach ($lato['carte'] as $carta)
                            <label class="flex cursor-pointer items-center gap-3 border border-ink-4 bg-ink-2 px-3 py-2 transition hover:border-paper-dim">
                                <input type="checkbox" name="{{ $lato['campo'] }}[]" value="{{ $carta['id'] }}"
                                       class="accent-rosso"
                                       @checked(in_array($carta['id'], old($lato['campo'], []) ?? []))>

                                <span class="w-6 shrink-0 text-center font-display text-lg font-black"
                                      style="color: var(--r-{{ $carta['role'] }})">{{ $carta['role'] }}</span>

                                <span class="min-w-0 flex-1">
                                    {{-- Il nome porta l'anteprima, la riga resta la spunta: sono
                                         due gesti diversi sulla stessa carta — «guardala» e
                                         «mettila nello scambio» — e vanno tenuti distinti. Da
                                         telefono ci pensa `anteprima-carta.js`, che sul nome
                                         ferma la spunta e mostra la carta. --}}
                                    <span class="block truncate font-display text-lg font-bold uppercase leading-none">
                                        <x-nome-giocatore :carta="$carta['id']">{{ $carta['last'] }}</x-nome-giocatore>
                                    </span>
                                    <span class="font-mono text-[10px] tracking-wide text-paper-dim">
                                        {{ $carta['club'] }} · {{ $carta['tier'] }} · power {{ $carta['power'] }}
                                    </span>
                                </span>
                            </label>
                        @endforeach

                        @if ($lato['carte']->isEmpty())
                            <p class="border border-ink-4 bg-ink-2 px-3 py-6 text-center text-sm text-paper-dim">
                                Nessuna carta per questa giornata.
                            </p>
                        @endif
                    </div>
                </section>
            @endforeach
        </div>

        <div class="mt-8 flex flex-wrap items-center gap-4">
            <button type="submit"
                    class="bg-rosso px-8 py-3 font-display text-2xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                Invia proposta
            </button>

            <a href="{{ route('trades.index') }}" class="text-sm text-paper-dim underline-offset-4 hover:text-paper hover:underline">
                Annulla
            </a>
        </div>

        <p class="mt-4 max-w-xl text-xs leading-relaxed text-paper-dim">
            La proposta non viene controllata adesso: la schierabilità si verifica quando
            {{ $controparte->name }} accetta, perché fino a quel momento le due rose possono
            ancora cambiare.
        </p>
    </form>
</x-layouts.app>
