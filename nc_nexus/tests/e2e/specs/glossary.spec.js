// @ts-check
const { test, expect } = require('@playwright/test')

test.describe('Glossar', () => {
	test('über dem Bewertungsmodell erreichbar, durchsuchbar, Kopf- und Fußzeile fest', async ({ page }) => {
		await page.goto('/apps/nc_nexus/')
		const nav = page.locator('[data-test="nav-glossary"]')
		await expect(nav).toBeVisible()
		// steht im Fuß der Navigation vor dem Bewertungsmodell (sofern dieses sichtbar ist)
		const model = page.locator('[data-test="nav-model"]')
		if (await model.count()) {
			const [g, m] = [await nav.boundingBox(), await model.boundingBox()]
			expect(g.y).toBeLessThan(m.y)
		}
		await nav.click()
		const dialog = page.locator('[data-test="glossary-dialog"]')
		await expect(dialog).toBeVisible()
		const entries = dialog.locator('[data-test="glossary-entry"]')
		expect(await entries.count()).toBeGreaterThan(30)
		await expect(dialog).toContainText(/Wertdichte|Value density/)

		// Fußzeile bleibt beim Scrollen an derselben Stelle
		const close = dialog.locator('[data-test="glossary-close"]')
		const before = await close.boundingBox()
		await entries.last().scrollIntoViewIfNeeded()
		expect((await close.boundingBox()).y).toBeCloseTo(before.y, 0)

		await dialog.locator('[data-test="glossary-search"] input').fill('Quorum')
		await expect(entries).toHaveCount(1)
		await close.click()
		await expect(dialog).toHaveCount(0)
	})
})
