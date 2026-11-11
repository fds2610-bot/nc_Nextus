// @ts-check
const { test, expect } = require('@playwright/test')
const { adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const thisMonth = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

test.describe('Planungs-Integration (nc_gantt)', () => {
	test('Freigegebenes Vorhaben in Gantt anlegen und Fortschritt lesen', async ({ browser }) => {
		test.setTimeout(150000)
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const t = 'P' + Math.random().toString(36).slice(2, 6).toUpperCase()
		const unit = await call(admin, 'post', '/org-units', { name: `E2E-Planung ${t}` })
		const pool = await call(admin, 'post', '/pools', { code: `E2EP${t}`, name: `E2E-Pool ${t}` })
		const d = await createDemand(own, { title: `E2E-Planung ${t}`, demandType: 'efficiency', problemStatement: 'x', orgUnitId: unit.id })
		// ohne nc_gantt: Abschnitt nur als Hinweis, Test überspringen (eigener Entwurf ist lesbar)
		const probe = await call(own, 'get', `/demands/${d.id}/planning`)
		test.skip(!probe.available, 'nc_gantt ist in dieser Umgebung nicht installiert')

		await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId: pool.id, period: thisMonth(), personDays: 5 }] }, 200)
		await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
		await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted' }, 200)
		const model = await call(admin, 'get', '/scoring/model')
		await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores: Object.fromEntries(model.set.criteria.map(c => [c.code, 3])), effort: 1 }, 201)
		await call(own, 'put', `/demands/${d.id}/business-case`, { content: { costs: [{ label: 'x', kind: 'capex', amount: 1 }], benefits: [{ type: 'qualitative', title: 'y', metric: 'm', baseline: 'b', ownerUserId: E2E_USER }] } }, 200)
		for (const step of [{ step: 'candidate' }, { step: 'g2', outcome: 'prioritized' }, { step: 'g3' }]) {
			await call(admin, 'post', `/demands/${d.id}/gate`, step, 200)
		}
		const demand = await call(admin, 'get', `/demands/${d.id}`)

		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await page.goto('/apps/nc_nexus/')
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			const panel = page.locator('[data-test="planning"]')
			await panel.locator('[data-test="planning-create"]').click()
			await expect(panel.locator('[data-test="planning-open"]')).toContainText(demand.projectKey)
			await expect(panel.locator('[data-test="planning-progress"]')).toHaveText('0 %')
			const link = (await call(admin, 'get', `/demands/${d.id}/planning`)).link
			expect(link.provider).toBe('gantt')
			expect(link.syncStatus).toBe('ok')
			// doppelt verknüpfen geht nicht
			expect((await admin.post(`/ocs/v2.php/apps/nc_nexus/api/v1/demands/${d.id}/planning`, { data: {} })).status()).toBe(400)
		} finally {
			await context.close()
			await own.dispose()
			await admin.dispose()
		}
	})
})
