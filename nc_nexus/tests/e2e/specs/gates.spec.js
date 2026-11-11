// @ts-check
const { test, expect } = require('@playwright/test')
const { PATH, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const thisMonth = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/** Vorhaben bis „In Qualifizierung“ mit Bedarf, Fremdbewertung und Business Case vorbereiten */
async function qualified(own, admin, { title, unitId, poolId, days }) {
	const d = await createDemand(own, { title, demandType: 'efficiency', problemStatement: 'x', orgUnitId: unitId })
	await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId, period: thisMonth(), personDays: days }] }, 200)
	await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
	const demand = await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted' }, 200)
	const model = await call(admin, 'get', '/scoring/model')
	const scores = Object.fromEntries(model.set.criteria.map(c => [c.code, 3]))
	await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores, effort: 3 }, 201)
	await call(own, 'put', `/demands/${d.id}/business-case`, {
		content: {
			costs: [{ label: 'Umsetzung', kind: 'capex', amount: 10000 }],
			benefits: [{ type: 'cost_saving', title: 'Einsparung', amount: 8000, metric: 'Kosten p. a.', baseline: '8 000 €', ownerUserId: E2E_USER }],
		},
	}, 200)
	return demand
}

test.describe('Gates G2/G3 und Kapazitätsschnitt', () => {
	test('Kandidat → G2 → G3 mit harter Zusage; Freigabe scheitert ohne Kapazität', async ({ browser }) => {
		test.setTimeout(150000)
		const t = 'G' + Math.random().toString(36).slice(2, 6).toUpperCase()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const unit = await call(admin, 'post', '/org-units', { name: `E2E-Gates ${t}` })
		const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Engpass ${t}`, isBottleneck: true, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
		await call(admin, 'put', `/pools/${pool.id}/capacity/${thisMonth()}`, { fte: 1, workingDays: 20 }, 200)
		const first = await qualified(own, admin, { title: `E2E-Gate eins ${t}`, unitId: unit.id, poolId: pool.id, days: 15 })
		const second = await qualified(own, admin, { title: `E2E-Gate zwei ${t}`, unitId: unit.id, poolId: pool.id, days: 10 })

		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await page.goto('/apps/nc_nexus/')
			await page.locator(`[data-test="demand-${first.key}"]`).click()
			const editor = page.locator('[data-test="demand-editor"]')
			const dialog = page.locator('[data-test="gate-dialog"]')

			// Kandidat: Prüfliste vollständig
			await editor.locator('[data-test="open-gate"]').click()
			await expect(dialog.locator('[data-test="check-business_case"]')).toHaveClass(/gate__ok/)
			await dialog.locator('[data-test="save-gate"]').click()
			await expect(editor.locator('[data-test="demand-status"]')).toHaveText(/Candidate|Kandidat/)

			// G2: priorisieren → Projektschlüssel
			await editor.locator('[data-test="open-gate"]').click()
			await dialog.locator('[data-test="outcome-prioritized"] input').check()
			await dialog.locator('[data-test="save-gate"]').click()
			await expect(editor.locator('[data-test="demand-status"]')).toHaveText(/Prioritized|Priorisiert/)
			await expect(editor.locator('[data-test="project-key"]')).toHaveText(/^PRJ-\d{4}-\d{4}$/)

			// G3: Freigabe mit harter Zusage über 15 PT
			await editor.locator('[data-test="open-gate"]').click()
			await expect(dialog.locator('[data-test="check-capacity"]')).toHaveClass(/gate__ok/)
			await dialog.locator('[data-test="save-gate"]').click()
			await expect(editor.locator('[data-test="demand-status"]')).toHaveText(/Released|Freigegeben/)
			await expect(editor.locator('[data-test="allocations"]')).toContainText('15')
			await page.keyboard.press('Escape')

			// Register: Kandidat, G2 und G3 als Beschlüsse
			const decisions = (await call(admin, 'get', '/decisions')).filter(d => d.demand.id === first.id).map(d => d.decisionType)
			expect(decisions).toEqual(['g3', 'g2', 'candidate', 'triage'])

			// zweites Vorhaben: G2 per API, G3 scheitert (10 PT gewünscht, nur 5 PT frei)
			await call(admin, 'post', `/demands/${second.id}/gate`, { step: 'candidate' }, 200)
			await call(admin, 'post', `/demands/${second.id}/gate`, { step: 'g2', outcome: 'prioritized' }, 200)
			const gate = await call(admin, 'get', `/demands/${second.id}/gate`)
			expect(gate.checks.find(c => c.key === 'capacity').ok).toBe(false)
			const refused = await admin.post(`${PATH}/${second.id}/gate`, { data: { step: 'g3' } })
			expect(refused.status()).toBe(400)
			expect((await refused.json()).ocs.data.message).toContain(`E2E${t}`)
			expect((await call(admin, 'get', `/demands/${second.id}`)).status).toBe('prioritized')

			// Startplan: das zweite Vorhaben wartet (Pool im aktuellen Monat ausgeschöpft)
			await page.locator('[data-test="nav-ranking"]').click()
			await page.locator('[data-test="run-cut"]').click()
			await expect(page.locator(`[data-test="plan-${second.key}"] .nx-chip`)).toBeVisible()
			const cut = await call(admin, 'get', '/capacity/cut')
			expect(['shifted', 'waiting']).toContain(cut.results[second.id].state)
		} finally {
			await context.close()
			await own.dispose()
			await admin.dispose()
		}
	})

	test('Antragsteller entscheidet nicht über das eigene Vorhaben', async () => {
		const own = await apiAs(E2E_USER)
		const d = await createDemand(own, { title: 'E2E-Gate selbst', demandType: 'innovation', problemStatement: 'x' })
		await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
		const admin = await adminApi()
		await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted' }, 200)
		const gate = await call(own, 'get', `/demands/${d.id}/gate`)
		expect(gate.canDecide).toBe(false)
		expect((await own.post(`${PATH}/${d.id}/gate`, { data: { step: 'candidate' } })).status()).toBe(403)
		await own.dispose()
		await admin.dispose()
	})
})
