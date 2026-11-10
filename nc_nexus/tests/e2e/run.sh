#!/bin/sh
# E2E-Tests über den generischen e2e-runner ausführen (npm run test:e2e oder ../../../dev.sh e2e).
# Die Tests sind OPTIONAL: Fehlt die Infrastruktur (Docker, Runner-Projekt,
# gepulltes Playwright-Image), wird nur eine Warnung ausgegeben und mit
# Exit-Code 0 übersprungen — es wird nichts nachinstalliert oder gepullt.
# Argumente gehen an `playwright test` (z. B. Spec-Datei oder --grep).
set -eu

TEST_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
RUNNER_DIR=${E2E_RUNNER_DIR:-$HOME/projects/e2e-runner}

skip() {
	echo "WARNUNG: $1 — E2E-Tests werden übersprungen." >&2
	exit 0
}

command -v docker >/dev/null 2>&1 || skip "Docker nicht gefunden"
docker info >/dev/null 2>&1 || skip "Docker-Daemon nicht erreichbar"
[ -f "$RUNNER_DIR/docker-compose.yml" ] || skip "e2e-runner-Projekt nicht gefunden ($RUNNER_DIR)"

IMAGE=$(sed -n 's/^ *image: *//p' "$RUNNER_DIR/docker-compose.yml" | head -n1)
[ -n "$IMAGE" ] || skip "Playwright-Image im Compose-File nicht ermittelbar"
docker image inspect "$IMAGE" >/dev/null 2>&1 \
	|| skip "Playwright-Image $IMAGE nicht vorhanden (docker pull $IMAGE)"

export BASE_URL="${BASE_URL:-http://host.docker.internal:8092}"
cd "$RUNNER_DIR"
TEST_DIR="$TEST_DIR" exec docker compose run --rm \
	-e E2E_USER -e E2E_PASSWORD -e E2E_OTHER_USER \
	playwright "$@"
