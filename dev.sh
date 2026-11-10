#!/bin/bash
# DEV-Umgebungen für nc_nexus (Docker Compose).
#
# Nutzung:  ./dev.sh <befehl> [optionen]
#
# Befehle:
#   up        Umgebung starten (beim ersten Mal: installieren und Apps aktivieren)
#   setup     Apps erneut aktivieren/installieren (idempotent)
#   stop      Container anhalten (Daten bleiben)
#   down      Container entfernen (Daten bleiben in den Volumes)
#   destroy   Container UND Volumes löschen (frische Instanz beim nächsten up)
#   occ ...   occ in der Umgebung ausführen, z. B. ./dev.sh occ app:list
#   logs      Logs des Nextcloud-Containers verfolgen
#   shell     Bash im Nextcloud-Container (www-data)
#   info      Projektname, Image und URL der gewählten Umgebung ausgeben
#   ls        alle nc_nexus-Umgebungen auflisten
#   e2e ...   E2E-Tests (Playwright über ~/projects/e2e-runner) gegen die Umgebung ausführen,
#             weitere Argumente gehen an playwright test, z. B. ./dev.sh e2e --db pgsql specs/demands.spec.js
#
# Optionen (auch als Umgebungsvariable NX_ENV, NX_DB, NX_NC, NX_PHP, NX_PORT):
#   --env simple|shared       simple: nur nc_nexus · shared: plus Apps aus docker-dev/shared-apps.conf
#                             (Default: simple)
#   --db  mariadb|pgsql|sqlite (Default: mariadb)
#   --nc  34|35               Nextcloud-Hauptversion (Default: 35)
#   --php 8.5|8.4|8.3         8.5 = offizielles Image; ältere Versionen werden aus docker-dev/php gebaut
#                             (Default: 8.5)
#   --port N                  Host-Port (Default: aus den Optionen berechnet, siehe info)
#
# Jede Kombination ist ein eigenes Compose-Projekt mit eigenen Volumes und eigenem Port,
# mehrere können parallel laufen. Anmeldung: admin / admin123 (NX_ADMIN_PASSWORD).
set -euo pipefail

ROOT=$(cd "$(dirname "$0")" && pwd)
DEV="$ROOT/docker-dev"

NX_ENV=${NX_ENV:-simple}
NX_DB=${NX_DB:-mariadb}
NX_NC=${NX_NC:-35}
NX_PHP=${NX_PHP:-8.5}
NX_PORT=${NX_PORT:-}
E2E_PASSWORD=${E2E_PASSWORD:-e2e-test-Pass123!}

usage() { sed -n '2,31p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-1}"; }
die() { echo "FEHLER: $*" >&2; exit 1; }

[ $# -ge 1 ] || usage
CMD=$1; shift
ARGS=()
while [ $# -gt 0 ]; do
	case "$1" in
	--env) NX_ENV=$2; shift 2 ;;
	--db) NX_DB=$2; shift 2 ;;
	--nc) NX_NC=$2; shift 2 ;;
	--php) NX_PHP=$2; shift 2 ;;
	--port) NX_PORT=$2; shift 2 ;;
	-h | --help) usage 0 ;;
	*) ARGS+=("$1"); shift ;;
	esac
done

case "$NX_ENV" in simple) env_i=0 ;; shared) env_i=1 ;; *) die "--env simple|shared" ;; esac
case "$NX_DB" in mariadb) db_i=0 ;; pgsql) db_i=1 ;; sqlite) db_i=2 ;; *) die "--db mariadb|pgsql|sqlite" ;; esac
case "$NX_NC" in 35) nc_i=0 ;; 34) nc_i=1 ;; *) die "--nc 34|35" ;; esac
case "$NX_PHP" in 8.5) php_i=0 ;; 8.4) php_i=1 ;; 8.3) php_i=2 ;; *) die "--php 8.5|8.4|8.3" ;; esac

# Ports 8092–8127: je 6 Ports pro NC-Version, je 12 pro PHP-Version
NX_PORT=${NX_PORT:-$((8092 + env_i * 3 + db_i + nc_i * 6 + php_i * 12))}
NX_PROJECT="nc-nexus-${NX_ENV}-${NX_DB}-nc${NX_NC}"
if [ "$NX_PHP" = "8.5" ]; then
	NX_IMAGE="nextcloud:${NX_NC}-apache"
else
	NX_IMAGE="nc-nexus/nextcloud:${NX_NC}-php${NX_PHP}"
	NX_PROJECT="${NX_PROJECT}-php${NX_PHP/./}"
fi
export NX_PORT NX_PROJECT NX_IMAGE

# Standard (einfach, MariaDB, NC 35, PHP 8.5) = docker-compose.yml unter dem Namen nc-nexus-dev,
# also dieselbe Instanz wie `docker compose up -d` in docker-dev/
[ "$NX_PROJECT" = "nc-nexus-simple-mariadb-nc35" ] && NX_PROJECT=nc-nexus-dev
export NX_PROJECT
if [ "$NX_DB" = "mariadb" ]; then
	FILES=(-f "$DEV/docker-compose.yml")
else
	FILES=(-f "$DEV/compose.base.yml" -f "$DEV/compose.$NX_DB.yml")
fi

# Shared-Umgebung: Mount-Overlay aus shared-apps.conf erzeugen (nur vorhandene Pfade)
GEN="$DEV/.generated"
if [ "$NX_ENV" = "shared" ]; then
	mkdir -p "$GEN"
	overlay="$GEN/shared-mounts.yml"
	{
		echo "# erzeugt von dev.sh aus shared-apps.conf – nicht bearbeiten"
		echo "services:"
		echo "  nextcloud:"
		echo "    volumes:"
		while read -r kind app path; do
			[ "$kind" = "mount" ] || continue
			src=$(cd "$ROOT" && realpath -m "$path")
			if [ -f "$src/appinfo/info.xml" ]; then
				echo "      - $src:/custom_apps/$app:ro"
			fi
		done < <(grep -Ev '^\s*(#|$)' "$DEV/shared-apps.conf")
	} > "$overlay"
	grep -q -- "- " "$overlay" || echo "      []" >> "$overlay"
	FILES+=(-f "$overlay")
fi

dc() { docker compose -p "$NX_PROJECT" --project-directory "$DEV" "${FILES[@]}" "$@"; }
occ() { dc exec -T --user www-data nextcloud php occ "$@"; }

build_image() {
	[ "$NX_PHP" = "8.5" ] && return 0
	if ! docker image inspect "$NX_IMAGE" >/dev/null 2>&1; then
		echo "Baue $NX_IMAGE ..."
		docker build --build-arg NC="$NX_NC" --build-arg PHP="$NX_PHP" -t "$NX_IMAGE" "$DEV/php"
	fi
}

wait_installed() {
	local i=0
	until occ status 2>/dev/null | grep -q "installed: true"; do
		i=$((i + 1))
		[ $i -le 60 ] || die "Nextcloud nach 5 Minuten nicht installiert – ./dev.sh logs"
		echo "warte auf Nextcloud-Installation ..."
		sleep 5
	done
	# „installed: true“ gilt schon vor den Post-Install-Hooks; erst danach kennt Nextcloud /custom_apps
	until dc exec -T nextcloud test -f /var/www/html/config/zz-dev-apps-paths.config.php </dev/null; do
		i=$((i + 1))
		[ $i -le 60 ] || die "Post-Install-Hook nicht gelaufen – ./dev.sh logs"
		echo "warte auf Post-Install-Hook ..."
		sleep 2
	done
}

setup_apps() {
	wait_installed
	occ app:disable firstrunwizard >/dev/null 2>&1 || true
	occ config:system:set trusted_domains 1 --value=host.docker.internal >/dev/null
	occ background:cron >/dev/null || true
	if [ "$NX_ENV" = "shared" ]; then
		# Liste vorab lesen: docker compose exec im Schleifenrumpf würde sonst stdin leeren
		mapfile -t entries < <(grep -Ev '^\s*(#|$)' "$DEV/shared-apps.conf")
		for entry in "${entries[@]}"; do
			read -r kind app _ <<<"$entry"
			case "$kind" in
			mount)
				if dc exec -T nextcloud test -f "/custom_apps/$app/appinfo/info.xml"; then
					occ app:enable "$app" || echo "WARNUNG: $app ließ sich nicht aktivieren"
				else
					echo "Hinweis: $app nicht gemountet (Projekt fehlt) – übersprungen"
				fi
				;;
			store)
				occ app:install "$app" >/dev/null 2>&1 || occ app:enable "$app" >/dev/null 2>&1 \
					|| echo "WARNUNG: $app ließ sich nicht installieren (kompatible Version im App-Store?)"
				;;
			esac
		done
	fi
	occ app:enable nc_nexus
	# Testkonten für die E2E-Tests (nc_nexus/tests/e2e), ohne Adminrechte
	for user in e2e e2e-other; do
		if ! occ user:info "$user" >/dev/null 2>&1; then
			dc exec -T --user www-data -e OC_PASS="$E2E_PASSWORD" nextcloud \
				php occ user:add --password-from-env --display-name "E2E ${user}" "$user" >/dev/null
			echo "Testkonto $user angelegt"
		fi
	done
}

info() {
	echo "Umgebung: $NX_ENV · DB: $NX_DB · Nextcloud $NX_NC · PHP $NX_PHP"
	echo "Projekt:  $NX_PROJECT"
	echo "Image:    $NX_IMAGE"
	echo "URL:      http://localhost:$NX_PORT  (admin / ${NX_ADMIN_PASSWORD:-admin123})"
}

case "$CMD" in
up)
	build_image
	dc up -d
	setup_apps
	info
	;;
setup) setup_apps ;;
stop) dc stop ;;
down) dc down ;;
destroy) dc down -v ;;
occ) occ "${ARGS[@]}" ;;
logs) dc logs -f nextcloud ;;
shell) dc exec --user www-data nextcloud bash ;;
info) info ;;
ls) docker compose ls -a --filter name=nc-nexus- ;;
e2e) BASE_URL="http://host.docker.internal:$NX_PORT" E2E_PASSWORD="$E2E_PASSWORD" sh "$ROOT/nc_nexus/tests/e2e/run.sh" "${ARGS[@]}" ;;
*) usage ;;
esac
