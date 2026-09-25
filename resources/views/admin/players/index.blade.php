<x-layouts.app title="Listone — Fantasbusta">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Verifica del listone</p>

        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            Chi è chi
        </h1>

        <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Un id sbagliato non si annuncia: se punta a un giocatore inesistente dà errore e lo vedi
            subito, ma se punta a un giocatore <em class="not-italic text-paper">diverso</em> mostra
            una faccia plausibile e supera qualunque controllo automatico. Confronta foto e nome, poi
            conferma.
            @unless (auth()->user()->is_admin)
                Sono cinquecento facce e nessuna macchina sa guardarle: in dodici è mezz'ora.
                Ruolo e quotazione invece li decide chi tiene la lega.
            @endunless
        </p>
    </header>

    {{-- Filtri. L'annata è il primo: ruolo e quotazione appartengono all'anno,
         quindi «da decidere» senza sapere quale anno non vuol dire niente. --}}
    <form method="GET" class="mb-8 flex flex-wrap items-end gap-3">
        <div class="flex overflow-hidden border border-ink-4">
            @foreach (['ruolo' => 'Ruolo da decidere', 'identita' => 'Identità da verificare', 'tutti' => 'Tutti'] as $chiave => $etichetta)
                {{-- L'ordine viaggia coi filtri: cambiando scheda si continua
                     a scorrere dallo stesso capo dell'elenco. --}}
                <a href="{{ route('listone.index', ['filtro' => $chiave, 'anno' => $anno, 'ordine' => $ordine]) }}"
                   class="px-4 py-2 text-sm transition {{ $filtro === $chiave ? 'bg-rosso text-paper' : 'text-paper-dim hover:bg-ink-2 hover:text-paper' }}">
                    {{ $etichetta }}
                    <span class="ml-1 font-mono text-[11px] opacity-70">{{ $conteggi[$chiave] }}</span>
                </a>
            @endforeach
        </div>

        <input type="hidden" name="filtro" value="{{ $filtro }}">

        <select name="anno" onchange="this.form.submit()"
                class="border border-ink-4 bg-ink-2 px-3 py-2 text-sm text-paper outline-none transition focus:border-rosso">
            @foreach ($annate as $a)
                <option value="{{ $a }}" @selected($a == $anno)>{{ $a }}/{{ substr((string) ($a + 1), 2) }}</option>
            @endforeach
        </select>

        {{-- Ordinato per quotazione di partenza: su cinquecento righe si
             verifica quello che si riesce, e conta aver guardato in faccia chi
             verrà davvero pescato. --}}
        <select name="ordine" onchange="this.form.submit()"
                class="border border-ink-4 bg-ink-2 px-3 py-2 text-sm text-paper outline-none transition focus:border-rosso">
            @foreach ($ordini as $chiave => $etichetta)
                <option value="{{ $chiave }}" @selected($ordine === $chiave)>{{ $etichetta }}</option>
            @endforeach
        </select>

        <input type="search" name="q" value="{{ request('q') }}" placeholder="Cognome…"
               class="border border-ink-4 bg-ink-2 px-3 py-2 text-sm text-paper outline-none transition focus:border-rosso">

        <select name="squadra"
                class="border border-ink-4 bg-ink-2 px-3 py-2 text-sm text-paper outline-none transition focus:border-rosso">
            <option value="">Tutte le squadre</option>
            @foreach ($squadre as $squadra)
                <option value="{{ $squadra->id }}" @selected(request('squadra') == $squadra->id)>{{ $squadra->name }}</option>
            @endforeach
        </select>

        <button type="submit" class="border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-rosso hover:text-paper">
            Filtra
        </button>
    </form>

    @if ($giocatori->isEmpty())
        <p class="border border-ink-4 bg-ink-2 px-6 py-12 text-center text-paper-dim">
            Nessun giocatore da rivedere con questi filtri.
        </p>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($giocatori as $giocatore)
                <article class="border border-ink-4 bg-ink-2 transition hover:border-ink-4/40">
                    {{-- Foto e nome affiancati: è tutto il senso della schermata. --}}
                    <div class="flex items-center gap-4 border-b border-ink-4/60 p-4">
                        {{-- La foto porta alla stessa immagine a dimensione piena: a
                             72 pixel due facce simili si somigliano tutte, e la
                             domanda a cui si sta rispondendo è proprio quella. --}}
                        <a href="{{ $giocatore->player->photoUrl() }}" target="_blank" rel="noopener"
                           class="shrink-0" title="Apri la foto a dimensione piena">
                            <img src="{{ $giocatore->player->photoUrl() }}" alt=""
                                 loading="lazy" width="72" height="72"
                                 class="size-18 rounded-full bg-ink-3 object-cover transition hover:opacity-80">
                        </a>

                        <div class="min-w-0 flex-1">
                            {{-- ⚠️ Il nome cerca sul web e non «apre la scheda
                                 API-Football»: quella non esiste: API-Football è
                                 un'API, non un sito con una pagina per giocatore.
                                 La risorsa vera dietro l'id è la foto, che sta sul
                                 ritratto qui accanto; per rispondere a «è davvero
                                 lui» serve invece un confronto esterno. --}}
                            <a href="https://duckduckgo.com/?q={{ urlencode(trim($giocatore->player->first_name.' '.$giocatore->player->last_name).' '.($giocatore->team?->name ?? '').' calciatore') }}"
                               target="_blank" rel="noopener"
                               class="block truncate font-display text-2xl font-bold uppercase leading-none underline-offset-4 hover:text-rosso hover:underline"
                               title="Cerca sul web">
                                {{ $giocatore->player->last_name }}
                            </a>
                            <p class="mt-1 truncate text-sm text-paper-dim">
                                {{ $giocatore->player->first_name }}
                            </p>
                            <p class="mt-1.5 font-mono text-[11px] text-paper-dim">
                                {{ $giocatore->team?->name ?? '—' }} · id {{ $giocatore->player_id }}
                            </p>
                        </div>
                    </div>

                    <div class="space-y-3 p-4">
                        @if (auth()->user()->is_admin)
                            <form method="POST" action="{{ route('admin.players.update', $giocatore) }}" class="space-y-3">
                                @csrf
                                @method('PATCH')

                                <div>
                                    <p class="eyebrow mb-1.5 text-paper-dim">
                                        Ruolo
                                        @unless ($giocatore->role_confirmed)
                                            <span class="text-rosso">· ipotizzato dall'API</span>
                                        @endunless
                                    </p>

                                    <div class="grid grid-cols-4 gap-1.5">
                                        @foreach ($ruoli as $ruolo)
                                            <label class="cursor-pointer">
                                                <input type="radio" name="role" value="{{ $ruolo->value }}"
                                                       class="peer sr-only"
                                                       @checked($giocatore->role === $ruolo)>
                                                <span class="ruolo-pill block border border-ink-4 py-1.5 text-center font-display text-lg font-black transition peer-checked:border-transparent peer-checked:text-ink hover:border-paper-dim">
                                                    {{ $ruolo->value }}
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <label for="qt-{{ $giocatore->player_id }}" class="eyebrow mb-1.5 block text-paper-dim">
                                        Quotazione
                                    </label>
                                    <input id="qt-{{ $giocatore->player_id }}" type="number" name="quotazione_iniziale"
                                           step="0.1" min="0" max="999.9"
                                           value="{{ $giocatore->quotazione_iniziale }}"
                                           class="w-full border border-ink-4 bg-ink px-2.5 py-1.5 text-sm outline-none transition focus:border-rosso">
                                </div>

                                <button type="submit"
                                        class="w-full bg-rosso px-3 py-2 font-display text-base font-black uppercase tracking-wide text-paper transition hover:bg-rosso-vivo">
                                    Salva e conferma ruolo
                                </button>
                            </form>
                        @else
                            {{-- Ruolo e quotazione sono decisioni di lega: si leggono, non si toccano. --}}
                            <div class="flex items-baseline justify-between border-b border-ink-4/60 pb-2">
                                <span class="ruolo-pill border border-ink-4 px-2.5 py-1 font-display text-lg font-black"
                                      data-ruolo="{{ $giocatore->role->value }}">
                                    {{ $giocatore->role->value }}
                                </span>
                                <span class="font-mono text-sm text-paper-dim">
                                    {{ number_format($giocatore->quotazione_iniziale, 1, ',', '') }}
                                </span>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('listone.verify', $giocatore) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full border px-3 py-2 text-sm transition {{ $giocatore->player->photo_verified
                                        ? 'border-ruolo-d text-ruolo-d hover:bg-ruolo-d/10'
                                        : 'border-ink-4 text-paper-dim hover:border-paper-dim hover:text-paper' }}">
                                {{ $giocatore->player->photo_verified ? '✓ Identità verificata' : 'È lui — verifica identità' }}
                            </button>
                        </form>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $giocatori->links() }}
        </div>
    @endif

    {{-- Il ruolo selezionato prende il suo colore. Sono gli stessi quattro di
         App\Enums\Role::color(), definiti come token in resources/css/app.css:
         un colore solo per ruolo, così carta e listone non si contraddicono. --}}
    <style>
        input[value="P"]:checked ~ .ruolo-pill { background-color: var(--color-ruolo-p); }
        input[value="D"]:checked ~ .ruolo-pill { background-color: var(--color-ruolo-d); }
        input[value="C"]:checked ~ .ruolo-pill { background-color: var(--color-ruolo-c); }
        input[value="A"]:checked ~ .ruolo-pill { background-color: var(--color-ruolo-a); }
    </style>
</x-layouts.app>
