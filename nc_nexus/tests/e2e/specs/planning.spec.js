// @ts-check
const { test, expect } = require('@playwright/test')
const { openDemands } = require('../helpers/nav')
const { adminApi, apiAs, call, createDemand } = require('../helpers/api')
const { ADMIN_PASSWORD, ADMIN_USER, E2E_USER } = require('../helpers/env')
const { loginAs } = require('../helpers/login')

const thisMonth = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

const path = require('path')

/**
 * Antrag bis G3 (freigegeben) bringen.
 *
 * @param {import('@playwright/test').APIRequestContext} admin PMO
 * @param {import('@playwright/test').APIRequestContext} own antragstellendes Konto
 * @param {string} t Kürzel des Laufs
 */
const releasedDemand = async (admin, own, t) => {
	const unit = await call(admin, 'post', '/org-units', { name: `E2E-Planung ${t}` })
	const pool = await call(admin, 'post', '/pools', { code: `E2EP${t}`, name: `E2E-Pool ${t}` })
	const d = await createDemand(own, { title: `E2E-Planung ${t}`, demandType: 'efficiency', problemStatement: 'x', orgUnitId: unit.id })
	await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId: pool.id, period: thisMonth(), personDays: 5 }] }, 200)
	await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
	await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted' }, 200)
	const model = await call(admin, 'get', '/scoring/model')
	await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores: Object.fromEntries(model.set.criteria.map(c => [c.code, 3])), effort: 1 }, 201)
	await call(own, 'put', `/demands/${d.id}/business-case`, { content: { costs: [{ label: 'x', kind: 'capex', amount: 1 }], benefits: [{ type: 'qualitative', title: 'y', metric: 'm', baseline: 'b', ownerUserId: E2E_USER }] } }, 200)
	for (const step of [{ step: 'candidate' }, { step: 'g2', outcome: 'prioritized' }, { step: 'g3' }]) {
		await call(admin, 'post', `/demands/${d.id}/gate`, step, 200)
	}
	return call(admin, 'get', `/demands/${d.id}`)
}

test.describe('Planungs-Integration: MS Project und OpenProject', () => {
	test('MS-Project-Plan (XML) importieren, neu importieren und lösen', async ({ browser }) => {
		test.setTimeout(150000)
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const t = 'M' + Math.random().toString(36).slice(2, 6).toUpperCase()
		const demand = await releasedDemand(admin, own, t)
		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await openDemands(page)
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			const panel = page.locator('[data-test="planning"]')
			await expect(panel).toBeVisible()
			const provider = panel.locator('[data-test="planning-provider"]')
			if (await provider.count()) {
				await provider.selectOption('mspdi')
			}
			await panel.locator('[data-test="planning-file"]').setInputFiles(path.join(__dirname, '..', 'fixtures', 'plan.xml'))
			await expect(panel.locator('[data-test="planning-open"]')).toHaveText('Leitstand Montage')
			await expect(panel.locator('[data-test="planning-tasks"]')).toHaveText('1 / 4')
			await expect(panel.locator('[data-test="planning-milestones"] li')).toHaveCount(2)
			await expect(panel.locator('[data-test="planning-milestones"]')).toContainText('Inbetriebnahme')
			const link = (await call(admin, 'get', `/demands/${demand.id}/planning`)).link
			expect(link.provider).toBe('mspdi')
			expect(link.summary.fileName).toBe('plan.xml')

			// neuer Stand ersetzt den Schnappschuss
			await panel.locator('[data-test="planning-file"]').setInputFiles({ name: 'plan-v2.xml', mimeType: 'text/xml', buffer: Buffer.from('<Project xmlns="http://schemas.microsoft.com/project"><Title>Leitstand v2</Title><Tasks><Task><UID>1</UID><Name>Alles</Name><Start>2026-11-02T08:00:00</Start><Finish>2026-11-30T17:00:00</Finish><PercentComplete>100</PercentComplete></Task></Tasks></Project>') })
			await expect(panel.locator('[data-test="planning-open"]')).toHaveText('Leitstand v2')
			await expect(panel.locator('[data-test="planning-progress"]')).toHaveText('100 %')

			// ungültige Datei: Fehlermeldung, Stand bleibt
			const bad = await admin.post(`/ocs/v2.php/apps/nc_nexus/api/v1/demands/${demand.id}/planning/import`, { data: { fileName: 'x.xml', content: '<!DOCTYPE x><Project/>' } })
			expect(bad.status()).toBe(400)
			// mit Datei verknüpft: kein zweites Planungstool
			expect((await admin.post(`/ocs/v2.php/apps/nc_nexus/api/v1/demands/${demand.id}/planning`, { data: { provider: 'gantt' } })).status()).toBe(400)

			await panel.locator('[data-test="planning-unlink"]').click()
			await expect(panel.locator('[data-test="planning-import"]')).toBeVisible()
		} finally {
			await context.close()
			await own.dispose()
			await admin.dispose()
		}
	})

	test('OpenProject: Adresse nur durch PMO, API-Schlüssel je Konto', async ({ browser }) => {
		test.setTimeout(150000)
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const t = 'O' + Math.random().toString(36).slice(2, 6).toUpperCase()
		try {
			expect((await own.put('/ocs/v2.php/apps/nc_nexus/api/v1/planning/config', { data: { openprojectUrl: 'https://op.example.invalid' } })).status()).toBe(403)
			expect((await admin.put('/ocs/v2.php/apps/nc_nexus/api/v1/planning/config', { data: { openprojectUrl: 'kein-url' } })).status()).toBe(400)

			// Adresse im Bereich Planungstools speichern (dev.sh e2e stellt die Konfiguration wieder her)
			const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
			try {
				await openDemands(page)
				await page.locator('[data-test="nav-planning-tools"]').click()
				const view = page.locator('[data-test="planning-tools-view"]')
				await view.locator('[data-test="tool-openproject"] [data-test="openproject-url"]').fill('https://op.example.invalid/')
				await view.locator('[data-test="save-planning-tools"]').click()
				await expect(view.locator('[data-test="save-planning-tools"]')).toBeDisabled()
				await expect(view.locator('[data-test="openproject-status"]')).toHaveClass(/nx-chip--accepted/)
			} finally {
				await context.close()
			}
			expect((await call(admin, 'get', '/planning/config')).openprojectUrl).toBe('https://op.example.invalid')

			const demand = await releasedDemand(admin, own, t)
			let planning = await call(own, 'get', `/demands/${demand.id}/planning`)
			const op = planning.providers.find(p => p.id === 'openproject')
			expect(op.hasCredentials).toBe(false)
			// ohne Schlüssel: klare Meldung statt Abruf
			expect((await own.get('/ocs/v2.php/apps/nc_nexus/api/v1/planning/projects?provider=openproject')).status()).toBe(400)

			await call(own, 'put', '/planning/credentials/openproject', { secret: 'e2e-dummy' }, 200)
			planning = await call(own, 'get', `/demands/${demand.id}/planning`)
			expect(planning.providers.find(p => p.id === 'openproject').hasCredentials).toBe(true)
			// Schlüssel gehört nur dem Konto
			planning = await call(admin, 'get', `/demands/${demand.id}/planning`)
			expect(planning.providers.find(p => p.id === 'openproject').hasCredentials).toBe(false)
			// Instanz nicht erreichbar: Fehler, keine Verknüpfung
			const res = await own.get('/ocs/v2.php/apps/nc_nexus/api/v1/planning/projects?provider=openproject')
			expect(res.status()).toBe(400)
			expect((await call(own, 'get', `/demands/${demand.id}/planning`)).link).toBeNull()
		} finally {
			await call(own, 'put', '/planning/credentials/openproject', { secret: null }, 200).catch(() => {})
			await own.dispose()
			await admin.dispose()
		}
	})
})

test.describe('Bereich Planungstools', () => {
	test('Gantt auf anderer Nextcloud mit technischem Konto, Verbindung prüfen', async ({ browser }) => {
		test.setTimeout(120000)
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			// Ausgangslage herstellen (dev.sh e2e stellt Einstellungen und Zugangsdaten danach wieder her)
			await call(admin, 'put', '/planning/config', { ganttUrl: '' }, 200)
			// ohne App-Passwort wird nichts gespeichert
			expect((await admin.put('/ocs/v2.php/apps/nc_nexus/api/v1/planning/config', { data: { ganttUrl: 'http://gantt.example.invalid', ganttUser: 'pmo' } })).status()).toBe(400)
			expect((await call(admin, 'get', '/planning/config')).ganttUrl).toBe('')
			expect((await own.post('/ocs/v2.php/apps/nc_nexus/api/v1/planning/config/test-gantt')).status()).toBe(403)

			await openDemands(page)
			await page.locator('[data-test="nav-planning-tools"]').click()
			const gantt = page.locator('[data-test="tool-gantt"]')
			await expect(gantt.locator('[data-test="gantt-local"]')).toBeVisible()
			await gantt.locator('[data-test="gantt-url"]').fill('http://gantt.example.invalid')
			await gantt.locator('[data-test="gantt-user"]').fill('pmo')
			await gantt.locator('[data-test="gantt-password"]').fill('e2e-app-passwort')
			await page.locator('[data-test="save-planning-tools"]').click()
			await expect(gantt.locator('[data-test="gantt-remote"]')).toBeVisible()
			await expect(gantt.locator('[data-test="gantt-password"]')).toHaveAttribute('placeholder', /gespeichert|stored/)
			const config = await call(admin, 'get', '/planning/config')
			expect(config.ganttHasPassword).toBe(true)
			expect(JSON.stringify(config)).not.toContain('e2e-app-passwort')
			// Host existiert nicht: Fehler wird angezeigt
			await gantt.locator('[data-test="gantt-test"]').click()
			await expect(gantt.locator('[data-test="gantt-test-result"]')).toContainText('unreachable')
		} finally {
			// Adresse leeren löscht auch Konto und App-Passwort (Credential-Store)
			await call(admin, 'put', '/planning/config', { ganttUrl: '' }, 200).catch(() => {})
			expect((await call(admin, 'get', '/planning/config')).ganttHasPassword).toBe(false)
			await context.close()
			await own.dispose()
			await admin.dispose()
		}
	})

	test('MS Project: Import abschaltbar, Startplan exportieren', async ({ browser }) => {
		test.setTimeout(150000)
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const t = 'X' + Math.random().toString(36).slice(2, 6).toUpperCase()
		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			const demand = await releasedDemand(admin, own, t)
			await call(admin, 'post', `/projects/${demand.id}/milestones`, { name: 'Abnahme Leitstand', dueDate: '2027-02-15', external: true }, 201)

			await openDemands(page)
			await page.locator('[data-test="nav-planning-tools"]').click()
			const ms = page.locator('[data-test="tool-mspdi"]')
			const importSwitch = ms.getByRole('switch', { name: /Import von Plänen|Import of plans/ })
			await ms.getByText(/^(Import von Plänen|Import of plans)$/).click()
			await expect(importSwitch).not.toBeChecked()
			await page.locator('[data-test="save-planning-tools"]').click()
			await expect(page.locator('[data-test="save-planning-tools"]')).toBeDisabled()
			let planning = await call(own, 'get', `/demands/${demand.id}/planning`)
			expect(planning.providers.map(p => p.id)).not.toContain('mspdi')
			expect(planning.canExport).toBe(true)
			expect((await own.post(`/ocs/v2.php/apps/nc_nexus/api/v1/demands/${demand.id}/planning/import`, { data: { fileName: 'x.xml', content: '<Project/>' } })).status()).toBe(400)

			// Export am Vorhaben
			await page.locator('[data-test="nav-all"]').click()
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			const panel = page.locator('[data-test="planning"]')
			await expect(panel.locator('[data-test="planning-import"]')).toHaveCount(0)
			const [download] = await Promise.all([
				page.waitForEvent('download'),
				panel.locator('[data-test="planning-export"]').click(),
			])
			expect(download.suggestedFilename()).toBe(`${demand.projectKey}.xml`)
			const file = await call(own, 'get', `/demands/${demand.id}/planning/export`)
			expect(file.content).toContain('<Title>' + demand.projectKey)
			expect(file.content).toMatch(/<Name>(Projektrahmen \(Governance\)|Project frame \(governance\))<\/Name>/)
			expect(file.content).toContain('<Name>Abnahme Leitstand</Name>')

			// Export aus: kein Button, API lehnt ab
			await call(admin, 'put', '/planning/config', { mspdiImport: true, mspdiExport: false }, 200)
			planning = await call(own, 'get', `/demands/${demand.id}/planning`)
			expect(planning.canExport).toBe(false)
			expect((await own.get(`/ocs/v2.php/apps/nc_nexus/api/v1/demands/${demand.id}/planning/export`)).status()).toBe(400)
		} finally {
			await call(admin, 'put', '/planning/config', { mspdiImport: true, mspdiExport: true }, 200).catch(() => {})
			await context.close()
			await own.dispose()
			await admin.dispose()
		}
	})
})

test.describe('Planungs-Integration (nc_gantt)', () => {
	test('Freigegebenes Vorhaben in Gantt anlegen und Fortschritt lesen', async ({ browser }) => {
		test.setTimeout(150000)
		const admin = await adminApi()
		const own = await apiAs(E2E_USER)
		const t = 'P' + Math.random().toString(36).slice(2, 6).toUpperCase()
		const unit = await call(admin, 'post', '/org-units', { name: `E2E-Planung ${t}` })
		const pool = await call(admin, 'post', '/pools', { code: `E2EP${t}`, name: `E2E-Pool ${t}` })
		const d = await createDemand(own, { title: `E2E-Planung ${t}`, demandType: 'efficiency', problemStatement: 'x', orgUnitId: unit.id })
		// ohne nc_gantt: Abschnitt nur als Hinweis, Test überspringen (eigener Entwurf ist lesbar)
		const probe = await call(own, 'get', `/demands/${d.id}/planning`)
		test.skip(!probe.providers.some(p => p.id === 'gantt'), 'nc_gantt ist in dieser Umgebung nicht installiert')

		await call(own, 'put', `/demands/${d.id}/needs`, { needs: [{ poolId: pool.id, period: thisMonth(), personDays: 5 }] }, 200)
		await call(own, 'post', `/demands/${d.id}/submit`, undefined, 200)
		await call(admin, 'post', `/demands/${d.id}/triage`, { code: 'accepted' }, 200)
		const model = await call(admin, 'get', '/scoring/model')
		await call(admin, 'post', `/demands/${d.id}/assessments`, { kind: 'review', scores: Object.fromEntries(model.set.criteria.map(c => [c.code, 3])), effort: 1 }, 201)
		await call(own, 'put', `/demands/${d.id}/business-case`, { content: { costs: [{ label: 'x', kind: 'capex', amount: 1 }], benefits: [{ type: 'qualitative', title: 'y', metric: 'm', baseline: 'b', ownerUserId: E2E_USER }] } }, 200)
		for (const step of [{ step: 'candidate' }, { step: 'g2', outcome: 'prioritized' }, { step: 'g3' }]) {
			await call(admin, 'post', `/demands/${d.id}/gate`, step, 200)
		}
		const demand = await call(admin, 'get', `/demands/${d.id}`)

		const { context, page } = await loginAs(browser, ADMIN_USER, ADMIN_PASSWORD)
		try {
			await openDemands(page)
			await page.locator(`[data-test="demand-${demand.key}"]`).click()
			const panel = page.locator('[data-test="planning"]')
			await panel.locator('[data-test="planning-create"]').click()
			await expect(panel.locator('[data-test="planning-open"]')).toContainText(demand.projectKey)
			await expect(panel.locator('[data-test="planning-progress"]')).toHaveText('0 %')
			const link = (await call(admin, 'get', `/demands/${d.id}/planning`)).link
			expect(link.provider).toBe('gantt')
			expect(link.syncStatus).toBe('ok')
			// doppelt verknüpfen geht nicht
			expect((await admin.post(`/ocs/v2.php/apps/nc_nexus/api/v1/demands/${d.id}/planning`, { data: {} })).status()).toBe(400)
		} finally {
			await context.close()
			await own.dispose()
			await admin.dispose()
		}
	})
})
