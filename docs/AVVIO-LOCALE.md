# Avvio locale

Come far girare Fantasbusta sulla propria macchina, e come rigenerare una lega
di prova con dodici squadre inventate.

---

## 1. La prima volta

```bash
composer install
npm install
npm run build

cp .env.example .env          # se non c'è già
php artisan key:generate
php artisan migrate
php artisan storage:link      # serve per stemmi e sponsor caricati
```

Poi in `.env`:

```
API_FOOTBALL_KEY=la-tua-chiave
API_FOOTBALL_LEAGUE=135       # Serie A
```

`API_FOOTBALL_SEASON` non serve più: **l'annata si sceglie di volta in volta**,
dalla pagina di gestione o come argomento dei comandi. Il database ne tiene
quante se ne vuole, e due gruppi possono giocare anni diversi nello stesso
momento.

---

## 2. Far partire l'applicazione

```bash
php artisan serve
```

→ `http://127.0.0.1:8000`

**In un terminale a parte, e non è facoltativo se giocano altre persone:**

```bash
php artisan schedule:work
```

È il battito del gioco: apre i draft quando maturano, sbusta d'ufficio i turni
scaduti e fa scorrere la coda. Senza, il draft resta fermo al primo che non
clicca, e la giornata si gioca con le rose a metà. Su Hostpoint la stessa cosa
si ottiene con una riga sola di crontab (vedi §5).

Fa anche rispondere i bot alle proposte di scambio (`bot:scambi`, ogni cinque
minuti): ne accettano circa metà, a caso. Non è un'intelligenza di mercato — è
che senza, una lega mezza di bot ha un mercato che non risponde mai, e non c'è
modo di distinguerlo da un mercato rotto. La percentuale si cambia lanciandolo
a mano: `php artisan bot:scambi --percentuale=80`.

⚠️ Se il draft sembra fermo con lo scheduler acceso, il sospetto numero uno è un
lock rimasto appeso: `withoutOverlapping()` scade dopo 24 ore, quindi un tick
ucciso a metà silenzia i successivi per un giorno intero. Si sblocca con
`php artisan schedule:clear-cache`.

Per lavorare sul frontend, in un secondo terminale:

```bash
npm run dev
```

Senza `npm run dev` il sito usa gli asset compilati da `npm run build`. Se una
pagina appare senza stili, quasi sempre manca uno dei due.

---

## 3. La lega di prova

Dodici squadre inventate, vestite, con calendario e giornate già giocate:

```bash
php artisan demo:run --fresh --giornate=3
```

Ogni giornata costa una decina di secondi e **non consuma chiamate all'API**:
le statistiche le genera il simulatore.

### Gli accessi

Password per tutti: **`password`**

| Squadra | Allenatore | Email |
|---|---|---|
| Atletico Sbustamento | Ada Fornaciari | `ada.fornaciari@demo.test` **(admin)** |
| Real Fornello | Bruno Tessari | `bruno.tessari@demo.test` |
| Dinamo Divano | Carla Pezzi | `carla.pezzi@demo.test` |
| Sporting Tapiro | Dario Belli | `dario.belli@demo.test` |
| Bustese 1908 | Elena Riva | `elena.riva@demo.test` |
| Olympique Cinghiale | Fabio Neri | `fabio.neri@demo.test` |
| Virtus Pantofola | Giulia Monti | `giulia.monti@demo.test` |
| Pro Sbadiglio | Hugo Marani | `hugo.marani@demo.test` |
| Unione Grattachecca | Irene Sala | `irene.sala@demo.test` |
| Ideal Tortellino | Luca Verri | `luca.verri@demo.test` |
| Audace Zanzara | Marta Conti | `marta.conti@demo.test` |
| Libertas Ombrellone | Nico Gallo | `nico.gallo@demo.test` |

Solo **Ada** è admin: è l'unica che vede `/admin/gestione` e `/admin/listone`.

### Le pagine

| Percorso | Cosa c'è |
|---|---|
| `/` | Home, con i promemoria per l'admin |
| `/draft` | Il palco: apri la busta, la coda dei turni, la tua rosa |
| `/giornata` | Le partite di Serie A coi voti e di chi è ogni carta |
| `/classifica` | Classifica e sfide della giornata |
| `/formazione` | Modulo, undici titolari, ordine della panchina |
| `/mercato` | Proposte di scambio e feed pubblico |
| `/statistiche` | Rialzi, ribassi, migliore in campo, chi capita sempre a chi |
| `/squadra` | Nome, allenatore, maglia, sponsor, stemma |
| `/admin/gestione` | Annate, stagioni, listone, «gioca la prossima» *(solo admin)* |
| `/admin/squadre` | Invita persone, aggiungi bot, password *(solo admin)* |
| `/admin/regole` | Bonus, malus, pesi del power, soglie *(solo admin)* |
| `/admin/listone` | Verifica ruoli e identità *(solo admin)* |

Se il gruppo ha più di una stagione, il selettore in alto a destra decide
quale si sta guardando: rosa, classifica, sfide e statistiche seguono quella.

---

## 4. Il giro normale: dall'interfaccia

Tutto quello che serve a far vivere una lega si fa da `/admin/gestione`, senza
terminale. La pagina distingue due piani, ed è la distinzione che regge tutto:

- **le annate di Serie A** — squadre, listone, calendario, statistiche. Sono
  dati del mondo: si scaricano una volta e li usano tutti i gruppi.
- **le stagioni** — la partita, con carte, sfide e classifica. Ognuna sceglie
  che annata giocare, e più stagioni possono scegliere la stessa.

Nell'ordine:

1. **Scarica un'annata** (~60 chiamate, qualche minuto).
2. **Importa il listone**: si trascina l'`.xlsx` ufficiale nel riquadro
   dell'annata. Ruoli e quotazioni restano una decisione umana — il sync non le
   scrive mai da solo — ma «umana» non vuol dire «da terminale».
   Se il listone di quell'anno non ce l'hai, il riquadro offre di
   **dedurre le quotazioni dalle statistiche**. Serve più di quanto sembri:
   senza, valgono tutti 1, e alla 1ª giornata — quando nessuno ha ancora
   giocato — il power è identico per tutti e la rarità la decide lo
   spareggio sull'id.
3. **Rivedi i ruoli** dubbi in `/admin/listone`.
4. **Crea la stagione**, scegliendo annata e prima giornata.
5. **Genera il calendario** e **avvia**: l'avvio apre da solo i draft delle
   prime due giornate. Serve perché il ciclo normale è sfasato di due — giocare
   la giornata N prepara il draft della N+2 — e senza questo passaggio le prime
   due non le preparerebbe nessuno.
6. **«Sbusta tutto d'ufficio»**, quando c'è un draft aperto. Nel gioco vero i
   turni li smaltisce il cron man mano che scadono; in una stagione di prova
   aspettare non serve. Finché il draft non è chiuso le rose non esistono, e
   giocare quella giornata non produrrebbe nessuna formazione.
7. **«Avanti»**, una giornata alla volta. La spunta *simula* fa girare tutto
   senza toccare l'API. Quando non resta più niente da giocare la stagione si
   chiude da sola.

Le **regole** — bonus, malus, pesi del power, soglie dei gol, dimensione della busta —
si tarano da `/admin/regole`, e valgono **per stagione**: ogni gruppo gioca come vuole e può
cambiare idea da un anno all'altro senza riscrivere il passato.

Di fabbrica le sfide si decidono **a gol**: i fantapunti si convertono in reti col primo a 66
e uno ogni 6, come nel fantacalcio di sempre. Togliendo la spunta si torna al sistema a scarto —
vince chi ha più fantapunti, e sotto la soglia di pareggio è pari. C'è un pulsante per copiarle
su un'altra stagione dello stesso gruppo. Salvare non ricalcola le giornate già chiuse: per
applicare una modifica al passato si rilancia la giornata.

---

## 5. I comandi

Restano tutti, per il cron e per quando fa comodo. **Ogni comando che tocca dati
di Serie A vuole l'annata**, perché in casa ce n'è più d'una.

### Dati veri (consumano chiamate API)

```bash
php artisan stagione:carica 2024        # squadre, listone e calendario di un'annata
php artisan sync:reference 2024         # come sopra, a pezzi
php artisan sync:reference 2024 --solo=teams
php artisan listone:import file.xlsx 2024   # ruoli e quotazioni (.xlsx o .csv)
php artisan players:review 2024         # chi aspetta una decisione dell'admin
php artisan sync:stats 12 2024          # statistiche di una giornata
```

### Dati finti (zero chiamate)

```bash
php artisan simula:giornata 12 2024          # statistiche plausibili
php artisan simula:giornata 12 2024 --seed=7 # ripetibile
php artisan demo:run --anno=2024 --giornate=3   # stagione di prova completa
```

### Il ciclo di gioco

Questi ragionano per **stagione di lega**, non per annata: `--stagione=<id>` ne
sceglie una, e senza opzione lavorano su tutte quelle in corso — che è quello
che serve al cron.

```bash
php artisan stagione:gioca              # la prossima giornata di ogni stagione viva
php artisan stagione:gioca 12 --simula  # una giornata precisa, senza API
php artisan score:matchday 12           # fantavoti, formazioni, sfide, classifica
php artisan power:compute 14            # power e tier PER la 14ª (usa dati fino alla 12ª)
php artisan draft:create 14             # pool + coda dei turni snake
php artisan draft:tick                  # apre, sbusta, avanza  ← questo va in cron
php artisan calendar:generate 1 --start=7   # calendario della stagione di lega 1
```

**L'ordine conta.** Il power della giornata M si calcola sui dati fino alla
M−2, perché il draft di M apre quando la M−1 è ancora in corso. E il pool del
draft congela i tier dal power, quindi `power:compute` viene sempre prima di
`draft:create`.

### In cron, su Hostpoint

Una riga sola, che chiama lo **scheduler** e non i singoli comandi: così
aggiungere un lavoro in `routes/console.php` non richiede di toccare il server.

```
* * * * * /usr/local/php82/bin/php /home/UTENTE/www/artisan schedule:run >/dev/null 2>&1
```

Se il cron minimo consentito è cinque minuti va benissimo lo stesso: i turni
durano fra venti minuti e quattro ore.

⚠️ Niente line break DOS: Hostpoint rifiuta i cron con `\r\n`.

---

## 6. ⚠️ Il tetto delle chiamate, e quanto costa una stagione

Misurato sulla Serie A 2023/24, 20 squadre e 380 partite:

| Operazione | Chiamate |
|---|---|
| Caricare un'annata (squadre + rose + calendario) | **62** |
| Dedurre le quotazioni, se non hai il listone vero | 60 |
| Statistiche di una giornata (una per partita) | **10** |
| Statistiche di tutte le 38 giornate | 380 |
| **Stagione completa, tutto compreso** | **~500** |

Sul piano gratuito sono **100 al giorno** e **10 al minuto**, quindi una
stagione intera si porta in casa in **cinque o sei giorni**. Non c'è modo di
comprimerli: il tetto è giornaliero.

La buona notizia è che **giocare non costa niente**. Le statistiche sono dati
del mondo, condivisi fra tutti i gruppi: una volta scaricate, le 38 giornate si
giocano in cinque minuti quando vi pare. Quindi conviene far partire lo scarico
adesso e pensare al resto dopo:

```bash
php artisan stagione:scarica 2023        # fin dove arriva la quota di oggi
```

Si ferma **prima** di sfondare il tetto — mai a metà giornata, che darebbe i
voti di sei partite su dieci — e il giorno dopo riprende da dov'era.

Per farlo in un giorno solo servirebbe il piano Pro (19 $/mese, 7500 chiamate),
che sono ~1,60 $ a testa in dodici per un mese.

### Scaricare qui e giocare là

Il tetto è per **chiave**, non per macchina: si può scaricare comodamente in
locale — dove il tempo di esecuzione non ha limiti e si può lasciar macinare per
giorni — e poi portare il pacchetto sul server senza spendere nemmeno una
chiamata in più.

```bash
# qui
php artisan annata:esporta 2023           # scrive storage/app/serie-a-2023.jsonl

# là, dopo aver caricato il file
php artisan annata:importa serie-a-2023.jsonl
```

È ripetibile: si riesporta ogni giorno man mano che l'annata cresce, e
l'importazione aggiunge le giornate nuove senza duplicare niente. Nel pacchetto
vanno solo i **dati del mondo** — squadre, listone, calendario, statistiche —
mentre voti, power, carte e sfide restano fuori: dipendono dalle regole del
gruppo e si ricalcolano giocando.

⚠️ Le giornate **simulate** non entrano nel pacchetto: sono dati inventati, e
trapiantarli altrove vorrebbe dire spacciarli per veri dove nessuno sa che non
lo sono. E un ruolo già confermato a mano sul server non viene sovrascritto.

### Simulare non è gratis, se hai già scaricato

Il simulatore scrive sulla stessa chiave del sync. Premere «Avanti» con
*simula* spuntato su una giornata già scaricata **cancellerebbe i voti veri**, e
in silenzio: i gol vengono ridistribuiti a partire dal risultato vero, quindi il
totale della giornata torna comunque e solo i marcatori sono inventati.

Adesso è impedito — la simulazione si rifiuta e lo dice. Si può forzare con
`--sovrascrivi`, ma bisogna chiederlo.

---

## 6bis. ⚠️ Il limite del piano API gratuito

Il piano Free di API-Football **copre solo le stagioni 2022–2024**. Per la
stagione in corso serve il piano Pro (19 $/mese).

Finché si resta sul gratuito si gioca un'annata fra 2022 e 2024, coi voti veri
di allora. Il simulatore serve al resto: provare il ciclo senza dipendere da
dati che non si possono scaricare.

Altri due limiti del piano gratuito, entrambi già gestiti nel codice:

- **100 chiamate al giorno.** Il fabbisogno reale è ~21 a giornata.
- **Un tetto al minuto**, basso. Il client distanzia le chiamate di 6,5
  secondi: una sincronizzazione delle rose impiega un paio di minuti ed è
  normale.

---

## 7. Quando qualcosa si inceppa

```bash
php artisan diagnostica
```

Scrive in `storage/logs/` un file solo con dentro stato della partita e ultimi
errori: a che giornata siete, se un draft è fermo e su chi, quante carte sono
in giro, che regole avete ritoccato, e la coda del log. Le email e la chiave API
sono oscurate, così il file si può spedire senza pensarci.

Il log da solo non basta quasi mai: dice cosa si è rotto, non in che stato era
la partita — e la domanda successiva è sempre quella.

---

## 8. Test e qualità

```bash
php artisan test                  # tutta la suite, su SQLite
php artisan test --filter=Draft   # solo una parte
vendor/bin/pint                   # formattazione
vendor/bin/pint --test            # solo controllo, senza toccare
```

### Anche su MariaDB, che è il database vero

```bash
docker run -d --name fb-maria -p 33061:3306   -e MARIADB_ROOT_PASSWORD=segreta -e MARIADB_DATABASE=fantasbusta   -e MARIADB_USER=fanta -e MARIADB_PASSWORD=fanta mariadb:10.11

php artisan test -c phpunit-mariadb.xml
```

Non è pignoleria: su SQLite `lockForUpdate()` è un'istruzione muta e Laravel
non protesta, quindi i test di concorrenza passerebbero senza aver esercitato
nessun lock. Su SQLite **si saltano da soli**, e la suite lo dice.

MariaDB ha già fatto emergere cose che SQLite nascondeva: un `first()` senza
ordinamento che restituiva la riga giusta solo per come sono fatti i rowid.

Per la messa in linea vedi **[HOSTPOINT.md](HOSTPOINT.md)**.

---

## 9. Inciampi noti, in locale

| Sintomo | Causa | Rimedio |
|---|---|---|
| `unable to get local issuer certificate` | PHP su Windows non ha un bundle di CA | Già gestito: il client usa lo store di sistema |
| `GD extension is not installed` nei test | manca l'estensione GD | Non serve all'app; i test usano file finti |
| Pagina senza stili | manca il build | `npm run build` |
| `Vite manifest not found` nei test | idem | `npm run build` |
| 429 dall'API | due sincronizzazioni ravvicinate | Aspettare un minuto |

---

## 10. Ricominciare da zero

```bash
php artisan migrate:fresh              # ⚠️ cancella tutto
php artisan db:seed                    # gruppo vuoto + admin@fantasbusta.test
php artisan stagione:carica 2024       # ~22 chiamate
php artisan demo:run --anno=2024 --fresh --giornate=3
```

Dopo un `migrate:fresh` sparisce anche l'anagrafica dei giocatori: senza un
`stagione:carica`, `demo:run` non ha un listone da cui pescare e si ferma
dicendolo.

⚠️ **`migrate:fresh` porta via anche le annate scaricate, e quelle sono costate
chiamate API** — una stagione intera sono cinque o sei giorni di tetto
giornaliero, non un minuto di attesa. Prima di azzerare, o comunque appena
finito di scaricare:

```bash
php artisan annata:esporta 2023        # → storage/app/serie-a-2023.jsonl
```

Il pacchetto si rimette dentro con `annata:importa`, senza spendere niente. È lo
stesso file che si porta sul server, quindi non è lavoro in più.

Per sapere in ogni momento cosa c'è in casa:

```bash
php artisan annate
```

Per buttare via **una sola annata** senza toccare il resto c'è il pulsante
«Butta» nella pagina di gestione. Si rifiuta se qualche stagione la sta
giocando: cancellare il listone sotto a una partita viva lascerebbe carte che
puntano a giocatori senza ruolo, cioè rose non più schierabili.
