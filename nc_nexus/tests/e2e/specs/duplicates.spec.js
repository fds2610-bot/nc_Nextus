// @ts-check
const { test, expect } = require('@playwright/test')
const { adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { E2E_OTHER_USER, E2E_USER } = require('../helpers/env')

// eindeutiges Kunstwort, damit ältere Testanträge der DEV-Instanz nicht mitzählen
const word = () => 'Zq' + Math.random().toString(36).slice(2, 9)

test.describe('Dublettenvorschlag', () => {
	test('Ähnliche offene Anträge erscheinen beim Tippen, fremde nur als Anzahl', async ({ page }) => {
		const marker = word()
		const own = await apiAs(E2E_USER)
		const other = await apiAs(E2E_OTHER_USER)
		const admin = await adminApi()
		const unit = await call(admin, 'post', '/org-units', { name: `E2E-Dubletten ${marker}` })

		// eigener eingereichter Antrag (lesbar) und fremder in einer Einheit ohne Rolle (nicht lesbar)
		const mine = await createDemand(own, { title: `${marker} Plattform ablösen`, demandType: 'lifecycle', problemStatement: 'Support endet.' })
		await call(own, 'post', `/demands/${mine.id}/submit`, undefined, 200)
		const foreign = await createDemand(other, { title: `${marker} Plattform erneuern`, demandType: 'lifecycle', problemStatement: 'Veraltet.', orgUnitId: unit.id })
		await call(other, 'post', `/demands/${foreign.id}/submit`, undefined, 200)
		try {
			await page.goto('/apps/nc_nexus/')
			await page.locator('[data-test="new-demand"]').click()
			const editor = page.locator('[data-test="demand-editor"]')
			await editor.locator('[data-test="field-title"] input').fill(`${marker} Plattform`)
			const box = editor.locator('[data-test="similar-demands"]')
			await expect(box.locator(`[data-test="similar-${mine.key}"]`)).toBeVisible()
			await expect(box).not.toContainText(foreign.key)
			await expect(box.locator('[data-test="similar-hidden"]')).toContainText('1')

			// ohne Treffer verschwindet der Hinweis wieder
			await editor.locator('[data-test="field-title"] input').fill(word() + ' Kantine')
			await expect(box).toHaveCount(0)
		} finally {
			await page.keyboard.press('Escape')
			// eingereichte Anträge bleiben (absichtlich nicht löschbar); die Einheit behält dadurch den Antrag
			await own.dispose()
			await other.dispose()
			await admin.dispose()
		}
	})
})
