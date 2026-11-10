// @ts-check
const { test, expect } = require('@playwright/test')
const { PATH, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { E2E_USER } = require('../helpers/env')

test.describe('Business Case und Nutzenregister', () => {
	test('Kosten und Nutzen erfassen, Pflichtregel, Kennzahlen, Nutzenregister', async ({ page }) => {
		test.setTimeout(90000)
		const t = 'Zb' + Math.random().toString(36).slice(2, 7)
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const d = await createDemand(own, { title: `E2E-BC ${t}`, demandType: 'efficiency', problemStatement: 'x' })
		// im Entwurf noch kein Business Case
		expect((await call(own, 'get', `/demands/${d.id}/business-case`)).canEdit).toBe(false)
		await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
		const demand = await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted' }, 200)
		try {
			// Pflichtregel: Nutzenposition ohne Ausgangswert wird abgelehnt
			const bad = await own.put(`${PATH}/${demand.id}/business-case`, {
				data: { content: { costs: [], benefits: [{ type: 'cost_saving', title: 'X', amount: 1, metric: 'Zeit', baseline: '', ownerUserId: E2E_USER }] } },
			})
			expect(bad.status()).toBe(400)

			await page.goto('/apps/nc_nexus/')
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			await page.locator('[data-test="open-business-case"]').click()
			const dialog = page.locator('[data-test="business-case-dialog"]')
			await dialog.locator('[data-test="add-cost"]').click()
			const cost = dialog.locator('[data-test="cost-0"]')
			await cost.locator('input').first().fill('Lizenzen und Einführung')
			await cost.locator('input[type="number"]').fill('60000')
			await dialog.locator('[data-test="add-benefit"]').click()
			const benefit = dialog.locator('[data-test="benefit-0"]')
			await benefit.locator('[data-test="benefit-title"]').fill('Wegfall Altvertrag')
			await benefit.locator('[data-test="benefit-amount"]').fill('30000')
			await benefit.locator('[data-test="benefit-metric"]').fill('Wartungskosten pro Jahr')
			await benefit.locator('[data-test="benefit-baseline"]').fill('30 000 €')
			// Vorschau: Amortisation 60 000 / 30 000 = 2 Jahre
			await expect(dialog.locator('[data-test="bc-payback"]')).toContainText('2')
			await dialog.locator('[data-test="save-business-case"]').click()
			await expect(dialog).toHaveCount(0)
			await expect(page.locator('[data-test="bc-summary-benefit"]')).toContainText('30')

			// Nutzenregister zeigt die Position mit Verantwortlichem
			await page.keyboard.press('Escape')
			await page.locator('[data-test="nav-benefits"]').click()
			const row = page.locator(`[data-test="benefit-row-${demand.key}"]`)
			await expect(row).toContainText('Wegfall Altvertrag')
			await expect(row).toContainText(E2E_USER)
			const bc = await call(own, 'get', `/demands/${demand.id}/business-case`)
			expect(bc.kpis.paybackYears).toBe(2)
			expect(bc.complete).toBe(true)
		} finally {
			await own.dispose()
			await admin.dispose()
		}
	})
})
