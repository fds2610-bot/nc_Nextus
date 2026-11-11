import { getCanonicalLocale, t } from '@nextcloud/l10n'

export const typeLabel = (type) => ({
	mandatory: t('nc_nexus', 'Mandatory / regulatory'),
	strategic: t('nc_nexus', 'Strategic'),
	efficiency: t('nc_nexus', 'Efficiency'),
	lifecycle: t('nc_nexus', 'Maintenance / lifecycle'),
	innovation: t('nc_nexus', 'Innovation'),
})[type] || type

/** Status in der Reihenfolge des Statusautomaten (Kapitel 02, §4.1) */
export const STATUSES = ['draft', 'submitted', 'query', 'further_analysis', 'deferred', 'in_qualification', 'candidate', 'prioritized', 'released', 'rejected', 'duplicate']

export const statusLabel = (status) => ({
	draft: t('nc_nexus', 'Draft'),
	submitted: t('nc_nexus', 'Submitted'),
	query: t('nc_nexus', 'Query'),
	further_analysis: t('nc_nexus', 'Further analysis'),
	deferred: t('nc_nexus', 'Deferred'),
	in_qualification: t('nc_nexus', 'In qualification'),
	candidate: t('nc_nexus', 'Candidate'),
	prioritized: t('nc_nexus', 'Prioritized'),
	released: t('nc_nexus', 'Released'),
	rejected: t('nc_nexus', 'Rejected'),
	duplicate: t('nc_nexus', 'Duplicate'),
})[status] || status

// Farbklasse des Status-Chips (nx-chip--…)
export const statusChip = (status) => ({
	draft: 'draft',
	submitted: 'submitted',
	query: 'on-hold',
	further_analysis: 'on-hold',
	deferred: 'on-hold',
	in_qualification: 'accepted',
	candidate: 'submitted',
	prioritized: 'accepted',
	released: 'accepted',
	rejected: 'rejected',
	duplicate: 'draft',
})[status] || 'draft'

/** Entscheidungscodes der Triage (Kapitel 02, §2) */
export const TRIAGE_CODES = ['accepted', 'further_analysis', 'deferred', 'rejected', 'duplicate']

export const codeLabel = (code) => ({
	accepted: t('nc_nexus', 'Accept'),
	further_analysis: t('nc_nexus', 'Further analysis'),
	deferred: t('nc_nexus', 'Defer'),
	rejected: t('nc_nexus', 'Reject'),
	duplicate: t('nc_nexus', 'Duplicate'),
	query: t('nc_nexus', 'Query to requestor'),
})[code] || code

// Ergebnis eines Beschlusses (Entscheidungscode) als Text und Chip-Farbe
export const outcomeLabel = (code) => ({
	accepted: t('nc_nexus', 'Accepted'),
	further_analysis: t('nc_nexus', 'Further analysis'),
	deferred: t('nc_nexus', 'Deferred'),
	rejected: t('nc_nexus', 'Rejected'),
	duplicate: t('nc_nexus', 'Duplicate'),
	ready_for_prioritization: t('nc_nexus', 'Ready for prioritization'),
	prioritized: t('nc_nexus', 'Prioritized'),
	released: t('nc_nexus', 'Released'),
})[code] || code

export const outcomeChip = (code) => ({
	accepted: 'accepted',
	further_analysis: 'on-hold',
	deferred: 'on-hold',
	rejected: 'rejected',
	duplicate: 'draft',
	ready_for_prioritization: 'submitted',
	prioritized: 'accepted',
	released: 'accepted',
})[code] || 'draft'

export const codeHint = (code) => ({
	accepted: t('nc_nexus', 'Continue with qualification and business case.'),
	further_analysis: t('nc_nexus', 'No decision possible without more information; assign an analysis with a due date.'),
	deferred: t('nc_nexus', 'Deliberately postponed; it comes back on the resubmission date.'),
	rejected: t('nc_nexus', 'Bindingly not accepted; the reason stays visible in the register.'),
	duplicate: t('nc_nexus', 'Merged into another demand that leads from now on.'),
	query: t('nc_nexus', 'Information is missing; the requestor completes the demand and submits it again.'),
})[code] || ''

/** Heute als JJJJ-MM-TT (lokale Zeit) */
export const today = () => {
	const d = new Date()
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Datum JJJJ-MM-TT in der Sprache von Nextcloud
export const formatIsoDay = (iso) => iso ? new Date(iso + 'T12:00:00').toLocaleDateString(getCanonicalLocale(), { dateStyle: 'medium' }) : ''

export const fieldLabel = (field) => ({
	title: t('nc_nexus', 'Title'),
	demandType: t('nc_nexus', 'Type'),
	description: t('nc_nexus', 'Description'),
	problemStatement: t('nc_nexus', 'Problem statement'),
	expectedBenefit: t('nc_nexus', 'Expected benefit'),
	orgUnitId: t('nc_nexus', 'Organizational unit'),
	triageCode: t('nc_nexus', 'Decision'),
	triageReason: t('nc_nexus', 'Reason'),
	resubmissionDate: t('nc_nexus', 'Resubmission'),
	analysisOwner: t('nc_nexus', 'Analysis by'),
	analysisDue: t('nc_nexus', 'Analysis due'),
	duplicateOfId: t('nc_nexus', 'Duplicate of'),
	projectClass: t('nc_nexus', 'Project class'),
	queryText: t('nc_nexus', 'Query'),
	queryDue: t('nc_nexus', 'Answer due'),
	analysisDoneAt: t('nc_nexus', 'Analysis delivered'),
	status: t('nc_nexus', 'Status'),
})[field] || field

export const actionLabel = (action) => ({
	create: t('nc_nexus', 'Created'),
	update: t('nc_nexus', 'Changed'),
	status_change: t('nc_nexus', 'Status changed'),
	decision: t('nc_nexus', 'Triage decision'),
	analysis: t('nc_nexus', 'Analysis completed'),
	delete: t('nc_nexus', 'Deleted'),
})[action] || action

export const formatDate = (ts) => ts ? new Date(ts * 1000).toLocaleString(getCanonicalLocale(), { dateStyle: 'medium', timeStyle: 'short' }) : ''

// Feste Farbe je Antragstyp (Navigation, Liste); Text steht immer daneben.
export const typeColor = (type) => ({
	mandatory: 'E9322D',
	strategic: '0082C9',
	efficiency: '46BA61',
	lifecycle: 'ECA700',
	innovation: '9B59B6',
})[type] || '888888'

export const formatDay = (ts) => ts ? new Date(ts * 1000).toLocaleDateString(getCanonicalLocale(), { dateStyle: 'medium' }) : ''

export const channelLabel = (channel) => ({
	form: t('nc_nexus', 'Form'),
	ticket: t('nc_nexus', 'Ticket system'),
	mail: t('nc_nexus', 'E-mail'),
	api: t('nc_nexus', 'Interface'),
})[channel] || channel

/** Rollen, die in der Oberfläche vergeben werden (Auswahl aus ROLE_ASSIGNMENT). */
export const ASSIGNABLE_ROLES = ['portfolio_manager', 'board_member', 'sponsor', 'pool_manager', 'project_manager', 'auditor', 'viewer']

export const roleLabel = (role) => ({
	requestor: t('nc_nexus', 'Requestor'),
	sponsor: t('nc_nexus', 'Sponsor'),
	project_manager: t('nc_nexus', 'Project manager'),
	pool_manager: t('nc_nexus', 'Resource pool manager'),
	portfolio_manager: t('nc_nexus', 'Portfolio office (PMO)'),
	board_member: t('nc_nexus', 'Board member'),
	steering_member: t('nc_nexus', 'Steering committee member'),
	benefit_owner: t('nc_nexus', 'Benefit owner'),
	reviewer: t('nc_nexus', 'Reviewer'),
	controller: t('nc_nexus', 'Controller'),
	auditor: t('nc_nexus', 'Auditor'),
	data_owner: t('nc_nexus', 'Data owner'),
	viewer: t('nc_nexus', 'Viewer'),
	admin: t('nc_nexus', 'Administrator'),
})[role] || role

export const roleHint = (role) => ({
	portfolio_manager: t('nc_nexus', 'Sees all demands in scope; globally also maintains organization and roles'),
	board_member: t('nc_nexus', 'Sees all demands in scope and decides'),
	sponsor: t('nc_nexus', 'Sees the demands of their area'),
	pool_manager: t('nc_nexus', 'Sees demands in scope to plan capacity'),
	project_manager: t('nc_nexus', 'Sees demands in scope'),
	auditor: t('nc_nexus', 'Read access for audits'),
	viewer: t('nc_nexus', 'Reserved for released projects'),
})[role] || ''

/**
 * Org-Einheiten als Baum in Anzeige-Reihenfolge, mit Tiefe und vollständigem Pfad.
 *
 * @param {Array} units flache Liste mit id, name, parentId
 * @return {Array<object>}
 */
export function orgTree(units) {
	const children = new Map()
	for (const unit of units) {
		const key = units.some(u => u.id === unit.parentId) ? unit.parentId : null
		if (!children.has(key)) {
			children.set(key, [])
		}
		children.get(key).push(unit)
	}
	const result = []
	const walk = (parentId, depth, path) => {
		for (const unit of (children.get(parentId) || []).sort((a, b) => a.name.localeCompare(b.name))) {
			const fullPath = path ? `${path} › ${unit.name}` : unit.name
			result.push({ ...unit, depth, path: fullPath })
			walk(unit.id, depth + 1, fullPath)
		}
	}
	walk(null, 0, '')
	return result
}

/**
 * Name einer Org-Einheit mit Einrückung nach Tiefe (für Auswahllisten).
 *
 * @param {{name: string, depth: number}} unit Eintrag aus orgTree()
 * @return {string}
 */
export const indented = (unit) => '\u00a0\u00a0\u00a0'.repeat(unit.depth) + unit.name

// Aktueller Monat als JJJJ-MM
export const thisMonth = () => today().slice(0, 7)

// Monat JJJJ-MM kurz in der Sprache von Nextcloud (z. B. „Nov. 26“)
export const formatMonth = (period) => period
	? new Date(period + '-15T12:00:00').toLocaleDateString(getCanonicalLocale(), { month: 'short', year: '2-digit' })
	: ''

// Zahl mit höchstens einer Nachkommastelle in der Sprache von Nextcloud
export const formatNumber = (value) => value === null || value === undefined
	? ''
	: Number(value).toLocaleString(getCanonicalLocale(), { maximumFractionDigits: 1 })

export const poolTypeLabel = (type) => ({
	internal: t('nc_nexus', 'Internal'),
	external: t('nc_nexus', 'External'),
	business: t('nc_nexus', 'Business department'),
})[type] || type

// Farbstufe der Auslastung (Text steht immer dabei)
export const loadLevel = (cell) => {
	if (cell.load === null) {
		return 'over'
	}
	if (cell.load > 100) {
		return 'over'
	}
	if (cell.load >= 80) {
		return 'high'
	}
	return cell.demand > 0 ? 'ok' : 'empty'
}

// Portfoliophasen (Kapitel 02 §4.2); Status → Phase wie im PortfolioService
export const PHASE_OF = {
	submitted: 'demand',
	query: 'demand',
	further_analysis: 'demand',
	in_qualification: 'demand',
	candidate: 'demand',
	prioritized: 'planned',
	released: 'planned',
	deferred: 'on_hold',
	rejected: 'closed',
	duplicate: 'closed',
}

export const phaseLabel = (phase) => ({
	demand: t('nc_nexus', 'Demand'),
	planned: t('nc_nexus', 'Planned'),
	active: t('nc_nexus', 'Active'),
	on_hold: t('nc_nexus', 'On hold'),
	closed: t('nc_nexus', 'Closed'),
	review: t('nc_nexus', 'Benefit review'),
})[phase] || phase

// Phasen, die erst mit Projekten (Stufe 2) belegt werden
export const FUTURE_PHASES = ['planned', 'active', 'review']

/**
 * Ziele als Baum in Anzeige-Reihenfolge (nach Code), mit Tiefe und Pfad.
 *
 * @param {Array} objectives flache Liste mit id, code, title, parentId
 * @return {Array<object>}
 */
export function objectiveTree(objectives) {
	const children = new Map()
	for (const o of objectives) {
		const key = objectives.some(x => x.id === o.parentId) ? o.parentId : null
		if (!children.has(key)) {
			children.set(key, [])
		}
		children.get(key).push(o)
	}
	const result = []
	const walk = (parentId, depth, path) => {
		for (const o of (children.get(parentId) || []).sort((a, b) => a.code.localeCompare(b.code, undefined, { numeric: true }))) {
			const fullPath = path ? `${path} › ${o.code}` : o.code
			result.push({ ...o, depth, path: fullPath })
			walk(o.id, depth + 1, fullPath)
		}
	}
	walk(null, 0, '')
	return result
}

export const dimensionLabel = (dimension) => ({
	benefit: t('nc_nexus', 'Benefit'),
	urgency: t('nc_nexus', 'Urgency'),
	risk: t('nc_nexus', 'Risk'),
})[dimension] || dimension

export const effortLabel = (effort) => ({
	1: t('nc_nexus', '1 – very small (under 5 PD)'),
	3: t('nc_nexus', '3 – small (5–30 PD)'),
	5: t('nc_nexus', '5 – medium (30–200 PD)'),
	8: t('nc_nexus', '8 – large (over 200 PD)'),
})[effort] || String(effort)

export const qualityLabel = (quality) => ({
	rough: t('nc_nexus', 'rough (±50 %)'),
	plausible: t('nc_nexus', 'plausibility-checked (±25 %)'),
	planned: t('nc_nexus', 'planned (±10 %)'),
})[quality] || quality

export const assessmentKindLabel = (kind) => ({
	self: t('nc_nexus', 'Self-assessment'),
	review: t('nc_nexus', 'Review by the portfolio office'),
})[kind] || kind

export const costKindLabel = (kind) => ({
	capex: t('nc_nexus', 'Investment (CAPEX)'),
	opex_once: t('nc_nexus', 'One-time expense'),
	opex_annual: t('nc_nexus', 'Running cost per year'),
})[kind] || kind

export const benefitTypeLabel = (type) => ({
	cost_saving: t('nc_nexus', 'Cost saving'),
	capacity: t('nc_nexus', 'Capacity gain'),
	revenue: t('nc_nexus', 'Revenue effect'),
	avoided_cost: t('nc_nexus', 'Avoided cost'),
	qualitative: t('nc_nexus', 'Qualitative'),
})[type] || type

// Geldbetrag in der Sprache von Nextcloud, ohne Nachkommastellen
export const formatMoney = (value) => value === null || value === undefined
	? '–'
	: Number(value).toLocaleString(getCanonicalLocale(), { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 })

export const gateLabel = (step) => ({
	candidate: t('nc_nexus', 'Ready for prioritization'),
	g2: t('nc_nexus', 'G2 – prioritization'),
	g3: t('nc_nexus', 'G3 – release'),
})[step] || step

export const checkLabel = (key) => ({
	assessment_review: t('nc_nexus', 'Assessment reviewed by the portfolio office'),
	business_case: t('nc_nexus', 'Business case with costs and benefit items'),
	resource_needs: t('nc_nexus', 'Resource needs recorded'),
	org_unit: t('nc_nexus', 'Organizational unit set'),
	capacity: t('nc_nexus', 'Capacity in all bottleneck pools (firm commitment)'),
})[key] || key

export const decisionTypeLabel = (type) => ({
	triage: t('nc_nexus', 'Triage'),
	candidate: t('nc_nexus', 'Ready for prioritization'),
	g2: t('nc_nexus', 'G2 – prioritization'),
	g3: t('nc_nexus', 'G3 – release'),
})[type] || type
