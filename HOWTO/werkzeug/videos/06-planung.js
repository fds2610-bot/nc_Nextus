const { execFileSync } = require('child_process')
const { L, S } = require('../lib/director')
const { sleep } = require('../lib/rec')
const { mkApi, month } = require('../lib/api')
const { build } = require('../lib/flow')

const E = '[data-test="demand-editor"]'
const OCC = ['exec', '--user', 'www-data', 'nc-nexus-shared-sqlite-nc35-nextcloud-1', 'php', 'occ']
const occ = (...a) => execFileSync('docker', [...OCC, ...a], { stdio: 'pipe' })

module.exports = {
  id: '06-planung',
  file: '06-Projekt-im-Planungstool',
  number: 6,
  title: 'Projekt im Planungstool (Gantt) anlegen',
  konten: 'paula (PMO, Marlene); Administrator (nur Hinweis)',
  lernziele: [
    'Ein freigegebenes Projekt mit dem Planungstool verbinden',
    'Mit einem fehlenden Planungstool umgehen',
    'Das Gantt-Projekt öffnen und Fortschritt zurücklesen',
    'Wissen, wann man ein bestehendes Projekt verknüpft statt ein neues anzulegen',
  ],
  probleme: [
    'Der Abschnitt „Planung“ zeigt nur einen Hinweis, weil die Gantt-App nicht aktiv ist.',
    'Es gibt schon einen Plan im Planungstool: neu anlegen oder bestehenden verknüpfen.',
  ],

  async prepare() {
    const needs = [{ poolId: 3, period: month(1), personDays: 10 }]
    const hero = await build({ upTo: 'prioritized', needs: [{ poolId: 2, period: month(3), personDays: 15 }, { poolId: 3, period: month(3), personDays: 12 }] })
    const paula = mkApi('paula')
    await paula.post(`/demands/${hero.id}/gate`, { step: 'g3' })
    // vorhandener Plan: anderes Vorhaben, bereits mit Gantt verbunden
    const other = await build({ title: 'Außendienst-Tablets ersetzen', upTo: 'prioritized', needs, orgUnitId: 4 })
    await paula.post(`/demands/${other.id}/gate`, { step: 'g3' })
    await paula.raw('post', `/demands/${other.id}/planning`, {}, [200, 201])
    // Gantt-App für den Einstieg deaktivieren (Problemfall)
    occ('app:disable', 'nc_gantt')
    return { key: hero.key, id: hero.id }
  },

  async intro(r) { await r.card('PMO-Schulung · Teil 6 von 6', 'Projekt im Planungstool', 'Gantt anlegen · verknüpfen · Fortschritt lesen') },

  script: (r, st) => [
    S('Einstieg'),
    L('M', 'Willkommen zum letzten Teil, Jonas. Das Projekt ist freigegeben. Nexus steuert das Portfolio, aber die eigentliche Projektplanung, mit Vorgängen, Terminen und Abhängigkeiten, passiert im Planungstool Gantt. Heute verbinden wir beides.', async () => { await r.endCard(7000) }, 'Titelkarte, danach die Antragsliste'),

    S('Zwischenfrage: Planungstool nicht aktiv'),
    L('M', 'Ich öffne das freigegebene Projekt und scrolle zum Abschnitt „Planung“.', async () => {
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await r.page.locator(`${E} [data-test="planning"]`).scrollIntoViewIfNeeded()
      await sleep(600)
    }, 'Abschnitt „Planung (Gantt)“ im Antrag'),
    L('J', 'Hier steht nur ein Hinweis, und es gibt keinen Knopf. Da steht: Gantt muss installiert sein. Was nun?', async () => {
      await r.hover(r.page.locator(`${E} [data-test="planning"]`))
    }, 'Hinweis statt Knopf'),
    L('M', 'Das ist ein Umgebungsproblem. Die Gantt-App ist hier nicht aktiv. Das PMO kann Apps nicht selbst einschalten, das macht die Administration. Ich bitte sie darum. Danach laden wir die Seite neu.', async () => {
      await sleep(1500)
      occ('app:enable', 'nc_gantt')
      await sleep(500)
      await r.page.reload()
      await r.page.waitForLoadState('networkidle').catch(() => {})
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await r.page.locator(`${E} [data-test="planning"]`).scrollIntoViewIfNeeded()
    }, 'Administration aktiviert die App; Seite neu laden, Antrag erneut öffnen'),

    S('Gantt-Projekt anlegen'),
    L('J', 'Jetzt gibt es den Knopf „Gantt-Projekt anlegen“. Und daneben eine Auswahl für ein bestehendes Projekt.', async () => {
      await r.hover(r.page.locator(`${E} [data-test="planning-create"]`))
    }),
    L('M', 'Genau, zwei Wege. Gibt es für das Vorhaben noch keinen Plan, legen wir ein neues Gantt-Projekt an. Gibt es schon einen Plan, zum Beispiel aus einer Vorstudie, verknüpfen wir diesen. Dann gibt es keine Doppelpflege. Hier legen wir neu an.', async () => {
      await r.click(r.page.locator(`${E} [data-test="planning-create"]`))
      await r.page.locator(`${E} [data-test="planning-open"]`).waitFor()
    }, 'Gantt-Projekt anlegen, Link mit dem Projektschlüssel erscheint'),

    S('Zwischenfrage: Fortschritt 0 Prozent'),
    L('J', 'Der Fortschritt steht auf null Prozent und null von null Aufgaben. Ist da etwas schiefgegangen?', async () => {
      await r.hover(r.page.locator(`${E} [data-test="planning-summary"]`))
    }, 'Zusammenfassung mit Fortschritt 0 %'),
    L('M', 'Nein, das ist richtig. Der Plan ist noch leer, denn die Vorgänge legt der Projektleiter erst im Gantt an. Nexus liest nur Fortschritt, Aufgaben und Termine daraus zurück. Schauen wir uns den Plan an.', async () => {
      const href = await r.page.locator(`${E} [data-test="planning-open"]`).getAttribute('href')
      await r.hover(r.page.locator(`${E} [data-test="planning-open"]`))
      await r.page.goto(href)
      await r.page.waitForLoadState('networkidle').catch(() => {})
      await sleep(3500)
    }, 'Link öffnen: das neue Projekt in der Gantt-App'),
    L('M', 'Hier ist das neue Projekt, mit Projektschlüssel und Titel. Ab jetzt plant der Projektleiter hier weiter. Sobald es Aufgaben und Termine gibt, erscheinen sie in Nexus.', async () => {
      await sleep(2500)
    }),
    L('J', 'Und die Verbindung kann ich lösen, wenn ich das falsche Projekt erwischt habe?'),
    L('M', 'Ja, im Antrag gibt es den Knopf „Verknüpfung lösen“. Er löst nur die Verknüpfung, das Projekt in Gantt bleibt bestehen. Dann kannst du ein anderes verknüpfen. Zurück nach Nexus.', async () => {
      await r.page.goBack()
      await r.page.waitForLoadState('networkidle').catch(() => {})
      await sleep(1000)
    }, 'Zurück zu Nexus'),

    S('Abschluss der Schulung'),
    L('J', 'Dann ist der Weg einmal komplett: Antrag, Bewertung, Entscheidung, Portfolio, Freigabe und Projekt im Planungstool.'),
    L('M', 'Genau, Jonas. Du hast jetzt jeden Schritt einmal gesehen, auch die typischen Stolpersteine. Beim nächsten Antrag machst du es selbst, und ich schaue dir über die Schulter. Viel Erfolg!'),
  ],
}
