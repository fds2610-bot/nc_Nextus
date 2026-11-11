// @ts-check
const { expect } = require('@playwright/test')

/**
 * Eintrag in einer eingeklappten Navigationsgruppe (Triage, Status, Antragstypen) anklicken;
 * die Gruppe wird bei Bedarf aufgeklappt.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'triage'|'status'|'types'} group
 * @param {string} item data-test des Eintrags
 */
async function navTo(page, group, item) {
	const entry = page.locator(`[data-test="${item}"]`)
	if (!await entry.isVisible()) {
		await page.locator(`[data-test="nav-group-${group}"] > .app-navigation-entry`).first().click()
		await expect(entry).toBeVisible()
	}
	await entry.click()
}

module.exports = { navTo }
