import { getCanonicalLocale, n, t } from '@nextcloud/l10n'

export const typeLabel = (type) => ({
	mandatory: t('nc_nexus', 'Mandatory / regulatory'),
	strategic: t('nc_nexus', 'Strategic'),
	efficiency: t('nc_nexus', 'Efficiency'),
	lifecycle: t('nc_nexus', 'Maintenance / lifecycle'),
	innovation: t('nc_nexus', 'Innovation'),
})[type] || type

/** Status in der Reihenfolge des Statusautomaten (Kapitel 02, §4.1) */
export const STATUSES = ['draft', 'submitted', 'query', 'further_analysis', 'deferred', 'in_qualification', 'candidate', 'prioritized', 'released', 'in_execution', 'paused', 'completed', 'closed', 'cancelled', 'rejected', 'duplicate']

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
	in_execution: t('nc_nexus', 'In execution'),
	paused: t('nc_nexus', 'Paused'),
	completed: t('nc_nexus', 'Completed'),
	closed: t('nc_nexus', 'Closed'),
	cancelled: t('nc_nexus', 'Cancelled'),
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
	in_execution: 'accepted',
	paused: 'on-hold',
	completed: 'submitted',
	closed: 'draft',
	cancelled: 'rejected',
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
	approved: t('nc_nexus', 'Approved'),
	approved_with_conditions: t('nc_nexus', 'Approved with conditions'),
	rework: t('nc_nexus', 'Rework'),
	cancelled: t('nc_nexus', 'Cancelled'),
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
	approved: 'accepted',
	approved_with_conditions: 'accepted',
	rework: 'on-hold',
	cancelled: 'rejected',
})[code] || 'draft'

export const codeHint = (code) => ({
	accepted: t('nc_nexus', 'Continue with qualification and business case.'),
	further_analysis: t('nc_nexus', 'No decision possible without more information; assign an analysis with a due date.'),
	deferred: t('nc_nexus', 'Deliberately postponed; it comes back on the resubmission date.'),
	rejected: t('nc_nexus', 'Bindingly not accepted; the reason stays visible in the register.'),
	duplicate: t('nc_nexus', 'Merged into another demand that leads from now on.'),
	query: t('nc_nexus', 'Information is missing; the requestor completes the demand and submits it again.'),
})[code] || ''

// Ausführliche Erklärung je Triage-Code für den Tooltip im Triage-Dialog
export const codeTooltip = (code) => ({
	accepted: t('nc_nexus', 'Accept: the demand is understandable, plausible and worth pursuing. It moves on to qualification: the requestor or the area prepares the business case, the assessment and the resource demand, so that the board can compare it with other demands at G2. Optionally set the project class now (XS/S use the shortened path). A reason is optional.'),
	further_analysis: t('nc_nexus', 'Further analysis: a sound decision is not possible yet, e.g. because the effort, the technical feasibility or the benefit is unclear. Name a person who clarifies the open points and a due date. The analysis appears in their work queue; when it is completed, the demand comes back to triage with the result.'),
	deferred: t('nc_nexus', 'Defer: the demand is sensible, but not now – e.g. no capacity, dependence on another project or a better time later. A resubmission date is mandatory; on that date the demand reappears under “Resubmission due” and is triaged again. Deferred demands are not lost and do not block capacity.'),
	rejected: t('nc_nexus', 'Reject: the demand is bindingly not pursued, e.g. because it does not fit the strategy, the benefit is too low or a different solution exists. A reason is mandatory; it is visible to the requestor and stays in the decision register, so that the same demand is not submitted again without new arguments.'),
	duplicate: t('nc_nexus', 'Duplicate: the same or a very similar need has already been submitted. Choose the leading demand; similar demands found by the system are listed first. This demand is closed and refers to the leading one, which continues alone; the decision remains traceable in the decision register.'),
	query: t('nc_nexus', 'Query to requestor: information is missing for a decision, e.g. the problem description or the expected benefit is too vague. Write your question and a due date. The demand goes back to the requestor (status “query”), who answers in the comment thread, completes the demand and submits it again; then it is back in triage.'),
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
	in_execution: 'active',
	paused: 'on_hold',
	completed: 'review',
	closed: 'closed',
	cancelled: 'closed',
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

// Realisierungsstatus einer Nutzenposition (Kapitel 03 §4.1)
export const realizationStatusLabel = (status) => ({
	not_started: t('nc_nexus', 'Not started'),
	on_track: t('nc_nexus', 'On track'),
	at_risk: t('nc_nexus', 'At risk'),
	realized: t('nc_nexus', 'Realized'),
	missed: t('nc_nexus', 'Missed'),
})[status] || status

export const realizationStatusChip = (status) => ({
	not_started: 'nx-chip--draft',
	on_track: 'nx-chip--submitted',
	at_risk: 'nx-chip--on-hold',
	realized: 'nx-chip--accepted',
	missed: 'nx-chip--rejected',
})[status] || 'nx-chip--draft'

// Bewertung eines Nutzenreviews
export const reviewAssessmentLabel = (a) => ({
	achieved: t('nc_nexus', 'Benefit achieved'),
	partial: t('nc_nexus', 'Partially achieved'),
	missed: t('nc_nexus', 'Benefit missed'),
})[a] || a

// Korrekturfaktor als Prozent (0,52 → „52 %“)
export const formatFactor = (f) => f === null || f === undefined ? '–' : Math.round(f * 100) + ' %'

// Geldbetrag in der Sprache von Nextcloud, ohne Nachkommastellen
export const formatMoney = (value) => value === null || value === undefined
	? '–'
	: Number(value).toLocaleString(getCanonicalLocale(), { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 })

export const gateLabel = (step) => ({
	candidate: t('nc_nexus', 'Ready for prioritization'),
	g1: t('nc_nexus', 'G1 – qualification'),
	g2: t('nc_nexus', 'G2 – prioritization'),
	g3: t('nc_nexus', 'G3 – release'),
	g4: t('nc_nexus', 'G4 – phase release'),
	g5: t('nc_nexus', 'G5 – acceptance / go-live'),
	g6: t('nc_nexus', 'G6 – benefit review'),
	change: t('nc_nexus', 'Change request above threshold'),
})[step] || step

/** Beschlussarten an Gates (Kapitel 02 §3) */
export const GATE_OUTCOMES = ['approved', 'approved_with_conditions', 'rework', 'deferred', 'rejected', 'cancelled']

// Beschlussart je Gate in Worten (Kapitel 02, Gate-Steckbriefe)
export const gateOutcomeLabel = (gate, outcome) => {
	const specific = {
		g2: { approved: t('nc_nexus', 'Prioritize – becomes a project'), deferred: t('nc_nexus', 'Defer – not prioritized now') },
		g3: { approved: t('nc_nexus', 'Release'), deferred: t('nc_nexus', 'Postpone the start') },
		g4: { approved: t('nc_nexus', 'Continue with the next phase'), deferred: t('nc_nexus', 'Pause'), cancelled: t('nc_nexus', 'Cancel the project') },
		g5: { approved: t('nc_nexus', 'Accept'), approved_with_conditions: t('nc_nexus', 'Accept with open items'), rejected: t('nc_nexus', 'Not accepted') },
		g6: { approved: t('nc_nexus', 'Benefit confirmed'), approved_with_conditions: t('nc_nexus', 'Benefit partly achieved'), rejected: t('nc_nexus', 'Benefit missed') },
	}[gate]?.[outcome]
	return specific || {
		approved: t('nc_nexus', 'Approve'),
		approved_with_conditions: t('nc_nexus', 'Approve with conditions'),
		rework: t('nc_nexus', 'Rework – back with a due date'),
		deferred: t('nc_nexus', 'Defer'),
		rejected: t('nc_nexus', 'Reject'),
		cancelled: t('nc_nexus', 'Cancel'),
	}[outcome] || outcome
}

export const bodyTypeLabel = (type) => ({
	executive: t('nc_nexus', 'Executive board'),
	portfolio_board: t('nc_nexus', 'Portfolio board'),
	resource_board: t('nc_nexus', 'Resource board'),
	architecture_board: t('nc_nexus', 'Architecture and security board'),
	steering_committee: t('nc_nexus', 'Steering committee'),
	pmo: t('nc_nexus', 'Portfolio office'),
})[type] || type

export const sessionStatusLabel = (status) => ({
	planned: t('nc_nexus', 'Planned'),
	agenda_final: t('nc_nexus', 'Agenda final'),
	held: t('nc_nexus', 'In session'),
	minuted: t('nc_nexus', 'Minuted'),
})[status] || status

export const instanceStatusLabel = (status) => ({
	open: t('nc_nexus', 'Pending'),
	incomplete: t('nc_nexus', 'Incomplete'),
	ready: t('nc_nexus', 'Ready for decision'),
	scheduled: t('nc_nexus', 'On the agenda'),
	adjourned: t('nc_nexus', 'Adjourned'),
	decided: t('nc_nexus', 'Decided'),
})[status] || status

export const voteLabel = (vote) => ({
	yes: t('nc_nexus', 'Yes'),
	no: t('nc_nexus', 'No'),
	abstain: t('nc_nexus', 'Abstain'),
})[vote] || vote

export const checkLabel = (key) => ({
	assessment_review: t('nc_nexus', 'Assessment reviewed by the portfolio office'),
	business_case: t('nc_nexus', 'Business case with costs and benefit items'),
	resource_needs: t('nc_nexus', 'Resource needs recorded'),
	org_unit: t('nc_nexus', 'Organizational unit set'),
	capacity: t('nc_nexus', 'Capacity in all bottleneck pools (firm commitment)'),
	project_class: t('nc_nexus', 'Project class set'),
	objectives: t('nc_nexus', 'Assigned to strategic objectives'),
	open_conditions: t('nc_nexus', 'No open blocking conditions'),
	planning_link: t('nc_nexus', 'Connected with the planning tool'),
	benefit_reviews: t('nc_nexus', 'Every benefit item has a benefit review'),
})[key] || key

export const decisionTypeLabel = (type) => ({
	triage: t('nc_nexus', 'Triage'),
	candidate: t('nc_nexus', 'Ready for prioritization'),
	g2: t('nc_nexus', 'G2 – prioritization'),
	g3: t('nc_nexus', 'G3 – release'),
	g4: t('nc_nexus', 'G4 – phase release'),
	g5: t('nc_nexus', 'G5 – acceptance / go-live'),
	g6: t('nc_nexus', 'G6 – benefit review'),
	change: t('nc_nexus', 'Change request'),
})[type] || type

// ---- Stufe 4: Steuerung im Betrieb ---------------------------------------------------------------

export const ragLabel = (rag) => ({
	green: t('nc_nexus', 'Green'),
	yellow: t('nc_nexus', 'Yellow'),
	red: t('nc_nexus', 'Red'),
})[rag] || '–'

export const dimensionRagLabel = (dimension) => ({
	scope: t('nc_nexus', 'Scope'),
	time: t('nc_nexus', 'Schedule'),
	cost: t('nc_nexus', 'Cost'),
	risk: t('nc_nexus', 'Risk'),
})[dimension] || dimension

// Regeln des Health-Indikators (Kapitel 02 §4.5)
export const healthRuleLabel = (rule) => ({
	milestone_overdue: t('nc_nexus', 'Milestone overdue'),
	milestone_late: t('nc_nexus', 'Milestone forecast after due date'),
	forecast_over_budget: t('nc_nexus', 'Forecast above budget'),
	report_overdue: t('nc_nexus', 'Status report overdue'),
	effort_deviation: t('nc_nexus', 'Actual effort deviates from plan'),
	blocking_condition_overdue: t('nc_nexus', 'Blocking condition overdue'),
	critical_risk_unmitigated: t('nc_nexus', 'Critical risk without mitigation'),
	critical_issue: t('nc_nexus', 'Critical issue open'),
	conflict_escalated: t('nc_nexus', 'Resource conflict escalated'),
})[rule] || rule

export const healthSourceLabel = (source) => ({
	milestones: t('nc_nexus', 'Milestones'),
	status_report: t('nc_nexus', 'Status report'),
	actuals: t('nc_nexus', 'Actuals'),
	conditions: t('nc_nexus', 'Decision follow-up'),
	risks: t('nc_nexus', 'Risks'),
	issues: t('nc_nexus', 'Issues'),
	conflicts: t('nc_nexus', 'Conflicts'),
})[source] || source

export const riskCategoryLabel = (c) => ({
	technical: t('nc_nexus', 'Technical'),
	schedule: t('nc_nexus', 'Schedule'),
	resources: t('nc_nexus', 'Resources'),
	supplier: t('nc_nexus', 'Supplier'),
	financial: t('nc_nexus', 'Financial'),
	compliance: t('nc_nexus', 'Compliance'),
	security: t('nc_nexus', 'Security'),
	organizational: t('nc_nexus', 'Organizational'),
})[c] || c

export const riskStatusLabel = (s) => ({
	open: t('nc_nexus', 'Pending'),
	mitigated: t('nc_nexus', 'Mitigated'),
	occurred: t('nc_nexus', 'Occurred'),
	closed: t('nc_nexus', 'Closed'),
	resolved: t('nc_nexus', 'Resolved'),
})[s] || s

export const severityLabel = (s) => ({
	low: t('nc_nexus', 'Low'),
	medium: t('nc_nexus', 'Medium'),
	high: t('nc_nexus', 'High'),
	critical: t('nc_nexus', 'Critical'),
})[s] || s

export const changeTypeLabel = (type) => ({
	scope: t('nc_nexus', 'Scope'),
	time: t('nc_nexus', 'Schedule'),
	cost: t('nc_nexus', 'Cost'),
	resource: t('nc_nexus', 'Resources'),
	benefit: t('nc_nexus', 'Benefit'),
})[type] || type

export const changeStatusLabel = (s) => ({
	draft: t('nc_nexus', 'Draft'),
	submitted: t('nc_nexus', 'Submitted'),
	incorporated: t('nc_nexus', 'Approved and incorporated'),
	rejected: t('nc_nexus', 'Rejected'),
})[s] || s

export const thresholdReasonLabel = (r) => ({
	budget: t('nc_nexus', 'budget above threshold'),
	time: t('nc_nexus', 'schedule above threshold'),
	resources: t('nc_nexus', 'bottleneck resources above threshold'),
	benefit: t('nc_nexus', 'committed benefit changes'),
})[r] || r

export const conflictTypeLabel = (type) => ({
	overbooking: t('nc_nexus', 'Overbooking'),
	uncovered_demand: t('nc_nexus', 'Uncovered demand'),
	bottleneck_peak: t('nc_nexus', 'Bottleneck peak'),
	wip_exceeded: t('nc_nexus', 'WIP limit exceeded'),
	zombie_project: t('nc_nexus', 'Zombie project'),
	priority_inversion: t('nc_nexus', 'Priority inversion'),
})[type] || type

export const conflictOptionLabel = (o) => ({
	sequenced: t('nc_nexus', 'Stagger in time'),
	scope_cut: t('nc_nexus', 'Cut scope'),
	substituted: t('nc_nexus', 'Substitute (other skill or pool)'),
	external: t('nc_nexus', 'Source externally'),
	paused: t('nc_nexus', 'Pause the lower-ranked project'),
	deprioritised: t('nc_nexus', 'Defer the higher-ranked project (portfolio board)'),
})[o] || o

export const escalationLabel = (level) => ({
	0: t('nc_nexus', 'not escalated'),
	1: t('nc_nexus', 'Level 1 · bilateral'),
	2: t('nc_nexus', 'Level 2 · resource board'),
	3: t('nc_nexus', 'Level 3 · portfolio board'),
})[level] || String(level)

export const ragChip = (rag) => ({ green: 'nx-chip--accepted', yellow: 'nx-chip--on-hold', red: 'nx-chip--rejected' })[rag] || 'nx-chip--draft'

/**
 * Prozessfarben (Governance-Diagramm und Antragsliste): von der Einreichung bis zur Abnahme (G5)
 * gleichmäßig von Hell- nach Dunkelblau; das Nutzenreview (G6, nach Projektende) immer gelb.
 */
export const PROCESS_STAGES = ['submitted', 'g1', 'g2', 'g3', 'g4', 'g5', 'g6']
const BLUE_STEPS = PROCESS_STAGES.length - 2
export const stageColor = (stage) => {
	if (stage === 'g6') {
		return { fill: '#FACC15', text: '#1F2937' }
	}
	const i = Math.max(0, PROCESS_STAGES.indexOf(stage))
	const light = 86 - (86 - 26) * i / BLUE_STEPS
	return { fill: `hsl(214 82% ${light}%)`, text: light > 58 ? '#0F2A4D' : '#FFFFFF' }
}

/**
 * Zuletzt durchlaufene Stufe eines Antrags (null = noch nicht eingereicht oder ausgeschieden).
 * G1 gilt mit „Zur Priorisierung“ als bestanden; G4 mit dem Wechsel in die Umsetzung.
 *
 * @param {string} status Status des Antrags
 */
export const passedStage = (status) => ({
	submitted: 'submitted',
	query: 'submitted',
	further_analysis: 'submitted',
	in_qualification: 'submitted',
	candidate: 'g1',
	prioritized: 'g2',
	released: 'g3',
	paused: 'g3',
	in_execution: 'g4',
	completed: 'g5',
	closed: 'g6',
})[status] ?? null

export const stageLabel = (stage) => stage === 'submitted' ? t('nc_nexus', 'Submitted') : gateLabel(stage)

// Ergebnis des Kapazitätsschnitts je Vorhaben (Kapitel 04 §5)
export const planChip = (r) => r
	? ({ startable: 'accepted', shifted: 'on-hold', waiting: 'rejected', budget: 'rejected', unassessed: 'draft' }[r.state] || 'draft')
	: 'draft'

export const planText = (r) => {
	if (!r) {
		return '–'
	}
	switch (r.state) {
	case 'startable': return r.start && r.start > thisMonth()
		? t('nc_nexus', 'startable from {month}', { month: formatMonth(r.start) })
		: t('nc_nexus', 'startable')
	case 'shifted': return t('nc_nexus', 'startable from {month}', { month: formatMonth(r.start) })
	case 'waiting': return r.pool ? t('nc_nexus', 'waiting for pool {pool}', { pool: r.pool }) : t('nc_nexus', 'waiting')
	case 'budget': return t('nc_nexus', 'waiting for budget')
	default: return t('nc_nexus', 'not assessed yet')
	}
}

// Szenarioanalyse (POR-003)
export const SCENARIO_CHANGE_TYPES = ['add_project', 'remove_project', 'pause', 'shift_start', 'change_rank', 'change_capacity', 'change_budget', 'change_quota']

export const scenarioChangeLabel = (type) => ({
	add_project: t('nc_nexus', 'Add a project'),
	remove_project: t('nc_nexus', 'Take a project out'),
	pause: t('nc_nexus', 'Pause a running project'),
	shift_start: t('nc_nexus', 'Shift a start'),
	change_rank: t('nc_nexus', 'Change a rank'),
	change_capacity: t('nc_nexus', 'Change pool capacity'),
	change_budget: t('nc_nexus', 'Change a bucket budget'),
	change_quota: t('nc_nexus', 'Change a bucket target share'),
})[type] || type

const signed = (v) => (v > 0 ? '+' : '') + formatNumber(v)

// Änderung als Satz („Pool SEC: +2 FTE ab Jan. 27“)
export const scenarioChangeText = (c) => {
	const p = c.parameters || {}
	const label = p.label || c.targetRef
	switch (c.changeType) {
	case 'add_project': return p.density
		? t('nc_nexus', '{label} added to bucket {bucket} (assumed value density {d})', { label, bucket: p.bucket, d: formatNumber(p.density) })
		: t('nc_nexus', '{label} added to bucket {bucket}', { label, bucket: p.bucket })
	case 'remove_project': return t('nc_nexus', '{label} taken out of the cut', { label })
	case 'pause': return t('nc_nexus', '{label} paused from {month}, commitments released', { label, month: formatMonth(p.from) })
	case 'shift_start': return n('nc_nexus', '{label} starts %n month later', '{label} starts %n months later', p.months, { label })
	case 'change_rank': return t('nc_nexus', '{label} moved to rank {rank}', { label, rank: p.rank })
	case 'change_capacity': return t('nc_nexus', 'Pool {label}: {fte} FTE from {month}', { label, fte: signed(p.fte), month: formatMonth(p.from) })
	case 'change_budget': return p.budget === null || p.budget === undefined
		? t('nc_nexus', 'Bucket {label}: no budget frame', { label })
		: t('nc_nexus', 'Bucket {label}: budget frame {amount}', { label, amount: formatMoney(p.budget) })
	case 'change_quota': return t('nc_nexus', 'Bucket {label}: target share {p} %', { label, p: formatNumber(p.sharePct) })
	default: return c.changeType
	}
}

export const scenarioStatusLabel = (status) => ({
	draft: t('nc_nexus', 'Draft'),
	presented: t('nc_nexus', 'Presented'),
	adopted: t('nc_nexus', 'Adopted'),
	discarded: t('nc_nexus', 'Discarded'),
})[status] || status

export const scenarioStatusChip = (status) => ({ draft: 'draft', presented: 'submitted', adopted: 'accepted', discarded: 'rejected' })[status] || 'draft'

export const scenarioEffectLabel = (effect) => ({
	rises: t('nc_nexus', 'rises above the line'),
	falls: t('nc_nexus', 'falls below the line'),
	earlier: t('nc_nexus', 'starts earlier'),
	later: t('nc_nexus', 'starts later'),
	added: t('nc_nexus', 'new'),
	removed: t('nc_nexus', 'taken out'),
	changed: t('nc_nexus', 'changed'),
	unchanged: t('nc_nexus', 'unchanged'),
})[effect] || effect

export const scenarioEffectChip = (effect) => ({
	rises: 'accepted', earlier: 'accepted', added: 'submitted', falls: 'rejected', later: 'on-hold', removed: 'rejected', changed: 'on-hold', unchanged: 'draft',
})[effect] || 'draft'
