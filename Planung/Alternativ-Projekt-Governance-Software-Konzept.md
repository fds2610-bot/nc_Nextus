---
date: 10. November 2026
lang: de
subtitle: Fachliches Zielbild, Governance-Landkarte und funktionale
  Anforderungen
title: Konzeptgrundlage -- Projekt-Governance-Software
version: 0.1 -- Arbeitsentwurf
---

# 1. Zweck und Einordnung

Dieses Dokument beschreibt eine fachliche Grundlage für die Konzeption
einer integrierten Projekt-Governance-Software. Es verbindet das
Problembild typischer Organisationen mit einem Zielmodell für
Governance, Prozessen, Rollen, Entscheidungsmechanismen und daraus
abgeleiteten Software-Funktionen.

Es ist bewusst **kein vollständiges Lastenheft und keine technische
Spezifikation**. Organisationsspezifische Richtlinien, Schwellenwerte,
Rollenbezeichnungen, Genehmigungsmatrizen und Integrationen müssen in
einer späteren Analyse konkretisiert werden.

## 1.1 Zielsetzung

Die Software soll Organisationen dabei unterstützen,

-   Projekt- und Investitionsvorhaben nachvollziehbar aus der Strategie
    abzuleiten,
-   Anforderungen strukturiert aufzunehmen und zu bewerten,
-   Portfolios realistisch anhand von Nutzen, Risiken, Kosten und
    Kapazitäten auszurichten,
-   Entscheidungen verbindlich und revisionsfähig zu treffen,
-   Projekte über ihren gesamten Lebenszyklus angemessen zu steuern,
-   Abweichungen und Risiken frühzeitig sichtbar zu machen,
-   Nutzenversprechen nach Projektabschluss zu überprüfen und daraus zu
    lernen.

**Leitgedanke:** Die Software institutionalisiert transparente
Governance und unterstützt Entscheidungen. Sie ersetzt weder ein
wirksames Mandat noch die Verantwortung der Führungskräfte und Gremien.

## 1.2 Abgrenzung: Governance ist nicht operatives Projektmanagement

Die Lösung fokussiert auf die Steuerungs- und Entscheidungsebene.
Operative Planung -- etwa Aufgaben, Tickets, detaillierte Arbeitspakete
oder Team-Kollaboration -- kann in spezialisierten
Projektmanagementsystemen verbleiben.

  ------------------------------------------------------------------------------------
  Ebene                                Leitfrage               Typische Verantwortung
  ------------------------------------ ----------------------- -----------------------
  Unternehmens-/Strategie-Governance   Welche strategischen    Geschäftsführung,
                                       Ziele und               Strategie
                                       Investitionsfelder sind 
                                       maßgeblich?             

  Portfolio-Governance                 Welche Vorhaben werden  Portfolio Board, PMO,
                                       aufgenommen,            Bereichsleitung
                                       priorisiert,            
                                       finanziert, verändert   
                                       oder beendet?           

  Projekt-Governance                   Wird das einzelne       Sponsor,
                                       Vorhaben innerhalb      Lenkungsausschuss,
                                       seiner Leitplanken      Projektleitung
                                       wirksam geführt?        

  Operatives Projektmanagement         Wie wird die            Projektleitung, Teams
                                       vereinbarte Arbeit      
                                       umgesetzt?              
  ------------------------------------------------------------------------------------

Die Software sollte die ersten drei Ebenen nativ unterstützen und
operative Werkzeuge integrieren können.

# 2. Ausgangslage: typische Herausforderungen

## 2.1 Struktur und Rollen

-   Entscheidungskompetenzen für Start, Stopp, Priorisierung und
    Finanzierung sind unklar.
-   Gremien beraten, besitzen aber kein eindeutiges Entscheidungsmandat.
-   IT, Fachbereiche, Finance und zentrale Steuerung verwenden
    unterschiedliche Prozesse und Kriterien.
-   Verantwortung für Nutzen, Datenqualität und Maßnahmen ist nicht
    eindeutig zugeordnet.

## 2.2 Portfolio und Strategie

-   Zu viele Vorhaben konkurrieren um begrenzte Kapazitäten.
-   Projekte sind nur lose oder nachträglich mit strategischen Zielen
    verknüpft.
-   Business Cases werden als Eintrittsformalität behandelt und später
    nicht aktualisiert.
-   Status, Kosten, Risiken und Forecasts sind nicht vergleichbar.
-   Abhängigkeiten zwischen Projekten und Programmen bleiben unsichtbar.

## 2.3 Kultur und Verhalten

-   Politische Interessen und einflussreiche Sponsoren überlagern
    sachliche Kriterien.
-   Stoppen oder Verschieben wird als Scheitern verstanden.
-   Schlechte Nachrichten werden verspätet oder beschönigt gemeldet.
-   Bereits getätigte Investitionen führen zu einer Fortsetzung trotz
    sinkender Erfolgsaussichten.
-   Neue Themen verdrängen laufende Prioritäten ohne transparente
    Folgenabschätzung.

## 2.4 Demand und Priorisierung

1.  Anfragen kommen über E-Mail, Meetings und informelle Kanäle.
2.  Bewertungsmaßstäbe sind uneinheitlich oder nicht transparent.
3.  Priorisierung erfolgt ohne belastbare Kapazitäts- und
    Kompetenzsicht.
4.  Lokale Bereichsinteressen optimieren zulasten des Gesamtportfolios.
5.  Ablehnungen und Vertagungen sind nicht verbindlich.
6.  Nutzen wird nach Abschluss nicht systematisch überprüft.
7.  Prioritäten ändern sich häufig, ohne Auswirkungen auf laufende
    Vorhaben zu bewerten.

## 2.5 Weitere Lücken über den Demand-Prozess hinaus

-   Fehlende Stage-Gate- und Freigabelogik über den Projektlebenszyklus.
-   Uneinheitliche Projektsteuerung und zu späte Erkennung von
    Abweichungen.
-   Unzureichendes Risiko-, Issue- und Eskalationsmanagement.
-   Fehlende Transparenz über Budgetentwicklung und Forecasts.
-   Scope-Änderungen ohne nachvollziehbare Auswirkungsanalyse.
-   Entscheidungen und Beschlüsse sind nicht zentral nachvollziehbar.
-   Nutzenverantwortung endet häufig mit dem Projektabschluss.

# 3. Zielbild: zwölf Governance-Dimensionen

Die folgenden zwölf Dimensionen bilden die fachliche Landkarte des
Zielsystems. Sie sind miteinander verbunden und sollten nicht als
voneinander isolierte Module verstanden werden.

``` mermaid
flowchart TB
    S["01 Strategic Alignment<br/>Strategie und Ziele"]
    D["02 Demand Management<br/>Anforderungen und Intake"]
    P["03 Portfolio Management<br/>Auswahl und Optimierung"]
    G["04 Decision Governance<br/>Rollen, Gremien, Beschlüsse"]
    L["05 Project Lifecycle<br/>Phasen, Gates, Freigaben"]
    M["06 Project Performance<br/>Fortschritt und Steuerung"]
    R["07 Resource Management<br/>Kapazitäten und Skills"]
    F["08 Financial Governance<br/>Budget und Business Case"]
    I["09 Risk & Issue Management<br/>Risiken und Eskalationen"]
    C["10 Change Governance<br/>Änderungen und Scope"]
    B["11 Benefits Management<br/>Nutzenrealisierung"]
    A["12 Reporting & Assurance<br/>Transparenz und Kontrolle"]

    S --> D --> P
    G --- D
    G --- P
    P --> L --> M
    R --- P
    R --- M
    F --- P
    F --- M
    I --- M
    I --- P
    C --- M
    C --- F
    M --> A
    P --> A
    L --> B
    B --> S
    A --> G
```

## 3.1 Strategic Alignment -- Strategieausrichtung

**Zweck:** Sicherstellen, dass Vorhaben auf strategische Ziele,
gesetzliche Verpflichtungen und definierte Investitionsfelder einzahlen.

Herausforderungen: - Strategiebeiträge sind nicht operationalisiert. -
Ziele sind zu allgemein oder nicht messbar. - Zielkonflikte zwischen
Bereichen bleiben verdeckt.

Mögliche Funktionen: - Verwaltung strategischer Ziele und
Zielhierarchien. - Zuordnung von Vorhaben zu Zielen und strategischen
Initiativen. - Bewertung des Beitrags und der Wirkung. - Darstellung von
Zielkonflikten und Investitionsschwerpunkten. - Historisierung von
Strategie- und Zielversionen.

## 3.2 Demand Management -- Bedarf und Intake

**Zweck:** Alle relevanten Veränderungsbedarfe kontrolliert, vollständig
und vergleichbar in die Governance bringen.

Mögliche Funktionen: - Single Point of Intake mit konfigurierbaren
Antragsformularen. - Antragstypen und Kategorien, z. B. regulatorisch,
strategisch, Effizienz, Erhalt. - Pflichtangaben zu Problem, Nutzen,
Dringlichkeit, Aufwand, Kosten, Risiken und Sponsor. - Dublettenprüfung
und Verknüpfung ähnlicher Anforderungen. - Triage, Rückfragen,
Vervollständigung und Vorprüfung. - Verbindliche Entscheidungscodes:
angenommen, abgelehnt, zurückgestellt, weitere Analyse. - Wiedervorlage
und nachvollziehbare Historie.

## 3.3 Portfolio Management -- Portfoliosteuerung

**Zweck:** Ein ausgewogenes, finanzierbares und kapazitätsgerechtes
Gesamtportfolio gestalten.

Mögliche Funktionen: - Scoring und gewichtete Bewertung. -
Szenariovergleich und Portfolio-Simulation. - Priorisierung nach Nutzen,
Strategie, Risiko, Dringlichkeit und Aufwand. - Kapazitäts- und
Budgetrestriktionen. - Portfolio-Obergrenzen und
Work-in-Progress-Limits. - Abhängigkeiten, Engpässe und Konflikte
sichtbar machen. - Warteliste und aktive Portfolioentscheidungen. -
Portfolio-Rebalancing mit Auswirkungsanalyse.

Hinweis: Scoring unterstützt Entscheidungen, ersetzt sie aber nicht.
Kriterien, Gewichte und Ausnahmen müssen transparent und versioniert
sein.

## 3.4 Decision Governance -- Entscheidungs- und Gremiensteuerung

**Zweck:** Sicherstellen, dass Entscheidungen durch legitimierte
Personen, auf ausreichender Informationsbasis und innerhalb klarer
Mandate getroffen werden.

Mögliche Funktionen: - Rollen-, Gremien- und Kompetenzmodell. -
Entscheidungsrechte nach Entscheidungstyp und Schwellenwert. - Sitzungs-
und Beschlussvorbereitung. - Entscheidungsanträge mit Optionen und
Empfehlung. - Dokumentation von Entscheidung, Begründung, Auflagen und
Verantwortlichen. - Beschlussverfolgung, Fristen und Eskalationen. -
Stellvertretungen, Quorum und Interessenkonflikte. - Vollständige
Entscheidungshistorie.

## 3.5 Project Lifecycle -- Projektlebenszyklus und Stage Gates

**Zweck:** Vorhaben kontrolliert von der Idee bis zum Abschluss führen.

Beispielhafte Phasen: 1. Idee und Bedarf 2. Vorprüfung 3. Initiierung
und Business Case 4. Planung und Freigabe 5. Umsetzung 6. Übergabe und
Go-Live 7. Abschluss und Nutzenreview

Mögliche Funktionen: - Konfigurierbare Phasenmodelle. - Gate-Kriterien
und Pflichtnachweise. - Rollenbasierte Freigaben. - Gate-Entscheidungen:
Go, Conditional Go, Hold, Rework, Stop. - Auflagen und Nachverfolgung. -
Abweichende Pfade für kleine, große oder regulatorische Vorhaben. -
Projektabschluss- und Übergabekriterien.

## 3.6 Project Performance -- Projektsteuerung

**Zweck:** Eine konsistente Sicht auf Fortschritt, Prognose und
Handlungsbedarf herstellen.

Mögliche Funktionen: - Einheitliches Statusmodell und
Reporting-Kadenz. - Meilenstein- und Terminstatus. - Fortschritt und
Leistungsindikatoren. - Soll-Ist-Vergleich und Prognose. - Maßnahmen,
Verantwortliche und Fälligkeiten. - Projektgesundheit getrennt von
formaler Statusampel. - Automatische Hinweise bei
Schwellenwertüberschreitungen. - Drill-down von Portfolio- auf Projekt-
und Arbeitsebene.

## 3.7 Resource Management -- Ressourcen und Kapazitäten

**Zweck:** Portfolioentscheidungen mit tatsächlich verfügbaren
Kompetenzen und Kapazitäten abgleichen.

Mögliche Funktionen: - Kapazitätsplanung nach Organisation, Rolle, Skill
und Zeitraum. - Bedarf und Verfügbarkeit gegenüberstellen. - Engpass-
und Konflikterkennung. - Kritische Schlüsselrollen sichtbar machen. -
Szenarien für Verschiebung, Stopp oder zusätzliche Kapazität. - Abgleich
mit HR- und Ressourcenplanungssystemen.

## 3.8 Financial Governance -- Finanzielle Steuerung

**Zweck:** Investitionsentscheidungen und laufende Finanzierung
transparent und belastbar machen.

Mögliche Funktionen: - Business Case mit Annahmen und Versionierung. -
Budgetantrag und gestufte Budgetfreigabe. - Plan-, Ist- und
Forecast-Kosten. - Forecast at Completion und Budgetabweichung. -
Mehrjahres- und Periodenbetrachtung. - Nachfinanzierungs- und
Re-Baselining-Prozess. - Verbindung von Kosten, Nutzen und strategischem
Beitrag. - Integration mit ERP- und Finanzsystemen.

## 3.9 Risk & Issue Management -- Risiken und Probleme

**Zweck:** Unsicherheiten und eingetretene Probleme frühzeitig erfassen,
bewerten und behandeln.

Mögliche Funktionen: - Einheitliche Risikokategorien und
Bewertungsmethodik. - Eintrittswahrscheinlichkeit, Auswirkung und
Risikoeigner. - Maßnahmen, Fristen und Restrisiko. - Issues und
Eskalationspfade. - Aggregierte Portfolio-Risikosicht. - Abhängigkeiten
und Korrelationen zwischen Risiken. - Schwellenwerte und automatische
Eskalationshinweise.

## 3.10 Change Governance -- Änderungssteuerung

**Zweck:** Änderungen an Umfang, Zielen, Kosten, Terminen und Nutzen
kontrolliert entscheiden.

Mögliche Funktionen: - Change Requests mit Begründung und
Antragsteller. - Impact Assessment für Kosten, Zeit, Ressourcen, Risiken
und Nutzen. - Genehmigung nach Schwellenwert und Mandat. - Versionierung
von Scope, Business Case und Baseline. - Nachvollziehbarkeit von
Änderungen und Entscheidungen. - Portfolioauswirkungen bei wesentlichen
Änderungen.

## 3.11 Benefits Management -- Nutzenrealisierung

**Zweck:** Sicherstellen, dass erwarteter Nutzen definiert,
verantwortet, gemessen und nachgehalten wird.

Mögliche Funktionen: - Nutzenregister mit Kennzahl, Baseline, Zielwert
und Messzeitpunkt. - Benefit Owner außerhalb der Projektleitung, sofern
sinnvoll. - Nutzenprognose und Realisierungsstatus. - Messung nach
Go-Live und in definierten Review-Zeitpunkten. - Abweichungsanalyse
zwischen geplantem und realisiertem Nutzen. - Rückkopplung in Business
Cases und künftige Priorisierungen.

## 3.12 Reporting & Assurance -- Reporting und Kontrolle

**Zweck:** Verlässliche Steuerungsinformationen und nachvollziehbare
Einhaltung der Governance bereitstellen.

Mögliche Funktionen: - Rollen- und adressatengerechte Dashboards. -
Portfolio-, Programm- und Projektberichte. - Drill-down und
Datenherkunft. - Datenqualitätsindikatoren. - Audit Trail und
Historisierung. - Governance-Compliance und Gate-Überblick. - Export und
standardisierte Berichtsformate.

# 4. End-to-End-Governance-Prozess

Der folgende Ablauf zeigt einen beispielhaften durchgängigen
Governance-Zyklus. Die konkrete Ausgestaltung muss nach Vorhabenart,
Risiko, Größe und regulatorischen Anforderungen konfigurierbar sein.

``` mermaid
flowchart TD
    A["Bedarf / Idee"] --> B["Standardisierter Intake"]
    B --> C["Triage und Vollständigkeitsprüfung"]
    C -->|Unvollständig| B
    C --> D["Vorprüfung und Kategorisierung"]
    D --> E["Business Case und Bewertung"]
    E --> F["Portfolio- und Kapazitätsanalyse"]
    F --> G{"Entscheidung"}
    G -->|Ablehnen| H["Ablehnung dokumentieren"]
    G -->|Zurückstellen| I["Warteliste / Wiedervorlage"]
    G -->|Annehmen| J["Initiierung und Projektauftrag"]
    J --> K["Gate: Planungsfreigabe"]
    K -->|Nacharbeit| J
    K -->|Hold / Stop| L["Pause oder Beendigung"]
    K -->|Go| M["Umsetzung und laufendes Reporting"]
    M --> N{"Abweichung / Change / Risiko?"}
    N -->|Ja| O["Impact Assessment und Eskalation"]
    O --> P{"Entscheidung"}
    P -->|Änderung genehmigt| M
    P -->|Neu planen| K
    P -->|Stop / Hold| L
    N -->|Nein| Q{"Nächstes Gate erreicht?"}
    Q -->|Nein| M
    Q -->|Ja| R["Übergabe und Abschluss"]
    R --> S["Nutzenmessung nach Go-Live"]
    S --> T["Lessons Learned und Rückkopplung"]
    T --> E
```

## 4.1 Entscheidungszustände

Ein einheitliches Zustandsmodell erleichtert Reporting und
Automatisierung. Beispielhafte Zustände:

  Zustand                   Bedeutung
  ------------------------- ----------------------------------------------------
  Entwurf                   Antrag wird vorbereitet
  Eingereicht               Intake erfolgt
  In Prüfung                Fachliche oder wirtschaftliche Bewertung läuft
  Entscheidung ausstehend   Entscheidungsreife erreicht
  Angenommen                Vorhaben grundsätzlich freigegeben
  Zurückgestellt            Entscheidung bewusst vertagt, mit Wiedervorlage
  Abgelehnt                 Antrag verbindlich nicht angenommen
  Geplant                   Im Portfolio eingeordnet, Start noch nicht erfolgt
  Aktiv                     Vorhaben wird umgesetzt
  On Hold                   Vorübergehend pausiert
  In Eskalation             Entscheidung oder Intervention erforderlich
  Gestoppt                  Vorhaben beendet
  Abgeschlossen             Projekt formal beendet
  Nutzenreview              Nutzenrealisierung wird nachverfolgt

Status und Entscheidung sind zu unterscheiden: Ein Projektstatus
beschreibt den Zustand des Vorhabens; ein Beschluss dokumentiert eine
konkrete Governance-Entscheidung.

# 5. Governance-Ebenen und Verantwortlichkeiten

## 5.1 Beispielhafte Gremienstruktur

``` mermaid
flowchart TB
    EX["Geschäftsführung / Executive Committee"]
    PB["Portfolio Board"]
    PMO["Enterprise PMO / Portfolio Office"]
    AB["Architektur-, Risiko- und Compliance-Funktionen"]
    SC["Projekt-Lenkungsausschuss"]
    SP["Sponsor / Auftraggeber"]
    PL["Projektleitung"]
    TEAM["Projektteam"]

    EX -->|"Strategische Leitplanken und Mandat"| PB
    PB <-->|"Entscheidungen und Portfolio-Transparenz"| PMO
    AB -->|"Standards, Prüfungen, Stellungnahmen"| PB
    PB -->|"Freigabe, Priorität, Finanzierung"| SP
    SP --> SC
    SC <-->|"Steuerung und Eskalation"| PL
    PL --> TEAM
    PL -->|"Status, Risiken, Forecast"| PMO
    SC -->|"Entscheidungen und Auflagen"| PL
```

Die Darstellung ist ein Beispiel, keine universelle
Organisationsvorgabe. In kleineren Organisationen können Rollen
zusammenfallen; in regulierten oder stark dezentralen Organisationen
können zusätzliche Gremien erforderlich sein.

## 5.2 Beispielhafte Entscheidungsrechte

  ----------------------------------------------------------------------------------------------
  Entscheidung          Vorschlag /         Entscheidung (Beispiel)  Beteiligte
                        Vorbereitung                                 
  --------------------- ------------------- ------------------------ ---------------------------
  Strategische Ziele    Strategie /         Geschäftsführung         Portfolio Board
  und Leitplanken       Geschäftsbereiche                            

  Aufnahme in den       Fachbereich /       Intake-Verantwortliche   PMO
  Demand-Prozess        Sponsor             für Triage               

  Portfolio-Priorität   PMO / Fachbereiche  Portfolio Board          Finance,
                                                                     Ressourcenverantwortliche

  Projektstart          Sponsor /           Zuständiges Portfolio-   PMO, Finance
  innerhalb Mandat      Projektleitung      oder Investitionsgremium 

  Gate-Freigabe         Projektleitung /    Benanntes Gate-Gremium   Fachstellen
                        Sponsor                                      

  Budgetänderung        Sponsor / Finance   Instanz gemäß            Portfolio Board
                                            Schwellenwert            

  Scope-Änderung        Projektleitung /    Instanz gemäß Auswirkung Betroffene Bereiche
                        Sponsor             und Mandat               

  Projektstopp          Sponsor / PMO       Mandatierte              Portfolio Board, Finance
                                            Entscheidungsinstanz     

  Nutzenreview          Benefit Owner       Verantwortliche          Finance, Fachbereich
                                            Managementinstanz        
  ----------------------------------------------------------------------------------------------

Für die konkrete Organisation sollte eine RACI- oder besser eine
explizite Entscheidungsrechte-Matrix erstellt werden. „Accountable" darf
pro Entscheidung nicht diffus auf mehrere Rollen verteilt sein.

# 6. Entscheidungsmodell und Priorisierung

## 6.1 Bewertungsdimensionen

Ein mögliches Scoring kann folgende Dimensionen berücksichtigen:

  ------------------------------------------------------------------------
  Kriterium             Leitfrage               Beispielhafte Gewichtung\*
  --------------------- --------------------- ----------------------------
  Strategischer Beitrag Wie stark unterstützt                         25 %
                        das Vorhaben          
                        strategische Ziele?   

  Wirtschaftlicher      Welcher                                       20 %
  Nutzen                quantifizierbare oder 
                        qualitative Nutzen    
                        entsteht?             

  Regulatorik /         Ist das Vorhaben                              15 %
  Verpflichtung         verpflichtend oder    
                        zeitkritisch?         

  Risikoreduktion       Welche Risiken werden                         15 %
                        reduziert oder        
                        vermieden?            

  Dringlichkeit / Time  Welche Nachteile                              10 %
  Criticality           entstehen bei         
                        Verzögerung?          

  Aufwand /             Wie hoch sind Aufwand                         10 %
  Umsetzbarkeit         und Komplexität?      

  Abhängigkeiten /      Ermöglicht das                                 5 %
  Enabler               Vorhaben andere       
                        wichtige Vorhaben?    
  ------------------------------------------------------------------------

\*Die Gewichte sind nur ein illustratives Beispiel und müssen
organisationsspezifisch validiert werden. Pflichtvorhaben sollten nicht
unreflektiert mit optionalen Vorhaben in einem einzigen Score
konkurrieren.

## 6.2 Priorisierung ist mehr als ein Score

Ein tragfähiges Entscheidungsmodell kombiniert:

1.  **Strategische Eignung:** Passt das Vorhaben zu den Zielen?
2.  **Wertbeitrag:** Welcher Nutzen ist realistisch?
3.  **Risiko und Verpflichtung:** Was passiert, wenn nicht investiert
    wird?
4.  **Machbarkeit:** Sind Kompetenzen und Kapazitäten vorhanden?
5.  **Abhängigkeiten:** Welche anderen Vorhaben werden ermöglicht oder
    blockiert?
6.  **Finanzierbarkeit:** Passt das Vorhaben in Budget und Planung?
7.  **Managemententscheidung:** Welche bewussten Trade-offs werden
    getroffen?

Ein hoher Score ist daher keine automatische Freigabe. Die Entscheidung
muss begründet, einem Mandat zugeordnet und dokumentiert werden.

## 6.3 Portfolio-Szenarien

Die Software sollte mindestens folgende Szenarien vergleichbar machen:

-   Bestehendes Portfolio unverändert fortführen.
-   Neues Vorhaben aufnehmen: Was wird verdrängt oder verschoben?
-   Kapazität oder Budget erhöhen.
-   Vorhaben pausieren oder stoppen.
-   Prioritäten oder strategische Gewichtungen ändern.

# 7. Funktionale Anforderungen an die Software

Die folgende Liste ist ein erster Anforderungskatalog auf
Capability-Ebene. Sie beschreibt, was die Lösung ermöglichen soll, nicht
bereits die endgültige Bedienoberfläche oder technische Umsetzung.

  ---------------------------------------------------------------------------------
  ID                      Capability                 Erwartetes Verhalten
  ----------------------- -------------------------- ------------------------------
  GOV-001                 Organisationsmodell        Organisationseinheiten,
                                                     Bereiche und Zuständigkeiten
                                                     verwalten

  GOV-002                 Rollen und Rechte          Rollen- und objektbezogene
                                                     Berechtigungen steuern

  GOV-003                 Governance-Konfiguration   Prozesse, Phasen, Kriterien
                                                     und Schwellenwerte
                                                     konfigurierbar machen

  DEM-001                 Intake                     Einheitlichen Eingang für
                                                     Vorhaben bereitstellen

  DEM-002                 Triage                     Vollständigkeit, Kategorie und
                                                     Zuständigkeit prüfen

  DEM-003                 Antragshistorie            Änderungen, Rückfragen und
                                                     Entscheidungen nachvollziehen

  POR-001                 Portfolioübersicht         Vorhaben über Organisation und
                                                     Zeitraum aggregieren

  POR-002                 Scoring                    Kriterien und Gewichte
                                                     versioniert bewerten

  POR-003                 Szenarioanalyse            Portfolioalternativen
                                                     simulieren und vergleichen

  POR-004                 Kapazitätsabgleich         Vorhaben mit
                                                     Kapazitätsrestriktionen
                                                     abgleichen

  DEC-001                 Entscheidungsregister      Entscheidungen mit Begründung,
                                                     Mandat und Verantwortlichen
                                                     dokumentieren

  DEC-002                 Gremienworkflow            Vorlagen, Tagesordnung,
                                                     Beschlüsse und Auflagen
                                                     unterstützen

  DEC-003                 Beschlussverfolgung        Maßnahmen, Fälligkeiten und
                                                     Eskalationen nachhalten

  LIF-001                 Phasenmodell               Projektlebenszyklen
                                                     konfigurierbar abbilden

  LIF-002                 Stage Gates                Kriterien, Nachweise und
                                                     Freigaben steuern

  PRJ-001                 Projektstatus              Einheitliches Status- und
                                                     Gesundheitsmodell
                                                     bereitstellen

  PRJ-002                 Meilensteine               Termine, Baselines und
                                                     Prognosen verfolgen

  PRJ-003                 Maßnahmen                  Gegenmaßnahmen mit
                                                     Verantwortlichen und Fristen
                                                     verwalten

  RES-001                 Ressourcen                 Kapazitäten und Skills nach
                                                     Zeitraum abbilden

  FIN-001                 Business Case              Nutzen- und Kostenannahmen
                                                     versionieren

  FIN-002                 Budgetsteuerung            Budget, Ist und Forecast
                                                     vergleichen

  RSK-001                 Risiken und Issues         Risiken, Probleme, Maßnahmen
                                                     und Eskalationen verwalten

  CHG-001                 Change Requests            Änderungen mit Impact
                                                     Assessment und Genehmigung
                                                     steuern

  BEN-001                 Nutzenregister             Nutzenkennzahlen, Baselines,
                                                     Zielwerte und Owner verwalten

  BEN-002                 Nutzenreviews              Realisierung nach
                                                     Projektabschluss nachhalten

  REP-001                 Dashboards                 Rollen- und adressatengerechte
                                                     Sichten bereitstellen

  AUD-001                 Audit Trail                Änderungen und Entscheidungen
                                                     manipulationsnachvollziehbar
                                                     protokollieren

  INT-001                 Integrationen              Daten mit Umsystemen
                                                     austauschen

  DAT-001                 Datenqualität              Pflichtfelder,
                                                     Plausibilitätsregeln und
                                                     Datenverantwortung
                                                     unterstützen
  ---------------------------------------------------------------------------------

## 7.1 Beispielhafte User Stories

-   Als Portfolio Board möchte ich eine priorisierte Portfolioansicht
    mit Kapazitäts- und Budgetrestriktionen sehen, damit ich
    realisierbare Entscheidungen treffen kann.
-   Als Sponsor möchte ich einen Business Case mit Annahmen und
    Versionen einreichen, damit die Entscheidungsgrundlage
    nachvollziehbar bleibt.
-   Als PMO möchte ich überfällige Statusmeldungen und kritische
    Abweichungen erkennen, damit ich rechtzeitig eskalieren kann.
-   Als Projektleitung möchte ich einen Change Request mit Auswirkungen
    auf Termine, Kosten und Nutzen einreichen, damit Änderungen nicht
    informell erfolgen.
-   Als Benefit Owner möchte ich den realisierten Nutzen nach Go-Live
    erfassen, damit Plan und tatsächliche Wirkung verglichen werden
    können.
-   Als Revisor möchte ich Entscheidungen und Änderungen mit Zeitstempel
    und Verantwortlichem nachvollziehen können, damit die Governance
    prüfbar ist.

# 8. Übergreifende Plattformanforderungen

## 8.1 Konfigurierbarkeit

-   Unterschiedliche Governance-Modelle nach Einheit, Vorhabenart und
    Risikoklasse.
-   Konfigurierbare Formulare, Pflichtfelder und Validierungen.
-   Regelbasierte Workflows und Eskalationen.
-   Versionierung von Kriterien, Schwellenwerten und
    Prozessdefinitionen.
-   Konfiguration ohne individuelle Softwareentwicklung, soweit
    sinnvoll.

## 8.2 Rollen, Rechte und Sicherheit

-   Rollen- und attributbasierte Berechtigungen.
-   Trennung von Antragstellung, Prüfung und Genehmigung, wo
    erforderlich.
-   Mandanten- und Organisationsbereichstrennung, falls erforderlich.
-   Single Sign-on und zentrale Identitätsverwaltung.
-   Protokollierung sicherheitsrelevanter Aktionen.
-   Datenschutz und Lösch-/Aufbewahrungskonzepte gemäß geltenden
    Vorgaben.

## 8.3 Daten und Integrationen

Mögliche Integrationsdomänen:

-   ERP und Finance: Budgets, Ist-Kosten, Forecasts.
-   HR und Ressourcenplanung: Organisation, Skills, Kapazitäten.
-   Projektmanagement: Termine, Meilensteine, Fortschritt.
-   Enterprise Architecture: Architekturstandards und Abhängigkeiten.
-   Dokumentenmanagement: Business Cases, Beschlüsse und Nachweise.
-   BI / Data Platform: unternehmensweite Analysen.
-   Identity Provider: Benutzer, Gruppen und Authentifizierung.

``` mermaid
flowchart LR
    subgraph Sources["Führende Systeme / Datenquellen"]
        ERP["ERP / Finance"]
        HR["HR / Skills"]
        PM["Projektmanagement"]
        EA["Enterprise Architecture"]
        DMS["Dokumentenmanagement"]
        IDP["Identity Provider"]
    end

    subgraph Gov["Governance-Plattform"]
        API["API- und Integrationsschicht"]
        CORE["Governance-Kern<br/>Demand · Portfolio · Projekte"]
        WF["Workflow- und Regel-Engine"]
        DATA["Governance-Datenmodell"]
        AUD["Audit Trail"]
        BI["Dashboards und Analysen"]
    end

    ERP <--> API
    HR <--> API
    PM <--> API
    EA <--> API
    DMS <--> API
    IDP --> API
    API <--> CORE
    CORE <--> WF
    CORE <--> DATA
    CORE --> AUD
    DATA --> BI
```

## 8.4 Datenqualität und Verantwortlichkeit

-   Für jedes zentrale Datenobjekt ist ein fachlicher Owner zu benennen.
-   Daten müssen aktuell, vollständig und plausibel sein.
-   Manuelle und integrierte Datenquellen müssen erkennbar sein.
-   Änderungen an Baselines und Stammdaten müssen nachvollziehbar sein.
-   Kennzahlen benötigen eindeutige Definitionen und Berechnungsregeln.

## 8.5 Automatisierung und KI

Mögliche Einsatzfelder: - Erkennen widersprüchlicher Angaben zwischen
Status, Risiken, Kosten und Terminen. - Hinweise auf überfällige
Entscheidungen oder Maßnahmen. - Zusammenfassungen von Projekt- und
Portfolioinformationen. - Erkennen von Mustern bei Verzögerungen und
Budgetüberschreitungen. - Unterstützung bei der Vorbereitung von
Entscheidungsvorlagen.

Leitplanken: - KI-Ergebnisse müssen als Hinweise oder Empfehlungen
erkennbar sein. - Datenbasis und Unsicherheit müssen transparent sein. -
Entscheidungen mit Governance-Wirkung bleiben bei legitimierten
Personen. - Zugriffsrechte, Datenschutz und Protokollierung gelten auch
für KI-Funktionen.

# 9. Reporting- und Steuerungsmodell

Ein Governance-System benötigt wenige, klar definierte Kennzahlen statt
einer möglichst großen Zahl von Ampeln.

## 9.1 Beispiele für Kennzahlen

  -----------------------------------------------------------------------
  Perspektive                         Beispielkennzahlen
  ----------------------------------- -----------------------------------
  Demand                              Anzahl neuer Anträge,
                                      Durchlaufzeit, Rückfragequote,
                                      Ablehnungsquote

  Portfolio                           Anzahl aktiver Vorhaben,
                                      Investitionsvolumen, strategische
                                      Abdeckung, WIP

  Kapazität                           Auslastung, Engpassrollen,
                                      Nachfrage-Verfügbarkeits-Lücke

  Finanzen                            Budget, Ist, Forecast at
                                      Completion, Abweichung

  Termine                             Meilensteintreue, prognostizierte
                                      Verzögerung

  Risiken                             Kritische Risiken, überfällige
                                      Maßnahmen, aggregierte Exponierung

  Entscheidungen                      Entscheidungsdurchlaufzeit, offene
                                      Beschlüsse, überfällige Auflagen

  Nutzen                              Nutzenprognose, realisierter
                                      Nutzen, Abweichung zum Business
                                      Case

  Governance                          Gate-Compliance,
                                      Datenvollständigkeit, überfällige
                                      Reviews
  -----------------------------------------------------------------------

Kennzahlen brauchen Definition, Datenquelle, Aktualisierungsfrequenz,
Owner und klare Interpretation. Eine Ampel ohne nachvollziehbare
Schwellenwerte ist kein belastbares Steuerungsinstrument.

## 9.2 Beispiel: Management-Dashboard

``` mermaid
flowchart TB
    DASH["Management-Dashboard"]
    DASH --> P["Portfolio"]
    DASH --> F["Finanzen"]
    DASH --> C["Kapazitäten"]
    DASH --> R["Risiken"]
    DASH --> D["Entscheidungen"]
    DASH --> B["Nutzen"]

    P --> P1["Aktive / geplante / gestoppte Vorhaben"]
    P --> P2["Strategische Zielabdeckung"]
    F --> F1["Budget · Ist · Forecast"]
    C --> C1["Engpässe · Auslastung"]
    R --> R1["Top-Risiken · Eskalationen"]
    D --> D1["Offene Beschlüsse · Entscheidungsalter"]
    B --> B1["Geplanter vs. realisierter Nutzen"]
```

# 10. MVP und Ausbaustufen

Ein vollständiges Zielbild sollte nicht bedeuten, alle Funktionen in der
ersten Produktversion umzusetzen. Der MVP muss einen klaren,
geschlossenen Steuerungskreislauf ermöglichen.

## 10.1 MVP -- Governance-Fundament

Empfohlener Umfang:

1.  Organisations-, Rollen- und Berechtigungsmodell.
2.  Standardisierter Demand Intake.
3.  Triage und nachvollziehbare Antragsentscheidungen.
4.  Strategische Zuordnung und konfigurierbares Scoring.
5.  Portfolioübersicht mit einfachen Budget- und
    Kapazitätsrestriktionen.
6.  Entscheidungsregister mit Beschlussverfolgung.
7.  Basis-Projektlebenszyklus mit Freigaben.
8.  Einheitlicher Projektstatus und Eskalationshinweise.
9.  Basis-Dashboard und Audit Trail.

**MVP-Ergebnis:** Ein Antrag kann von der Einreichung über Bewertung und
Entscheidung bis zur Aufnahme ins Portfolio und zur ersten
Projektfreigabe nachvollziehbar gesteuert werden.

## 10.2 Ausbaustufe 2 -- Integrierte Projektsteuerung

-   Erweiterte Stage-Gate-Modelle.
-   Finanzielle Steuerung mit Forecast.
-   Risiko-, Issue- und Maßnahmenmanagement.
-   Change Requests und Baseline-Management.
-   Ressourcen- und Skillplanung.
-   Erweiterte Integrationen.

## 10.3 Ausbaustufe 3 -- Portfoliooptimierung und Nutzensteuerung

-   Szenario- und What-if-Analysen.
-   Erweiterte Abhängigkeitsmodellierung.
-   Nutzenrealisierung über den Projektabschluss hinaus.
-   Portfolio-Risikokonsolidierung.
-   Prognosen und KI-gestützte Frühwarnungen.
-   Benchmarking und kontinuierliche Governance-Verbesserung.

## 10.4 MVP-Abgrenzung als Entscheidungsfrage

Vor dem Start sollte geklärt werden, ob das Produkt zunächst vor allem
ein **Portfolio- und Investment-Governance-System** oder von Anfang an
ein **End-to-End-Project-Governance-System** sein soll. Die empfohlene
Produktvision ist End-to-End; der MVP kann dennoch mit Intake,
Portfolio, Entscheidungen und Basis-Projektfreigaben starten.

# 11. Erfolgsfaktoren und Risiken der Einführung

## 11.1 Kritische Erfolgsfaktoren

-   Sichtbares Mandat der Geschäftsführung.
-   Eindeutige Entscheidungsrechte und Eskalationswege.
-   Einfache, risikogerechte Prozesse statt maximaler Bürokratie.
-   Verbindliche Datenverantwortung.
-   Konsequente Nutzung der Beschlüsse und Workflows.
-   Integration in bestehende Arbeitsabläufe.
-   Transparente Kriterien und nachvollziehbare Ausnahmen.
-   Befähigung der Rollen und kontinuierliche Weiterentwicklung.

## 11.2 Typische Einführungsrisiken

  -----------------------------------------------------------------------
  Risiko                              Gegenmaßnahme
  ----------------------------------- -----------------------------------
  Tool wird eingeführt, Mandate       Governance- und Kompetenzmodell vor
  bleiben unklar                      oder parallel zur Konfiguration
                                      verabschieden

  Zu viele Pflichtfelder und          Risikobasierte Prozessvarianten und
  Prozessschritte                     schlanker MVP

  Schattenprozesse bleiben bestehen   Verbindlicher Intake und klare
                                      Regel für Ausnahmen

  Daten sind unvollständig oder       Daten-Owner, Validierungen und
  veraltet                            klare Aktualisierungspflichten

  Scoring wird als automatische       Entscheidungshoheit und
  Wahrheit verstanden                 Begründungspflicht beim Gremium
                                      belassen

  Doppelpflege mit bestehenden Tools  Führende Systeme und
                                      Integrationsgrenzen festlegen

  Reporting wird geschönt             Kennzahlen, Datenherkunft und
                                      Eskalationsregeln transparent
                                      machen
  -----------------------------------------------------------------------

# 12. Offene Konzeptentscheidungen

Vor der detaillierten Anforderungserhebung sollten mindestens folgende
Fragen beantwortet werden:

1.  Welche Organisationseinheiten und Vorhabenarten umfasst die Lösung?
2.  Werden ausschließlich Projekte oder auch Programme, Produkte,
    Initiativen und Changes gesteuert?
3.  Welche Governance-Ebenen sind verbindlich im Scope?
4.  Welche Gremien existieren bereits und welche Mandate besitzen sie?
5.  Welche Entscheidungsschwellen gelten für Budget, Risiko und
    strategische Bedeutung?
6.  Welche Vorhaben sind verpflichtend und benötigen einen Sonderpfad?
7.  Wie detailliert soll Ressourcenplanung erfolgen -- auf Rollen-,
    Skill- oder Personenebene?
8.  Welche Systeme bleiben führend für Kosten, Personal, Termine und
    Dokumente?
9.  Welche Anforderungen bestehen an Datenschutz,
    Informationssicherheit, Audit und Aufbewahrung?
10. Welche Kennzahlen sind für Geschäftsführung, Portfolio Board, PMO
    und Projektleitung entscheidend?
11. Welche Konfigurationsmöglichkeiten benötigt ein Kunde ohne
    individuelle Entwicklung?
12. Welche Funktionen sind für den MVP unverzichtbar?

# 13. Empfohlene nächste Arbeitspakete

  -----------------------------------------------------------------------
  Arbeitspaket                        Ergebnis
  ----------------------------------- -----------------------------------
  AP 1 -- Stakeholder- und            Abgestimmtes Problembild und
  Problemvalidierung                  Zielgruppen

  AP 2 -- Governance Operating Model  Ebenen, Rollen, Gremien und
                                      Entscheidungsrechte

  AP 3 -- Prozesslandkarte            End-to-End-Prozesse, Varianten und
                                      Eskalationen

  AP 4 -- Informations- und           Zentrale Objekte, Beziehungen,
  Datenmodell                         Datenverantwortung

  AP 5 -- Funktionale Anforderungen   Epics, Capabilities und
                                      priorisierte Anforderungen

  AP 6 -- Integrations- und           Systemgrenzen, Schnittstellen,
  Architekturkonzept                  Sicherheit

  AP 7 -- MVP-Schnitt                 Priorisierter Funktionsumfang und
                                      Akzeptanzkriterien
  -----------------------------------------------------------------------

## 13.1 Zentrale fachliche Datenobjekte

Als Ausgangspunkt für ein späteres Datenmodell bieten sich an:

-   Strategisches Ziel
-   Demand / Anforderung
-   Initiative / Programm / Projekt
-   Business Case und Version
-   Portfolio und Portfolioperiode
-   Bewertung und Scoring-Version
-   Budget und Forecast
-   Ressource / Skill / Kapazitätsbedarf
-   Risiko und Issue
-   Change Request
-   Phase und Gate
-   Entscheidung und Beschluss
-   Maßnahme
-   Nutzenkennzahl und Benefit Review
-   Dokument und Nachweis
-   Organisationseinheit, Rolle und Person

``` mermaid
erDiagram
    STRATEGIC_OBJECTIVE ||--o{ DEMAND : supports
    DEMAND ||--o| INITIATIVE : becomes
    PORTFOLIO ||--o{ INITIATIVE : contains
    INITIATIVE ||--o{ BUSINESS_CASE_VERSION : has
    INITIATIVE ||--o{ PROJECT : may_realize_as
    PROJECT ||--o{ PROJECT_GATE : passes
    PROJECT ||--o{ STATUS_REPORT : reports
    PROJECT ||--o{ RISK : identifies
    PROJECT ||--o{ ISSUE : has
    PROJECT ||--o{ CHANGE_REQUEST : receives
    PROJECT ||--o{ DECISION : is_subject_of
    DECISION ||--o{ ACTION : creates
    PROJECT ||--o{ BENEFIT : targets
    BENEFIT ||--o{ BENEFIT_REVIEW : measured_by
    PORTFOLIO ||--o{ PORTFOLIO_PERIOD : plans
    PROJECT ||--o{ RESOURCE_DEMAND : requires
    ORGANIZATION_UNIT ||--o{ PERSON : employs
    PERSON ||--o{ ROLE_ASSIGNMENT : holds
```

Das Diagramm ist ein konzeptioneller Startpunkt. Kardinalitäten und
Entitäten sind in einer fachlichen Datenmodellierung zu validieren;
beispielsweise können Initiativen mehrere Projekte bündeln und ein
Projekt mehreren strategischen Zielen zugeordnet sein.

# 14. Zusammenfassung

Die ursprüngliche Problembeschreibung adressiert zentrale Schwächen von
Projekt-Governance -- insbesondere Demand Management, Priorisierung,
Kapazitätsblindheit, Entscheidungsmandate und Governance-Kultur.

Für ein belastbares Softwarekonzept muss sie um den vollständigen
Steuerungskreislauf erweitert werden: von der strategischen Ausrichtung
und dem Intake über Portfolioentscheidung, Projektfreigabe und laufende
Kontrolle bis zur Nutzenrealisierung und Rückkopplung.

Die zwölf Governance-Dimensionen bilden dafür eine geeignete fachliche
Struktur. Entscheidend ist, dass die Software nicht nur Informationen
sammelt, sondern **verbindliche Entscheidungsprozesse,
Verantwortlichkeiten, Nachverfolgung und Transparenz** ermöglicht.

**Leitprinzip für das Produkt:** Die richtige Arbeit auswählen,
realistisch finanzieren und besetzen, wirksam steuern, rechtzeitig
korrigieren oder stoppen und den erzielten Nutzen überprüfen.
