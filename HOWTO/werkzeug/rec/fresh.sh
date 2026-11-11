#!/bin/bash
# Aufnahme-Instanz frisch aufsetzen (nur die Kopie unter ~/video-work, Port 8097)
set -e
cd "$HOME/video-work/rec/nc-nexus"
O="--env shared --db sqlite --nc 35"
./dev.sh destroy $O >/dev/null 2>&1 || true
./dev.sh up $O > ../up3.log 2>&1
scripts/seed-demo.sh $O > ../seed.log 2>&1
echo FRESH-OK
