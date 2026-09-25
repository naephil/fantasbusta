/**
 * L'anteprima della carta: col mouse al passaggio, col dito al tocco.
 *
 * Le carte sono la cosa più bella del gioco e si vedono solo al draft: per il
 * resto della settimana i giocatori sono righe di testo. Questo le rimette
 * sotto gli occhi ovunque compaia un nome, senza cambiare pagina.
 *
 * Delegato sul documento e non agganciato ai singoli nomi: le pagine che lo
 * usano ne hanno centinaia, e alcune li ridisegnano da sole — il campo della
 * formazione ricostruisce le caselle a ogni cambio di modulo. Con gli ascolti
 * appesi a ciascuno bisognerebbe ricordarsi di riattaccarli.
 */
const RITARDO = 180;
const cache = new Map();

let pannello = null;
let timer = null;
let corrente = null;

/**
 * Quale carta è in mostra ADESSO, non quale si sta per mostrare.
 *
 * Distinto da `corrente`, che è la richiesta in volo: al tocco serve sapere se
 * quello che si sta toccando è già aperto, per chiuderlo invece di riaprirlo.
 */
let mostrata = null;

function creaPannello() {
    if (pannello) return pannello;

    pannello = document.createElement('div');
    pannello.className = 'anteprima-pannello';
    pannello.setAttribute('role', 'tooltip');
    pannello.hidden = true;
    document.body.appendChild(pannello);

    return pannello;
}

/**
 * La carta, chiesta al server una volta sola.
 *
 * Una carta non cambia più — tier e ruolo sono congelati alla pescata — quindi
 * la seconda volta esce dalla memoria e l'anteprima è istantanea.
 */
async function carica(id) {
    if (cache.has(id)) return cache.get(id);

    const promessa = fetch(`/carta/${id}`, { headers: { Accept: 'text/html' } })
        .then((r) => (r.ok ? r.text() : null))
        // Una rete che sfarfalla non deve lasciare in cache un buco
        // permanente: si toglie, così il passaggio dopo riprova.
        .catch(() => { cache.delete(id); return null; });

    cache.set(id, promessa);

    return promessa;
}

/** Dove mettere il riquadro perché non esca dallo schermo. */
function posiziona(el) {
    const r = el.getBoundingClientRect();
    const p = pannello.getBoundingClientRect();
    const margine = 12;

    let sinistra = r.left + r.width / 2 - p.width / 2;
    sinistra = Math.max(margine, Math.min(sinistra, window.innerWidth - p.width - margine));

    // Sopra il nome se c'è posto, altrimenti sotto: in fondo a una tabella
    // lunga lo spazio sopra c'è sempre, in cima quasi mai.
    const sopra = r.top - p.height - margine;
    const alto = sopra > margine ? sopra : r.bottom + margine;

    pannello.style.left = `${sinistra + window.scrollX}px`;
    pannello.style.top = `${alto + window.scrollY}px`;
}

async function mostra(el) {
    const id = el.dataset.carta;
    corrente = el;

    const html = await carica(id);

    // Nel frattempo il mouse può essere andato altrove: senza questo controllo
    // comparirebbe la carta di un nome che non si sta più guardando.
    if (!html || corrente !== el) return;

    const p = creaPannello();
    p.innerHTML = html;
    p.hidden = false;
    mostrata = el;
    posiziona(el);
}

function nascondi() {
    corrente = null;
    mostrata = null;
    clearTimeout(timer);

    if (pannello) {
        pannello.hidden = true;
        pannello.innerHTML = '';
    }
}

function programma(el) {
    clearTimeout(timer);
    timer = setTimeout(() => mostra(el), RITARDO);
}

export function attivaAnteprimaCarte() {
    document.addEventListener('mouseover', (e) => {
        const el = e.target.closest('[data-carta]');
        if (el) programma(el);
    });

    document.addEventListener('mouseout', (e) => {
        if (e.target.closest('[data-carta]')) nascondi();
    });

    // Da tastiera vale lo stesso: il nome ha `tabindex`, quindi ci si arriva
    // col tab e l'anteprima non è roba da soli mouse.
    document.addEventListener('focusin', (e) => {
        const el = e.target.closest('[data-carta]');
        if (el) programma(el);
    });

    document.addEventListener('focusout', (e) => {
        if (e.target.closest('[data-carta]')) nascondi();
    });

    /*
     * Il tocco, che è l'unico modo che ha un telefono.
     *
     * ⚠️ Senza questo l'anteprima da telefono non compariva MAI, in nessuna
     * pagina: c'era solo `mouseover`, e un dito non passa sopra le cose. La
     * funzione esisteva e metà di chi gioca non poteva vederla.
     *
     * Si guarda `pointerType` e non una media query: `(hover: hover)` è vera
     * sui portatili col touchscreen, e lì il dito tornerebbe a non funzionare
     * mentre il mouse va. L'evento sa da sé con cosa è stato prodotto.
     */
    document.addEventListener('pointerdown', (e) => {
        if (e.pointerType !== 'touch') return;

        // ⚠️ I comandi veri vincono sempre sull'anteprima. Il nome di una carta
        // sta dentro l'etichetta di una casella da spuntare (il mercato) e
        // accanto a una tendina (la formazione): senza questa riga, toccare la
        // tendina aprirebbe una carta invece della lista, e scegliere i
        // titolari da telefono diventerebbe impossibile.
        if (e.target.closest('select, input, button, a, textarea, [contenteditable]')) {
            nascondi();

            return;
        }

        const el = e.target.closest('[data-carta]');

        if (!el) {
            nascondi();   // toccato altrove: si chiude, come ci si aspetta

            return;
        }

        // Niente spunta e niente scroll involontario: chi tocca un nome
        // sottolineato sta chiedendo la carta, non il comando che ci sta sotto.
        e.preventDefault();

        if (mostrata === el) {
            nascondi();   // secondo tocco sullo stesso nome: si richiude

            return;
        }

        // Senza attesa: il ritardo serve a non far lampeggiare le carte mentre
        // il mouse attraversa una tabella, e un dito non attraversa niente.
        clearTimeout(timer);
        mostra(el);
    });

    // Scorrendo, il riquadro resterebbe appeso dov'era.
    window.addEventListener('scroll', nascondi, { passive: true });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') nascondi(); });
}
