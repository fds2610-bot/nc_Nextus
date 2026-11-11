# HOWTO – Schulungsvideos „Vom Antrag zum Projekt“

Sechs Videos, in denen die Senior-Dozentin Marlene dem Junior-PMO Jonas den Ablauf
**Antrag → Bewertung → Entscheidung → Portfolio → Freigabe → Projekt im Planungstool** in Nexus zeigt.
Jonas stellt Zwischenfragen, wenn die Umgebung nicht wie im Idealfall reagiert (jeweils unterschiedliche Probleme).
Zu jedem Video gehören ein Skript (`.md`) und Untertitel (`.srt`).

| Teil | Video | Skript | Problemfälle |
|---|---|---|---|
| 1 | [01-Antrag-erfassen.mp4](01-Antrag-erfassen.mp4) | [Skript](01-Antrag-erfassen.md) | Dublettenhinweis · Rückfrage des PMO |
| 2 | [02-Bewertung.mp4](02-Bewertung.mp4) | [Skript](02-Bewertung.md) | Speichern gesperrt (Kriterium fehlt) · Business Case ohne Ausgangswert |
| 3 | [03-Entscheidung.mp4](03-Entscheidung.mp4) | [Skript](03-Entscheidung.md) | Prüfliste unvollständig · Entscheidung früher als geplant |
| 4 | [04-Portfolio.mp4](04-Portfolio.mp4) | [Skript](04-Portfolio.md) | Reihenfolge korrigieren · überbuchter Pool |
| 5 | [05-Freigabe.mp4](05-Freigabe.mp4) | [Skript](05-Freigabe.md) | Freigabe wegen Kapazität gesperrt · Startplan |
| 6 | [06-Projekt-im-Planungstool.mp4](06-Projekt-im-Planungstool.mp4) | [Skript](06-Projekt-im-Planungstool.md) | Gantt-App nicht aktiv · 0 % Fortschritt |

## Herstellung

- **Bild:** echte Bildschirmaufnahme der Nexus-Oberfläche (Playwright/Chromium, 1600×900) auf einer eigenen
  DEV-Instanz mit den Demo-Daten aus `scripts/seed-demo.sh`; der Ablauf ist skriptgesteuert und wiederholbar.
- **Stimmen:** Qwen3-TTS (`Qwen/Qwen3-TTS-12Hz-0.6B-CustomVoice`), lokal auf der CPU; Marlene = „serena“, Jonas = „ryan“.
- **Werkzeuge:** [`werkzeug/`](werkzeug/) (Drehbücher je Video in `werkzeug/videos/`, Aufnahme-Bibliothek in `werkzeug/lib/`).
  Die Skripte erwarten ein Arbeitsverzeichnis `~/video-work` (Python-venv mit `qwen-tts`, `soundfile`) und
  eine laufende Instanz auf Port 8097; Details in den Kommentaren von `record_all.sh` und `rec/fresh.sh`.
- Neu erzeugen: Sprache mit `tts_run.py` rendern, dann `record_all.sh`. Ändert sich die Oberfläche, genügt es,
  die betroffenen Abläufe in `werkzeug/videos/*.js` anzupassen.
