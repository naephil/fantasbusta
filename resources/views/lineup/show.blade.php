<x-layouts.app title="Formazione — Fantasbusta">
    @php
        // Al client serve la rosa in una forma sola: ruolo per filtrare gli
        // slot, cognome per leggerli, power per ordinare la tendina.
        $perJs = $rosa->map(fn (array $c) => [
            'id' => $c['id'],
            'role' => $c['role'],
            'last' => $c['last'],
            'club' => $c['club'],
            'power' => $c['power'],
            'tier' => $c['tier'],
        ])->sortByDesc('power')->values();
    @endphp

    <header class="mb-6">
        <p class="eyebrow mb-2 text-rosso">Formazione · giornata {{ $matchday }}</p>

        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            {{ $bloccata ? 'Giornata chiusa' : 'Schiera' }}
        </h1>
    </header>

    @if ($bloccata)
        <p class="mb-6 border border-ink-4 bg-ink-2 px-4 py-3 text-sm text-paper-dim">
            La finestra di questa giornata è chiusa{{ $scadenza ? ' da '.$scadenza->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) : '' }}:
            la formazione è quella che c'era, e non si tocca più.
        </p>
    @elseif ($scadenza)
        <p class="mb-6 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm text-paper-dim">
            Hai tempo fino a <span class="font-mono text-paper">{{ $scadenza->translatedFormat('D j M, H:i') }}</span>
            — {{ $scadenza->diffForHumans() }}.
        </p>
    @endif

    @error('formazione')
        <p class="mb-6 border-l-2 border-rosso bg-rosso/10 px-4 py-3 text-sm">{{ $message }}</p>
    @enderror

    {{-- ── Le partite per cui vale questa formazione ──

         ⚠️ Quelle della giornata che si sta schierando, non di quella in corso.
         È la distinzione che rende la sezione utile invece che decorativa: chi
         apre questa pagina decide per il weekend che deve ancora arrivare.

         E sono le partite DELLA PROPRIA ROSA, non il calendario di Serie A —
         quello si trova ovunque. Il calendario della propria rosa invece non
         esisteva da nessuna parte: per sapere chi dei tuoi giocava contro chi
         bisognava aprire il listone e cercare squadra per squadra.

         Richiudibile e chiusa di default: serve a un controllo al volo, non è
         il contenuto della pagina — che resta il campo. --}}
    @if ($partite->isNotEmpty())
        <details class="group mb-6 border border-ink-4 bg-ink-2" open>
            <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3">
                <span class="eyebrow text-paper-dim">
                    Serie A · la {{ $matchday }}ª
                </span>

                <span class="flex items-center gap-2 font-mono text-[10px] uppercase tracking-[0.18em] text-paper-dim">
                    <span class="group-open:hidden">mostra</span>
                    <span class="hidden group-open:inline">nascondi</span>
                    <svg viewBox="0 0 10 6" class="h-1.5 w-2.5 transition group-open:rotate-180" aria-hidden="true">
                        <path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" />
                    </svg>
                </span>
            </summary>

            <div class="divide-y divide-ink-4/40 border-t border-ink-4/60">
                @foreach ($partite as $p)
                    {{-- ⚠️ Blocco e non forma breve con le parentesi: quella si
                         è già rotta due volte in questo progetto, emettendo un
                         tag PHP aperto senza chiusura che ingoia il resto del
                         file. `VisteTest` monta la guardia su entrambe le
                         trappole — vedi il commento lì. --}}
                    @php
                        $f = $p['fixture'];
                    @endphp

                    <div class="px-3 py-2.5 sm:px-4 {{ $p['casa']->isEmpty() && $p['fuori']->isEmpty() ? 'opacity-45' : '' }}">
                        <div class="flex items-center gap-2 text-sm sm:gap-3">
                            <span class="min-w-0 flex-1 truncate text-right font-display text-base font-bold uppercase leading-none sm:text-lg">
                                {{ $f->homeTeam?->name ?? '—' }}
                            </span>

                            <span class="shrink-0 font-mono text-[10px] text-paper-dim">
                                {{ $f->kickoff_at?->format('d/m H:i') ?? '—' }}
                            </span>

                            <span class="min-w-0 flex-1 truncate font-display text-base font-bold uppercase leading-none sm:text-lg">
                                {{ $f->awayTeam?->name ?? '—' }}
                            </span>
                        </div>

                        @if ($p['casa']->isNotEmpty() || $p['fuori']->isNotEmpty())
                            <div class="mt-1.5 flex items-start gap-2 text-[11px] leading-snug sm:gap-3 sm:text-xs">
                                @foreach ([[$p['casa'], 'text-right'], [$p['fuori'], '']] as [$mie, $allineamento])
                                    <span class="min-w-0 flex-1 {{ $allineamento }}">
                                        @foreach ($mie as $carta)
                                            <span class="block truncate">
                                                <span class="font-display font-black"
                                                      style="color: var(--r-{{ $carta->role }})">{{ $carta->role }}</span>
                                                <x-nome-giocatore :carta="$carta->id">{{ $carta->player->last_name }}</x-nome-giocatore>
                                            </span>
                                        @endforeach
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="border-t border-ink-4/60 px-4 py-2.5 font-mono text-[11px] leading-relaxed tracking-wide text-paper-dim">
                Prima le partite che ti riguardano. Le altre restano in fondo, smorzate:
                servono a sapere che ci sono, non a essere lette.
            </p>
        </details>
    @endif

    <form method="POST" action="{{ route('lineup.store') }}" data-formazione
          data-rosa='@json($perJs)'
          data-moduli='@json($moduli)'
          data-schierabili='@json(array_values($schierabili))'
          data-titolari='@json(old('titolari', $titolari))'
          data-panchina='@json(old('panchina', $panchina))'
          data-modulo="{{ old('module', $lineup?->module) }}"
          @if ($bloccata) data-bloccata @endif>
        @csrf

        {{-- ── Modulo ── --}}
        <section class="mb-6">
            <p class="eyebrow mb-3 text-paper-dim">Modulo</p>

            <div class="flex flex-wrap gap-2">
                @foreach ($moduli as $nome => $reparti)
                    @php $ok = in_array($nome, $schierabili, true); @endphp
                    <label class="{{ $ok && ! $bloccata ? 'cursor-pointer' : 'cursor-not-allowed opacity-35' }}"
                           @unless ($ok) title="La rosa non lo compone" @endunless>
                        <input type="radio" name="module" value="{{ $nome }}" class="peer sr-only"
                               @checked(old('module', $lineup?->module) === $nome)
                               @disabled(! $ok || $bloccata)>
                        <span class="block border border-ink-4 px-4 py-2 font-display text-xl font-black tracking-wide transition peer-checked:border-rosso peer-checked:bg-rosso peer-checked:text-paper">
                            {{ $nome }}
                        </span>
                    </label>
                @endforeach
            </div>

            @if ($schierabili === [])
                <p class="mt-3 border-l-2 border-rosso bg-ink-2 px-3 py-2 text-sm">
                    Con questa rosa non si compone nessuno dei sette moduli.
                    Serve uno scambio: {{ collect($conteggi)->map(fn ($n, $r) => "{$n}{$r}")->implode(' · ') }}.
                </p>
            @endif
        </section>

        {{-- ── Il campo ──
             Undici caselle, una per posto del modulo, ognuna con la sua tendina
             ristretta al ruolo giusto. Prima si spuntavano le carte da un elenco:
             era facilissimo ritrovarsi con dodici titolari o tre difensori di
             troppo, scoprirlo solo al salvataggio e non capire dove. Qui il
             modulo è rispettato per costruzione. --}}
        <section class="mb-6">
            <div class="campo" data-campo>
                <div class="campo-linee" aria-hidden="true"></div>
                {{-- Le caselle le costruisce lo script: dipendono dal modulo,
                     e ricostruirle è più onesto che nasconderne settantasette. --}}
            </div>
        </section>

        {{-- ── Il magazzino delle carte ──

             Le carte vere della rosa, rese qui una volta sola e tenute fuori
             schermo: lo script ne clona una dentro la casella quando scegli il
             giocatore. Sul campo si vedono le CARTE, non i nomi.

             ⚠️ Rese dal server e non chieste a richiesta, ed è la differenza
             fra fattibile e no. `CartaController` serve una carta per volta
             apposta — su una pagina come la giornata di Serie A ce ne
             vorrebbero cinquecento — ma qui la rosa è già stata passata tutta
             dal presenter per riempire le tendine: i dati sono in mano, e
             renderle costa zero query e zero richieste in più. Undici caselle
             che chiedessero la propria carta al server sarebbero invece undici
             richieste a ogni cambio di modulo.

             `aria-hidden` perché è un deposito, non contenuto: quello che conta
             per chi legge con la voce sono le tendine, che restano etichettate. --}}
        <div class="carte-pronte" data-magazzino aria-hidden="true">
            @foreach ($rosa as $id => $c)
                <div data-carta-di="{{ $id }}">
                    <x-carta :carta="$c" larghezza="100%" />
                </div>
            @endforeach
        </div>

        {{-- ── Panchina ── --}}
        <section class="mb-6">
            <p class="eyebrow mb-1 text-paper-dim">Panchina</p>
            <p class="mb-3 max-w-2xl text-sm leading-relaxed text-paper-dim">
                L'ordine non è decorativo: un titolare senza voto viene rimpiazzato dalla
                <em class="not-italic text-paper">prima</em> carta in panchina del suo ruolo.
                Le carte si pescano prima che escano le formazioni ufficiali, quindi capita spesso.
            </p>

            <div class="space-y-1.5" data-panchina></div>
        </section>

        {{-- ── Barra di salvataggio ──
             Appiccicata in fondo allo schermo, e non è un vezzo: prima il
             pulsante stava in coda a una pagina lunga, insieme al messaggio di
             esito. Si salvava senza vedere niente e sembrava che non funzionasse. --}}
        @unless ($bloccata)
            <div class="sticky bottom-0 -mx-6 flex flex-wrap items-center gap-4 border-t border-ink-4 bg-ink/95 px-6 py-4 backdrop-blur">
                <button type="submit" data-salva
                        class="bg-rosso px-8 py-3 font-display text-2xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo disabled:cursor-not-allowed disabled:opacity-40">
                    Salva formazione
                </button>

                <p class="font-mono text-[11px] uppercase tracking-[0.2em]" data-stato></p>
            </div>
        @endunless
    </form>

    <script>
        (() => {
            const form = document.querySelector('[data-formazione]');
            if (!form) return;

            const rosa = JSON.parse(form.dataset.rosa);
            const moduli = JSON.parse(form.dataset.moduli);
            const schierabili = JSON.parse(form.dataset.schierabili);
            const bloccata = form.hasAttribute('data-bloccata');

            const campo = form.querySelector('[data-campo]');
            const panchina = form.querySelector('[data-panchina]');
            const stato = form.querySelector('[data-stato]');
            const salva = form.querySelector('[data-salva]');

            const perId = new Map(rosa.map((c) => [String(c.id), c]));
            const ORDINE = ['P', 'D', 'C', 'A'];

            // Chi era schierato prima, per ricostruire la formazione salvata.
            let scelte = [];
            const salvati = JSON.parse(form.dataset.titolari) || [];
            const panchinaSalvata = (JSON.parse(form.dataset.panchina) || []).map(String);

            const moduloScelto = () => form.querySelector('input[name="module"]:checked')?.value;

            /** I posti del modulo, dal portiere agli attaccanti. */
            const posti = (modulo) => {
                const reparti = moduli[modulo];
                if (!reparti) return [];

                return [
                    'P',
                    ...Array(reparti.D).fill('D'),
                    ...Array(reparti.C).fill('C'),
                    ...Array(reparti.A).fill('A'),
                ];
            };

            /** Chi può occupare questo posto: stesso ruolo e non già schierato. */
            const disponibili = (ruolo, indice) =>
                rosa.filter((c) => c.role === ruolo
                    && (scelte[indice] === String(c.id) || !scelte.includes(String(c.id))));

            const disegna = () => {
                const modulo = moduloScelto();

                // A formazione bloccata il campo lo dichiara, e il CSS toglie le
                // tendine: restano le carte, che è come si guarda una formazione
                // già schierata invece che una da comporre.
                campo.toggleAttribute('data-bloccato', bloccata);

                campo.innerHTML = '<div class="campo-linee" aria-hidden="true"></div>';

                if (!modulo) {
                    campo.insertAdjacentHTML('beforeend',
                        '<p class="campo-vuoto">Scegli un modulo per disporre la squadra.</p>');
                    aggiornaStato();
                    return;
                }

                const lista = posti(modulo);

                // Una riga per reparto: il portiere in basso, gli attaccanti in
                // alto, come si guarda un campo alla televisione.
                ORDINE.forEach((ruolo) => {
                    const indici = lista.map((r, i) => (r === ruolo ? i : -1)).filter((i) => i >= 0);
                    if (!indici.length) return;

                    const riga = document.createElement('div');
                    riga.className = 'campo-riga';
                    riga.dataset.ruolo = ruolo;

                    indici.forEach((i) => riga.appendChild(casella(ruolo, i)));
                    campo.appendChild(riga);
                });

                aggiornaPanchina();
                aggiornaStato();
            };

            /**
             * La carta vera per una casella, clonata dal magazzino.
             *
             * ⚠️ Si CLONA e non si sposta: la stessa carta può passare da un
             * posto all'altro a ogni cambio di modulo, e spostando il nodo
             * originale il magazzino si svuoterebbe — la seconda volta che
             * serve non ci sarebbe più.
             */
            const cartaDi = (cardId) => {
                const originale = form.querySelector(`[data-carta-di="${cardId}"] .slot`);

                return originale ? originale.cloneNode(true) : null;
            };

            const casella = (ruolo, indice) => {
                const posto = document.createElement('label');
                posto.className = 'posto';
                posto.dataset.ruolo = ruolo;

                const select = document.createElement('select');
                select.name = 'titolari[]';
                select.className = 'posto-scelta';
                select.disabled = bloccata;

                const vuoto = new Option('— vuoto —', '');
                select.appendChild(vuoto);

                disponibili(ruolo, indice)
                    .forEach((c) => {
                        // Il power sta nell'etichetta e non solo nell'ordine:
                        // la tendina è già ordinata per power, ma «primo della
                        // lista» non dice DI QUANTO — e fra due centrocampisti
                        // vicini è quella la differenza su cui si sceglie.
                        const opt = new Option(`${c.last} · ${c.club} · pw ${c.power}`, String(c.id));
                        opt.selected = scelte[indice] === String(c.id);
                        select.appendChild(opt);
                    });

                select.value = scelte[indice] ?? '';

                select.addEventListener('change', () => {
                    scelte[indice] = select.value;
                    disegna();
                });

                const pastiglia = Object.assign(document.createElement('span'), {
                    className: 'posto-ruolo', textContent: ruolo,
                });

                /*
                 * ── La carta in campo ──
                 *
                 * Il posto occupato mostra LA CARTA, non il nome del giocatore.
                 * È la cosa più bella del gioco e finora si vedeva solo al
                 * draft: il campo — l'altro momento in cui si guarda la propria
                 * rosa con attenzione — era una griglia di tendine.
                 *
                 * Compare appena si sceglie dalla tendina, non al salvataggio:
                 * schierare diventa comporre una figurina alla volta, e il
                 * campo si riempie sotto gli occhi mentre si decide.
                 */
                const vetrina = document.createElement('span');
                vetrina.className = 'posto-carta';

                const carta = scelte[indice] ? cartaDi(scelte[indice]) : null;

                if (carta) {
                    vetrina.appendChild(carta);
                    posto.classList.add('pieno');

                    // L'anteprima grande resta: la carta in campo è piccola per
                    // forza — undici in quattro righe — e le statistiche si
                    // leggono avvicinandola.
                    posto.dataset.carta = scelte[indice];
                } else {
                    // Il posto vuoto tiene la sagoma di una carta, invece di
                    // afflosciarsi: così il campo non balla a ogni scelta, e si
                    // vede a colpo d'occhio quanti posti restano.
                    vetrina.appendChild(Object.assign(document.createElement('span'), {
                        className: 'posto-vuoto', textContent: ruolo,
                    }));
                }

                posto.append(vetrina, pastiglia, select);

                return posto;
            };

            // L'ordine scelto a mano, che deve sopravvivere a ogni ridisegno:
            // è l'unica cosa che il manager controlla sulle sostituzioni, e
            // ricalcolarlo da capo a ogni cambio di titolare la vanificherebbe.
            let ordinePanca = panchinaSalvata.slice();

            const aggiornaPanchina = () => {
                const fuori = rosa.filter((c) => !scelte.includes(String(c.id)));

                // Chi era già in un ordine lo mantiene; i nuovi arrivati si
                // accodano per reparto, che è meglio di un ordine a caso.
                fuori.sort((a, b) => {
                    const p = ordinePanca.indexOf(String(a.id));
                    const q = ordinePanca.indexOf(String(b.id));
                    if (p >= 0 && q >= 0) return p - q;
                    if (p >= 0) return -1;
                    if (q >= 0) return 1;
                    return ORDINE.indexOf(a.role) - ORDINE.indexOf(b.role);
                });

                ordinePanca = fuori.map((c) => String(c.id));
                panchina.innerHTML = '';

                fuori.forEach((c, i) => {
                    const riga = document.createElement('div');
                    riga.className = 'panca-riga';

                    riga.innerHTML = `
                        <span class="panca-ordine">${i + 1}</span>
                        <span class="ruolo-pill panca-ruolo" data-ruolo="${c.role}">${c.role}</span>
                        <span class="panca-nome anteprima-carta" data-carta="${c.id}" tabindex="0">${c.last}</span>
                        <span class="panca-club">${c.club}</span>
                    `;

                    if (!bloccata) {
                        riga.appendChild(freccia('↑', i, i - 1, i === 0));
                        riga.appendChild(freccia('↓', i, i + 1, i === fuori.length - 1));
                    }

                    const campoOrdine = document.createElement('input');
                    campoOrdine.type = 'hidden';
                    campoOrdine.name = 'panchina[]';
                    campoOrdine.value = String(c.id);
                    riga.appendChild(campoOrdine);

                    panchina.appendChild(riga);
                });
            };

            const freccia = (simbolo, da, a, disabilitata) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'panca-freccia';
                b.textContent = simbolo;
                b.disabled = disabilitata;
                b.setAttribute('aria-label', simbolo === '↑' ? 'Sposta su' : 'Sposta giù');

                b.addEventListener('click', () => {
                    [ordinePanca[da], ordinePanca[a]] = [ordinePanca[a], ordinePanca[da]];
                    aggiornaPanchina();
                });

                return b;
            };

            /** Quanti posti restano scoperti, detto sul pulsante. */
            const aggiornaStato = () => {
                if (!stato || !salva) return;

                const modulo = moduloScelto();

                if (!modulo) {
                    stato.textContent = 'nessun modulo scelto';
                    stato.className = 'font-mono text-[11px] uppercase tracking-[0.2em] text-paper-dim';
                    salva.disabled = true;
                    return;
                }

                const totale = posti(modulo).length;
                const pieni = scelte.filter(Boolean).length;
                const mancano = totale - pieni;

                salva.disabled = mancano > 0;
                stato.className = 'font-mono text-[11px] uppercase tracking-[0.2em] '
                    + (mancano > 0 ? 'text-rosso' : 'text-paper-dim');
                stato.textContent = mancano > 0
                    ? `${mancano} ${mancano === 1 ? 'posto scoperto' : 'posti scoperti'}`
                    : `${totale} schierati · pronto`;
            };

            form.querySelectorAll('input[name="module"]').forEach((el) => {
                el.addEventListener('change', () => { scelte = []; ricostruisci(); });
            });

            /** Rimette in campo quelli che c'erano, nei posti del loro ruolo. */
            const ricostruisci = () => {
                const modulo = moduloScelto();
                if (!modulo) return disegna();

                const lista = posti(modulo);
                scelte = Array(lista.length).fill('');

                // Si assegna reparto per reparto: chi era titolare torna nel
                // primo posto libero del suo ruolo, il resto va in panchina.
                const rimasti = salvati.map(String).filter((id) => perId.has(id));

                lista.forEach((ruolo, i) => {
                    const j = rimasti.findIndex((id) => perId.get(id).role === ruolo);
                    if (j >= 0) scelte[i] = rimasti.splice(j, 1)[0];
                });

                disegna();
            };

            // Se non c'era una formazione salvata si parte dal primo modulo
            // componibile: una pagina che apre vuota non dice cosa fare.
            if (!moduloScelto() && schierabili.length && !bloccata) {
                const primo = form.querySelector(`input[name="module"][value="${schierabili[0]}"]`);
                if (primo) primo.checked = true;
            }

            ricostruisci();
        })();
    </script>
</x-layouts.app>
