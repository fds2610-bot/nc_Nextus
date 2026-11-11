const { L, S } = require('../lib/director')
const { sleep } = require('../lib/rec')
const { month } = require('../lib/api')
const { build } = require('../lib/flow')

module.exports = {
  id: '04-portfolio',
  file: '04-Portfolio',
  number: 4,
  title: 'Portfolio: Übersicht, Rangliste und Kapazität',
  konten: 'paula (PMO, Marlene)',
  lernziele: [
    'Die Portfolioübersicht nach Org-Einheit und Phase lesen',
    'Die Rangliste nach Buckets und Wertdichte verstehen',
    'Die Reihenfolge mit Begründung korrigieren',
    'Die Kapazitäts-Heatmap lesen und Engpässe erkennen',
  ],
  probleme: [
    'Das Vorhaben steht in der Rangliste nicht oben, obwohl der Vorstand es zuerst will: Korrektur mit Begründung.',
    'In der Heatmap ist ein Pool überbucht (rote Zelle): Ursache über die Zelle finden.',
  ],

  async prepare() {
    const hero = await build({ upTo: 'candidate', effort: 5, needs: [{ poolId: 3, period: month(1), personDays: 12 }, { poolId: 2, period: month(2), personDays: 18 }] })
    return { key: hero.key }
  },

  async intro(r) { await r.card('PMO-Schulung · Teil 4 von 6', 'Portfolio', 'Übersicht · Rangliste · Kapazität') },

  script: (r, st) => [
    S('Einstieg'),
    L('M', 'Hallo Jonas, in Teil vier schauen wir auf das ganze Portfolio. Ein Vorhaben ist nie allein. Es konkurriert mit anderen um dieselben Leute und dasselbe Geld. Dafür hat Nexus drei Ansichten: die Portfolioübersicht, die Priorisierung und die Kapazität.', async () => { await r.endCard(7000) }, 'Titelkarte, danach die Antragsliste'),

    S('Portfolioübersicht'),
    L('M', 'Zuerst die Portfolioübersicht. Sie zeigt alle Anträge und Projekte, die wir sehen dürfen, nach Org-Einheit und Phase.', async () => {
      await r.click(r.page.locator('[data-test="nav-portfolio"]'))
      await r.page.locator('[data-test="portfolio-view"]').waitFor()
    }, 'Portfolioübersicht öffnen'),
    L('J', 'Die Zahlen unter der Phase „Antrag“, sind das die Personentage?', async () => {
      await r.hover(r.page.locator('[data-test="portfolio-row-Vertrieb"]'))
    }),
    L('M', 'Genau. Die große Zahl ist die Anzahl der Vorhaben, die kleine darunter der geschätzte Bedarf in Personentagen. Übergeordnete Einheiten wie die IT rollen ihre Untereinheiten mit auf. Ein Klick auf eine Zahl öffnet die Liste dahinter.', async () => {
      await r.click(r.page.locator('[data-test="portfolio-cell-Vertrieb-demand"]'))
      await sleep(800)
    }, 'Klick auf die Zahl bei Vertrieb, gefilterte Antragsliste'),

    S('Rangliste'),
    L('M', 'Nun die Priorisierung. Hier konkurrieren die Kandidaten innerhalb ihres Buckets, also ihrer Kategorie, um Kapazität. Die Reihenfolge ergibt sich aus der Wertdichte: Nutzen im Verhältnis zum Aufwand.', async () => {
      await r.click(r.page.locator('[data-test="nav-ranking"]'))
      await r.page.locator('[data-test="ranking-view"]').waitFor()
    }, 'Rangliste mit den Buckets'),

    S('Zwischenfrage: Reihenfolge korrigieren'),
    L('J', 'Die digitale Reisekostenabrechnung steht nur auf Rang zwei, hinter dem Self-Service-Portal. Dabei will der Vorstand sie als Erstes haben. Kann ich die Reihenfolge einfach ändern?', async () => {
      await r.hover(r.page.locator(`[data-test="rank-${st.key}"]`))
    }, 'Mauszeiger auf der Zeile des Vorhabens'),
    L('M', 'Die Wertdichte selbst nicht, die ist errechnet. Aber das Portfolio-Board darf die Reihenfolge korrigieren, und zwar mit Begründung. Die Korrektur bleibt in der Liste sichtbar, damit jeder sieht, dass hier von der Rechnung abgewichen wurde. Wir setzen Rang eins.', async () => {
      await r.click(r.page.locator(`[data-test="override-${st.key}"]`))
      await r.page.locator('[data-test="override-dialog"]').waitFor()
      await r.type(r.page.locator('[data-test="field-override-rank"] input'), '1')
      await r.fill(r.page.locator('[data-test="field-override-reason"] textarea'), 'Vorstandsbeschluss: Reisekostenabrechnung wird vorgezogen.')
      await r.click(r.page.locator('[data-test="save-override"]'))
      await r.page.locator(`[data-test="rank-${st.key}"] [data-test="rank-overridden"]`).waitFor()
    }, 'Korrektur-Dialog: Rang 1 mit Begründung'),
    L('M', 'Jetzt steht das Vorhaben oben, mit der Markierung für die Korrektur und der Begründung.', async () => {
      await r.hover(r.page.locator(`[data-test="rank-${st.key}"] [data-test="rank-overridden"]`))
    }),

    S('Kapazität'),
    L('M', 'Die Rangliste sagt, was wichtig ist. Ob wir es auch leisten können, zeigt die Kapazität. Jede Zeile ist ein Ressourcenpool, jede Spalte ein Monat. Die Auslastung ist der Bedarf der offenen Anträge geteilt durch die Projektkapazität des Pools.', async () => {
      await r.click(r.page.locator('[data-test="nav-capacity"]'))
      await r.page.locator('[data-test="heatmap"]').waitFor()
    }, 'Kapazitäts-Heatmap'),

    S('Zwischenfrage: Rote Zelle'),
    L('J', 'Bei der Softwareentwicklung ist im Dezember eine Zelle rot, über hundert Prozent. Was bedeutet das, und woher kommt das?', async () => {
      await r.hover(r.page.locator('[data-test="cell-DEV-' + month(1) + '"]'))
    }, 'Mauszeiger auf der roten Zelle'),
    L('M', 'Dass mehr Bedarf angemeldet ist, als der Pool in dem Monat leisten kann. Klick auf die Zelle, dann siehst du die Anträge dahinter.', async () => {
      await r.click(r.page.locator('[data-test="cell-DEV-' + month(1) + '"]'))
      await r.page.locator('[data-test="cell-detail"]').waitFor()
    }, 'Klick auf die Zelle, Detailliste der Anträge'),
    L('J', 'Mehrere Anträge ziehen gleichzeitig an denselben Leuten.'),
    L('M', 'So ist es. Solche Engpässe lösen wir nicht mit Bauchgefühl. Zuerst gibt es den Kapazitätsschnitt, und dann entscheidet das Gate drei. Das ist Thema von Teil fünf.'),

    S('Zusammenfassung'),
    L('J', 'Übersicht für das Gesamtbild, Rangliste für die Reihenfolge, Kapazität für die Machbarkeit. Und Korrekturen gehen nur mit Begründung.'),
    L('M', 'Genau. Bis zum nächsten Teil.'),
  ],
}
