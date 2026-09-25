#!/usr/bin/env bash
#
# Mette in servizio quello che è appena stato caricato. Si lancia SUL SERVER,
# dalla cartella del progetto:
#
#   cd ~/www/naephil.ch/sbusta && bash deploy/pubblica.sh
#
# Si può rilanciare a ogni aggiornamento: non ricrea niente che esista già e
# non tocca il .env.

set -euo pipefail

cd "$(dirname "$0")/.."

PHP="${PHP:-php}"

echo "── PHP: $($PHP -v | head -1)"

if [ ! -f .env ]; then
  echo
  echo "  ✗ Manca .env. Scrivilo prima (vedi docs/HOSTPOINT.md §3),"
  echo "    poi rilancia. Senza, il resto non ha senso."
  exit 1
fi

echo
echo "── Permessi"
# ⚠️ L'archivio nasce su Windows, che non ha i permessi unix: tar ci scrive
# dentro 777 sulle cartelle e 666 sui file. Su un hosting condiviso con suExec
# — Hostpoint lo usa — Apache si RIFIUTA di servire roba scrivibile da tutti, e
# risponde «Forbidden» senza spiegare perché. È anche un problema di
# sicurezza a sé: su una macchina condivisa, scrivibile da tutti vuol dire
# proprio da tutti.
find . -type d -not -path './vendor/*' -exec chmod 755 {} +
find . -type f -not -path './vendor/*' -exec chmod 644 {} +

# Queste due devono restare scrivibili dall'applicazione, o non parte nemmeno.
chmod -R 775 storage bootstrap/cache
chmod 755 artisan

# ⚠️ Il .env non deve essere leggibile dagli altri utenti della macchina:
# contiene la password del database e la chiave API.
if [ -f .env ]; then chmod 600 .env; fi

echo "  cartelle 755, file 644, storage scrivibile, .env riservato"

echo
echo "── Dipendenze"
composer install --no-dev --optimize-autoloader --no-interaction

echo
echo "── Chiave"
# `key:generate` sovrascriverebbe una chiave esistente, e cambiarla scollega
# tutti e rende illeggibile qualunque dato cifrato: si tocca solo se è vuota.
if grep -qE '^APP_KEY=.+' .env; then
  echo "  già presente, non la tocco"
else
  $PHP artisan key:generate --force
fi

echo
echo "── Database"
$PHP artisan migrate --force

# Rieseguibile: crea il gruppo e l'amministratore se non ci sono, e non tocca
# nulla se ci sono già. Senza questo passaggio si finisce con un'installazione
# perfetta e nessuno che possa amministrarla.
$PHP artisan db:seed --force

echo
echo "── Storage"
# Idempotente: se il link c'è già non fa niente e non protesta.
$PHP artisan storage:link || true

echo
echo "── Cache"
# ⚠️ Prima si azzera e poi si ricostruisce: config:cache su una cache vecchia
# lascerebbe in vigore i valori di prima, ed è il motivo per cui «ho cambiato
# il .env e non cambia niente» è la lamentela più comune di ogni deploy.
$PHP artisan config:clear
$PHP artisan route:clear
$PHP artisan view:clear

$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

echo
echo "── Controllo finale"

# ⚠️ L'esito del controllo NON è l'esito della pubblicazione: il codice è su,
# funzionante, e mancano semmai delle scelte da fare a schermo. Confonderli
# farebbe leggere «pubblicazione fallita» a chi invece ha solo da creare una
# stagione. Si distingue con un codice d'uscita a parte.
set +e
$PHP artisan controlla
ESITO=$?
set -e

RIGA="* * * * * $(command -v php) $(pwd)/artisan schedule:run >/dev/null 2>&1"

echo
if crontab -l 2>/dev/null | grep -qF "$(pwd)/artisan schedule:run"; then
  echo "Il cron è già in crontab."
else
  echo "Il cron non è in crontab. ⚠️ La riga NON va lanciata nella shell —"
  echo "gli asterischi diventerebbero i nomi dei file e uscirebbe «command"
  echo "not found». Va installata, e questo comando lo fa (ripetibile: prima"
  echo "toglie l'eventuale riga vecchia di questa stessa applicazione):"
  echo
  echo "  ( crontab -l 2>/dev/null | grep -Fv '$(pwd)/artisan schedule:run'; \\"
  echo "    echo '$RIGA' ) | crontab -"
fi

echo
echo "Poi aspetta un minuto e rilancia \`php artisan controlla\`: se funziona,"
echo "alla voce «Cron» comparirà l'ora dell'ultimo giro. Si vede anche da"
echo "schermo, in cima a /admin/gestione."

if [ "$ESITO" -ne 0 ]; then
  echo
  echo "Il codice è a posto: sono le voci col segno x qui sopra da sistemare."
  exit 2
fi
