// @ts-check
const { test, expect } = require('@playwright/test')
const { API, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

test.describe('Management-Dashboard (REP-001)', () => {
	test('Governance-Rollen starten auf der Übersicht; Kacheln zeigen die Lage und führen in die Detailsichten', async ({ browser }) => {
		test.setTimeout(120000)
		const own = await apiAs(E2E_USER)
		const admin = await adminApi()
		try {
			// ein frisch eingereichter Antrag wartet auf die Triage
			const d = await createDemand(own, { title: `E2E-Dashboard ${Date.now()}`, demandType: 'efficiency', problemStatement: 'Übersicht prüfen.' })
			await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)

			const data = await call(admin, 'get', '/dashboard')
			expect(data.decisions.pending).toBeGreaterThanOrEqual(1)
			expect(data.decisions.oldest.length).toBeLessThanOrEqual(5)
			expect(Object.keys(data)).toEqual(expect.arrayContaining(['portfolio', 'finance', 'capacity', 'risks', 'decisions', 'benefits']))

			const ui = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
			const page = ui.page
			try {
				await page.goto('/apps/nc_nexus/')
				const view = page.locator('[data-test="dashboard-view"]')
				await expect(view).toBeVisible()
				for (const tile of ['portfolio', 'finance', 'capacity', 'risks', 'decisions', 'benefits']) {
					await expect(view.locator(`[data-test="tile-${tile}"]`)).toBeVisible()
				}
				// Werte wie in der API
				await expect(view.locator('[data-test="decisions-pending"]')).toHaveText(String((await call(admin, 'get', '/dashboard')).decisions.pending))
				await expect(view.locator('[data-test="risks-open"]')).toHaveText(String(data.risks.open))
				await expect(view.locator('[data-test="capacity-waiting"]')).toHaveText(String(data.capacity.cut.waiting))

				// Portfolio-Matrix: ein Kreis je Vorhaben mit Aufwand, Tooltip, Tabellenansicht
				const matrix = view.locator('[data-test="tile-maturity"]')
				if (data.maturity.points.length) {
					await expect(matrix.locator('.mat__dot')).toHaveCount(data.maturity.points.length)
					const point = data.maturity.points[0]
					await matrix.locator(`[data-test="maturity-${point.key}"] .mat__mark`).hover()
					await expect(matrix.locator('[data-test="maturity-tip"]')).toContainText(point.key)
					// Farbe nach Bucket: Legende nennt jeden Bucket, Tooltip den des Vorhabens
					for (const b of data.maturity.buckets) {
						await expect(matrix.locator(`[data-test="maturity-bucket-${b.code}"]`)).toContainText(b.name)
					}
					await expect(matrix.locator('[data-test="maturity-tip"]')).toContainText(data.maturity.buckets.find(b => b.code === point.bucket).name)
					await matrix.locator('[data-test="maturity-toggle"]').click()
					await expect(matrix.locator('[data-test="maturity-table"] tbody tr')).toHaveCount(data.maturity.points.length)
					await matrix.locator('[data-test="maturity-toggle"]').click()
					// Klick: Projekt öffnet das Cockpit, Antrag den Dialog
					await matrix.locator(`[data-test="maturity-${point.key}"] .mat__mark`).click()
					if (point.project) {
						await expect(page.locator('[data-test="nav-projects"]')).toBeVisible()
					} else {
						await expect(page.locator('[data-test="demand-editor"]')).toBeVisible()
						await page.keyboard.press('Escape')
					}
					await page.locator('[data-test="nav-dashboard"]').click()
				}
				// alle Vorhaben im Reifegrad: ohne Aufwand gesondert gezählt, nie doppelt
				const ids = [...data.maturity.points, ...data.maturity.withoutDays].map(x => x.id)
				expect(new Set(ids).size).toBe(ids.length)
				expect(ids).toContain(d.id)

				// Drill-down: Kachel → Detailsicht, die Navigationsgruppe klappt dabei auf
				await view.locator('[data-test="drill-capacity"]').click()
				await expect(page.locator('[data-test="capacity-view"]')).toBeVisible()
				await expect(page.locator('[data-test="nav-capacity"]')).toBeVisible()
				await page.locator('[data-test="nav-dashboard"]').click()
				await view.locator('[data-test="drill-risks"]').click()
				await expect(page.locator('[data-test="risks-view"]')).toBeVisible()
				await expect(page.locator('[data-test="nav-risks"]')).toBeVisible()

				// Eintrag in der Kachel öffnet den Antrag
				await page.locator('[data-test="nav-dashboard"]').click()
				const pending = await call(admin, 'get', '/dashboard')
				const first = pending.decisions.oldest[0]
				await view.locator('[data-test="tile-decisions"] .dash__item').first().click()
				const editor = page.locator('[data-test="demand-editor"]')
				await expect(editor).toBeVisible()
				await expect(editor).toContainText(first.key)
			} finally {
				await ui.context.close()
			}

			// ohne Governance-Rolle: kein Dashboard, Start in der Antragsliste
			expect((await own.get(`${API}/dashboard`)).status()).toBe(403)
		} finally {
			await own.dispose()
			await admin.dispose()
		}
	})

	test('Ohne Governance-Rolle bleibt die Antragsliste die Startseite', async ({ page }) => {
		await page.goto('/apps/nc_nexus/')
		await expect(page.locator('[data-test="nav-all"]')).toBeVisible()
		await expect(page.locator('[data-test="nav-dashboard"]')).toHaveCount(0)
		await expect(page.locator('[data-test="dashboard-view"]')).toHaveCount(0)
	})
})
