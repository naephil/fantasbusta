/**
 * La navigazione a gruppi.
 *
 * Il grosso lo fa il browser: i gruppi sono `<details>`, quindi si aprono e si
 * chiudono da soli anche senza JavaScript, e su schermo stretto diventano
 * fisarmoniche senza una riga di codice. Qui si aggiungono solo le tre cose che
 * i `<details>` da soli non sanno fare, e che su una barra di menu si notano
 * subito quando mancano.
 */
export function attivaMenu() {
    const nav = document.querySelector('[data-menu]');
    const bottone = document.querySelector('[data-menu-toggle]');

    if (!nav) return;

    const gruppi = () => [...nav.querySelectorAll('.nav-gruppo')];

    // Il pannello su schermo stretto. `hidden` come classe e non come attributo:
    // sopra lg il menu deve tornare visibile da CSS, e un attributo `hidden`
    // vincerebbe su qualunque media query.
    bottone?.addEventListener('click', () => {
        const aperto = nav.classList.toggle('hidden');

        bottone.setAttribute('aria-expanded', String(!aperto));
    });

    // ① Una tendina per volta. Due aperte insieme si sovrappongono, e sopra lg
    // la seconda copre la prima invece di affiancarla.
    nav.addEventListener('toggle', (e) => {
        const gruppo = e.target;

        if (!gruppo.matches?.('.nav-gruppo') || !gruppo.open) return;

        gruppi().forEach((altro) => {
            if (altro !== gruppo) altro.open = false;
        });
    }, true);

    // ② Si chiude cliccando altrove. Senza, una tendina aperta resta appesa
    // sopra la pagina finché non ci si ritorna sopra — e sembra rotta.
    document.addEventListener('click', (e) => {
        if (!nav.contains(e.target) && !bottone?.contains(e.target)) {
            gruppi().forEach((g) => { g.open = false; });
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') gruppi().forEach((g) => { g.open = false; });
    });

    // ③ Il gruppo della pagina in cui ci si trova nasce aperto, ma SOLO nel
    // pannello stretto: sopra lg sarebbe una tendina spalancata sul contenuto a
    // ogni caricamento. La media query si legge da qui perché è una decisione
    // di stato, non di stile, e in CSS non si può esprimere.
    const stretto = window.matchMedia('(max-width: 1023px)');

    const sincronizza = () => {
        nav.querySelector('.nav-gruppo[data-corrente]')?.toggleAttribute('open', stretto.matches);
    };

    sincronizza();
    stretto.addEventListener('change', sincronizza);
}
