// node run_podcast.js podcast01 [--fast] [--dest DIR]
const fs = require('fs'); const path = require('path'); const { execFileSync } = require('child_process')
const { Recorder, sleep } = require('./lib/rec'); const D = require('./lib/director')
const argv = process.argv
const id = argv[2]; const fast = argv.includes('--fast')
const dest = argv.includes('--dest') ? argv[argv.indexOf('--dest') + 1] : path.join(D.WORK, 'final')
const V = require(`./videos/${id}.js`)

;(async () => {
  const st = await V.prepare()
  const outDir = path.join(D.WORK, 'out', id)
  const rec = new Recorder(outDir); await rec.start()
  await rec.prepareUser('anna'); await rec.prepareUser('paula')
  await rec.switchUser('paula')
  await rec.begin()
  const cues = V.cues(st)
  const lateLog = []
  for (const c of cues) {
    if (!fast) { const w = c.t - rec.now(); if (w > 0) await sleep(w * 1000) }
    const late = rec.now() - c.t
    if (!fast && late > 0.6) lateLog.push(`${c.t.toFixed(0)}s ${c.name}: ${late.toFixed(1)}s zu spät`)
    try {
      if (c.card) await rec.showCard(c.card.html(c.t), fast ? 900 : (c.card.end ? (c.card.end - c.t) * 1000 : 0))
      if (c.act) await c.act(rec, st)
    } catch (e) {
      console.error(`FEHLER bei ${c.t.toFixed(0)}s „${c.name}“: ${e.message.split('\n')[0]}`)
      await rec.page.screenshot({ path: path.join(D.WORK, 'shots', `pod-fehler-${Math.round(c.t)}.png`) }).catch(() => {})
    }
    if (fast) await sleep(900)
  }
  if (!fast) { const w = V.duration - rec.now(); if (w > 0) await sleep(w * 1000) }
  await rec.end(id)
  console.log('Aufnahme fertig:', rec.frames.length, 'Frames,', rec.now().toFixed(1), 's; verspätete Aktionen:', lateLog.length)
  lateLog.forEach(l => console.log('  ', l))
  if (fast) return
  // Mix: Bild aus Frames, Ton = Podcast unverändert
  fs.mkdirSync(dest, { recursive: true })
  const mp4 = path.join(dest, V.file + '.mp4')
  execFileSync('ffmpeg', ['-y', '-f', 'concat', '-safe', '0', '-i', path.join(outDir, 'frames.txt'), '-i', path.join(D.WORK, V.audio),
    '-map', '0:v', '-map', '1:a', '-vf', 'fps=25,format=yuv420p', '-c:v', 'libx264', '-preset', 'medium', '-crf', '24', '-c:a', 'copy', '-movflags', '+faststart', '-shortest', mp4], { stdio: 'inherit' })
  // Bildplan als Markdown
  const clk = s => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`
  let md = `# ${V.title}\n\n> Bildspur zum Podcast „Wie smarte Projektanträge Schattenprojekte verhindern“ (Audio: \`${V.audio}\`, ${clk(V.duration)} min). Datei: \`${V.file}.mp4\`\n\n`
  md += 'Die Bilder sind auf die Zeitmarken des Audios abgestimmt: **Karte** = eingeblendete Grafik, **Live** = Aufnahme aus Nexus, **Hinweis** = Einblendung über der Oberfläche.\n\n| Zeit | Art | Bild |\n|---|---|---|\n'
  for (const c of cues) { const art = c.card ? 'Karte' : (c.name.startsWith('Hinweis') ? 'Hinweis' : 'Live'); md += `| ${clk(c.t)} | ${art} | ${c.name.replace(/^(Karte|Live|Hinweis): /, '')} |\n` }
  fs.writeFileSync(path.join(dest, V.file + '.md'), md)
})().catch(e => { console.error(e); process.exit(1) })
