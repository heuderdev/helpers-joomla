"""
Acha o início da FALA REAL num áudio (energia sustentada), ignorando estalos/pops
de boca (picos curtos isolados de teste de mic). Retorna o tempo em segundos.

Uso: python3 find_speech.py <audio> [noise_db]   ->  imprime tempo em segundos

Usado para achar onde cortar o começo barulhento do vídeo já sincronizado
(pops, "barulho de botão", assovios antes da fala). O 1o bloco de som com
duração >= MIN_SPEECH é a fala; picos < MIN_SPEECH são estalos e ficam de fora.
"""
import subprocess, re, sys

audio = sys.argv[1]
NOISE = sys.argv[2] if len(sys.argv) > 2 else "-30"
MIN_SPEECH = 0.40   # bloco de som >= 0.40s = fala (estalo é < 0.15s)

out = subprocess.run(
    ["ffmpeg","-i",audio,"-af",f"silencedetect=noise={NOISE}dB:d=0.08","-f","null","-"],
    capture_output=True, text=True).stderr

ends   = [float(m) for m in re.findall(r"silence_end:\s*([0-9.]+)", out)]
starts = [float(m) for m in re.findall(r"silence_start:\s*([0-9.]+)", out)]

blocks = []
for e in ends:
    nxt = next((s for s in starts if s > e + 0.01), None)
    blocks.append((e, nxt if nxt is not None else e + 10))

for a, b in blocks:
    if (b - a) >= MIN_SPEECH:
        print(f"{a:.3f}")
        sys.exit(0)

print(f"{blocks[0][0]:.3f}" if blocks else "0.000")
