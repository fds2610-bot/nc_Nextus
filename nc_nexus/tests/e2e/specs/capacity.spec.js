// @ts-check
const { test, expect } = require('@playwright/test')
const { navTo, openDemands } = require('../helpers/nav')
const { adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const code = () => 'E2E' + Math.random().toString(36).slice(2, 7).toUpperCase()
const thisMonth = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/**
 * Pool samt Bedarf wieder entfernen (Bedarf über die PMO-Rechte des Admins)
 * @param admin
 * @param poolCode
 */
async function cleanup(admin, poolCode) {
	const { pools } = await call(admin, 'get', '/pools')
	const pool = pools.find(p => p.code === poolCode)
	if (!pool) {
		return
	}
	for (const demand of await call(admin, 'get', '/demands')) {
		const needs = await call(admin, 'get', `/demands/${demand.id}/needs`)
		if (needs.some(n => n.poolId === pool.id)) {
			await call(admin, 'put', `/demands/${demand.id}/needs`, { needs: needs.filter(n => n.poolId !== pool.id) })
		}
	}
	await call(admin, 'delete', `/pools/${pool.id}`)
}

test.describe('Ressourcenpools und Kapazität', () => {
	test('Ohne Governance-Rolle keine Kapazitätsansicht', async ({ page }) => {
		await openDemands(page)
		await expect(page.locator('[data-test="new-demand"]')).toBeVisible()
		await expect(page.locator('[data-test="nav-capacity"]')).toHaveCount(0)
	})

	test('PMO legt einen Pool an und pflegt die Kapazität', async ({ browser }) => {
		const poolCode = code()
		const admin = await adminApi()
		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await openDemands(page)
			await navTo(page, 'portfolio', 'nav-capacity')
			await page.locator('[data-test="new-pool"]').click()
			const dialog = page.locator('[data-test="pool-dialog"]')
			await dialog.locator('[data-test="field-pool-name"] input').fill(`E2E-Pool ${poolCode}`)
			await dialog.locator('[data-test="field-pool-code"] input').fill(poolCode)
			await dialog.locator('[data-test="field-pool-bottleneck"] input').check()
			await dialog.locator('[data-test="save-pool"]').click()

			// Dialog öffnet sich für den neuen Pool: Kapazität des aktuellen Monats erfassen
			const row = page.locator(`[data-test="pool-capacity"] [data-test="capacity-${thisMonth()}"]`)
			await row.locator('input').first().fill('2')
			await page.locator('[data-test="save-pool"]').click()
			await expect(page.locator('[data-test="pool-dialog"]')).toHaveCount(0)

			const heatRow = page.locator(`[data-test="heatmap-${poolCode}"]`)
			await expect(heatRow).toContainText(/bottleneck|Engpass/)
			// 2 FTE ergeben eine Projekt-Kapazität > 0 und ohne Bedarf 0 %
			await expect(heatRow.locator(`[data-test="cell-${poolCode}-${thisMonth()}"]`)).toContainText('0 %')
		} finally {
			await context.close()
			await cleanup(admin, poolCode)
			await admin.dispose()
		}
	})

	test('Bedarf im Antrag erscheint in der Heatmap und in der Aufschlüsselung', async ({ page, browser }) => {
		test.setTimeout(90000)
		const poolCode = code()
		const admin = await adminApi()
		const pool = await call(admin, 'post', '/pools', { code: poolCode, name: `E2E-Pool ${poolCode}`, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
		// 1 FTE × 20 Arbeitstage = 20 PT Projekt-Kapazität (ohne Abzüge)
		await call(admin, 'put', `/pools/${pool.id}/capacity/${thisMonth()}`, { fte: 1, workingDays: 20 }, 200)
		const own = await apiAs(E2E_USER)
		try {
			// Antragsteller erfasst 15 PT im Antrag und reicht ein
			const title = `E2E-Bedarf ${poolCode}`
			await openDemands(page)
			await page.locator('[data-test="new-demand"]').click()
			const editor = page.locator('[data-test="demand-editor"]')
			await editor.locator('[data-test="field-title"] input').fill(title)
			await editor.locator('[data-test="field-problem"] textarea').fill('Ohne Ausbau fehlt Kapazität.')
			await editor.locator('[data-test="add-need"]').click()
			const need = editor.locator('[data-test="need-0"]')
			await need.locator('select').selectOption({ label: `E2E-Pool ${poolCode}` })
			await need.locator('input[type="month"]').fill(thisMonth())
			await need.locator('input.needs__days').fill('15')
			await editor.locator('[data-test="submit-demand"]').click()
			await expect(editor.locator('[data-test="demand-status"]')).toHaveText(/Submitted|Eingereicht/)
			const demand = (await call(own, 'get', '/demands')).find(d => d.title === title)
			expect((await call(own, 'get', `/demands/${demand.id}/needs`)).map(n => n.personDays)).toEqual([15])

			// PMO sieht 75 % Auslastung und den Antrag in der Aufschlüsselung
			const { context, page: pmo } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
			try {
				await openDemands(pmo)
				await navTo(pmo, 'portfolio', 'nav-capacity')
				const cell = pmo.locator(`[data-test="cell-${poolCode}-${thisMonth()}"]`)
				await expect(cell).toContainText('75 %')
				await cell.click()
				await expect(pmo.locator('[data-test="cell-detail"]')).toContainText(demand.key)
			} finally {
				await context.close()
			}
		} finally {
			await own.dispose()
			await cleanup(admin, poolCode)
			await admin.dispose()
		}
	})
})
