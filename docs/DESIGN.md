# Fantasbusta — Documento di design

Fantacalcio a carte collezionabili. 12 manager, rose estratte **ogni giornata** tramite
sbustamento, seguito da una fase di scambi libera.

La struttura è a tre livelli: l'**app** ospita più **gruppi di amici**, e ogni gruppo gioca
più **stagioni**, una dopo l'altra o anche in parallelo. Il gruppo e le identità delle
squadre sono permanenti; tutto ciò che si gioca — carte, draft, formazioni, sfide,
classifica, scambi, tornei — appartiene a una stagione e muore con lei.

---

## 1. Loop di gioco

```
Ven 20:45   1ª partita giornata N
            ├─ rose N congelate e salvate
            ├─ pool carte resettato
            └─ apre il DRAFT per N+1

            ↓  finestra draft (~72h weekend / ~48h infrasettimanale)

            60 turni snake (12 manager × 5 giri)
            ordine: inverso alla classifica dopo N-1
            (1ª giornata di campionato: ordine predefinito)

Lun sera    draft chiuso  →  apre il TRADING

            ↓  scambi liberi fino alla deadline

Ven 20:45   1ª partita giornata N+1
            ├─ rose congelate, trade pendenti scaduti
            └─ si ricomincia
```

Il draft di N+1 gira **mentre si gioca la giornata N**: si pesca senza sapere come sta
andando, e un infortunio del sabato te lo tieni. È voluto.

---

## 2. Carte e rarità

### 2.1 Power score

Ricalcolato per ogni giocatore dopo ogni giornata. È ciò che fa salire e scendere le carte
di tier settimana per settimana.

```
power = w1 · baseline        (quotazione iniziale listone, normalizzata per ruolo)
      + w2 · fantamedia       (stagionale)
      + w3 · forma            (media ultime 3 giornate)      ← il "trend"
      + w4 · titolarità       (% minuti giocati)
      − w5 · rischio          (squalifiche, infortuni)
```

Pesi in `leagues.settings`, così sono tarabili senza deploy. `w3` alto = carte molto
volatili; `w3` basso = rarità più stabile e vicina al valore reale.

### 2.2 Tier

Sei fasce, di due specie diverse. Quattro si assegnano per **percentile dentro il ruolo**,
due per **soglia assoluta** sul power.

| Tier         | Come si assegna    | ≈ giocatori |
|--------------|--------------------|------------:|
| Leggendaria  | top 3% del ruolo   |         ~16 |
| Epica        | 12%                |         ~66 |
| Rara         | 30%                |        ~165 |
| Comune       | 55%                |        ~300 |
| **Pacco**    | power < 10         |    il resto |
| **Monnezza** | power < 2          |    il resto |

⚠️ **Le due fasce di fondo non sono cosmetica: senza, la piramide mente.** Il listone
porta centinaia di giocatori a quotazione 1 che non hanno mai giocato — terzi portieri,
primavera, ceduti a gennaio. Contati nei percentili occupavano tutta la fascia Comune e
spingevano in alto tutti gli altri, così che «quasi tutte le carte sono Rare» diventava
vero senza che nessuna carta fosse migliorata di un punto. La piramide misurava una
popolazione diversa da quella che gioca.

Il rimedio ha **due metà che vanno insieme**: una fascia propria per chi sta sotto soglia,
e i percentili contati **solo su chi resta**. Le quote qui sopra sono quindi percentuali
di chi è in gioco, non del listone intero.

⚠️ Alla 1ª giornata le soglie **non mordono**, ed è voluto: finché non si gioca,
`Normalizer::minMax` dà 0.5 a tutti su fantamedia e forma — «nessuno si distingue, quindi
nessuno deve guadagnarci né rimetterci» — e il power minimo possibile è già sopra 27. Le
due fasce compaiono man mano che la stagione produce dati, cioè quando si sa davvero chi è
uno scarto. Marchiare come Monnezza chi non ha ancora avuto modo di giocare sarebbe una
condanna scritta sul nulla.

Le soglie si tarano per lega (`power.soglie`) perché il power è una somma **pesata**: se si
spostano i pesi, la scala si sposta con loro e una soglia fissa nel codice vorrebbe dire
un'altra cosa.

Il tier viene **congelato sulla carta al momento della pesca** (`cards.tier`): se un
giocatore cambia tier dopo, la carta già in rosa non muta. Serve per la coerenza degli
scambi — altrimenti il valore di uno scambio cambierebbe dopo l'accettazione.

L'elenco delle fasce vive **solo** in `App\Enums\Tier`. Era duplicato in tre `enum` di
schema (`player_power`, `cards`, `draft_pool`), e aggiungerne una voleva dire ricordarsi di
tutte e tre: adesso il database tiene una stringa.

---

## 3. Draft

### 3.1 Struttura

- **5 buste da 5 carte** = 25 carte per manager — sono i valori di PARTENZA,
  non una struttura: `draft.giri` e `draft.carte_per_busta` si tarano per
  stagione da `/admin/regole` (§6.3). Tre buste da otto danno 24 carte con turni
  meno numerosi e più lunghi, ed è una scelta di ritmo altrettanto valida.
- **Giri snake**: giro 1 in ordine `1→12`, giro 2 `12→1`, giro 3 `1→12`, ...
- Turni totali = giri x manager (60 con la taratura di partenza, 36 con 3 giri)
- **Pool esclusivo**: ogni giocatore esiste in una sola copia per giornata

```
12 manager × 25 carte  =  300 carte pescate   (con la taratura di partenza)
listone Serie A         ≈  550 giocatori
                        →  55% del pool esaurito, ~250 carte mai viste
```

### 3.2 Timer dinamico

Fissare il timer a mano non funziona: la finestra passa da 72h a 48h nei turni
infrasettimanali.

```
timer_turno = (deadline_draft − adesso) / turni_rimanenti
              clamp(20 min, 4h)
```

Allo scadere il cron sbusta d'ufficio e avanza la coda. Ogni manager può attivare la
**modalità auto**: viene sbustato appena arriva il suo turno, senza attendere la scadenza.

### 3.3 Composizione busta — riempimento a deficit

Le buste sono **casuali** salvo quando la garanzia rischia di saltare. Prima di generare
la busta `k`-esima:

```
per ogni ruolo r in {P, D, C, A}:
    deficit[r] = max(0, minimo[r] − posseduti[r])

slot_rimanenti = (5 − k + 1) × 5
if  Σ deficit  ==  slot_rimanenti:
        → tutti gli slot della busta sono forzati sui ruoli in deficit
elif Σ deficit  >   slot_rimanenti − 5:
        → forza solo gli slot necessari, il resto casuale
else:   → busta interamente casuale
```

Con minimo = `{P:1, D:4, C:4, A:2}` = 11 su 25 (garantisce il **4-4-2** come rete di
sicurezza) il forzamento scatta molto raramente: nella grande maggioranza dei casi tutte e
5 le buste sono pura fortuna.

**Perché un solo portiere e non due.** Con 20 titolari in Serie A e 12 manager non si
resta mai senza. Garantirne due però ucciderebbe il mercato: nessuno avrebbe bisogno di
cercare il secondo portiere, e il titolare di riserva perderebbe ogni valore di scambio.
Con la garanzia a uno, chi pesca solo un secondo portiere è vulnerabile e chi ha il
titolare in più ha una merce di scambio reale. Vedi la penalità in §5.2, che è ciò che
rende la scarsità mordente.

**Rarità nella busta:** ogni busta garantisce ≥1 Rara. Nessun rubber banding aggiuntivo —
l'ordine inverso alla classifica lo fornisce già.

**Le probabilità si ricavano dal pool, non sono costanti.** Il pregio si distribuisce a
cascata dall'alto: gli slot che restano da pescare in tutto il draft sono un budget, e si
assegna prima ai Leggendari finché ce n'è, poi agli Epici, poi ai Rari; i Comuni assorbono
l'avanzo. Le probabilità per slot sono quelle quote.

⚠️ Prima erano fisse — 2% Leggendaria, 9% Epica — e non parlavano né con la piramide del
listone né con quante carte il draft distribuisce davvero. Con 12 manager × 5 × 5 = 300
slot su un pool da ~540, il 2% produceva **6 Leggendarie estratte su 16 esistenti**: dieci
big restavano liberi a ogni draft, e quasi 40 Epiche con loro. Ricavarle dal pool fa sì che
la proprietà «i top vengono usati» regga anche cambiando numero di manager, giri, carte per
busta o lunghezza del listone.

**I tier si contano dentro il ruolo.** La piramide `{3%, 12%, 30%, 55%}` si applica a ogni
reparto separatamente, non al listone intero. In globale i tier alti finiscono quasi tutti
ad attaccanti e centrocampisti — sono loro ad accumulare fantapunti — e il miglior portiere
del campionato esce Comune. È la stessa ragione per cui la quotazione si normalizza per
ruolo nel power score: in assoluto direbbe solo che gli attaccanti costano più dei portieri.
Il `rank` invece resta globale, perché i movimenti di mercato sono una graduatoria di lega.

---

## 4. Trading

Apre a draft chiuso, si chiude quando la giornata comincia.

⚠️ «A draft chiuso» adesso è **imposto**, non solo scritto qui. Prima la pagina
si comportava da aperta e ogni proposta veniva respinta dal pavimento delle
undici — che a metà draft non può essere soddisfatto per definizione, perché la
rosa non è ancora completa. Il manager leggeva «rosa troppo corta», cioè la
conseguenza, e il motivo vero non compariva da nessuna parte.

**Regole**

1. **Scambi sbilanciati permessi** (7 carte per 1). Nessun tetto massimo di rosa.
2. **Pavimento rigido**: uno scambio è rifiutato se lascia una delle due parti
   **inschierabile** — meno di 11 carte, o impossibilitata a comporre uno dei 7 moduli
   canonici, o senza portiere.
3. La validazione avviene **all'accettazione**, mai alla proposta: nel frattempo la
   controparte può aver concluso altri scambi.
4. **Atomicità**: applicazione in transazione con lock su entrambe le rose. Due scambi
   incrociati accettati nello stesso istante sono il modo classico per duplicare una carta.
5. Le proposte pendenti **scadono** alla deadline.
6. **Storico pubblico**: ogni scambio visibile a tutta la lega nel feed. Con il 7-per-1
   permesso il rischio non è l'equilibrio ma la collusione, e la trasparenza è il rimedio
   proporzionato per una lega di amici. Veto a maggioranza come estensione futura.

**Nota di equilibrio.** Lo squilibrio si autoregola: chi cede 7 carte per una Leggendaria
consolida qualità e si assottiglia, chi accetta si riempie di panchina che non schiererà.
Il 7-per-1 conviene quindi solo a chi è già lungo di rosa. Il vincolo degli 11 schierabili
fa da solo tutto il lavoro di bilanciamento.

---

## 5. Schieramento e punteggio

### 5.1 Moduli

I 7 canonici: `3-4-3` `3-5-2` `4-3-3` `4-4-2` `4-5-1` `5-3-2` `5-4-1`.
Sempre 1 portiere + 10 di movimento.

**Quando si blocca.** Quando l'amministratore dichiara che la **giornata è
cominciata** (`league_seasons.started_matchday`) — e **non** al primo fischio
vero della partita di Serie A, né alla scadenza del draft.

⚠️ La distinzione non è teorica: le annate si giocano **ricaricate**, quindi il
calendario reale è nel passato e ogni primo fischio è già suonato. Con la regola
del fischio ogni formazione nasceva bloccata e il gioco era ingiocabile — è
successo davvero, al primo test con dei volontari.

Il rimedio successivo fu guardare la scadenza del draft. Meglio, ma ancora un
conto sulle date: quella finestra è una *quota* calcolata fra due primi fischi,
quindi «da quando non posso più schierare» restava una deduzione. La regola di
adesso è un fatto dichiarato, e funziona uguale su una stagione in diretta e su
una rigiocata dieci anni dopo, perché non dipende dall'orologio.

Rete di sicurezza: una giornata già **chiusa** è bloccata comunque, anche se
nessuno aveva premuto «iniziata». I punti sono in classifica, e riscrivere la
formazione che li ha prodotti cambierebbe una storia già letta.

### 5.1.1 I tre momenti di una giornata

I risultati veri arrivano alla spicciolata — sabato alle 15, domenica sera, il
lunedì — quindi «giocare una giornata» non è un istante ma una finestra:

| Momento | Chi | Cosa fa |
|---|---|---|
| **Avvia stagione** | admin | Apre **un solo** draft: quello della giornata di partenza |
| **Giornata N cominciata** | admin | Congela le formazioni di N · apre il draft della **N+1** |
| **Parziali** | admin, ripetibile | Statistiche e fantavoti delle partite finite. Classifica, sfide e tornei **fermi** |
| **Chiudi N** | admin | Formazioni d'ufficio, sfide, classifica, tornei |

⚠️ Il draft della successiva apre quando questa **comincia**, non due giornate
avanti. Lo sfasamento di due — che nasceva dal voler avere il draft di N+1 già
chiuso quando N si giocava — faceva esistere le carte di N+1 prima che N fosse
giocata: comparivano nella pagina della formazione, elencate per nome, e la
busta quando si apriva non rivelava più niente.

Il conto del power torna identico: il draft di N+1 guarda i dati fino a N−1,
che a giornata N appena cominciata sono esattamente quelli completi.

### 5.2 Sostituzioni automatiche

Necessarie: si pesca per N+1 senza sapere le formazioni ufficiali di N+1.

- Panchina **ordinata** dal manager (le 14 carte non titolari)
- Un titolare senza voto (0 minuti, oppure rating assente) viene sostituito dalla prima
  carta in panchina **dello stesso ruolo**
- Massimo 3 sostituzioni (configurabile)
- Il modulo resta invariato: si sostituisce ruolo su ruolo

**Portiere senza voto e senza riserva in panchina → voto d'ufficio 4.**
È la regola che dà valore al secondo portiere sul mercato: senza una penalità, non averlo
non costerebbe nulla e nessuno scambierebbe mai per procurarselo. Il 4 è abbastanza
doloroso da far muovere il mercato, non abbastanza da compromettere la giornata.

### 5.3 Fantavoto

Non esistono pagelle editoriali accessibili via API, quindi il voto base è **statistico**,
derivato dal rating API-Football normalizzato sulla scala fantacalcio e clampato.

```
fantavoto = voto_base + bonus − malus
```

| Evento              | Valore |
|---------------------|-------:|
| Gol segnato         |   +3   |
| Rigore segnato      |   +3   |
| Rigore parato       |   +3   |
| Assist              |   +1   |
| Ammonizione         |  −0.5  |
| Espulsione          |   −1   |
| Autorete            |   −2   |
| Rigore sbagliato    |   −3   |
| Gol subito (P)      |   −1   |

Tutti i valori in `leagues.settings`. Modificatore di difesa opzionale, disattivato di
default.

---

## 6. Schema database (MariaDB / InnoDB)

### 6.0 I due assi

Lo schema si regge su una distinzione che va tenuta ferma dappertutto:

| | Chiave | Chi la usa |
|---|---|---|
| **Annata di Serie A** | `season` (2024 = 2024/25) | listone, calendario, **statistiche** |
| **Stagione di lega** | `league_season_id` | **voti, power**, carte, draft, formazioni, sfide, classifica, scambi, tornei |

I dati dell'annata sono **del mondo**: si scaricano una volta e li condividono tutti i
gruppi. Due leghe che rigiocano il 2023/24 leggono lo stesso listone e le stesse
statistiche — minuti, gol, cartellini, rating — perché quelli sono fatti.

Il confine cade esattamente lì. Da `player_stats` in poi comincia l'**interpretazione**, e
l'interpretazione è di chi gioca: bonus, malus e pesi del power si tarano per gruppo e per
stagione (§6.3), quindi lo stesso gol vale 3 per una lega e 10 per un'altra. Per questo
`player_scores` e `player_power` portano `league_season_id` e non `season`: condividerli
significherebbe che l'ultima lega a ricalcolare cancella i voti di tutte le altre, in
silenzio, perché nessuna chiave se ne accorgerebbe.

Da qui discendono due regole che il codice non deve mai violare:

1. **Niente attributo di annata su `players`.** Squadra, ruolo e quotazione cambiano ogni
   anno; tenerli sull'identità significherebbe che caricare il 2024 riscrive il 2022, e un
   gruppo che sta rigiocando una stagione vecchia si ritroverebbe mezza Serie A nella
   squadra sbagliata. Stanno su `player_seasons`.
2. **Niente `league_id` sulle tabelle di gioco.** Con `league_id` la 5ª giornata del 2023
   collide con la 5ª del 2024 dello stesso gruppo. La chiave è `league_season_id`, che
   porta con sé sia il gruppo sia l'anno — così non si può passarne uno e dimenticare
   l'altro.

### 6.1 Dati di riferimento

```
teams
  id                  PK   -- id API-Football, riusato come PK
  name, code, logo_url

players                                          -- ★ solo l'identità, permanente
  id                  PK   -- id API-Football
  first_name, last_name
  photo_url                                      -- media.api-sports.io/football/players/{id}.png
  photo_verified      BOOL DEFAULT 0             -- «è davvero lui», si dice una volta sola

player_seasons                                   -- ★ quello che cambia da un anno all'altro
  id                  PK
  player_id           FK → players
  season              SMALLINT
  team_id             FK → teams
  role                ENUM('P','D','C','A')      -- dal listone, non dall'API
  role_confirmed      BOOL DEFAULT 0
  quotazione_iniziale DECIMAL(4,1)               -- dal listone
  active              BOOL DEFAULT 1
  UNIQUE (player_id, season)
  INDEX (season, team_id, role)
  INDEX (season, role_confirmed)

fixtures
  id                  PK
  season, matchday    SMALLINT
  home_team_id, away_team_id   FK → teams
  kickoff_at          DATETIME
  status              ENUM('scheduled','live','finished')
  INDEX (season, matchday, kickoff_at)

player_stats                                     -- una riga per giocatore per giornata
  id                  PK
  player_id           FK → players
  season, matchday    SMALLINT
  minutes, goals, assists, yellow, red, own_goals
  pen_scored, pen_missed, pen_saved, goals_conceded
  rating              DECIMAL(3,1) NULL          -- NULL = senza voto
  UNIQUE (player_id, season, matchday)
```

> Le due bandiere di verifica stanno apposta su tabelle diverse. `role_confirmed` risponde a
> «che ruolo ha» ed è una domanda per annata: si rifà a ogni listone. `photo_verified`
> risponde a «è davvero lui» ed è una domanda sulla persona: si risponde una volta e vale
> per sempre.

> **Nota di seeding.** L'API fornisce `position` in inglese (Goalkeeper/Defender/…), non il
> ruolo fantacalcio. Il listone Excel è la fonte autoritativa per `role` e
> `quotazione_iniziale`; il match con l'id API-Football si fa una volta a inizio stagione
> (fuzzy su cognome + squadra).
>
> **Serve una schermata di verifica degli accoppiamenti, obbligatoria prima dell'avvio
> stagione.** Un id sbagliato non si annuncia: se punta a un giocatore inesistente dà 404 e
> lo vedi subito, ma se punta a un giocatore *diverso* restituisce una faccia plausibile e
> passa qualunque controllo automatico. Con ~550 righe da validare, l'unico strumento
> affidabile è l'occhio umano: foto e nome affiancati, scorrimento rapido, conferma o
> correzione manuale. Riscontrato in prototipazione con un id inventato che per caso
> esisteva e mostrava un altro giocatore.

### 6.2 Scoring e rarità

```
player_scores                                    -- ★ per stagione di lega, non per annata
  league_season_id, player_id, matchday      UNIQUE insieme
  voto_base, bonus, malus, fantavoto   DECIMAL(4,2)

player_power                                     -- ricalcolato ogni giornata
  league_season_id, player_id, matchday      UNIQUE insieme
  power               DECIMAL(6,3)
  tier                ENUM('comune','rara','epica','leggendaria')
  rank, rank_delta, tier_changed
  INDEX (league_season_id, matchday, tier)
```

> Il costo è la ridondanza: N leghe sulla stessa annata calcolano N volte gli stessi voti a
> partire dalle stesse statistiche. È accettato consapevolmente — sono ~550 righe per
> giornata e il calcolo è locale, mentre l'alternativa (voti condivisi) renderebbe
> impossibile tarare le regole, che è una delle promesse del gioco.
>
> Conseguenza meno ovvia: **il tier di una carta è una proprietà della lega**. Lo stesso
> giocatore può essere Epica per un gruppo che pesa molto la forma e Rara per uno che guarda
> la quotazione — e i due pool di draft sono diversi di conseguenza.

### 6.3 Lega

```
leagues                                          -- ★ il gruppo di amici, permanente
  id, name
  settings            JSON      -- pesi power, bonus/malus, quote, timer, n. sostituzioni

league_seasons                                   -- ★ una stagione giocata dal gruppo
  id, league_id
  season              SMALLINT   -- quale annata di Serie A si gioca
  state               ENUM('preparazione','in_corso','conclusa')
  start_matchday      SMALLINT   -- la lega può nascere a campionato iniziato
  settings            JSON NULL  -- ritocchi validi per questa sola stagione
  UNIQUE (league_id, season)
  INDEX (league_id, state)

managers                                         -- ★ la squadra, permanente come il gruppo
  id, league_id, name, coach_name, email, password_hash
  auto_draft          BOOL DEFAULT 0
  is_admin            BOOL DEFAULT 0
  active              BOOL DEFAULT 1             -- chi lascia resta, ma non entra nelle nuove
  jersey, crest, sponsor   JSON                  -- identità visiva
  telegram_chat_id    NULL

standings
  league_season_id, manager_id, matchday      UNIQUE insieme
  punti, fantapunti_totali, posizione
```

> Le impostazioni si leggono a tre livelli: default del codice ← `leagues.settings` ←
> `league_seasons.settings`, e la sovrascrittura è **per foglia**: ritoccare il gol non
> azzera l'assist, e un parametro aggiunto al codice in futuro entra in vigore da solo
> invece di restare congelato al valore che aveva il giorno del salvataggio.
>
> Il gruppo tara le sue regole di casa una volta; una singola stagione può ritoccarle senza
> toccare le altre — così cambiare idea non riscrive il passato, e la classifica del 2023
> resta quella che tutti hanno letto allora. Si modificano da `/admin/regole`.
>
> ⚠️ Salvare **non ricalcola** le giornate già chiuse: per applicare una modifica al passato
> si rilancia la giornata, che è rieseguibile apposta.

### 6.4 Draft

```
drafts
  id, league_season_id, matchday
  state               ENUM('pending','open','closed')
  opens_at, deadline_at
  UNIQUE (league_season_id, matchday)

draft_pool                                       -- ★ garanzia di esclusività
  draft_id, player_id                 UNIQUE insieme
  status              ENUM('available','drawn')
  drawn_by_manager_id NULL
  INDEX (draft_id, status)

draft_turns
  id, draft_id
  round               TINYINT        -- 1..5
  pick_index          SMALLINT       -- 1..60, ordine snake già risolto
  manager_id
  state               ENUM('waiting','active','done')
  expires_at          DATETIME NULL
  opened_at           NULL
  opened_by           ENUM('manager','auto') NULL
  UNIQUE (draft_id, pick_index)
  INDEX (state, expires_at)          -- query del cron

cards                                            -- ★ la rosa vive qui
  id, league_season_id, matchday
  player_id
  tier                ENUM(...)      -- congelato alla pesca
  draft_turn_id                      -- provenienza
  owner_manager_id                   -- muta con gli scambi
  original_owner_id                  -- immutabile
  UNIQUE (league_season_id, matchday, player_id) -- ridondante col pool, ma è cintura+bretelle
  INDEX (owner_manager_id, matchday)
```

### 6.5 Scambi

```
trades
  id, league_season_id, matchday
  proposer_id, receiver_id
  state               ENUM('pending','accepted','rejected','cancelled','expired')
  created_at, resolved_at
  INDEX (league_season_id, matchday, state)

trade_items
  trade_id, card_id
  direction           ENUM('offered','requested')
```

### 6.6 Formazioni

```
lineups
  league_season_id, manager_id, matchday      UNIQUE insieme
  module              ENUM('3-4-3','3-5-2','4-3-3','4-4-2','4-5-1','5-3-2','5-4-1')
  state               ENUM('draft','locked')
  locked_at

lineup_slots
  lineup_id, card_id
  is_starter          BOOL
  bench_order         TINYINT NULL

lineup_results
  lineup_id
  totale              DECIMAL(6,2)
  sostituzioni        JSON           -- chi è entrato per chi, per il report
  computed_at
```

---

## 7. Concorrenza

**L'osservazione che semplifica tutto:** la struttura a turni snake **serializza già** la
pesca dal pool. Solo un manager alla volta può pescare, perché solo uno alla volta ha il
turno attivo. Non serve né coda né `SKIP LOCKED`.

Restano due sole corse reali:

1. **Manager apre ↔ cron sbusta d'ufficio nello stesso istante.**
   Lock sulla riga di `draft_turns` con `SELECT … FOR UPDATE`, ricontrollo dello stato
   dentro la transazione, e chi arriva secondo trova `state = 'done'` ed esce.

2. **Due scambi incrociati accettati simultaneamente.**
   Transazione con lock sulle carte coinvolte, acquisito **in ordine di `card_id`
   crescente** per evitare deadlock. Rivalidazione della schierabilità dentro la
   transazione, non prima.

`UNIQUE (draft_id, player_id)` su `draft_pool` è la rete finale: anche a fronte di un bug
logico il database rifiuta la doppia assegnazione.

---

## 8. Stack e deploy (Hostpoint)

```
Laravel 12  +  MariaDB  +  React/Vite (build statico)  →  PWA
```

> **Perché Laravel 12 e non 13.** Laravel 13 richiede PHP ^8.3. Il PHP locale è 8.2.8 e il
> riferimento noto su Hostpoint è `/usr/local/php82`. Se il Control Panel espone PHP 8.3+
> si può salire, ma va verificato prima — non dopo aver scritto il codice.

> **Il progetto vive in `C:\projects\fantasbusta`.** Non su `N:`, che è una share di rete
> (`\\DS920\NAS`): Composer fallisce l'installazione su SMB perché crea ed elimina archivi
> temporanei a raffica, e comunque `vendor/` (~10k file) e `node_modules/` (~30k) renderebbero
> penoso ogni comando. La NAS resta buona come remote git o backup.

> **⚠️ SQLite in locale non verifica i lock.** Lo sviluppo usa SQLite per comodità, ma
> `lockForUpdate()` su SQLite **non fa nulla** e Laravel non protesta. Tutta la logica di
> concorrenza del §7 — il lock sul turno, i lock ordinati per `card_id` negli scambi —
> passerebbe i test in locale senza essere mai stata realmente esercitata. Le prove di
> concorrenza vanno fatte su **MariaDB**, non su SQLite.

> **⚠️ Encoding dei file.** Due trappole già incontrate, entrambe fatali in produzione e
> silenziose in locale:
> - `Out-File -Encoding utf8` di PowerShell 5.1 scrive **UTF-8 con BOM**. Tre byte prima di
>   `<?php` significano output prima degli header. Usare `[System.IO.File]::WriteAllText`
>   con `UTF8Encoding($false)`, o gli strumenti di edit dell'editor.
> - Una riga di crontab dentro un **docblock PHP** rompe il file: la sequenza `*/` di `*/5`
>   chiude il commento a blocco. Gli esempi di cron vanno in commenti `//`.

- **PHP 8.2** — la shell può avere un default diverso dal web server, usare
  `/usr/local/php82/bin/php` esplicito ovunque
- **Composer** non preinstallato, si installa in `~/bin` via SSH (nessun root richiesto)
- **Node solo in locale** per il build Vite; sull'hosting si caricano gli asset compilati
- **Cron**, intervallo minimo 5 min — sufficiente, i turni durano decine di minuti:

  ```
  */5 * * * * /usr/local/php82/bin/php /home/UTENTE/www/artisan draft:tick >/dev/null 2>&1
  ```

  `draft:tick` fa una cosa sola: trova i turni scaduti, sbusta d'ufficio, avanza la coda.

- **⚠️ Da verificare nel Control Panel:** possibilità di puntare il document root a una
  sottocartella (Laravel serve da `/public`). Se non disponibile, workaround standard con
  il contenuto di `public/` nella webroot e i path corretti in `index.php`.
- **⚠️ Line ending:** Hostpoint rifiuta i cron con line break DOS. `.gitattributes` con
  `* text eol=lf` fin dal primo commit.

**Notifiche.** Canale primario **bot Telegram** (gratuito, una chiamata HTTP da PHP,
arriva ovunque). Push PWA come extra — su iOS funzionano solo se l'utente ha davvero fatto
"Aggiungi a Home", troppo fragile per una notifica critica come *"tocca a te sbustare"*.

**Immagini.** `https://media.api-sports.io/football/players/{id}.png`, stesso spazio di id
delle statistiche: zero mapping. API-Sports dichiara di non detenere i diritti sulle
immagini e le fornisce a scopo identificativo — irrilevante per una lega privata, da
rivedere se il progetto diventa pubblico. Fallback a licenza pulita: Wikidata P18, come
override manuale.

---

## 9. Aperto / da decidere

- Formula esatta di normalizzazione `rating` API-Football → scala fantacalcio
- Modificatore di difesa: incluso o no
- Sistema di crediti (attualmente non previsto: niente reroll, niente mercato a valuta —
  gli scambi sono solo carta contro carta)
- Cosa succede se un manager non schiera la formazione entro la deadline
