// @ts-check
const { test, expect } = require('@playwright/test')
const { navTo, openDemands } = require('../helpers/nav')
const { adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

test.describe('Portfolioübersicht', () => {
	test('Org-Einheiten × Phasen mit Aufrollen und Sprung in die gefilterte Liste', async ({ browser }) => {
		test.setTimeout(90000)
		const tag = 'Zp' + Math.random().toString(36).slice(2, 7)
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const parent = await call(admin, 'post', '/org-units', { name: `E2E-Bereich ${tag}` })
		const child = await call(admin, 'post', '/org-units', { name: `E2E-Team ${tag}`, parentId: parent.id })
		// zwei eingereichte Anträge im Team, einer davon zurückgestellt
		const submit = async (title) => {
			const d = await createDemand(own, { title, demandType: 'innovation', problemStatement: 'x', orgUnitId: child.id })
			return call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
		}
		const open = await submit(`E2E-Portfolio offen ${tag}`)
		const later = await submit(`E2E-Portfolio später ${tag}`)
		await call(admin, 'post', `/demands/${later.id}/triage`, { code: 'deferred', resubmissionDate: '2030-01-01' }, 200)

		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await openDemands(page)
			await navTo(page, 'portfolio', 'nav-portfolio')
			const view = page.locator('[data-test="portfolio-phases"]')
			// Bereich rollt das Team auf: 1 Antrag, 1 On Hold
			await expect(view.locator(`[data-test="portfolio-cell-E2E-Bereich ${tag}-demand"]`)).toHaveText('1')
			await expect(view.locator(`[data-test="portfolio-cell-E2E-Bereich ${tag}-on_hold"]`)).toHaveText('1')
			await expect(view.locator(`[data-test="portfolio-cell-E2E-Team ${tag}-demand"]`)).toHaveText('1')

			// Klick auf „Antrag“ des Bereichs öffnet die Liste mit genau dem offenen Antrag
			await view.locator(`[data-test="portfolio-cell-E2E-Bereich ${tag}-demand"]`).click()
			await expect(page.locator(`[data-test="demand-${open.key}"]`)).toBeVisible()
			await expect(page.locator(`[data-test="demand-${later.key}"]`)).toHaveCount(0)
			await expect(page.locator('[data-test^="demand-DEM-"]')).toHaveCount(1)
		} finally {
			await context.close()
			await own.dispose()
			await admin.dispose()
		}
	})
})
