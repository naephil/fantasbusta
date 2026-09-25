<x-layouts.app title="Nuovo torneo — Fantasbusta">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Competizioni</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">Nuovo torneo</h1>
    </header>

    @if ($errors->any())
        <ul class="mb-6 space-y-1 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">
            @foreach ($errors->all() as $errore)
                <li>{{ $errore }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('tornei.store') }}" data-torneo class="space-y-10">
        @csrf

        <section class="grid gap-4 sm:grid-cols-[1fr_10rem]">
            <div>
                <label for="name" class="eyebrow mb-2 block text-paper-dim">Nome</label>
                <input id="name" name="name" type="text" maxlength="60" required
                       value="{{ old('name') }}" placeholder="Coppa Fantasbusta"
                       class="w-full border border-ink-4 bg-ink-2 px-3 py-2.5 font-display text-xl font-bold uppercase outline-none transition focus:border-rosso">
            </div>

            <div>
                <label for="start_matchday" class="eyebrow mb-2 block text-paper-dim">Dalla giornata</label>
                <input id="start_matchday" name="start_matchday" type="number" min="1" max="38" required
                       value="{{ old('start_matchday', 1) }}"
                       class="w-full border border-ink-4 bg-ink-2 px-3 py-2.5 text-center font-mono outline-none transition focus:border-rosso">
            </div>
        </section>

        {{-- ── Formato ── --}}
        <section>
            <p class="eyebrow mb-3 text-rosso">Formato</p>

            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($formati as $chiave => $formato)
                    @php [$min, $max] = $formato->partecipanti(); @endphp
                    <label class="cursor-pointer">
                        <input type="radio" name="format" value="{{ $chiave }}" class="peer sr-only"
                               data-formato="{{ $chiave }}" data-min="{{ $min }}" data-max="{{ $max }}"
                               @checked(old('format') === $chiave)>

                        <span class="block h-full border border-ink-4 bg-ink-2 p-4 transition peer-checked:border-rosso peer-checked:bg-rosso/10 hover:border-paper-dim">
                            <span class="block font-display text-xl font-bold uppercase leading-none">
                                {{ $formato->nome() }}
                            </span>
                            <span class="mt-2 block text-sm leading-relaxed text-paper-dim">
                                {{ $formato->descrizione() }}
                            </span>
                            <span class="mt-2 block font-mono text-[10px] tracking-wide text-paper-dim">
                                da {{ $min }} a {{ $max }} squadre
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>

            {{-- I parametri del formato scelto: uno solo alla volta è visibile. --}}
            @foreach ($parametri as $chiave => $campi)
                @if ($campi)
                    <div data-parametri="{{ $chiave }}" hidden class="mt-4 flex flex-wrap gap-4 border border-ink-4 bg-ink-2 p-4">
                        @foreach ($campi as $nome => $campo)
                            <div>
                                <label class="eyebrow mb-2 block text-paper-dim">{{ $campo['etichetta'] }}</label>
                                <input type="number" name="settings[{{ $chiave }}][{{ $nome }}]"
                                       min="{{ $campo['min'] }}" max="{{ $campo['max'] }}"
                                       value="{{ old("settings.{$chiave}.{$nome}", $campo['default']) }}"
                                       class="w-24 border border-ink-4 bg-ink px-3 py-2 text-center font-mono outline-none transition focus:border-rosso">
                            </div>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </section>

        {{-- ── Partecipanti ── --}}
        <section>
            <p class="eyebrow mb-1 text-rosso">Partecipanti</p>
            <p class="mb-4 max-w-2xl text-xs leading-relaxed text-paper-dim">
                L'ordine in cui compaiono è l'ordine di testa di serie: decide gli accoppiamenti
                del primo turno ed è l'ultimo spareggio quando tutto il resto è pari.
                <span data-conteggio class="text-paper">0 selezionate</span>
            </p>

            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($squadre as $i => $squadra)
                    <label class="flex cursor-pointer items-center gap-3 border border-ink-4 bg-ink-2 px-3 py-2 transition hover:border-paper-dim">
                        <input type="checkbox" name="partecipanti[]" value="{{ $squadra->id }}"
                               class="accent-rosso" data-partecipante
                               @checked(in_array($squadra->id, old('partecipanti', $squadre->pluck('id')->all())))>

                        <span class="w-6 shrink-0 text-center font-mono text-[11px] text-paper-dim">{{ $i + 1 }}</span>

                        <x-squadra-identita :manager="$squadra" :dimensione="28" :con-allenatore="false" class="flex-1" />
                    </label>
                @endforeach
            </div>
        </section>

        <button type="submit"
                class="bg-rosso px-8 py-3 font-display text-2xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
            Crea torneo
        </button>
    </form>

    <script>
        (() => {
            const form = document.querySelector('[data-torneo]');
            if (!form) return;

            const conteggio = form.querySelector('[data-conteggio]');
            const caselle = [...form.querySelectorAll('[data-partecipante]')];

            const aggiorna = () => {
                const scelte = caselle.filter((c) => c.checked).length;
                const formato = form.querySelector('input[name="format"]:checked');

                // I parametri del formato scelto, e solo quelli.
                form.querySelectorAll('[data-parametri]').forEach((el) => {
                    el.toggleAttribute('hidden', el.dataset.parametri !== formato?.value);
                });

                if (!formato) {
                    conteggio.textContent = `${scelte} selezionate`;
                    return;
                }

                const min = Number(formato.dataset.min);
                const max = Number(formato.dataset.max);
                const ok = scelte >= min && scelte <= max;

                conteggio.textContent = ok
                    ? `${scelte} selezionate`
                    : `${scelte} selezionate — questo formato ne vuole fra ${min} e ${max}`;
                conteggio.classList.toggle('text-rosso', !ok);
                conteggio.classList.toggle('text-paper', ok);
            };

            form.addEventListener('change', aggiorna);
            aggiorna();
        })();
    </script>
</x-layouts.app>
