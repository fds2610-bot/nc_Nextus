# nc_Nexus

Plattform, die Strategie, Projekte, Ressourcen und Entscheidungen zusammenführt – Projekt-Governance
als Nextcloud-App.

- **App:** [`nc_nexus/`](nc_nexus/) (Nextcloud 34–35, PHP 8.3–8.5, SQLite/PostgreSQL/MariaDB)
- **Entwicklung:** [README-dev.md](README-dev.md)
- **Konzept:** [Planung/README.md](Planung/README.md)

Stand: Entwicklungsversion 0.19.1 – Stufe 1 bis 4 des Konzepts umgesetzt, Stufe 5 begonnen. Stufe 1–2: Antragseingang, Organisation und Rollen, Triage, Entscheidungsregister, Ressourcenpools und Kapazität, Portfolioübersicht, strategische Ziele, Bewertung und Rangliste, Business Case, Kapazitätsschnitt, Gantt-Adapter. Stufe 3: Gate-Sets G2–G6, Gremien mit Entscheidungsrechte-Matrix, Sitzungen mit Abstimmung und Quorum, Beschlussverfolgung, Datenqualitäts-Cockpit. Stufe 4: Projekt-Cockpit mit Statusbericht und Health-Indikator, Baselines, Budgetsteuerung mit FAC und Ist-Import (CSV), Risiken/Issues/Maßnahmen mit Portfolio-Risikosicht, Change Requests mit Schwellenlogik, Konflikterkennung mit Fristeskalation, Benachrichtigungen und Tagesüberblick, Kennzahlen. Stufe 5: Nutzenreviews mit mehreren Messzeitpunkten, Soll/Ist-Nutzenbilanz und Korrekturfaktoren je Nutzenart, Nachkalkulation mit Schätzkorrekturfaktoren je Pool und Vorhabensklasse, Szenarioanalyse (Was-wäre-wenn mit Vergleich gegen den Ist-Schnitt), Management-Dashboard mit sechs Kacheln und gegliederte Navigation, weitere Planungs-Provider über eine Provider-Registry, an die auch andere Apps andocken können: Gantt lokal oder auf einer anderen Nextcloud (technisches Konto mit App-Passwort), OpenProject über die REST-API, MS Project als XML-Import und Startplan-Export; eigener Bereich „Planungstools“ für das PMO. Noch offen: Gantt-API v2 (separat in nc-gantt), Anbindung von Zeiterfassung/Finanzsystem.
