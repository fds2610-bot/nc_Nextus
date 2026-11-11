# python tts_run.py out/<id>.lines.json ...  – erzeugt fehlende WAVs in tts/
import sys, os, json, time, torch, soundfile as sf
from qwen_tts import Qwen3TTSModel
torch.set_num_threads(8)
m = Qwen3TTSModel.from_pretrained("Qwen/Qwen3-TTS-12Hz-0.6B-CustomVoice", device_map="cpu", dtype=torch.float32)
os.makedirs("tts", exist_ok=True)
for f in sys.argv[1:]:
    for i, l in enumerate(json.load(open(f))):
        out = os.path.join("tts", l["file"])
        if os.path.exists(out): continue
        t = time.time()
        w, sr = m.generate_custom_voice(text=l["text"], language="German", speaker=l["voice"])
        sf.write(out + ".tmp.wav", w[0], sr); os.rename(out + ".tmp.wav", out)
        print(f, i, round(len(w[0])/sr,1), "s Audio in", round(time.time()-t), "s", flush=True)
print("FERTIG", flush=True)
