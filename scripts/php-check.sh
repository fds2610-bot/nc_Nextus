#!/bin/bash
# PHP-Prüfungen für nc_nexus im Docker-Container (lokal ist kein PHP installiert):
#   - Syntax-Check (php -l) und PHPUnit je PHP-Version
#   - PHPStan (einmal, gegen die OCP-API von Nextcloud 34, PHP 8.3–8.5)
#
# Nutzung: scripts/php-check.sh [PHP-Versionen …]   (Default: 8.3 8.4 8.5)
# Composer-Abhängigkeiten landen im Docker-Volume nc-nexus-vendor, nie im Projekt.
set -euo pipefail

APP=$(cd "$(dirname "$0")/../nc_nexus" && pwd)
VERSIONS=("$@")
[ ${#VERSIONS[@]} -gt 0 ] || VERSIONS=(8.3 8.4 8.5)
VOL=nc-nexus-vendor

echo "== composer install (Plattform PHP 8.3)"
docker run --rm -v "$APP":/src:ro -v "$VOL":/vendor composer:2 sh -c '
	set -e
	mkdir -p /tmp/app && cp /src/composer.json /tmp/app/ && cd /tmp/app
	composer install --no-interaction --no-progress --quiet --no-scripts --ignore-platform-req=ext-*
	rm -rf /vendor/* && cp -a vendor/. /vendor/'

run() { # $1 = PHP-Version, $2 = Befehl
	docker run --rm -v "$APP":/src:ro -v "$VOL":/vendor:ro "php:$1-cli" sh -c "
		set -e
		mkdir -p /tmp/app && cp -a /src/. /tmp/app/ && rm -rf /tmp/app/node_modules /tmp/app/tests/e2e/node_modules
		cp -a /vendor /tmp/app/vendor && cd /tmp/app
		$2"
}

for v in "${VERSIONS[@]}"; do
	echo "== PHP $v: Syntax"
	run "$v" "find lib tests/Unit appinfo templates -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v '^No syntax errors' || true"
	echo "== PHP $v: PHPUnit"
	run "$v" "php vendor/bin/phpunit --configuration phpunit.xml --no-progress"
done

echo "== PHPStan"
run "${VERSIONS[-1]}" "php vendor/bin/phpstan analyse --no-progress --memory-limit=1G"
