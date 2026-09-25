<x-layouts.app title="Regole — Fantasbusta">
    @php
        $campo = 'w-full border border-ink-4 bg-ink px-2.5 py-1.5 text-sm outline-none transition focus:border-rosso';
        $altre = $stagioniDisponibili->reject(fn ($s) => $s->id === $stagione->id);
    @endphp

    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Amministrazione</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            Le regole
        </h1>
        <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Vale tutto per <strong class="text-paper">{{ $stagione->etichetta() }}</strong>: ogni stagione
            ha la sua taratura, e le altre non si toccano. Salvare
            <strong class="text-paper">non ricalcola</strong> le giornate già chiuse — per
            applicare una modifica al passato si rilancia la giornata dalla gestione.
        </p>
    </header>

    @error('regole')
        <p class="mb-6 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    <form method="POST" action="{{ route('admin.regole.update') }}">
        @csrf

        {{-- ══════════ Bonus e malus ══════════ --}}
        <section class="mb-10">
            <p class="eyebrow mb-3 text-rosso">Bonus e malus</p>

            <div class="border border-ink-4 bg-ink-2 p-5">
                <p class="mb-5 max-w-2xl text-sm leading-relaxed text-paper-dim">
                    Nessun evento è «per definizione» un bonus: vale ciò che dice il suo segno.
                    Portare il gol a <span class="font-mono text-paper">−3</span> lo trasforma in un
                    malus, e la ripartizione fra le colonne del tabellino segue di conseguenza.
                    Le caselle di ruolo lasciate vuote ricadono sul valore generale.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[34rem] border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-ink-4">
                                <th class="pb-2 text-left font-normal"><span class="eyebrow text-paper-dim">Evento</span></th>
                                <th class="pb-2 px-1 text-center font-normal"><span class="eyebrow text-paper-dim">Tutti</span></th>
                                @foreach ($ruoli as $ruolo)
                                    <th class="pb-2 px-1 text-center font-normal">
                                        <span class="eyebrow" style="color: {{ $ruolo->color() }}">{{ $ruolo->value }}</span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-ink-4/60">
                            @foreach ($eventi as $chiave => $etichetta)
                                <tr>
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $etichetta }}</td>
                                    <td class="py-2 px-1">
                                        <input type="number" step="0.5" name="eventi[{{ $chiave }}][default]"
                                               value="{{ $valori['eventi'][$chiave]['default'] ?? 0 }}"
                                               class="{{ $campo }} w-20 text-center font-mono tabular-nums">
                                    </td>
                                    @foreach ($ruoli as $ruolo)
                                        <td class="py-2 px-1">
                                            <input type="number" step="0.5" name="eventi[{{ $chiave }}][{{ $ruolo->value }}]"
                                                   value="{{ $valori['eventi'][$chiave][$ruolo->value] ?? '' }}"
                                                   placeholder="—"
                                                   class="{{ $campo }} w-20 text-center font-mono tabular-nums placeholder:text-ink-4">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- ══════════ Voto base ══════════ --}}
        <section class="mb-10">
            <p class="eyebrow mb-3 text-rosso">Il voto base</p>

            <div class="border border-ink-4 bg-ink-2 p-5">
                <p class="mb-5 max-w-2xl text-sm leading-relaxed text-paper-dim">
                    I rating che arrivano dall'API sono compressi: quasi tutti fra 6,0 e 7,5. La molla
                    tiene fermo il centro e allarga le distanze, così la prestazione torna a pesare
                    quanto i bonus. Con <span class="font-mono text-paper">k = 1</span> è disattivata.
                </p>

                <div class="grid gap-4 sm:grid-cols-4">
                    @foreach (['centro' => 'Centro', 'k' => 'Molla (k)', 'min' => 'Minimo', 'max' => 'Massimo'] as $k => $et)
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">{{ $et }}</span>
                            <input type="number" step="0.1" name="voto_base[{{ $k }}]"
                                   value="{{ $valori['voto_base'][$k] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ══════════ Formazione ══════════ --}}
        <section class="mb-10">
            <p class="eyebrow mb-3 text-rosso">Formazione</p>

            <div class="border border-ink-4 bg-ink-2 p-5">
                <div class="grid gap-4 sm:grid-cols-4">
                    <label class="block">
                        <span class="eyebrow mb-1.5 block text-paper-dim">Senza voto</span>
                        <input type="number" step="0.5" name="senza_voto[default]"
                               value="{{ $valori['senza_voto']['default'] }}"
                               class="{{ $campo }} font-mono tabular-nums">
                    </label>
                    <label class="block">
                        <span class="eyebrow mb-1.5 block text-paper-dim">Senza voto · portiere</span>
                        <input type="number" step="0.5" name="senza_voto[P]"
                               value="{{ $valori['senza_voto']['P'] }}"
                               class="{{ $campo }} font-mono tabular-nums">
                    </label>
                    <label class="block">
                        <span class="eyebrow mb-1.5 block text-paper-dim">Sostituzioni max</span>
                        <input type="number" name="sostituzioni[max]"
                               value="{{ $valori['sostituzioni']['max'] }}"
                               class="{{ $campo }} font-mono tabular-nums">
                    </label>
                    <label class="block">
                        <span class="eyebrow mb-1.5 block text-paper-dim">Penalità dimenticanza</span>
                        <input type="number" step="0.5" name="formazione_mancante[penalita]"
                               value="{{ $valori['formazione_mancante']['penalita'] }}"
                               class="{{ $campo }} font-mono tabular-nums">
                    </label>
                </div>

                <p class="mt-4 max-w-2xl text-sm leading-relaxed text-paper-dim">
                    Il valore del portiere rimasto senza voto e senza riserva è ciò che dà mercato al
                    secondo portiere: senza penalità, non averlo non costerebbe niente e nessuno
                    scambierebbe mai per procurarselo.
                </p>
            </div>
        </section>

        {{-- ══════════ Power ══════════ --}}
        <section class="mb-10">
            <p class="eyebrow mb-3 text-rosso">Power score</p>

            <div class="border border-ink-4 bg-ink-2 p-5">
                <p class="mb-5 max-w-2xl text-sm leading-relaxed text-paper-dim">
                    È il parametro che decide <em class="not-italic text-paper">che gioco è</em>.
                    <strong class="text-paper">Forma</strong> alta rende le carte volatili — caccia al
                    giocatore del momento; forma bassa tiene la rarità vicina al valore reale, e il
                    mercato diventa di valori consolidati. I pesi devono sommare a 1.
                </p>

                <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach (['baseline' => 'Quotazione', 'fantamedia' => 'Fantamedia', 'forma' => 'Forma', 'titolarita' => 'Titolarità', 'rischio' => 'Rischio'] as $k => $et)
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">{{ $et }}</span>
                            <input type="number" step="0.05" min="0" max="1" name="power[pesi][{{ $k }}]"
                                   value="{{ $valori['power']['pesi'][$k] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                    @endforeach
                    <label class="block">
                        <span class="eyebrow mb-1.5 block text-paper-dim">Giornate forma</span>
                        <input type="number" name="power[giornate_forma]"
                               value="{{ $valori['power']['giornate_forma'] }}"
                               class="{{ $campo }} font-mono tabular-nums">
                    </label>
                </div>

                <p class="mt-3 font-mono text-[11px] tracking-wide text-paper-dim">
                    Il rischio si <span class="text-paper">sottrae</span>: pesa le assenze recenti.
                </p>

                {{-- ── Il fondo della piramide ──
                     Due soglie assolute sotto le quali la rarità non si decide
                     più per posizione. Servono perché il listone porta
                     centinaia di giocatori che non giocano mai: contati nei
                     percentili occupavano la fascia Comune e spingevano in alto
                     tutti gli altri, così «quasi tutte le carte sono Rare»
                     diventava vero senza che nessuna fosse migliorata. --}}
                <div class="mt-5 border-t border-ink-4/60 pt-4">
                    <p class="eyebrow mb-4 text-paper-dim">Il fondo della piramide</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">Pacco sotto</span>
                            <input type="number" step="0.1" min="0" max="100" name="power[soglie][pacco]"
                                   value="{{ $valori['power']['soglie']['pacco'] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">Monnezza sotto</span>
                            <input type="number" step="0.1" min="0" max="100" name="power[soglie][monnezza]"
                                   value="{{ $valori['power']['soglie']['monnezza'] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                    </div>

                    <p class="mt-4 text-sm leading-relaxed text-paper-dim">
                        Sul power, che va da 0 a 100. Sotto queste soglie la carta è
                        <span class="text-paper">Pacco</span> o <span class="text-paper">Monnezza</span> a
                        prescindere dalla posizione nel ruolo, e resta fuori dai percentili: le quote della
                        piramide si contano solo su chi sta sopra.
                    </p>

                    <p class="mt-3 font-mono text-[11px] leading-relaxed tracking-wide text-paper-dim">
                        ⚠️ Alla 1ª giornata non mordono: finché non si gioca, fantamedia e forma valgono
                        uguale per tutti e nessun power scende così in basso. Le due fasce compaiono man
                        mano che la stagione produce dati — cioè quando si sa davvero chi è uno scarto.
                    </p>
                </div>
            </div>
        </section>

        {{-- ══════════ Draft e sfide ══════════ --}}
        <section class="mb-10">
            <p class="eyebrow mb-3 text-rosso">Draft, sfide, calendario</p>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="border border-ink-4 bg-ink-2 p-5">
                    <p class="eyebrow mb-4 text-paper-dim">Busta</p>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">Giri</span>
                            <input type="number" name="draft[giri]" value="{{ $valori['draft']['giri'] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">Carte per busta</span>
                            <input type="number" name="draft[carte_per_busta]" value="{{ $valori['draft']['carte_per_busta'] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">Quota finestra</span>
                            <input type="number" step="0.01" name="draft[quota_finestra]" value="{{ $valori['draft']['quota_finestra'] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                    </div>
                    <p class="mt-4 text-sm leading-relaxed text-paper-dim">
                        Giri × carte è la dimensione della rosa:
                        <span class="font-mono text-paper">{{ $valori['draft']['giri'] * $valori['draft']['carte_per_busta'] }}</span>
                        carte. La quota è quanta parte della finestra fra due giornate spetta al draft;
                        il resto è mercato.
                    </p>

                    {{-- ── Quanto dura un turno ──
                         La durata vera è il tempo che resta diviso per i turni
                         che mancano: questi due numeri sono solo gli estremi
                         entro cui quel conto può muoversi. Il minimo è quello
                         che conta in una prova — è lui a decidere quanto si
                         aspetta quando la finestra è già cortissima. --}}
                    @php($turni = $valori['draft']['giri'] * count($stagione->partecipanti()))

                    <div class="mt-5 border-t border-ink-4/60 pt-4">
                        <p class="eyebrow mb-4 text-paper-dim">Durata di un turno</p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="eyebrow mb-1.5 block text-paper-dim">Minimo (minuti)</span>
                                <input type="number" min="1" max="1440" name="draft[turno_min_minuti]"
                                       value="{{ $valori['draft']['turno_min_minuti'] }}"
                                       class="{{ $campo }} font-mono tabular-nums">
                            </label>
                            <label class="block">
                                <span class="eyebrow mb-1.5 block text-paper-dim">Massimo (minuti)</span>
                                <input type="number" min="1" max="1440" name="draft[turno_max_minuti]"
                                       value="{{ $valori['draft']['turno_max_minuti'] }}"
                                       class="{{ $campo }} font-mono tabular-nums">
                            </label>
                        </div>

                        <p class="mt-4 text-sm leading-relaxed text-paper-dim">
                            Un turno dura il tempo che resta diviso per i turni che mancano, ma mai
                            fuori da questi due estremi. Con
                            <span class="font-mono text-paper">{{ $turni }}</span>
                            turni, il minimo da
                            <span class="font-mono text-paper">{{ $valori['draft']['turno_min_minuti'] }}</span>
                            minuti significa che un draft in cui nessuno si fa vivo dura al massimo
                            <span class="font-mono text-paper">{{ round($turni * $valori['draft']['turno_min_minuti'] / 60, 1) }}</span>
                            ore. <span class="text-paper">Per collaudare</span> si porta il minimo a un
                            minuto: le giornate si susseguono in pochi secondi.
                        </p>
                    </div>
                </div>

                <div class="border border-ink-4 bg-ink-2 p-5">
                    <p class="eyebrow mb-4 text-paper-dim">Sfida</p>

                    @php($gol = $valori['sfida']['gol'])

                    <label class="mb-4 flex items-center gap-2 text-sm text-paper-dim">
                        <input type="checkbox" name="sfida[gol][attivo]" value="1"
                               @checked($gol['attivo']) class="accent-rosso">
                        i fantapunti diventano gol
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">Primo gol a</span>
                            <input type="number" step="0.5" name="sfida[gol][prima_soglia]"
                                   value="{{ $gol['prima_soglia'] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                        <label class="block">
                            <span class="eyebrow mb-1.5 block text-paper-dim">Poi uno ogni</span>
                            <input type="number" step="0.5" name="sfida[gol][passo]"
                                   value="{{ $gol['passo'] }}"
                                   class="{{ $campo }} font-mono tabular-nums">
                        </label>
                    </div>

                    <p class="mt-4 text-sm leading-relaxed text-paper-dim">
                        Con {{ number_format($gol['prima_soglia'], 0, ',', '') }} e
                        {{ number_format($gol['passo'], 0, ',', '') }}:
                        <span class="font-mono text-paper">
                            @for ($n = 1; $n <= 4; $n++)
                                {{ number_format($gol['prima_soglia'] + ($n - 1) * $gol['passo'], 0, ',', '') }}→{{ $n }}@if ($n < 4), @endif
                            @endfor
                        </span>.
                        Sotto la prima soglia, zero.
                    </p>

                    <div class="mt-5 border-t border-ink-4/60 pt-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach (['soglia_pareggio' => 'Soglia pareggio', 'vittoria' => 'Vittoria', 'pareggio' => 'Pareggio', 'sconfitta' => 'Sconfitta'] as $k => $et)
                                <label class="block">
                                    <span class="eyebrow mb-1.5 block text-paper-dim">{{ $et }}</span>
                                    <input type="number" step="{{ $k === 'soglia_pareggio' ? '0.5' : '1' }}"
                                           name="sfida[{{ $k }}]" value="{{ $valori['sfida'][$k] }}"
                                           class="{{ $campo }} font-mono tabular-nums">
                                </label>
                            @endforeach
                        </div>

                        <p class="mt-4 text-sm leading-relaxed text-paper-dim">
                            Vittoria, pareggio e sconfitta sono i punti di classifica e valgono sempre.
                            La <span class="text-paper">soglia pareggio</span> serve solo a gol spenti:
                            in quel caso vince chi ha più fantapunti, e sotto quello scarto è pari.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-6 border border-ink-4 bg-ink-2 p-5">
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Gironi di campionato</span>
                    <input type="number" name="calendario[gironi]" value="{{ $valori['calendario']['gironi'] }}"
                           class="{{ $campo }} w-24 font-mono tabular-nums">
                </label>

                <label class="flex items-center gap-2 pb-2 text-sm text-paper-dim">
                    <input type="checkbox" name="modificatore_difesa[attivo]" value="1"
                           @checked($valori['modificatore_difesa']['attivo']) class="accent-rosso">
                    Modificatore di difesa
                </label>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-4">
            <button type="submit"
                    class="bg-rosso px-8 py-3 font-display text-2xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                Salva
            </button>

            @if ($ritoccati)
                <span class="font-mono text-[11px] uppercase tracking-[0.18em] text-paper-dim">
                    ritoccate rispetto ai valori di partenza
                </span>
            @endif
        </div>
    </form>

    {{-- ══════════ Fuori dal form principale ══════════ --}}
    <div class="mt-8 flex flex-wrap items-end gap-6 border-t border-ink-4/60 pt-6">
        @if ($altre->isNotEmpty())
            <form method="POST" action="{{ route('admin.regole.copia') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label class="block">
                    <span class="eyebrow mb-1.5 block text-paper-dim">Copia queste regole su</span>
                    <select name="verso" class="border border-ink-4 bg-ink px-3 py-2 text-sm outline-none focus:border-rosso">
                        @foreach ($altre as $s)
                            <option value="{{ $s->id }}">{{ $s->etichetta() }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit"
                        class="border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-rosso hover:text-rosso">
                    Copia
                </button>
            </form>
        @endif

        @if ($ritoccati)
            <form method="POST" action="{{ route('admin.regole.azzera') }}"
                  onsubmit="return confirm('Riporta {{ $stagione->etichetta() }} ai valori di partenza. Sicuro?')">
                @csrf
                <button type="submit" class="pb-2 text-sm text-paper-dim underline-offset-4 transition hover:text-rosso hover:underline">
                    Torna ai valori di partenza
                </button>
            </form>
        @endif
    </div>
</x-layouts.app>
