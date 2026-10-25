#!/usr/bin/env python3
"""
Gera video a partir de uma imagem (image-to-video) pela API da Magnific.

  python3 generate-video.py --model kling-v2-5-pro \
      --image "https://cdn.exemplo.com/simbolo.png" \
      --prompt "slow majestic rotation, glowing" \
      --duration 5 --out saida.mp4

A API so aceita URL publica na imagem (use a skill api-upload-files para subir).
"""
import argparse, json, os, sys, time, urllib.request, urllib.error

BASE = "https://api.magnific.com"

# duration: alguns modelos usam int, outros string
MODELOS = {
    "kling-v2-5-pro":            {"path": "/v1/ai/image-to-video/kling-v2-5-pro",            "img": "image",     "dur": "str"},
    "kling-v2":                  {"path": "/v1/ai/image-to-video/kling-v2",                  "img": "image",     "dur": "str"},
    "minimax-hailuo-02-1080p":   {"path": "/v1/ai/image-to-video/minimax-hailuo-02-1080p",   "img": "image",     "dur": "int"},
    "wan-v2-2-720p":             {"path": "/v1/ai/image-to-video/wan-v2-2-720p",             "img": "image",     "dur": "int"},
    "pixverse-v5":               {"path": "/v1/ai/image-to-video/pixverse-v5",               "img": "image_url", "dur": "int"},
}


def env_key():
    k = os.environ.get("MAGNIFIC_API_KEY")
    if k:
        return k
    envp = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env")
    if os.path.exists(envp):
        for line in open(envp):
            line = line.strip()
            if line.startswith("MAGNIFIC_API_KEY="):
                return line.split("=", 1)[1].strip().strip('"').strip("'")
    sys.exit("MAGNIFIC_API_KEY nao encontrada")


def req(method, url, key, payload=None):
    data = json.dumps(payload).encode() if payload is not None else None
    r = urllib.request.Request(url, data=data, method=method)
    r.add_header("x-magnific-api-key", key)
    r.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(r, timeout=120) as resp:
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        sys.exit(f"HTTP {e.code}: {e.read().decode()[:500]}")


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--model", required=True, choices=sorted(MODELOS))
    p.add_argument("--image", required=True, help="URL publica da imagem inicial")
    p.add_argument("--prompt", required=True)
    p.add_argument("--negative-prompt")
    p.add_argument("--duration", default="5")
    p.add_argument("--resolution", help="ex: 1080p (pixverse)")
    p.add_argument("--out", default="magnific-video.mp4")
    a = p.parse_args()

    key = env_key()
    m = MODELOS[a.model]

    payload = {m["img"]: a.image, "prompt": a.prompt}
    payload["duration"] = int(a.duration) if m["dur"] == "int" else str(a.duration)
    if a.negative_prompt:
        payload["negative_prompt"] = a.negative_prompt
    if a.resolution:
        payload["resolution"] = a.resolution

    print(f"-> criando task em {m['path']} ...", file=sys.stderr)
    r = req("POST", BASE + m["path"], key, payload)
    task = r["data"]["task_id"]

    inicio = time.time()
    while True:
        time.sleep(6)
        s = req("GET", f"{BASE}{m['path']}/{task}", key)
        d = s["data"]
        st = d.get("status")
        el = int(time.time() - inicio)
        print(f"   status: {st} ({el}s)", end="\r", file=sys.stderr)
        if st == "COMPLETED":
            break
        if st in ("FAILED", "ERROR"):
            sys.exit(f"\nfalhou: {json.dumps(d)[:500]}")
        if el > 900:
            sys.exit("\ntimeout (15min)")

    urls = d.get("generated") or []
    if not urls:
        sys.exit(f"\nsem saida: {json.dumps(d)[:500]}")

    saidas = []
    for i, u in enumerate(urls):
        dest = a.out if len(urls) == 1 else f"{os.path.splitext(a.out)[0]}_{i}{os.path.splitext(a.out)[1]}"
        urllib.request.urlretrieve(u, dest)
        saidas.append(dest)

    print(f"\n{json.dumps({'ok': True, 'model': a.model, 'task_id': task, 'saidas': saidas})}")


if __name__ == "__main__":
    main()
