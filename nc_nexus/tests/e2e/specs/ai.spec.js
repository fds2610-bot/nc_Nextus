// @ts-check
const { test, expect } = require('@playwright/test')
const { openDemands } = require('../helpers/nav')
const { adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_OTHER_USER, E2E_PASSWORD, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

/*
 * KI-Assistenz (AI-001). Die DEV-Umgebung bringt den Dummy-Anbieter nexus_dev_ai mit
 * (docker-dev/apps), der sofort eine feste Antwort liefert. Ohne Anbieter werden die Tests übersprungen.
 */
test.describe('KI-Assistenz', () => {
	test('Zusammenfassung als Vorschlag mit Datenbasis, nur mit Leserecht, abschaltbar', async ({ browser }) => {
		test.setTimeout(120000)
		const admin = await adminApi()
		const config = await call(admin, 'get', '/ai/config')
		test.skip(!config.provider, 'kein TaskProcessing-Anbieter für Text zu Text')
		const own = await apiAs(E2E_USER)
		const other = await apiAs(E2E_OTHER_USER)
		const t = Math.random().toString(36).slice(2, 6).toUpperCase()
		const title = `E2E-KI ${t}`
		const d = await createDemand(own, { title, demandType: 'efficiency', problemStatement: 'Prüfung mit Dummy-KI' })
		await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)

		// API: Aufträge je Status, ohne Leserecht wie „nicht vorhanden“
		const state = await call(own, 'get', `/demands/${d.id}/ai`)
		expect(state.enabled).toBe(true)
		expect(state.available).toBe(true)
		expect(state.kinds).toEqual(['summary', 'proposal'])
		await call(own, 'post', `/demands/${d.id}/ai`, { kind: 'contradictions' }, 400)
		await call(other, 'get', `/demands/${d.id}/ai`, undefined, 404)
		await call(other, 'post', `/demands/${d.id}/ai`, { kind: 'summary' }, 404)

		const { context, page } = await loginAs(browser, E2E_USER, E2E_PASSWORD)
		try {
			await openDemands(page)
			await page.locator(`[data-test="demand-${d.key}"]`).click()
			const panel = page.locator('[data-test="ai"]')
			await expect(panel).toBeVisible()
			// nur der Inhalt scrollt, nicht der Modal-Container (sonst wandern die Buttons mit)
			await expect(page.locator('[data-test="group-request"]')).toBeVisible()
			expect(await page.locator('.modal-container').evaluate(el => el.scrollHeight - el.clientHeight)).toBeLessThanOrEqual(1)
			await panel.locator('[data-test="ai-request-summary"]').click()
			const result = panel.locator('[data-test="ai-result-summary"]')
			await expect(result.locator('[data-test="ai-text"]')).toContainText('[DEV-KI]', { timeout: 30000 })
			await expect(result.locator('[data-test="ai-text"]')).toContainText(title)
			// Datenbasis: genau die Daten, die die KI bekommen hat
			await result.locator('summary').click()
			await expect(result.locator('[data-test="ai-basis"]')).toContainText(title)
			await expect(result.locator('[data-test="ai-basis"]')).toContainText('Prüfung mit Dummy-KI')
			// Ergebnis bleibt beim Wiederöffnen erhalten (TaskProcessing speichert es)
			await page.reload()
			await page.locator('[data-test="nav-all"]').click()
			await page.locator(`[data-test="demand-${d.key}"]`).click()
			await expect(page.locator('[data-test="ai-result-summary"] [data-test="ai-text"]')).toContainText(title)
		} finally {
			await context.close()
		}

		// Aufruf steht im Verlauf (Audit)
		const history = await call(own, 'get', `/demands/${d.id}/history`)
		expect(history.some(h => h.action === 'ai_request' && h.userId === E2E_USER)).toBe(true)

		// PMO schaltet die KI-Assistenz ab → nicht mehr angeboten
		const ui = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await ui.page.goto('/apps/nc_nexus/')
			await ui.page.locator('[data-test="nav-governance"]').click()
			const section = ui.page.locator('[data-test="ai-config"]')
			await expect(section.locator('[data-test="ai-provider"]')).toContainText(config.provider)
			await section.getByText(/^(KI-Assistenz anbieten|Offer AI assistance)$/).click()
			await expect.poll(async () => (await call(admin, 'get', '/ai/config')).enabled).toBe(false)
			expect((await call(own, 'get', `/demands/${d.id}/ai`)).enabled).toBe(false)
			await call(own, 'post', `/demands/${d.id}/ai`, { kind: 'summary' }, 400)
		} finally {
			await call(admin, 'put', '/ai/config', { enabled: config.enabled })
			await ui.context.close()
		}
		// Nur das PMO darf schalten
		await call(own, 'put', '/ai/config', { enabled: false }, 403)
	})
})
