// @ts-check
// OCS-Zugriffe auf die Demand-API, z. B. zum Anlegen von Testdaten als anderes Konto.
const { request } = require('@playwright/test')
const { BASE_URL, E2E_PASSWORD } = require('./env')

const PATH = '/ocs/v2.php/apps/nc_nexus/api/v1/demands'

/**
 * API-Kontext für ein Konto (Basic Auth, OCS-Header).
 *
 * @param {string} user Konto
 */
async function apiAs(user) {
	// Header explizit und ohne Sitzung: sonst übernimmt Nextcloud ggf. das Konto aus Cookies
	return request.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: 'Basic ' + Buffer.from(`${user}:${E2E_PASSWORD}`).toString('base64'),
			'OCS-APIREQUEST': 'true',
			Accept: 'application/json',
		},
	})
}

/**
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {Record<string, string>} demand
 */
async function createDemand(api, demand) {
	const res = await api.post(PATH, { data: demand })
	if (res.status() !== 201) {
		throw new Error(`Antrag anlegen fehlgeschlagen: ${res.status()} ${await res.text()}`)
	}
	return (await res.json()).ocs.data
}

module.exports = { PATH, apiAs, createDemand }
