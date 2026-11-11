// @ts-check
const { test, expect } = require('@playwright/test')
const { PATH, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_OTHER_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const unique = (prefix) => `${prefix} ${Date.now()}`

test.describe('Organisation und Rollen', () => {
	test('Ohne PMO-Rolle keine Organisationsansicht', async ({ page }) => {
		await page.goto('/apps/nc_nexus/')
		await expect(page.locator('[data-test="new-demand"]')).toBeVisible()
		await expect(page.locator('[data-test="nav-org"]')).toHaveCount(0)
	})

	test('Admin legt Einheit an und vergibt eine Rolle', async ({ browser }) => {
		const name = unique('E2E-Bereich')
		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		const admin = await adminApi()
		try {
			await page.goto('/apps/nc_nexus/')
			await page.locator('[data-test="nav-org"]').click()
			await expect(page.locator('[data-test="org-view"]')).toBeVisible()

			await page.locator('[data-test="new-unit"]').click()
			await page.locator('[data-test="field-unit-name"] input').fill(name)
			await page.locator('[data-test="save-unit"]').click()
			await expect(page.locator(`[data-test="unit-${name}"]`)).toBeVisible()

			await page.locator('[data-test="new-role"]').click()
			const dialog = page.locator('[data-test="role-dialog"]')
			await dialog.locator('[data-test="field-principal"] input').fill(E2E_OTHER_USER)
			// Die Optionsliste hängt NcSelect an den body, nicht in den Dialog
			await expect(page.locator('.vs__dropdown-option').first()).toContainText(E2E_OTHER_USER)
			await dialog.locator('[data-test="field-principal"] input').press('Enter')
			await dialog.locator('[data-test="field-role"] select').selectOption('board_member')
			await dialog.locator('[data-test="field-scope"] select').selectOption({ label: name })
			await dialog.locator('[data-test="save-role"]').click()
			// Standard zugeklappt; Klick auf Rolle bzw. Einheit zeigt die Personen
			await expect(page.locator(`[data-test="role-${E2E_OTHER_USER}-board_member"]`)).toHaveCount(0)
			await expect(page.locator(`[data-test="unit-role-${E2E_OTHER_USER}-board_member"]`)).toHaveCount(0)
			await page.locator(`[data-test="unit-${name}"]`).click()
			await expect(page.locator(`[data-test="unit-role-${E2E_OTHER_USER}-board_member"]`)).toBeVisible()
			await page.locator('[data-test="role-group-board_member"]').click()
			await expect(page.locator(`[data-test="role-${E2E_OTHER_USER}-board_member"]`)).toBeVisible()
			// Zuweisung bearbeiten: Gültigkeit setzen, Person bleibt
			await page.locator('[data-test^="edit-role-"]').first().click()
			const edit = page.locator('[data-test="role-dialog"]')
			await expect(edit.locator('[data-test="field-principal-fixed"]')).toContainText(E2E_OTHER_USER)
			await expect(edit.locator('[data-test="save-role"]')).toBeDisabled()
			await edit.locator('input[type="date"]').last().fill('2099-12-31')
			await edit.locator('[data-test="save-role"]').click()
			await expect(edit).toHaveCount(0)
			const saved = (await call(admin, 'get', '/role-assignments')).find(a => a.principalId === E2E_OTHER_USER && a.role === 'board_member' && a.scopeType === 'org_unit')
			expect(saved.validTo).toBe('2099-12-31')
			// Rollen zuerst, die Person darunter eingerückt
			const group = page.locator('[data-test="role-tree"] > *')
			const names = await group.evaluateAll(els => els.map(e => e.getAttribute('data-test')))
			expect(names.indexOf('role-group-board_member')).toBeGreaterThanOrEqual(0)
			expect(names.indexOf(`role-${E2E_OTHER_USER}-board_member`)).toBeGreaterThan(names.indexOf('role-group-board_member'))
		} finally {
			// Aufräumen über die API: Rollen der Einheit, dann die Einheit
			const units = await call(admin, 'get', '/org-units')
			const unit = units.find(u => u.name === name)
			if (unit) {
				for (const a of await call(admin, 'get', '/role-assignments')) {
					if (a.scopeType === 'org_unit' && a.scopeId === unit.id) {
						await call(admin, 'delete', `/role-assignments/${a.id}`)
					}
				}
				await call(admin, 'delete', `/org-units/${unit.id}`)
			}
			await admin.dispose()
			await context.close()
		}
	})

	test('Fremde Anträge sind nur mit Rolle im Bereich sichtbar und dann schreibgeschützt', async ({ page }) => {
		const admin = await adminApi()
		const other = await apiAs(E2E_OTHER_USER)
		const unit = await call(admin, 'post', '/org-units', { name: unique('E2E-Sicht') })
		// eingereicht: Entwürfe sind privat und für niemanden sonst sichtbar
		const draft = await createDemand(other, { title: unique('E2E-Fremd'), demandType: 'innovation', problemStatement: 'Test', orgUnitId: unit.id })
		const demand = await call(other, 'post', `/demands/${draft.id}/submit`, undefined, 200)
		let role = null
		try {
			// ohne Rolle: weder in der Liste noch einzeln abrufbar
			const own = await apiAs(E2E_USER)
			expect((await own.get(`${PATH}/${demand.id}`)).status()).toBe(404)
			await page.goto('/apps/nc_nexus/')
			await expect(page.locator('[data-test="new-demand"]')).toBeVisible()
			await expect(page.locator(`[data-test="demand-${demand.key}"]`)).toHaveCount(0)

			// Board-Rolle auf der Einheit → sichtbar, aber nicht änderbar
			role = await call(admin, 'post', '/role-assignments', {
				role: 'board_member', scopeType: 'org_unit', scopeId: unit.id, principalType: 'user', principalId: E2E_USER,
			})
			await page.reload()
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			const editor = page.locator('[data-test="demand-editor"]')
			await expect(editor.locator('[data-test="field-title"] input')).toBeDisabled()
			await expect(editor.locator('[data-test="save-demand"]')).toHaveCount(0)
			await expect(editor.locator('[data-test="delete-demand"]')).toHaveCount(0)
			await expect(editor).toContainText(unit.name)
			expect((await own.put(`${PATH}/${demand.id}`, { data: { title: 'gekapert' } })).status()).toBe(403)
			await own.dispose()
		} finally {
			if (role) {
				await call(admin, 'delete', `/role-assignments/${role.id}`)
			}
			// Eingereichte Anträge sind nicht löschbar; Antrag und Einheit bleiben als Testdaten stehen
			await admin.dispose()
			await other.dispose()
		}
	})
})
