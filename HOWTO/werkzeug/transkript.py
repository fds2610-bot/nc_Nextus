import sys, json, subprocess, numpy as np
from faster_whisper import WhisperModel
src, out, model = sys.argv[1], sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else "small"
raw = subprocess.check_output(["ffmpeg","-v","error","-i",src,"-f","f32le","-ac","1","-ar","16000","-"])
a = np.frombuffer(raw, dtype=np.float32)
m = WhisperModel(model, device="cpu", compute_type="int8", cpu_threads=8)
segs, info = m.transcribe(a, language="de", vad_filter=True, beam_size=3, condition_on_previous_text=True)
res = []
for s in segs:
    res.append({"start": round(s.start, 2), "end": round(s.end, 2), "text": s.text.strip()})
    print(f"{s.start:7.1f} {s.text.strip()[:90]}", flush=True)
json.dump(res, open(out, "w"), ensure_ascii=False, indent=1)
print("FERTIG", flush=True)
