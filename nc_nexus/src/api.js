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
