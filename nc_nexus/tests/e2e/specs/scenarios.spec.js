// @ts-check
const { test, expect } = require('@playwright/test')
const { API, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const month = (offset = 0) => {
	const d = new Date()
	d.setDate(1)
	d.setMonth(d.getMonth() + offset)
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
const tag = () => 'S' + Math.random().toString(36).slice(2, 6).toUpperCase()

/**
 * Antrag mit Bedarf im Pool; optional triagiert und bewertet (steht dann in der Rangliste).
 *
 * @param {import('@playwright/test').APIRequestContext} own Antragsteller
 * @param {import('@playwright/test').APIRequestContext} admin PMO
 * @param {{title: string, unitId: number, poolId: number, ranked: boolean}} spec Angaben
 */
async function demand(own, admin, { title, unitId, poolId, ranked }) {
	const d = await createDemand(own, { title, demandType: 'efficiency', problemStatement: 'Szenario prüfen.', orgUnitId: unitId })
	await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId, period: month(1), personDays: 5 }] }, 200)
	await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
	if (ranked) {
		await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted', projectClass: 'S' }, 200)
		const model = await call(admin, 'get', '/scoring/model')
		await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores: Object.fromEntries(model.set.criteria.map(c => [c.code, 3])), effort: 3 }, 201)
	}
	return call(admin, 'get', `/demands/${d.id}`)
}

test.describe('Szenarioanalyse (POR-003)', () => {
	test('Was-wäre-wenn: Kapazität, Neuaufnahme, Herausnehmen; Vorlegen friert ein, Annahme erzeugt Maßnahmen', async ({ browser }) => {
		test.setTimeout(180000)
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Szenario ${t}` })
			const code = `E2E${t}`
			// Engpass-Pool ohne Kapazität: alles mit Bedarf darin wartet
			const pool = await call(admin, 'post', '/pools', { code, name: `E2E-Szenariopool ${t}`, isBottleneck: true, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
			const a = await demand(own, admin, { title: `E2E-Szenario ${t} A`, unitId: unit.id, poolId: pool.id, ranked: true })
			const b = await demand(own, admin, { title: `E2E-Szenario ${t} B`, unitId: unit.id, poolId: pool.id, ranked: true })
			const neu = await demand(own, admin, { title: `E2E-Szenario ${t} Neu`, unitId: unit.id, poolId: pool.id, ranked: false })

			// nur Governance-Rollen
			expect((await own.get(`${API}/scenarios`)).status()).toBe(403)

			const ui = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
			const page = ui.page
			try {
				await page.goto('/apps/nc_nexus/')
				await page.locator('[data-test="nav-scenarios"]').click()
				const view = page.locator('[data-test="scenarios-view"]')
				await view.locator('[data-test="new-scenario"]').click()
				const dialog = page.locator('[data-test="scenario-dialog"]')
				await dialog.locator('[data-test="field-scenario-name"] input').fill(`E2E-Szenario ${t}`)
				await dialog.locator('[data-test="save-scenario"]').click()
				await expect(view.locator('[data-test="scenario-name"]')).toHaveText(`E2E-Szenario ${t}`)
				await expect(view.locator('[data-test="scenario-status"]')).toContainText(/Draft|Entwurf/)
				await expect(view.locator('[data-test="changes-empty"]')).toBeVisible()

				// Kapazität +5 FTE ab nächstem Monat über den Dialog
				await view.locator('[data-test="add-change"]').click()
				const change = page.locator('[data-test="change-dialog"]')
				await change.locator('[data-test="field-change-type"] select').selectOption('change_capacity')
				await change.locator('[data-test="field-change-target"] select').selectOption(String(pool.id))
				await change.locator('[data-test="field-change-fte"] input').fill('5')
				await change.locator('[data-test="field-change-from"] select').selectOption(month(1))
				await change.locator('[data-test="save-change"]').click()
				await expect(change).toHaveCount(0)
				await expect(view.locator('[data-test="change-change_capacity"]')).toContainText(code)

				// beide Vorhaben steigen über die Linie
				for (const d of [a, b]) {
					const row = view.locator(`[data-test="item-${d.key}"]`)
					await expect(row.locator('[data-test="effect"]')).toContainText(/rises above the line|steigt über die Linie/)
				}
				await expect(view.locator(`[data-test="pool-${code}"]`)).toContainText('→')
			} finally {
				await ui.context.close()
			}

			// Weitere Änderungen per API: Neuaufnahme mit angenommener Wertdichte, B herausnehmen
			const list = await call(admin, 'get', '/scenarios')
			const id = list.scenarios.find(s => s.name === `E2E-Szenario ${t}`).id
			let s = await call(admin, 'get', `/scenarios/${id}`)
			expect(s.options.addable.map(d => d.id)).toContain(neu.id)
			expect(s.options.pools.map(p => p.id)).toContain(pool.id)
			s = await call(admin, 'post', `/scenarios/${id}/changes`, { changeType: 'add_project', targetRef: String(neu.id), parameters: { bucket: 'C', density: 50 } }, 200)
			s = await call(admin, 'post', `/scenarios/${id}/changes`, { changeType: 'remove_project', targetRef: String(b.id), parameters: {} }, 200)
			const items = Object.fromEntries(s.comparison.items.map(i => [i.id, i]))
			expect(items[neu.id]).toMatchObject({ effect: 'added', bucketScenario: 'C', base: null })
			expect(items[neu.id].scenario.state).toMatch(/startable|shifted/)
			expect(items[b.id]).toMatchObject({ effect: 'removed', scenario: null })
			expect(items[a.id].effect).toBe('rises')
			// ungültig: Antrag, der schon in der Rangliste steht, lässt sich nicht aufnehmen
			const bad = await admin.post(`${API}/scenarios/${id}/changes`, { data: { changeType: 'add_project', targetRef: String(a.id) } })
			expect(bad.status()).toBe(400)
			// nichts davon verändert den Ist-Stand
			const cut = await call(admin, 'get', '/capacity/cut')
			expect(cut.results[a.id].state).toBe('waiting')
			expect(cut.results[neu.id]).toBeUndefined()

			// Vorlegen friert ein
			s = await call(admin, 'post', `/scenarios/${id}/present`, {}, 200)
			expect(s.status).toBe('presented')
			expect(s.baseSnapshotAt).toBeGreaterThan(0)
			expect(s.options).toBeNull()
			expect((await admin.post(`${API}/scenarios/${id}/changes`, { data: { changeType: 'remove_project', targetRef: String(a.id) } })).status()).toBe(400)

			// Annahme über die Oberfläche
			const pmo = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
			try {
				const p = pmo.page
				await p.goto('/apps/nc_nexus/')
				await p.locator('[data-test="nav-scenarios"]').click()
				const view = p.locator('[data-test="scenarios-view"]')
				await view.locator(`[data-test="scenario-${id}"]`).click()
				await expect(view.locator('[data-test="scenario-basis"]')).toContainText(/frozen|eingefroren/)
				await expect(view.locator('[data-test="summary-added"] .nx-kpi__num')).toHaveText('1')
				await view.locator('[data-test="adopt-scenario"]').click()
				const decide = p.locator('[data-test="decide-dialog"]')
				await decide.locator('[data-test="field-decide-reason"] textarea').fill('Externe Verstärkung beschlossen')
				await decide.locator('[data-test="confirm-decide"]').click()
				await expect(decide).toHaveCount(0)
				await expect(view.locator('[data-test="scenario-status"]')).toContainText(/Adopted|Angenommen/)
				await expect(view.locator('[data-test="scenario-decision"]')).toContainText('Externe Verstärkung beschlossen')
				// Kapazitätsänderung als Umsetzungsaufgabe
				const task = view.locator('[data-test="task-0"]')
				await expect(task).toContainText(code)
				await task.locator('.checkbox-radio-switch__content, label').first().click()
				await expect(task.locator('input[type="checkbox"]')).toBeChecked()
			} finally {
				await pmo.context.close()
			}

			// Änderungen an Vorhaben wurden Maßnahmen in der Beschlussverfolgung
			const conditions = await call(admin, 'get', '/conditions')
			const actions = conditions.filter(c => c.originType === 'scenario' && c.originId === id)
			expect(actions.map(c => c.demandId).sort()).toEqual([b.id, neu.id].sort())
			expect(actions.every(c => c.kind === 'action' && c.ownerId === ADMIN_USER)).toBe(true)
			s = await call(admin, 'get', `/scenarios/${id}`)
			expect(s.comparison.tasks[0].done).toBe(true)
			// Rang und Allokationen bleiben unberührt
			expect((await call(admin, 'get', '/capacity/cut')).results[b.id]).toBeDefined()
		} finally {
			await own.dispose()
			await admin.dispose()
		}
	})
})
