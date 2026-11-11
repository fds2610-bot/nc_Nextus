const { L, S } = require('../lib/director')
const { sleep } = require('../lib/rec')
const { mkApi } = require('../lib/api')

const E = '[data-test="demand-editor"]'
const TITLE = 'Digitale Reisekostenabrechnung'

module.exports = {
  id: '01-antrag',
  file: '01-Antrag-erfassen',
  number: 1,
  title: 'Antrag erfassen und einreichen',
  konten: 'anna (Antragstellerin, Vertrieb), paula (PMO)',
  lernziele: [
    'Einen Antrag in unter fünf Minuten erfassen',
    'Auf den Dublettenhinweis richtig reagieren',
    'Die Problembeschreibung als Pflichtfeld verstehen',
    'Entwurf und Einreichen unterscheiden',
    'Eine Rückfrage des PMO beantworten',
  ],
  probleme: [
    'Dublettenhinweis: Beim Tippen erscheint ein ähnlicher eigener Antrag (Frage von Jonas).',
    'Rückfrage des PMO: Der Antrag kommt mit einer offenen Frage zurück und muss ergänzt werden.',
  ],

  /** Unsichtbare Vorbereitung über die API */
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

  async intro(r) { await r.card('PMO-Schulung · Teil 1 von 6', 'Antrag erfassen und einreichen', 'Marlene Keller (Senior-PMO) schult Jonas Weber (Junior-PMO)<br>im Portfolio-Prozess mit Nexus') },

  script: (r, st) => [
    S('Einstieg'),
    L('M', 'Hallo Jonas, schön, dass du da bist. Heute beginnt deine Schulung zum Portfolio-Prozess in Nexus. Wir gehen den ganzen Weg: Antrag, Bewertung, Entscheidung, Portfolio, Freigabe und am Ende das Projekt im Planungstool. Das hier ist Teil eins, der Antrag.', async () => { await r.endCard(9000) }, 'Titelkarte der Schulung, danach die Nexus-Startseite im Konto von Marlene'),
    L('J', 'Hallo Marlene, danke dir. Ich bin gespannt. Eine Frage vorweg: Wo fängt ein Vorhaben bei uns überhaupt an?'),
    L('M', 'Immer mit einem Antrag. Jeder Bedarf, ob Idee, Gesetzesfrist oder Ersatzbeschaffung, kommt als Antrag ins System. Es gibt keinen anderen Eingang. Ich zeige dir das aus Sicht der Antragstellerin und wechsle dafür in das Konto von Anna aus dem Vertrieb.', async () => {
      await r.switchUser('anna')
    }, 'Wechsel in das Konto von Anna, Startseite von Nexus mit der Antragsliste'),

    S('Neuen Antrag anlegen'),
    L('M', 'Oben links steht der Knopf „Neuer Antrag“. Den drücken wir.', async () => {
      await r.click(r.page.locator('[data-test="new-demand"]'))
    }, 'Klick auf „Neuer Antrag“, Formular öffnet sich'),
    L('J', 'Das Formular ist ja gar nicht so lang.'),
    L('M', 'Das ist Absicht. Ein Antrag soll in unter fünf Minuten erfasst sein, denn Hürden am Eingang führen nur zu Schattenprojekten. Wir beginnen mit dem Titel.', async () => {
      await r.type(r.page.locator(`${E} [data-test="field-title"] input`), TITLE)
    }, 'Titel „Digitale Reisekostenabrechnung“ eintippen'),

    S('Zwischenfrage: Hinweis auf einen ähnlichen Antrag'),
    L('J', 'Moment, da ist ein Kasten aufgetaucht: „Es gibt bereits ähnliche Anträge“. Ist mein Antrag schon mal gestellt worden?', async () => {
      await r.hover(r.page.locator(`${E} [data-test="similar-demands"]`))
    }, 'Der gelbe Hinweiskasten mit dem ähnlichen Antrag erscheint'),
    L('M', 'Gut gesehen. Während man tippt, sucht Nexus in den offenen Anträgen nach ähnlichen Titeln und Beschreibungen. Anna hat vor zwei Wochen einen Antrag zum Scannen von Reisebelegen eingereicht. Anträge anderer Konten, die man nicht lesen darf, tauchen hier nicht auf.'),
    L('J', 'Und jetzt? Ist das ein Doppelantrag?'),
    L('M', 'Nicht unbedingt. Der alte Antrag betrifft nur das Scannen der Belege. Der neue umfasst den ganzen Prozess, von der Genehmigung bis zur Buchung. Anna entscheidet selbst: Ist es dasselbe, ergänzt sie den bestehenden Antrag. Ist es etwas Eigenes, reicht sie neu ein und verweist im Text auf den anderen. Wir machen das Zweite. Das PMO prüft später in der Triage noch einmal nach.'),

    S('Typ und Org-Einheit'),
    L('M', 'Jetzt der Typ. Wir nehmen „Effizienz“, denn es geht um schnellere Abläufe. Der Typ bestimmt später, welche Zusatzangaben nötig sind und in welchen Bucket das Vorhaben gehört.', async () => {
      await r.click(r.page.locator(`${E} [data-test="field-type"] select`))
      await r.page.locator(`${E} [data-test="field-type"] select`).selectOption({ label: 'Effizienz' })
    }, 'Typ „Effizienz“ wählen'),
    L('M', 'Dazu die Org-Einheit, hier der Vertrieb. Sie legt fest, wer außer Anna den Antrag sieht: Sponsor, PMO und das Board des Bereichs.', async () => {
      await r.click(r.page.locator(`${E} [data-test="field-org-unit"] select`))
      await r.page.locator(`${E} [data-test="field-org-unit"] select`).selectOption({ label: 'Vertrieb' })
    }, 'Org-Einheit „Vertrieb“ wählen'),

    S('Pflichtfeld Problembeschreibung'),
    L('J', 'Kann ich nach dem Titel gleich auf „Einreichen“ klicken?', async () => {
      await r.hover(r.page.locator(`${E} [data-test="submit-demand"]`))
    }, 'Mauszeiger auf dem grauen „Einreichen“-Knopf'),
    L('M', 'Probier es. Der Knopf ist grau. Ohne Problembeschreibung lässt Nexus das Einreichen nicht zu. Das ist das wichtigste Feld im ganzen Antrag: Was passiert, wenn wir nichts tun? Zwei, drei Sätze genügen.', async () => {
      await r.fill(r.page.locator(`${E} [data-test="field-problem"] textarea`), 'Reisekosten werden heute mit Excel und Papierbelegen abgerechnet. Das kostet jeden Monat etwa vierzig Stunden Nacharbeit und erzeugt Fehler in der Buchhaltung. Der bestehende Antrag zum Belegscan deckt nur einen Teil davon ab.')
    }, 'Problembeschreibung eintippen, „Einreichen“ wird blau'),
    L('M', 'Beim erwarteten Nutzen reicht eine qualitative Aussage. Zahlen kommen später im Business Case. Eine Frist tragen wir nur ein, wenn es einen echten Termin gibt, etwa eine gesetzliche Vorgabe. Hier lassen wir sie leer.', async () => {
      await r.fill(r.page.locator(`${E} [data-test="field-benefit"] textarea`), 'Weniger Nacharbeit und schnellere Erstattung für den Außendienst.')
    }, 'Erwarteten Nutzen eintippen'),

    S('Entwurf speichern und einreichen'),
    L('J', 'Soll Anna das jetzt direkt einreichen?'),
    L('M', 'Erst speichern wir als Entwurf. Ein Entwurf ist privat. Ihn sieht nur Anna, nicht einmal das PMO. Erst das Einreichen macht ihn verbindlich.', async () => {
      await r.click(r.page.locator(`${E} [data-test="save-demand"]`))
      await r.page.locator(`${E} [data-test="demand-status"]`).waitFor()
    }, 'Klick auf „Entwurf speichern“, Antragsnummer und Status „Entwurf“ erscheinen'),
    L('J', 'Mir fällt auf: Der Knopf unten links heißt jetzt „Schließen“ statt „Abbrechen“.', async () => {
      await r.hover(r.page.locator(`${E} [data-test="close-demand"]`))
    }),
    L('M', 'Richtig. „Abbrechen“ steht nur da, wo ungespeicherte Eingaben verloren gehen würden. Jetzt ist alles gesichert. Dann reichen wir ein.', async () => {
      await r.click(r.page.locator(`${E} [data-test="submit-demand"]`))
      await r.page.locator(`${E} [data-test="field-title"] input`).waitFor()
    }, 'Klick auf „Einreichen“, Felder werden gesperrt, Status „Eingereicht“'),
    L('M', 'Der Antrag hat jetzt seine Nummer, die Felder sind gesperrt, und im Verlauf steht, wer wann was gemacht hat. Ab jetzt ist das PMO am Zug.', async () => {
      await r.hover(r.page.locator(`${E} [data-test="demand-history"]`))
    }, 'Verlauf des Antrags am Ende des Dialogs'),

    S('Zwischenfrage: Rückfrage vom PMO'),
    L('J', 'Und wenn das PMO etwas nicht versteht? Die Anträge sind ja manchmal ziemlich knapp.'),
    L('M', 'Dann stellt es eine Rückfrage. Dafür habe ich einen Antrag vorbereitet, die Tablets im Außendienst. Ich schließe diesen Dialog und öffne den anderen.', async () => {
      await r.page.keyboard.press('Escape')
      await sleep(500)
      await r.click(r.page.locator(`[data-test="demand-${st.queryKey}"]`))
      await r.page.locator(`${E} [data-test="query-notice"]`).waitFor()
    }, 'Antrag „Außendienst-Tablets ersetzen“ mit Status „Rückfrage“ öffnen'),
    L('J', 'Oben steht ein Hinweis mit der Frage vom PMO, und darunter ist ein Antwortfeld.', async () => {
      await r.hover(r.page.locator(`${E} [data-test="query-notice"]`))
    }),
    L('M', 'Genau. Anna beantwortet die Frage und reicht erneut ein. Frage und Antwort stehen danach als Gespräch im Kommentarverlauf, nichts geht in Mails verloren.', async () => {
      await r.fill(r.page.locator(`${E} [data-test="field-answer"] textarea`), 'Betroffen sind 24 Geräte. Der Austausch muss bis Ende des Quartals abgeschlossen sein, weil dann die Wartung ausläuft.')
      await r.click(r.page.locator(`${E} [data-test="submit-demand"]`))
      await r.page.locator(`${E} [data-test="comments"]`).waitFor()
    }, 'Antwort eintragen, „Erneut einreichen“; Gespräch im Kommentarverlauf'),
    L('J', 'Und das PMO sieht den Antrag dann automatisch wieder im Arbeitsvorrat?'),
    L('M', 'Ja. Er steht wieder auf „Eingereicht“ und taucht in der Triage-Liste auf. Darum geht es im nächsten Teil.', async () => {
      await r.hover(r.page.locator(`${E} [data-test="demand-status"]`))
    }),

    S('Zusammenfassung'),
    L('J', 'Dann fasse ich zusammen: Der Antrag ist kurz, die Problembeschreibung ist Pflicht, der Entwurf bleibt privat, den Dublettenhinweis nehme ich ernst, und Rückfragen beantworte ich direkt im Antrag.'),
    L('M', 'Genau so. Im nächsten Teil schauen wir uns an, wie das PMO den Antrag prüft und bewertet. Bis gleich.'),
  ],
}
