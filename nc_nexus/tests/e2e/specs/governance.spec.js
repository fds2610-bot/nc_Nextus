// @ts-check
const { test, expect } = require('@playwright/test')
const { PATH, adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_OTHER_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const thisMonth = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
const inDays = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10)
const tag = () => 'Z' + Math.random().toString(36).slice(2, 6).toUpperCase()

/**
 * Vorhaben bis „Kandidat“ vorbereiten. Die Klasse trennt die Tests von der übrigen Konfiguration:
 * XS bekommt im Gremiums-Test ein eigenes Entscheidungsrecht, M bleibt ohne Recht (Direktbeschluss);
 * die Beispieldaten weisen nur Klasse L einem Gremium zu.
 *
 * @param {import('@playwright/test').APIRequestContext} own Antragsteller
 * @param {import('@playwright/test').APIRequestContext} admin PMO
 * @param {{title: string, unitId: number, poolId: number, projectClass: string}} demand Angaben
 */
async function candidate(own, admin, { title, unitId, poolId, projectClass }) {
	const d = await createDemand(own, { title, demandType: 'efficiency', problemStatement: 'Manuelle Erfassung kostet Zeit.', orgUnitId: unitId })
	await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId, period: thisMonth(), personDays: 2 }] }, 200)
	await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
	await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted', projectClass }, 200)
	const model = await call(admin, 'get', '/scoring/model')
	await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores: Object.fromEntries(model.set.criteria.map(c => [c.code, 3])), effort: 3 }, 201)
	await call(own, 'put', `/demands/${d.id}/business-case`, {
		content: {
			costs: [{ label: 'Umsetzung', kind: 'capex', amount: 40000 }],
			benefits: [{ type: 'cost_saving', title: 'Einsparung', amount: 20000, metric: 'Kosten p. a.', baseline: '20 000 €', ownerUserId: E2E_USER }],
		},
	}, 200)
	await call(admin, 'post', `/demands/${d.id}/gate`, { step: 'candidate' }, 200)
	return call(admin, 'get', `/demands/${d.id}`)
}

/**
 * Konto in einer UserSelect/NcSelect-Auswahl wählen
 *
 * @param {import('@playwright/test').Page} page Seite
 * @param {import('@playwright/test').Locator} field Feld mit der Auswahl
 * @param {string} user Konto
 */
async function pickUser(page, field, user) {
	await field.locator('input').fill(user)
	await expect(page.locator('.vs__dropdown-option').first()).toContainText(user)
	await field.locator('input').press('Enter')
}

test.describe('Entscheidungsprozess (Stufe 3)', () => {
	test('Gremium und Entscheidungsrecht anlegen, G2 vorlegen, Sitzung mit Quorum, Beschluss mit Auflage', async ({ browser }) => {
		test.setTimeout(180000)
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const other = await apiAs(E2E_OTHER_USER)
		const rights = []
		const unit = await call(admin, 'post', '/org-units', { name: `E2E-Gremium ${t}` })
		const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Pool ${t}`, isBottleneck: false, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
		const demand = await candidate(own, admin, { title: `E2E-Gremium ${t}`, unitId: unit.id, poolId: pool.id, projectClass: 'XS' })

		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			// Gremium in der Oberfläche anlegen: Quorum 2, Pflichtsitz Finanzen
			await page.goto('/apps/nc_nexus/')
			await page.locator('[data-test="nav-governance"]').click()
			await expect(page.locator('[data-test="gate-set-Standard"]')).toBeVisible()
			await page.locator('[data-test="new-body"]').click()
			const bodyDialog = page.locator('[data-test="body-dialog"]')
			await bodyDialog.locator('[data-test="field-body-code"] input').fill(`PB${t}`)
			await bodyDialog.locator('[data-test="field-body-name"] input').fill(`E2E-Board ${t}`)
			await bodyDialog.locator('[data-test="field-body-quorum"] input').fill('2')
			await bodyDialog.locator('[data-test="field-body-seats"] input').fill('Finanzen')
			await bodyDialog.locator('[data-test="save-body"]').click()
			// Mitglieder: das zweite Testkonto auf dem Pflichtsitz, der Admin als GF
			await pickUser(page, bodyDialog.locator('[data-test="member-user"]'), E2E_OTHER_USER)
			await bodyDialog.locator('[data-test="member-seat"] input').fill('Finanzen')
			await bodyDialog.locator('[data-test="add-member"]').click()
			await expect(bodyDialog.locator(`[data-test="member-${E2E_OTHER_USER}"]`)).toBeVisible()
			await pickUser(page, bodyDialog.locator('[data-test="member-user"]'), ADMIN_USER)
			await bodyDialog.locator('[data-test="member-seat"] input').fill('GF')
			await bodyDialog.locator('[data-test="add-member"]').click()
			await expect(bodyDialog.locator(`[data-test="member-${ADMIN_USER}"]`)).toBeVisible()
			await page.keyboard.press('Escape')
			await expect(page.locator(`[data-test="body-PB${t}"]`)).toContainText(`E2E-Board ${t}`)

			// Entscheidungsrecht: G2 für Klasse XS entscheidet das neue Gremium
			const body = (await call(admin, 'get', '/governance')).bodies.find(b => b.code === `PB${t}`)
			await page.locator('[data-test="new-right"]').click()
			const rightDialog = page.locator('[data-test="right-dialog"]')
			await rightDialog.locator('[data-test="field-right-gate"] select').selectOption('g2')
			await rightDialog.locator('[data-test="field-right-body"] select').selectOption(String(body.id))
			await rightDialog.locator('[data-test="field-right-classes"] input[value="XS"]').check()
			await rightDialog.locator('[data-test="save-right"]').click()
			await expect(page.locator('[data-test="rights-g2"]')).toContainText(`E2E-Board ${t}`)
			rights.push(...(await call(admin, 'get', '/governance')).rights.rights.filter(r => r.bodyId === body.id).map(r => r.id))

			// Direktbeschluss ist jetzt gesperrt; das PMO legt das Gate dem Gremium vor
			const direct = await admin.post(`${PATH}/${demand.id}/gate`, { data: { step: 'g2', outcome: 'approved' } })
			expect(direct.status()).toBe(403)
			await page.locator('[data-test="nav-all"]').click()
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			const editor = page.locator('[data-test="demand-editor"]')
			await expect(editor.locator('[data-test="gate-decider"]')).toContainText(`E2E-Board ${t}`)
			await expect(editor.locator('[data-test="open-gate"]')).toHaveCount(0)
			await editor.locator('[data-test="submit-gate"]').click()
			await expect(editor.locator('[data-test="gate-instance"]')).toHaveText(/Ready for decision|Entscheidungsreif/)
			await page.keyboard.press('Escape')

			// Sitzung anlegen (ohne Kalender und Talk: die lägen außerhalb der App-Daten)
			await page.locator('[data-test="nav-sessions"]').click()
			await page.locator('[data-test="new-session"]').click()
			const sessionDialog = page.locator('[data-test="session-dialog"]')
			await sessionDialog.locator('[data-test="field-session-body"] select').selectOption(String(body.id))
			await sessionDialog.locator('[data-test="field-session-title"] input').fill(`E2E-Sitzung ${t}`)
			await sessionDialog.locator('[data-test="field-session-when"] input').fill(`${inDays(7)}T10:00`)
			for (const opt of ['field-session-calendar', 'field-session-talk']) {
				if (await sessionDialog.locator(`[data-test="${opt}"] input`).count()) {
					await sessionDialog.locator(`[data-test="${opt}"] input`).uncheck()
				}
			}
			await sessionDialog.locator('[data-test="save-session"]').click()
			await expect(page.locator('[data-test="session-title"]')).toHaveText(`E2E-Sitzung ${t}`)

			// Tagesordnung aus entscheidungsreifen Gates, Empfehlung, festlegen
			await page.locator('[data-test="generate-agenda"]').click()
			const item = page.locator('[data-test="item-0"]')
			await expect(item).toContainText(`E2E-Gremium ${t}`)
			await expect(item.locator('[data-test="item-brief"]')).toContainText('Manuelle Erfassung')
			await item.locator('[data-test="item-recommendation"] textarea').fill('Aufnehmen, Security vorab klären.')
			await item.locator('[data-test="item-recommendation"] textarea').blur()
			await page.locator('[data-test="finalize-session"]').click()
			await expect(page.locator('[data-test="session-status"]')).toHaveText(/Agenda final|Tagesordnung steht/)

			// Abstimmung: Admin stimmt ab – Quorum (2) und Pflichtsitz Finanzen fehlen noch
			await item.locator(`[data-test="vote-as-${ADMIN_USER}"] [data-test="vote-yes"]`).click()
			await expect(item.locator('[data-test="item-quorum"]')).toContainText('1/2')
			await expect(item.locator('[data-test="item-quorum"]')).toContainText('Finanzen')
			const sessionId = (await call(admin, 'get', '/sessions')).find(s => s.title === `E2E-Sitzung ${t}`).id
			let session = await call(admin, 'get', `/sessions/${sessionId}`)
			const itemId = session.items[0].id
			// Beschluss erst während der Sitzung
			expect((await admin.post(`${PATH.replace('/demands', '')}/agenda-items/${itemId}/decision`, { data: { outcome: 'approved' } })).status()).toBe(400)
			// Finanzen stimmt ab (zweites Konto), der Antragsteller dürfte es nicht
			expect((await own.post(`${PATH.replace('/demands', '')}/agenda-items/${itemId}/vote`, { data: { vote: 'yes' } })).status()).toBe(403)
			await call(other, 'post', `/agenda-items/${itemId}/vote`, { vote: 'yes' }, 200)

			await page.locator('[data-test="start-session"]').click()
			await expect(page.locator('[data-test="session-status"]')).toHaveText(/In session|Sitzung läuft/)
			await expect(item.locator('[data-test="item-quorum"]')).toContainText('2/2')

			// Beschluss: aufgenommen mit blockierender Auflage für das zweite Konto
			await item.locator('[data-test="decide-item"]').click()
			const gateDialog = page.locator('[data-test="gate-dialog"]')
			await gateDialog.locator('[data-test="outcome-approved_with_conditions"] input').check()
			const cond = gateDialog.locator('[data-test="condition-0"]')
			await cond.locator('[data-test="condition-title"]').fill('Security-Konzept vorlegen')
			await pickUser(page, cond.locator('[data-test="condition-owner"]'), E2E_OTHER_USER)
			await cond.locator('[data-test="condition-due"]').fill(inDays(30))
			await cond.locator('[data-test="condition-blocking"]').check()
			await gateDialog.locator('[data-test="field-gate-reason"] textarea').fill('Wertbeitrag hoch, Security offen')
			await gateDialog.locator('[data-test="save-gate"]').click()
			await expect(item.locator('[data-test="item-decision"]')).toContainText(/BES-\d{4}-\d{4}/)

			// Folgen: Projekt priorisiert, Beschluss mit Gremium als Mandat und Stimmbild, Auflage blockiert G3
			const after = await call(admin, 'get', `/demands/${demand.id}`)
			expect(after.status).toBe('prioritized')
			const decision = (await call(admin, 'get', '/decisions')).find(d => d.demand.id === demand.id && d.decisionType === 'g2')
			expect(decision.outcome).toBe('approved_with_conditions')
			expect(decision.mandate.scopeType).toBe('body')
			expect(decision.details.votes.yes).toBe(2)
			const gate = await call(admin, 'get', `/demands/${demand.id}/gate`)
			expect(gate.checks.find(c => c.key === 'open_conditions').ok).toBe(false)

			// Abschließen: Stimmen sind danach unveränderlich
			await page.locator('[data-test="close-session"]').click()
			await page.locator('[data-test="minutes-text"]').fill('G2 mit Auflage beschlossen.')
			await page.locator('[data-test="save-minutes"]').click()
			await expect(page.locator('[data-test="session-status"]')).toHaveText(/Minuted|Protokolliert/)
			expect((await other.post(`${PATH.replace('/demands', '')}/agenda-items/${itemId}/vote`, { data: { vote: 'no' } })).status()).toBe(400)

			// Beschlussverfolgung: Verantwortlicher meldet erledigt, danach ist G3 nicht mehr blockiert
			const condition = (await call(other, 'get', '/conditions')).find(c => c.demandId === demand.id)
			expect(condition.can.done).toBe(true)
			expect(condition.can.waive).toBe(false)
			await call(other, 'post', `/conditions/${condition.id}/close`, { status: 'done', evidence: 'Konzept liegt im Projektordner' }, 200)
			await page.locator('[data-test="nav-conditions"]').click()
			await page.locator('[data-test="filter-closed"]').click()
			await expect(page.locator(`[data-test="condition-${condition.id}"]`)).toContainText('Konzept liegt im Projektordner')
			session = await call(admin, 'get', `/demands/${demand.id}/gate`)
			expect(session.checks.find(c => c.key === 'open_conditions').ok).toBe(true)
		} finally {
			for (const id of rights) {
				await admin.delete(`${PATH.replace('/demands', '')}/decision-rights/${id}`)
			}
			await context.close()
			await own.dispose()
			await other.dispose()
			await admin.dispose()
		}
	})

	test('Projektlebenszyklus G3 → G4 (Tranche, Pause, Fortsetzen) → G5 → G6 mit Maßnahme', async () => {
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		try {
			const unit = await call(admin, 'post', '/org-units', { name: `E2E-Lebenszyklus ${t}` })
			const pool = await call(admin, 'post', '/pools', { code: `E2E${t}`, name: `E2E-Pool ${t}`, isBottleneck: false, absencePct: 0, linePct: 0, overheadPct: 0, reservePct: 0 })
			const d = await candidate(own, admin, { title: `E2E-Lebenszyklus ${t}`, unitId: unit.id, poolId: pool.id, projectClass: 'M' })
			const gate = (body) => call(admin, 'post', `/demands/${d.id}/gate`, body, 200)
			const status = async () => (await call(admin, 'get', `/demands/${d.id}`)).status

			await gate({ step: 'g2', outcome: 'approved', tranche: { amount: 5000, fiscalYear: 2026 } })
			await gate({ step: 'g3', outcome: 'approved' })
			expect(await status()).toBe('released')
			let info = await call(admin, 'get', `/demands/${d.id}/gate`)
			expect(info.steps).toEqual(['g4'])
			expect(info.gateSet.gates.filter(g => g.state === 'done').map(g => g.code)).toEqual(['g2', 'g3'])

			// G4: Freigabe der ersten Phase mit Tranche
			await gate({ step: 'g4', outcome: 'approved', tranche: { amount: 20000, fiscalYear: 2026 } })
			expect(await status()).toBe('in_execution')
			info = await call(admin, 'get', `/demands/${d.id}/gate`)
			expect(info.budgetReleased).toBe(25000)
			expect(info.steps).toEqual(['g4', 'g5'])

			// Pausieren braucht Begründung und Wiederaufnahmedatum
			const noDate = await admin.post(`${PATH}/${d.id}/gate`, { data: { step: 'g4', outcome: 'deferred', reason: 'Budgetstopp' } })
			expect(noDate.status()).toBe(400)
			await gate({ step: 'g4', outcome: 'deferred', reason: 'Budgetstopp', resubmissionDate: inDays(30) })
			expect(await status()).toBe('paused')
			await gate({ step: 'g4', outcome: 'approved' })
			expect(await status()).toBe('in_execution')

			// G5: Abnahme gibt die Zusagen ab dem Folgemonat frei (diesen Monat bleiben)
			await gate({ step: 'g5', outcome: 'approved' })
			expect(await status()).toBe('completed')
			info = await call(admin, 'get', `/demands/${d.id}/gate`)
			expect(info.allocations.every(a => a.period <= thisMonth())).toBe(true)
			expect(info.steps).toEqual(['g6'])

			// G6: verfehlter Nutzen nur mit Maßnahme
			const noAction = await admin.post(`${PATH}/${d.id}/gate`, { data: { step: 'g6', outcome: 'rejected', reason: 'Einsparung nicht erreicht' } })
			expect(noAction.status()).toBe(400)
			await gate({ step: 'g6', outcome: 'rejected', reason: 'Einsparung nicht erreicht', conditions: [{ title: 'Prozess nachschulen', ownerId: E2E_USER, dueDate: inDays(60) }] })
			expect(await status()).toBe('closed')
			const actions = (await call(own, 'get', '/conditions')).filter(c => c.demandId === d.id)
			expect(actions.map(a => a.kind)).toEqual(['action'])
			expect(actions[0].can.done).toBe(true)

			// Register: alle Gates als Beschlüsse
			const types = (await call(admin, 'get', '/decisions')).filter(x => x.demand.id === d.id).map(x => x.decisionType)
			const count = (type) => types.filter(x => x === type).length
			expect([count('g2'), count('g3'), count('g4'), count('g5'), count('g6')]).toEqual([1, 1, 3, 1, 1])
		} finally {
			await own.dispose()
			await admin.dispose()
		}
	})

	test('Gate-Set als neue Version speichern und Datenqualitäts-Cockpit', async ({ browser }) => {
		const t = tag()
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			// Antrag ohne Org-Einheit → Befund im Cockpit
			const d = await createDemand(own, { title: `E2E-Qualität ${t}`, demandType: 'innovation', problemStatement: 'x' })
			await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)

			await page.goto('/apps/nc_nexus/')
			await page.locator('[data-test="nav-governance"]').click()
			await page.locator('[data-test="gate-set-Verkürzter Pfad"]').click()
			const dialog = page.locator('[data-test="gate-set-dialog"]')
			await expect(dialog.locator('[data-test="set-gate-g2"] [data-test="gate-enabled"]')).toBeDisabled()
			await dialog.locator('[data-test="set-gate-g6"] [data-test="gate-enabled"]').check()
			await dialog.locator('[data-test="save-set"]').click()
			await expect(page.locator('[data-test="gate-set-Verkürzter Pfad"]')).toContainText(/Version 2/)
			await expect(page.locator('[data-test="gate-set-Verkürzter Pfad"]')).toContainText('G6')

			await page.locator('[data-test="nav-quality"]').click()
			await expect(page.locator('[data-test="quality-score"]')).toBeVisible()
			const rule = page.locator('[data-test="rule-missing_org_unit"]')
			await rule.click()
			await expect(page.locator('[data-test="quality-view"]')).toContainText(`E2E-Qualität ${t}`)
			// Klick auf den Befund öffnet den Antrag
			await page.locator('[data-test="quality-view"]').getByText(`E2E-Qualität ${t}`).click()
			await expect(page.locator('[data-test="demand-editor"]')).toBeVisible()
		} finally {
			await context.close()
			await own.dispose()
			await admin.dispose()
		}
	})
})
