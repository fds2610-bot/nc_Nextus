// @ts-check
const { BASE_URL } = require('./env')

/**
 * Neuer Browser-Kontext, per Login-Formular als anderes Konto angemeldet.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string} user
 * @param {string} password
 */
async function loginAs(browser, user, password) {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto('/login')
	await page.fill('#user', user)
	await page.fill('#password', password)
	await page.click('button[type="submit"]')
	await page.waitForURL(url => !url.pathname.includes('/login'), { timeout: 30000 })
	return { context, page }
}

module.exports = { loginAs }
