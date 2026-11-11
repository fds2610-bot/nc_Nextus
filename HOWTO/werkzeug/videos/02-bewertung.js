const { L, S } = require('../lib/director')
const { sleep } = require('../lib/rec')
const { mkApi, month } = require('../lib/api')

const E = '[data-test="demand-editor"]'
const SCORES = { N1: 5, N2: 3, N3: 5, N4: 1, N5: 3, N6: 1, Z1: 3, Z2: 3, R1: 3, R2: 1, R3: 1, R4: 1 }

module.exports = {
  id: '02-bewertung',
  file: '02-Bewertung',
  number: 2,
  title: 'Bewertung: Triage, Kriterienkatalog und Business Case',
  konten: 'paula (PMO, Marlene)',
  lernziele: [
    'Anträge im Arbeitsvorrat finden und triagieren',
    'Die fünf Entscheidungscodes kennen',
    'Einen Antrag mit dem Kriterienkatalog bewerten (Wertdichte)',
    'Kosten und Nutzen im Business Case erfassen',
  ],
  probleme: [
    'Die Bewertung lässt sich nicht speichern, weil ein Kriterium vergessen wurde.',
    'Der Business Case wird mit einer Fehlermeldung abgelehnt: Nutzenposition ohne Ausgangswert.',
  ],

  async prepare() {
    const anna = mkApi('anna')
    const d = await anna.post('/demands', { title: 'Digitale Reisekostenabrechnung', demandType: 'efficiency', orgUnitId: 4,
      problemStatement: 'Reisekosten werden mit Excel und Papierbelegen abgerechnet. Das kostet jeden Monat etwa vierzig Stunden Nacharbeit.',
      expectedBenefit: 'Weniger Nacharbeit und schnellere Erstattung für den Außendienst.' })
    await anna.put(`/demands/${d.id}/needs`, { needs: [{ poolId: 3, period: month(1), personDays: 25 }, { poolId: 2, period: month(1), personDays: 12 }] })
    await anna.post(`/demands/${d.id}/submit`)
    return { key: d.key }
  },

  async intro(r) { await r.card('PMO-Schulung · Teil 2 von 6', 'Bewertung', 'Triage · Kriterienkatalog · Business Case') },

  script: (r, st) => [
    S('Einstieg'),
    L('M', 'Willkommen zurück, Jonas. Annas Antrag liegt jetzt bei uns. In Teil zwei geht es um die Bewertung. Sie hat drei Schritte: die Triage, die Bewertung mit dem Kriterienkatalog und den Business Case.', async () => { await r.endCard(7000) }, 'Titelkarte, danach die Antragsliste im Konto von Marlene'),

    S('Triage'),
    L('J', 'Wo finde ich eigentlich die Anträge, die auf uns warten?'),
    L('M', 'In der Navigation, unter der Überschrift „Triage“. Der Eintrag „Zu triagieren“ ist unser Arbeitsvorrat, und die Zahl zeigt, wie viele Anträge offen sind.', async () => {
      await r.openNav('nav-group-triage', 'nav-queue-triage')
      await r.click(r.page.locator('[data-test="nav-queue-triage"]'))
    }, 'Klick auf „Zu triagieren“'),
    L('M', 'Hier ist der Antrag zur digitalen Reisekostenabrechnung. Ich öffne ihn und klicke auf „Triage“. Die Frage lautet: Ist der Bedarf verständlich beschrieben, und lohnt sich der Aufwand für eine Bewertung?', async () => {
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await r.click(r.page.locator('[data-test="open-triage"]'))
    }, 'Antrag öffnen, „Triage“ anklicken, Dialog öffnet sich'),
    L('J', 'Welche Entscheidungen kann ich hier treffen?', async () => {
      await r.hover(r.page.locator('[data-test="triage-dialog"] [data-test="code-further_analysis"]'))
    }),
    L('M', 'Es gibt fünf Entscheidungscodes: angenommen, weitere Analyse, zurückgestellt, abgelehnt und Dublette. Eine Ablehnung braucht immer eine Begründung, eine Zurückstellung ein Wiedervorlagedatum. Wir nehmen den Antrag an und wählen die Projektklasse M, also mittel.', async () => {
      await r.click(r.page.locator('[data-test="triage-dialog"] [data-test="code-accepted"]'))
      await r.click(r.page.locator('[data-test="triage-dialog"] [data-test="field-class"] select'))
      await r.page.locator('[data-test="triage-dialog"] [data-test="field-class"] select').selectOption('M')
      await sleep(600)
      await r.click(r.page.locator('[data-test="triage-dialog"] [data-test="save-triage"]'))
      await r.page.locator(`${E} [data-test="demand-status"]`).filter({ hasText: 'Qualifizierung' }).waitFor()
    }, 'Code „angenommen“, Klasse M, Speichern; Status „In Qualifizierung“'),

    S('Bewertung mit dem Kriterienkatalog'),
    L('M', 'Jetzt bewerten wir. Der Antrag ist aus dem Arbeitsvorrat verschwunden, also öffne ich ihn über „Alle Anträge“ neu. Als PMO machen wir eine unabhängige Fremdbewertung. Der Kriterienkatalog hat Nutzen-, Dringlichkeits- und Risikokriterien. Jedes bekommt einen Wert auf der Skala null, eins, drei, fünf oder acht.', async () => {
      await r.page.keyboard.press('Escape')
      await sleep(500)
      await r.click(r.page.locator('[data-test="nav-all"]'))
      await r.click(r.page.locator(`[data-test="demand-${st.key}"]`))
      await r.click(r.page.locator('[data-test="assess-review"]'))
      await r.page.locator('[data-test="assessment-dialog"]').waitFor()
    }, 'Dialog „Review“ mit den Kriterien'),
    L('M', 'Ich fülle die Nutzenkriterien aus: Der Strategiebeitrag ist hoch, der finanzielle Nutzen mittel, der Nutzen für die Mitarbeitenden ebenfalls.', async () => {
      for (const c of ['N1', 'N2', 'N3', 'N4', 'N5', 'N6']) {
        await r.click(r.page.locator(`[data-test="assessment-dialog"] [data-test="criterion-${c}"] label:has(input[value="${SCORES[c]}"])`))
      }
    }, 'Kriterien N1 bis N6 bewerten'),

    S('Zwischenfrage: Speichern ist gesperrt'),
    L('J', 'Ich bin schon fertig und möchte speichern. Aber der Knopf „Bewertung speichern“ ist grau. Was habe ich falsch gemacht?', async () => {
      await r.hover(r.page.locator('[data-test="save-assessment"]'))
    }, 'Mauszeiger auf dem grauen Speichern-Knopf'),
    L('M', 'Du hast noch nicht alles bewertet. Es müssen alle Kriterien einen Wert haben, auch die zur Dringlichkeit und zum Risiko. Nexus soll keine halben Bewertungen speichern, denn sie lassen sich später nicht ändern. Eine neue Einschätzung wäre eine neue Bewertung. Scroll einmal nach unten.', async () => {
      for (const c of ['Z1', 'Z2', 'R1', 'R2', 'R3', 'R4']) {
        await r.click(r.page.locator(`[data-test="assessment-dialog"] [data-test="criterion-${c}"] label:has(input[value="${SCORES[c]}"])`))
      }
    }, 'Dringlichkeits- und Risikokriterien nachtragen'),
    L('M', 'Dazu kommt der Aufwand. Die Aufwandsklasse schlägt Nexus aus den erfassten Ressourcenbedarfen vor. Ich nehme Klasse drei. Unten siehst du sofort die Wertdichte, also den Nutzen im Verhältnis zum Aufwand. Sie bestimmt später die Reihenfolge in der Rangliste.', async () => {
      await r.click(r.page.locator('[data-test="field-effort"] select'))
      await r.page.locator('[data-test="field-effort"] select').selectOption('3')
      await r.hover(r.page.locator('[data-test="assess-preview"]'))
    }, 'Aufwandsklasse 3 wählen, Vorschau der Wertdichte'),
    L('M', 'Jetzt lässt sich die Bewertung speichern.', async () => {
      await r.click(r.page.locator('[data-test="save-assessment"]'))
      await r.page.locator('[data-test="rating-density"]').waitFor()
    }, 'Speichern, Wertdichte erscheint im Antrag'),

    S('Business Case'),
    L('M', 'Als Letztes der Business Case. Er ist kein Foliensatz, sondern eine strukturierte Aufstellung von Kosten und Nutzen. Wir öffnen ihn.', async () => {
      await r.click(r.page.locator('[data-test="open-business-case"]'))
      await r.page.locator('[data-test="business-case-dialog"]').waitFor()
    }, 'Business-Case-Dialog öffnen'),
    L('M', 'Erst die Kosten: Einführung und Lizenzen, fünfundvierzigtausend Euro.', async () => {
      const d = r.page.locator('[data-test="business-case-dialog"]')
      await r.click(d.locator('[data-test="add-cost"]'))
      await r.type(d.locator('[data-test="cost-0"] input').first(), 'Einführung und Lizenzen')
      await r.type(d.locator('[data-test="cost-0"] input.bc__amount'), '45000')
    }, 'Kostenposition anlegen'),
    L('M', 'Dann der Nutzen: weniger Nacharbeit, dreißigtausend Euro im Jahr. Als Messgröße tragen wir die Nacharbeitsstunden pro Monat ein.', async () => {
      const d = r.page.locator('[data-test="business-case-dialog"]')
      await r.click(d.locator('[data-test="add-benefit"]'))
      await r.type(d.locator('[data-test="benefit-title"]'), 'Weniger Nacharbeit')
      await r.type(d.locator('[data-test="benefit-amount"]'), '30000')
      await r.type(d.locator('[data-test="benefit-metric"]'), 'Nacharbeitsstunden pro Monat')
      await r.type(d.locator('[data-test="benefit-owner"]'), 'anna')
    }, 'Nutzenposition mit Betrag, Messgröße und Verantwortlicher'),

    S('Zwischenfrage: Fehlermeldung beim Speichern'),
    L('J', 'Ich speichere. Oh, es kommt eine rote Fehlermeldung. Was fehlt denn jetzt?', async () => {
      await r.click(r.page.locator('[data-test="save-business-case"]'))
      await sleep(1500)
    }, 'Klick auf Speichern, rote Fehlermeldung erscheint'),
    L('M', 'Der Ausgangswert. Jede Nutzenposition braucht eine Messgröße und einen Ausgangswert. Ohne ihn kann man später nicht prüfen, ob der Nutzen wirklich eingetreten ist. Vierzig Stunden pro Monat laufen heute an. Das tragen wir ein.', async () => {
      await r.type(r.page.locator('[data-test="benefit-baseline"]'), '40 Stunden pro Monat')
      await r.hover(r.page.locator('[data-test="bc-payback"]'))
    }, 'Ausgangswert eintragen, Amortisation wird angezeigt'),
    L('J', 'Und die Amortisation rechnet Nexus selbst aus. Fünfundvierzigtausend durch dreißigtausend, anderthalb Jahre.'),
    L('M', 'Richtig. Jetzt speichern wir.', async () => {
      await r.click(r.page.locator('[data-test="save-business-case"]'))
      await r.page.locator('[data-test="bc-summary-benefit"]').waitFor()
    }, 'Speichern, Zusammenfassung im Antrag'),

    S('Zusammenfassung'),
    L('J', 'Also: Erst die Triage, dann die Bewertung mit allen Kriterien, dann der Business Case mit Ausgangswerten. Dann ist der Antrag bereit für die Entscheidung.'),
    L('M', 'Genau. Und die Entscheidung ist das Thema von Teil drei.'),
  ],
}
