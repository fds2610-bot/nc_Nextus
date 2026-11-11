// node run.js <video-id> [--audio] [--dest DIR]
const fs = require('fs'); const path = require('path')
const { Recorder } = require('./lib/rec'); const D = require('./lib/director')
;(async () => {
  const id = process.argv[2]; const withAudio = process.argv.includes('--audio')
  const video = require(`./videos/${id}.js`)
  if (process.argv.includes('--lines')) { console.log(D.writeLinesJson(video).length, 'Zeilen'); return }
  const outDir = path.join(D.WORK, 'out', id)
  const state = video.prepare ? await video.prepare() : {}
  const rec = new Recorder(outDir); await rec.start()
  await rec.prepareUser('anna'); await rec.prepareUser('paula'); await rec.prepareUser('boris'); await rec.prepareUser('sven')
  if (video.intro) await video.intro(rec)
  else await rec.page.goto('about:blank')
  await rec.begin()
  try { await D.perform(rec, video, state, { withAudio }) } catch (e) { await rec.page.screenshot({ path: path.join(D.WORK, 'shots', 'fail-' + id + '.png') }).catch(() => {}); await rec.browser.close(); throw e }
  await rec.end(id)
  console.log('Aufnahme fertig:', rec.frames.length, 'Frames,', rec.now().toFixed(1), 's')
  if (withAudio) {
    const dest = process.argv[process.argv.indexOf('--dest') + 1] || outDir
    fs.mkdirSync(dest, { recursive: true })
    D.build(video, outDir, dest, video.file)
    D.writeScript(video, outDir, dest, video.file, rec.now())
  }
})().catch(e => { console.error(e); process.exit(1) })
