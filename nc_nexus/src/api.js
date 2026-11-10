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
export const submitDemand = async (id) => data(await axios.post(`${base}/${id}/submit`))
export const deleteDemand = async (id) => data(await axios.delete(`${base}/${id}`))
export const demandHistory = async (id) => data(await axios.get(`${base}/${id}/history`))
