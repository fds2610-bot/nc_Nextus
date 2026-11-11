// Bildspur zum Podcast „Wie smarte Projektanträge Schattenprojekte verhindern“ (Kapitel 1: Antrag).
// Zeiten = Position im Audio (aus der Transkription). Grafikkarten + Live-Aufnahmen aus Nexus.
const C = require('../lib/cards')
const { sleep } = require('../lib/rec')
const { mkApi } = require('../lib/api')

const K = s => { const [m, x] = s.split(':').map(Number); return m * 60 + x }
const E = '[data-test="demand-editor"]'
const TITLE = 'Digitale Reisekostenabrechnung'
const PROBLEM = 'Reisekosten werden heute mit Excel und ausgedruckten Papierbelegen abgerechnet. Das kostet die Buchhaltung jeden Monat etwa vierzig Stunden händische Nacharbeit und erzeugt Fehler, weil Belege verloren gehen.'

module.exports = {
  id: 'podcast01',
  file: 'Podcast-01-Schattenprojekte-verhindern',
  title: 'Podcast zu Teil 1: Wie smarte Projektanträge Schattenprojekte verhindern',
  audio: 'podcast01.m4a',
  duration: 1533.7,

  async prepare() {
    const anna = mkApi('anna'), paula = mkApi('paula')
    const old = await anna.post('/demands', { title: 'Reisekostenabrechnung: Belege automatisch scannen', demandType: 'efficiency', problemStatement: 'Belege der Reisekosten werden von Hand erfasst und abgetippt.' })
    await anna.post(`/demands/${old.id}/submit`)
    const q = await anna.post('/demands', { title: 'Außendienst-Tablets ersetzen', demandType: 'lifecycle', problemStatement: 'Die Tablets im Außendienst sind vier Jahre alt und fallen häufig aus.' })
    await anna.post(`/demands/${q.id}/submit`)
    const due = new Date(Date.now() + 7 * 86400000).toISOString().slice(0, 10)
    await paula.post(`/demands/${q.id}/query`, { question: 'Wie viele Geräte sind betroffen, und gibt es einen festen Termin für den Austausch?', due })
    return { queryKey: q.key }
  },

  /** Cues: { t, name, card?: { end, html(t0) }, act?: (r, st) => Promise } */
  cues: (st) => [
    // ---- Einstieg: Schattenprojekte
    { t: 0, name: 'Karte: Schattenprojekte', card: { end: K('0:23'), html: t0 => C.list('Deep Dive · Nexus Schritt 1', 'Schattenprojekte: Millionen für Projekte, die niemand sieht', [['IT-Lösungen', K('0:10') - t0], ['Software-Lizenzen', K('0:12') - t0], ['Prozessumstellungen – heimlich eingekauft', K('0:14') - t0], ['am offiziellen Management vorbei', K('0:19') - t0]]) } },
    { t: K('0:23'), name: 'Karte: offizieller Eingang vs. Hintereingang', card: { end: K('1:01'), html: t0 => C.cols('Warum passiert das?', 'Zwei Türen für neue Ideen', [
      { head: 'Der offizielle Eingang', at: 1, items: [['bürokratisch', K('0:28') - t0], ['starr und abschreckend', K('0:31') - t0], ['eine Wand aus Formularen', K('0:48') - t0]] },
      { head: 'Der Hintereingang', tone: 'bad', at: K('0:35') - t0, items: [['heimlich eingekauft', K('0:38') - t0], ['Lücke im System', K('0:57') - t0]] }]) } },
    { t: K('1:01'), name: 'Karte: Symptom eines kaputten Prozesses', card: { end: K('1:10'), html: () => C.quote('Schatten-IT', 'Selten böser Wille – sondern das Symptom eines kaputten Prozesses.', '', 'warm') } },
    { t: K('1:11'), name: 'Karte: die Quelle', card: { end: K('1:39'), html: t0 => C.list('Die Quelle', 'Notizen aus einer internen Schulung', [['Marlene – Senior-PMO', K('1:25') - t0], ['Jonas – Junior-PMO', K('1:33') - t0], ['Das System: Nexus', K('1:36') - t0]]) } },
    { t: K('1:39'), name: 'Karte: erster Schritt', card: { end: K('1:52'), html: () => C.hero('Heute', 'Der erste Schritt: Wie erfasst man einen Projektantrag?', '') } },
    { t: K('1:52'), name: 'Karte: Verhaltenspsychologie', card: { end: K('2:20'), html: t0 => C.list('Mehr als ein Handbuch', 'Systemdesign steuert Verhalten', [['Ein Meisterstück der Verhaltenspsychologie', K('1:56') - t0], ['Eine Eingangstür, die jeder freiwillig nutzt', K('2:08') - t0], ['Design steuert menschliches Verhalten – live', K('2:15') - t0]]) } },
    { t: K('2:20'), name: 'Karte: die eiserne Regel', card: { end: K('3:00'), html: t0 => C.list('Die wichtigste Regel', 'Jedes Vorhaben muss als Antrag ins System', [['die brillante Idee aus dem Vertrieb', K('2:35') - t0], ['die harte Gesetzesfrist', K('2:39') - t0], ['die banale Ersatzbeschaffung von Laptops', K('2:43') - t0], ['es gibt keinen anderen Eingang', K('2:48') - t0]]) } },
    { t: K('3:00'), name: 'Karte: PMO als Flugsicherung', card: { end: K('3:14'), html: () => C.quote('Der Vergleich', 'Das PMO ist die Flugsicherung des Unternehmens.', 'Ein einziger Radarschirm, auf dem alles sichtbar ist') } },
    { t: K('3:14'), name: 'Live: Portfolioübersicht als „Radarschirm“', act: async r => { await r.click(r.page.locator('[data-test="nav-portfolio"]')) } },
    { t: K('3:22'), name: 'Live: Wechsel in das Konto von Anna', act: async r => { await r.switchUser('anna') } },
    { t: K('3:27'), name: 'Hinweis: Sicht der Antragstellerin', act: async r => { await r.badge('Sicht der Antragstellerin: Anna aus dem Vertrieb', 8000) } },
    { t: K('3:42'), name: 'Karte: unter fünf Minuten', card: { end: K('3:55'), html: () => C.quote('Das Ziel', 'Ein Antrag in unter 5 Minuten', 'Wie ein Türsteher, der alle durchwinkt – mit Absicht') } },
    { t: K('3:55'), name: 'Live: „Neuer Antrag“ öffnen', act: async r => { await r.click(r.page.locator('[data-test="new-demand"]')); await r.page.locator(`${E}`).waitFor() } },
    { t: K('4:22'), name: 'Karte: Reibung ist kein Qualitätsfilter', card: { end: K('4:52'), html: t0 => C.cols('Verhaltensökonomie', 'Reibung ist kein Qualitätsfilter', [
      { head: 'Die Annahme', tone: 'bad', at: 0.5, items: [['„Wer ein 20-seitiges Formular ausfüllt, meint es ernst.“', K('4:29') - t0]] },
      { head: 'Die Realität', tone: 'good', at: K('4:36') - t0, items: [['Reibung filtert die vielbeschäftigten Leute heraus', K('4:40') - t0], ['Gute Ideen sterben an der Bürokratie', K('4:47') - t0]] }]) } },
    { t: K('4:57'), name: 'Hinweis: Vordertür statt Badezimmerfenster', act: async r => { await r.badge('Einfacher Einstieg: Vordertür statt Badezimmerfenster', 7000) } },
    { t: K('5:10'), name: 'Hinweis: gefiltert wird später', act: async r => { await r.badge('Gefiltert wird später – durch smarte Mechanismen, nicht durch Hürden', 9000) } },
    { t: K('5:38'), name: 'Live: Titel eintippen, Dublettenhinweis erscheint', act: async r => { await r.type(r.page.locator(`${E} [data-test="field-title"] input`), TITLE) } },
    { t: K('5:52'), name: 'Live: Hinweiskasten zeigen', act: async r => { await r.hover(r.page.locator(`${E} [data-test="similar-demands"]`)) } },
    { t: K('6:06'), name: 'Hinweis: nur lesbare Anträge', act: async r => { await r.badge('Durchsucht nur Anträge, für die Anna Leserechte hat', 9000) } },
    { t: K('6:35'), name: 'Live: älteren Antrag im Hinweis zeigen', act: async r => { await r.hover(r.page.locator(`${E} [data-test^="similar-DEM"]`).first()) } },
    { t: K('6:45'), name: 'Karte: alter vs. neuer Antrag', card: { end: K('7:12'), html: t0 => C.cols('Zwei verschiedene Paar Schuhe', 'Alter Antrag ↔ neuer Antrag', [
      { head: 'Vor zwei Wochen', at: 1, items: [['„Belege automatisch scannen“', K('6:48') - t0], ['nur ein Pflaster: Papierbelege scannen', K('6:52') - t0]] },
      { head: 'Neu', tone: 'good', at: K('6:55') - t0, items: [['„Digitale Reisekostenabrechnung“', K('6:55') - t0], ['End-to-End: Genehmigung → Auszahlung', K('6:59') - t0]] }]) } },
    { t: K('7:13'), name: 'Hinweis: Anna entscheidet selbst', act: async r => { await r.badge('Anna entscheidet: bestehenden Antrag ergänzen – oder neu einreichen', 14000) } },
    { t: K('8:08'), name: 'Karte: Choice Architecture', card: { end: K('8:37'), html: () => C.lane('Choice Architecture', 'Bowling mit hochgeklappten Banden') } },
    { t: K('8:39'), name: 'Karte: Reaktanz', card: { end: K('9:14'), html: t0 => C.chain('Psychologie', 'Warum Nexus nicht hart blockiert', [['Hartes Blockieren', K('8:41') - t0], ['Widerstand: Reaktanz', K('8:46') - t0, 'hot'], ['Excel-Liste am PMO vorbei', K('9:04') - t0, 'bad']], '', 'Und wir haben wieder das Schattenprojekt.') } },
    { t: K('9:15'), name: 'Hinweis: informieren statt blockieren', act: async r => { await r.badge('Nexus blockiert nicht – es informiert und fördert Selbstreflexion', 14000) } },
    { t: K('9:33'), name: 'Karte: Triage', card: { end: K('10:05'), html: t0 => C.chain('Fachbegriff', 'Triage – das PMO bleibt die Sicherheitskontrolle', [['Sichten', K('9:41') - t0], ['Sortieren', K('9:43') - t0], ['Priorisieren', K('9:46') - t0, 'hot', 'nach Dringlichkeit']], '', 'Kommt aus der Notfallmedizin: Menschen erkennen Nuancen besser als ein starrer Algorithmus.') } },
    { t: K('10:15'), name: 'Live: Typ „Effizienz“ wählen', act: async r => { await r.click(r.page.locator(`${E} [data-test="field-type"] select`)); await r.page.locator(`${E} [data-test="field-type"] select`).selectOption({ label: 'Effizienz' }) } },
    { t: K('10:20'), name: 'Live: Org-Einheit „Vertrieb“ wählen', act: async r => { await r.click(r.page.locator(`${E} [data-test="field-org-unit"] select`)); await r.page.locator(`${E} [data-test="field-org-unit"] select`).selectOption({ label: 'Vertrieb' }) } },
    { t: K('10:33'), name: 'Karte: zwei Klicks steuern alles', card: { end: K('11:20'), html: t0 => C.chain('Zwei einfache Klicks', 'Typ und Org-Einheit steuern unsichtbar die Verteilung', [['Typ + Org-Einheit', 1], ['Bucket', K('10:35') - t0, '', 'welcher thematische Topf'], ['Sponsor', K('10:56') - t0, '', 'gibt das Budget frei'], ['Board', K('10:59') - t0, 'hot', 'welches Gremium entscheidet']], '', 'Keine Banalitäten im CEO-Board – keine Riesenthemen im Postfach eines Managers.') } },
    { t: K('11:34'), name: 'Live: grauer „Einreichen“-Knopf', act: async r => { await r.hover(r.page.locator(`${E} [data-test="submit-demand"]`)) } },
    { t: K('11:48'), name: 'Hinweis: Einreichen gesperrt', act: async r => { await r.badge('Einreichen gesperrt: Die Problembeschreibung fehlt', 11000) } },
    { t: K('12:00'), name: 'Live: Problembeschreibung anklicken', act: async r => { await r.click(r.page.locator(`${E} [data-test="field-problem"] textarea`)) } },
    { t: K('12:12'), name: 'Karte: Was passiert, wenn wir nichts tun?', card: { end: K('12:30'), html: () => C.quote('Das Herzstück des Formulars', 'Was passiert, wenn wir nichts tun?', 'Nicht: Was wollen Sie machen?') } },
    { t: K('12:31'), name: 'Karte: Solutioneering', card: { end: K('13:16'), html: t0 => C.cols('Kognitive Verzerrung', 'Solutioneering', [
      { head: 'Verliebt in die Lösung', tone: 'bad', at: K('12:37') - t0, items: [['„Wir brauchen unbedingt eine KI-gestützte Cloud-Software!“', K('12:48') - t0], ['Die Lösung sucht sich ein Problem', K('12:56') - t0]] },
      { head: 'Nexus fragt nach dem Problem', tone: 'good', at: K('13:00') - t0, items: [['Leere Floskeln: „um innovativ zu sein“', K('13:01') - t0], ['Pflicht: den echten, schmerzhaften Status quo beschreiben', K('13:09') - t0]] }]) } },
    { t: K('13:21'), name: 'Live: Problembeschreibung eintippen', act: async r => { await r.fill(r.page.locator(`${E} [data-test="field-problem"] textarea`), PROBLEM) } },
    { t: K('13:46'), name: 'Hinweis: Business Case in Reinform', act: async r => { await r.badge('40 Stunden Nacharbeit pro Monat = ein Business Case in Reinform', 7000) } },
    { t: K('13:54'), name: 'Live: erwarteten Nutzen eintippen', act: async r => { await r.fill(r.page.locator(`${E} [data-test="field-benefit"] textarea`), 'Weniger Fehler, weniger Nacharbeit und schnellere Erstattung.') } },
    { t: K('14:07'), name: 'Hinweis: Zahlen kommen später', act: async r => { await r.badge('Qualitativ genügt: Zahlen und ROI kommen erst viel später', 10000) } },
    { t: K('14:27'), name: 'Live: Fristfeld zeigen', act: async r => { await r.hover(r.page.locator(`${E} [data-test="field-deadline"] input`)); await r.badge('Frist nur bei einem echten, harten Termin', 9000) } },
    { t: K('14:45'), name: 'Hinweis: Wunschvorstellung vs. Notwendigkeit', act: async r => { await r.badge('Nur Wunschvorstellung? Dann hat der Antrag keine Priorität', 12000) } },
    { t: K('15:11'), name: 'Live: „Einreichen“ ist jetzt blau', act: async r => { await r.hover(r.page.locator(`${E} [data-test="submit-demand"]`)); await r.badge('Der Knopf „Einreichen“ ist jetzt blau', 5000) } },
    { t: K('15:49'), name: 'Live: als Entwurf speichern', act: async r => { await r.click(r.page.locator(`${E} [data-test="save-demand"]`)); await r.page.locator(`${E} [data-test="demand-status"]`).waitFor() } },
    { t: K('15:56'), name: 'Hinweis: Entwurf ist privat', act: async r => { await r.badge('Entwurf = privat: nur Anna sieht ihn, nicht einmal das PMO', 12000) } },
    { t: K('16:15'), name: 'Live: Knopf „Schließen“ statt „Abbrechen“', act: async r => { await r.hover(r.page.locator(`${E} [data-test="close-demand"]`)) } },
    { t: K('16:25'), name: 'Hinweis: Abbrechen vs. Schließen', act: async r => { await r.badge('„Abbrechen“ signalisiert Verlust – „Schließen“: alles ist gesichert', 28000) } },
    { t: K('17:00'), name: 'Karte: E-Mail ohne Empfänger', card: { end: K('17:20'), html: () => C.quote('Eine bekannte Analogie', 'Erst der Text, dann die Empfängerzeile', 'Der Entwurfsmodus: schleifen, ohne dass jemand zusieht') } },
    { t: K('17:29'), name: 'Karte: Selbstzensur vs. Entwurfsraum', card: { end: K('17:49'), html: t0 => C.cols('Psychologische Sicherheit', 'Unter Beobachtung zensiert man sich', [
      { head: 'Alles sofort sichtbar', tone: 'bad', at: K('17:31') - t0, items: [['Mitarbeiter zensieren sich selbst', K('17:35') - t0], ['Angst, etwas Falsches zu sagen', K('17:37') - t0]] },
      { head: 'Privater Entwurfsraum', tone: 'good', at: K('17:45') - t0, items: [['Die Einstiegshürde sinkt', K('17:47') - t0]] }]) } },
    { t: K('17:50'), name: 'Karte: Garderobe im Club', card: { end: K('17:58'), html: () => C.quote('Das Bild dazu', 'Die Garderobe im Club', 'Erst unbeobachtet umziehen – dann hinein') } },
    { t: K('18:02'), name: 'Live: Einreichen', act: async r => { await r.click(r.page.locator(`${E} [data-test="submit-demand"]`)); await r.page.locator(`${E} [data-test="demand-history"]`).waitFor() } },
    { t: K('18:07'), name: 'Hinweis: offizielle Nummer', act: async r => { await r.badge('Der Antrag erhält eine offizielle, fortlaufende Nummer', 5000) } },
    { t: K('18:10'), name: 'Live: Felder gesperrt', act: async r => { await r.hover(r.page.locator(`${E} [data-test="field-title"] input`)); await r.badge('Die Felder sind jetzt gesperrt', 3500) } },
    { t: K('18:18'), name: 'Live: Verlauf', act: async r => { await r.hover(r.page.locator(`${E} [data-test="demand-history"]`)); await r.badge('Verlauf: wer wann welche Änderung gemacht hat', 5000) } },
    { t: K('18:24'), name: 'Hinweis: Staffelstab Anna → PMO', act: async r => { await r.badge('Die Verantwortung wechselt von Anna zum PMO', 9000) } },
    { t: K('18:59'), name: 'Karte: E-Mail-Ping-Pong', card: { end: K('19:20'), html: t0 => C.list('Wenn Fragen entstehen', 'Das berüchtigte E-Mail-Ping-Pong', [['E-Mails', K('19:15') - t0], ['Chats', K('19:16') - t0], ['Flurfunk', K('19:17') - t0]], 'warm') } },
    { t: K('19:21'), name: 'Live: anderen Antrag öffnen („Außendienst-Tablets ersetzen“)', act: async (r, s) => { await r.page.keyboard.press('Escape'); await sleep(500); await r.click(r.page.locator(`[data-test="demand-${s.queryKey}"]`)); await r.page.locator(`${E} [data-test="query-notice"]`).waitFor() } },
    { t: K('19:36'), name: 'Live: Status „Rückfrage“', act: async r => { await r.hover(r.page.locator(`${E} [data-test="demand-status"]`)) } },
    { t: K('19:54'), name: 'Live: Rückfrage des PMO im Antrag', act: async r => { await r.hover(r.page.locator(`${E} [data-test="query-notice"]`)); await r.badge('Die Rückfrage steht direkt im Antrag', 8000) } },
    { t: K('20:07'), name: 'Karte: Wo steckt die Information?', card: { end: K('20:39'), html: t0 => C.cols('Das E-Mail-Chaos', 'Wo steckt die Information?', [
      { head: '20 Antworten im Thread', tone: 'bad', at: K('20:12') - t0, items: [['die wichtigste Person fehlte auf CC', K('20:15') - t0], ['jemand antwortete per Chat', K('20:19') - t0], ['das Dokument liegt auf irgendeinem Netzlaufwerk', K('20:23') - t0]] },
      { head: 'Nexus', tone: 'good', at: K('20:36') - t0, items: [['Frage und Antwort am selben Ort', K('20:37') - t0]] }]) } },
    { t: K('20:45'), name: 'Live: Antwortfeld anklicken', act: async r => { await r.click(r.page.locator(`${E} [data-test="field-answer"] textarea`)) } },
    { t: K('20:54'), name: 'Live: Antwort eintippen', act: async r => { await r.fill(r.page.locator(`${E} [data-test="field-answer"] textarea`), 'Betroffen sind 24 Geräte. Der Austausch muss bis Ende des Quartals abgeschlossen sein, weil dann die Wartungsverträge auslaufen.') } },
    { t: K('21:09'), name: 'Live: „Erneut einreichen“', act: async r => { await r.click(r.page.locator(`${E} [data-test="submit-demand"]`)); await r.page.locator(`${E} [data-test="comments"]`).waitFor() } },
    { t: K('21:15'), name: 'Live: Gespräch im Kommentarverlauf', act: async r => { await r.hover(r.page.locator(`${E} [data-test="comments"]`)) } },
    { t: K('21:31'), name: 'Karte: Closed Loop', card: { end: K('21:40'), html: () => C.quote('IT-Architektur', 'Closed Loop', 'Die Informationsschleife ist komplett geschlossen') } },
    { t: K('21:40'), name: 'Karte: Single Source of Truth', card: { end: K('21:51'), html: () => C.quote('Und damit', 'Single Source of Truth', 'Eine einzige zentrale Quelle der Wahrheit') } },
    { t: K('21:52'), name: 'Hinweis: Historie für den Sponsor', act: async r => { await r.badge('Auch Wochen später ist der Kontext sofort verständlich', 10000) } },
    { t: K('22:19'), name: 'Live: Status springt automatisch', act: async r => { await r.hover(r.page.locator(`${E} [data-test="demand-status"]`)); await r.badge('Der Status springt automatisch zurück auf „Eingereicht“', 5000) } },
    { t: K('22:25'), name: 'Live: Arbeitsvorrat des PMO (Triage-Liste)', act: async r => { await r.switchUser('paula'); await r.openNav('nav-group-triage', 'nav-queue-triage'); await r.click(r.page.locator('[data-test="nav-queue-triage"]')); await r.badge('Zurück im Arbeitsvorrat des PMO – bereit zur Bewertung', 6000) } },
    { t: K('22:35'), name: 'Karte: Jonas fasst zusammen', card: { end: K('23:20'), html: t0 => C.list('Jonas’ Fazit', 'Die Schulung in einem Satz', [['Der Antrag ist kurz.', K('22:42') - t0], ['Die Problembeschreibung zwingt mich zum Nachdenken.', K('22:44') - t0], ['Mein Entwurf bleibt geschützt.', K('22:47') - t0], ['Wenn mich das System warnt, nehme ich das ernst.', K('22:49') - t0], ['Und wenn das PMO etwas will, antworte ich direkt im Antrag.', K('22:51') - t0]]) } },
    { t: K('23:23'), name: 'Karte: die wichtigsten Erkenntnisse', card: { end: K('24:27'), html: t0 => C.list('Zusammenfassung', 'Die wichtigsten Erkenntnisse', [['Verhaltenslenkung statt immer mehr Regeln', K('23:28') - t0], ['5-Minuten-Regel: baut Widerstand ab', K('23:34') - t0], ['Sanfter Dublettenhinweis: Autonomie plus Nudges', K('23:40') - t0], ['Problembeschreibung: Filter gegen Solutioneering', K('23:48') - t0], ['Privater Entwurf ↔ verbindliche Einreichung: Sicherheit', K('23:57') - t0], ['Geschlossener Kreislauf: Ende des E-Mail-Chaos', K('24:03') - t0]]) } },
    { t: K('24:28'), name: 'Karte: Denkaufgabe', card: { end: K('25:15'), html: t0 => C.quote('Eine Denkaufgabe', 'Was passiert eigentlich, wenn wir einfach gar nichts tun?', 'Ist die ehrliche Antwort „nichts Schlimmes“ – dann kann man sich das Meeting sparen.') } },
    { t: K('25:15'), name: 'Karte: Abspann', card: { end: 0, html: () => C.hero('Nexus · Schritt 1', 'Der Antrag', 'Danke fürs Zuhören.') } },
  ],
}
