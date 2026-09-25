<x-layouts.app title="Squadre — Fantasbusta">
    @php($campo = 'w-full border border-ink-4 bg-ink px-2.5 py-1.5 text-sm outline-none transition focus:border-rosso')

    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Amministrazione</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            Chi gioca
        </h1>
        <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Due modi per far entrare qualcuno: creargli la squadra a mano e dargli la password
            a voce, oppure aprire le iscrizioni e passare il link — in quel caso se la crea da sé,
            password compresa.
        </p>
    </header>

    @error('squadra')
        <p class="mb-6 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    {{-- ══════════ Il link d'invito ══════════ --}}
    <section class="mb-12">
        <p class="eyebrow mb-3 text-rosso">Il link per iscriversi</p>

        <div class="border border-ink-4 bg-ink-2 p-5">
            @if ($league->iscrizioniAperte())
                <p class="mb-4 text-sm text-paper-dim">
                    Iscrizioni <span class="text-paper">aperte</span>. Chi apre questo indirizzo si crea
                    una squadra ed entra subito, senza che tu approvi nulla.
                </p>

                <input type="text" readonly value="{{ $league->linkIscrizione() }}"
                       onclick="this.select()"
                       class="{{ $campo }} font-mono text-[11px] tracking-tight">

                <div class="mt-5 flex flex-wrap items-center gap-4">
                    <form method="POST" action="{{ route('admin.squadre.invito.chiudi') }}">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="bg-rosso px-6 py-2.5 font-display text-lg font-black uppercase tracking-wide text-paper transition hover:bg-rosso-vivo">
                            Chiudi le iscrizioni
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.squadre.invito.apri') }}"
                          onsubmit="return confirm('Il link di adesso smette di funzionare. Procedere?')">
                        @csrf
                        <button type="submit"
                                class="border border-ink-4 px-5 py-2.5 text-sm text-paper-dim transition hover:border-rosso hover:text-rosso">
                            Rigenera
                        </button>
                    </form>
                </div>

                <p class="mt-5 max-w-2xl border-t border-ink-4/60 pt-4 font-mono text-[11px] leading-relaxed tracking-wide text-paper-dim">
                    ⚠️ Questo link <span class="text-paper">vale quanto una password</span>: non c'è
                    verifica dell'email e chiunque ce l'abbia entra. Tienile aperte il tempo che serve
                    e poi chiudi — chi si è già iscritto resta dentro. Se il link finisce dove non
                    doveva, <span class="text-paper">rigenera</span>: il vecchio muore all'istante.
                </p>
            @else
                <p class="mb-5 max-w-2xl text-sm leading-relaxed text-paper-dim">
                    Iscrizioni <span class="text-paper">chiuse</span>: si entra solo con una squadra
                    creata da te. Aprendole ottieni un indirizzo da passare nella chat del gruppo —
                    comodo quando le persone da far entrare sono più di due o tre.
                </p>

                <form method="POST" action="{{ route('admin.squadre.invito.apri') }}">
                    @csrf
                    <button type="submit"
                            class="border border-rosso px-6 py-2.5 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                        Apri le iscrizioni
                    </button>
                </form>
            @endif
        </div>
    </section>

    {{-- ══════════ Chi c'è ══════════ --}}
    <section class="mb-12">
        <p class="eyebrow mb-3 text-rosso">
            Le squadre di «{{ $league->name }}» · {{ $squadre->where('active', true)->count() }} attive
        </p>

        @foreach ($squadre as $m)
            <form method="POST" action="{{ route('admin.squadre.update', $m) }}"
                  class="mb-3 border p-4 {{ $m->active ? 'border-ink-4 bg-ink-2' : 'border-ink-4/40 bg-ink-2/40' }}">
                @csrf @method('PATCH')

                <div class="flex flex-wrap items-end gap-4">
                    <label class="block min-w-52 flex-1">
                        <span class="eyebrow mb-1.5 block text-paper-dim">
                            Squadra
                            @if ($m->is_bot)<span class="text-rosso">· bot</span>@endif
                        </span>
                        <input type="text" name="name" value="{{ $m->name }}" class="{{ $campo }}">
                    </label>

                    <label class="block">
                        <span class="eyebrow mb-1.5 block text-paper-dim">Nuova password</span>
                        <input type="text" name="password" placeholder="lascia vuoto"
                               class="{{ $campo }} w-40 placeholder:text-ink-4">
                    </label>

                    <div class="flex flex-wrap items-center gap-4 pb-1.5 text-sm text-paper-dim">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="active" value="1" @checked($m->active) class="accent-rosso">
                            attiva
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="auto_draft" value="1" @checked($m->auto_draft) class="accent-rosso">
                            sbusta da sola
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" name="is_admin" value="1" @checked($m->is_admin) class="accent-rosso">
                            admin
                        </label>
                    </div>

                    <button type="submit"
                            class="border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-rosso hover:text-rosso">
                        Salva
                    </button>
                </div>

                <p class="mt-3 font-mono text-[11px] tracking-wide text-paper-dim">
                    {{ $m->email }}
                    @if ($m->coach_name) · {{ $m->coach_name }} @endif
                </p>
            </form>

            @unless ($m->id === auth()->id())
                <form method="POST" action="{{ route('admin.squadre.destroy', $m) }}"
                      class="-mt-2 mb-4 pl-4"
                      onsubmit="return confirm('Togliere «{{ $m->name }}» dal giro?')">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-xs text-paper-dim underline-offset-4 transition hover:text-rosso hover:underline">
                        Togli dal giro
                    </button>
                </form>
            @endunless
        @endforeach
    </section>

    {{-- ══════════ Invitare ══════════ --}}
    <section class="mb-12">
        <p class="eyebrow mb-3 text-rosso">Invita una persona</p>

        <form method="POST" action="{{ route('admin.squadre.store') }}" class="border border-ink-4 bg-ink-2 p-5">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Nome squadra</span>
                    <input type="text" name="name" value="{{ old('name') }}" required class="{{ $campo }}">
                </label>
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Allenatore</span>
                    <input type="text" name="coach_name" value="{{ old('coach_name') }}" class="{{ $campo }}">
                </label>
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required class="{{ $campo }}">
                </label>
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Password iniziale</span>
                    <input type="text" name="password" required class="{{ $campo }}">
                </label>
            </div>

            @foreach (['name', 'email', 'password'] as $c)
                @error($c)<p class="mt-3 text-sm text-rosso">{{ $message }}</p>@enderror
            @endforeach

            <div class="mt-5 flex flex-wrap items-center gap-5">
                <button type="submit"
                        class="bg-rosso px-6 py-2.5 font-display text-xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                    Crea
                </button>

                <label class="flex items-center gap-2 text-sm text-paper-dim">
                    <input type="checkbox" name="auto_draft" value="1" class="accent-rosso">
                    sbusta da sola (per chi non vuole star dietro al draft)
                </label>
            </div>
        </form>
    </section>

    {{-- ══════════ Riempire i posti ══════════ --}}
    <section>
        <p class="eyebrow mb-3 text-rosso">Riempi i posti vuoti</p>

        <form method="POST" action="{{ route('admin.squadre.bot') }}" class="border border-ink-4 bg-ink-2 p-5">
            @csrf

            <p class="mb-5 max-w-2xl text-sm leading-relaxed text-paper-dim">
                Con quattro persone il calendario dà tre sfide a testa e il pool del draft resta
                pieno per due terzi: si proverebbe un gioco che non somiglia a quello vero. I bot
                riempiono i posti — sbustano appena tocca a loro e schierano sempre la miglior
                formazione possibile.
            </p>

            <div class="flex flex-wrap items-end gap-4">
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Quanti</span>
                    <input type="number" name="quanti" min="1" max="12"
                           value="{{ max(1, 12 - $squadre->where('active', true)->count()) }}"
                           class="{{ $campo }} w-24 text-center font-mono">
                </label>

                <button type="submit"
                        class="border border-rosso px-6 py-2.5 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                    Aggiungi bot
                </button>
            </div>

            <p class="mt-5 border-t border-ink-4/60 pt-4 max-w-2xl font-mono text-[11px] leading-relaxed tracking-wide text-paper-dim">
                ⚠️ Un bot <span class="text-paper">non prende la penalità da dimenticanza</span>: quella
                punisce chi si scorda di schierare, e un bot non si scorda. Va saputo leggendo la
                classifica — gioca sempre al meglio delle sue carte, quindi è un avversario un filo
                più ostico della media di una lega di amici.
            </p>
        </form>
    </section>
</x-layouts.app>
