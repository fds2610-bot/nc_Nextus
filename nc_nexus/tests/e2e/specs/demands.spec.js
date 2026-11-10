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
		// ungespeicherte Eingabe → „Abbrechen“, nach dem Speichern → „Schließen“
		await expect(editor.locator('[data-test="close-demand"]')).toHaveText(/Cancel|Abbrechen/)
		await editor.locator('[data-test="save-demand"]').click()
		await expect(editor.locator('[data-test="demand-status"]')).toBeVisible()
		await expect(editor.locator('[data-test="close-demand"]')).toHaveText(/Close|Schließen/)
		const key = await editor.locator('[data-test="demand-key"]').innerText()
		expect(key).toMatch(/^DEM-\d{4}-\d{4}$/)

		await editor.locator('[data-test="field-problem"] textarea').fill('Ohne Antrag keine Entscheidung.')
		await editor.locator('[data-test="submit-demand"]').click()
		await expect(editor.locator('[data-test="field-title"] input')).toBeDisabled()
		await expect(editor.locator('[data-test="field-type"] select')).toBeDisabled()
		await expect(editor.locator('[data-test="demand-history"] li')).toHaveCount(3)
		await page.keyboard.press('Escape')

		const row = page.locator(`[data-test="demand-${key}"]`)
		await expect(row).toContainText(title)
		await expect(row).toContainText(/Submitted|Eingereicht/)
	})

	test('Filter nach Status und Suche in der Navigation', async ({ page }) => {
		const api = await apiAs(E2E_USER)
		const title = unique('E2E-Filter')
		const demand = await createDemand(api, { title, demandType: 'lifecycle' })
		await page.reload()
		const row = page.locator(`[data-test="demand-${demand.key}"]`)

		await page.locator('[data-test="nav-status-submitted"]').click()
		await expect(row).toHaveCount(0)
		await page.locator('[data-test="nav-status-draft"]').click()
		await expect(row).toBeVisible()

		await page.locator('[data-test="nav-all"]').click()
		await page.getByRole('textbox', { name: /Search demands|Anträge durchsuchen/ }).fill(title)
		await expect(page.locator('[data-test^="demand-DEM-"]')).toHaveCount(1)
		await expect(page.locator('[data-test="kpis"]')).toBeVisible()

		expect((await api.delete(`${PATH}/${demand.id}`)).status()).toBe(200)
		await api.dispose()
	})

	test('Entwurf löschen', async ({ page }) => {
		const api = await apiAs(E2E_USER)
		const demand = await createDemand(api, { title: unique('E2E-Löschen'), demandType: 'efficiency' })
		await page.reload()
		await page.locator(`[data-test="demand-${demand.key}"]`).click()
		await page.locator('[data-test="delete-demand"]').click()
		await expect(page.locator(`[data-test="demand-${demand.key}"]`)).toHaveCount(0)
		expect((await api.get(`${PATH}/${demand.id}`)).status()).toBe(404)

		// Die Nummer des gelöschten Entwurfs wird nicht erneut vergeben, der Verlauf beginnt neu
		const next = await createDemand(api, { title: unique('E2E-Nachfolger'), demandType: 'efficiency' })
		expect(next.key).not.toBe(demand.key)
		const history = (await (await api.get(`${PATH}/${next.id}/history`)).json()).ocs.data
		expect(history.map(e => e.action)).toEqual(['create'])
		expect((await api.delete(`${PATH}/${next.id}`)).status()).toBe(200)
		await api.dispose()
	})

	test('Esc schließt den Dialog auch mit Fokus im Eingabefeld', async ({ page }) => {
		await page.locator('[data-test="new-demand"]').click()
		const editor = page.locator('[data-test="demand-editor"]')
		await editor.locator('[data-test="field-title"] input').fill('nur getippt')
		await editor.locator('[data-test="field-title"] input').press('Escape')
		await expect(editor).toHaveCount(0)
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
