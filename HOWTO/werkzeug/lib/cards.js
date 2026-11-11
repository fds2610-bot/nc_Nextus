// Grafikkarten (HTML) für die Podcast-Bildspur. Elemente mit at=Sekunden erscheinen nach Kartenbeginn.
const CSS = `<style>
.nxc{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;font-family:system-ui,'Noto Sans',sans-serif;color:#fff;
 background:linear-gradient(135deg,#003e5c 0%,#0082c9 62%,#3fb0e6 100%);}
.nxc.dark{background:linear-gradient(135deg,#0e1b27,#17324a 70%,#1d4666)}
.nxc.warm{background:linear-gradient(135deg,#3b1d12,#8a3b17 70%,#c9692a)}
.nxc .in{max-width:1240px;width:100%;padding:0 70px;box-sizing:border-box}
.nxc .k{font-size:24px;letter-spacing:.16em;text-transform:uppercase;opacity:.82;margin-bottom:16px}
.nxc h1{font-size:62px;line-height:1.12;margin:0 0 26px;font-weight:800}
.nxc h1.big{font-size:84px}
.nxc .sub{font-size:30px;line-height:1.45;opacity:.94}
.nxc .i{opacity:0;transform:translateY(18px);transition:all .6s ease}
.nxc .i.on{opacity:1;transform:none}
.nxc ul{list-style:none;padding:0;margin:0}
.nxc li{font-size:36px;line-height:1.3;margin:0 0 20px;padding-left:54px;position:relative}
.nxc li:before{content:'✓';position:absolute;left:0;top:0;width:38px;height:38px;border-radius:50%;background:#7fd1ff;color:#003e5c;font-size:26px;line-height:38px;text-align:center;font-weight:800}
.nxc .cols{display:flex;gap:34px;align-items:stretch;margin-top:10px}
.nxc .col{flex:1;border-radius:22px;padding:30px 34px;background:rgba(255,255,255,.12);border:2px solid rgba(255,255,255,.28)}
.nxc .col.bad{background:rgba(120,20,20,.35);border-color:#ff9b8a}
.nxc .col.good{background:rgba(10,90,50,.35);border-color:#8fe3b0}
.nxc .col h3{font-size:30px;margin:0 0 16px}
.nxc .col p{font-size:28px;line-height:1.35;margin:0 0 12px}
.nxc .chain{display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-top:14px}
.nxc .node{background:rgba(255,255,255,.16);border:2px solid rgba(255,255,255,.4);border-radius:18px;padding:20px 28px;font-size:32px;font-weight:700;text-align:center}
.nxc .node small{display:block;font-size:22px;font-weight:400;opacity:.85;margin-top:6px}
.nxc .node.hot{background:#ffc46b;color:#3a2200;border-color:#ffc46b}
.nxc .node.bad{background:#ff8f7d;color:#3a0b00;border-color:#ff8f7d}
.nxc .ar{font-size:46px;opacity:.9}
.nxc .quote{font-size:74px;line-height:1.18;font-weight:800}
.nxc .note{margin-top:30px;font-size:26px;opacity:.85}
</style>`
const att = (at) => (at === undefined ? '' : ` data-at="${at}"`)
const wrap = (cls, body) => `${CSS}<div class="nxc ${cls || ''}"><div class="in">${body}</div></div>`
const kick = k => (k ? `<div class="k">${k}</div>` : '')

module.exports = {
  hero: (k, title, sub, cls = '') => wrap(cls, `${kick(k)}<h1 class="big">${title}</h1><div class="sub">${sub || ''}</div>`),
  list: (k, title, items, cls = '') => wrap(cls, `${kick(k)}<h1>${title}</h1><ul>${items.map(([t, at]) => `<li class="i"${att(at)}>${t}</li>`).join('')}</ul>`),
  cols: (k, title, cols, cls = '') => wrap(cls, `${kick(k)}<h1>${title}</h1><div class="cols">${cols.map(c => `<div class="col ${c.tone || ''} i"${att(c.at)}><h3>${c.head}</h3>${(c.items || []).map(([t, at]) => `<p class="i"${att(at)}>${t}</p>`).join('')}</div>`).join('')}</div>`),
  chain: (k, title, nodes, cls = '', note = '') => wrap(cls, `${kick(k)}<h1>${title}</h1><div class="chain">${nodes.map(([t, at, tone, sub], n) => `${n ? `<span class="ar i"${att(at)}>➜</span>` : ''}<div class="node ${tone || ''} i"${att(at)}>${t}${sub ? `<small>${sub}</small>` : ''}</div>`).join('')}</div>${note ? `<div class="note i" data-at="0.5">${note}</div>` : ''}`),
  quote: (k, text, sub, cls = 'dark') => wrap(cls, `${kick(k)}<div class="quote">${text}</div><div class="sub i" data-at="2" style="margin-top:30px">${sub || ''}</div>`),
  // Bowling mit Banden: Bahn mit Banden, Kugel läuft sanft zur Mitte
  lane: (k, title) => wrap('', `${kick(k)}<h1>${title}</h1>
   <svg viewBox="0 0 1100 260" width="1100" height="260" style="display:block;margin-top:10px">
    <rect x="20" y="30" width="1060" height="200" rx="18" fill="#f3d9a4" stroke="#fff" stroke-width="3"/>
    <rect x="20" y="30" width="1060" height="28" fill="#1d4666"/><rect x="20" y="202" width="1060" height="28" fill="#1d4666"/>
    <text x="40" y="52" fill="#fff" font-size="20" font-family="system-ui">Rinne – Fehler (doppelter Antrag)</text>
    <text x="40" y="224" fill="#fff" font-size="20" font-family="system-ui">Rinne – Fehler (falscher Bucket)</text>
    <path id="p" d="M70 150 C 300 70, 420 190, 600 120 S 900 135, 1040 130" fill="none" stroke="#c9692a" stroke-width="6" stroke-dasharray="14 10" class="i on"/>
    <circle cx="1040" cy="130" r="22" fill="#c9692a"/><text x="70" y="128" fill="#3a2200" font-size="24" font-family="system-ui" font-weight="700">Anna wirft frei</text>
    <text x="760" y="108" fill="#3a2200" font-size="24" font-family="system-ui" font-weight="700">Nexus lenkt sanft zurück</text>
   </svg><div class="sub i" data-at="3" style="margin-top:16px">Volle Autonomie – mit hochgeklappten Banden.</div>`),
}
