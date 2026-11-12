import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const base = generateOcsUrl('/apps/nc_nexus/api/v1/demands')

const data = (response) => response.data.ocs.data

/**
 * Fehlermeldung aus einer OCS-Antwort (oder dem Axios-Fehler) ermitteln.
 *
 * @param {Error} error Axios-Fehler
 * @return {string}
 */
export function errorMessage(error) {
	return error?.response?.data?.ocs?.data?.message || error.message
}

export const listDemands = async () => data(await axios.get(base))
export const getDemand = async (id) => data(await axios.get(`${base}/${id}`))
export const createDemand = async (demand) => data(await axios.post(base, demand))
export const updateDemand = async (id, demand) => data(await axios.put(`${base}/${id}`, demand))
export const submitDemand = async (id, answer = null) => data(await axios.post(`${base}/${id}/submit`, answer ? { answer } : {}))
export const listComments = async (id) => data(await axios.get(`${base}/${id}/comments`))
export const addComment = async (id, message) => data(await axios.post(`${base}/${id}/comments`, { message }))
export const deleteDemand = async (id) => data(await axios.delete(`${base}/${id}`))
export const triageDemand = async (id, decision) => data(await axios.post(`${base}/${id}/triage`, decision))
export const completeAnalysis = async (id, result) => data(await axios.post(`${base}/${id}/analysis`, { result }))
export const queryDemand = async (id, query) => data(await axios.post(`${base}/${id}/query`, query))
export const similarDemands = async (params) => data(await axios.get(`${base}/similar`, { params }))
export const demandHistory = async (id) => data(await axios.get(`${base}/${id}/history`))

const v1 = (path) => generateOcsUrl('/apps/nc_nexus/api/v1' + path)

export const getMe = async () => data(await axios.get(v1('/me')))
export const listOrgUnits = async () => data(await axios.get(v1('/org-units')))
export const createOrgUnit = async (unit) => data(await axios.post(v1('/org-units'), unit))
export const updateOrgUnit = async (id, unit) => data(await axios.put(v1(`/org-units/${id}`), unit))
export const deleteOrgUnit = async (id) => data(await axios.delete(v1(`/org-units/${id}`)))
export const listRoleAssignments = async () => data(await axios.get(v1('/role-assignments')))
export const assignRole = async (assignment) => data(await axios.post(v1('/role-assignments'), assignment))
export const updateRoleAssignment = async (id, assignment) => data(await axios.put(v1(`/role-assignments/${id}`), assignment))
export const unassignRole = async (id) => data(await axios.delete(v1(`/role-assignments/${id}`)))

/**
 * Konten und Gruppen suchen (Nextcloud-Autovervollständigung).
 *
 * @param {string} search Suchtext
 * @return {Promise<Array<{type: string, id: string, label: string}>>}
 */
export async function searchPrincipals(search) {
	const response = await axios.get(generateOcsUrl('/core/autocomplete/get'), {
		params: { search, itemType: ' ', itemId: ' ', shareTypes: [0, 1], limit: 10 },
	})
	return data(response).map(entry => ({
		type: entry.source === 'groups' ? 'group' : 'user',
		id: entry.id,
		label: entry.label,
	}))
}

export const listDecisions = async () => data(await axios.get(v1('/decisions')))

export const listPools = async () => data(await axios.get(v1('/pools')))
export const createPool = async (pool) => data(await axios.post(v1('/pools'), pool))
export const updatePool = async (id, pool) => data(await axios.put(v1(`/pools/${id}`), pool))
export const deletePool = async (id) => data(await axios.delete(v1(`/pools/${id}`)))
export const poolCapacity = async (id, from, months = 12) => data(await axios.get(v1(`/pools/${id}/capacity`), { params: { from, months } }))
export const setPoolCapacity = async (id, period, values) => data(await axios.put(v1(`/pools/${id}/capacity/${period}`), values))
export const capacityHeatmap = async (from, months = 12) => data(await axios.get(v1('/capacity/heatmap'), { params: { from, months } }))
export const poolLoad = async (id, period) => data(await axios.get(v1(`/pools/${id}/load/${period}`)))
export const demandNeeds = async (id) => data(await axios.get(v1(`/demands/${id}/needs`)))
export const saveDemandNeeds = async (id, needs) => data(await axios.put(v1(`/demands/${id}/needs`), { needs }))
export const portfolioOverview = async () => data(await axios.get(v1('/portfolio')))

export const listObjectives = async () => data(await axios.get(v1('/objectives')))
export const createObjective = async (o) => data(await axios.post(v1('/objectives'), o))
export const updateObjective = async (id, o) => data(await axios.put(v1(`/objectives/${id}`), o))
export const deleteObjective = async (id) => data(await axios.delete(v1(`/objectives/${id}`)))
export const demandObjectives = async (id) => data(await axios.get(v1(`/demands/${id}/objectives`)))
export const saveDemandObjectives = async (id, objectiveIds) => data(await axios.put(v1(`/demands/${id}/objectives`), { objectiveIds }))

export const scoringModel = async () => data(await axios.get(v1('/scoring/model')))
export const saveCriteria = async (criteria, note) => data(await axios.post(v1('/scoring/sets'), { criteria, note }))
export const updateBucket = async (code, values) => data(await axios.put(v1(`/scoring/buckets/${code}`), values))
export const demandAssessments = async (id) => data(await axios.get(v1(`/demands/${id}/assessments`)))
export const assessDemand = async (id, assessment) => data(await axios.post(v1(`/demands/${id}/assessments`), assessment))
export const getRanking = async () => data(await axios.get(v1('/ranking')))
export const overrideRank = async (id, rank, reason) => data(await axios.put(v1(`/demands/${id}/rank-override`), { rank, reason }))

export const getBusinessCase = async (id) => data(await axios.get(v1(`/demands/${id}/business-case`)))
export const saveBusinessCase = async (id, content) => data(await axios.put(v1(`/demands/${id}/business-case`), { content }))
export const benefitRegister = async () => data(await axios.get(v1('/benefits')))
export const benefitFactors = async () => data(await axios.get(v1('/benefits/factors')))
export const trackBenefit = async (id, key, tracking) => data(await axios.put(v1(`/demands/${id}/benefits/${key}`), { tracking }))
export const reviewBenefit = async (id, key, review) => data(await axios.post(v1(`/demands/${id}/benefits/${key}/reviews`), { review }))

export const gateStatus = async (id) => data(await axios.get(v1(`/demands/${id}/gate`)))
export const decideGate = async (id, decision) => data(await axios.post(v1(`/demands/${id}/gate`), decision))
export const capacityCut = async () => data(await axios.get(v1('/capacity/cut')))

// Szenarioanalyse (POR-003)
export const managementDashboard = async () => data(await axios.get(v1('/dashboard')))
export const listScenarios = async () => data(await axios.get(v1('/scenarios')))
export const getScenario = async (id) => data(await axios.get(v1(`/scenarios/${id}`)))
export const createScenario = async (scenario) => data(await axios.post(v1('/scenarios'), scenario))
export const updateScenario = async (id, scenario) => data(await axios.put(v1(`/scenarios/${id}`), scenario))
export const deleteScenario = async (id) => data(await axios.delete(v1(`/scenarios/${id}`)))
export const addScenarioChange = async (id, change) => data(await axios.post(v1(`/scenarios/${id}/changes`), change))
export const removeScenarioChange = async (id, changeId) => data(await axios.delete(v1(`/scenarios/${id}/changes/${changeId}`)))
export const scenarioAction = async (id, action, payload = {}) => data(await axios.post(v1(`/scenarios/${id}/${action}`), payload))
export const setScenarioTask = async (id, index, done) => data(await axios.put(v1(`/scenarios/${id}/tasks/${index}`), { done }))

export const getPlanning = async (id) => data(await axios.get(v1(`/demands/${id}/planning`)))
export const connectPlanning = async (id, projectId = null) => data(await axios.post(v1(`/demands/${id}/planning`), { projectId }))
export const disconnectPlanning = async (id) => data(await axios.delete(v1(`/demands/${id}/planning`)))
export const planningProjects = async () => data(await axios.get(v1('/planning/projects')))

// Stufe 3: Gates, Governance-Konfiguration, Sitzungen, Auflagen, Datenqualität
export const submitGate = async (id, step) => data(await axios.post(`${base}/${id}/gate/submit`, { step }))
export const governance = async () => data(await axios.get(v1('/governance')))
export const createBody = async (body) => data(await axios.post(v1('/bodies'), body))
export const updateBody = async (id, body) => data(await axios.put(v1(`/bodies/${id}`), body))
export const deleteBody = async (id) => data(await axios.delete(v1(`/bodies/${id}`)))
export const addMember = async (bodyId, member) => data(await axios.post(v1(`/bodies/${bodyId}/members`), member))
export const updateMember = async (bodyId, id, member) => data(await axios.put(v1(`/bodies/${bodyId}/members/${id}`), member))
export const removeMember = async (bodyId, id) => data(await axios.delete(v1(`/bodies/${bodyId}/members/${id}`)))
export const createRight = async (right) => data(await axios.post(v1('/decision-rights'), right))
export const updateRight = async (id, right) => data(await axios.put(v1(`/decision-rights/${id}`), right))
export const endRight = async (id) => data(await axios.delete(v1(`/decision-rights/${id}`)))
export const createGateSet = async (set) => data(await axios.post(v1('/gate-sets'), set))
export const updateGateSet = async (id, set) => data(await axios.put(v1(`/gate-sets/${id}`), set))
export const retireGateSet = async (id) => data(await axios.delete(v1(`/gate-sets/${id}`)))
export const listSessions = async () => data(await axios.get(v1('/sessions')))
export const getSession = async (id) => data(await axios.get(v1(`/sessions/${id}`)))
export const createSession = async (session) => data(await axios.post(v1('/sessions'), session))
export const updateSession = async (id, session) => data(await axios.put(v1(`/sessions/${id}`), session))
export const deleteSession = async (id) => data(await axios.delete(v1(`/sessions/${id}`)))
export const sessionStatus = async (id, status, minutes = null) => data(await axios.post(v1(`/sessions/${id}/status`), { status, minutes }))
export const generateAgenda = async (id) => data(await axios.post(v1(`/sessions/${id}/agenda`)))
export const addTopic = async (id, title) => data(await axios.post(v1(`/sessions/${id}/topics`), { title }))
export const updateAgendaItem = async (id, values) => data(await axios.put(v1(`/agenda-items/${id}`), values))
export const removeAgendaItem = async (id) => data(await axios.delete(v1(`/agenda-items/${id}`)))
export const castVote = async (id, vote) => data(await axios.post(v1(`/agenda-items/${id}/vote`), vote))
export const decideItem = async (id, decision) => data(await axios.post(v1(`/agenda-items/${id}/decision`), decision))
export const adjournItem = async (id) => data(await axios.post(v1(`/agenda-items/${id}/adjourn`)))
export const listConditions = async () => data(await axios.get(v1('/conditions')))
export const createCondition = async (demandId, condition) => data(await axios.post(v1(`/demands/${demandId}/conditions`), condition))
export const closeCondition = async (id, status, evidence) => data(await axios.post(v1(`/conditions/${id}/close`), { status, evidence }))
export const dataQuality = async () => data(await axios.get(v1('/quality')))

// Stufe 4: Projektsteuerung, Risiken, Change Requests, Konflikte, Kennzahlen, Schwellen
export const listProjects = async () => data(await axios.get(v1('/projects')))
export const getProject = async (id) => data(await axios.get(v1(`/projects/${id}`)))
export const updateProject = async (id, values) => data(await axios.put(v1(`/projects/${id}`), values))
export const addMilestone = async (id, m) => data(await axios.post(v1(`/projects/${id}/milestones`), m))
export const updateMilestone = async (id, mid, m) => data(await axios.put(v1(`/projects/${id}/milestones/${mid}`), m))
export const deleteMilestone = async (id, mid) => data(await axios.delete(v1(`/projects/${id}/milestones/${mid}`)))
export const reportTemplate = async (id) => data(await axios.get(v1(`/projects/${id}/reports/template`)))
export const addReport = async (id, report) => data(await axios.post(v1(`/projects/${id}/reports`), report))
export const addActual = async (id, actual) => data(await axios.post(v1(`/projects/${id}/actuals`), actual))
export const importActuals = async (csv) => data(await axios.post(v1('/actuals/import'), { csv }))
export const projectRisks = async (id) => data(await axios.get(v1(`/projects/${id}/risks`)))
export const addRisk = async (id, risk) => data(await axios.post(v1(`/projects/${id}/risks`), risk))
export const updateRisk = async (riskId, risk) => data(await axios.put(v1(`/risks/${riskId}`), risk))
export const portfolioRisks = async () => data(await axios.get(v1('/risks')))
export const projectIssues = async (id) => data(await axios.get(v1(`/projects/${id}/issues`)))
export const addIssue = async (id, issue) => data(await axios.post(v1(`/projects/${id}/issues`), issue))
export const updateIssue = async (id, issueId, issue) => data(await axios.put(v1(`/projects/${id}/issues/${issueId}`), issue))
export const addAction = async (id, action) => data(await axios.post(v1(`/projects/${id}/actions`), action))
export const projectChanges = async (id) => data(await axios.get(v1(`/projects/${id}/changes`)))
export const addChange = async (id, change) => data(await axios.post(v1(`/projects/${id}/changes`), change))
export const updateChange = async (id, changeId, change) => data(await axios.put(v1(`/projects/${id}/changes/${changeId}`), change))
export const submitChange = async (id, changeId) => data(await axios.post(v1(`/projects/${id}/changes/${changeId}/submit`)))
export const decideChange = async (id, changeId, decision) => data(await axios.post(v1(`/projects/${id}/changes/${changeId}/decision`), decision))
export const listConflicts = async () => data(await axios.get(v1('/conflicts')))
export const detectConflicts = async () => data(await axios.post(v1('/conflicts/detect')))
export const resolveConflict = async (id, resolution) => data(await axios.post(v1(`/conflicts/${id}/resolve`), resolution))
export const getPostCalculation = async () => data(await axios.get(v1('/post-calculation')))
export const estimateFactors = async () => data(await axios.get(v1('/post-calculation/factors')))
export const listKpis = async () => data(await axios.get(v1('/kpis')))
export const addKpi = async (kpi) => data(await axios.post(v1('/kpis'), kpi))
export const updateKpi = async (id, kpi) => data(await axios.put(v1(`/kpis/${id}`), kpi))
export const getSettings = async () => data(await axios.get(v1('/settings')))
export const saveSettings = async (values) => data(await axios.put(v1('/settings'), values))
