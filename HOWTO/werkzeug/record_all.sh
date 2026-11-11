#!/bin/bash
# Alle sechs Videos auf jeweils frisch aufgesetzter Instanz aufnehmen und mischen.
set -e
cd "$HOME/video-work"
for v in 01-antrag 02-bewertung 03-entscheidung 04-portfolio 05-freigabe 06-planung; do
  echo "=== $v"
  ./rec/fresh.sh
  node run.js $v --audio --dest "$HOME/video-work/final"
done
echo ALLES-FERTIG
