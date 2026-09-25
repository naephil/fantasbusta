<x-layouts.app title="La mia squadra — Fantasbusta">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Identità</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            La mia squadra
        </h1>
        <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Colori e forme si scelgono da una tavolozza chiusa. È voluto: con libertà totale
            escono dodici squadre indistinguibili e tre orrende.
        </p>
    </header>

    @if ($errors->any())
        <ul class="mb-6 space-y-1 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">
            @foreach ($errors->all() as $errore)
                <li>{{ $errore }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('team.update') }}" enctype="multipart/form-data"
          data-squadra class="grid gap-10 lg:grid-cols-[1fr_20rem]">
        @csrf

        {{-- ───────────── Scelte ───────────── --}}
        <div class="space-y-10">

            {{-- Nome e allenatore --}}
            <section class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="eyebrow mb-2 block text-paper-dim">Nome della squadra</label>
                    <input id="name" name="name" type="text" maxlength="40" required
                           value="{{ old('name', $manager->name) }}"
                           data-anteprima="nome"
                           class="w-full border border-ink-4 bg-ink-2 px-3 py-2.5 font-display text-xl font-bold uppercase outline-none transition focus:border-rosso">
                </div>

                <div>
                    <label for="coach_name" class="eyebrow mb-2 block text-paper-dim">Allenatore</label>
                    <input id="coach_name" name="coach_name" type="text" maxlength="40"
                           value="{{ old('coach_name', $manager->coach_name) }}"
                           data-anteprima="allenatore" placeholder="—"
                           class="w-full border border-ink-4 bg-ink-2 px-3 py-2.5 outline-none transition focus:border-rosso">
                </div>
            </section>

            {{-- ───────────── Maglia ───────────── --}}
            <section>
                <h2 class="eyebrow mb-4 text-rosso">Maglia</h2>

                <x-scelta-colori campo="maglia_colori" bersaglio="maglia"
                                 :palette="$palette" :colori="old('maglia_colori', $maglia['colori'])" />

                <p class="eyebrow mb-2 mt-6 text-paper-dim">Stile</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($stili as $chiave => $etichetta)
                        <label class="cursor-pointer">
                            <input type="radio" name="maglia_stile" value="{{ $chiave }}" class="peer sr-only"
                                   data-imposta="maglia:stile"
                                   @checked(old('maglia_stile', $maglia['stile']) === $chiave)>
                            <span class="block border border-ink-4 px-3 py-2 text-sm transition peer-checked:border-rosso peer-checked:bg-rosso peer-checked:text-paper hover:border-paper-dim">
                                {{ $etichetta }}
                            </span>
                        </label>
                    @endforeach
                </div>

            </section>

            {{-- ───────────── Sponsor ───────────── --}}
            <section>
                <h2 class="eyebrow mb-4 text-rosso">Sponsor</h2>

                <p class="eyebrow mb-2 text-paper-dim">Modelli</p>
                <div class="mb-6 flex flex-wrap gap-2">
                    @foreach ($sponsorModelli as $chiave => $modello)
                        <button type="button" data-modello-sponsor
                                data-testo="{{ $modello['testo'] }}"
                                data-stile="{{ $modello['stile'] }}"
                                class="border border-ink-4 px-3 py-2 text-sm transition hover:border-rosso hover:text-paper">
                            {{ $modello['testo'] }}
                        </button>
                    @endforeach

                    <button type="button" data-modello-sponsor data-testo="" data-stile="blocco"
                            class="border border-ink-4 px-3 py-2 text-sm text-paper-dim transition hover:border-paper-dim">
                        Nessuno
                    </button>
                </div>

                <div class="grid gap-4 sm:grid-cols-[1fr_auto]">
                    <div>
                        <label for="sponsor_testo" class="eyebrow mb-2 block text-paper-dim">Oppure scrivilo</label>
                        <input id="sponsor_testo" name="sponsor_testo" type="text" maxlength="18"
                               value="{{ old('sponsor_testo', $sponsor['testo']) }}" placeholder="—"
                               data-anteprima="sponsor"
                               class="w-full border border-ink-4 bg-ink-2 px-3 py-2.5 uppercase outline-none transition focus:border-rosso">
                    </div>

                    <div>
                        <p class="eyebrow mb-2 text-paper-dim">Carattere</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($sponsorStili as $chiave => $etichetta)
                                <label class="cursor-pointer">
                                    <input type="radio" name="sponsor_stile" value="{{ $chiave }}" class="peer sr-only"
                                           data-imposta="maglia:sponsor-stile"
                                           @checked(old('sponsor_stile', $sponsor['stile']) === $chiave)>
                                    <span class="block border border-ink-4 px-3 py-2 text-sm transition peer-checked:border-rosso peer-checked:bg-rosso peer-checked:text-paper hover:border-paper-dim">
                                        {{ $etichetta }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Posizione, riquadro e colore.
                     Il riquadro non è un vezzo: su una maglia a righe o a quarti
                     la scritta cade a metà fra due colori e sparisce. È lo stesso
                     rimedio che usano le maglie vere. --}}
                <div class="mt-6 flex flex-wrap items-end gap-6 border border-ink-4 bg-ink-2 p-4">
                    <div>
                        <p class="eyebrow mb-2 text-paper-dim">Altezza sul petto</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach (['alto' => 'Alto', 'centro' => 'Centro', 'basso' => 'Basso'] as $chiave => $etichetta)
                                <label class="cursor-pointer">
                                    <input type="radio" name="sponsor_posizione" value="{{ $chiave }}" class="peer sr-only"
                                           data-sponsor-posizione
                                           @checked(old('sponsor_posizione', $sponsor['posizione']) === $chiave)>
                                    <span class="block border border-ink-4 px-3 py-2 text-sm transition peer-checked:border-rosso peer-checked:bg-rosso peer-checked:text-paper hover:border-paper-dim">
                                        {{ $etichetta }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <label class="block">
                        <span class="mb-1.5 block font-mono text-[10px] uppercase tracking-[0.2em] text-paper-dim">
                            Scritta
                        </span>
                        <input type="color" name="sponsor_colore"
                               value="{{ old('sponsor_colore', $sponsor['colore'] ?? ($maglia['colori'][2] ?? '#f2ede1')) }}"
                               data-sponsor-colore
                               class="h-10 w-16 cursor-pointer border border-ink-4 bg-ink p-1">
                    </label>

                    <div>
                        <label class="mb-2 flex items-center gap-2 text-sm text-paper-dim">
                            <input type="checkbox" name="sponsor_riquadro" value="1" class="accent-rosso"
                                   data-sponsor-riquadro
                                   @checked(old('sponsor_riquadro', $sponsor['riquadro']))>
                            riquadro dietro
                        </label>

                        <input type="color" name="sponsor_colore_riquadro"
                               value="{{ old('sponsor_colore_riquadro', $sponsor['colore_riquadro'] ?? ($maglia['colori'][0] ?? '#0b0b0e')) }}"
                               data-sponsor-colore-riquadro
                               class="h-10 w-16 cursor-pointer border border-ink-4 bg-ink p-1">
                    </div>
                </div>

                <div class="mt-6 border border-ink-4 bg-ink-2 p-4">
                    <p class="eyebrow mb-2 text-paper-dim">Oppure carica un marchio</p>
                    <p class="mb-3 text-xs leading-relaxed text-paper-dim">
                        PNG con trasparenza, oppure WebP o SVG. Niente JPEG: il marchio finisce sopra
                        il colore della maglia, e un rettangolo bianco attorno rovinerebbe tutto.
                        Massimo {{ config('squadra.upload_sponsor.max_kb') }} KB.
                    </p>

                    <input type="file" name="sponsor_file"
                           accept="{{ collect(config('squadra.upload_sponsor.formati'))->map(fn ($f) => '.'.$f)->join(',') }}"
                           class="block w-full text-sm text-paper-dim file:mr-3 file:border-0 file:bg-ink-4 file:px-3 file:py-1.5 file:text-sm file:text-paper hover:file:bg-ink-3">

                    @if ($manager->sponsor_path)
                        <label class="mt-3 flex items-center gap-2 text-sm text-paper-dim">
                            <input type="checkbox" name="rimuovi_sponsor" value="1" class="accent-rosso">
                            Rimuovi il marchio caricato e torna al testo
                        </label>
                    @endif
                </div>
            </section>

            {{-- ───────────── Stemma ───────────── --}}
            <section>
                <h2 class="eyebrow mb-4 text-rosso">Stemma</h2>

                <x-scelta-colori campo="stemma_colori" bersaglio="stemma"
                                 :palette="$palette" :colori="old('stemma_colori', $stemma['colori'])"
                                 :etichette="['Sfondo', 'Simbolo', 'Bordo']" />

                <p class="eyebrow mb-2 mt-6 text-paper-dim">Forma</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($forme as $chiave => $etichetta)
                        <label class="cursor-pointer">
                            <input type="radio" name="stemma_forma" value="{{ $chiave }}" class="peer sr-only"
                                   data-imposta="stemma:forma"
                                   @checked(old('stemma_forma', $stemma['forma']) === $chiave)>
                            <span class="block border border-ink-4 px-3 py-2 text-sm transition peer-checked:border-rosso peer-checked:bg-rosso peer-checked:text-paper hover:border-paper-dim">
                                {{ $etichetta }}
                            </span>
                        </label>
                    @endforeach
                </div>

                <p class="eyebrow mb-2 mt-6 text-paper-dim">Simbolo</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($simboli as $chiave => $etichetta)
                        <label class="cursor-pointer" title="{{ $etichetta }}">
                            <input type="radio" name="stemma_simbolo" value="{{ $chiave }}" class="peer sr-only"
                                   data-simbolo="{{ $chiave }}"
                                   @checked(old('stemma_simbolo', $stemma['simbolo']) === $chiave)>
                            <span class="flex size-14 items-center justify-center border border-ink-4 transition peer-checked:border-rosso peer-checked:bg-rosso/15 hover:border-paper-dim">
                                @if ($chiave === 'nessuno')
                                    <span class="font-display text-xs font-black uppercase text-paper-dim">AB</span>
                                @else
                                    <svg viewBox="0 0 100 100" class="size-7 fill-paper-dim">
                                        <use href="#sim-{{ $chiave }}"/>
                                    </svg>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>

                {{-- Caricamento --}}
                <div class="mt-8 border border-ink-4 bg-ink-2 p-4">
                    <p class="eyebrow mb-2 text-paper-dim">Oppure carica il tuo</p>
                    <p class="mb-3 text-xs leading-relaxed text-paper-dim">
                        Ha la precedenza su quello composto qui sopra. Massimo
                        {{ config('squadra.upload.max_kb') }} KB,
                        {{ implode(' · ', config('squadra.upload.formati')) }}.
                    </p>

                    <input type="file" name="stemma_file"
                           accept="{{ collect(config('squadra.upload.formati'))->map(fn ($f) => ".{$f}")->join(',') }}"
                           class="block w-full text-sm text-paper-dim file:mr-3 file:border-0 file:bg-ink-4 file:px-3 file:py-1.5 file:text-sm file:text-paper hover:file:bg-ink-3">

                    @if ($manager->crest_path)
                        <label class="mt-3 flex items-center gap-2 text-sm text-paper-dim">
                            <input type="checkbox" name="rimuovi_stemma" value="1" class="accent-rosso">
                            Rimuovi lo stemma caricato e torna a quello composto
                        </label>
                    @endif
                </div>
            </section>

            <button type="submit"
                    class="bg-rosso px-8 py-3 font-display text-2xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                Salva squadra
            </button>
        </div>

        {{-- ───────────── Anteprima ───────────── --}}
        <aside class="lg:sticky lg:top-6 lg:self-start">
            <div class="border border-ink-4 bg-ink-2 p-6 text-center">
                <p class="eyebrow mb-5 text-paper-dim">Anteprima</p>

                <div class="flex items-end justify-center gap-5">
                    <div data-preview-stemma>
                        <x-stemma :stemma="$stemma" :iniziali="$manager->initials()" :dimensione="88" />
                    </div>

                    <div data-preview-maglia>
                        <x-maglia :maglia="$maglia" :sponsor="$sponsor"
                                  :sponsor-file="$manager->sponsor_path" :dimensione="120" />
                    </div>
                </div>

                <p class="mt-5 font-display text-2xl font-black uppercase leading-none" data-mostra="nome">
                    {{ $manager->name }}
                </p>
                <p class="mt-1 font-mono text-[11px] tracking-[0.2em] uppercase text-paper-dim" data-mostra="allenatore">
                    {{ $manager->coach_name ?: '—' }}
                </p>

                @if ($manager->crest_path || $manager->sponsor_path)
                    <p class="mt-4 border-t border-ink-4/60 pt-3 text-xs leading-relaxed text-paper-dim">
                        @if ($manager->crest_path) Stemma caricato in uso. @endif
                        @if ($manager->sponsor_path) Marchio caricato in uso sulla maglia. @endif
                    </p>
                @endif
            </div>
        </aside>
    </form>

    <script>
        // L'anteprima non ridisegna niente: cambia variabili CSS e attributi
        // sullo stesso SVG che il server ha già reso. Un renderer solo, come
        // per le carte — con due, prima o poi se ne aggiorna uno solo.
        (() => {
            const form = document.querySelector('[data-squadra]');
            if (!form) return;

            const maglia = form.querySelector('[data-preview-maglia] svg');
            const stemma = form.querySelector('[data-preview-stemma] svg');
            const bersagli = { maglia, stemma };

            const colora = (svg, colori, prefisso) => {
                if (!svg) return;
                colori.forEach((c, i) => svg.style.setProperty(`--${prefisso}${i + 1}`, c));
            };

            const prefisso = (quale) => (quale === 'maglia' ? 'm' : 's');

            // I tre selettori, uno per volta: si tocca solo la variabile CSS
            // corrispondente, così muovere il colore del motivo non ridisegna
            // il resto e l'anteprima resta immediata.
            form.querySelectorAll('[data-colore]').forEach((el) => {
                el.addEventListener('input', () => {
                    const quale = el.dataset.colore;
                    bersagli[quale]?.style.setProperty(
                        `--${prefisso(quale)}${Number(el.dataset.indice) + 1}`,
                        el.value,
                    );
                });
            });

            // Le palette riempiono i tre selettori invece di essere una scelta
            // a parte: chi parte da una palette può comunque ritoccarla, che è
            // tutto il motivo per cui i colori sono diventati liberi.
            form.querySelectorAll('[data-preset]').forEach((el) => {
                el.addEventListener('click', () => {
                    const quale = el.dataset.preset;
                    const colori = JSON.parse(el.dataset.colori);

                    form.querySelectorAll(`[data-colore="${quale}"]`).forEach((input) => {
                        input.value = colori[Number(input.dataset.indice)];
                        input.dispatchEvent(new Event('input'));
                    });
                });
            });

            // ── Sponsor: altezza, riquadro, colori ──
            const sponsorY = { alto: 53, centro: 63, basso: 73 };

            const spostaSponsor = (posizione) => {
                const y = sponsorY[posizione] ?? 63;

                maglia?.querySelector('.sponsor')?.setAttribute('y', y);
                maglia?.querySelector('.sponsor-box')?.setAttribute('y', y - 9);
                maglia?.querySelector('.sponsor-img')?.setAttribute('y', y - 15);
            };

            form.querySelectorAll('[data-sponsor-posizione]').forEach((el) => {
                el.addEventListener('change', () => spostaSponsor(el.value));
            });

            const riquadro = form.querySelector('[data-sponsor-riquadro]');
            const testoSponsor = form.querySelector('#sponsor_testo');

            const aggiornaRiquadro = () => {
                const box = maglia?.querySelector('.sponsor-box');
                if (!box) return;

                // Nascosto anche quando non c'è scritta da incorniciare: un
                // rettangolo solo in mezzo al petto non vuol dire niente.
                box.toggleAttribute('hidden', !riquadro?.checked || !testoSponsor?.value.trim());
            };

            riquadro?.addEventListener('change', aggiornaRiquadro);
            testoSponsor?.addEventListener('input', aggiornaRiquadro);

            form.querySelector('[data-sponsor-colore]')?.addEventListener('input', (e) => {
                maglia?.querySelector('.sponsor')?.setAttribute('fill', e.target.value);
            });

            form.querySelector('[data-sponsor-colore-riquadro]')?.addEventListener('input', (e) => {
                maglia?.querySelector('.sponsor-box')?.setAttribute('fill', e.target.value);
            });

            form.querySelectorAll('[data-imposta]').forEach((el) => {
                el.addEventListener('change', () => {
                    const [quale, attributo] = el.dataset.imposta.split(':');
                    bersagli[quale]?.setAttribute(`data-${attributo}`, el.value);
                });
            });

            form.querySelectorAll('[data-simbolo]').forEach((el) => {
                el.addEventListener('change', () => {
                    const uso = stemma?.querySelector('use');
                    const testo = stemma?.querySelector('text');

                    if (el.value === 'nessuno') {
                        uso?.closest('g')?.setAttribute('hidden', '');
                        testo?.removeAttribute('hidden');
                    } else {
                        uso?.closest('g')?.removeAttribute('hidden');
                        testo?.setAttribute('hidden', '');
                        uso?.setAttribute('href', `#sim-${el.value}`);
                    }
                });
            });

            // I modelli non sono un insieme a parte: riempiono i campi del testo
            // libero, cosi chi parte da un modello puo comunque ritoccarlo.
            form.querySelectorAll('[data-modello-sponsor]').forEach((el) => {
                el.addEventListener('click', () => {
                    const campo = form.querySelector('#sponsor_testo');
                    campo.value = el.dataset.testo;
                    campo.dispatchEvent(new Event('input'));

                    const stile = form.querySelector(`input[name="sponsor_stile"][value="${el.dataset.stile}"]`);
                    if (stile) { stile.checked = true; stile.dispatchEvent(new Event('change')); }
                });
            });

            form.querySelectorAll('[data-anteprima]').forEach((el) => {
                el.addEventListener('input', () => {
                    const dove = el.dataset.anteprima;

                    if (dove === 'sponsor') {
                        const marchio = maglia?.querySelector('.sponsor');
                        if (!marchio) return;
                        marchio.textContent = el.value.toUpperCase();
                        marchio.toggleAttribute('hidden', el.value.trim() === '');
                        return;
                    }

                    const bersaglio = form.querySelector(`[data-mostra="${dove}"]`);
                    if (bersaglio) bersaglio.textContent = el.value || '—';
                });
            });
        })();
    </script>

    {{-- ── La password ──
         Fuori dal modulo della squadra: sono due cose diverse, e mescolarle
         costringerebbe a ridigitare la password ogni volta che si cambia il
         colore della maglia. --}}
    <section class="mt-12 border-t border-ink-4/60 pt-8">
        <p class="eyebrow mb-3 text-rosso">La tua password</p>

        <form method="POST" action="{{ route('password.update') }}"
              class="max-w-xl border border-ink-4 bg-ink-2 p-5">
            @csrf

            <p class="mb-5 text-sm leading-relaxed text-paper-dim">
                Se sei entrato con la password che ti ha dato l'amministratore, cambiala:
                da qui in poi la sai solo tu.
            </p>

            <div class="grid gap-4 sm:grid-cols-3">
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Quella attuale</span>
                    <input type="password" name="attuale" required autocomplete="current-password"
                           class="w-full border border-ink-4 bg-ink px-2.5 py-1.5 text-sm outline-none transition focus:border-rosso">
                </label>
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">La nuova</span>
                    <input type="password" name="nuova" required minlength="8" autocomplete="new-password"
                           class="w-full border border-ink-4 bg-ink px-2.5 py-1.5 text-sm outline-none transition focus:border-rosso">
                </label>
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Di nuovo</span>
                    <input type="password" name="nuova_confirmation" required minlength="8" autocomplete="new-password"
                           class="w-full border border-ink-4 bg-ink px-2.5 py-1.5 text-sm outline-none transition focus:border-rosso">
                </label>
            </div>

            @foreach (['attuale', 'nuova'] as $campo)
                @error($campo)<p class="mt-3 text-sm text-rosso">{{ $message }}</p>@enderror
            @endforeach

            <button type="submit"
                    class="mt-5 border border-rosso px-6 py-2 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                Cambia
            </button>
        </form>
    </section>
</x-layouts.app>
