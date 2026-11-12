// @ts-check
const { test, expect } = require('@playwright/test')
const { navTo, openDemands } = require('../helpers/nav')
const { API, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { E2E_OTHER_USER, E2E_PASSWORD, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const month = (offset = 0) => {
	const d = new Date()
	d.setDate(1)
	d.setMonth(d.getMonth() + offset)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
const inDays = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10)
const tag = () => 'B' + Math.random().toString(36).slice(2, 6).toUpperCase()

/**
 * Freigegebenes Projekt (Klasse M, Direktbeschlüsse) mit zwei Nutzenpositionen von E2E_USER.
 *
 * @param {import('@playwright/test').APIRequestContext} own Antragsteller
 * @param {import('@playwright/test').APIRequestContext} admin PMO
 * @param {{title: string, unitId: number, poolId: number}} spec Angaben
 */
async function released(own, admin, { title, unitId, poolId }) {
	const d = await createDemand(own, { title, demandType: 'efficiency', problemStatement: 'Nutzen verfolgen.', orgUnitId: unitId })
	await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId, period: month(), personDays: 2 }] }, 200)
	await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
	await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted', projectClass: 'M' }, 200)
	const model = await call(admin, 'get', '/scoring/model')
	await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores: Object.fromEntries(model.set.criteria.map(c => [c.code, 3])), effort: 3 }, 201)
	await call(own, 'put', `/demands/${d.id}/business-case`, {
		content: {
			costs: [{ label: 'Umsetzung', kind: 'capex', amount: 50000 }],
			benefits: [
				{ type: 'cost_saving', title: 'Wartung entfällt', amount: 40000, metric: 'Wartungskosten p. a.', baseline: '40 000 €', target: '0 €', ownerUserId: E2E_USER },
				{ type: 'qualitative', title: 'Zufriedenheit', metric: 'Umfragewert', baseline: '3,1', target: '4,0', ownerUserId: E2E_USER },
			],
		},
	}, 200)
	for (const step of [{ step: 'candidate' }, { step: 'g2', outcome: 'approved' }, { step: 'g3', outcome: 'approved' }]) {
		await call(admin, 'post', `/demands/${d.id}/gate`, step, 200)
	}
	return call(admin, 'get', `/demands/${d.id}`)
}

const rowOf = async (api, demandId, key) => (await call(api, 'get', '/benefits')).rows.find(r => r.demandId === demandId && r.key === key)

test.describe('Nutzenreviews (Stufe 5, BEN-002)', () => {
	test('Prognose, Messzeitpunkte, Reviews mit Abweichungsanalyse, Bilanz und Korrekturfaktor', async () => {
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const other = await apiAs(E2E_OTHER_USER)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Nutzen ${t}` })
			const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Pool ${t}`, isBottleneck: false, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
			const d = await released(own, admin, { title: `E2E-Nutzen ${t}`, unitId: unit.id, poolId: pool.id })

			// Positionen haben stabile Schlüssel; der bei G3 gesetzte Reviewtermin ist der erste Messzeitpunkt
			const cockpit = await call(admin, 'get', `/projects/${d.id}`)
			let row = await rowOf(own, d.id, 'p1')
			expect(row.committed).toBe(true)
			expect(row.realizationStatus).toBe('not_started')
			expect(row.reviewDates).toEqual([cockpit.project.benefitReviewDue])
			expect(row.canTrack).toBe(true)
			expect((await rowOf(own, d.id, 'p2')).amount).toBeNull()

			// fremdes Konto ohne Leserecht sieht die Position nicht und darf sie nicht pflegen
			expect(await rowOf(other, d.id, 'p1')).toBeUndefined()
			expect((await other.put(`${API}/demands/${d.id}/benefits/p1`, { data: { tracking: { realizationStatus: 'on_track', reviewDates: [inDays(1)] } } })).status()).toBe(404)

			// Benefit Owner: Prognose und zwei Messzeitpunkte (der erste ist erreicht)
			row = await call(own, 'put', `/demands/${d.id}/benefits/p1`, { tracking: { realizationStatus: 'on_track', forecastAmount: 30000, forecast: '10 000 €', reviewDates: [inDays(200), inDays(-1)] } })
			expect(row.reviewDates).toEqual([inDays(-1), inDays(200)])
			expect(row.forecastAmount).toBe(30000)
			expect(row.reviewDue).toBe(true)
			expect(row.nextReview).toBe(inDays(-1))

			// Review: Abweichung ohne Begründung und verfehlter Nutzen ohne Maßnahme werden abgelehnt
			const base = { reviewDate: inDays(0), actualValue: '20 000 €', actualAmount: 20000 }
			let refused = await own.post(`${API}/demands/${d.id}/benefits/p1/reviews`, { data: { review: base } })
			expect(refused.status()).toBe(400)
			expect((await refused.json()).ocs.data.message).toMatch(/explanation|Begründung/)
			refused = await own.post(`${API}/demands/${d.id}/benefits/p1/reviews`, { data: { review: { ...base, actualAmount: 10000, explanation: 'Vertrag läuft weiter' } } })
			expect(refused.status()).toBe(400)
			expect((await refused.json()).ocs.data.message).toMatch(/action|Maßnahme/)

			// 50 % realisiert → teilweise, Status gefährdet, nächster Messzeitpunkt rückt nach
			row = await call(own, 'post', `/demands/${d.id}/benefits/p1/reviews`, { review: { ...base, explanation: 'Kündigungsfrist verlängert', lessonsLearned: 'Fristen früher prüfen' } })
			expect(row.reviews).toHaveLength(1)
			expect(row.reviews[0].achievementPct).toBe(50)
			expect(row.reviews[0].assessment).toBe('partial')
			expect(row.realizationStatus).toBe('at_risk')
			expect(row.nextReview).toBe(inDays(200))
			expect(row.reviewDue).toBe(false)

			// verfehlter qualitativer Nutzen mit Maßnahme → Maßnahme mit Herkunft „benefit_review“
			row = await call(own, 'post', `/demands/${d.id}/benefits/p2/reviews`, {
				review: {
					reviewDate: inDays(0), actualValue: '3,0', achievementPct: 10, explanation: 'Schulung fehlte',
					actions: [{ title: `Schulung nachholen ${t}`, ownerId: E2E_USER, dueDate: inDays(30) }],
				},
			})
			expect(row.reviews[0].assessment).toBe('missed')
			expect(row.realizationStatus).toBe('missed')
			const actions = await call(admin, 'get', '/conditions')
			const action = actions.find(c => c.title === `Schulung nachholen ${t}`)
			expect(action.originType).toBe('benefit_review')
			expect(action.kind).toBe('action')

			// Bilanz und Korrekturfaktor
			const register = await call(own, 'get', '/benefits')
			expect(register.balance.total.realized).toBeGreaterThanOrEqual(20000)
			expect(register.factors.byType.cost_saving.count).toBeGreaterThanOrEqual(1)
			const factors = await call(own, 'get', '/benefits/factors')
			expect(factors.byType.cost_saving.factor).toBeLessThanOrEqual(1)

			// Verlauf im Audit-Log
			const history = await call(admin, 'get', `/demands/${d.id}/history`)
			expect(history.map(h => h.action)).toEqual(expect.arrayContaining(['benefit_tracking', 'benefit_review']))
		} finally {
			await own.dispose()
			await other.dispose()
			await admin.dispose()
		}
	})

	test('Oberfläche: Register mit Filter, Dialog mit Prognose und Review', async ({ browser }) => {
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const ui = await loginAs(browser, E2E_USER, E2E_PASSWORD)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Nutzen-UI ${t}` })
			const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Pool ${t}`, isBottleneck: false, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
			const d = await released(own, admin, { title: `E2E-Nutzen-UI ${t}`, unitId: unit.id, poolId: pool.id })
			await call(own, 'put', `/demands/${d.id}/benefits/p1`, { tracking: { realizationStatus: 'on_track', reviewDates: [inDays(-2)] } })

			const page = ui.page
			await openDemands(page)
			await navTo(page, 'steering', 'nav-benefits')
			const register = page.locator('[data-test="benefit-register"]')
			await expect(register.locator('[data-test="benefit-balance"]')).toBeVisible()

			// Filter „Reviews fällig“ zeigt die Position
			await register.locator('[data-test="filter-due"]').click()
			const row = register.locator(`[data-test="benefit-row-${d.key}"]`)
			await expect(row).toHaveCount(1)
			await expect(row).toContainText('Wartung entfällt')

			// Dialog: Prognose ändern, Footer bleibt beim Scrollen stehen
			await row.click()
			const dialog = page.locator('[data-test="benefit-dialog"]')
			await expect(dialog).toBeVisible()
			await dialog.locator('[data-test="benefit-forecast"]').fill('5 000 €')
			await dialog.locator('[data-test="benefit-save"]').click()
			await expect(dialog.locator('[data-test="benefit-save"]')).toBeDisabled()

			// Review erfassen: 100 % → erreicht
			await dialog.locator('[data-test="benefit-new-review"]').click()
			const form = page.locator('[data-test="benefit-review-form"]')
			await form.locator('[data-test="field-actualValue"] input').fill('0 €')
			await form.locator('[data-test="field-actualAmount"] input').fill('40000')
			await form.locator('[data-test="form-save"]').click()
			await expect(form).toBeHidden()
			await expect(dialog.locator('[data-test="benefit-reviews"]')).toContainText('100 %')
			await expect(dialog.locator('[data-test="benefit-status"]')).toHaveValue('realized')
			await dialog.getByRole('button', { name: /Close|Schließen/ }).click()
			await expect(dialog).toBeHidden()

			// Nach dem Review ist die Position nicht mehr fällig
			await expect(row).toHaveCount(0)
		} finally {
			await ui.context.close()
			await own.dispose()
			await admin.dispose()
		}
	})
})
