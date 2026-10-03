#!/usr/bin/env python3
"""
Remove silêncios de um vídeo usando ffmpeg.

Reproduz o pipeline do tauri-admin (teste-pipeline.py): detecta silêncios com
`silencedetect`, calcula segmentos com áudio, corta em paralelo e concatena.
"""

import argparse
import os
import re
import shutil
import subprocess
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path


def log(msg):
    print(f"\033[36m[remove-silence]\033[0m {msg}", file=sys.stderr, flush=True)


def secs(s):
    if s < 60:
        return f"{s:.1f}s"
    m, s = divmod(s, 60)
    return f"{int(m)}m{int(s):02d}s"


def ffprobe_duracao(path):
    out = subprocess.check_output([
        "ffprobe", "-v", "error", "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1", str(path)
    ]).decode().strip()
    return float(out)


def detectar_silencios(arquivo, limite_db=-30.0, duracao_minima=0.6):
    log(f"detectando silêncios (noise={limite_db}dB, d={duracao_minima}s)...")
    proc = subprocess.Popen(
        [
            "ffmpeg", "-hide_banner", "-nostats",
            "-i", str(arquivo),
            "-vn", "-af", f"silencedetect=noise={limite_db}dB:d={duracao_minima}",
            "-f", "null", "-",
        ],
        stderr=subprocess.PIPE,
        stdout=subprocess.DEVNULL,
        text=True,
    )
    silencios = []
    inicio = None
    re_inicio = re.compile(r"silence_start:\s*(-?\d+\.?\d*)")
    re_fim = re.compile(r"silence_end:\s*(-?\d+\.?\d*)")
    for linha in proc.stderr:
        m = re_inicio.search(linha)
        if m:
            inicio = max(0.0, float(m.group(1)))
            continue
        m = re_fim.search(linha)
        if m and inicio is not None:
            fim = float(m.group(1))
            if fim > inicio:
                silencios.append((inicio, fim))
            inicio = None
    proc.wait()
    return silencios


def calcular_segmentos(silencios, duracao, padding=0.15):
    cursor = 0.0
    segs = []
    for ini, fim in silencios:
        ini_corte = max(cursor, ini + padding)
        fim_corte = min(duracao, fim - padding)
        if ini_corte > cursor + 0.05:
            segs.append((cursor, ini_corte))
        if fim_corte > ini_corte:
            cursor = fim_corte
        else:
            cursor = min(duracao, fim)
    if duracao - cursor > 0.05:
        segs.append((cursor, duracao))
    return segs


def cortar_silencios(arquivo, saida, segs):
    log(f"cortando {len(segs)} segmentos com áudio (split + concat)...")
    tmpdir = saida.parent / f".segmentos-{os.getpid()}"
    tmpdir.mkdir(parents=True, exist_ok=True)

    partes = []
    workers = max(2, min(8, os.cpu_count() or 4))

    def cortar_um(idx_seg):
        idx, (a, b) = idx_seg
        dur = b - a
        if dur <= 0.05:
            return None
        parte = tmpdir / f"p_{idx:05d}.mp4"
        cmd = [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-ss", f"{a:.3f}",
            "-i", str(arquivo),
            "-t", f"{dur:.3f}",
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "20",
            "-pix_fmt", "yuv420p",
            "-c:a", "aac", "-b:a", "192k",
            "-avoid_negative_ts", "make_zero",
            "-movflags", "+faststart",
            str(parte),
        ]
        res = subprocess.run(cmd, capture_output=True)
        if res.returncode != 0:
            raise RuntimeError(
                f"corte segmento {idx} falhou: {res.stderr.decode()[-800:]}"
            )
        return parte

    enumerados = list(enumerate(segs))
    feitos = 0
    with ThreadPoolExecutor(max_workers=workers) as ex:
        futuros = {ex.submit(cortar_um, e): e[0] for e in enumerados}
        for fut in as_completed(futuros):
            r = fut.result()
            if r:
                partes.append((futuros[fut], r))
            feitos += 1
            if feitos % 20 == 0 or feitos == len(enumerados):
                log(f"  {feitos}/{len(enumerados)} segmentos cortados")

    partes.sort(key=lambda t: t[0])
    lista_txt = tmpdir / "lista.txt"
    lista_txt.write_text("\n".join(f"file '{p[1]}'" for p in partes))

    cmd = [
        "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
        "-f", "concat", "-safe", "0",
        "-i", str(lista_txt),
        "-c", "copy",
        "-movflags", "+faststart",
        str(saida),
    ]
    res = subprocess.run(cmd, capture_output=True)
    if res.returncode != 0:
        log("  concat -c copy falhou, tentando com re-encode...")
        cmd = [
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-y",
            "-f", "concat", "-safe", "0",
            "-i", str(lista_txt),
            "-c:v", "libx264", "-preset", "veryfast", "-crf", "20",
            "-c:a", "aac", "-b:a", "192k",
            "-movflags", "+faststart",
            str(saida),
        ]
        res = subprocess.run(cmd, capture_output=True)
        if res.returncode != 0:
            shutil.rmtree(tmpdir, ignore_errors=True)
            raise RuntimeError(f"concat falhou: {res.stderr.decode()[-2000:]}")

    shutil.rmtree(tmpdir, ignore_errors=True)


def main():
    p = argparse.ArgumentParser(description="Remove silêncios de um vídeo")
    p.add_argument("video", help="caminho do vídeo de entrada")
    p.add_argument("-o", "--output", help="caminho de saída (default: <nome>-sem-silencio.mp4 ao lado)")
    p.add_argument("--noise", type=float, default=-30.0, help="limite de ruído em dB (default: -30)")
    p.add_argument("--min", type=float, default=0.6, dest="duracao_minima", help="duração mínima do silêncio em segundos (default: 0.6)")
    p.add_argument("--padding", type=float, default=0.15, help="folga em segundos preservada em cada lado do silêncio (default: 0.15)")
    args = p.parse_args()

    video = Path(args.video).expanduser().resolve()
    if not video.exists():
        print(f"vídeo não encontrado: {video}", file=sys.stderr)
        sys.exit(1)

    if args.output:
        saida = Path(args.output).expanduser().resolve()
    else:
        saida = video.with_name(f"{video.stem}-sem-silencio{video.suffix}")
    saida.parent.mkdir(parents=True, exist_ok=True)

    t_total = time.time()
    duracao_orig = ffprobe_duracao(video)
    log(f"vídeo: {video.name} | duração original: {secs(duracao_orig)}")

    silencios = detectar_silencios(video, args.noise, args.duracao_minima)
    log(f"  {len(silencios)} silêncios detectados")

    if not silencios:
        log("nenhum silêncio para remover — copiando arquivo original")
        shutil.copy2(video, saida)
        duracao_corte = duracao_orig
    else:
        segs = calcular_segmentos(silencios, duracao_orig, args.padding)
        cortar_silencios(video, saida, segs)
        duracao_corte = ffprobe_duracao(saida)

    tempo = time.time() - t_total
    economia = duracao_orig - duracao_corte
    log(f"pronto em {secs(tempo)} | {secs(duracao_orig)} -> {secs(duracao_corte)} (-{secs(economia)})")
    print(str(saida))


if __name__ == "__main__":
    main()
