import sys, json, subprocess, numpy as np
def load(f):
    raw = subprocess.check_output(["ffmpeg","-v","error","-i",f,"-f","f32le","-ac","1","-ar","16000","-"])
    return np.frombuffer(raw, dtype=np.float32)
from faster_whisper import WhisperModel
m = WhisperModel("small", device="cpu", compute_type="int8", cpu_threads=2)
l = json.load(open(sys.argv[1]))
for i in map(int, sys.argv[2:]):
    segs, _ = m.transcribe(load("tts/" + l[i]["file"]), language="de")
    print(i, l[i]["voice"], "ORIG:", l[i]["text"][:110]); print("   ASR :", " ".join(s.text for s in segs)[:110], flush=True)
