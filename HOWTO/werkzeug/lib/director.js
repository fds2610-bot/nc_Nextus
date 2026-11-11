// Regie: Zeilen sprechen, Aktionen parallel ausführen, Skript (Markdown) und Mix erzeugen.
const fs = require('fs')
const path = require('path')
const crypto = require('crypto')
const { execFileSync } = require('child_process')
const { sleep } = require('./rec')

const CAST = {
  M: { name: 'Marlene', role: 'Senior-PMO, Dozentin', voice: 'serena', color: '#7fd1ff' },
  J: { name: 'Jonas', role: 'Junior-PMO', voice: 'ryan', color: '#ffc46b' },
}
const WORK = path.join(process.env.HOME, 'video-work')
const TTS_DIR = path.join(WORK, 'tts')

// Aussprachehilfen nur für die Sprachausgabe (Untertitel zeigen den Originaltext)
const PRON = [
  [/\bPMO\b/g, 'P M O'], [/\bG2\b/g, 'Gate zwei'], [/\bG3\b/g, 'Gate drei'], [/\bPT\b/g, 'Personentage'],
  [/\bFTE\b/g, 'Vollzeitkräfte'], [/\bBES-/g, 'Beschluss '], [/\bIT\b/g, 'I-T'], [/\bNIS-2\b/g, 'Nis zwei'],
  [/\bDEM-\b/g, 'Antrag '],
]
const ttsText = t => PRON.reduce((s, [re, to]) => s.replace(re, to), t)
const keyOf = (who, text) => crypto.createHash('sha1').update(CAST[who].voice + '|' + ttsText(text)).digest('hex').slice(0, 16)
const wavOf = (who, text) => path.join(TTS_DIR, keyOf(who, text) + '.wav')

function wavDuration(file) {
  const out = execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', file]).toString()
  return parseFloat(out)
}
const estimate = text => Math.max(1.5, text.split(/\s+/).length * 0.36)

/** Zeilenobjekt: Sprecher, Text, Aktion (optional), Bildschirmnotiz für das Skript */
const L = (who, text, act, note) => ({ who, text, act, note })
const S = title => ({ section: title })

function flatLines(video) {
  return video.script(null, {}).filter(x => x.who)
}

function writeLinesJson(video) {
  fs.mkdirSync(TTS_DIR, { recursive: true })
  const lines = flatLines(video).map(l => ({ voice: CAST[l.who].voice, text: ttsText(l.text), file: keyOf(l.who, l.text) + '.wav' }))
  fs.writeFileSync(path.join(WORK, 'out', `${video.id}.lines.json`), JSON.stringify(lines, null, 1))
  return lines
}

/** Ablauf aufnehmen */
async function perform(rec, video, state, { withAudio }) {
  const items = video.script(rec, state)
  for (const it of items) {
    if (it.section) { rec.chapter && rec.chapter(it.section); rec.chapters = rec.chapters || []; rec.chapters.push({ t: rec.now(), title: it.section }); continue }
    const wav = wavOf(it.who, it.text)
    const have = fs.existsSync(wav)
    if (withAudio && !have) throw new Error('Audio fehlt: ' + it.text.slice(0, 50))
    const dur = have ? wavDuration(wav) : estimate(it.text)
    const start = await rec.say(CAST[it.who].name, it.text, have ? wav : null, dur, CAST[it.who].color)
    it.start = start
    const tasks = [sleep(dur * 1000 + 200)]
    if (it.act) tasks.push(it.act())
    await Promise.all(tasks)
    await sleep(250)
  }
  await rec.endCaption()
}

function srtTime(s) {
  const ms = Math.round(s * 1000); const p = (n, w = 2) => String(n).padStart(w, '0')
  return `${p(Math.floor(ms / 3600000))}:${p(Math.floor(ms / 60000) % 60)}:${p(Math.floor(ms / 1000) % 60)},${p(ms % 1000, 3)}`
}
const clock = s => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}`

/** Mix: Frames → h264, Sprachspuren → AAC, SRT daneben */
function build(video, outDir, destDir, baseName) {
  const tl = JSON.parse(fs.readFileSync(path.join(outDir, 'timeline.json')))
  const off = tl.offset
  const lines = tl.lines.filter(l => l.wav)
  const args = ['-y', '-f', 'concat', '-safe', '0', '-i', path.join(outDir, 'frames.txt')]
  lines.forEach(l => args.push('-i', l.wav))
  const fc = lines.map((l, i) => `[${i + 1}:a]adelay=${Math.round((l.start - off) * 1000)}|${Math.round((l.start - off) * 1000)},volume=1.0[a${i}]`)
  const mix = lines.map((_, i) => `[a${i}]`).join('') + `amix=inputs=${lines.length}:normalize=0,loudnorm=I=-16:TP=-1.5:LRA=11[aout]`
  args.push('-filter_complex', fc.join(';') + ';' + mix, '-map', '0:v', '-map', '[aout]',
    '-vf', 'fps=25,format=yuv420p', '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-c:a', 'aac', '-b:a', '160k', '-ar', '48000', '-movflags', '+faststart', '-shortest',
    path.join(destDir, baseName + '.mp4'))
  // -vf fehlt bei filter_complex-Mix für Video: separat über -filter:v
  execFileSync('ffmpeg', args, { stdio: 'inherit' })
  let srt = ''
  tl.lines.forEach((l, i) => { srt += `${i + 1}\n${srtTime(l.start - off)} --> ${srtTime(l.start - off + l.dur)}\n${l.speaker}: ${l.text}\n\n` })
  fs.writeFileSync(path.join(destDir, baseName + '.srt'), srt)
}

module.exports = { CAST, L, S, perform, build, writeLinesJson, wavOf, ttsText, flatLines, clock, WORK }

/** Markdown-Skript je Video, mit Zeitmarken aus der Aufnahme */
function writeScript(video, outDir, destDir, baseName, totalSec) {
  const tl = JSON.parse(fs.readFileSync(path.join(outDir, 'timeline.json')))
  const off = tl.offset
  const items = video.script(null, {})
  let i = 0
  let md = `# Teil ${video.number} von 6 – ${video.title}\n\n`
  md += `> Schulungsvideo „Vom Antrag zum Projekt“ · Skript zum Video \`${baseName}.mp4\`\n\n`
  md += `| | |\n|---|---|\n`
  md += `| **Dauer** | ${clock(totalSec)} min |\n`
  md += `| **Sprecher** | Marlene Keller (${CAST.M.role}), Jonas Weber (${CAST.J.role}) |\n`
  md += `| **Konten in der Demo** | ${video.konten} |\n`
  md += `| **Stimmen** | Qwen3-TTS (12Hz-0.6B-CustomVoice): Marlene = „${CAST.M.voice}“, Jonas = „${CAST.J.voice}“ |\n`
  md += `| **Untertitel** | \`${baseName}.srt\` |\n\n`
  md += `## Lernziele\n\n${video.lernziele.map(x => `- ${x}`).join('\n')}\n\n`
  md += `## Eingebaute Problemfälle (Zwischenfragen)\n\n${video.probleme.map(x => `- ${x}`).join('\n')}\n\n`
  md += `## Ablauf\n\n`
  let sectionOpen = false
  const sections = []
  for (const it of items) {
    if (it.section) { sections.push({ title: it.section, rows: [] }); continue }
    const t = tl.lines[i++]
    sections[sections.length - 1].rows.push({ t: t.start - off, who: CAST[it.who].name, text: it.text, note: it.note || '' })
  }
  sections.forEach((s, n) => {
    md += `### ${n + 1}. ${s.title} (ab ${clock(s.rows[0].t)})\n\n| Zeit | Sprecher | Sprechtext | Bildschirm |\n|---|---|---|---|\n`
    for (const r of s.rows) md += `| ${clock(r.t)} | ${r.who} | ${r.text.replace(/\|/g, '/')} | ${r.note.replace(/\|/g, '/')} |\n`
    md += '\n'
  })
  fs.writeFileSync(path.join(destDir, baseName + '.md'), md)
}
module.exports.writeScript = writeScript
