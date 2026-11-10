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

/** Eingereichten Antrag des Testkontos anlegen */
async function submitted(api, title) {
	const demand = await createDemand(api, { title, demandType: 'strategic', problemStatement: 'Ohne Lösung steigt das Risiko.' })
	return call(api, 'post', `/demands/${demand.id}/submit`, undefined, 200)
}

test.describe('Triage', () => {
	/** e2e-other ist während der Tests globales PMO; danach wird die Rolle wieder entfernt */
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

	test('Rückfrage, Ergänzung und Ablehnung mit Pflichtbegründung', async ({ page, browser }) => {
		test.setTimeout(90000)
		const own = await apiAs(E2E_USER)
		const demand = await submitted(own, unique('E2E-Triage'))
		const { context, page: pmo } = await loginAs(browser, E2E_OTHER_USER, E2E_PASSWORD)
		try {
			// PMO: Arbeitsvorrat → Rückfrage
			await pmo.goto('/apps/nc_nexus/')
			await pmo.locator('[data-test="nav-queue-triage"]').click()
			await pmo.locator(`[data-test="demand-${demand.key}"]`).click()
			await pmo.locator('[data-test="open-triage"]').click()
			const dialog = pmo.locator('[data-test="triage-dialog"]')
			await dialog.locator('[data-test="code-query"]').click()
			await dialog.locator('[data-test="field-question"] textarea').fill('Welche Systeme sind betroffen?')
			await dialog.locator('[data-test="field-query-due"] input').fill(isoDay(7))
			await dialog.locator('[data-test="save-triage"]').click()
			await expect(dialog).toHaveCount(0)
			await expect(pmo.locator('[data-test="demand-status"]')).toHaveText(/Query|Rückfrage/)

			// Antragsteller: Hinweis sehen, ergänzen, erneut einreichen
			await page.goto('/apps/nc_nexus/')
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			const editor = page.locator('[data-test="demand-editor"]')
			await expect(editor.locator('[data-test="query-notice"]')).toContainText('Welche Systeme sind betroffen?')
			await editor.locator('[data-test="field-description"] textarea').fill('SAP und Ticketsystem')
			await editor.locator('[data-test="field-answer"] textarea').fill('SAP und das Ticketsystem.')
			await editor.locator('[data-test="submit-demand"]').click()
			await expect(editor.locator('[data-test="demand-status"]')).toHaveText(/Submitted|Eingereicht/)
			// Rückfrage und Antwort stehen als Gespräch im Kommentarfaden
			const thread = editor.locator('[data-test="comments"]')
			await expect(thread.locator('[data-test="comment-query"]')).toContainText('Welche Systeme sind betroffen?')
			await expect(thread.locator('[data-test="comment-query"]')).toContainText(E2E_OTHER_USER)
			await expect(thread.locator('[data-test="comment-answer"]')).toContainText('SAP und das Ticketsystem.')
			await expect(editor.locator('[data-test="open-triage"]')).toHaveCount(0)

			// PMO: Kommentar schreiben, Antragsteller sieht ihn
			await pmo.reload()
			await pmo.locator(`[data-test="demand-${demand.key}"]`).click()
			await pmo.locator('[data-test="field-comment"] textarea').fill('Danke, wird im Board besprochen.')
			await pmo.locator('[data-test="send-comment"]').click()
			await expect(pmo.locator('[data-test="comment-comment"]')).toContainText('Danke, wird im Board besprochen.')
			const seen = await call(own, 'get', `/demands/${demand.id}/comments`)
			expect(seen.map(c => c.kind)).toEqual(['query', 'answer', 'comment'])

			// PMO: Ablehnen ist ohne Begründung gesperrt
			await pmo.locator('[data-test="open-triage"]').click()
			await dialog.locator('[data-test="code-rejected"]').click()
			await expect(dialog.locator('[data-test="save-triage"]')).toBeDisabled()
			await dialog.locator('[data-test="field-reason"] textarea').fill('Bereits durch das Konzernprojekt abgedeckt.')
			await dialog.locator('[data-test="save-triage"]').click()
			const result = pmo.locator('[data-test="triage-result"]')
			await expect(result).toContainText('Bereits durch das Konzernprojekt abgedeckt.')
			await expect(pmo.locator('[data-test="demand-status"]')).toHaveText(/Rejected|Abgelehnt/)
			await expect(pmo.locator('[data-test="open-triage"]')).toHaveCount(0)

			// Verlauf in der Oberfläche: Statuswechsel mit „alt → neu“ und dem auslösenden Konto
			const decision = pmo.locator('[data-test="demand-history"] [data-test="history-decision"]')
			await expect(decision).toContainText(/Submitted → Rejected|Eingereicht → Abgelehnt/)
			await expect(decision).toContainText(E2E_OTHER_USER)
			const changes = pmo.locator('[data-test="demand-history"] [data-test="history-status_change"]')
			await expect(changes.nth(0)).toContainText(/Draft → Submitted|Entwurf → Eingereicht/)
			await expect(changes.nth(0)).toContainText(E2E_USER)
			await expect(changes.nth(1)).toContainText(/Submitted → Query|Eingereicht → Rückfrage/)
			await expect(changes.nth(1)).toContainText(E2E_OTHER_USER)

			// Verlauf: Rückfrage, Einreichen, Entscheidung
			const history = await call(own, 'get', `/demands/${demand.id}/history`)
			expect(history.map(e => e.action)).toEqual(['create', 'status_change', 'status_change', 'update', 'status_change', 'decision'])
		} finally {
			await context.close()
			await own.dispose()
		}
	})

	test('Zurückgestellter Antrag erscheint zur Wiedervorlage im Arbeitsvorrat', async ({ browser }) => {
		const own = await apiAs(E2E_USER)
		const other = await apiAs(E2E_OTHER_USER)
		const demand = await submitted(own, unique('E2E-Wiedervorlage'))
		await call(other, 'post', `/demands/${demand.id}/triage`, { code: 'deferred', resubmissionDate: isoDay(0) }, 200)
		const { context, page } = await loginAs(browser, E2E_OTHER_USER, E2E_PASSWORD)
		try {
			await page.goto('/apps/nc_nexus/')
			await page.locator('[data-test="nav-queue-resubmission"]').click()
			await expect(page.locator(`[data-test="demand-${demand.key}"]`)).toBeVisible()
			// erneut triagierbar: annehmen mit Vorhabensklasse
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			await page.locator('[data-test="open-triage"]').click()
			const dialog = page.locator('[data-test="triage-dialog"]')
			await dialog.locator('[data-test="code-accepted"]').click()
			await dialog.locator('[data-test="field-class"] select').selectOption('S')
			await dialog.locator('[data-test="save-triage"]').click()
			await expect(page.locator('[data-test="demand-status"]')).toHaveText(/In qualification|In Qualifizierung/)
			await page.keyboard.press('Escape')
			await expect(page.locator(`[data-test="demand-${demand.key}"]`)).toHaveCount(0)
		} finally {
			await context.close()
			await own.dispose()
			await other.dispose()
		}
	})

	test('Den eigenen Antrag kann niemand triagieren', async () => {
		const own = await apiAs(E2E_OTHER_USER)
		const demand = await submitted(own, unique('E2E-Eigen'))
		const res = await own.post(`/ocs/v2.php/apps/nc_nexus/api/v1/demands/${demand.id}/triage`, { data: { code: 'accepted' } })
		expect(res.status()).toBe(403)
		const fresh = await call(own, 'get', `/demands/${demand.id}`)
		expect(fresh.can.triage).toBe(false)
		await own.dispose()
	})
})
