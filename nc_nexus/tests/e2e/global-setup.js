// @ts-check
// Einmalig per Login-Formular anmelden und den Session-Zustand (Cookies)
// als storageState speichern — alle Tests starten damit eingeloggt.
const { chromium } = require('@playwright/test')
const { BASE_URL, E2E_USER, E2E_PASSWORD } = require('./helpers/env')

module.exports = async () => {
	const browser = await chromium.launch()
	const page = await browser.newPage({ baseURL: BASE_URL })
	await page.goto('/login')
	await page.fill('#user', E2E_USER)
	await page.fill('#password', E2E_PASSWORD)
	await page.click('button[type="submit"]')
	await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 30000 })
	await page.context().storageState({ path: '.auth/user.json' })
	await browser.close()
}
