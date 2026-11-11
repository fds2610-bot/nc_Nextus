const { BASE, PASS } = require('./rec')
const API = '/ocs/v2.php/apps/nc_nexus/api/v1'
const mkApi = (user, pass = PASS) => {
  const auth = 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64')
  const call = async (method, p, data, ok) => {
    const res = await fetch(BASE + API + p, { method: method.toUpperCase(), headers: { Authorization: auth, 'OCS-APIREQUEST': 'true', Accept: 'application/json', 'Content-Type': 'application/json' }, body: data ? JSON.stringify(data) : undefined })
    const txt = await res.text()
    if (!res.ok && !(ok || []).includes(res.status)) throw new Error(`${method} ${p}: ${res.status} ${txt.slice(0, 300)}`)
    try { return JSON.parse(txt).ocs.data } catch { return txt }
  }
  return { get: p => call('get', p), post: (p, d) => call('post', p, d || {}), put: (p, d) => call('put', p, d), del: p => call('delete', p), raw: call }
}
const month = (o = 0) => { const d = new Date(); const m = d.getMonth() + o; return `${d.getFullYear() + Math.floor(m / 12)}-${String(m % 12 + 1).padStart(2, '0')}` }
module.exports = { mkApi, month }
