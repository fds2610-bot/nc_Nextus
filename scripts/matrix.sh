#!/bin/bash
# Kompatibilitätsmatrix: startet je Kombination eine einfache DEV-Umgebung, führt die
# E2E-Tests aus und hält sie danach wieder an (Daten bleiben, ./dev.sh destroy … löscht sie).
#
# Nutzung: scripts/matrix.sh [kombination …]   Kombination = db:nc:php, z. B. pgsql:34:8.3
# Default: alle drei Datenbanken auf NC 35/PHP 8.5, dazu NC 34 und PHP 8.3 mit MariaDB.
set -uo pipefail
cd "$(dirname "$0")/.."

COMBOS=("$@")
[ ${#COMBOS[@]} -gt 0 ] || COMBOS=(mariadb:35:8.5 pgsql:35:8.5 sqlite:35:8.5 mariadb:34:8.5 mariadb:35:8.3)

LOG=$(mktemp)
declare -A RESULT
for combo in "${COMBOS[@]}"; do
	IFS=: read -r db nc php <<<"$combo"
	opts=(--db "$db" --nc "$nc" --php "$php")
	echo "================ $combo"
	if ./dev.sh up "${opts[@]}" >"$LOG" 2>&1 && ./dev.sh e2e "${opts[@]}"; then
		RESULT[$combo]=OK
	else
		RESULT[$combo]=FEHLER
		tail -20 "$LOG"
	fi
	[ "${KEEP:-no}" = yes ] || ./dev.sh stop "${opts[@]}" >/dev/null 2>&1
done

echo "================ Ergebnis"
rc=0
for combo in "${COMBOS[@]}"; do
	printf '%-18s %s\n' "$combo" "${RESULT[$combo]}"
	[ "${RESULT[$combo]}" = OK ] || rc=1
done
exit $rc
