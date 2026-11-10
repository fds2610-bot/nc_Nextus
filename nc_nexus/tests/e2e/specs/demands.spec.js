// @ts-check
const { test, expect } = require('@playwright/test')
const { PATH, apiAs, createDemand } = require('../helpers/api')
const { E2E_OTHER_USER, E2E_USER } = require('../helpers/env')

const unique = (prefix) => `${prefix} ${Date.now()}`

test.describe('Antragseingang', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/nc_nexus/')
		await expect(page.getByRole('heading', { level: 2 }).first()).toBeVisible()
	})

	test('App erscheint in der Navigation und lädt ohne Fehler', async ({ page }) => {
		const errors = []
		page.on('pageerror', e => errors.push(e.message))
		await page.reload()
		await expect(page.locator('[data-test="new-demand"]')).toBeVisible()
		expect(errors).toEqual([])
	})

	test('Entwurf anlegen, ändern und einreichen', async ({ page }) => {
		const title = unique('E2E-Antrag')
		await page.locator('[data-test="new-demand"]').click()
		const editor = page.locator('[data-test="demand-editor"]')
		await expect(editor).toBeVisible()

		await editor.locator('[data-test="field-title"] input').fill(title)
		// Einreichen ohne Problembeschreibung ist gesperrt
		await expect(editor.locator('[data-test="submit-demand"]')).toBeDisabled()
		await editor.locator('[data-test="save-demand"]').click()
		await expect(editor.locator('[data-test="demand-status"]')).toBeVisible()
		const key = (await editor.locator('.editor__title p').innerText()).split(' ')[0]
		expect(key).toMatch(/^DEM-\d{4}-\d{4}$/)

		await editor.locator('[data-test="field-problem"] textarea').fill('Ohne Antrag keine Entscheidung.')
		await editor.locator('[data-test="submit-demand"]').click()
		await expect(editor.locator('[data-test="field-title"] input')).toBeDisabled()
		await expect(editor.locator('[data-test="demand-history"] li')).toHaveCount(3)
		await page.keyboard.press('Escape')

		const row = page.locator(`[data-test="demand-${key}"]`)
		await expect(row).toContainText(title)
		await expect(row).toContainText(/Submitted|Eingereicht/)
	})

	test('Entwurf löschen', async ({ page }) => {
		const api = await apiAs(E2E_USER)
		const demand = await createDemand(api, { title: unique('E2E-Löschen'), demandType: 'efficiency' })
		await page.reload()
		await page.locator(`[data-test="demand-${demand.key}"]`).click()
		await page.locator('[data-test="delete-demand"]').click()
		await expect(page.locator(`[data-test="demand-${demand.key}"]`)).toHaveCount(0)
		expect((await api.get(`${PATH}/${demand.id}`)).status()).toBe(404)
		await api.dispose()
	})

	test('Antrag eines anderen Kontos ist schreibgeschützt', async ({ page }) => {
		const other = await apiAs(E2E_OTHER_USER)
		const demand = await createDemand(other, { title: unique('E2E-Fremd'), demandType: 'innovation' })
		await page.reload()
		await page.locator(`[data-test="demand-${demand.key}"]`).click()
		const editor = page.locator('[data-test="demand-editor"]')
		await expect(editor.locator('[data-test="field-title"] input')).toBeDisabled()
		await expect(editor.locator('[data-test="save-demand"]')).toHaveCount(0)
		await expect(editor.locator('[data-test="delete-demand"]')).toHaveCount(0)

		// Auch die API verweigert die Änderung
		const own = await apiAs(E2E_USER)
		expect((await own.put(`${PATH}/${demand.id}`, { data: { title: 'gekapert' } })).status()).toBe(403)
		await own.dispose()

		// Aufräumen: Entwurf des anderen Kontos löschen
		expect((await other.delete(`${PATH}/${demand.id}`)).status()).toBe(200)
		await other.dispose()
	})

	test('Kopf- und Fußzeile des Dialogs bleiben beim Scrollen stehen', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 600 })
		await page.locator('[data-test="new-demand"]').click()
		const editor = page.locator('[data-test="demand-editor"]')
		await editor.locator('[data-test="field-description"] textarea').fill('Zeile\n'.repeat(60))
		const head = editor.locator('.editor__head')
		const save = editor.locator('[data-test="save-demand"]')
		const before = [await head.boundingBox(), await save.boundingBox()]
		await editor.locator('.editor__body').evaluate(el => { el.scrollTop = el.scrollHeight })
		const after = [await head.boundingBox(), await save.boundingBox()]
		expect(after[0]?.y).toBe(before[0]?.y)
		expect(after[1]?.y).toBe(before[1]?.y)
		await expect(save).toBeInViewport()
	})
})
