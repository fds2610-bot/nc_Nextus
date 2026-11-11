const { L, S } = require('../lib/director')
const { sleep } = require('../lib/rec')
const { mkApi, month } = require('../lib/api')
const { build } = require('../lib/flow')

const E = '[data-test="demand-editor"]'
const G = '[data-test="gate-dialog"]'

module.exports = {
  id: '03-entscheidung',
  file: '03-Entscheidung',
  number: 3,
  title: 'Entscheidung: Kandidat und Portfolioaufnahme (Gate 2)',
  konten: 'paula (PMO, Marlene)',
  lernziele: [
    'Den Unterschied zwischen Kandidat und Gate 2 kennen',
    'Die Prüfliste eines Gates lesen und Lücken schließen',
    'Ein Vorhaben priorisieren und den Projektschlüssel erkennen',
    'Beschlüsse im Entscheidungsregister nachvollziehen',
    'Eine Entscheidung außerplanmäßig erneut treffen',
  ],
  probleme: [
    'Die Prüfliste zeigt „Ressourcenbedarf“ in Rot, der Entscheidungsknopf ist gesperrt.',
    'Ein zurückgestellter Antrag soll früher als geplant entschieden werden, weil sich das Budget geändert hat.',
  ],

  async prepare() {
    const hero = await build({ upTo: 'bc' })
    const ben = mkApi('ben'), paula = mkApi('paula')
    const later = await ben.post('/demands', { title: 'Dokumentenmanagement für den Vertrieb', demandType: 'strategic', orgUnitId: 4, problemStatement: 'Angebote und Verträge liegen verstreut auf Laufwerken und in Postfächern.' })
    await ben.post(`/demands/${later.id}/submit`)
    const resub = new Date(Date.now() + 120 * 86400000).toISOString().slice(0, 10)
    await paula.post(`/demands/${later.id}/triage`, { code: 'deferred', resubmissionDate: resub })
    return { key: hero.key, laterKey: later.key, id: hero.id }
  },

  async intro(r) { await r.card('PMO-Schulung · Teil 3 von 6', 'Entscheidung', 'Kandidat · Gate 2 · Entscheidungsregister') },

  script: (r, st) => [
    S('Einstieg'),
    L('M', 'Willkommen zu Teil drei, Jonas. Der Antrag ist bewertet und hat einen Business Case. Jetzt wird entschieden, ob er ins Portfolio aufgenommen wird. Dazu gibt es zwei Schritte: zuerst den Kandidaten, dann das Gate zwei.', async () => { await r.endCard(7000) }, 'Titelkarte, danach die Antragsliste'),
    L('J', 'Was ist der Unterschied zwischen den beiden?'),
    L('M', 'Beim Kandidaten bestätigt das PMO, dass alle Unterlagen vollständig sind. Erst dann tritt das Vorhaben in der Rangliste gegen andere an. Beim Gate zwei entscheidet das Portfolio-Board, ob es aufgenommen wird. Dabei wird aus dem Antrag ein Projekt mit eigener Nummer.'),

    S('Kandidat'),
    L('M', 'Ich öffne den Antrag und klicke auf den Entscheidungsknopf. Nexus zeigt mir zuerst die Prüfliste.', async () => {
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await r.click(r.page.locator(`${E} [data-test="open-gate"]`))
      await r.page.locator(G).waitFor()
    }, 'Antrag öffnen, Gate-Dialog mit Prüfliste'),

    S('Zwischenfrage: Prüfliste unvollständig'),
    L('J', 'Da ist ein Punkt rot: „Ressourcenbedarf“. Und der Knopf zum Entscheiden ist grau.', async () => {
      await r.hover(r.page.locator(`${G} [data-test="check-resource_needs"]`))
    }, 'Prüfliste mit rotem Punkt „Ressourcenbedarf“'),
    L('M', 'Genau dafür ist die Prüfliste da. Es fehlt eine grobe Schätzung, wie viele Personentage aus welchen Pools gebraucht werden. Ohne sie kann man die Kapazität nicht planen. Ich schließe den Dialog und trage den Bedarf direkt im Antrag ein: fünfundzwanzig Personentage SAP-Basis im nächsten Monat, dazu zwölf Tage Softwareentwicklung.', async () => {
      await r.click(r.page.locator(`${G} [data-test="close-gate"], ${G} button:has-text("Schließen"), ${G} button:has-text("Abbrechen")`).first())
      await sleep(400)
      const need = r.page.locator(`${E} [data-test="add-need"]`)
      await r.click(need)
      await r.choose(r.page.locator(`${E} [data-test="need-0"] select`), 'SAP-Basis')
      await r.setValue(r.page.locator(`${E} [data-test="need-0"] input[type="month"]`), month(1))
      await r.setValue(r.page.locator(`${E} [data-test="need-0"] input.needs__days`), '25')
      await r.click(need)
      await r.choose(r.page.locator(`${E} [data-test="need-1"] select`), 'Softwareentwicklung')
      await r.setValue(r.page.locator(`${E} [data-test="need-1"] input[type="month"]`), month(1))
      await r.setValue(r.page.locator(`${E} [data-test="need-1"] input.needs__days`), '12')
    }, 'Zwei Bedarfszeilen eintragen'),
    L('M', 'Jetzt speichern wir die Änderung. Damit Nexus die Prüfliste neu berechnet, öffne ich den Antrag noch einmal und rufe die Entscheidung erneut auf.', async () => {
      await r.click(r.page.locator(`${E} [data-test="save-demand"]`))
      await sleep(1500)
      await r.page.keyboard.press('Escape')
      await sleep(600)
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await sleep(800)
      await r.click(r.page.locator(`${E} [data-test="open-gate"]`))
      await r.page.locator(`${G} [data-test="check-resource_needs"].gate__ok`).waitFor()
    }, 'Speichern, Gate-Dialog erneut öffnen, alle Punkte grün'),
    L('J', 'Jetzt sind alle Punkte grün.'),
    L('M', 'Dann bestätigen wir den Kandidaten.', async () => {
      await r.click(r.page.locator(`${G} [data-test="save-gate"]`))
      await r.page.locator(`${E} [data-test="demand-status"]`).filter({ hasText: 'Kandidat' }).waitFor()
    }, 'Bestätigen; Status „Kandidat“'),

    S('Gate 2: Portfolioaufnahme'),
    L('M', 'Jetzt das Gate zwei. Zur Wahl stehen: priorisieren, zurückstellen oder ablehnen. Beim Priorisieren wird der Business Case als Version eingefroren, und aus dem Antrag wird ein Projekt.', async () => {
      await r.click(r.page.locator(`${E} [data-test="open-gate"]`))
      await r.page.locator(G).waitFor()
      await r.hover(r.page.locator(`${G} [data-test="outcome-deferred"]`))
    }, 'Gate-Dialog mit den Beschlussarten'),
    L('J', 'Darf ich das als Junior eigentlich allein entscheiden?'),
    L('M', 'Entscheiden dürfen das Board oder das PMO des Bereichs, aber nie die Antragstellerin selbst. Diese Funktionstrennung prüft Nexus automatisch. Wir priorisieren.', async () => {
      await r.click(r.page.locator(`${G} [data-test="outcome-prioritized"] input`))
      await r.click(r.page.locator(`${G} [data-test="save-gate"]`))
      await r.page.locator(`${E} [data-test="project-key"]`).waitFor()
    }, 'Priorisieren; Projektschlüssel PRJ-… erscheint'),
    L('M', 'Hier steht der neue Projektschlüssel. Ab jetzt führen wir das Vorhaben unter dieser Nummer.', async () => {
      await r.hover(r.page.locator(`${E} [data-test="project-key"]`))
    }),

    S('Entscheidungsregister'),
    L('M', 'Jeder Beschluss wird im Entscheidungsregister festgehalten, mit Nummer, Mandat und Begründung. Das Register kann man nicht nachträglich ändern.', async () => {
      await r.page.keyboard.press('Escape')
      await sleep(500)
      await r.click(r.page.locator('[data-test="nav-decisions"]'))
      await r.page.locator('[data-test="decision-register"]').waitFor()
    }, 'Entscheidungsregister mit den neuen Beschlüssen'),
    L('J', 'Ich sehe drei neue Einträge zu unserem Antrag: Triage, Kandidat und Gate zwei.', async () => {
      await r.hover(r.page.locator('[data-test^="decision-BES-"]').first())
    }),

    S('Zwischenfrage: Entscheidung früher als geplant'),
    L('J', 'Ich habe eine Frage zu einem anderen Fall. Das Dokumentenmanagement für den Vertrieb wurde zurückgestellt, mit Wiedervorlage in vier Monaten. Jetzt hat die Bereichsleitung aber doch Budget freigegeben. Müssen wir wirklich warten?'),
    L('M', 'Nein. Das PMO kann jeden eingereichten Antrag auch außerplanmäßig neu entscheiden. Dann ist aber eine Begründung Pflicht, und der neue Beschluss ersetzt den alten im Register. Schauen wir es uns an.', async () => {
      await r.click(r.page.locator('[data-test="nav-all"]'))
      await r.click(r.page.locator(`[data-test="demand-${st.laterKey}"]`))
      await r.page.locator(`${E} [data-test="retriage"]`).waitFor()
    }, 'Zurückgestellten Antrag öffnen'),
    L('M', 'Der normale Triage-Knopf ist grau, denn der reguläre Schritt ist erledigt. Aber „Erneut entscheiden“ steht uns offen.', async () => {
      await r.click(r.page.locator(`${E} [data-test="retriage"]`))
      await r.page.locator('[data-test="triage-dialog"] [data-test="override-notice"]').waitFor()
    }, 'Dialog „Erneut entscheiden“ mit Hinweis'),
    L('M', 'Wir wählen „angenommen“ und tragen die Begründung ein: Das Budget wurde von der Bereichsleitung vorzeitig freigegeben.', async () => {
      await r.click(r.page.locator('[data-test="triage-dialog"] [data-test="code-accepted"]'))
      await r.fill(r.page.locator('[data-test="triage-dialog"] [data-test="field-reason"] textarea'), 'Das Budget wurde von der Bereichsleitung vorzeitig freigegeben.')
      await r.click(r.page.locator('[data-test="triage-dialog"] [data-test="save-triage"]'))
      await r.page.locator(`${E} [data-test="demand-status"]`).filter({ hasText: 'Qualifizierung' }).waitFor()
    }, 'Annehmen mit Begründung; Status „In Qualifizierung“'),
    L('J', 'Und im Register steht das dann als außerplanmäßiger Beschluss?'),
    L('M', 'Ja, mit Kennzeichnung. So bleibt nachvollziehbar, wer wann abweichend entschieden hat.', async () => {
      await r.page.keyboard.press('Escape')
      await sleep(400)
      await r.click(r.page.locator('[data-test="nav-decisions"]'))
      await r.hover(r.page.locator('[data-test="out-of-sequence"]').first())
    }, 'Register: Kennzeichnung „außerplanmäßig“'),

    S('Zusammenfassung'),
    L('J', 'Ich fasse zusammen: Erst die Prüfliste vollständig machen, dann Kandidat, dann Gate zwei. Beschlüsse stehen im Register, und abweichende Entscheidungen brauchen eine Begründung.'),
    L('M', 'Genau. Im nächsten Teil schauen wir auf das ganze Portfolio.'),
  ],
}
