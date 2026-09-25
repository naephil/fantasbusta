#!/usr/bin/env bash
#
# Prepara l'archivio da caricare su Hostpoint. Si lancia IN LOCALE.
#
#   bash deploy/prepara.sh
#
# Produce deploy/fantasbusta.tar.gz, da mandare con un solo scp.
#
# ⚠️ `vendor/` NON entra nell'archivio: sul server si ricostruisce con
# composer, che è più veloce del caricamento e non richiede di spogliare
# l'ambiente locale delle dipendenze di sviluppo.

set -euo pipefail

cd "$(dirname "$0")/.."

ARCHIVIO="deploy/fantasbusta.tar.gz"

echo "── Controlli prima di impacchettare"

# ⚠️ Gli asset compilati sono in .gitignore ma DEVONO essere caricati: senza,
# il sito esce senza stili e sembra rotto. È l'errore che farebbe chi si limita
# a copiare ciò che sta in git.
if [ ! -d public/build ]; then
  echo "  ✗ Manca public/build: lancia prima 'npm run build'."
  exit 1
fi
echo "  ✓ Asset compilati presenti"

if [ ! -f public/index.php ]; then
  echo "  ✗ Non sembra la radice del progetto."
  exit 1
fi

rm -f "$ARCHIVIO"
mkdir -p deploy

# Si elenca cosa ENTRA invece di cosa resta fuori: una lista di esclusioni si
# dimentica sempre di qualcosa, e la cosa dimenticata è di solito .env.
#
# ⚠️ Le esclusioni vanno PRIMA dell'elenco: tar le applica solo a ciò che le
# segue, e messe in coda vengono ignorate con un avviso facile da non leggere.
tar --exclude-vcs \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='public/storage' \
  --exclude='public/hot' \
  --exclude='deploy/*.tar.gz' \
  --exclude='deploy/env-produzione'   --exclude='database/*.sqlite'   --exclude='storage/framework/testing'   --exclude='storage/app/*.jsonl'   --exclude='storage/app/public/*' \
  -czf "$ARCHIVIO" \
  app bootstrap config database lang public resources routes storage \
  artisan composer.json composer.lock \
  deploy docs \
  .htaccess

echo
echo "── Fatto: $ARCHIVIO ($(du -h "$ARCHIVIO" | cut -f1))"
echo
echo "Ora, da qui:"
echo "  scp $ARCHIVIO UTENTE@naephil.ch:www/naephil.ch/sbusta/"
echo
echo "e poi sul server:"
echo "  cd ~/www/naephil.ch/sbusta && tar xzf fantasbusta.tar.gz && bash deploy/pubblica.sh"
