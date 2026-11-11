const { L, S } = require('../lib/director')
const { sleep } = require('../lib/rec')
const { month } = require('../lib/api')
const { build } = require('../lib/flow')

const E = '[data-test="demand-editor"]'
const G = '[data-test="gate-dialog"]'

module.exports = {
  id: '05-freigabe',
  file: '05-Freigabe',
  number: 5,
  title: 'Freigabe: Gate 3 mit Kapazitätszusage',
  konten: 'paula (PMO, Marlene)',
  lernziele: [
    'Den Kapazitätsschnitt (Startplan) lesen',
    'Gate 3 und die harte Kapazitätszusage verstehen',
    'Mit einer abgelehnten Freigabe umgehen und den Bedarf verschieben',
    'Zugesagte Kapazität im Antrag erkennen',
  ],
  probleme: [
    'Die Freigabe ist gesperrt: Zu wenig Kapazität im Pool SAP-Basis im Dezember (rote Prüfliste, grauer Knopf).',
    'Der Kapazitätsschnitt zeigt für das Vorhaben einen späteren Start als gewünscht.',
  ],

  async prepare() {
    const hero = await build({ upTo: 'prioritized', needs: [{ poolId: 2, period: month(1), personDays: 20 }, { poolId: 3, period: month(1), personDays: 12 }] })
    return { key: hero.key, id: hero.id }
  },

  async intro(r) { await r.card('PMO-Schulung · Teil 5 von 6', 'Freigabe', 'Kapazitätsschnitt · Gate 3 · harte Zusage') },

  script: (r, st) => [
    S('Einstieg'),
    L('M', 'Willkommen zu Teil fünf, Jonas. Das Vorhaben ist jetzt ein Projekt und im Portfolio. Aber ein Projekt darf erst starten, wenn auch die Leute da sind. Das prüft das Gate drei, die Freigabe. Mit der Freigabe sagen wir die Kapazität verbindlich zu.', async () => { await r.endCard(7000) }, 'Titelkarte, danach die Antragsliste'),
    L('J', 'Was heißt „verbindlich“?'),
    L('M', 'Bis jetzt war der Bedarf eine Schätzung. Mit der Freigabe wird er für die Engpass-Pools zur harten Zusage. Nexus achtet darauf, dass die Summe der Zusagen nie größer ist als die Projektkapazität des Pools. Probieren wir es.'),

    S('Freigabe versuchen'),
    L('M', 'Ich öffne das Projekt und die Entscheidung. Die Prüfliste hat diesmal einen zusätzlichen Punkt: die Kapazität.', async () => {
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await r.click(r.page.locator(`${E} [data-test="open-gate"]`))
      await r.page.locator(`${G} [data-test="check-capacity"]`).waitFor()
    }, 'Gate-Dialog, Punkt „Kapazität“ ist rot'),

    S('Zwischenfrage: Freigabe gesperrt'),
    L('J', 'Die Kapazität ist rot, und der Knopf „Freigeben“ ist grau. Kann ich ihn irgendwie trotzdem anklicken?', async () => {
      await r.hover(r.page.locator(`${G} [data-test="save-gate"]`))
    }, 'Mauszeiger auf dem grauen Freigeben-Knopf'),
    L('M', 'Nein, und das ist Absicht. Solange die Kapazität nicht reicht, lässt Nexus die Freigabe nicht zu. Hinter dem roten Punkt steht der Grund: Im Pool SAP-Basis brauchen wir im Dezember zwanzig Personentage, aber es sind nur knapp fünfzehn frei. Selbst über die Schnittstelle würde der Server die Freigabe ablehnen. Wir haben zwei Möglichkeiten: weniger Bedarf anmelden oder den Start verschieben. Dazu schauen wir zuerst den Kapazitätsschnitt an.', async () => {
      await r.hover(r.page.locator(`${G} [data-test="check-capacity"]`))
      await sleep(2500)
      await r.click(r.page.locator(`${G} button:has-text("Abbrechen"), ${G} button:has-text("Schließen")`).first())
      await sleep(400)
      await r.page.keyboard.press('Escape')
      await sleep(500)
      await r.click(r.page.locator('[data-test="nav-ranking"]'))
    }, 'Grund in der Prüfliste lesen, dann zur Priorisierung wechseln'),

    S('Kapazitätsschnitt'),
    L('M', 'Der Kapazitätsschnitt rechnet für alle Vorhaben durch, wer mit der vorhandenen Kapazität wann starten kann. Die Reihenfolge der Rangliste entscheidet, wer zuerst bedient wird.', async () => {
      await r.click(r.page.locator('[data-test="run-cut"]'))
      await sleep(1500)
    }, 'Kapazitätsschnitt berechnen, Spalte „Startplan“ füllt sich'),
    L('J', 'In der Spalte „Startplan“ steht bei unserem Projekt ein Hinweis auf den Pool SAP. Es kann so nicht wie gewünscht starten.', async () => {
      await r.hover(r.page.locator(`[data-test="plan-${st.key}"]`))
    }),
    L('M', 'Richtig. Der Startplan zeigt uns, woran es hängt: am Pool SAP-Basis im Dezember. Wir verschieben den Bedarf deshalb auf den Januar. Dort ist der Pool frei.', async () => {
      await r.click(r.page.locator('[data-test="nav-all"]'))
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await sleep(600)
    }, 'Antrag erneut öffnen'),
    L('M', 'Im Antrag ändere ich beide Bedarfszeilen auf den Januar und speichere.', async () => {
      for (const i of [0, 1]) {
        const m = r.page.locator(`${E} [data-test="need-${i}"] input[type="month"]`)
        await r.setValue(m, month(2))
      }
      await r.click(r.page.locator(`${E} [data-test="save-demand"]`))
      await sleep(1200)
    }, 'Monat der beiden Bedarfszeilen auf Januar ändern, speichern'),

    S('Freigabe erteilen'),
    L('M', 'Damit Nexus die Prüfliste neu berechnet, öffne ich den Antrag noch einmal und rufe die Entscheidung erneut auf.', async () => {
      await r.page.keyboard.press('Escape')
      await sleep(600)
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await sleep(800)
      await r.click(r.page.locator(`${E} [data-test="open-gate"]`))
      await r.page.locator(`${G} [data-test="check-capacity"].gate__ok`).waitFor()
    }, 'Gate-Dialog: Kapazität jetzt grün'),
    L('J', 'Jetzt ist die Kapazität grün.'),
    L('M', 'Dann geben wir frei.', async () => {
      await r.click(r.page.locator(`${G} [data-test="save-gate"]`))
      await r.page.locator(`${E} [data-test="demand-status"]`).filter({ hasText: 'Freigegeben' }).waitFor()
    }, 'Freigeben; Status „Freigegeben“'),
    L('M', 'Unten im Antrag steht jetzt die zugesagte Kapazität je Pool und Monat. Das sind die harten Zusagen. Die Pool-Verantwortlichen können sich darauf verlassen.', async () => {
      await r.hover(r.page.locator(`${E} [data-test="allocations"]`))
    }, 'Abschnitt mit den zugesagten Personentagen'),

    S('Zusammenfassung'),
    L('J', 'Also: Die Freigabe prüft die Kapazität. Wenn sie fehlt, lehnt Nexus ab, ich sehe im Kapazitätsschnitt, wann es passt, verschiebe den Bedarf und gebe dann frei.'),
    L('M', 'Genau. Im letzten Teil übergeben wir das freigegebene Projekt an das Planungstool.'),
  ],
}
