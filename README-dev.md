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

Für die KI-Assistenz bringt jede DEV-Instanz den Dummy-Anbieter `nexus_dev_ai`
(`docker-dev/apps/`) mit: Er beantwortet Aufträge „Text zu Text“ sofort mit einem festen Text,
ohne Sprachmodell. Für echte Antworten in der Nextcloud-Verwaltung unter „Künstliche Intelligenz“
einen anderen Anbieter (z. B. `integration_openai` mit Ollama) vorziehen oder `nexus_dev_ai` abschalten.

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
./dev.sh reset-data                  # alle App-Daten (nx_*) löschen – oder frisch: ./dev.sh destroy && ./dev.sh up
scripts/seed-demo.sh                 # Konten und Beispieldaten anlegen
```

Fiktives Unternehmen **Brenner Maschinenbau GmbH**: Technik (Konstruktion, OT-Softwareentwicklung),
Produktion (Fertigung, Montage, Logistik), IT-Infrastruktur und kaufmännischer Bereich (Finanzen,
Personal, Einkauf, Vertrieb). Rollen, Ziele (Smart Factory, Fernwartung, NIS-2/IEC 62443, PDM),
acht Pools mit Kapazität (Engpässe OT, Security, SAP, Konstruktion) und gut 20 Vorhaben in allen
Stadien: Entwurf, Triage, Rückfrage, Analyse, zurückgestellt, abgelehnt, Bewertung, Kandidat
(L-Vorhaben in der Board-Sitzung), im Portfolio, freigegeben, in Umsetzung (MES gelb, Fernwartung
rot, mit Risiken, Issue, Change Request), pausiert, abgebrochen, abgeschlossen mit Ist-Aufwand
(Nachkalkulation) und nach G6 geschlossen mit Nutzenreview.

Konten (Passwort `DEMO_PASSWORD`, Standard siehe Skript): paula (PMO), boris (Geschäftsführung),
fiona (Finanzen), anna (Vertrieb), ben (IT), sven (Leitung IT), kai (Konstruktion), olga (OT),
frank (Fertigung), mona (Montage), lars (Logistik), hanna (Personal).

## Tests

```bash
scripts/php-check.sh          # PHP 8.3/8.4/8.5: Syntax + PHPUnit, dazu PHPStan
cd nc_nexus && npm run lint   # ESLint
./dev.sh e2e [--db …]         # Playwright über den e2e-runner
# Hinweis: ./dev.sh e2e sichert vorher die App-Tabellen (nx_*), die App-Konfiguration und die
# gespeicherten Zugangsdaten (Credential-Store, nc_nexus::*) und spielt sie nach dem Lauf wieder
# ein; Benachrichtigungen und KI-Aufträge des Laufs werden gelöscht. Während eines Laufs keine
# Einstellungen auf der Instanz ändern. NX_E2E_KEEP=1 ./dev.sh e2e … behält die Testdaten.
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
- Qwen3-TTS (0,6B) läuft lokal auf der CPU und schafft bei mir nur rund 15 Minuten Sprache in etwa 1,5 Stunden im Hintergrund. 
