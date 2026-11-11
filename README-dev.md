# Entwicklung von nc_nexus

Das Repository enthält den gesamten DEV-Stack; die eigentliche Nextcloud-App liegt in
[`nc_nexus/`](nc_nexus/).

| Pfad | Inhalt |
|---|---|
| `nc_nexus/` | Nextcloud-App (PHP-Backend, Vue-3-Frontend, Tests) |
| `dev.sh` | DEV-Umgebungen starten, stoppen, `occ`, E2E-Tests |
| `docker-dev/` | `docker-compose.yml` (Standard), `compose.base.yml` (Nextcloud-Dienst), `compose.pgsql.yml`/`compose.sqlite.yml`, Post-Install-Hooks, PHP-8.3/8.4-Image, Liste der Shared-Apps |
| `scripts/php-check.sh` | Syntax-Check und PHPUnit für PHP 8.3/8.4/8.5, PHPStan – alles im Docker-Container |
| `scripts/matrix.sh` | Kompatibilitätsmatrix: Umgebung je Kombination starten und E2E-Tests ausführen |
| `Planung/` | Fachkonzept und Architektur |

## Zielplattform

- Nextcloud **34 und 35**
- Datenbanken **SQLite, PostgreSQL, MariaDB/MySQL**
- PHP **8.3 bis 8.5** (die offiziellen Images `nextcloud:34/35-apache` bringen PHP 8.5 mit;
  für 8.3 und 8.4 baut `dev.sh` ein eigenes Image aus `docker-dev/php/Dockerfile`)

## Voraussetzungen

Docker mit Compose-Plugin, Node.js ≥ 20. PHP und Composer werden lokal **nicht** gebraucht.
Für E2E-Tests das Projekt [e2e-runner](../e2e-runner) unter `~/projects/e2e-runner` und das
Playwright-Image (`docker pull mcr.microsoft.com/playwright:v1.61.1-noble`).

## Schnellstart

Wie bei nc-gantt und Netherrack:

```bash
cd nc_nexus && npm ci && npm run build && cd ..
cd docker-dev && docker compose up -d     # → http://localhost:8092
```

Anmeldung: `admin` / `admin123`. Beim ersten Start installiert sich Nextcloud selbst; die
Post-Install-Hooks aktivieren nc_nexus und legen die Testkonten `e2e` und `e2e-other` (ohne
Adminrechte) an. Compose-Projekt: `nc-nexus-dev`.

`./dev.sh up` (ohne Optionen) startet genau dieselbe Instanz; `./dev.sh e2e` führt die
E2E-Tests dagegen aus. `dev.sh` braucht es nur für die Varianten unten.

Während der Entwicklung `npm run dev` im App-Ordner laufen lassen; die App ist read-only in den
Container gemountet, PHP- und JS-Änderungen wirken ohne Neustart (F5 genügt).

## Umgebungen

```bash
./dev.sh up --env shared                  # Shared-DEV: nc_gantt, Netherrack, nc_ismscm, Vertragswerk, Deck, Calendar, Talk …
./dev.sh up --db pgsql                    # PostgreSQL 17
./dev.sh up --db sqlite                   # SQLite
./dev.sh up --nc 34                       # Nextcloud 34
./dev.sh up --php 8.3                     # PHP 8.3 (Image wird beim ersten Mal gebaut)
./dev.sh info --db pgsql --nc 34          # Projektname und Port einer Kombination
./dev.sh ls                               # alle nc_nexus-Umgebungen
./dev.sh occ app:list --db sqlite         # occ in einer Umgebung
./dev.sh destroy --db sqlite              # Umgebung samt Daten löschen
```

Jede Kombination ist ein eigenes Compose-Projekt (`nc-nexus-<env>-<db>-nc<NC>[-php<PHP>]`,
der Standard heißt `nc-nexus-dev`)
mit eigenen Volumes und eigenem Port (8092–8127), mehrere können parallel laufen.

| | MariaDB | PostgreSQL | SQLite |
|---|---|---|---|
| einfach, NC 35, PHP 8.5 | 8092 | 8093 | 8094 |
| Shared, NC 35, PHP 8.5 | 8095 | 8096 | 8097 |
| NC 34 | +6 | +6 | +6 |
| PHP 8.4 / 8.3 | +12 / +24 | +12 / +24 | +12 / +24 |

**Shared-DEV:** Welche Apps dazukommen, steht in `docker-dev/shared-apps.conf`. `mount`-Einträge
binden Apps read-only aus den Nachbarprojekten ein (fehlt ein Projekt, wird es übersprungen),
`store`-Einträge werden aus dem App-Store installiert.

## Beispieldaten

```bash
./dev.sh destroy && ./dev.sh up      # frische Instanz
scripts/seed-demo.sh                 # Konten anna, ben, paula (PMO), boris (Board), sven (Pool)
```

Legt Organisation, Rollen, Ziele, Pools mit Kapazität und zehn Vorhaben in allen Stufen an
(freigegeben mit Zusage, Kandidaten, Pflichtvorhaben, Triage, Analyse, Rückfrage, zurückgestellt,
abgelehnt, Entwurf). Passwort der Demo-Konten: `DEMO_PASSWORD` (Standard siehe Skript).

## Tests

```bash
scripts/php-check.sh          # PHP 8.3/8.4/8.5: Syntax + PHPUnit, dazu PHPStan
cd nc_nexus && npm run lint   # ESLint
./dev.sh e2e [--db …]         # Playwright über den e2e-runner
scripts/matrix.sh             # E2E über die ganze Matrix (DBs, NC 34, PHP 8.3)
```

## Fallstricke

- Der Projektname steht fest in `docker-compose.yml` (`nc-nexus-dev`); ohne eindeutigen Namen
  würde `docker compose up` Container anderer Projekte mit einem `docker-dev`-Ordner ersetzen.
  `compose.base.yml`, `compose.pgsql.yml` und `compose.sqlite.yml` nicht direkt starten, sondern
  über `dev.sh`.
- Beim Image-Update (`docker pull nextcloud:35-apache`, danach `./dev.sh up` und
  `./dev.sh occ upgrade`) schützt `docker-dev/upgrade.exclude` das Verzeichnis `apps-extra`
  vor dem rsync des Images.
- Der Tracing-JIT von PHP 8.5 ist in der DEV-Umgebung abgeschaltet (`opcache-dev.ini`).
