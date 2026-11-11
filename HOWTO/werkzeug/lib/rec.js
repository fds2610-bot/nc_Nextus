// Aufnahme-Bibliothek: CDP-Screencast (scharfe JPEG-Frames), sichtbarer Mauszeiger,
// Untertitel-Einblendung, Sprecher-Zeitleiste für das Audio-Mixing.
const fs = require('fs')
const path = require('path')
const { chromium } = require('/home/fds/projects/nc-nexus/nc_nexus/tests/e2e/node_modules/@playwright/test')

const BASE = process.env.NX_URL || 'http://localhost:8097'
const PASS = 'demo-Pass123!'
const W = 1600, H = 900
const sleep = ms => new Promise(r => setTimeout(r, ms))

const OVERLAY = `
(() => {
  if (window.__ovl) return; window.__ovl = true
  const mk = () => {
    if (!document.documentElement || document.getElementById('nx-cursor')) return
    const c = document.createElement('div'); c.id = 'nx-cursor'
    c.style.cssText = 'position:fixed;left:0;top:0;width:22px;height:22px;z-index:2147483647;pointer-events:none;transform:translate(-100px,-100px);transition:none;'
    c.innerHTML = '<svg width="22" height="22" viewBox="0 0 22 22"><path d="M2 1 L2 17 L6.2 13.2 L9 19.5 L11.6 18.4 L8.8 12.2 L14.5 12 Z" fill="#fff" stroke="#111" stroke-width="1.4" stroke-linejoin="round"/></svg>'
    document.documentElement.appendChild(c)
    const cap = document.createElement('div'); cap.id = 'nx-caption'
    cap.style.cssText = 'position:fixed;left:50%;bottom:22px;transform:translateX(-50%);max-width:1100px;z-index:2147483646;pointer-events:none;display:none;font:500 22px/1.35 system-ui,sans-serif;color:#fff;background:rgba(15,23,35,.86);padding:10px 18px;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.35)'
    document.documentElement.appendChild(cap)
  }
  document.addEventListener('DOMContentLoaded', mk); mk()
  window.__nxMove = (x, y) => { mk(); document.getElementById('nx-cursor').style.transform = 'translate(' + x + 'px,' + y + 'px)' }
  window.__nxRipple = (x, y) => {
    const r = document.createElement('div')
    r.style.cssText = 'position:fixed;z-index:2147483645;pointer-events:none;border-radius:50%;border:3px solid #0082c9;width:10px;height:10px;left:' + (x - 5) + 'px;top:' + (y - 5) + 'px;opacity:.9;transition:all .45s ease-out'
    document.documentElement.appendChild(r)
    requestAnimationFrame(() => { r.style.width = '46px'; r.style.height = '46px'; r.style.left = (x - 23) + 'px'; r.style.top = (y - 23) + 'px'; r.style.opacity = '0' })
    setTimeout(() => r.remove(), 600)
  }
  window.__nxCaption = (who, text, color) => {
    mk(); const c = document.getElementById('nx-caption')
    if (!text) { c.style.display = 'none'; return }
    c.style.display = 'block'
    c.innerHTML = '<span style="color:' + color + ';font-weight:700;margin-right:10px">' + who + '</span>' + text.replace(/</g, '&lt;')
  }
})()
`

class Recorder {
  constructor(outDir) {
    this.outDir = outDir
    this.frames = []
    this.t0 = null
    this.mouse = { x: 800, y: 450 }
    this.timeline = [] // { start, wav, speaker, text }
    this.pending = []
  }

  async start() {
    fs.rmSync(this.outDir, { recursive: true, force: true })
    fs.mkdirSync(path.join(this.outDir, 'frames'), { recursive: true })
    this.browser = await chromium.launch({ args: ['--force-device-scale-factor=1', '--hide-scrollbars'] })
    this.ctx = await this.browser.newContext({ baseURL: BASE, locale: 'de-DE', timezoneId: 'Europe/Berlin', viewport: { width: W, height: H } })
    await this.ctx.addInitScript(OVERLAY)
    this.page = await this.ctx.newPage()
    this.cdp = await this.ctx.newCDPSession(this.page)
    this.cdp.on('Page.screencastFrame', async ev => {
      this.cdp.send('Page.screencastFrameAck', { sessionId: ev.sessionId }).catch(() => {})
      if (!this.recording) return
      const ts = ev.metadata.timestamp
      const i = this.frames.length
      const file = path.join(this.outDir, 'frames', String(i).padStart(6, '0') + '.jpg')
      this.pending.push(fs.promises.writeFile(file, Buffer.from(ev.data, 'base64')))
      this.frames.push({ ts, file })
    })
    this.sessions = {}
    return this.page
  }

  /** Sitzung eines Kontos vorbereiten (unsichtbar, ohne Aufnahme). */
  async prepareUser(user) {
    const c = await this.browser.newContext({ baseURL: BASE, locale: 'de-DE' })
    const p = await c.newPage()
    await p.goto('/login'); await p.fill('#user', user); await p.fill('#password', PASS); await p.click('button[type=submit]')
    await p.waitForURL(u => !u.pathname.includes('/login'), { timeout: 30000 })
    this.sessions[user] = await c.cookies()
    await c.close()
  }

  /** Konto wechseln (Cookies tauschen) und App neu laden. */
  async switchUser(user, url = '/apps/nc_nexus/') {
    if (!this.sessions[user]) await this.prepareUser(user)
    await this.ctx.clearCookies()
    await this.ctx.addCookies(this.sessions[user])
    await this.page.goto(url)
    await this.page.waitForLoadState('networkidle').catch(() => {})
    this.mouse = { x: 800, y: 450 }
    await this.page.evaluate(() => window.__nxMove && window.__nxMove(800, 450)).catch(() => {})
  }

  /** Titelkarte (HTML) anzeigen */
  async card(kicker, title, sub) {
    await this.page.setContent(`<html><body style="margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#00537d,#0082c9 60%,#4fb6e8);font-family:system-ui,sans-serif;color:#fff"><div style="max-width:1100px"><div style="font-size:26px;letter-spacing:.14em;text-transform:uppercase;opacity:.85">${kicker}</div><div style="font-size:70px;font-weight:800;line-height:1.1;margin:18px 0 26px">${title}</div><div style="font-size:28px;opacity:.92;line-height:1.5">${sub}</div></div></body></html>`)
  }

  /** Karte nach ms ausblenden und die App im Konto des Dozenten zeigen */
  async endCard(ms = 8000, user = 'paula') { await sleep(ms); await this.switchUser(user) }

  async begin() {
    this.recording = true
    await this.cdp.send('Page.startScreencast', { format: 'jpeg', quality: 92, maxWidth: W, maxHeight: H, everyNthFrame: 1 })
    this.t0 = Date.now() / 1000
    await sleep(300)
  }

  now() { return Date.now() / 1000 - this.t0 }

  async end(name) {
    await sleep(500)
    await this.cdp.send('Page.stopScreencast').catch(() => {})
    this.recording = false
    await Promise.all(this.pending)
    await this.browser.close()
    // concat-Liste mit echten Frame-Abständen
    const fr = this.frames
    if (!fr.length) throw new Error('keine Frames')
    const base = fr[0].ts
    const endTs = base + (Date.now() / 1000 - this.t0) // Gesamtdauer
    let list = 'ffconcat version 1.0\n'
    for (let i = 0; i < fr.length; i++) {
      const next = i + 1 < fr.length ? fr[i + 1].ts : Math.max(fr[i].ts + 0.04, endTs)
      list += `file '${fr[i].file}'\nduration ${Math.max(0.001, next - fr[i].ts).toFixed(4)}\n`
    }
    list += `file '${fr[fr.length - 1].file}'\n`
    fs.writeFileSync(path.join(this.outDir, 'frames.txt'), list)
    // Zeitversatz: erster Frame vs. Aufnahmestart
    const offset = fr[0].ts - this.t0
    fs.writeFileSync(path.join(this.outDir, 'timeline.json'), JSON.stringify({ offset, lines: this.timeline, name }, null, 1))
  }

  // ---------- Bedienung ----------
  async moveTo(x, y, steps = 28) {
    const { x: x0, y: y0 } = this.mouse
    for (let i = 1; i <= steps; i++) {
      const k = i / steps, e = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2
      const px = x0 + (x - x0) * e, py = y0 + (y - y0) * e
      await this.page.mouse.move(px, py)
      await this.page.evaluate(([a, b]) => window.__nxMove(a, b), [px, py])
      await sleep(14)
    }
    this.mouse = { x, y }
  }

  async point(loc, { dx = 0, dy = 0 } = {}) {
    await loc.scrollIntoViewIfNeeded()
    const b = await loc.boundingBox()
    if (!b) throw new Error('Element ohne Box')
    await this.moveTo(b.x + b.width / 2 + dx, b.y + b.height / 2 + dy)
    return b
  }

  async click(loc, opt = {}) {
    const b = await this.point(loc, opt)
    await sleep(180)
    await this.page.evaluate(([a, c]) => window.__nxRipple(a, c), [this.mouse.x, this.mouse.y])
    await loc.click({ position: undefined, ...opt.click })
    await sleep(350)
    return b
  }

  async type(loc, text, delay = 38) {
    await this.click(loc)
    await loc.fill('')
    await loc.pressSequentially(text, { delay })
    await sleep(250)
  }

  async fill(loc, text) { // lange Texte: zügig, aber sichtbar
    await this.click(loc)
    await loc.fill('')
    await loc.pressSequentially(text, { delay: 14 })
    await sleep(200)
  }

  /** Option per Textteil wählen (nativer Dropdown wird nicht gefilmt) */
  async choose(loc, part) {
    await this.click(loc)
    const val = await loc.evaluate((el, t) => [...el.options].find(o => o.text.includes(t))?.value, part)
    if (val === undefined) throw new Error('Option fehlt: ' + part)
    await loc.selectOption(val)
    await sleep(300)
  }

  /** Wert direkt setzen (z. B. Monatsfeld), mit sichtbarem Klick */
  async setValue(loc, v) { await this.click(loc); await loc.fill(v); await sleep(300) }

  async hover(loc) { await this.point(loc); await sleep(500) }

  /** Zeile sprechen: liefert Dauer; Audio wird beim Mixen an dieser Stelle eingefügt. */
  async say(speaker, text, wav, dur, color) {
    const start = this.now()
    this.timeline.push({ start, speaker, text, wav, dur })
    await this.page.evaluate(([w, t, c]) => window.__nxCaption(w, t, c), [speaker, text, color]).catch(() => {})
    return start
  }
  async endCaption() { await this.page.evaluate(() => window.__nxCaption('', '')).catch(() => {}) }
}

module.exports = { Recorder, sleep, BASE, PASS, W, H }
