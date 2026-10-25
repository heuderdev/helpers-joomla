"""
Gera legenda .ass estilo "tempo real" (Reels) a partir do JSON word-level do
whisper.cpp (rodado com -ml 1 -sow --output-json).

Uso: python3 make_ass.py <words.json> <saida.ass> [fontsize] [maxwords]

Legenda: blocos de N palavras, Montserrat branca, contorno preto sutil, embaixo.

IMPORTANTE (bug histórico): o Format da seção [Events] DEVE ter o campo `Name`
entre `Style` e `MarginL`. Sem ele, o libass lê os campos desalinhados e vaza um
','/'0' pro início do texto renderizado (aparecia ",Muito bem" na tela).
"""
import json, sys, re

src = sys.argv[1]
out = sys.argv[2]
FONTSIZE = int(sys.argv[3]) if len(sys.argv) > 3 else 58
MAXW     = int(sys.argv[4]) if len(sys.argv) > 4 else 3

data = json.load(open(src))

words = []
for seg in data.get("transcription", []):
    txt = seg.get("text", "").strip()
    # ignora tokens vazios ou só-pontuação (whisper cospe ',' '.' isolados)
    if not txt or not any(c.isalnum() for c in txt):
        continue
    off = seg.get("offsets", {})
    t0 = off.get("from", 0) / 1000.0
    t1 = off.get("to", 0) / 1000.0
    words.append([t0, t1, txt])

groups = []
i = 0
while i < len(words):
    chunk = words[i:i+MAXW]
    start = chunk[0][0]
    end = chunk[-1][1]
    text = " ".join(w[2].strip() for w in chunk)
    text = re.sub(r"\s+([,.;:!?])", r"\1", text)   # cola pontuação
    text = re.sub(r"\s{2,}", " ", text).strip()
    if text:
        groups.append((start, end, text))
    i += MAXW

def ts(s):
    h = int(s//3600); s -= h*3600
    m = int(s//60); s -= m*60
    cs = int(round((s-int(s))*100))
    return f"{h}:{m:02d}:{int(s):02d}.{cs:02d}"

header = f"""[Script Info]
ScriptType: v4.00+
PlayResX: 1080
PlayResY: 1920
ScaledBorderAndShadow: yes

[V4+ Styles]
Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding
Style: Sub,Montserrat,{FONTSIZE},&H00FFFFFF,&H000000FF,&HDC000000,&H80000000,-1,0,0,0,100,100,0,0,1,3,1,2,80,80,240,1

[Events]
Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text
"""

lines = [header]
for start, end, text in groups:
    text = text.replace("{", "(").replace("}", ")").replace("\\", "")
    lines.append(f"Dialogue: 0,{ts(start)},{ts(end)},Sub,,0,0,0,,{text}")

open(out, "w").write("\n".join(lines))
print(f"{len(groups)} blocos de legenda gerados")
