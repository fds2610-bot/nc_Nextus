// @ts-check
const { test, expect } = require('@playwright/test')
const { API, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_PASSWORD, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')
const { navTo, openDemands } = require('../helpers/nav')

const month = (offset = 0) => {
	const d = new Date()
	d.setDate(1)
	d.setMonth(d.getMonth() + offset)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
const tag = () => 'N' + Math.random().toString(36).slice(2, 6).toUpperCase()

/**
 * Abgeschlossenes Projekt (G5) mit Schätzung im Pool und optionalem Ist-Aufwand.
 *
 * @param {import('@playwright/test').APIRequestContext} own Antragsteller
 * @param {import('@playwright/test').APIRequestContext} admin PMO
 * @param {{title: string, unitId: number, poolId: number, projectClass: string, estimate: number, actual: number|null}} spec Angaben
 */
async function completed(own, admin, { title, unitId, poolId, projectClass, estimate, actual }) {
	const d = await createDemand(own, { title, demandType: 'efficiency', problemStatement: 'Nachkalkulation prüfen.', orgUnitId: unitId })
	await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId, period: month(-1), personDays: estimate / 2 }, { poolId, period: month(), personDays: estimate / 2 }] }, 200)
	await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
	await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted', projectClass }, 200)
	const model = await call(admin, 'get', '/scoring/model')
	await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores: Object.fromEntries(model.set.criteria.map(c => [c.code, 3])), effort: 3 }, 201)
	await call(own, 'put', `/demands/${d.id}/business-case`, {
		content: { costs: [{ label: 'Umsetzung', kind: 'capex', amount: 10000 }], benefits: [{ type: 'qualitative', title: 'Ordnung', metric: 'Umfrage', baseline: '3', target: '4', ownerUserId: E2E_USER }] },
	}, 200)
	for (const step of [{ step: 'candidate' }, { step: 'g2', outcome: 'approved' }, { step: 'g3', outcome: 'approved' }, { step: 'g4', outcome: 'approved' }]) {
		await call(admin, 'post', `/demands/${d.id}/gate`, step, 200)
	}
	if (actual !== null) {
		await call(admin, 'post', `/projects/${d.id}/actuals`, { period: month(-1), kind: 'effort', poolId, amount: actual / 2 }, 201)
		await call(admin, 'post', `/projects/${d.id}/actuals`, { period: month(), kind: 'effort', poolId, amount: actual / 2 }, 201)
	}
	await call(admin, 'post', `/demands/${d.id}/gate`, { step: 'g5', outcome: 'approved' }, 200)
	return call(admin, 'get', `/demands/${d.id}`)
}

test.describe('Nachkalkulation und Schätzkorrekturfaktoren (Stufe 5)', () => {
	test('Ist gegen Baseline 1 je Pool und Klasse, Faktor ab Mindestzahl, Hinweis beim Schätzen', async ({ browser }) => {
		test.setTimeout(180000)
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Nachkalk ${t}` })
			const code = `E2E${t}`
			const pool = await call(admin, 'post', '/pools', { code, name: `E2E-Pool ${t}`, isBottleneck: false, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
			const settings = (await call(admin, 'get', '/settings')).values
			const min = settings.recalcMinProjects
			expect(min).toBeGreaterThanOrEqual(1)

			// erstes Projekt: 10 PT geschätzt, 13 PT Ist → 130 %, aber noch unter der Mindestzahl
			const first = await completed(own, admin, { title: `E2E-Nachkalk ${t} 1`, unitId: unit.id, poolId: pool.id, projectClass: 'M', estimate: 10, actual: 13 })
			const cockpit = await call(admin, 'get', `/projects/${first.id}`)
			expect(cockpit.postCalculation.pools).toEqual([{ poolId: pool.id, estimate: 10, current: 10, actual: 13, factor: 1.3 }])
			expect(cockpit.postCalculation.factor).toBe(1.3)
			let overview = await call(admin, 'get', '/post-calculation')
			expect(overview.byPool[pool.id].classes.M).toMatchObject({ estimate: 10, actual: 13, count: 1, factor: 1.3, valid: min <= 1 })

			// weitere Projekte bis zur Mindestzahl; zusammen 1,3-fach
			for (let i = 2; i <= Math.max(min, 3); i++) {
				await completed(own, admin, { title: `E2E-Nachkalk ${t} ${i}`, unitId: unit.id, poolId: pool.id, projectClass: 'M', estimate: 10, actual: i === 2 ? 12 : (i === 3 ? 14 : 13) })
			}
			// ohne Ist-Aufwand: gelistet, aber nicht gezählt
			const empty = await completed(own, admin, { title: `E2E-Nachkalk ${t} leer`, unitId: unit.id, poolId: pool.id, projectClass: 'M', estimate: 10, actual: null })

			overview = await call(admin, 'get', '/post-calculation')
			const n = Math.max(min, 3)
			const cell = overview.byPool[pool.id].classes.M
			expect(cell).toMatchObject({ estimate: n * 10, actual: n * 13, count: n, factor: 1.3, valid: true })
			expect(overview.byPool[pool.id].all).toMatchObject({ count: n, factor: 1.3, valid: true })
			const listed = overview.projects.find(p => p.demandId === empty.id)
			expect(listed).toMatchObject({ counted: false, inWindow: true, projectClass: 'M' })

			// Faktoren ohne Projektbezug für alle, Übersicht nur für Portfolio-Rollen
			const factors = await call(own, 'get', '/post-calculation/factors')
			expect(factors.byPool[pool.id].classes.M.factor).toBe(1.3)
			expect((await own.get(`${API}/post-calculation`)).status()).toBe(403)

			// Kennzahl „Schätzgenauigkeit“ ist im Startmodell vorhanden
			const kpis = await call(admin, 'get', '/kpis')
			expect(kpis.kpis.map(k => k.code)).toContain('estimate_accuracy')

			// Oberfläche PMO: Matrix und Cockpit
			const pmo = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
			try {
				await openDemands(pmo.page)
				await navTo(pmo.page, 'steering', 'nav-postcalc')
				const view = pmo.page.locator('[data-test="postcalc-view"]')
				await expect(view.locator(`[data-test="postcalc-all-${code}"]`)).toContainText('130 %')
				await expect(view.locator(`[data-test="postcalc-project-${empty.id}"]`)).toContainText(/no actual effort|kein Ist-Aufwand/)
				await view.locator(`[data-test="postcalc-project-${first.id}"] .nx-row--click`).click()
				const table = pmo.page.locator('[data-test="cockpit-postcalc"]')
				await expect(table).toBeVisible()
				await expect(table.locator('[data-test="cockpit-postcalc-factor"]')).toHaveText('130 %')
			} finally {
				await pmo.context.close()
			}

			// Oberfläche Antragsteller: Erfahrungswert beim Schätzen, Faktor anwenden
			const ui = await loginAs(browser, E2E_USER, E2E_PASSWORD)
			try {
				const page = ui.page
				await openDemands(page)
				await page.locator('[data-test="new-demand"]').click()
				const editor = page.locator('[data-test="demand-editor"]')
				await editor.locator('[data-test="add-need"]').click()
				const need = editor.locator('[data-test="need-0"]')
				await need.locator('select').selectOption({ label: `E2E-Pool ${t}` })
				await need.locator('input.needs__days').fill('20')
				const hint = editor.locator(`[data-test="needs-correction-${pool.id}"]`)
				await expect(hint).toContainText('130 %')
				await expect(hint).toContainText('26')
				await hint.locator(`[data-test="apply-correction-${pool.id}"]`).click()
				await expect(need.locator('input.needs__days')).toHaveValue('26')
				// einmal angewendet, verschwindet der Hinweis (kein doppeltes Hochrechnen)
				await expect(hint).toHaveCount(0)
			} finally {
				await ui.context.close()
			}
		} finally {
			await own.dispose()
			await admin.dispose()
		}
	})
})
