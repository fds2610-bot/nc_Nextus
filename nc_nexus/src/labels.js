import { getCanonicalLocale, t } from '@nextcloud/l10n'

export const typeLabel = (type) => ({
	mandatory: t('nc_nexus', 'Mandatory / regulatory'),
	strategic: t('nc_nexus', 'Strategic'),
	efficiency: t('nc_nexus', 'Efficiency'),
	lifecycle: t('nc_nexus', 'Maintenance / lifecycle'),
	innovation: t('nc_nexus', 'Innovation'),
})[type] || type

/** Status in der Reihenfolge des Statusautomaten (Kapitel 02, §4.1) */
export const STATUSES = ['draft', 'submitted', 'query', 'further_analysis', 'deferred', 'in_qualification', 'rejected', 'duplicate']

export const statusLabel = (status) => ({
	draft: t('nc_nexus', 'Draft'),
	submitted: t('nc_nexus', 'Submitted'),
	query: t('nc_nexus', 'Query'),
	further_analysis: t('nc_nexus', 'Further analysis'),
	deferred: t('nc_nexus', 'Deferred'),
	in_qualification: t('nc_nexus', 'In qualification'),
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
	status: t('nc_nexus', 'Status'),
})[field] || field

export const actionLabel = (action) => ({
	create: t('nc_nexus', 'Created'),
	update: t('nc_nexus', 'Changed'),
	status_change: t('nc_nexus', 'Status changed'),
	decision: t('nc_nexus', 'Triage decision'),
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
