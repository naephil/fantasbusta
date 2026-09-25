# Prepara l'archivio da caricare su Hostpoint. Si lancia IN LOCALE, da PowerShell:
#
#   .\deploy\prepara.ps1
#
# Produce deploy\fantasbusta.tar.gz, da mandare con un solo scp.
#
# Esiste accanto a prepara.sh perche' in PowerShell il comando `bash` viene
# risolto con quello di WSL, non con quello di Git: se WSL non e' configurato
# lo script si rifiuta di partire con un errore che non parla di questo.
#
# ⚠️ `vendor/` NON entra nell'archivio: sul server si ricostruisce con Composer,
# che e' molto piu' veloce del caricamento e non costringe a spogliare
# l'ambiente locale delle dipendenze di sviluppo.

$ErrorActionPreference = 'Stop'

Set-Location (Join-Path $PSScriptRoot '..')

$archivio = 'deploy/fantasbusta.tar.gz'

Write-Host '--- Controlli prima di impacchettare'

# ⚠️ Gli asset compilati sono in .gitignore ma DEVONO essere caricati: senza,
# il sito esce senza stili e sembra rotto. E' l'errore che farebbe chi si limita
# a copiare cio' che sta in git.
if (-not (Test-Path 'public/build')) {
    Write-Host '  [!!] Manca public/build: lancia prima "npm run build".' -ForegroundColor Red
    exit 1
}
Write-Host '  [ok] Asset compilati presenti' -ForegroundColor Green

if (-not (Test-Path 'public/index.php')) {
    Write-Host '  [!!] Non sembra la radice del progetto.' -ForegroundColor Red
    exit 1
}

if (-not (Get-Command tar -ErrorAction SilentlyContinue)) {
    Write-Host '  [!!] Manca "tar". Su Windows 10/11 c-e in System32; altrimenti usa Git Bash.' -ForegroundColor Red
    exit 1
}

if (Test-Path $archivio) { Remove-Item $archivio -Force }

# Si elenca cosa ENTRA invece di cosa resta fuori: una lista di esclusioni si
# dimentica sempre di qualcosa, e la cosa dimenticata e' di solito .env.
#
# ⚠️ Le esclusioni vanno PRIMA dell'elenco: tar le applica solo a cio' che le
# segue, e messe in coda vengono ignorate con un avviso facile da non leggere.
$argomenti = @(
    '--exclude-vcs'
    '--exclude=storage/logs/*'
    '--exclude=storage/framework/cache/data/*'
    '--exclude=storage/framework/sessions/*'
    '--exclude=storage/framework/views/*'
    '--exclude=public/storage'
    '--exclude=public/hot'
    '--exclude=deploy/*.tar.gz'
    '--exclude=deploy/env-produzione'
    # ⚠️ Roba locale che non deve viaggiare: il database di sviluppo pesava da
    # solo più di tutto il resto, e sul server non servirebbe a niente perché
    # lì si usa MariaDB. Gli artefatti dei test idem.
    '--exclude=database/*.sqlite'
    '--exclude=storage/framework/testing'
    '--exclude=storage/app/*.jsonl'
    '--exclude=storage/app/public/*'
    '-czf'
    $archivio
    'app'; 'bootstrap'; 'config'; 'database'; 'lang'; 'public'
    'resources'; 'routes'; 'storage'
    'artisan'; 'composer.json'; 'composer.lock'
    'deploy'; 'docs'
    # Serve solo se la radice del sito punta alla cartella del progetto invece
    # che a public/: senza, in quel caso, l'intero albero e' scaricabile.
    '.htaccess'
)

& tar @argomenti

if ($LASTEXITCODE -ne 0) {
    Write-Host '  [!!] tar ha segnalato un problema.' -ForegroundColor Red
    exit 1
}

$peso = [math]::Round((Get-Item $archivio).Length / 1MB, 1)

Write-Host ''
Write-Host "--- Fatto: $archivio ($peso MB)" -ForegroundColor Green
Write-Host ''
Write-Host 'Ora, da qui:'
Write-Host "  scp $archivio UTENTE@naephil.ch:www/naephil.ch/sbusta/"
Write-Host ''
Write-Host 'e poi sul server:'
Write-Host '  cd ~/www/naephil.ch/sbusta'
Write-Host '  tar xzf fantasbusta.tar.gz'
Write-Host '  bash deploy/pubblica.sh'
