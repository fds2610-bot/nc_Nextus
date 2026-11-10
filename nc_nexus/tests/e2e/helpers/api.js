// @ts-check
// OCS-Zugriffe auf die Demand-API, z. B. zum Anlegen von Testdaten als anderes Konto.
const { request } = require('@playwright/test')
const { ADMIN_PASSWORD, ADMIN_USER, BASE_URL, E2E_PASSWORD } = require('./env')

const API = '/ocs/v2.php/apps/nc_nexus/api/v1'
const PATH = `${API}/demands`

/**
 * API-Kontext für ein Konto (Basic Auth, OCS-Header).
 *
 * @param {string} user Konto
 * @param {string} [password] Passwort, Standard: das der Testkonten
 */
async function apiAs(user, password = E2E_PASSWORD) {
	// Header explizit und ohne Sitzung: sonst übernimmt Nextcloud ggf. das Konto aus Cookies
	return request.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: {
			Authorization: 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64'),
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

/** API-Kontext als Admin der DEV-Umgebung. */
const adminApi = () => apiAs(ADMIN_USER, ADMIN_PASSWORD)

/**
 * OCS-Aufruf mit erwartetem Status, liefert ocs.data.
 *
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {'get'|'post'|'put'|'delete'} method
 * @param {string} path Pfad unterhalb von /api/v1
 * @param {object} [data] Nutzdaten
 * @param {number} [status] erwarteter HTTP-Status
 */
async function call(api, method, path, data, status = method === 'post' ? 201 : 200) {
	const res = await api[method](API + path, data ? { data } : {})
	if (res.status() !== status) {
		throw new Error(`${method.toUpperCase()} ${path}: ${res.status()} ${await res.text()}`)
	}
	return (await res.json()).ocs.data
}

module.exports = { API, PATH, adminApi, apiAs, call, createDemand }
