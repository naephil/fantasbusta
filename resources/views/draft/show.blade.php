<x-layouts.app title="Draft — Fantasbusta">
    <header class="mb-8">
        <p class="eyebrow mb-2 text-rosso">
            Draft · giornata {{ $draft->matchday }}
        </p>

        <h1 class="font-display text-5xl font-black uppercase leading-none tracking-tight">
            @if ($draft->state === 'pending')
                Non è ancora ora
            @elseif ($eIlMioTurno)
                Tocca a te
            @else
                Aspetta il tuo turno
            @endif
        </h1>

        <p class="mt-4 font-mono text-[11px] tracking-[0.2em] text-paper-dim uppercase">
            {{ $fatti }} / {{ $totali }} turni ·
            {{ $draft->rounds }} buste da {{ $draft->pack_size }}
            @if ($draft->state === 'pending')
                · apre {{ $draft->opens_at->format('d/m H:i') }}
            @else
                · chiude {{ $draft->deadline_at->format('d/m H:i') }}
            @endif
        </p>
    </header>

    {{-- Il palco: l'invito a rivedere le buste aperte senza di te, e l'overlay
         in cui va in scena sia quella che si rivede sia quella che si apre
         adesso. Sta in cima perché il richiamo è la cosa che si vuole fare
         appena si arriva; l'overlay è fisso e non gliene importa. --}}
    <x-sbustamento :da-rivedere="$daRivedere" />

    {{-- ── «Avvisami quando tocca a te» ──

         Questa è l'unica pagina del gioco in cui si ASPETTA, e il controllo
         periodico in fondo non basta da solo: se la scheda è sepolta sotto le
         altre ci si accorge del proprio turno tornando a guardare, cioè nel
         momento in cui si sarebbe tornati comunque. La notifica del browser
         copre esattamente quel buco.

         Il pulsante esiste perché il permesso si può chiedere SOLO da un gesto
         dell'utente: chiedendolo al caricamento Firefox lo ignora e Chrome lo
         mette in castigo. E il testo lo scrive il JavaScript, non Blade, perché
         lo stato del permesso lo conosce solo il browser — il server non ha modo
         di saperlo, e stamparne uno sbagliato sarebbe peggio che tacere.

         Compare solo mentre si aspetta: a turno proprio la pagina ha già la
         busta al centro, e offrire un avviso per una cosa che sta succedendo
         adesso non aiuta nessuno. --}}
    @unless ($eIlMioTurno)
        <div data-avviso hidden class="mb-8"></div>
    @endunless

    {{-- ── Il palco ── --}}
    @if ($draft->state === 'open' && $eIlMioTurno)
        <section data-sbustamento data-url="{{ route('draft.open') }}"
                 class="mb-12 border border-rosso bg-ink-2 p-8 text-center">
            <p class="eyebrow mb-4 text-rosso">Busta {{ $turnoAttivo->round }} di {{ $draft->rounds }}</p>

            <button data-apri
                    class="bg-rosso px-10 py-4 font-display text-3xl font-black uppercase tracking-wider text-paper transition hover:bg-rosso-vivo disabled:cursor-wait disabled:opacity-60">
                Apri la busta
            </button>

            @if ($turnoAttivo->expires_at)
                <p class="mt-5 font-mono text-[11px] tracking-[0.2em] text-paper-dim uppercase">
                    Scade fra <span data-countdown="{{ $turnoAttivo->expires_at->toIso8601String() }}">—</span>
                </p>
                <p class="mx-auto mt-2 max-w-md text-xs leading-relaxed text-paper-dim">
                    Allo scadere sbusta il sistema al posto tuo. Le carte sono le stesse:
                    cambia solo che non le vedi uscire.
                </p>
            @endif
        </section>
    @elseif ($draft->state === 'open' && $turnoAttivo)
        <section class="mb-12 border border-ink-4 bg-ink-2 p-8">
            <p class="eyebrow mb-3 text-paper-dim">In questo momento</p>
            <p class="font-display text-3xl font-black uppercase leading-none">
                Sta pescando {{ $turnoAttivo->manager->name }}
            </p>
            @if ($turnoAttivo->expires_at)
                <p class="mt-4 font-mono text-[11px] tracking-[0.2em] text-paper-dim uppercase">
                    Ha tempo ancora <span data-countdown="{{ $turnoAttivo->expires_at->toIso8601String() }}">—</span>
                </p>
            @endif
        </section>
    @endif

    {{-- ── La coda ── --}}
    @if ($coda->isNotEmpty())
        <section class="mb-12">
            <p class="eyebrow mb-3 text-paper-dim">Prossimi turni</p>

            <ol class="flex flex-wrap gap-2">
                @foreach ($coda as $turno)
                    <li class="border px-3 py-1.5 font-mono text-[11px] tracking-wide
                               {{ $turno->state === 'active'
                                    ? 'border-rosso text-paper'
                                    : ($turno->manager_id === auth()->id()
                                        ? 'border-paper-dim text-paper'
                                        : 'border-ink-4 text-paper-dim') }}">
                        <span class="opacity-50">{{ $turno->pick_index }}</span>
                        {{ $turno->manager->name }}
                        @if ($turno->manager_id === auth()->id())
                            <span class="text-rosso">← tu</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    {{-- ── Chi ha preso cosa ── --}}
    @if ($storico->isNotEmpty())
        {{-- L'ancora serve al ritorno: espandere la cronologia è un giro sul
             server, e senza si tornerebbe in cima alla pagina — cioè lontano
             dalla cosa che si era appena chiesto di vedere. --}}
        <section id="cronologia" class="mb-12 scroll-mt-6">
            <p class="eyebrow mb-3 text-paper-dim">
                {{ $cronologiaAperta ? 'Tutte le buste aperte' : 'Ultime buste aperte' }}
                <span class="text-paper-dim/60">· {{ $storico->count() }} di {{ $fatti }}</span>
            </p>

            <div class="space-y-2">
                @foreach ($storico as $turno)
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-l-2 py-1.5 pl-3
                                {{ $turno->manager_id === auth()->id() ? 'border-rosso' : 'border-ink-4' }}">
                        <span class="font-mono text-[11px] tracking-wide text-paper-dim">
                            {{ $turno->pick_index }}
                        </span>

                        <span class="font-display text-lg font-bold uppercase leading-none">
                            {{ $turno->manager->name }}
                        </span>

                        @if ($turno->opened_by === 'auto')
                            <span class="font-mono text-[10px] uppercase tracking-[0.2em] text-paper-dim">d'ufficio</span>
                        @endif

                        {{-- Il ripasso, senza clamore: qui la busta è già stata
                             vista o comunque non è più la novità della pagina —
                             per quella c'è il richiamo in cima. Resta perché
                             una busta bella la si riguarda volentieri, e perché
                             chi chiude l'animazione per sbaglio deve avere un
                             modo di tornarci senza aspettare il turno dopo. --}}
                        @if ($turno->manager_id === auth()->id())
                            <button data-rivedi="{{ route('draft.rivedi', $turno) }}"
                                    class="font-mono text-[10px] uppercase tracking-[0.2em] text-paper-dim underline-offset-4 transition hover:text-rosso hover:underline disabled:cursor-wait">
                                rivedi
                            </button>
                        @endif

                        <span class="flex flex-wrap gap-x-2 gap-y-1 text-sm text-paper-dim">
                            @foreach ($turno->cards->sortByDesc(fn ($c) => \App\Enums\Tier::from($c->tier)->rank()) as $carta)
                                <span class="whitespace-nowrap">
                                    <span class="font-mono text-[10px]"
                                          style="color: {{ \App\Enums\Tier::from($carta->tier)->color() }}">●</span>
                                    {{-- La cronologia dice CHE COSA è stato portato via, ed è
                                         l'informazione su cui si decide il turno dopo: un nome da
                                         solo non basta a sapere se quella era la carta che
                                         aspettavi. Qui l'anteprima serve più che altrove. --}}
                                    <x-nome-giocatore :carta="$carta->id">{{ $carta->player->last_name }}</x-nome-giocatore>
                                    <span class="font-mono text-[10px] opacity-60">{{ $carta->role }}</span>
                                </span>
                            @endforeach
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- ── Il resto della cronologia ──

                 Un draft è sessanta turni e le ultime otto non sono la storia:
                 sono la coda. Chi vuole sapere se un giocatore è già stato
                 preso, o rileggere come sono andate le prime scelte, doveva
                 fidarsi della memoria.

                 È un collegamento e non un pulsante, perché lo stato sta
                 nell'indirizzo: la pagina si ricarica da sola a ogni turno che
                 scorre, e tenuta lato client la cronologia si richiuderebbe
                 addosso a chi la sta leggendo. --}}
            @if ($fatti > $storico->count())
                <a href="{{ route('draft.show', ['cronologia' => 'tutta']).'#cronologia' }}"
                   class="mt-4 inline-block border border-ink-4 px-4 py-2 text-sm text-paper-dim transition hover:border-rosso hover:text-rosso">
                    Mostra le meno recenti
                    <span class="font-mono text-[11px]">({{ $fatti - $storico->count() }})</span>
                </a>
            @elseif ($cronologiaAperta && $fatti > $storicoBreve)
                <a href="{{ route('draft.show').'#cronologia' }}"
                   class="mt-4 inline-block text-sm text-paper-dim underline-offset-4 transition hover:text-rosso hover:underline">
                    Mostra solo le ultime {{ $storicoBreve }}
                </a>
            @endif
        </section>
    @endif

    {{-- ── La rosa ── --}}
    <section>
        <p class="eyebrow mb-4 text-paper-dim">
            La tua rosa · {{ $rosa->count() }} carte
        </p>

        @if ($rosa->isEmpty())
            <p class="border border-ink-4 bg-ink-2 px-6 py-12 text-center text-paper-dim">
                Ancora nessuna carta per questa giornata.
            </p>
        @else
            <div class="flex flex-wrap gap-4">
                @foreach ($rosa as $carta)
                    <x-carta :carta="$carta" larghezza="164px" />
                @endforeach
            </div>
        @endif
    </section>

    <script>
        // Conto alla rovescia: il timer è dinamico — (deadline − adesso) diviso
        // i turni rimasti — quindi il valore in pagina invecchia in fretta.
        document.querySelectorAll('[data-countdown]').forEach((el) => {
            const fine = new Date(el.dataset.countdown);

            const aggiorna = () => {
                const mancano = Math.max(0, Math.floor((fine - Date.now()) / 1000));
                const h = Math.floor(mancano / 3600);
                const m = Math.floor((mancano % 3600) / 60);
                const s = mancano % 60;

                el.textContent = h > 0
                    ? `${h}h ${String(m).padStart(2, '0')}m`
                    : `${m}m ${String(s).padStart(2, '0')}s`;

                if (mancano === 0) el.textContent = 'scaduto';
            };

            aggiorna();
            setInterval(aggiorna, 1000);
        });

        /*
         * L'avviso del browser quando arriva il proprio turno.
         *
         * Notification API e nient'altro: nessun service worker, nessuna chiave
         * VAPID, nessuna tabella di sottoscrizioni, nessuna riga di server. Il
         * prezzo di questa economia è preciso — funziona solo con la PAGINA
         * APERTA. In una scheda in secondo piano sì, col browser ridotto a icona
         * sì, a browser chiuso no. È il caso vero, che è «ho lasciato il draft
         * aperto e sono andato a fare altro».
         *
         * ⚠️ Su iPhone non esiste e non è una svista: Safari espone le notifiche
         * soltanto alle pagine installate in Home, e solo via push con service
         * worker. È lo stesso motivo per cui DESIGN.md §Notifiche dà le push PWA
         * per «troppo fragili» e mette Telegram come canale primario. Questo non
         * lo sostituisce: gli sta accanto, e costa zero.
         */
        const avvisa = (() => {
            const box = document.querySelector('[data-avviso]');

            // `isSecureContext` insieme al supporto: fuori da HTTPS — e da
            // localhost, che i browser trattano da sicuro — il permesso viene
            // negato in silenzio. Offrire un pulsante che non può funzionare è
            // peggio che non offrirne nessuno.
            const disponibile = 'Notification' in window && window.isSecureContext;

            const spiegazioni = {
                default: 'Un avviso del browser appena tocca a te. Basta lasciare la pagina aperta, anche in un\'altra scheda.',
                granted: 'Ti avviso io appena tocca a te: puoi lasciare questa scheda in secondo piano.',
                denied: 'Le notifiche sono bloccate per questo sito. Si riattivano dal lucchetto nella barra degli indirizzi.',
            };

            const disegna = () => {
                if (!box || !disponibile) return;

                const stato = Notification.permission;

                box.hidden = false;
                box.innerHTML = `
                    <p class="flex flex-wrap items-center gap-3 border-l-2 border-ink-4 pl-3 text-sm text-paper-dim">
                        ${stato === 'default' ? '<button data-chiedi class="border border-ink-4 px-3 py-1.5 text-sm text-paper-dim transition hover:border-rosso hover:text-rosso">Avvisami quando tocca a me</button>' : ''}
                        <span>${spiegazioni[stato] ?? spiegazioni.denied}</span>
                    </p>`;

                box.querySelector('[data-chiedi]')
                    ?.addEventListener('click', () => Notification.requestPermission().then(disegna));
            };

            disegna();

            return (titolo, corpo) => {
                if (!disponibile || Notification.permission !== 'granted') return;

                // Stesso `tag` a ogni giro: se per qualunque motivo si notifica
                // due volte, la seconda sostituisce la prima invece di
                // impilarcisi sopra. Un turno, un avviso.
                const n = new Notification(titolo, {
                    body: corpo,
                    tag: 'fantasbusta-turno',
                    icon: '/favicon.ico',
                });

                n.addEventListener('click', () => {
                    window.focus();
                    n.close();
                });
            };
        })();

        // Il draft è l'unica pagina in cui si ASPETTA: il proprio turno arriva
        // quando arriva, e il cron lo fa scorrere anche mentre nessuno guarda.
        // Senza questo si restava fermi su «aspetta il tuo turno» col turno già
        // proprio — la cosa più segnalata al primo test coi volontari.
        //
        // Si confronta una firma e si ricarica: la pagina è già renderizzata
        // dal server, quindi ricaricarla è sempre corretto. Aggiornare i pezzi
        // a mano vorrebbe dire tenere due versioni della stessa pagina in
        // accordo fra loro, ed è lì che questi aggiornamenti marciscono.
        (() => {
            const firma = @json($firma);
            const url = @json(route('draft.stato'));
            let fermo = false;

            // Da cosa si riconosce il turno che ARRIVA, invece del turno che
            // c'è già: si notifica solo il passaggio da «non mio» a «mio».
            //
            // Il valore di partenza viene dal server e non da `false`, ed è
            // quello che evita il doppione: appena notificato si ricarica, e la
            // pagina nuova nasce con il turno già proprio. Partendo da `false`
            // ogni ricaricamento suonerebbe una seconda volta.
            let mioPrima = @json($eIlMioTurno);

            // ⚠️ Non si ricarica mentre la busta si sta aprendo: l'animazione
            // dura qualche secondo ed è il momento del gioco. Il sbustamento
            // alza questa bandiera e non la riabbassa — a busta aperta la
            // pagina va comunque ricaricata a mano dall'utente o al giro dopo.
            document.addEventListener('sbustamento:inizio', () => { fermo = true; });

            // ⚠️ A scheda nascosta NON ci si ferma più, ed è tutto il punto
            // dell'avviso: è proprio lì che serve. I browser rallentano
            // comunque i timer in secondo piano — Chrome scende a un giro al
            // minuto dopo qualche minuto di inattività — quindi la notifica può
            // arrivare con un minuto di ritardo. Su un turno che di suo ne dura
            // venti è un prezzo che si paga volentieri.
            const guarda = async () => {
                if (fermo) return;

                try {
                    const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                    if (!r.ok) return;

                    const stato = await r.json();

                    // ⚠️ Prima si avvisa, poi si ricarica. Invertirli non
                    // avviserebbe mai nessuno: `location.reload()` porta via
                    // questa pagina e con lei il codice che doveva notificare.
                    if (stato.mio && !mioPrima) {
                        avvisa('Tocca a te', 'Il draft aspetta la tua busta.');
                    }

                    mioPrima = stato.mio;

                    if (stato.firma !== firma) location.reload();
                } catch (e) {
                    // Una rete che sfarfalla non deve spegnere il controllo:
                    // si riprova al giro dopo.
                }
            };

            setInterval(guarda, 5000);

            // Tornando sulla scheda si controlla subito: chi ha lasciato il
            // draft aperto in secondo piano vuole sapere adesso se tocca a lui.
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) guarda();
            });
        })();
    </script>
</x-layouts.app>
