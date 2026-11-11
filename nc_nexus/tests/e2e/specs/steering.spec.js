// @ts-check
const { test, expect } = require('@playwright/test')
const { API, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_OTHER_USER, E2E_PASSWORD, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')
const { navTo } = require('../helpers/nav')

const month = (offset = 0) => {
	const d = new Date()
	d.setDate(1)
	d.setMonth(d.getMonth() + offset)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
const inDays = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10)
const tag = () => 'S' + Math.random().toString(36).slice(2, 6).toUpperCase()

/**
 * Freigegebenes Projekt (Klasse M, Direktbeschlüsse) mit Bedarf im angegebenen Pool.
 *
 * @param {import('@playwright/test').APIRequestContext} own Antragsteller
 * @param {import('@playwright/test').APIRequestContext} admin PMO
 * @param {{title: string, unitId: number, poolId: number, days: number}} spec Angaben
 */
async function released(own, admin, { title, unitId, poolId, days }) {
	const d = await createDemand(own, { title, demandType: 'efficiency', problemStatement: 'Steuerung testen.', orgUnitId: unitId })
	await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId, period: month(), personDays: days }, { poolId, period: month(1), personDays: days }] }, 200)
	await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
	await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted', projectClass: 'M' }, 200)
	const model = await call(admin, 'get', '/scoring/model')
	await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores: Object.fromEntries(model.set.criteria.map(c => [c.code, 3])), effort: 3 }, 201)
	await call(own, 'put', `/demands/${d.id}/business-case`, {
		content: {
			costs: [{ label: 'Umsetzung', kind: 'capex', amount: 100000 }],
			benefits: [{ type: 'cost_saving', title: 'Einsparung', amount: 40000, metric: 'Kosten p. a.', baseline: '40 000 €', ownerUserId: E2E_USER }],
		},
	}, 200)
	for (const step of [{ step: 'candidate' }, { step: 'g2', outcome: 'approved' }, { step: 'g3', outcome: 'approved' }]) {
		await call(admin, 'post', `/demands/${d.id}/gate`, step, 200)
	}
	return call(admin, 'get', `/demands/${d.id}`)
}

test.describe('Steuerung im Betrieb (Stufe 4)', () => {
	test('Cockpit: Baseline bei G3, Meilenstein, Statusbericht mit Health-Widerspruch, Finanzen, Ist-Import, Auto-CR', async () => {
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const pm = await apiAs(E2E_OTHER_USER)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Steuerung ${t}` })
			const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Pool ${t}`, isBottleneck: false, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0, pdRate: 800 })
			const d = await released(own, admin, { title: `E2E-Cockpit ${t}`, unitId: unit.id, poolId: pool.id, days: 5 })

			// G3 legt Projektstammdaten, Baseline 1 und den Nutzenreview-Termin an
			let cockpit = await call(admin, 'get', `/projects/${d.id}`)
			expect(cockpit.baselines.map(b => b.version)).toEqual([1])
			expect(cockpit.baselines[0].budgetFrame).toBe(100000)
			expect(cockpit.baselines[0].personDays).toBe(10)
			expect(cockpit.project.plannedStart).toBe(`${month()}-01`)
			expect(cockpit.project.benefitReviewDue).toBeTruthy()
			expect(cockpit.can.manage).toBe(true)

			// Projektleitung benennen; danach darf sie das Projekt pflegen, der Antragsteller nicht
			expect((await own.put(`${API}/projects/${d.id}`, { data: { managerId: E2E_OTHER_USER } })).status()).toBe(403)
			await call(admin, 'put', `/projects/${d.id}`, { managerId: E2E_OTHER_USER, committed: 30000 }, 200)

			// externer Meilenstein überfällig → Health rot (Termin)
			cockpit = await call(pm, 'post', `/projects/${d.id}/milestones`, { name: 'Kundenabnahme', dueDate: inDays(-3), external: true })
			expect(cockpit.health.level).toBe('red')
			expect(cockpit.health.findings.map(f => f.rule)).toContain('milestone_overdue')

			// Bericht „Termin grün“ widerspricht dem Indikator → Begründung Pflicht
			const template = await call(pm, 'get', `/projects/${d.id}/reports/template`)
			const report = { ...template, ragScope: 'green', ragTime: 'green', ragCost: 'green', ragRisk: 'green', achievements: 'Konzept fertig', forecastCost: 100000 }
			const refused = await pm.post(`${API}/projects/${d.id}/reports`, { data: report })
			expect(refused.status()).toBe(400)
			expect((await refused.json()).ocs.data.message).toMatch(/time/)
			const red = await pm.post(`${API}/projects/${d.id}/reports`, { data: { ...report, ragTime: 'red' } })
			expect(red.status()).toBe(400) // rot ohne Kommentar
			cockpit = await call(pm, 'post', `/projects/${d.id}/reports`, { ...report, contradictionReason: 'Kunde hat die Abnahme mündlich bestätigt' })
			expect(cockpit.reports[0].contradictionReason).toBe('Kunde hat die Abnahme mündlich bestätigt')
			expect(cockpit.contradictions).toEqual(['time'])

			// Ist-Werte: Kosten von Hand, Aufwand per CSV (Personentage × Verrechnungssatz 800 €)
			await call(pm, 'post', `/projects/${d.id}/actuals`, { period: month(), kind: 'cost', amount: 12000 })
			const csv = `Projekt;Monat;Art;Pool;Wert\n${d.projectKey};${month()};effort;E2E${t};5\nPRJ-0000-9999;${month()};cost;;1\n`
			const imported = await call(admin, 'post', '/actuals/import', { csv }, 200)
			expect(imported.imported).toBe(1)
			expect(imported.errors.length).toBe(1)
			expect((await pm.post(`${API}/actuals/import`, { data: { csv } })).status()).toBe(403)
			cockpit = await call(admin, 'get', `/projects/${d.id}`)
			expect(cockpit.finance.actualExternal).toBe(12000)
			expect(cockpit.finance.actualInternal).toBe(4000)
			expect(cockpit.finance.committed).toBe(30000)
			expect(cockpit.effort.actual).toBe(5)

			// Forecast 130 % des Rahmens → automatischer Change Request „Nachfinanzierung“
			await call(pm, 'post', `/projects/${d.id}/reports`, { ...report, ragCost: 'red', ragComment: 'Lizenzkosten gestiegen', forecastCost: 130000, contradictionReason: 'siehe oben' })
			const changes = await call(pm, 'get', `/projects/${d.id}/changes`)
			expect(changes.filter(c => c.origin === 'system' && c.changeType === 'cost').map(c => c.deltaBudget)).toEqual([30000])
			cockpit = await call(admin, 'get', `/projects/${d.id}`)
			expect(cockpit.health.findings.map(f => f.rule)).toContain('forecast_over_budget')

			// Projektliste (Portfolio-Sicht) zeigt Ampel, Health und Abweichung
			const list = await call(admin, 'get', '/projects')
			const row = list.find(p => p.id === d.id)
			expect(row.health).toBe('red')
			expect(row.deviationPct).toBe(30)
		} finally {
			await own.dispose()
			await pm.dispose()
			await admin.dispose()
		}
	})

	test('Risiken (projektübergreifend), Issues, Maßnahmen und Change Requests mit Schwellenlogik', async () => {
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const pm = await apiAs(E2E_OTHER_USER)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Risiko ${t}` })
			const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Pool ${t}`, isBottleneck: true, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
			await call(admin, 'put', `/pools/${pool.id}/capacity/${month()}`, { fte: 2, workingDays: 20 }, 200)
			await call(admin, 'put', `/pools/${pool.id}/capacity/${month(1)}`, { fte: 2, workingDays: 20 }, 200)
			const a = await released(own, admin, { title: `E2E-Risiko A ${t}`, unitId: unit.id, poolId: pool.id, days: 4 })
			const b = await released(own, admin, { title: `E2E-Risiko B ${t}`, unitId: unit.id, poolId: pool.id, days: 4 })
			await call(admin, 'put', `/projects/${a.id}`, { managerId: E2E_OTHER_USER }, 200)

			// Risiko für zwei Projekte (PMO), Score 20 ohne Gegenmaßnahme → Eskalationshinweis, Health rot
			const risk = await call(admin, 'post', `/projects/${a.id}/risks`, { title: `Lieferant fällt aus ${t}`, category: 'supplier', probability: 4, impact: 5, ownerId: E2E_OTHER_USER, demandIds: [a.id, b.id] })
			expect(risk.score).toBe(20)
			expect(risk.crossProject).toBe(true)
			expect(risk.escalationHint).toBe(true)
			expect((await call(admin, 'get', `/projects/${b.id}`)).health.findings.map(f => f.rule)).toContain('critical_risk_unmitigated')
			const portfolio = await call(admin, 'get', '/risks')
			expect(portfolio.risks.map(r => r.id)).toContain(risk.id)
			// Gegenmaßnahme und Restrisiko; Maßnahme landet in der Beschlussverfolgung
			const mitigated = await call(pm, 'put', `/risks/${risk.id}`, { mitigation: 'Zweitlieferant qualifizieren', residualProbability: 2, residualImpact: 3, escalated: true }, 200)
			expect(mitigated.residualScore).toBe(6)
			await call(pm, 'post', `/projects/${a.id}/actions`, { originType: 'risk', originId: risk.id, title: 'Rahmenvertrag mit Zweitlieferant', ownerId: E2E_OTHER_USER, dueDate: inDays(30) })
			expect((await call(pm, 'get', `/projects/${a.id}/risks`))[0].actions.map(x => x.title)).toEqual(['Rahmenvertrag mit Zweitlieferant'])
			expect((await call(pm, 'get', '/conditions')).some(c => c.title === 'Rahmenvertrag mit Zweitlieferant' && c.kind === 'action')).toBe(true)

			// Issue mit Eskalationsstufe → Kennzeichen am Projekt
			const issues = await call(pm, 'post', `/projects/${a.id}/issues`, { title: 'Testumgebung fehlt', severity: 'critical', escalationLevel: 2 })
			expect(issues[0].escalationLevel).toBe(2)
			expect((await call(admin, 'get', `/projects/${a.id}`)).escalation).toBe(2)

			// Change Request unter der Schwelle: Projektebene (nicht, wer ihn gestellt hat – Funktionstrennung)
			const small = await call(pm, 'post', `/projects/${a.id}/changes`, { title: 'Abnahme zwei Wochen später', changeType: 'time', deltaDays: 14, impactAnalysis: 'Keine Kostenwirkung', deltaRisk: 'keine', deltaBenefit: 'keine' })
			expect(small.reasons).toEqual([])
			const submitted = await call(pm, 'post', `/projects/${a.id}/changes/${small.id}/submit`, undefined, 200)
			expect(submitted.aboveThreshold).toBe(0)
			expect((await pm.post(`${API}/projects/${a.id}/changes/${small.id}/decision`, { data: { outcome: 'approved', reason: 'x' } })).status()).toBe(403)
			const noReason = await admin.post(`${API}/projects/${a.id}/changes/${small.id}/decision`, { data: { outcome: 'approved' } })
			expect(noReason.status()).toBe(400)
			const decided = await call(admin, 'post', `/projects/${a.id}/changes/${small.id}/decision`, { outcome: 'approved', reason: 'vertretbar' }, 200)
			expect(decided.status).toBe('incorporated')
			let cockpit = await call(admin, 'get', `/projects/${a.id}`)
			expect(cockpit.baselines.map(x => x.version)).toEqual([2, 1])

			// Change Request über der Schwelle (Budget, Ressourcen im Engpass-Pool): nicht die Projektleitung
			const big = await call(pm, 'post', `/projects/${a.id}/changes`, {
				title: 'Zusätzliche Schnittstelle',
				changeType: 'scope',
				deltaBudget: 60000,
				deltaPersonDays: { [pool.id]: 40 },
				impactAnalysis: 'Mehraufwand Entwicklung',
				deltaRisk: 'Terminrisiko steigt',
				deltaBenefit: 'Automatisierung +10 %',
			})
			expect(big.reasons).toEqual(['budget', 'resources'])
			expect(big.portfolioImpact[0].covered).toBe(false)
			await call(pm, 'post', `/projects/${a.id}/changes/${big.id}/submit`, undefined, 200)
			expect((await pm.post(`${API}/projects/${a.id}/changes/${big.id}/decision`, { data: { outcome: 'approved', reason: 'x' } })).status()).toBe(403)
			await call(admin, 'post', `/projects/${a.id}/changes/${big.id}/decision`, { outcome: 'approved', reason: 'Board-Beschluss' }, 200)
			cockpit = await call(admin, 'get', `/projects/${a.id}`)
			expect(cockpit.baselines[0].version).toBe(3)
			expect(cockpit.baselines[0].budgetFrame).toBe(160000)
			expect(cockpit.baselines[0].personDays).toBe(48)
			const types = (await call(admin, 'get', '/decisions')).filter(x => x.demand.id === a.id).map(x => x.decisionType)
			expect(types.filter(x => x === 'change').length).toBe(2)
		} finally {
			await own.dispose()
			await pm.dispose()
			await admin.dispose()
		}
	})

	test('Konflikterkennung mit Lösung in Prüfreihenfolge, KPI-Dashboard und Schwellenwerte', async () => {
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Konflikt ${t}` })
			// nicht als Engpass markiert: G3 lässt die Überbuchung zu, die Erkennung meldet sie
			const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Pool ${t}`, isBottleneck: false, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
			await call(admin, 'put', `/pools/${pool.id}/capacity/${month()}`, { fte: 0.1, workingDays: 20 }, 200)
			const d = await released(own, admin, { title: `E2E-Konflikt ${t}`, unitId: unit.id, poolId: pool.id, days: 6 })

			expect((await own.post(`${API}/conflicts/detect`)).status()).toBe(403)
			const list = await call(admin, 'post', '/conflicts/detect', undefined, 200)
			const conflict = list.conflicts.find(c => c.conflictType === 'overbooking' && c.poolId === pool.id && c.period === month())
			expect(conflict.escalationLevel).toBe(1)
			expect(conflict.projects.map(p => p.id)).toContain(d.id)
			// erneute Erkennung legt keinen zweiten Konflikt an
			const again = await call(admin, 'post', '/conflicts/detect', undefined, 200)
			expect(again.conflicts.filter(c => c.fingerprint === conflict.fingerprint && c.status === 'open').length).toBe(1)

			// „Scope schneiden“ erst, wenn „zeitlich staffeln“ begründet verworfen ist
			const skip = await admin.post(`${API}/conflicts/${conflict.id}/resolve`, { data: { option: 'scope_cut', note: 'Umfang reduziert' } })
			expect(skip.status()).toBe(400)
			const resolved = await call(admin, 'post', `/conflicts/${conflict.id}/resolve`, { option: 'scope_cut', rejected: { sequenced: 'Frist ist gesetzlich' }, note: 'Umfang reduziert' }, 200)
			const after = resolved.conflicts.find(c => c.id === conflict.id)
			expect(after.status).toBe('resolved')
			expect(after.rejectedOptions.sequenced).toBe('Frist ist gesetzlich')

			const kpis = await call(admin, 'get', '/kpis')
			const codes = kpis.kpis.map(k => k.code)
			expect(codes).toContain('bottleneck_load')
			expect(codes).toContain('health_contradiction')
			const overdue = kpis.kpis.find(k => k.code === 'overdue_conditions')
			expect(typeof overdue.value).toBe('number')
			expect(['green', 'yellow', 'red']).toContain(overdue.level)
			expect((await own.get(`${API}/kpis`)).status()).toBe(403)

			const settings = await call(admin, 'get', '/settings')
			expect(settings.values.crBudgetPct).toBe(10)
			expect((await own.put(`${API}/settings`, { data: { crBudgetPct: 5 } })).status()).toBe(403)
		} finally {
			await own.dispose()
			await admin.dispose()
		}
	})

	test('Oberfläche: Cockpit mit Meilenstein und Statusbericht, Konflikt im Dialog lösen', async ({ browser }) => {
		test.setTimeout(150000)
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const pmUi = await loginAs(browser, E2E_OTHER_USER, E2E_PASSWORD)
		const pmoUi = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Oberfläche ${t}` })
			const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Pool ${t}`, isBottleneck: false, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
			await call(admin, 'put', `/pools/${pool.id}/capacity/${month()}`, { fte: 0.1, workingDays: 20 }, 200)
			const d = await released(own, admin, { title: `E2E-Oberfläche ${t}`, unitId: unit.id, poolId: pool.id, days: 6 })
			await call(admin, 'put', `/projects/${d.id}`, { managerId: E2E_OTHER_USER }, 200)

			// Projektleitung: Projekt über die Gruppe „Steuerung“ öffnen
			const page = pmUi.page
			await page.goto('/apps/nc_nexus/')
			await navTo(page, 'steering', 'nav-projects')
			await page.locator(`[data-test="project-${d.projectKey}"]`).click()
			const cockpit = page.locator('[data-test="project-cockpit"]')
			await expect(cockpit.locator('[data-test="cockpit-manager"]')).toHaveText(E2E_OTHER_USER)

			// Meilenstein mit externer Zusage, überfällig → Indikator rot
			await cockpit.locator('[data-test="add-milestone"]').click()
			const ms = page.locator('[data-test="milestone-dialog"]')
			await ms.locator('[data-test="field-name"] input').fill('Abnahme durch den Kunden')
			await ms.locator('[data-test="field-dueDate"] input').fill(new Date(Date.now() - 2 * 86400000).toISOString().slice(0, 10))
			await ms.locator('[data-test="field-external"] input').check()
			await ms.locator('[data-test="form-save"]').click()
			await expect(cockpit.locator('[data-test="cockpit-milestones"]')).toContainText('Abnahme durch den Kunden')
			await expect(cockpit.locator('[data-test="cockpit-health"]')).toHaveText(/Red|Rot/)

			// Statusbericht: grün gemeldet widerspricht dem Indikator → erst mit Begründung
			await cockpit.locator('[data-test="new-report"]').click()
			const report = page.locator('[data-test="report-dialog"]')
			await report.locator('[data-test="field-achievements"] textarea').fill('Schnittstelle fertig')
			await report.locator('[data-test="form-save"]').click()
			await expect(report).toBeVisible()
			await report.locator('[data-test="field-contradictionReason"] textarea').fill('Abnahme mündlich erteilt, Protokoll folgt')
			await report.locator('[data-test="form-save"]').click()
			await expect(report).toHaveCount(0)
			await expect(cockpit.locator('[data-test="cockpit-reports"]')).toContainText('Schnittstelle fertig')
			await expect(cockpit.locator('[data-test="cockpit-status"]')).toContainText(/contradicts the indicator|widerspricht dem Indikator/)

			// PMO: Überbuchung erkennen und im Dialog in Prüfreihenfolge lösen
			const pmo = pmoUi.page
			await pmo.goto('/apps/nc_nexus/')
			await navTo(pmo, 'steering', 'nav-conflicts')
			await pmo.locator('[data-test="detect-conflicts"]').click()
			await expect(pmo.locator('[data-test="conflicts-view"]')).toContainText(`E2E-Pool ${t}`)
			const conflict = (await call(admin, 'get', '/conflicts')).conflicts.find(c => c.status === 'open' && c.poolId === pool.id && c.conflictType === 'overbooking')
			const row = pmo.locator(`[data-test="conflict-${conflict.id}"]`)
			await row.locator('[data-test="resolve-conflict"]').click()
			const dialog = pmo.locator('[data-test="resolve-dialog"]')
			await dialog.locator('[data-test="resolve-option-scope_cut"] input[type="radio"]').check()
			await expect(dialog.locator('[data-test="save-resolution"]')).toBeDisabled()
			await dialog.locator('[data-test="resolve-why-sequenced"]').fill('Termin ist vertraglich fix')
			await dialog.locator('[data-test="resolve-note"] textarea').fill('Umfang um ein Modul reduziert')
			await dialog.locator('[data-test="save-resolution"]').click()
			await expect(row).toContainText(/resolved|gelöst/)
			await expect(row).toContainText(/Cut scope|Scope schneiden/)
		} finally {
			await pmUi.context.close()
			await pmoUi.context.close()
			await own.dispose()
			await admin.dispose()
		}
	})
})
