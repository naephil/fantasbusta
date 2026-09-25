<x-layouts.app title="Gestione — Fantasbusta">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">Amministrazione</p>
        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            Gestione
        </h1>
        <p class="mt-4 max-w-2xl border-l-2 border-rosso pl-3 text-sm leading-relaxed text-paper-dim">
            Due piani distinti. Le <strong class="text-paper">annate di Serie A</strong> sono dati del
            mondo: si scaricano una volta e le usano tutti i gruppi. Le
            <strong class="text-paper">stagioni</strong> sono la partita — carte, sfide, classifica — e
            ognuna sceglie che annata giocare.
        </p>
    </header>

    {{-- ══════════ Il battito del cron ══════════

         L'unico pezzo che vive fuori dall'applicazione, e l'unico che quando
         manca non dà nessun errore: il draft smette di avanzare da solo e
         sembra soltanto che il gioco sia lento. Sta qui perché verificarlo non
         deve richiedere di collegarsi al server. --}}
    <p class="mb-8 flex flex-wrap items-baseline gap-x-3 gap-y-1 border-l-2 pl-3 font-mono text-[11px] uppercase tracking-[0.18em]
              {{ $battito['stato'] === 'vivo' ? 'border-ink-4 text-paper-dim' : 'border-rosso text-rosso' }}">
        <span class="{{ $battito['stato'] === 'vivo' ? '' : 'font-bold' }}">
            @if ($battito['stato'] === 'vivo')
                Cron attivo · ultimo giro {{ $battito['eta'] }}
            @elseif ($battito['stato'] === 'fermo')
                Cron fermo · ultimo giro {{ $battito['eta'] }}
            @else
                Cron mai partito
            @endif
        </span>

        @if ($battito['stato'] !== 'vivo')
            <span class="normal-case tracking-normal text-paper-dim">
                Il draft non avanza da solo: i turni scaduti restano lì finché qualcuno non sbusta.
                @if ($battito['stato'] === 'mai')
                    Se l'hai appena installato, ricarica fra un minuto.
                @endif
            </span>
        @endif
    </p>

    @foreach (['annata', 'listone', 'stagione'] as $campo)
        @error($campo)
            <p class="mb-6 border-l-2 border-rosso bg-ink-2 px-4 py-3 text-sm">{{ $message }}</p>
        @enderror
    @endforeach

    {{-- Chi l'import non ha agganciato, per nome.
         Il conteggio da solo non si può usare: «61 senza corrispondenza» non
         dice se manca mezzo Sassuolo o sessanta riserve sparse, e le due cose
         vogliono rimedi opposti. Con i nomi sotto gli occhi si riconosce in due
         secondi se è l'annata sbagliata o solo la coda dei mai convocati. --}}
    @foreach ([
        'listoneMancanti' => ['Senza corrispondenza in anagrafica', 'Se sono tanti e famosi, l\'annata caricata non è quella del listone. Se sono pochi e sconosciuti, è normale: sono giocatori che l\'API non ha in rosa.'],
        'listoneAmbigui' => ['Troppi omonimi per decidere', 'Il cognome corrisponde a più giocatori e la squadra non li separa. Si sistemano a mano dal listone.'],
    ] as $chiave => [$titolo, $spiegazione])
        @if (session($chiave))
            <details class="mb-6 border border-ink-4 bg-ink-2">
                <summary class="cursor-pointer px-4 py-3 text-sm">
                    <span class="font-mono text-rosso">{{ count(session($chiave)) }}</span>
                    · {{ $titolo }}
                </summary>

                <div class="border-t border-ink-4/60 px-4 py-3">
                    <p class="mb-3 max-w-2xl text-xs leading-relaxed text-paper-dim">{{ $spiegazione }}</p>

                    <p class="font-mono text-[11px] leading-relaxed text-paper-dim">
                        {{ collect(session($chiave))->implode(' · ') }}
                    </p>
                </div>
            </details>
        @endif
    @endforeach

    {{-- ══════════ Le stagioni del gruppo ══════════ --}}
    <section class="mb-14">
        <p class="eyebrow mb-3 text-rosso">Le stagioni di «{{ $league->name }}»</p>

        @forelse ($stagioni as $r)
            @php($s = $r['stagione'])
            <article class="mb-4 border p-5 {{ $s->id === $attiva ? 'border-rosso bg-ink-2' : 'border-ink-4 bg-ink-2' }}">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 class="font-display text-3xl font-black uppercase leading-none">
                        {{ $s->etichetta() }}
                    </h2>

                    <span class="font-mono text-[11px] uppercase tracking-[0.18em]
                                 {{ $s->state === 'in_corso' ? 'text-rosso' : 'text-paper-dim' }}">
                        {{ str_replace('_', ' ', $s->state) }}
                        @if ($s->id === $attiva) · in vista @endif
                    </span>
                </div>

                <p class="mt-3 font-mono text-[11px] uppercase tracking-[0.18em] text-paper-dim">
                    {{ count($r['giocate']) }} di {{ $r['totali'] ?: '?' }} giornate ·
                    {{ $r['turni'] }} turni di calendario · parte dalla {{ $s->start_matchday }}ª
                </p>

                @if ($r['totali'])
                    <div class="mt-3 flex flex-wrap gap-1">
                        @foreach (range(1, $r['totali']) as $g)
                            <span title="Giornata {{ $g }}"
                                  class="size-5 border text-center font-mono text-[9px] leading-[1.15rem]
                                         {{ in_array($g, $r['giocate'], true)
                                            ? 'border-rosso bg-rosso text-paper'
                                            : ((int) $g === (int) $r['prossima'] ? 'border-rosso text-rosso' : 'border-ink-4 text-paper-dim') }}">
                                {{ $g }}
                            </span>
                        @endforeach
                    </div>
                @endif

                {{-- Il vuoto di fine calendario: si pesca e si schiera, ma non
                     c'è più niente in palio e la classifica resta ferma.
                     Senza dirlo, ci si accorge solo quando i punti smettono
                     di muoversi. --}}
                @if ($r['scoperte'] > 0)
                    <p class="mt-4 border-l-2 border-rosso bg-ink px-3 py-2 text-sm leading-relaxed text-paper-dim">
                        Il campionato copre {{ $r['turni'] > 0 ? 'fino alla '.($r['totali'] - $r['scoperte']).'ª' : 'zero giornate' }},
                        ma l'annata ne ha {{ $r['totali'] }}:
                        <strong class="text-paper">{{ $r['scoperte'] }} giornate senza sfide</strong>.
                        Si pescherebbe e si schiererebbe a vuoto. Con
                        {{ $s->league->managers()->where('active', true)->count() }}
                        squadre servono più gironi: si cambia in
                        <a href="{{ route('admin.regole.edit') }}" class="text-rosso underline-offset-4 hover:underline">Regole</a>
                        prima di generare il calendario, oppure si riempie il resto con un torneo.
                    </p>
                @endif

                <div class="mt-5 flex flex-wrap items-end gap-3">
                    {{-- Il calendario si genera prima di cominciare: rifarlo dopo
                         cancellerebbe risultati già acquisiti, e il servizio si
                         rifiuta di farlo. --}}
                    @if ($r['turni'] === 0)
                        <form method="POST" action="{{ route('admin.stagione.calendario', $s) }}">
                            @csrf
                            <button type="submit"
                                    class="border border-rosso px-4 py-2 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                                Genera calendario
                            </button>
                        </form>
                    @endif

                    @if ($s->state === 'preparazione' && $r['turni'] > 0)
                        <form method="POST" action="{{ route('admin.stagione.avvia', $s) }}">
                            @csrf
                            <button type="submit"
                                    class="bg-rosso px-6 py-2 font-display text-lg font-black uppercase tracking-wide text-paper transition hover:bg-rosso-vivo">
                                Avvia
                            </button>
                        </form>
                    @endif

                    {{-- ── Azzera, che non è cancella ──
                         Ricominciare da capo e buttare via la stagione sono due
                         cose diverse: la prima tiene in piedi regole tarate e
                         giornata di partenza, la seconda porta via anche
                         quelle. Stanno una accanto all'altra perché è lì che
                         serve sceglierle, ma la distinzione la deve dire il
                         pulsante, non l'esperienza. --}}
                    <form method="POST" action="{{ route('admin.stagione.azzera', $s) }}"
                          onsubmit="return confirm('Azzera {{ $s->etichetta() }}: via classifica, sfide, carte, draft, tornei, formazioni e scambi. Le regole e la giornata di partenza restano, le statistiche di Serie A anche. Sicuro?')">
                        @csrf
                        <button type="submit"
                                class="border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-rosso hover:text-rosso">
                            Azzera
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.stagione.elimina', $s) }}"
                          onsubmit="return confirm('Cancella carte, draft, sfide e classifica di {{ $s->etichetta() }}. Sicuro?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-sm text-paper-dim underline-offset-4 transition hover:text-rosso hover:underline">
                            Cancella
                        </button>
                    </form>
                </div>

                <p class="mt-3 text-sm leading-relaxed text-paper-dim">
                    <span class="text-paper">Azzera</span> riporta la stagione al giorno zero tenendo
                    regole e giornata di partenza: si rigenera il calendario e si ricomincia.
                    <span class="text-paper">Cancella</span> porta via anche quelle. Nessuna delle due
                    tocca le statistiche di Serie A, che sono di tutti i gruppi.
                </p>

                {{-- ── Il draft in sospeso ──
                     Sta PRIMA del pulsante «avanti» perché è il suo prerequisito:
                     giocare una giornata col draft aperto produce formazioni
                     vuote, e nessuno capirebbe perché. --}}
                @if ($r['draft'])
                    @php($d = $r['draft'])
                    <div class="mt-5 border-t border-ink-4/60 pt-5">
                        <p class="font-display text-2xl font-black uppercase leading-none">
                            Draft della {{ $d['matchday'] }}ª · {{ $d['fatti'] }}/{{ $d['totali'] }} turni
                        </p>

                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-paper-dim">
                            Finché non è concluso, le rose della {{ $d['matchday'] }}ª non esistono e
                            giocarla non produrrebbe nessuna formazione. Nel gioco vero i turni li
                            smaltisce il cron man mano che scadono; qui si può bruciare tutto adesso.
                        </p>

                        <form method="POST" action="{{ route('admin.stagione.draft', $s) }}" class="mt-4">
                            @csrf
                            <button type="submit"
                                    class="border border-rosso px-6 py-2.5 font-display text-xl font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                                Sbusta tutto d'ufficio
                            </button>
                        </form>
                    </div>
                @endif

                {{-- ── La giornata, nei suoi tre momenti ──

                     Prima era un pulsante solo che faceva tutto: statistiche,
                     voti, sfide, classifica e draft. Ma i risultati veri
                     arrivano alla spicciolata — sabato alle 15, domenica sera,
                     il lunedì — e fra «è cominciata» e «è finita» c'è una
                     finestra lunga giorni in cui i voti si guardano e la
                     classifica non si deve muovere.

                     Sono tre pulsanti perché sono tre momenti, e mostrarli
                     tutti insieme sarebbe peggio: si vede quello che ha senso
                     adesso. --}}
                @if ($s->state === 'in_corso' && $r['inCorso'])
                    @php($g = $r['inCorso'])

                    <div class="mt-5 border-t border-ink-4/60 pt-5">
                        <p class="font-display text-2xl font-black uppercase leading-none text-rosso">
                            La {{ $g }}ª è in corso
                        </p>

                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-paper-dim">
                            Le formazioni sono congelate e il draft della {{ $g + 1 }}ª è aperto.
                            Scarica i voti quante volte vuoi mentre arrivano: la classifica non si
                            muove finché non chiudi.
                        </p>

                        {{-- ── Cosa c'è già addosso a questa giornata ──

                             ⚠️ Le statistiche VERE sopravvivono a ogni azzeramento di
                             stagione — costano chiamate all'API e sono condivise fra i
                             gruppi — quindi una giornata scaricata mesi fa resta
                             scaricata anche dopo aver riportato la lega al foglio
                             bianco. Chi poi prova a simularla si sentiva rispondere
                             che ci sono già i voti veri, senza modo di sapere né da
                             dove venissero né quanto fossero completi.

                             Il conto delle partite coperte è la voce che conta: un
                             download interrotto a metà lascia una giornata che esiste
                             ma è mezza vuota, ed è quella che fa dire «a una squadra
                             manca mezzo tabellino». --}}
                        @if ($r['dati'] && ($r['dati']['reali'] > 0 || $r['dati']['simulate'] > 0))
                            @php($d = $r['dati'])
                            <p class="mt-4 border-l-2 pl-3 text-sm leading-relaxed
                                      {{ $d['partiteConDati'] < $d['partite'] ? 'border-rosso text-paper' : 'border-ink-4 text-paper-dim' }}">
                                @if ($d['reali'] > 0)
                                    Ha già <strong>{{ $d['reali'] }} statistiche vere</strong>
                                @else
                                    Ha <strong>{{ $d['simulate'] }} statistiche simulate</strong>
                                @endif
                                · {{ $d['partiteConDati'] }} partite coperte su {{ $d['partite'] }}.

                                @if ($d['partiteConDati'] < $d['partite'])
                                    <span class="block mt-1 text-paper-dim">
                                        Il tabellone di Serie A mostrerà mezze squadre: lo scarico si è
                                        fermato prima della fine, oppure quelle partite non erano ancora
                                        finite. Rilancia senza «simula», o riscrivi tutto da capo con la
                                        spunta qui sotto.
                                    </span>
                                @endif
                            </p>
                        @endif

                        {{-- ② I parziali, rilanciabili --}}
                        <form method="POST" action="{{ route('admin.stagione.parziali', $s) }}" class="mt-4">
                            @csrf
                            <input type="hidden" name="giornata" value="{{ $g }}">

                            <div class="flex flex-wrap items-center gap-3">
                                <button type="submit"
                                        class="border border-rosso px-5 py-2 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                                    Aggiorna i voti
                                </button>

                                <label class="flex items-center gap-2 text-sm text-paper-dim">
                                    <input type="checkbox" name="simula" value="1" class="accent-rosso">
                                    Simula invece di scaricare
                                </label>

                                <label class="flex items-center gap-2 text-sm text-paper-dim">
                                    <input type="checkbox" name="tutte" value="1" class="accent-rosso">
                                    Tutte le partite, anche quelle non finite
                                </label>
                            </div>

                            {{-- La forzatura compare SOLO quando c'è qualcosa di vero da
                                 buttare. Sempre visibile sarebbe un piede di porco accanto
                                 a una porta aperta: la si spunterebbe per abitudine, e un
                                 giorno cancellerebbe una giornata scaricata sul serio. --}}
                            @if ($r['dati'] && $r['dati']['reali'] > 0)
                                <label class="mt-3 flex items-start gap-2 border border-rosso/40 bg-ink px-3 py-2 text-sm text-paper-dim">
                                    <input type="checkbox" name="sovrascrivi" value="1" class="mt-1 accent-rosso">
                                    <span>
                                        <strong class="text-paper">Riscrivi sopra le statistiche vere.</strong>
                                        Serve per simulare una giornata già scaricata: i
                                        {{ $r['dati']['reali'] }} voti veri vengono buttati e rifatti.
                                        Riscaricarli costa {{ $r['costo'] }} chiamate.
                                    </span>
                                </label>
                            @endif

                            <p class="mt-3 font-mono text-[11px] tracking-wide text-paper-dim">
                                Costo: <span class="text-paper">fino a {{ $r['costo'] }} chiamate</span> — una per
                                partita. Le partite non ancora finite si saltano, a meno che non spunti «tutte».
                            </p>
                        </form>

                        {{-- ③ La chiusura --}}
                        <form method="POST" action="{{ route('admin.stagione.chiudi', $s) }}" class="mt-5"
                              onsubmit="return confirm('Chiude la {{ $g }}ª: sfide, classifica e tornei. Da qui i punti sono punti. Sicuro?')">
                            @csrf
                            <input type="hidden" name="giornata" value="{{ $g }}">

                            <button type="submit"
                                    class="bg-rosso px-8 py-2.5 font-display text-xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                                Chiudi la {{ $g }}ª
                            </button>

                            <span class="ml-3 text-sm text-paper-dim">
                                Formazioni d'ufficio, sfide, classifica e tornei.
                            </span>
                        </form>
                    </div>
                @elseif ($s->state === 'in_corso' && $r['prossima'])
                    {{-- ① Il via --}}
                    <form method="POST" action="{{ route('admin.stagione.inizia', $s) }}"
                          class="mt-5 border-t border-ink-4/60 pt-5">
                        @csrf
                        <input type="hidden" name="giornata" value="{{ $r['prossima'] }}">

                        <p class="font-display text-2xl font-black uppercase leading-none">
                            La {{ $r['prossima'] }}ª è cominciata
                        </p>

                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-paper-dim">
                            Congela le formazioni della {{ $r['prossima'] }}ª e apre il draft della
                            <strong class="text-paper">{{ $r['prossima'] + 1 }}ª</strong>. Da premere
                            quando comincia la prima partita vera.
                        </p>

                        <div class="mt-4 flex flex-wrap items-end gap-5">
                            <div>
                                <label for="ore-{{ $s->id }}" class="eyebrow mb-2 block text-paper-dim">Draft aperto per</label>
                                <div class="flex items-center gap-2">
                                    <input id="ore-{{ $s->id }}" name="ore" type="number" min="1" max="336" value="48"
                                           class="w-20 border border-ink-4 bg-ink px-2 py-1.5 text-center font-mono outline-none focus:border-rosso">
                                    <span class="text-sm text-paper-dim">ore</span>
                                </div>
                            </div>

                            <button type="submit"
                                    class="bg-rosso px-8 py-2.5 font-display text-xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo">
                                Via
                            </button>
                        </div>
                    </form>
                @elseif ($s->state === 'in_corso')
                    <p class="mt-5 border-t border-ink-4/60 pt-5 text-sm text-paper-dim">
                        Tutte le giornate sono state giocate.
                    </p>
                @endif
            </article>
        @empty
            <p class="mb-4 border border-ink-4 bg-ink-2 p-5 text-sm text-paper-dim">
                Nessuna stagione. Carica un'annata qui sotto, importane il listone, e poi creala.
            </p>
        @endforelse

        {{-- ── Nuova stagione ── --}}
        <form method="POST" action="{{ route('admin.stagione.crea') }}"
              class="mt-6 border border-ink-4 bg-ink-2 p-5">
            @csrf
            <p class="eyebrow mb-4 text-paper-dim">Nuova stagione</p>

            <div class="flex flex-wrap items-end gap-5">
                <div>
                    <label for="season" class="eyebrow mb-2 block text-paper-dim">Annata da giocare</label>
                    <select id="season" name="season" required
                            class="border border-ink-4 bg-ink px-3 py-2 font-mono text-sm outline-none focus:border-rosso">
                        @forelse ($annate as $a)
                            <option value="{{ $a['anno'] }}">{{ $a['etichetta'] }}</option>
                        @empty
                            <option value="">— nessuna annata in casa —</option>
                        @endforelse
                    </select>
                </div>

                <div>
                    <label for="start_matchday" class="eyebrow mb-2 block text-paper-dim">Prima giornata</label>
                    <input id="start_matchday" name="start_matchday" type="number" min="1" max="38" value="1" required
                           class="w-20 border border-ink-4 bg-ink px-2 py-2 text-center font-mono outline-none focus:border-rosso">
                </div>

                <button type="submit" @disabled($annate->isEmpty())
                        class="border border-rosso px-6 py-2 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper disabled:cursor-not-allowed disabled:opacity-40">
                    Crea
                </button>
            </div>

            <p class="mt-4 max-w-2xl text-sm leading-relaxed text-paper-dim">
                Un gruppo può giocare più annate: quelle concluse restano leggibili dal selettore in
                alto, con la loro classifica e le loro carte. Non si cancella niente.
            </p>
        </form>
    </section>

    {{-- ══════════ Le annate di Serie A ══════════ --}}
    <section>
        <p class="eyebrow mb-3 text-rosso">Annate di Serie A in casa</p>

        @foreach ($annate as $a)
            <article class="mb-3 flex flex-wrap items-center justify-between gap-4 border border-ink-4 bg-ink-2 p-4">
                <div>
                    <p class="font-display text-2xl font-black uppercase leading-none">{{ $a['etichetta'] }}</p>
                    <p class="mt-2 font-mono text-[11px] uppercase tracking-[0.18em] text-paper-dim">
                        {{ $a['giocatori'] }} giocatori · {{ $a['giornate'] }} giornate
                        @if ($a['daConfermare'])
                            · <a href="{{ route('listone.index', ['anno' => $a['anno']]) }}"
                                 class="text-rosso underline-offset-4 hover:underline">{{ $a['daConfermare'] }} ruoli da confermare</a>
                        @endif
                        @if ($a['inUso'])
                            · in gioco in {{ $a['inUso'] }} {{ $a['inUso'] === 1 ? 'stagione' : 'stagioni' }}
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-4">
                    {{-- Il listone è una decisione umana e resta tale: il sync non
                         scrive mai ruoli e quotazioni da solo. Ma «umana» non deve
                         voler dire «da terminale». --}}
                    <form method="POST" action="{{ route('admin.annata.listone') }}"
                          enctype="multipart/form-data" class="flex flex-wrap items-end gap-2">
                        @csrf
                        <input type="hidden" name="anno" value="{{ $a['anno'] }}">
                        <div>
                            <label for="listone-{{ $a['anno'] }}" class="eyebrow mb-1.5 block text-paper-dim">Listone .xlsx</label>
                            <input id="listone-{{ $a['anno'] }}" type="file" name="listone" required
                                   accept=".xlsx,.xls,.csv"
                                   class="w-56 border border-ink-4 bg-ink px-2 py-1.5 text-xs file:mr-2 file:border-0 file:bg-ink-3 file:px-2 file:py-1 file:text-xs file:text-paper-dim">
                        </div>
                        <button type="submit"
                                class="border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-rosso hover:text-rosso">
                            Importa
                        </button>
                    </form>

                    @if ($a['inUso'] === 0)
                        <form method="POST" action="{{ route('admin.annata.scarta') }}"
                              onsubmit="return confirm('Butta via listone, calendario e statistiche del {{ $a['anno'] }}. Sicuro?')">
                            @csrf @method('DELETE')
                            <input type="hidden" name="anno" value="{{ $a['anno'] }}">
                            <button type="submit" class="pb-2 text-sm text-paper-dim underline-offset-4 transition hover:text-rosso hover:underline">
                                Butta
                            </button>
                        </form>
                    @endif
                </div>

                {{-- ── Il listone dedotto ──
                     Compare solo finché nessuno vale più di 1, cioè finché il
                     listone non è mai stato né importato né stimato. Senza, la
                     rarità della prima giornata è un sorteggio: nessuno ha
                     ancora giocato, quindi la quotazione è l'unica componente
                     del power che esista già. --}}
                @unless ($a['quotato'])
                    <div class="mt-4 w-full border-t border-ink-4/60 pt-4">
                        <p class="eyebrow mb-2 text-rosso">Nessuna quotazione</p>
                        <p class="mb-4 max-w-2xl text-sm leading-relaxed text-paper-dim">
                            Valgono tutti 1, quindi alla 1ª giornata il power è identico per tutti e
                            «chi è Leggendaria» lo decide lo spareggio sull'id. Si può importare il
                            listone vero qui sopra, oppure dedurre le quotazioni dalle statistiche.
                        </p>

                        <div class="flex flex-wrap items-end gap-3">
                            <form method="POST" action="{{ route('admin.annata.stima') }}">
                                @csrf
                                <input type="hidden" name="anno" value="{{ $a['anno'] }}">
                                <input type="hidden" name="dati" value="{{ $a['anno'] - 1 }}">
                                <button type="submit"
                                        class="border border-rosso px-4 py-2 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                                    Deduci dal {{ $a['anno'] - 1 }}/{{ substr((string) $a['anno'], 2) }}
                                </button>
                            </form>

                            <form method="POST" action="{{ route('admin.annata.stima') }}">
                                @csrf
                                <input type="hidden" name="anno" value="{{ $a['anno'] }}">
                                <input type="hidden" name="dati" value="{{ $a['anno'] }}">
                                <button type="submit"
                                        class="border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-paper-dim hover:text-paper">
                                    Deduci dallo stesso anno
                                </button>
                            </form>
                        </div>

                        <p class="mt-4 max-w-2xl font-mono text-[11px] leading-relaxed tracking-wide text-paper-dim">
                            ~60 chiamate, qualche minuto. <span class="text-paper">L'anno prima</span> è
                            quello che fa il listone vero — le quotazioni si scrivono prima che si
                            cominci — ma chi ha cambiato squadra fra i due anni resta al minimo.
                            <span class="text-paper">Lo stesso anno</span> copre tutti ed è comodo per
                            una prova, ma è preveggenza: alla 1ª giornata si saprebbe già chi chiude
                            con ventiquattro gol, e la caccia al giocatore in forma sparisce.
                        </p>
                    </div>
                @endunless
            </article>
        @endforeach

        {{-- ── Scaricare un'annata ── --}}
        <form method="POST" action="{{ route('admin.annata.load') }}"
              class="mt-6 border border-ink-4 bg-ink-2 p-5">
            @csrf
            <p class="eyebrow mb-4 text-paper-dim">Scarica un'annata</p>

            <div class="mb-4 flex flex-wrap gap-2">
                @foreach ($caricabili as $anno)
                    <label class="cursor-pointer">
                        <input type="radio" name="anno" value="{{ $anno }}" class="peer sr-only"
                               @checked($loop->last)>
                        <span class="block border border-ink-4 px-4 py-2.5 font-display text-xl font-black transition peer-checked:border-rosso peer-checked:bg-rosso peer-checked:text-paper hover:border-paper-dim">
                            {{ $anno }}/{{ substr((string) ($anno + 1), 2) }}
                        </span>
                    </label>
                @endforeach
            </div>

            <button type="submit"
                    class="border border-rosso px-6 py-2.5 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                Scarica
            </button>

            <p class="mt-5 border-t border-ink-4/60 pt-4 max-w-2xl font-mono text-[11px] leading-relaxed tracking-wide text-paper-dim">
                Costa ~60 chiamate (squadre, rose per annata, calendario) e impiega qualche minuto: le chiamate
                vanno distanziate per non sfondare il tetto al minuto. Rilanciarla su un'annata già in
                casa la aggiorna, non la duplica — utile se il caricamento si interrompe a metà.
                Le annate <span class="text-paper">non si cancellano a vicenda</span>.
            </p>
        </form>
    </section>

    {{-- ══════════ Ricominciare da capo ══════════

         In fondo alla pagina e dentro una cornice sua, perché sono i due
         pulsanti che si premono una volta ogni tanto e mai per sbaglio.

         Sono due e non uno per una ragione precisa: le identità delle squadre —
         nomi, maglie, stemmi, allenatori — sono la sola cosa del gruppo che
         nessuno ha voglia di rifare a mano. Metterle dentro «azzera tutto»
         avrebbe voluto dire che chi ripuliva una prova si ritrovava a
         ricostruire dodici maglie che non aveva chiesto di buttare. --}}
    <section class="mt-14 border border-rosso/40 bg-ink-2 p-6">
        <p class="eyebrow mb-3 text-rosso">Ricominciare da capo</p>

        <p class="mb-6 max-w-2xl text-sm leading-relaxed text-paper-dim">
            Due tagli di taglia diversa, tenuti separati apposta. Nessuno dei due tocca le
            <strong class="text-paper">annate di Serie A</strong>: quelle costano chiamate all'API e
            le usano tutti i gruppi — per buttarle c'è «Butta», qui sopra.
        </p>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="border border-ink-4 bg-ink p-5">
                <p class="font-display text-2xl font-black uppercase leading-none">Azzera tutto</p>

                <p class="my-4 text-sm leading-relaxed text-paper-dim">
                    Via <strong class="text-paper">tutte le stagioni</strong> del gruppo, con carte, draft,
                    sfide, classifiche, tornei, scambi e regole di casa. Spariscono anche le statistiche
                    <strong class="text-paper">simulate</strong> delle annate che nessun altro sta giocando —
                    quelle inventate non valgono niente per nessuno, e restando in giro facevano sembrare
                    il campionato a metà.
                    <br><br>
                    Le squadre iscritte <strong class="text-paper">restano</strong>, con le loro maglie.
                </p>

                <form method="POST" action="{{ route('admin.gruppo.azzera') }}"
                      onsubmit="return confirm('Via TUTTE le stagioni di «{{ $league->name }}», con tutto quello che è stato giocato. Le squadre iscritte restano. Sicuro?')">
                    @csrf
                    <button type="submit"
                            class="border border-rosso px-6 py-2.5 font-display text-lg font-black uppercase tracking-wide text-rosso transition hover:bg-rosso hover:text-paper">
                        Azzera tutto
                    </button>
                </form>
            </div>

            <div class="border border-ink-4 bg-ink p-5">
                <p class="font-display text-2xl font-black uppercase leading-none">Togli gli iscritti</p>

                <p class="my-4 text-sm leading-relaxed text-paper-dim">
                    Via le <strong class="text-paper">squadre</strong> del gruppo e tutto ciò che si portano
                    dietro. È l'unico taglio che tocca le persone, ed è separato proprio per questo.
                    <br><br>
                    <strong class="text-paper">Tu resti.</strong> Senza un amministratore il gruppo non
                    avrebbe più una porta d'ingresso, e si recupererebbe solo da riga di comando.
                    Conviene premerlo dopo «azzera tutto», così toglie persone e non partite.
                </p>

                <form method="POST" action="{{ route('admin.gruppo.iscritti') }}"
                      onsubmit="return confirm('Via tutte le squadre di «{{ $league->name }}» tranne la tua. Sicuro?')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="border border-ink-4 px-6 py-2.5 font-display text-lg font-black uppercase tracking-wide text-paper-dim transition hover:border-rosso hover:text-rosso">
                        Togli gli iscritti
                    </button>
                </form>
            </div>
        </div>
    </section>
</x-layouts.app>
