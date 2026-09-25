# Carica e pubblica su Hostpoint, in un colpo solo. Da PowerShell:
#
#   .\deploy\carica.ps1
#
# Fa l'archivio, lo manda, manda il .env, scompatta e lancia pubblica.sh.
#
# Opzioni utili:
#   -SoloCarica     carica ma non pubblica (niente composer, migrazioni, cache)
#   -SenzaEnv       non tocca il .env che sta gia' sul server
#   -SenzaArchivio  riusa deploy/fantasbusta.tar.gz invece di rifarlo
#   -Annata 2023    porta anche i dati dell'annata: esporta qui, importa la'
#
# ⚠️ L'archivio contiene SOLO il codice. Squadre, listone, calendario e
# statistiche stanno nel database, e il database non viaggia: senza -Annata,
# rilanciare questo script non porta di la' niente di quello che hai scaricato.
# E' l'aspettativa naturale e sbagliata, quindi c'e' l'opzione.

param(
    [string] $Server   = 'naephilc@naephilc.ssh.cloud.hostpoint.ch',
    [string] $Cartella = 'www/naephil.ch/sbusta',
    # Hostpoint accetta questo; senza, il trasferimento muore con
    # «Corrupted MAC on input» a meta' strada.
    [string] $Mac      = 'hmac-sha2-512',
    [string] $FileEnv  = 'deploy/env-produzione',
    [int]    $Annata   = 0,
    [switch] $SoloCarica,
    [switch] $SenzaEnv,
    [switch] $SenzaArchivio
)

$ErrorActionPreference = 'Stop'

Set-Location (Join-Path $PSScriptRoot '..')

$archivio = 'deploy/fantasbusta.tar.gz'
$opzioni  = @('-o', "MACs=$Mac")

function Passo([string] $testo) {
    Write-Host ''
    Write-Host "--- $testo" -ForegroundColor Cyan
}

function Fermati([string] $testo) {
    Write-Host "  [!!] $testo" -ForegroundColor Red
    exit 1
}

# ── 1. L'archivio ──────────────────────────────────────────────────────────
if (-not $SenzaArchivio) {
    Passo 'Preparo l''archivio'
    & (Join-Path $PSScriptRoot 'prepara.ps1')
    if ($LASTEXITCODE -ne 0) { Fermati 'La preparazione non e'' riuscita.' }
}

if (-not (Test-Path $archivio)) {
    Fermati "Manca $archivio. Togli -SenzaArchivio, oppure lancia prima prepara.ps1."
}

# ── 2. Il .env ─────────────────────────────────────────────────────────────
# ⚠️ Si carica PRIMA di pubblicare: pubblica.sh si ferma se non lo trova, e
# senza i dati del database le migrazioni fallirebbero comunque.
if (-not $SenzaEnv) {
    if (-not (Test-Path $FileEnv)) {
        Write-Host ''
        Write-Host "  [!!] Manca $FileEnv." -ForegroundColor Red
        Write-Host '       Fallo cosi'', una volta sola:' -ForegroundColor Yellow
        Write-Host '         copy deploy\env-produzione.esempio deploy\env-produzione' -ForegroundColor Yellow
        Write-Host '         notepad deploy\env-produzione' -ForegroundColor Yellow
        Write-Host '       Poi rilancia. Se il .env sul server e'' gia'' a posto, usa -SenzaEnv.' -ForegroundColor Yellow
        exit 1
    }

    # ⚠️ Si controlla col PARSER VERO, non a occhio: una regex scritta a mano
    # sbaglia proprio i casi che contano. Il primo .env rotto di questo progetto
    # era «APP_KEY=<spazi>< si genera al primo avvio», e una regola improvvisata
    # non lo intercettava perche' dopo l'uguale c'erano spazi e sembrava un
    # valore vuoto legittimo. Qui gira lo stesso dotenv che girera' sul server.
    Passo 'Controllo il .env'
    & php deploy/verifica-env.php $FileEnv

    if ($LASTEXITCODE -ne 0) {
        Write-Host ''
        Write-Host '       Correggi e rilancia. Se il .env sul server e'' gia'' a posto, usa -SenzaEnv.' -ForegroundColor Yellow
        exit 1
    }
}

# ── 2bis. Il pacchetto dell'annata ─────────────────────────────────────────
# Si esporta PRIMA di trasferire: se l'esportazione non riesce e' meglio
# scoprirlo qui che a meta' del caricamento.
$pacchetto = $null

if ($Annata -gt 0) {
    Passo "Impacchetto l'annata $Annata"

    & php artisan annata:esporta $Annata
    if ($LASTEXITCODE -ne 0) { Fermati 'L''esportazione non e'' riuscita.' }

    $pacchetto = "storage/app/serie-a-$Annata.jsonl"

    if (-not (Test-Path $pacchetto)) { Fermati "Non trovo $pacchetto." }
}

# ── 3. Il trasferimento ────────────────────────────────────────────────────
Passo "Carico su $Server"

Write-Host "  archivio -> $Cartella/"
& scp @opzioni $archivio "${Server}:$Cartella/"
if ($LASTEXITCODE -ne 0) { Fermati 'Il caricamento dell''archivio non e'' riuscito.' }

if (-not $SenzaEnv) {
    Write-Host "  $FileEnv -> $Cartella/.env"
    & scp @opzioni $FileEnv "${Server}:$Cartella/.env"
    if ($LASTEXITCODE -ne 0) { Fermati 'Il caricamento del .env non e'' riuscito.' }
}

if ($pacchetto) {
    $peso = [math]::Round((Get-Item $pacchetto).Length / 1MB, 1)
    Write-Host "  annata $Annata ($peso MB) -> $Cartella/"
    & scp @opzioni $pacchetto "${Server}:$Cartella/"
    if ($LASTEXITCODE -ne 0) { Fermati 'Il caricamento dell''annata non e'' riuscito.' }
}

# ── 4. Scompattare, e semmai pubblicare ────────────────────────────────────
Passo 'Scompatto sul server'

# `tar xzf` sovrascrive quello che trova: e' esattamente ciò che serve per un
# aggiornamento, e non tocca .env perche' nell'archivio non c'e'.
$remoto = "cd '$Cartella' && tar xzf fantasbusta.tar.gz && rm -f fantasbusta.tar.gz && echo '  scompattato'"

if (-not $SoloCarica) {
    $remoto = "$remoto && bash deploy/pubblica.sh"
}

& ssh @opzioni $Server $remoto
$esito = $LASTEXITCODE

# 2 = pubblicato, ma il controllo finale ha trovato cose da sistemare. È
# diverso da «non ha funzionato»: il codice è su e gira.
if ($esito -ne 0 -and $esito -ne 2) {
    Fermati 'Qualcosa e'' andato storto sul server: guarda l''errore qui sopra.'
}

# ── 5. L'annata, dopo le migrazioni ────────────────────────────────────────
# ⚠️ Chiamata a parte e non in coda al comando di prima: pubblica.sh esce con 2
# quando il controllo finale ha trovato qualcosa, e un `&&` salterebbe
# l'importazione per un motivo che non c'entra niente. Comunque dopo, mai
# prima: senza migrazioni le tabelle dove scrivere non esistono.
if ($pacchetto -and -not $SoloCarica) {
    Passo "Importo l'annata $Annata sul server"

    $nome = Split-Path $pacchetto -Leaf
    & ssh @opzioni $Server "cd '$Cartella' && php artisan annata:importa '$nome' && rm -f '$nome'"

    if ($LASTEXITCODE -ne 0) {
        Write-Host ''
        Write-Host "  [!] Il codice e'' pubblicato, ma l'annata non e'' entrata." -ForegroundColor Yellow
        Write-Host "      Il file e'' sul server: ssh $Server" -ForegroundColor Yellow
        Write-Host "      cd $Cartella && php artisan annata:importa $nome" -ForegroundColor Yellow
    }
} elseif ($pacchetto) {
    Write-Host ''
    Write-Host "  [!] Con -SoloCarica l'annata resta li' senza essere importata:" -ForegroundColor Yellow
    Write-Host "      le migrazioni non sono girate. Poi: php artisan annata:importa serie-a-$Annata.jsonl" -ForegroundColor Yellow
}

Write-Host ''

if ($SoloCarica) {
    Write-Host 'Caricato. Per pubblicare:' -ForegroundColor Green
    Write-Host "  ssh $Server"
    Write-Host "  cd $Cartella && bash deploy/pubblica.sh"
} elseif ($esito -eq 2) {
    Write-Host 'Pubblicato.' -ForegroundColor Green
    Write-Host 'Restano da sistemare le voci col segno x qui sopra.' -ForegroundColor Yellow
} else {
    Write-Host 'Pubblicato, e il controllo e'' pulito.' -ForegroundColor Green
}
