# 04 — Ressourcen und Kapazität

Dieses Kapitel ist der Kern des Modells: Priorisierung wird erst wirksam, wenn sie gegen eine
belastbare Kapazitätsaussage geschnitten wird.

## 1. Ressourcenpools statt Personen

Portfolio-Steuerung erfolgt auf Ebene von **Pools** (Skill-/Rollengruppen), nicht auf Ebene
einzelner Personen. Namentliche Zuordnung ist Aufgabe der Projekt- und Linienplanung.

Gründe: Portfolio-Horizont reicht 12–24 Monate — personenscharfe Planung ist dort Fiktion;
Fluktuation und Urlaub würden jede Planung entwerten; und Mitbestimmung/Datenschutz sind auf
Poolebene unkritisch.

**Beispielhafte Poolstruktur einer IT-Organisation:**

| Pool | Engpass? | Bemerkung |
|---|---|---|
| Business Analyse / Anforderungsmanagement | – | |
| Softwareentwicklung Backend | – | |
| Softwareentwicklung Frontend | – | |
| Datenbank / Data Engineering | ja | häufig unterbesetzt |
| ERP-/SAP-Basis | **ja** | klassischer Engpass |
| Netzwerk & Infrastruktur | ja | |
| IT-Security / Security-Architektur | **ja** | zwingend in jedem Vorhaben, kaum skalierbar |
| Enterprise-Architektur | **ja** | |
| Test / QA | – | |
| Betrieb / Service Management | – | |
| Projektleitung | ja | häufig unterschätzter Engpass |
| Fachbereichskapazität je Bereich | ja | der *am häufigsten übersehene* Engpass |

> **Der wichtigste Punkt der Tabelle:** Fachbereichskapazität gehört ins Modell. Projekte
> scheitern öfter an fehlender Zeit der Fachexperten als an fehlenden Entwicklern. Wer nur
> IT-Pools modelliert, bekommt eine schöne Planung und ein reales Scheitern.

Das Flag `is_bottleneck` steuert die Governance-Härte: nur für Engpasspools ist eine
**harte Zusage** Voraussetzung für Gate G3.

**Schlüsselrollen ohne Personenplanung:** Manche Engpässe sind nicht ein Pool, sondern eine oder zwei
Personen mit exklusivem Wissen. Dafür gibt es am Pool das Kennzeichen `key_person_dependency`
(Anzahl Personen mit dem kritischen Wissen). Bei 1 warnt das Tool, wenn mehrere Vorhaben
gleichzeitig harte Zusagen aus diesem Pool erhalten, und führt das Thema als Portfolio-Risiko. Die
Person selbst wird nicht modelliert.

**Skills** sind ein optionales Attribut des Pools (Schlagwortliste) und dienen der Suche beim
Substituieren (Konfliktoption 3, Kapitel 01, 7), nicht der Planung.

**Herkunft der Kapazitätsdaten:** FTE je Pool kommen idealerweise aus dem HR-System oder aus den
Ressourcen des Planungstools (Kapitel 07), sonst aus manueller Pflege durch den Pool-Manager
(Daten-Owner). Die Quelle wird angezeigt.

## 2. Kapazitätsrechnung

Nutzbare Projektkapazität ist deutlich kleiner als die Kopfzahl suggeriert. Das Tool rechnet:

```
Brutto-Kapazität   = FTE × Arbeitstage der Periode
Netto-Kapazität    = Brutto × (1 − Abwesenheitsquote) × (1 − Linien-/Betriebsquote) × (1 − Overhead-Quote)
Projekt-Kapazität  = Netto-Kapazität − Notfallreserve
```

**Realistische Ausgangswerte** (je Pool konfigurierbar, jährlich aus Ist-Daten nachjustieren):

| Faktor | Typischer Wert | Enthält |
|---|---|---|
| Abwesenheitsquote | 15–18 % | Urlaub, Krankheit, Feiertage anteilig |
| Linien-/Betriebsquote | 20–50 % | Incidents, Support, Wartung, Regelbetrieb — bei Betriebsteams weit höher |
| Overhead-Quote | 10–15 % | Meetings, Weiterbildung, Verwaltung, Onboarding |
| Notfallreserve | 5–10 % | Fast-Track-Vorhaben (Kapitel 01, 6.2) |

**Rechenbeispiel Pool „IT-Security", 3,0 FTE, Quartal mit 62 Arbeitstagen:**

```
Brutto      = 3,0 × 62                    = 186 PT
− Abwesenheit 16 %                        = 156 PT
− Betriebsquote 35 % (Incidents, Audits)  = 101 PT
− Overhead 12 %                           =  89 PT
− Notfallreserve 10 %                     =  80 PT   ← real verplanbar
```

Aus 186 „Kopf-Personentagen" werden 80 verplanbare. Diese Rechnung sichtbar zu machen, ist oft
schon der halbe Governance-Nutzen — sie beendet die Diskussion „wir haben doch drei Leute".

## 3. Bedarf und Allokation

### 3.1 Bedarf (Demand)

Jedes Vorhaben meldet Bedarf als Tripel **(Pool, Periode, Personentage)**. Periodenraster:
Monat für die nächsten 12 Monate, Quartal darüber hinaus.

Quelle des Bedarfs je Reifegrad:

| Projektstatus | Quelle des Bedarfs | Genauigkeit |
|---|---|---|
| Kandidat (vor G2) | Grobschätzung des PL/PMO, ggf. aus Vergleichsprojekten | ±50 % |
| Priorisiert (nach G2) | Plausibilisiert durch Pool-Manager | ±25 % |
| Freigegeben (nach G3) | aus dem Projektplan/Gantt abgeleitet | ±10 % |
| In Umsetzung | Restbedarf aus dem laufenden Plan, monatlich aktualisiert | Ist + Rest |

**Rückkopplung zur Projektplanung:** Sobald ein Gantt-Plan existiert, ist er die führende Quelle
für den zeitlich verteilten Bedarf. Das Governance-Tool sollte ihn übernehmen und nicht parallel
pflegen lassen — doppelte Pflege ist die zuverlässigste Art, ein Portfolio-Tool sterben zu lassen.
Technisch: Das Planungstool liefert Personentage je **Rolle** und Periode; eine Zuordnungstabelle
Rolle → Pool übersetzt sie in Pool-Bedarf (Kapitel 07, 4). Ressourcen ohne Zuordnung werden als
„unzugeordneter Bedarf“ ausgewiesen, nicht verschluckt.

### 3.2 Allokationsstufen

| Stufe | Bedeutung | Wer setzt sie | Verbindlichkeit |
|---|---|---|---|
| **Bedarf** (`requested`) | Vorhaben meldet an | Projektleiter | keine |
| **Vorgemerkt** (`soft`) | Pool-Manager hält Kapazität vor | Pool-Manager | Planungsindikation, kann verfallen |
| **Zugesagt** (`hard`) | verbindliche Zusage, Voraussetzung für G3 | Pool-Manager, bestätigt im Ressourcen-Board | verbindlich; Rücknahme nur über Board |
| **Ist** (`actual`) | tatsächlich geleistet | Zeiterfassung oder Schätzung des PL | Grundlage für Nachkalkulation und Quotenkorrektur |

Eine harte Zusage ohne Deckung in der Projekt-Kapazität wird vom System **abgelehnt**, nicht nur
gewarnt. Das ist die technische Umsetzung von Prinzip P4.

### 3.3 WIP-Limit

Je Pool wird eine maximale Anzahl gleichzeitig aktiver Vorhaben festgelegt (Faustregel:
`WIP_max ≈ FTE / 1,5`, mindestens 1). Grund: Kontextwechsel kosten bei Wissensarbeit real
15–40 % Produktivität, und diese Kosten tauchen in keiner Kapazitätsrechnung auf.

Bei Überschreitung: Warnung im Ressourcen-Board mit Sequenzierungsvorschlag (welche Vorhaben
könnten nacheinander statt parallel laufen).

## 4. Konflikterkennung

Das Tool erkennt und meldet automatisch:

| Konflikttyp | Erkennungsregel | Meldung an |
|---|---|---|
| **Überbuchung** | Σ hard(Pool, Periode) > Projekt-Kapazität(Pool, Periode) | Pool-Manager, PMO |
| **Ungedeckter Bedarf** | freigegebenes Projekt ohne hard-Allokation im Engpasspool | Projektleiter, Ressourcen-Board |
| **Engpassspitze** | Auslastung > 100 % in ≥ 2 aufeinanderfolgenden Perioden | Ressourcen-Board |
| **WIP-Überschreitung** | aktive Vorhaben > WIP_max | Ressourcen-Board |
| **Zombie-Projekt** | Status *InUmsetzung*, aber Ist-Aufwand ≈ 0 über 2 Perioden | PMO |
| **Prioritätsinversion** | Vorhaben mit niedrigerem Rang hat hard-Allokation, höherrangiges nicht | Portfolio-Board |
| **Verfallende Vormerkung** | soft-Allokation ohne G3 innerhalb von n Monaten | Projektleiter |

Prioritätsinversion ist die politisch heikelste Meldung — und die wertvollste. Sie macht sichtbar,
wo an der Governance vorbei gearbeitet wurde.

## 5. Kapazitätsschnitt: von der Rangliste zum Startplan

Der Algorithmus, der Priorisierung und Kapazität zusammenführt:

```
1. Ziehe den Bedarf von Bucket A (Pflicht) vollständig ab.
2. Ziehe laufende, freigegebene Vorhaben (hard) ab.
3. Verteile die Restkapazität gemäß Bucket-Zielquoten auf B, C, D.
4. Gehe je Bucket die Rangliste von oben nach unten durch:
     - passt der Bedarf des Vorhabens in allen Engpasspools in allen benötigten Perioden?
         ja  -> "startfaehig", Kapazitaet reservieren
         nein-> versuche Startverschiebung um 1..n Perioden (spaetester Start
                aus Termindruck Z1 begrenzt das Fenster)
                passt es dann -> "startfaehig ab Periode X"
                sonst          -> "wartend", Engpasspool als Ursache benennen
5. Ergebnis: Startplan mit Kapazitaetslinie + Liste der wartenden Vorhaben
   mit jeweiliger Engpassursache.
```

Der letzte Halbsatz ist entscheidend: Ein wartendes Vorhaben bekommt nicht „abgelehnt", sondern
**„wartet auf Pool X ab Q3"**. Damit ist der Konflikt entpersonalisiert und der Kapazitätsbedarf
belegt.

Der Schnitt prüft neben der Kapazität auch den Budgetrahmen des Buckets (Kapitel 03, 7.1).

### 5.1 Szenarioanalyse (Was-wäre-wenn)

Ein **Szenario** ist eine benannte Kopie des aktuellen Portfoliostands, in der Änderungen
ausprobiert werden, ohne Zusagen zu berühren. Der Kapazitätsschnitt läuft auf dem Szenario und wird
gegen den Ist-Stand verglichen (welche Vorhaben steigen über oder fallen unter die Linie, welche
Startdaten verschieben sich, wie ändert sich die Bucket-Balance).

Mindestens vergleichbar:

| Szenario | Änderung |
|---|---|
| Status quo | Portfolio unverändert fortführen |
| Neuaufnahme | ein zusätzliches Vorhaben: was wird verdrängt oder verschoben? |
| Kapazität / Budget erhöhen | FTE eines Pools oder Budgetrahmen verändern (z. B. Externe beschaffen, mit Vorlaufzeit) |
| Pausieren / Stoppen | Vorhaben aus dem Schnitt nehmen: was wird frei? |
| Prioritäten ändern | Rang, Bucket-Quoten oder Kriteriengewichte verändern |
| Verschieben | Zusage für ein Vorhaben um n Perioden verschieben |

Szenarien sind Arbeitsmittel für PMO, Pool-Manager und Board; ein Szenario kann einer
Board-Vorlage beigefügt und dort als Entscheidungsoption gewählt werden. Wird es beschlossen, erzeugt
das Tool die nötigen Änderungen (Rangänderungen, Allokationsaufträge) als Aufgaben, nicht stillschweigend.

### Visualisierung

Drei Sichten decken den Bedarf ab:

1. **Kapazitäts-Heatmap** — Pools (Zeilen) × Perioden (Spalten), Farbwert = Auslastung.
   Rot > 100 %, Gelb 90–100 %, Grün 70–90 %, Blau < 70 % (Unterauslastung ist ebenfalls ein Signal)
2. **Portfolio-Zeitstrahl** — Vorhaben als Balken, gestapelt nach Rang, mit Markierung der
   Kapazitätslinie
3. **Poolprofil** — Kapazität als Fläche, Bedarf als gestapelte Balken je Vorhaben; zeigt sofort,
   welches Vorhaben die Spitze verursacht

## 6. Umgang mit Unsicherheit

- **Schätzgüte mitführen** (Kapitel 03, 2.3) und in der Heatmap als Bandbreite darstellen —
  eine 95-%-Auslastung aus ±50-%-Schätzungen ist keine belastbare Aussage
- **Verplanungsgrad begrenzen:** nie 100 % der Projekt-Kapazität verplanen. 80–85 % ist der
  praktikable Zielwert; der Rest fängt Schätzfehler und Nachläufer ab
- **Rollierende Nachkalkulation:** Ist-Aufwand vs. Schätzung je Pool und Vorhabensklasse
  auswerten und den Korrekturfaktor in künftige Schätzungen einspeisen

## 7. Externe Kapazität

Externe sind kein unbegrenzter Puffer. Sie werden als eigene Pools oder als Kapazitätsanteil
innerhalb eines Pools modelliert, mit:

- Vorlaufzeit (Beschaffung, Onboarding) als Attribut — typisch 4–12 Wochen
- Einarbeitungsabschlag in den ersten Perioden (z. B. 50 % Produktivität im ersten Monat)
- Betreuungsaufwand interner Mitarbeiter (typisch 10–20 % der externen Kapazität) — dieser
  **belastet den internen Pool** und wird oft vergessen

Ohne diese drei Attribute erscheint externe Beschaffung im Tool als Wunderwaffe und wird
systematisch überschätzt.
