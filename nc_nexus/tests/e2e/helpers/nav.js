// @ts-check
const { expect } = require('@playwright/test')

/**
 * Eintrag in einer eingeklappten Navigationsgruppe (Triage, Status, Antragstypen, Portfolio, Steuerung, Gremien) anklicken;
 * die Gruppe wird bei Bedarf aufgeklappt.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'triage'|'status'|'types'|'portfolio'|'steering'|'decisions'} group
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

/**
 * App öffnen und die Antragsliste wählen (Governance-Rollen starten sonst auf der Übersicht).
 *
 * @param {import('@playwright/test').Page} page
 */
async function openDemands(page) {
	await page.goto('/apps/nc_nexus/')
	await page.locator('[data-test="nav-all"]').click()
}

module.exports = { navTo, openDemands }
