"""
Sincroniza um áudio limpo (regravado) com o áudio original de um vídeo, por
correlação cruzada das envelopes de energia. Retorna o DELAY (segundos) a
aplicar no áudio limpo para que a FALA case com a boca do vídeo.

Uso:  python3 xcorr_sync.py <video> <audio_limpo>   ->  imprime delay em segundos

delay > 0: atrasar o áudio limpo (a fala limpa está adiantada vs o vídeo).
Este é o método CONFIÁVEL — não depende de silencedetect/limiar. Funciona mesmo
quando a tomada limpa tem ritmo diferente, estalos ou pausas distintas, porque
casa o PADRÃO de energia da fala inteira, não um único ponto.

Requer: numpy  (pip install --user --break-system-packages numpy)
"""
import sys, subprocess
import numpy as np

def load_env(path, sr_env=100):
    """Áudio -> envelope de energia a sr_env Hz (100 = 10ms/amostra)."""
    raw = subprocess.run(
        ["ffmpeg","-v","quiet","-i",path,"-ac","1","-ar","16000","-f","s16le","-"],
        capture_output=True).stdout
    x = np.frombuffer(raw, dtype=np.int16).astype(np.float32)
    if len(x) == 0:
        return np.zeros(1)
    x = np.abs(x)
    win = 16000 // sr_env
    n = (len(x) // win) * win
    env = x[:n].reshape(-1, win).mean(axis=1)
    return env - env.mean()

def main():
    orig  = load_env(sys.argv[1])  # áudio do vídeo
    limpo = load_env(sys.argv[2])  # áudio limpo regravado
    SR = 100
    corr = np.correlate(orig, limpo, mode="full")
    lag = np.argmax(corr) - (len(limpo) - 1)
    print(f"{lag / SR:.3f}")

if __name__ == "__main__":
    main()
