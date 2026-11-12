#!/bin/bash
# Demo-Konten anlegen und Beispieldaten einspielen (für eine frische DEV-Instanz).
#
#   scripts/seed-demo.sh [dev.sh-Optionen]      z. B. scripts/seed-demo.sh --db pgsql
#
# Fiktives Unternehmen: Brenner Maschinenbau GmbH (Details in seed-demo.py).
# Vorhandene App-Daten vorher löschen: ./dev.sh reset-data
#
# Konten (Passwort: DEMO_PASSWORD, Standard demo-Pass123!):
#   paula – Portfolio-Office (global)
#   boris – Geschäftsführung, Vorsitz Portfolio-Board
#   fiona – Finanzen und Controlling (Board)
#   anna  – Vertrieb und Service, Sponsorin
#   ben   – IT-Infrastruktur
#   sven  – Leitung IT, Pool-Management IT, Vorsitz Ressourcen-Board
#   kai   – Leitung Konstruktion (Pool-Management, Sponsor Technik)
#   olga  – Leitung OT-Softwareentwicklung (Pool-Management)
#   frank – Leitung Fertigung, Sponsor Produktion
#   mona  – Leitung Montage
#   lars  – Leitung Logistik
#   hanna – Personal
set -euo pipefail
cd "$(dirname "$0")/.."

export DEMO_PASSWORD="${DEMO_PASSWORD:-demo-Pass123!}"
port=$(./dev.sh info "$@" | sed -n 's|^URL: *http://localhost:\([0-9]*\).*|\1|p')
project=$(./dev.sh info "$@" | sed -n 's|^Projekt: *||p')

for entry in "anna:Anna Antrag" "ben:Ben Berger" "paula:Paula PMO" "boris:Boris Board" "sven:Sven Security" \
	"fiona:Fiona Finanzen" "kai:Kai Kessler" "olga:Olga Neumann" "frank:Frank Weber" "mona:Mona Keller" \
	"lars:Lars Brandt" "hanna:Hanna Roth"; do
	user=${entry%%:*}
	name=${entry#*:}
	if ! ./dev.sh occ user:info "$user" "$@" >/dev/null 2>&1; then
		docker exec --user www-data -e OC_PASS="$DEMO_PASSWORD" "${project}-nextcloud-1" \
			php occ user:add --password-from-env --display-name "$name" "$user" >/dev/null
		echo "Konto $user angelegt"
	fi
	# Demo-Konten auf Deutsch
	docker exec --user www-data "${project}-nextcloud-1" php occ user:setting "$user" core lang de >/dev/null
done

python3 -I scripts/seed-demo.py --url "http://localhost:$port"
