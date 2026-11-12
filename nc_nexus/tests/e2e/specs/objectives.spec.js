// @ts-check
const { test, expect } = require('@playwright/test')
const { navTo, openDemands } = require('../helpers/nav')
const { adminApi, apiAs, call } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const code = () => 'Z' + Math.random().toString(36).slice(2, 7).toUpperCase()

test.describe('Strategische Ziele', () => {
	test('PMO legt ein Ziel an, Antragsteller ordnet zu, Abdeckung erscheint', async ({ page, browser }) => {
		test.setTimeout(90000)
		const c = code()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const { context, page: pmo } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		let demandId = null
		try {
			// PMO legt das Ziel in der Oberfläche an
			await openDemands(pmo)
			await navTo(pmo, 'portfolio', 'nav-objectives')
			await pmo.locator('[data-test="new-objective"]').click()
			const dialog = pmo.locator('[data-test="objective-dialog"]')
			await dialog.locator('[data-test="field-objective-code"] input').fill(c)
			await dialog.locator('[data-test="field-objective-title"] input').fill(`E2E-Ziel ${c}`)
			await dialog.locator('[data-test="save-objective"]').click()
			const row = pmo.locator(`[data-test="objective-${c}"]`)
			await expect(row.locator('[data-test="objective-coverage"]')).toHaveText(/not covered|nicht abgedeckt/)

			// Antragsteller sieht die Ziele (ohne Anlegen) und ordnet seinen Antrag zu
			await openDemands(page)
			await navTo(page, 'portfolio', 'nav-objectives')
			await expect(page.locator('[data-test="new-objective"]')).toHaveCount(0)
			await page.locator('[data-test="new-demand"]').click()
			const editor = page.locator('[data-test="demand-editor"]')
			await editor.locator('[data-test="field-title"] input').fill(`E2E-Zielbeitrag ${c}`)
			await editor.locator(`[data-test="objective-check-${c}"]`).check()
			await editor.locator('[data-test="save-demand"]').click()
			await expect(editor.locator('[data-test="demand-status"]')).toBeVisible()
			const demand = (await call(own, 'get', '/demands')).find(d => d.title === `E2E-Zielbeitrag ${c}`)
			demandId = demand.id
			const objective = (await call(own, 'get', '/objectives')).objectives.find(o => o.code === c)
			expect(await call(own, 'get', `/demands/${demandId}/objectives`)).toEqual([objective.id])
			// der eigene Entwurf zählt für den Antragsteller zur Abdeckung, für das PMO noch nicht
			expect(objective.demandCount).toBe(1)
			expect((await call(admin, 'get', '/objectives')).objectives.find(o => o.code === c).demandCount).toBe(0)

			// zugeordnetes Ziel lässt sich nicht löschen
			const res = await admin.delete(`/ocs/v2.php/apps/nc_nexus/api/v1/objectives/${objective.id}`)
			expect(res.status()).toBe(400)
		} finally {
			await context.close()
			if (demandId) {
				await call(own, 'delete', `/demands/${demandId}`)
			}
			const o = (await call(admin, 'get', '/objectives')).objectives.find(x => x.code === c)
			if (o) {
				await call(admin, 'delete', `/objectives/${o.id}`)
			}
			await own.dispose()
			await admin.dispose()
		}
	})
})
