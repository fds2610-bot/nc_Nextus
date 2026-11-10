// @ts-check
const { test, expect } = require('@playwright/test')
const { adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { E2E_OTHER_USER, E2E_PASSWORD, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const unique = (prefix) => `${prefix} ${Date.now()}`
const isoDay = (offset = 0) => {
	const d = new Date(Date.now() + offset * 86400000)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

test.describe('Entscheidungsregister und PMO-Rechte', () => {
	let admin, role
	test.beforeAll(async () => {
		admin = await adminApi()
		role = await call(admin, 'post', '/role-assignments', {
			role: 'portfolio_manager', scopeType: 'global', principalType: 'user', principalId: E2E_OTHER_USER,
		})
	})
	test.afterAll(async () => {
		await call(admin, 'delete', `/role-assignments/${role.id}`)
		await admin.dispose()
	})

	test('Wiedervorlage vorziehen: außerplanmäßig nur mit Begründung, Register zeigt beide Beschlüsse', async ({ page, browser }) => {
		test.setTimeout(90000)
		const own = await apiAs(E2E_USER)
		const other = await apiAs(E2E_OTHER_USER)
		const draft = await createDemand(own, { title: unique('E2E-Vorziehen'), demandType: 'efficiency', problemStatement: 'Kosten steigen.' })
		const demand = await call(own, 'post', `/demands/${draft.id}/submit`, undefined, 200)
		await call(other, 'post', `/demands/${demand.id}/triage`, { code: 'deferred', resubmissionDate: isoDay(60) }, 200)
		const { context, page: pmo } = await loginAs(browser, E2E_OTHER_USER, E2E_PASSWORD)
		try {
			await pmo.goto('/apps/nc_nexus/')
			await pmo.locator(`[data-test="demand-${demand.key}"]`).click()
			// regulärer Schritt grau, außerplanmäßig möglich
			await expect(pmo.locator('[data-test="open-triage"]')).toBeDisabled()
			await pmo.locator('[data-test="retriage"]').click()
			const dialog = pmo.locator('[data-test="triage-dialog"]')
			await expect(dialog.locator('[data-test="override-notice"]')).toBeVisible()
			await dialog.locator('[data-test="code-accepted"]').click()
			await expect(dialog.locator('[data-test="save-triage"]')).toBeDisabled()
			await dialog.locator('[data-test="field-reason"] textarea').fill('Budget wurde vorzeitig freigegeben.')
			await dialog.locator('[data-test="save-triage"]').click()
			await expect(pmo.locator('[data-test="demand-status"]')).toHaveText(/In qualification|In Qualifizierung/)
			await pmo.keyboard.press('Escape')

			// Register: neuer Beschluss außerplanmäßig, ersetzt den vorigen; Mandat ist das globale PMO
			await pmo.locator('[data-test="nav-decisions"]').click()
			const register = pmo.locator('[data-test="decision-register"]')
			await register.getByRole('textbox').fill(demand.key)
			const rows = register.locator('[data-test^="decision-BES-"]')
			await expect(rows).toHaveCount(2)
			await expect(rows.nth(0)).toContainText('Budget wurde vorzeitig freigegeben.')
			await expect(rows.nth(0).locator('[data-test="out-of-sequence"]')).toBeVisible()
			await expect(rows.nth(0)).toContainText(/replaces BES-|ersetzt BES-/)
			await expect(rows.nth(0).locator('[data-test="decision-mandate"]')).toHaveText(/Portfolio office \(PMO\) · Global|Portfolio-Office \(PMO\) · Global/)
			await expect(rows.nth(1)).toContainText(/replaced|ersetzt/)

			// Antragsteller sieht die Beschlüsse zum eigenen Antrag
			const seen = await call(own, 'get', '/decisions')
			expect(seen.filter(d => d.demand.id === demand.id).map(d => d.outcome)).toEqual(['accepted', 'deferred'])
		} finally {
			await context.close()
			await own.dispose()
			await other.dispose()
		}
	})

	test('PMO bearbeitet einen eingereichten Antrag, der Verlauf zeigt das Konto', async ({ browser }) => {
		const own = await apiAs(E2E_USER)
		const draft = await createDemand(own, { title: unique('E2E-PMO-Edit'), demandType: 'strategic', problemStatement: 'x' })
		const demand = await call(own, 'post', `/demands/${draft.id}/submit`, undefined, 200)
		const { context, page } = await loginAs(browser, E2E_OTHER_USER, E2E_PASSWORD)
		try {
			await page.goto('/apps/nc_nexus/')
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			const editor = page.locator('[data-test="demand-editor"]')
			await expect(editor.locator('[data-test="submit-demand"]')).toHaveCount(0)
			await editor.locator('[data-test="field-description"] textarea').fill('Vom PMO präzisiert')
			await expect(editor.locator('[data-test="close-demand"]')).toHaveText(/Cancel|Abbrechen/)
			await editor.locator('[data-test="save-demand"]').click()
			await expect(editor.locator('[data-test="close-demand"]')).toHaveText(/Close|Schließen/)
			const update = editor.locator('[data-test="demand-history"] [data-test="history-update"]')
			await expect(update).toContainText(E2E_OTHER_USER)
			// Status unverändert, Antragsteller kann weiterhin nichts ändern
			const fresh = await call(own, 'get', `/demands/${demand.id}`)
			expect(fresh.status).toBe('submitted')
			expect(fresh.can.edit).toBe(false)
			expect(fresh.description).toBe('Vom PMO präzisiert')
		} finally {
			await context.close()
			await own.dispose()
		}
	})
})
