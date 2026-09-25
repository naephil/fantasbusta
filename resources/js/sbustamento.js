/**
 * La macchina dell'apertura, portata dal prototipo.
 *
 * Le carte NON si costruiscono qui: arrivano dal server già rese, con lo stesso
 * componente Blade che disegna la rosa. Il JavaScript si occupa solo della
 * drammaturgia — taglio, giro, lampo, ventaglio — perché quella è l'unica
 * cosa che il server non può fare.
 *
 * La pesca è già avvenuta quando la bustina compare: l'ordine, con la carta
 * migliore in 4ª posizione, lo ha già imposto PackGenerator::orderForReveal().
 * Qui non si decide più niente, si mette solo in scena.
 */

/**
 * I colori e l'ordine dei tier, gli stessi di `App\Enums\Tier`.
 *
 * ⚠️ Erano rimasti a quattro dopo l'arrivo di Pacco e Monnezza, e sbagliavano
 * in silenzio: una pastiglia di quelle due usciva senza colore — `undefined`
 * finiva dentro la variabile CSS — e il lampo di fine rivelazione, che scatta
 * sopra il rango 2, confrontava `undefined >= 2` cioè sempre falso. Nessun
 * errore in console, solo due fasce su sei rese peggio delle altre.
 *
 * Dalla peggiore alla migliore: è anche l'ordine in cui si spera.
 */
const TINT = {
    monnezza: '#4a4640',
    pacco: '#7a6a4f',
    comune: '#8a929e',
    rara: '#2ec4f1',
    epica: '#f0157a',
    leggendaria: '#ffb703',
};

const RANK = { monnezza: -2, pacco: -1, comune: 0, rara: 1, epica: 2, leggendaria: 3 };

/**
 * Quanto del taglio basta per considerarlo fatto. Non 1: l'ultimo millimetro
 * il dito non ci arriva mai, perché la busta finisce e il bordo dello schermo
 * o il pollice stesso lo coprono.
 */
const TAGLIO_COMPLETO = 0.96;

/**
 * Semina scintille dentro `radice`, tutte da un punto, ognuna verso una
 * direzione sua. Il CSS le fa volare e svanire; qui si decide solo dove
 * nascono e dove vanno.
 *
 * ⚠️ Si tolgono con un timer e non su `animationend`: con la riduzione del
 * movimento attiva le animazioni sono spente, l'evento non arriva mai, e le
 * scintille resterebbero lì per sempre come coriandoli sul pavimento.
 *
 * @param {HTMLElement} radice
 * @param {(i: number) => {x: number, y: number, dx: number, dy: number}} dove
 * @param {number} quante
 * @param {string} colore
 */
function scintille(radice, dove, quante, colore) {
    for (let i = 0; i < quante; i++) {
        const { x, y, dx, dy } = dove(i);
        const s = document.createElement('i');
        s.className = 'spark';
        s.style.cssText = `left:${x}px; top:${y}px; --dx:${dx}px; --dy:${dy}px; color:${colore}`;
        radice.appendChild(s);
        setTimeout(() => s.remove(), 700);
    }
}

/**
 * @param {boolean} rivedi  true per una busta GIÀ aperta, da rimettere in scena.
 *
 * La drammaturgia è la stessa identica, e deve restarlo: una replica che si
 * vedesse diversa dall'originale non sarebbe un recupero, sarebbe un'altra
 * cosa. Cambia solo come si arriva alle carte — una POST che pesca contro una
 * GET che rilegge — e di conseguenza cosa si può dire se la rete cade: sotto
 * una POST la busta potrebbe essersi aperta comunque, sotto una GET no.
 */
export function avviaSbustamento({ overlay, flash, bottone, url, token, rivedi = false }) {
    if (!overlay || !bottone) return;

    let pack = [];
    let idx = 0;
    let rivelate = [];

    // L'etichetta di riposo si legge dal pulsante invece di riscriverla qui:
    // così «Apri la busta» e «Rivedi» tornano ognuno il proprio dopo un errore,
    // e il testo resta una decisione della pagina.
    const etichetta = bottone.textContent;

    const bang = () => {
        flash.classList.remove('go');
        void flash.offsetWidth;
        flash.classList.add('go');
    };

    const chiudi = () => {
        overlay.classList.remove('on');
        overlay.innerHTML = '';
        // La rosa e la coda sono cambiate: ricaricare è più onesto che
        // aggiornare a pezzi e rischiare che la pagina menta.
        window.location.reload();
    };

    const riposa = () => {
        bottone.disabled = false;
        bottone.textContent = etichetta;
    };

    async function apri() {
        bottone.disabled = true;
        bottone.textContent = rivedi ? 'Un attimo…' : 'Apertura…';

        // Il controllo periodico della pagina deve stare fermo da qui in poi:
        // ricaricare a metà animazione toglierebbe di mezzo proprio il momento
        // del gioco. A busta chiusa ci pensa chiudi(), che ricarica comunque.
        document.dispatchEvent(new CustomEvent('sbustamento:inizio'));

        try {
            const risposta = await fetch(url, {
                method: rivedi ? 'GET' : 'POST',
                headers: rivedi
                    ? { Accept: 'application/json' }
                    : { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
            });

            const dati = await risposta.json();

            if (!risposta.ok) {
                riposa();
                mostraErrore(dati.errore ?? 'Qualcosa è andato storto.');
                return;
            }

            pack = dati.carte;
            idx = 0;
            rivelate = [];
            bustina();
        } catch {
            riposa();

            // ⚠️ Due messaggi diversi perché due verità diverse: una POST caduta
            // può aver aperto la busta lo stesso — la risposta è andata persa,
            // non la pescata — mentre una GET che non arriva non ha spostato
            // niente e si può solo riprovare. Dire «potrebbe essersi aperta» a
            // chi sta rivedendo sarebbe uno spavento gratuito.
            mostraErrore(rivedi
                ? 'Connessione persa: le carte restano dove sono, riprova.'
                : 'Connessione persa. La busta potrebbe essere stata aperta comunque: ricarica.');
        }
    }

    function mostraErrore(messaggio) {
        overlay.classList.add('on');
        overlay.innerHTML = `
            <div class="stage">
                <div class="counter">${messaggio}</div>
                <button class="ghost" data-chiudi>Ricarica</button>
            </div>`;
        overlay.querySelector('[data-chiudi]').addEventListener('click', chiudi);
    }

    function bustina() {
        overlay.classList.add('on');

        // Le pastiglie delle rarità: gli stessi colori delle carte, così la riga
        // si legge senza spiegazioni. Dalla peggiore alla migliore, che è anche
        // l'ordine in cui si spera.
        const pips = Object.values(TINT)
            .map((c) => `<i style="color:${c}"></i>`)
            .join('');

        // La busta è fatta di pezzi perché lo è anche quella vera: un coperchio
        // con la saldatura e la riga di taglio, e un corpo con la banda col
        // marchio, lo specchietto delle rarità e la stampa in fondo. Sono due
        // elementi distinti perché il coperchio deve poter sollevarsi e volare
        // via da solo quando la lama arriva in fondo. La lama (`.pcut`) sta
        // fuori da entrambi, sulla giuntura.
        overlay.innerHTML = `
            <div class="stage">
                <div class="pouch" data-strappa>
                    <div class="plid">
                        <div class="pcrimp top"></div>
                        <div class="ptear"><span>✂ taglia qui</span><span>⟶</span></div>
                    </div>
                    <div class="pbody">
                        <div class="pcorner tl"></div><div class="pcorner tr"></div>
                        <div class="pcorner bl"></div><div class="pcorner br"></div>

                        <div class="plabel"><b>F<em>B</em></b><small>${pack.length} carte</small></div>
                        <div class="pband"><b>Fanta<em>busta</em></b></div>
                        <div class="ppips">${pips}</div>

                        <div class="pfoot">Serie A · edizione ${new Date().getFullYear()}</div>
                        <div class="pcrimp bottom"></div>
                    </div>
                    <div class="pcut"></div>
                </div>
                <div class="counter">Trascina lungo il bordo per tagliare · o clicca</div>
            </div>`;

        lama(overlay.querySelector('[data-strappa]'));
    }

    /**
     * La lama che segue il dito.
     *
     * Il taglio è `--cut`, da 0 a 1, e cresce soltanto: un coltello non
     * ricuce, e un dito che torna indietro lascia la busta com'era. Si segue
     * la posizione del puntatore SULLA busta, non lo spostamento dal punto di
     * pressione — così la linea arriva sempre esattamente dove sta il dito,
     * che è ciò che fa sembrare di tagliare davvero invece di guardare un
     * indicatore di avanzamento.
     *
     * Un clic secco, senza trascinare, non deve lasciare la busta chiusa:
     * la lama parte da sola e la percorre. Lo stesso se si lascia la presa
     * oltre metà — il taglio a quel punto «si finisce da sé», come sulla
     * busta vera dove l'ultimo pezzo si strappa senza bisogno di aiuto.
     * Mollare prima di metà invece lascia il taglio dov'è, e si riprende.
     *
     * ⚠️ `setPointerCapture` sulla busta: senza, appena il dito esce dal
     * rettangolo — e in cima esce subito, la riga sta a un decimo dal bordo —
     * i `pointermove` smettono di arrivare e il taglio si blocca a metà.
     */
    function lama(busta) {
        let taglio = 0;
        let premuto = false;
        let mosso = false;
        let partenza = 0;
        let fatto = false;
        let ultimaScintilla = 0;

        const porta = (v) => {
            taglio = Math.min(1, Math.max(taglio, v));
            busta.style.setProperty('--cut', taglio.toFixed(3));
        };

        // Le scintille dalla punta: a raffica ma non a ogni evento, che sui
        // mouse veloci arrivano a centinaia al secondo.
        const sprizza = (quante = 2) => {
            const ora = performance.now();
            if (ora - ultimaScintilla < 16 && quante < 5) return;
            ultimaScintilla = ora;

            const w = busta.offsetWidth;
            scintille(busta, () => {
                const a = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.3;
                const d = w * (0.06 + Math.random() * 0.14);
                return { x: taglio * w, y: w * 0.11, dx: Math.cos(a) * d, dy: Math.sin(a) * d };
            }, quante, '#ffd866');
        };

        // I tempi seguono l'animazione dello strappo, che è fatta di tre gesti
        // in fila: tremore, coperchio che vola, corpo che si accascia. Il lampo
        // scatta quando il coperchio si stacca — non a caso a metà — e la prima
        // carta sale mentre il corpo sta ancora scendendo, così i due movimenti
        // si passano il testimone invece di alternarsi.
        const completa = () => {
            if (fatto) return;
            fatto = true;
            porta(1);
            sprizza(14);
            busta.classList.remove('cutting');
            busta.classList.add('tearing');
            setTimeout(bang, 380);
            setTimeout(mostraCarta, 660);
        };

        // La lama che corre da sola, da dove è arrivato il dito fino in fondo.
        const daSola = () => {
            const da = taglio;
            const inizio = performance.now();
            const durata = 80 + 520 * (1 - da);

            const passo = (t) => {
                if (fatto) return;
                const p = Math.min(1, (t - inizio) / durata);
                porta(da + (1 - da) * (1 - (1 - p) ** 2));
                sprizza();
                p < 1 ? requestAnimationFrame(passo) : completa();
            };

            requestAnimationFrame(passo);
        };

        busta.addEventListener('pointerdown', (e) => {
            if (fatto) return;
            premuto = true;
            mosso = false;
            partenza = e.clientX;
            busta.classList.add('cutting');
            busta.setPointerCapture(e.pointerId);
        });

        busta.addEventListener('pointermove', (e) => {
            if (!premuto || fatto) return;

            const r = busta.getBoundingClientRect();
            const x = (e.clientX - r.left) / r.width;

            if (Math.abs(e.clientX - partenza) > 6) mosso = true;

            if (x > taglio) {
                porta(x);
                sprizza();
            }

            if (taglio >= TAGLIO_COMPLETO) completa();
        });

        const lascia = () => {
            if (!premuto || fatto) return;
            premuto = false;
            busta.classList.remove('cutting');
            if (!mosso || taglio > 0.5) daSola();
        };

        busta.addEventListener('pointerup', lascia);
        busta.addEventListener('pointercancel', lascia);
    }

    function mostraCarta() {
        const carta = pack[idx];
        const clou = idx === 3;   // la posizione della carta migliore, non la sua identità
        const restanti = pack.length - idx - 1;

        // La prima carta ESCE dalla busta — il movimento è il seguito del corpo
        // che si accascia. Le altre si staccano dal mazzetto che sta dietro,
        // ognuna al posto della precedente appena volata via.
        const entra = idx === 0 ? 'entra' : 'dal-mazzo';

        // I dorsi delle carte che restano, sfalsati. Al massimo quattro: oltre
        // il quarto bordo non si vede più niente e il mazzetto non dice di più.
        const mazzo = Array.from({ length: Math.min(restanti, 4) }, (_, i) => `<i style="--n:${i + 1}"></i>`).join('');

        overlay.innerHTML = `
            <div class="stage">
                <div class="counter"><b>${idx + 1}</b> / ${pack.length}</div>
                <div class="spot ${entra} ${clou ? 'hype' : ''}" data-spot>
                    <div class="rays" style="--t:${TINT[carta.tier]}"></div>
                    <div class="aura"></div>
                    <div class="mazzo">${mazzo}</div>
                </div>
                <div class="counter" data-cta>Clicca la carta per scoprirla</div>
                <div class="tray">${rivelate.map((c, i) => `
                    <span class="chip" style="--c:${TINT[c.tier]}; animation-delay:${i * 0.05}s">
                        <i>${c.role}</i>${c.last}</span>`).join('')}
                </div>
            </div>`;

        const spot = overlay.querySelector('[data-spot]');
        spot.insertAdjacentHTML('beforeend', carta.html);
        spot.querySelector('.aura').style.background =
            `radial-gradient(circle, ${TINT[carta.tier]}, transparent 68%)`;

        const slot = spot.querySelector('.slot');
        let girata = false;
        let inVolo = false;

        slot.addEventListener('click', () => {
            if (!girata) {
                girata = true;
                slot.querySelector('.card').classList.remove('flipped');
                spot.classList.remove('hype');
                spot.classList.add('lit');
                festeggia(spot, carta.tier);

                overlay.querySelector('[data-cta]').textContent =
                    idx + 1 < pack.length ? 'Clicca ancora per la prossima' : 'Clicca per vedere la busta';

                rivelate.push(carta);
            } else if (!inVolo) {
                // La carta vola via e la prossima arriva quando è sparita: il
                // tempo è quello dell'animazione `via`. Il secondo clic durante
                // il volo non conta, altrimenti si salterebbe una carta.
                inVolo = true;
                spot.classList.add('via');
                setTimeout(() => {
                    idx++;
                    idx < pack.length ? mostraCarta() : ventaglio();
                }, 340);
            }
        });
    }

    /**
     * Quello che succede attorno alla carta appena scoperta, in proporzione a
     * quanto vale. Niente sotto Rara; una manciata di scintille sulla Rara; da
     * Epica in su il lampo, i raggi che girano dietro e un'esplosione vera.
     *
     * I tempi sono legati al giro della carta (.6s): le scintille partono
     * quando la faccia è già quasi tutta in vista, non mentre è ancora di
     * taglio, altrimenti si festeggia un dorso.
     */
    function festeggia(spot, tier) {
        const rango = RANK[tier];
        if (rango < 1) return;

        const grande = rango >= 2;
        if (grande) {
            spot.classList.add('raro');
            setTimeout(bang, 260);
        }

        setTimeout(() => {
            const w = spot.offsetWidth;
            const h = spot.offsetHeight;
            scintille(spot, () => {
                const a = Math.random() * Math.PI * 2;
                const d = w * (0.35 + Math.random() * 0.55);
                return {
                    x: w / 2 + Math.cos(a) * w * 0.42,
                    y: h / 2 + Math.sin(a) * h * 0.42,
                    dx: Math.cos(a) * d,
                    dy: Math.sin(a) * d - w * 0.1,
                };
            }, grande ? 28 : 10, TINT[tier]);
        }, 300);
    }

    function ventaglio() {
        overlay.innerHTML = `
            <div class="stage">
                <div class="counter">Busta completa · <b>${pack.length} carte</b></div>
                <div class="fan" data-fan></div>
                <div class="bar" style="justify-content:center">
                    <button data-chiudi>Torna al draft</button>
                </div>
            </div>`;

        const fan = overlay.querySelector('[data-fan]');

        pack.forEach((carta, i) => {
            fan.insertAdjacentHTML('beforeend', carta.htmlScoperta);
            const slot = fan.lastElementChild;
            slot.classList.add('sealed');
            slot.style.animationDelay = `${i * 0.08}s`;
        });

        overlay.querySelector('[data-chiudi]').addEventListener('click', chiudi);
    }

    // Il parallasse non si aggancia più qui: lo fa `attivaParallasse()` in
    // delega sul documento, e copre anche le carte create dopo — queste, e
    // quelle che il campo della formazione clona a ogni scelta.

    bottone.addEventListener('click', apri);

    // Chiudere per sbaglio a metà apertura resta una seccatura — le carte sono
    // già assegnate, ma si stava guardando — quindi nessuna chiusura da fondo o
    // da Escape finché il ventaglio non è lì. Non è più una tragedia, però: la
    // busta si ritrova fra le proprie e si rivede da capo.
}

/**
 * L'inclinazione col puntatore su OGNI carta della pagina.
 *
 * ⚠️ Delegata sul documento e non agganciata a ciascuna carta, come già fa
 * l'anteprima. Prima si agganciava una volta al caricamento, e bastava a
 * un'epoca in cui le carte erano tutte lì dall'inizio. Adesso non lo sono più:
 * il campo della formazione clona le carte a ogni scelta e le ricostruisce a
 * ogni cambio di modulo, e l'apertura della busta le crea una alla volta.
 * Quelle nate dopo restavano piatte — un difetto che si nota solo passandoci
 * sopra, cioè tardi.
 *
 * È anche il motivo per cui questa funzione ha smesso di prendere una radice:
 * chiamarla di nuovo sui pezzi nuovi era la soluzione che invitava a
 * dimenticarsene.
 */
export function attivaParallasse() {
    document.addEventListener('pointermove', (e) => {
        const el = e.target.closest?.('.slot .card');

        if (!el) return;

        const r = el.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width;
        const y = (e.clientY - r.top) / r.height;

        el.style.setProperty('--mx', `${x * 100}%`);
        el.style.setProperty('--my', `${y * 100}%`);
        el.style.setProperty('--rx', `${(0.5 - y) * 12}deg`);
        el.style.setProperty('--ry', `${(x - 0.5) * 12}deg`);
    });

    // `pointerout` e non `pointerleave`: quest'ultimo non risale, e in delega
    // non arriverebbe mai. Si controlla che il puntatore sia uscito DALLA
    // carta e non solo passato su un suo figlio.
    document.addEventListener('pointerout', (e) => {
        const el = e.target.closest?.('.slot .card');

        if (!el || el.contains(e.relatedTarget)) return;

        el.style.setProperty('--rx', '0deg');
        el.style.setProperty('--ry', '0deg');
    });
}
