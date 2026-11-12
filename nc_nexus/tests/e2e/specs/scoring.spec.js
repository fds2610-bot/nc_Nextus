// @ts-check
const { test, expect } = require('@playwright/test')
const { navTo, openDemands } = require('../helpers/nav')
const { adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const tag = () => 'Zs' + Math.random().toString(36).slice(2, 7)

/**
 * Antrag einreichen und annehmen (Status „In Qualifizierung“)
 * @param own
 * @param admin
 * @param title
 * @param type
 */
async function accepted(own, admin, title, type = 'efficiency') {
	const d = await createDemand(own, { title, demandType: type, problemStatement: 'x' })
	await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
	return call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted' }, 200)
}

/**
 * alle Kriterien mit einem Wert, Nutzen optional abweichend
 * @param model
 * @param benefit
 * @param other
 */
function scores(model, benefit, other = 1) {
	return Object.fromEntries(model.set.criteria.map(c => [c.code, c.dimension === 'benefit' ? benefit : other]))
}

test.describe('Bewertung und Priorisierung', () => {
	test('Selbst- und Fremdbewertung, Wertdichte, Rangliste mit Board-Korrektur', async ({ page, browser }) => {
		test.setTimeout(120000)
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const high = await accepted(own, admin, `E2E-Hoch ${t}`)
		const low = await accepted(own, admin, `E2E-Niedrig ${t}`)
		const model = await call(admin, 'get', '/scoring/model')
		try {
			// Antragsteller: Selbstbewertung in der Oberfläche (alle Kriterien 5, Aufwand 3)
			await openDemands(page)
			await page.locator(`[data-test="demand-${high.key}"]`).click()
			await page.locator('[data-test="assess-self"]').click()
			const dialog = page.locator('[data-test="assessment-dialog"]')
			await expect(dialog.locator('[data-test="save-assessment"]')).toBeDisabled()
			for (const c of model.set.criteria) {
				await dialog.locator(`[data-test="criterion-${c.code}"] input[value="5"]`).check()
			}
			await dialog.locator('[data-test="field-effort"] select').selectOption('3')
			// Vorschau: Nutzen 5 · Dringlichkeit 5 · Risiko 5 → (5 × 1,625) / (3 × 1,3125) = 2,06
			await expect(dialog.locator('[data-test="assess-preview"]')).toContainText(/2[.,]06/)
			await dialog.locator('[data-test="save-assessment"]').click()
			await expect(page.locator('[data-test="rating-density"]')).toHaveText(/2[.,]06/)
			// Fremdbewertung nur durch das PMO
			await expect(page.locator('[data-test="assess-review"]')).toHaveCount(0)
			await page.keyboard.press('Escape')

			// PMO: Fremdbewertung per API (niedriger), zweiter Antrag höher bewertet
			await call(admin, 'post', `/demands/${high.id}/assessments`, { kind: 'review', scores: scores(model, 3), effort: 3 }, 201)
			await call(admin, 'post', `/demands/${low.id}/assessments`, { kind: 'review', scores: scores(model, 8, 0), effort: 1 }, 201)
			const rating = await call(own, 'get', `/demands/${high.id}/assessments`)
			expect(rating.current.kind).toBe('review')
			expect(rating.current.selfDensity).toBe(2.06)
			// Bewertungen sind unveränderlich: beide bleiben im Verlauf
			expect(rating.assessments.map(a => a.kind)).toEqual(['self', 'review'])

			// Rangliste: „Niedrig“ hat nach der Fremdbewertung die höhere Wertdichte
			const { context, page: pmo } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
			try {
				await openDemands(pmo)
				await navTo(pmo, 'portfolio', 'nav-ranking')
				const bucket = pmo.locator('[data-test="bucket-C"]')
				const rankOf = async (key) => Number(await bucket.locator(`[data-test="rank-${key}"] [data-test="rank-no"]`).innerText())
				expect(await rankOf(low.key)).toBeLessThan(await rankOf(high.key))

				// Board-Korrektur: „Hoch“ auf Rang 1, nur mit Begründung
				await bucket.locator(`[data-test="override-${high.key}"]`).click()
				const dlg = pmo.locator('[data-test="override-dialog"]')
				await dlg.locator('[data-test="field-override-rank"] input').fill('1')
				await expect(dlg.locator('[data-test="save-override"]')).toBeDisabled()
				await dlg.locator('[data-test="field-override-reason"] textarea').fill('Vorstandsbeschluss Q4')
				await dlg.locator('[data-test="save-override"]').click()
				await expect(bucket.locator(`[data-test="rank-${high.key}"] [data-test="rank-no"]`)).toHaveText('1')
				await expect(bucket.locator(`[data-test="rank-${high.key}"] [data-test="rank-overridden"]`)).toBeVisible()
				await expect(bucket.locator(`[data-test="rank-${high.key}"]`)).toContainText('Vorstandsbeschluss Q4')
			} finally {
				await context.close()
			}
		} finally {
			// Korrektur zurücknehmen, damit andere Läufe nicht beeinflusst werden
			await call(admin, 'put', `/demands/${high.id}/rank-override`, { rank: null }, 200)
			await own.dispose()
			await admin.dispose()
		}
	})

	test('Buckets werden beim Tippen automatisch gespeichert', async ({ browser }) => {
		const admin = await adminApi()
		const before = (await call(admin, 'get', '/scoring/model')).buckets.find(b => b.code === 'D')
		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await openDemands(page)
			await page.locator('[data-test="nav-model"]').click()
			const field = page.locator('[data-test="bucket-budget-D"]')
			await expect(page.locator('[data-test="save-bucket-D"]')).toHaveCount(0)
			await field.fill('1234567')
			await expect(page.locator('[data-test="bucket-status-D"]')).toHaveText(/Gespeichert|Saved/)
			await field.blur()
			// nach dem Verlassen mit Tausendertrennzeichen der Sprache angezeigt
			await expect(field).toHaveValue(/^1\D234\D567$/)
			const after = (await call(admin, 'get', '/scoring/model')).buckets.find(b => b.code === 'D')
			expect(Number(after.targetBudget)).toBe(1234567)
		} finally {
			await call(admin, 'put', '/scoring/buckets/D', { targetSharePct: before.targetSharePct, targetBudget: before.targetBudget })
			await context.close()
			await admin.dispose()
		}
	})

	test('Katalog ändern erzeugt eine neue Version, alte Bewertungen behalten ihre', async ({ browser }) => {
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const before = await call(admin, 'get', '/scoring/model')
		const demand = await accepted(own, admin, `E2E-Version ${tag()}`)
		await call(admin, 'post', `/demands/${demand.id}/assessments`, { kind: 'review', scores: scores(before, 3), effort: 3 }, 201)
		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await openDemands(page)
			await page.locator('[data-test="nav-model"]').click()
			await page.locator('[data-test="edit-criteria"]').click()
			// Gewichte verschieben: N1 +5, N2 −5 → Summe bleibt 100
			const n1 = page.locator('[data-test="model-criterion-N1"] .criterion__weight input')
			const n2 = page.locator('[data-test="model-criterion-N2"] .criterion__weight input')
			const w1 = Number(await n1.inputValue())
			const w2 = Number(await n2.inputValue())
			await n1.fill(String(w1 + 5))
			await expect(page.locator('[data-test="save-criteria"]')).toBeDisabled()
			await n2.fill(String(w2 - 5))
			await page.locator('[data-test="criteria-note"]').fill('E2E: Gewichte angepasst')
			await page.locator('[data-test="save-criteria"]').click()
			// gezielt auf „Version n“ warten – eine bloße Ziffer steckt auch in jedem Datum
			await expect(page.locator('[data-test="criteria-versions"]')).toContainText(`Version ${before.set.version + 1}`)
			const after = await call(admin, 'get', '/scoring/model')
			expect(after.set.version).toBe(before.set.version + 1)
			const old = await call(admin, 'get', `/demands/${demand.id}/assessments`)
			expect(old.current.setId).toBe(before.set.id)
		} finally {
			await context.close()
			// Ausgangsgewichte als weitere Version wiederherstellen (Einstellungen nicht verändert hinterlassen)
			await call(admin, 'post', '/scoring/sets', { criteria: before.set.criteria, note: 'E2E: wiederhergestellt' }, 201)
			await own.dispose()
			await admin.dispose()
		}
	})
})
