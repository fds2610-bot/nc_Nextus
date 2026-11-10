# 03 — Bewertung, Priorisierung und Finanzen

Ziel: Business-Interessen vergleichbar machen, ohne den Vergleich vorzutäuschen, wo er nicht
möglich ist. Das Modell trennt deshalb drei Ebenen: **Bucket** (welcher Topf), **Score**
(Rang im Topf), **Kapazitätsschnitt** (was passt hinein) — ergänzt um die **Budgetrestriktion**
als zweite harte Grenze (Abschnitt 7).

> **Scoring unterstützt Entscheidungen, es ersetzt sie nicht.** Ein hoher Score ist keine
> automatische Freigabe. Das Board beantwortet zu jedem Vorhaben sieben Fragen, von denen der Score
> nur einige abdeckt:
>
> 1. Strategische Eignung — passt es zu den Zielen? *(N1)*
> 2. Wertbeitrag — welcher Nutzen ist realistisch? *(N2–N5, Business Case)*
> 3. Risiko und Verpflichtung — was passiert, wenn wir nicht investieren? *(Bucket A, N4, Problemfeld des Demands)*
> 4. Machbarkeit — sind Kompetenzen und Kapazität da? *(Kapazitätsschnitt, Kapitel 04)*
> 5. Abhängigkeiten — was wird ermöglicht oder blockiert? *(N6, Enabler-Regel)*
> 6. Finanzierbarkeit — passt es in Budget und Planung? *(Abschnitt 7)*
> 7. Managemententscheidung — welcher Trade-off wird bewusst eingegangen? *(Begründung der Decision, ggf. Board-Korrektur)*
>
> Kriterien, Gewichte und Ausnahmen sind transparent und versioniert (Abschnitt 6).

## 1. Investment-Buckets

Das häufigste Governance-Versagen ist der Einheitstopf: Pflichtvorhaben mit „Score 100 —
regulatorisch" verdrängen strukturell jede Innovation, bis das Unternehmen nur noch verwaltet.
Deshalb wird die Kapazität **vorab** auf Buckets aufgeteilt, und der Wettbewerb findet
*innerhalb* eines Buckets statt.

| Bucket | Inhalt | Typische Zielquote | Bewertungslogik |
|---|---|---|---|
| **A — Pflicht** | Gesetz, Regulatorik, Vertrag, Audit-Findings, akute Sicherheitslücken | 15–25 % | kein Nutzen-Score nötig; bewertet werden nur Aufwand und Termindruck. Nachweis der Verpflichtung ist Pflichtfeld |
| **B — Betrieb & Lifecycle** | End-of-Life-Ablösungen, technische Schulden, Stabilität, Skalierung | 20–30 % | Nutzen = vermiedenes Risiko und vermiedene Folgekosten |
| **C — Wachstum & Effizienz** | Umsatzsteigerung, Kostensenkung, Prozessautomatisierung | 35–50 % | voller Business Case, quantitativer Nutzen im Vordergrund |
| **D — Innovation & Exploration** | Neue Geschäftsmodelle, Technologie-Erprobung, unsichere Wetten | 5–15 % | bewusst geringe Beweislast, aber kleine Tranchen und harte Abbruchgates |

Die Zielquoten werden jährlich vom Portfolio-Board festgelegt und beziehen sich auf
**Kapazität (Personentage), nicht auf Budget** — denn Personentage sind der Engpass.
Abweichungen der Ist- von der Zielquote sind eine überwachte Kennzahl.

> **Anpassungspunkt:** Die Quoten oben sind Erfahrungswerte. Ein Unternehmen unter starkem
> regulatorischem Druck oder in einer Lifecycle-Welle wird A und B deutlich höher ansetzen.
> Wichtig ist nur, dass D **nie null** ist.

## 2. Kriterienkatalog

Alle Kriterien werden auf einer **einheitlichen Ordinalskala 0/1/3/5/8** bewertet (nicht 1–5 linear —
die gespreizte Skala erzwingt Differenzierung und verhindert das „alles ist eine 4"-Problem).
Jede Stufe hat einen definierten Ankertext; das Tool zeigt diesen bei der Eingabe an.

### 2.1 Nutzendimension

| Kriterium | Gewicht (Beispiel) | Ankertexte |
|---|---|---|
| **N1 Strategiebeitrag** | 25 % | 0 = kein Bezug · 1 = mittelbar · 3 = zahlt auf ein strategisches Ziel ein · 5 = wesentlicher Beitrag zu einem Ziel · 8 = Voraussetzung für ein strategisches Ziel |
| **N2 Finanzieller Nutzen** | 25 % | abgeleitet aus dem Business Case, nicht frei geschätzt: 0 = kein · 1 = < 50 T€/a · 3 = 50–200 T€/a · 5 = 200–500 T€/a · 8 = > 500 T€/a (Schwellen unternehmensspezifisch) |
| **N3 Kunden-/Nutzernutzen** | 15 % | 0 = keiner · 1 = einzelne Anwender · 3 = ein Fachbereich · 5 = mehrere Bereiche oder Endkunden · 8 = messbare Verbesserung der Kundenerfahrung im Kerngeschäft |
| **N4 Risikominderung** | 15 % | 0 = keine · 1 = geringes Restrisiko · 3 = mittleres operatives Risiko gemindert · 5 = hohes Risiko gemindert · 8 = existenzielles Risiko (Ausfall Kernsystem, Datenverlust) gemindert |
| **N5 Mitarbeiterwirkung** | 10 % | 0 = keine · 1 = kosmetisch · 3 = spürbare Entlastung · 5 = deutliche Entlastung mehrerer Teams · 8 = beseitigt einen belastenden Engpass |
| **N6 Synergie / Enabler** | 10 % | 0 = isoliert · 1 = geringe Wiederverwendung · 3 = ein Folgevorhaben profitiert · 5 = mehrere · 8 = Plattform, ohne die andere Vorhaben nicht startbar sind |

`Nutzenwert = Σ (Kriterienwert × Gewicht)` → Wertebereich 0–8.

### 2.2 Dringlichkeit / Zeitkritikalität

| Kriterium | Ankertexte |
|---|---|
| **Z1 Termindruck** | 0 = beliebig verschiebbar · 1 = wünschenswert im Jahr · 3 = Fenster durch Abhängigkeit · 5 = harte interne Zusage · 8 = gesetzliche/vertragliche Frist mit Sanktion |
| **Z2 Nutzenverfall** | verliert der Nutzen bei Verzögerung? 0 = nein · 3 = linear abnehmend · 5 = deutlich abnehmend · 8 = Nutzen entfällt vollständig (Marktfenster) |

`Dringlichkeit = max(Z1, Z2)` — bewusst Maximum, nicht Mittelwert: eine Gesetzesfrist wird nicht
dadurch entschärft, dass der Nutzen stabil bleibt.

### 2.3 Aufwand

| Größe | Erhebung |
|---|---|
| Interner Aufwand | Personentage **je Ressourcenpool** (nicht als Summe!) — der Poolschnitt ist für die Kapazitätsplanung zwingend |
| Externe Kosten | Beratung, Lizenzen, Hardware, Cloud (CAPEX/OPEX getrennt) |
| Laufende Folgekosten | jährlicher Betriebsaufwand nach Go-Live in PT und € |
| Schätzgüte | grob (±50 %) · plausibilisiert (±25 %) · geplant (±10 %) — als Feld, damit Scores nicht Scheingenauigkeit vortäuschen |

Für den Score wird der Aufwand auf dieselbe Skala normiert:
`A = 1` bei sehr kleinem Vorhaben … `A = 8` bei sehr großem (Schwellen wie bei den
Vorhabensklassen in Kapitel 02).

### 2.4 Risiko

| Kriterium | Ankertexte |
|---|---|
| **R1 Umsetzungsrisiko** | Technologiereife, Neuartigkeit, Erfahrung des Teams |
| **R2 Abhängigkeitsrisiko** | Anzahl und Kritikalität von Vorbedingungen und Lieferanten |
| **R3 Organisatorisches Risiko** | Change-Bedarf, Betroffenenzahl, erwarteter Widerstand |
| **R4 Sicherheits-/Compliance-Risiko** | Bewertungsbeitrag des Architektur- und Sicherheitsboards, nicht des Antragstellers |

`Risikowert = Mittelwert(R1..R4)`, Wertebereich 0–8.

## 3. Priorisierungsformel

```
Wertdichte  =  (Nutzenwert × Dringlichkeitsfaktor)  /  (Aufwand × Risikofaktor)

mit   Dringlichkeitsfaktor = 1 + (Dringlichkeit / 8)        →  1,0 … 2,0
      Risikofaktor         = 1 + (Risikowert / 16)          →  1,0 … 1,5
```

Das ist eine an *Weighted Shortest Job First* angelehnte Wertdichte: Nutzen pro eingesetzter
Kapazitätseinheit. Sie belohnt kleine, schnell wirksame Vorhaben — genau das richtige Verhalten
bei knapper Kapazität.

**Beispielrechnung**

| Vorhaben | Nutzen | Dringl. | Aufwand | Risiko | Wertdichte | Rang |
|---|---|---|---|---|---|---|
| Rechnungsworkflow automatisieren | 5,2 | 3 | 3 | 2,0 | (5,2 × 1,375) / (3 × 1,125) = **2,12** | 1 |
| CRM-Ablösung | 6,8 | 5 | 8 | 5,5 | (6,8 × 1,625) / (8 × 1,344) = **1,03** | 3 |
| Self-Service-Portal | 4,0 | 1 | 2 | 2,5 | (4,0 × 1,125) / (2 × 1,156) = **1,95** | 2 |

Die CRM-Ablösung hat den höchsten Nutzen und landet trotzdem auf Rang 3 — sie bindet die knappe
Kapazität am längsten. Genau diese Diskussion soll das Modell erzwingen: nicht „was ist am
wichtigsten", sondern „was bringt pro eingesetztem Personentag am meisten".

### 3.1 Sonderregeln

- **Bucket A (Pflicht):** kein Nutzen-Score. Rangfolge allein nach Fristendatum, dann nach Aufwand
  (kurze zuerst). Der Kapazitätsbedarf von Bucket A wird **vor** allen anderen Buckets von der
  Gesamtkapazität abgezogen.
- **Enabler-Vorhaben:** Wenn Vorhaben X Voraussetzung für Y und Z ist, wird sein Nutzenwert um
  den Faktor `1 + 0,15 × Anzahl abhängiger Vorhaben` erhöht (gedeckelt bei 1,5). Sonst verlieren
  Plattformvorhaben systematisch gegen sichtbare Fachvorhaben.
- **Board-Korrektur:** Das Portfolio-Board darf den Rang übersteuern. Die Korrektur wird als
  eigenes Feld gespeichert (`rank_override`, `override_reason`) und im Bericht sichtbar gemacht.
  Eine hohe Korrekturquote ist ein Hinweis, dass der Kriterienkatalog nachjustiert werden muss.

## 4. Business Case

Der Business Case ist ein strukturiertes Objekt, keine Präsentation. Nur so ist er nachhaltbar (G6).

**Nutzenpositionen** (je Position: Typ, Betrag, Einheit, Zeitpunkt/Zeitraum, Messgröße,
Verantwortlicher, Erhebungsmethode):

| Typ | Beispiel | Nachweisbar bei G6 über |
|---|---|---|
| Kostensenkung, kassenwirksam | Wegfall Wartungsvertrag 40 T€/a | Buchhaltung |
| Kapazitätsgewinn | 1,5 FTE Sachbearbeitung frei | Prozesskennzahl, Vorher/Nachher |
| Umsatzwirkung | +2 % Conversion | Web-/Vertriebskennzahl |
| Vermiedene Kosten | ausbleibender Ausfall, Bußgeld | Risikomodell (Eintrittswahrsch. × Schaden) |
| Qualitativ | Mitarbeiterzufriedenheit | Befragung, definierter Indikator |

**Pflichtregel:** Jede Nutzenposition hat einen **benannten Verantwortlichen** und eine
**Messgröße mit Ausgangswert**. Ohne Ausgangswert (Baseline) ist ein Nutzenreview unmöglich —
das Tool erzwingt dieses Feld.

**Kennzahlen** aus den Positionen: Gesamtkosten (TCO über 5 Jahre), Nutzen p. a., Amortisationsdauer,
Kapitalwert (NPV bei definiertem Kalkulationszins), optional interner Zinsfuß.

**Annahmen** sind ein eigener Abschnitt des Business Case (Liste mit Annahme, Begründung,
Verantwortlichem, Prüfzeitpunkt). Sie werden bei jedem G4 abgefragt: „Gilt die Annahme noch?“ Eine
gefallene Annahme macht den Business Case nicht automatisch ungültig, erzwingt aber eine neue
Version.

**Versionierung:** Der Business Case wird zu jedem Gate und bei jedem genehmigten Change mit
Nutzen- oder Kostenwirkung als neue Version eingefroren. Damit lässt sich jederzeit zeigen, auf
welcher Grundlage entschieden wurde.

### 4.1 Nutzenregister

Die Nutzenpositionen aller Business Cases bilden zusammen das **Nutzenregister** — die
portfolioweite Sicht auf zugesagten Nutzen. Je Position werden über die Zeit geführt:

- **Nutzenprognose** — erwarteter Wert, laufend vom Benefit Owner aktualisierbar
- **Realisierungsstatus** — `nicht_begonnen` · `auf_kurs` · `gefaehrdet` · `realisiert` · `verfehlt`
- **Review-Zeitpunkte** — einer oder mehrere, nach Go-Live; jeder Review erzeugt ein
  `BENEFIT_REVIEW`-Objekt mit Ist-Wert und Abweichungsanalyse

Der **Benefit Owner** sitzt im nutznießenden Bereich und bleibt nach Projektabschluss verantwortlich;
die Verantwortung endet nicht mit der Auflösung des Projektteams.

**Rückkopplung:** Systematische Abweichungen (z. B. Kapazitätsgewinne werden regelmäßig nur zu 50 %
realisiert) fließen als Korrekturfaktor in die Plausibilisierung künftiger Business Cases und in die
Ankertexte von N2 ein.

## 5. Bewertungsverfahren

1. **Selbstbewertung** durch Antragsteller/Sponsor (Entwurf)
2. **Plausibilisierung**: Controlling prüft Zahlen, Architektur/Security prüft Risiko und Aufwand,
   Pool-Manager prüft die PT-Schätzung je Pool
3. **Konsolidierung** durch das PMO — Abweichungen zwischen Selbst- und Fremdbewertung bleiben
   **sichtbar**, sie werden nicht überschrieben
4. **Kalibrierungsrunde** im Board: bei jedem Portfolio-Board werden zwei bis drei Vorhaben
   gemeinsam neu bewertet, um ein gemeinsames Skalenverständnis zu halten

**Versionierung:** Jede Bewertung ist ein neues, unveränderliches `Assessment` mit Zeitstempel.
Die Historie zeigt, wie sich die Einschätzung eines Vorhabens entwickelt hat — bei Nachfragen im
Nachhinein ist das die wichtigste Information überhaupt.

## 6. Konfigurierbarkeit (Tool-Anforderung)

Kriterienkatalog, Gewichte, Skalenanker, Bucket-Quoten und Schwellenwerte müssen **Daten sein,
nicht Code**. Jedes Unternehmen wird sie anpassen, und sie ändern sich mit der Strategie.

```
CriterionSet (versioniert, gültig ab / bis)
 └── Criterion (Code, Bezeichnung, Dimension, Gewicht, Pflicht ja/nein)
      └── ScaleAnchor (Wert 0|1|3|5|8, Ankertext, Beispiel)
```

Eine Änderung des Kriterienkatalogs erzeugt eine **neue Version**; bestehende Bewertungen bleiben
an ihrer Version hängen. Andernfalls verändern sich historische Ränge rückwirkend und die
Entscheidungshistorie wird unerklärbar.

Mehrere Kriterienkataloge können **parallel** gelten, etwa je Portfolio oder Organisationseinheit
(Mandantenfähigkeit, Kapitel 06, 7). Ein Vorhaben wird immer nur gegen einen Katalog bewertet.

## 7. Finanzielle Steuerung

Kapazität ist der primäre Engpass (Kernannahme 1), Budget die zweite Restriktion. Beide werden
getrennt geprüft: Ein Vorhaben ist nur startfähig, wenn es **in die Kapazität und ins Budget** passt.

### 7.1 Budgetrahmen

- Budget wird je **Portfolio, Bucket und Geschäftsjahr** als Rahmen festgelegt (optional, Feld
  `target_budget` am Bucket)
- Der Kapazitätsschnitt (Kapitel 04, 5) prüft beim Durchgehen der Rangliste zusätzlich den
  verbleibenden Budgetrahmen des Buckets; ein Vorhaben, das an Budget statt an Kapazität scheitert,
  wird als „wartet auf Budget“ ausgewiesen
- **Mehrjahresbetrachtung:** Kosten und Budget werden je Periode geführt; ein Vorhaben über drei
  Geschäftsjahre belastet die Folgejahre bereits bei Freigabe (Vorbelastung)

### 7.2 Plan, Ist, Forecast

| Größe | Quelle | Bemerkung |
|---|---|---|
| **Genehmigtes Budget** (`budget_approved`) | Decision | Summe der freigegebenen Tranchen (Kapitel 02, 3) |
| **Budgetrahmen gesamt** | Business Case (genehmigte Version) | Obergrenze aller Tranchen |
| **Gebunden** (`budget_committed`) | Bestellungen, Verträge | aus dem Finanzsystem oder manuell |
| **Ist** (`cost_actual`) | Finanzsystem, Zeiterfassung × Satz | Herkunft wird angezeigt (P12) |
| **Forecast at Completion** (FAC) | PL im Statusbericht | Ist + erwartete Restkosten |
| **Abweichung** | berechnet | FAC − Budgetrahmen, absolut und in % |

Überschreitet der FAC den Budgetrahmen über die Toleranz (Schwelle wie beim Change Request), wird
automatisch ein Change Request vom Typ `cost` angelegt — **Nachfinanzierung** ist kein formloser
Vorgang. Interne Personentage werden mit einem Verrechnungssatz je Pool in Kosten umgerechnet, damit
Vorhaben mit hohem Eigenanteil nicht billiger erscheinen, als sie sind.
