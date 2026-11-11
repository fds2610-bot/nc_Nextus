const { mkApi, month } = require('./api')
const SCORES = { N1: 5, N2: 3, N3: 5, N4: 1, N5: 3, N6: 1, Z1: 3, Z2: 3, R1: 3, R2: 1, R3: 1, R4: 1 }
/** Antrag per API bis zu einem Zielstatus bringen (unsichtbare Vorbereitung). */
async function build({ title = 'Digitale Reisekostenabrechnung', needs, upTo = 'submitted', orgUnitId = 4, requestor = 'anna', extra = {}, effort = 3 }) {
  const anna = mkApi(requestor), paula = mkApi('paula')
  const d = await anna.post('/demands', { title, demandType: 'efficiency', orgUnitId,
    problemStatement: 'Reisekosten werden mit Excel und Papierbelegen abgerechnet. Das kostet jeden Monat etwa vierzig Stunden Nacharbeit.',
    expectedBenefit: 'Weniger Nacharbeit und schnellere Erstattung für den Außendienst.', ...extra })
  if (needs) await anna.put(`/demands/${d.id}/needs`, { needs })
  const order = ['submitted', 'triaged', 'assessed', 'bc', 'candidate', 'prioritized', 'released']
  const at = order.indexOf(upTo)
  if (at >= 0) await anna.post(`/demands/${d.id}/submit`)
  if (at >= 1) await paula.post(`/demands/${d.id}/triage`, { code: 'accepted', projectClass: 'M' })
  if (at >= 2) await paula.post(`/demands/${d.id}/assessments`, { kind: 'review', scores: SCORES, effort, bucket: 'C' })
  if (at >= 3) await anna.put(`/demands/${d.id}/business-case`, { content: { costs: [{ label: 'Einführung und Lizenzen', kind: 'capex', amount: 45000 }], benefits: [{ type: 'cost_saving', title: 'Weniger Nacharbeit', amount: 30000, metric: 'Nacharbeitsstunden pro Monat', baseline: '40 Stunden pro Monat', ownerUserId: 'anna' }] } })
  if (at >= 4) await paula.post(`/demands/${d.id}/gate`, { step: 'candidate' })
  if (at >= 5) await paula.post(`/demands/${d.id}/gate`, { step: 'g2', outcome: 'prioritized' })
  return paula.get(`/demands/${d.id}`)
}
module.exports = { build, SCORES }
