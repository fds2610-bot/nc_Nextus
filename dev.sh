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
#   e2e-restore DIR  Sicherung eines abgebrochenen E2E-Laufs einspielen (Ordner nennt e2e bei Fehlern)
#   reset-data  alle App-Daten (nx_*-Tabellen) und Nexus-Benachrichtigungen löschen; Startmodelle
#             (Kriterien, Gate-Sets, KPIs) legt die App beim nächsten Aufruf neu an
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
	# Neue App-Versionen (Migrationen) aus dem Live-Mount übernehmen; ohne Änderung ein No-op.
	# Wiederholen, falls der Container gerade neu startet – sonst bleibt Nextcloud auf der Update-Seite.
	local try
	for try in 1 2 3 4 5 6 7 8 9 10; do
		if occ upgrade --no-interaction </dev/null | grep -v "^No upgrade required"; [ "${PIPESTATUS[0]}" -eq 0 ]; then
			break
		fi
		[ $try -lt 10 ] || die "occ upgrade schlägt fehl – ./dev.sh logs"
		echo "occ upgrade nicht möglich, neuer Versuch ..."
		sleep 3
	done
	occ app:disable firstrunwizard >/dev/null 2>&1 || true
	occ config:system:set trusted_domains 1 --value=host.docker.internal >/dev/null
	# Planungstools anderer DEV-Stacks (z. B. nc-gantt auf http://host.docker.internal:8088) erreichbar machen
	occ config:system:set allow_local_remote_servers --type=boolean --value=true >/dev/null
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
	# DEV-Dummy für die KI-Assistenz (feste Antworten, kein Sprachmodell)
	if dc exec -T nextcloud test -f /custom_apps/nexus_dev_ai/appinfo/info.xml </dev/null; then
		occ app:enable nexus_dev_ai >/dev/null || echo "WARNUNG: nexus_dev_ai ließ sich nicht aktivieren"
	fi
	# Testkonten für die E2E-Tests (nc_nexus/tests/e2e), ohne Adminrechte
	for user in e2e e2e-other; do
		if ! occ user:info "$user" >/dev/null 2>&1; then
			# der Post-Install-Hook legt die Konten bei einer Neuinstallation parallel an
			if dc exec -T --user www-data -e OC_PASS="$E2E_PASSWORD" nextcloud \
				php occ user:add --password-from-env --display-name "E2E ${user}" "$user" >/dev/null 2>&1; then
				echo "Testkonto $user angelegt"
			elif ! occ user:info "$user" >/dev/null 2>&1; then
				die "Testkonto $user ließ sich nicht anlegen"
			fi
		fi
	done
}

info() {
	echo "Umgebung: $NX_ENV · DB: $NX_DB · Nextcloud $NX_NC · PHP $NX_PHP"
	echo "Projekt:  $NX_PROJECT"
	echo "Image:    $NX_IMAGE"
	echo "URL:      http://localhost:$NX_PORT  (admin / ${NX_ADMIN_PASSWORD:-admin123})"
}

# ---- E2E mit Datensicherung -------------------------------------------------------------------
# Die Tests legen Anträge, Org-Einheiten, Pools usw. an, die sich teils nicht löschen lassen
# (eingereichte Anträge bleiben im Register). Darum werden die App-Tabellen (nx_*) vor dem Lauf
# gesichert und danach – auch bei Abbruch der Tests – wieder eingespielt. Beispieldaten bleiben.
# NX_E2E_KEEP=1 lässt die Testdaten stehen (z. B. zur Fehlersuche).
db_tables() {
	case "$NX_DB" in
	mariadb) dc exec -T db mariadb -uroot -p"${NX_DB_PASSWORD:-nextcloud}" nextcloud -N -e "show tables like 'oc\\_nx\\_%'" 2>/dev/null ;;
	pgsql) dc exec -T db psql -U nextcloud -d nextcloud -At -c "select tablename from pg_tables where tablename like 'oc\\_nx\\_%'" ;;
	esac | tr -d '\r'
}

snapshot_save() { # $1 = Zieldatei
	case "$NX_DB" in
	mariadb)
		dc exec -T db mariadb-dump -uroot -p"${NX_DB_PASSWORD:-nextcloud}" --no-create-info --skip-triggers --complete-insert nextcloud $(db_tables) >"$1" ;;
	pgsql)
		dc exec -T db pg_dump -U nextcloud -d nextcloud --data-only $(db_tables | sed 's/^/-t /') >"$1" ;;
	sqlite)
		dc exec -T --user www-data nextcloud cp /var/www/html/data/nextcloud.db /tmp/nx-e2e-snapshot.db ;;
	esac
}

# App-Konfiguration von nc_nexus (Schwellenwerte, Adresse von OpenProject …) generisch sichern
# und wiederherstellen: Schlüssel, die der Lauf neu angelegt hat, werden gelöscht, alle anderen
# auf den gesicherten Wert gesetzt. Versionsschlüssel der App bleiben unberührt.
config_save() { # $1 = Zieldatei (JSON)
	occ config:list nc_nexus --private >"$1"
}

config_restore() { # $1 = Sicherungsdatei
	local action key value
	# je Zeile: „del <key>“ oder „set <key> <wert-base64>“; occ bekommt /dev/null als stdin,
	# sonst liest docker exec die restlichen Zeilen der Schleife weg
	occ config:list nc_nexus --private </dev/null | python3 -c '
import base64, json, sys
skip = {"enabled", "installed_version", "types"}
before = json.load(open(sys.argv[1]))["apps"].get("nc_nexus", {})
now = json.load(sys.stdin)["apps"].get("nc_nexus", {})
for k in now:
    if k not in before and k not in skip:
        print("del", k)
for k, v in before.items():
    if k not in skip and now.get(k) != v:
        print("set", k, base64.b64encode(str(v).encode()).decode() or "-")' "$1" |
		while read -r action key value; do
			if [ "$action" = del ]; then
				occ config:app:delete nc_nexus "$key" </dev/null >/dev/null
			else
				[ "$value" = - ] && value='' || value=$(printf %s "$value" | base64 -d)
				occ config:app:set nc_nexus "$key" --value="$value" </dev/null >/dev/null
			fi
		done
}

# Gespeicherte Zugangsdaten von nc_nexus (App-Passwort der Gantt-Instanz, API-Schlüssel je Konto)
# liegen im Credential-Store von Nextcloud. Die Zeilen bleiben verschlüsselt, wie sie sind.
CREDS_WHERE="identifier LIKE 'nc_nexus::%'"
creds_save() { # $1 = Zieldatei
	case "$NX_DB" in
	mariadb)
		dc exec -T db mariadb-dump -uroot -p"${NX_DB_PASSWORD:-nextcloud}" --no-create-info --skip-triggers --complete-insert \
			--where="$CREDS_WHERE" nextcloud oc_storages_credentials </dev/null >"$1" ;;
	pgsql)
		dc exec -T db psql -U nextcloud -d nextcloud -c "COPY (SELECT \"user\", identifier, credentials FROM oc_storages_credentials WHERE $CREDS_WHERE) TO STDOUT" </dev/null >"$1" ;;
	sqlite) : ;; # steckt in der Kopie der Datenbank
	esac
}

creds_restore() { # $1 = Sicherungsdatei
	case "$NX_DB" in
	mariadb)
		{ echo "DELETE FROM oc_storages_credentials WHERE $CREDS_WHERE;"; cat "$1"; } \
			| dc exec -T db mariadb -uroot -p"${NX_DB_PASSWORD:-nextcloud}" nextcloud ;;
	pgsql)
		{ echo "DELETE FROM oc_storages_credentials WHERE $CREDS_WHERE;"
			echo "COPY oc_storages_credentials (\"user\", identifier, credentials) FROM STDIN;"; cat "$1"; echo '\.'; } \
			| dc exec -T db psql -q -v ON_ERROR_STOP=1 -U nextcloud -d nextcloud >/dev/null ;;
	sqlite) : ;;
	esac
}

snapshot_restore() { # $1 = Sicherungsdatei, $2 = Startzeit des Laufs (Unix)
	local tables
	# Benachrichtigungen und KI-Aufträge, die Nexus während des Laufs erzeugt hat, gehören zu den Testdaten
	local notes="DELETE FROM oc_notifications WHERE app = 'nc_nexus' AND timestamp >= ${2:-0};
DELETE FROM oc_taskprocessing_tasks WHERE app_id = 'nc_nexus' AND last_updated >= ${2:-0};"
	case "$NX_DB" in
	mariadb)
		{ echo 'SET FOREIGN_KEY_CHECKS=0;'; for t in $(db_tables); do echo "TRUNCATE $t;"; done; cat "$1"; echo "$notes"; } \
			| dc exec -T db mariadb -uroot -p"${NX_DB_PASSWORD:-nextcloud}" nextcloud ;;
	pgsql)
		tables=$(db_tables | paste -sd,)
		{ echo "TRUNCATE $tables RESTART IDENTITY;"; cat "$1"; echo "$notes"; } \
			| dc exec -T db psql -q -v ON_ERROR_STOP=1 -U nextcloud -d nextcloud >/dev/null ;;
	sqlite)
		dc exec -T --user www-data nextcloud cp /tmp/nx-e2e-snapshot.db /var/www/html/data/nextcloud.db ;;
	esac
}

reset_data() {
	local empty
	empty=$(mktemp)
	case "$NX_DB" in
	mariadb | pgsql) snapshot_restore "$empty" 0 ;;
	*) rm -f "$empty"; die "reset-data unterstützt nur mariadb und pgsql" ;;
	esac
	rm -f "$empty"
	echo "App-Daten gelöscht ($(db_tables | wc -l) Tabellen geleert)"
}

# Sicherungen eines E2E-Laufs liegen in einem eigenen Ordner (nur für den Nutzer lesbar). Er wird
# nur gelöscht, wenn alles wiederhergestellt ist; sonst bleibt er für `./dev.sh e2e-restore <ordner>`
# (z. B. wenn der Stack während des Laufs gestoppt wurde).
E2E_STATE="${XDG_STATE_HOME:-$HOME/.local/state}/nc-nexus"

e2e_restore() { # $1 = Sicherungsordner
	local dir=$1 ok=0
	[ -d "$dir" ] || die "Sicherungsordner $dir nicht gefunden"
	occ status >/dev/null 2>&1 || { echo "WARNUNG: Umgebung läuft nicht – Sicherungen bleiben in $dir; nach ./dev.sh up: ./dev.sh e2e-restore $dir" >&2; return 1; }
	if [ -f "$dir/snapshot" ]; then
		if snapshot_restore "$dir/snapshot" "$(cat "$dir/started" 2>/dev/null || echo 0)"; then
			echo "E2E-Testdaten entfernt (App-Daten auf den Stand vor dem Lauf zurückgesetzt)"
			rm -f "$dir/snapshot"
		else
			echo "WARNUNG: App-Daten konnten nicht zurückgesetzt werden" >&2
			ok=1
		fi
	fi
	if [ -f "$dir/config.json" ]; then
		if config_restore "$dir/config.json"; then
			echo "App-Konfiguration wiederhergestellt"
			rm -f "$dir/config.json"
		else
			echo "WARNUNG: App-Konfiguration konnte nicht wiederhergestellt werden" >&2
			ok=1
		fi
	fi
	if [ -f "$dir/credentials" ]; then
		if creds_restore "$dir/credentials"; then
			echo "Zugangsdaten (Credential-Store) wiederhergestellt"
			rm -f "$dir/credentials"
		else
			echo "WARNUNG: Zugangsdaten konnten nicht wiederhergestellt werden" >&2
			ok=1
		fi
	fi
	if [ "$ok" = 0 ]; then
		rm -rf "$dir"
	else
		echo "Sicherungen bleiben in $dir – nach Behebung: ./dev.sh e2e-restore $dir" >&2
	fi
	return "$ok"
}

e2e() {
	local dir='' rc=0
	if [ -z "${NX_E2E_KEEP:-}" ]; then
		mkdir -p "$E2E_STATE" && chmod 700 "$E2E_STATE"
		dir=$(mktemp -d "$E2E_STATE/e2e-$NX_PROJECT-XXXXXX")
		date +%s >"$dir/started"
		config_save "$dir/config.json" || { rm -rf "$dir"; die "Sicherung der App-Konfiguration vor den E2E-Tests fehlgeschlagen"; }
		creds_save "$dir/credentials" || { rm -rf "$dir"; die "Sicherung der Zugangsdaten vor den E2E-Tests fehlgeschlagen"; }
		# sqlite: Kopie liegt im Container, die leere Datei ist nur die Markierung
		: >"$dir/snapshot"
		snapshot_save "$dir/snapshot" || { rm -rf "$dir"; die "Sicherung der App-Daten vor den E2E-Tests fehlgeschlagen"; }
		[ "$NX_DB" = sqlite ] || [ -s "$dir/snapshot" ] || { rm -rf "$dir"; die "Sicherung der App-Daten ist leer"; }
	fi
	trap 'rc=130' INT
	BASE_URL="http://host.docker.internal:$NX_PORT" E2E_PASSWORD="$E2E_PASSWORD" sh "$ROOT/nc_nexus/tests/e2e/run.sh" "${ARGS[@]}" || rc=$?
	if [ -n "$dir" ]; then
		e2e_restore "$dir" || [ "$rc" != 0 ] || rc=1
	fi
	return "$rc"
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
e2e) e2e ;;
e2e-restore) [ ${#ARGS[@]} -eq 1 ] || die "Nutzung: ./dev.sh e2e-restore <sicherungsordner>"; e2e_restore "${ARGS[0]}" ;;
reset-data) reset_data ;;
*) usage ;;
esac
