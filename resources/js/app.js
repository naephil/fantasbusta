import './bootstrap';
import { avviaSbustamento, attivaParallasse } from './sbustamento';
import { attivaAnteprimaCarte } from './anteprima-carta';
import { attivaMenu } from './menu';

const overlay = document.querySelector('[data-overlay]');
const flash = document.querySelector('[data-flash]');
const palco = document.querySelector('[data-sbustamento]');

if (palco) {
    avviaSbustamento({
        overlay,
        flash,
        bottone: document.querySelector('[data-apri]'),
        url: palco.dataset.url,
        token: document.querySelector('meta[name="csrf-token"]')?.content,
    });
}

// Le buste già aperte che si possono rivedere. Ognuna ha il suo pulsante e la
// sua istanza: lo stato dell'apertura — a che carta si è arrivati, quali sono
// già sul tavolo — vive dentro la chiusura, quindi due buste non si pestano.
document.querySelectorAll('[data-rivedi]').forEach((bottone) => {
    avviaSbustamento({
        overlay,
        flash,
        bottone,
        url: bottone.dataset.rivedi,
        rivedi: true,
    });
});

attivaMenu();
attivaParallasse();
attivaAnteprimaCarte();
