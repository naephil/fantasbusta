# Messa in linea su Hostpoint

Scritto sui percorsi veri di questo progetto: il codice va in
`/www/naephil.ch/sbusta`, e la radice del sito punta dentro `public/`.

---

## 1. Come si dispone

```
/www/naephil.ch/sbusta/
  ├ app/  bootstrap/  config/  database/  lang/  resources/  routes/
  ├ storage/  vendor/                    ← niente di tutto questo sul web
  ├ .env                                 ← soprattutto questo
  └ public/                              ← ★ QUI punta la radice del sito
      ├ index.php
      ├ build/                           (gli asset compilati)
      └ storage/                         (link agli stemmi caricati)
```

⚠️ **La radice del sito deve puntare a `/www/naephil.ch/sbusta/public`**, non a
`/www/naephil.ch/sbusta`. È l'unica cosa che tiene `.env` fuori dalla portata di
chiunque: lì dentro ci sono la password del database e la chiave API, e un
indirizzo indovinato basterebbe a scaricarle.

Nel pannello Hostpoint si imposta creando un sottodominio (per esempio
`sbusta.naephil.ch`) e indicandone la cartella, oppure mappando il
sottopercorso se il pannello lo consente.

### Come si riconosce che è puntata male

La home risponde **`Forbidden`** — non un errore dell'applicazione: Apache non
trova nessun indice nella cartella del progetto e si ferma lì. La prova che
toglie ogni dubbio:

```
https://sbusta.naephil.ch/public/login
```

Se **quella** apre la pagina d'accesso mentre la home dà `Forbidden`, la radice
sta una cartella troppo in alto.

L'`.htaccess` nella radice del progetto fa da rete: manda tutto dentro `public/`
e sbarra il resto, così il sito funziona lo stesso e `.env` non esce. Ma è la
seconda scelta e va saputo — con la radice giusta quel file non viene nemmeno
letto, e la sicurezza non dipende da nessuna regola che qualcuno possa
cancellare per sbaglio.

---

## 2. Caricare e pubblicare

### La prima volta: il .env

```powershell
copy deploy\env-produzione.esempio deploy\env-produzione
notepad deploy\env-produzione
```

Da riempire: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
`API_FOOTBALL_KEY`, `APP_URL`. `APP_KEY` resta vuota, la genera lo script.

`deploy/env-produzione` è ignorato da git e **non entra nell'archivio**: le
password restano su questa macchina e sul server, da nessun'altra parte. Il
modello versionato è `env-produzione.esempio`, e va lasciato coi campi vuoti.

Per controllarlo in qualunque momento:

```powershell
php deploy/verifica-env.php deploy/env-produzione
```

### Poi, ogni volta: un comando

```powershell
.\deploy\carica.ps1
```

Fa tutto: compila l'archivio, controlla il `.env`, carica archivio e `.env`,
scompatta sul server e lancia `pubblica.sh` — dipendenze, chiave, migrazioni,
link di storage, cache, e infine `php artisan controlla`.

Prima serve `npm run build` (gli asset compilati stanno in `.gitignore` ma
**devono** essere caricati: senza, il sito esce senza stili e sembra rotto; lo
script se ne accorge e si ferma).

Opzioni utili:

| | |
|---|---|
| `-SoloCarica` | carica e scompatta, ma non pubblica |
| `-SenzaEnv` | non tocca il `.env` che sta già sul server |
| `-SenzaArchivio` | riusa l'archivio invece di rifarlo |
| `-Annata 2023` | porta anche i dati dell'annata (vedi sotto) |
| `-Server` `-Cartella` | se cambiano host o percorso |

⚠️ **L'archivio contiene solo il codice.** Squadre, listone, calendario e
statistiche stanno nel database, e il database non viaggia: rilanciare
`carica.ps1` non porta di là niente di quello che hai scaricato in locale. È
l'aspettativa naturale e sbagliata, e per questo c'è `-Annata`:

```powershell
.\deploy\carica.ps1 -Annata 2023
```

Esporta l'annata qui, la carica insieme all'archivio e la importa là dopo le
migrazioni. Ripetibile: reimportare un pacchetto più ricco aggiunge le giornate
nuove senza duplicare, e non tocca i ruoli confermati a mano sul server.

### Se preferisci a mano

```powershell
.\deploy\prepara.ps1                       # oppure: bash deploy/prepara.sh
scp -o MACs=hmac-sha2-512 deploy/fantasbusta.tar.gz UTENTE@HOST:www/naephil.ch/sbusta/
ssh -o MACs=hmac-sha2-512 UTENTE@HOST
#   cd ~/www/naephil.ch/sbusta && tar xzf fantasbusta.tar.gz && bash deploy/pubblica.sh
```

⚠️ **`scp` non ha l'opzione `-m` di `ssh`**: si passa da `-o MACs=`. Scritta come
su ssh dà soltanto «unknown option -- m».

⚠️ Senza il MAC giusto il trasferimento muore a metà con «Corrupted MAC on
input»: un client OpenSSH recente e un server più vecchio si accordano su un
codice di autenticazione che uno dei due gestisce male. Su Hostpoint funziona
`hmac-sha2-512`.

Conviene scriverlo una volta sola in `~/.ssh/config`, così vale per `ssh`,
`scp` e `sftp` senza opzioni da ricordare:

```
Host hostpoint
    HostName naephilc.ssh.cloud.hostpoint.ch
    User naephilc
    MACs hmac-sha2-512
```

⚠️ In PowerShell **non** funziona `bash deploy/prepara.sh`: Windows risolve
`bash` con quello di WSL, non con quello di Git, e se WSL non è configurato
esce un errore che non parla di questo (`getpwuid(0) failed`).

### Cosa viaggia

Nell'archivio: codice, asset compilati, script e guida. **Fuori**: `.env`,
`deploy/env-produzione`, `vendor/`, `tests/`, `node_modules/`. `vendor/` si
ricostruisce sul server con Composer — è molto più veloce del caricamento.

---

## 3. Il `.env` sul server

Il `.env` locale **non si carica**. Si parte dal modello `deploy/env-produzione`,
lo si compila **in locale** in un file che git ignora, e lo si manda su già
pronto:

```powershell
# qui, una volta sola
copy deploy\env-produzione .env.hostpoint
notepad .env.hostpoint          # database, chiave API, indirizzo

# e a ogni bisogno
scp .env.hostpoint hostpoint:www/naephil.ch/sbusta/.env
```

Così i segreti restano in un solo file, fuori da git e fuori dall'archivio.

⚠️ **Non compilare `deploy/env-produzione`**: quello è il modello, resta
versionato, e riempirlo di password vere significa consegnarle a chiunque legga
il repository.

### DB_HOST non è «localhost»

Su un hosting condiviso il database sta su un'altra macchina. Con `localhost`
PDO cerca un socket unix in locale e non lo trova:

```
SQLSTATE[HY000] [2002] No such file or directory
```

Il messaggio non parla di rete e manda a cercare dalla parte sbagliata. Va messo
il nome che il pannello Hostpoint indica per il database, del tipo
`UTENTE.mysql.db.internal`.

Nell'errore compare anche `Database: ` **vuoto**: è il segno che il `.env` sul
server è ancora il modello non compilato.

### Il resto

Da riempire: `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
`API_FOOTBALL_KEY` e `APP_URL`. `APP_KEY` resta vuota, la genera `pubblica.sh`.

⚠️ In un `.env` tutto ciò che segue `=` è il valore, spazi compresi: una nota
messa dopo il valore fa fallire l'avvio con «Failed to parse dotenv file». Nel
modello le note stanno tutte sopra, precedute da `#`.

`mariadb` e non `mysql`: Laravel 12 ha un driver dedicato.

⚠️ `APP_DEBUG=false` non è pignoleria: con `true` il primo errore mostra a chi
lo incontra il contenuto di `.env`, chiave API compresa.

---

## 4. Il primo avvio

```bash
cd ~/www/naephil.ch/sbusta

php artisan key:generate
php artisan migrate --force
php artisan db:seed              # gruppo + admin@fantasbusta.test / password
php artisan storage:link

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Poi, **prima di dare l'indirizzo a chiunque**:

```bash
php artisan controlla
```

Passa in rassegna debug, chiave, database, permessi, link di storage, password
di partenza e stagione aperta, e per ogni cosa storta dice il rimedio. Esce con
errore finché resta qualcosa di grave.

⚠️ Dopo ogni modifica a `.env` o alle rotte va rifatto `config:cache` e
`route:cache`, altrimenti resta in vigore la versione vecchia.

### Far entrare le persone

Due strade, da `/admin/squadre`:

- **A mano.** Crei la squadra e consegni a voce una password provvisoria. Chi
  entra se la cambia da `/squadra`. Va bene per due o tre persone.
- **Col link.** *Apri le iscrizioni* e ottieni un indirizzo tipo
  `https://sbusta.naephil.ch/registra/7Kx9…`. Chi lo apre si crea la squadra da
  sé, password compresa. Va bene quando le persone sono dodici.

⚠️ **Quel link vale quanto una password.** Non c'è verifica dell'email e nessuno
approva: chiunque ce l'abbia entra nel gruppo e da lì vede draft, mercato e rose
di tutti. Si tiene aperto il tempo che serve e poi si chiude — chi si è già
iscritto resta dentro. Se finisce dove non doveva, **rigenera**: il vecchio
indirizzo smette di funzionare all'istante.

Di fabbrica le iscrizioni sono **chiuse**, e restano tali finché non le apri.

---

## 5. Il cron

Una riga sola, che chiama lo **scheduler** e non i singoli comandi: così
aggiungere un lavoro non richiede di tornare sul server.

```
* * * * * /usr/local/bin/php /home/naephilc/www/naephil.ch/sbusta/artisan schedule:run >/dev/null 2>&1
```

⚠️ **Quella riga non si lancia nella shell.** Gli asterischi verrebbero
espansi nei nomi dei file della cartella e uscirebbe `command not found`: è
l'errore che si fa la prima volta, e non lascia nessuna traccia del perché.

Si installa così — ripetibile, perché prima toglie l'eventuale riga vecchia di
questa stessa applicazione:

```bash
cd ~/www/naephil.ch/sbusta
( crontab -l 2>/dev/null | grep -Fv "$(pwd)/artisan schedule:run"; \
  echo "* * * * * $(command -v php) $(pwd)/artisan schedule:run >/dev/null 2>&1" ) | crontab -
crontab -l
```

I percorsi se li ricava da sé, quindi non c'è niente da adattare. In alternativa
c'è il gestore dei cron nel pannello Hostpoint, dove però il minimo è ogni
cinque minuti — va benissimo lo stesso.

Se il minimo consentito è cinque minuti va bene lo stesso: i turni di draft
durano fra venti minuti e quattro ore.

⚠️ Niente line break DOS: Hostpoint rifiuta i cron con `\r\n`.

**Senza cron il draft non avanza.** Resta fermo al primo che non clicca, e la
giornata si gioca con le rose a metà.

### Come si verifica che giri davvero

Guardare il crontab non basta: una riga presente ma col percorso di PHP
sbagliato si comporta esattamente come una riga assente, e non lascia nessun
errore da leggere. Lo scheduler lascia quindi un segno a ogni giro.

Da schermo, in fondo all'intestazione di **`/admin/gestione`**:

```
Cron attivo · ultimo giro 40 secondi fa
```

Da SSH:

```bash
php artisan controlla
```

Tre risposte, che vogliono dire tre cose diverse:

| Cosa si legge | Cosa significa |
|---|---|
| `Il cron gira: ultimo giro …` | a posto |
| `Il cron non è mai passato` | la riga non c'è, o non funziona (percorso di PHP, `\r\n`) |
| `Il cron era attivo ma si è fermato` | c'era e ha smesso |

Appena installato è normale leggere «mai passato»: il primo giro arriva entro un
minuto — cinque, se il cron è ogni cinque. Ricarica e basta.

Se resta «mai passato», la riga si prova a mano — così l'errore si vede invece
di finire in `/dev/null`:

```bash
cd ~/www/naephil.ch/sbusta
$(command -v php) artisan schedule:run
```

Se questo funziona ma il cron no, il problema è nella riga di crontab, non
nell'applicazione: quasi sempre il percorso di PHP.

---

## 6. I dati: scaricare qui, giocare là

Una stagione intera sono **~440 chiamate**: 62 per l'annata (rose, listone,
calendario) più una per partita. Sul piano Pro, che ne concede 7500 al giorno,
ci sta comodamente in una sola sessione — e allora tanto vale scaricare
direttamente sul server dalla pagina di gestione, senza passare da qui.

⚠️ Con una riserva: una richiesta web ha un tempo massimo, e sul condiviso non
sempre lo si può alzare. Le 380 chiamate delle statistiche sono ~2 minuti di
freno più la rete, e se il server tronca a metà la giornata resta coi voti a
pezzi. Da schermo conviene quindi **una manciata di giornate per volta**; per
tutta l'annata in un colpo si usa `stagione:scarica` da SSH, che non ha
scadenze.

### Se il tetto è stretto: scaricare qui, giocare là

Il tetto dell'API è per **chiave**, non per macchina. Sul piano gratuito, dove
una stagione sono cinque o sei giorni di quota, conviene scaricare in locale —
dove il tempo non ha limiti — e portare il pacchetto sul server:

```bash
# in locale, anche per giorni
php artisan stagione:scarica 2023
php artisan annata:esporta 2023        # → storage/app/serie-a-2023.jsonl

# sul server, dopo aver caricato il file
php artisan annata:importa serie-a-2023.jsonl
```

È ripetibile: si riesporta man mano che l'annata cresce e l'importazione
aggiunge le giornate nuove senza duplicare.

Vale la pena farlo comunque, una volta finito di scaricare: quelle righe sono
costate chiamate, e un `migrate:fresh` distratto le porta via.

### Il freno si tara da solo

Oltre al tetto giornaliero c'è un tetto **al minuto**, e dipende dal piano: 10
sul gratuito, 300 sul Pro. Non c'è niente da configurare — al primo `/status`
l'applicazione legge il tetto vero e ne ricava la distanza fra una chiamata e
l'altra, all'80% del concesso per lasciare margine a ciò che gira altrove.

Cambiando piano si adegua da sé. Se serve forzarlo:

```
API_FOOTBALL_INTERVAL=250      # millisecondi fra una chiamata e l'altra
```

---

## 7. Quanto tempo ci mette

Misurato in locale su una stagione intera, dodici squadre:

| | |
|---|---|
| giocare una giornata | ~8 secondi |
| stagione completa (38 giornate) | ~5 minuti |
| sbustare d'ufficio un draft intero | ~4 secondi |
| scaricare un'annata | ~7 minuti (60 chiamate distanziate) |

Le azioni lunghe alzano da sole il limite di esecuzione a 15 minuti
(`Controller::senzaFretta`), perché il default dell'hosting condiviso è trenta
secondi e scadere a metà lascia una giornata coi voti di sei partite su dieci.

⚠️ Se il server ha un suo timeout davanti a PHP, quello non si può alzare da
qui. Se «Scarica un'annata» si interrompe, si rilancia: è ripetibile e riprende
da dove era arrivata.

---

## 8. Se qualcosa si inceppa

```bash
php artisan diagnostica
```

Scrive in `storage/logs/` un file con stato della partita e ultimi errori.
Email e chiave API sono oscurate: si può spedire senza pensarci.

---

## 9. La suite anche su MariaDB

In locale, prima di caricare aggiornamenti:

```bash
docker run -d --name fb-maria -p 33061:3306 \
  -e MARIADB_ROOT_PASSWORD=segreta -e MARIADB_DATABASE=fantasbusta \
  -e MARIADB_USER=fanta -e MARIADB_PASSWORD=fanta mariadb:10.11

php artisan test -c phpunit-mariadb.xml
```

Serve davvero: su SQLite `lockForUpdate()` non fa niente e i test di concorrenza
si **saltano**, quindi passerebbero senza aver provato nulla. MariaDB ha già
fatto emergere difetti che SQLite nascondeva.
