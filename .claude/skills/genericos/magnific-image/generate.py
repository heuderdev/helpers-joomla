#!/usr/bin/env python3
"""
Gera imagens via Magnific (Freepik) API — múltiplos modelos text-to-image e edição.

Fluxo da API (assíncrono): POST cria a task -> recebe task_id -> GET faz polling
até status COMPLETED -> baixa a(s) imagem(ns) da lista `generated`.

A chave é lida de:
  1. variável de ambiente MAGNIFIC_API_KEY
  2. arquivo .env ao lado deste script (MAGNIFIC_API_KEY=...)

Base URL: https://api.magnific.com   ·   header de auth: x-magnific-api-key

Uso:
  python3 generate.py --model nano-banana-pro --prompt "..." --aspect 9:16 --out capa.png
  python3 generate.py --model seedream-v4-5-edit --prompt "..." --ref foto.png --ref logo.png --out r.png
  python3 generate.py --model mystic --prompt "..." --aspect widescreen_16_9 --out img.png
  python3 generate.py --balance        # mostra uso de créditos (se o plano permitir)

Sem dependências além da stdlib (urllib).
"""

import argparse
import base64
import json
import mimetypes
import os
import sys
import time
import urllib.request
import urllib.error

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_URL = "https://api.magnific.com"

# ---- catálogo de modelos: path do endpoint + se aceita refs e em que formato ----
# refs: None (não aceita) | "url" (só URL pública) | "b64_or_url" (base64 ou URL)
# aspect_style: "ratio" (ex 9:16) | "named" (ex widescreen_16_9)
MODELOS = {
    "nano-banana-pro":    {"path": "/v1/ai/text-to-image/nano-banana-pro",    "refs": "url",       "aspect_style": "ratio", "ref_field": "reference_images_obj"},
    "seedream-v4-5":      {"path": "/v1/ai/text-to-image/seedream-v4-5",      "refs": None,        "aspect_style": "named"},
    "seedream-v4-5-edit": {"path": "/v1/ai/text-to-image/seedream-v4-5-edit", "refs": "b64_or_url","aspect_style": "named", "ref_field": "reference_images"},
    "seedream-v5-lite":   {"path": "/v1/ai/text-to-image/seedream-v5-lite",   "refs": None,        "aspect_style": "named"},
    "mystic":             {"path": "/v1/ai/mystic",                          "refs": None,        "aspect_style": "named"},
    "flux-2-pro":         {"path": "/v1/ai/text-to-image/flux-2-pro",        "refs": None,        "aspect_style": "ratio"},
    "flux-pro-v1-1":      {"path": "/v1/ai/text-to-image/flux-pro-v1-1",     "refs": None,        "aspect_style": "ratio"},
    "imagen4-ultra":      {"path": "/v1/ai/text-to-image/imagen4-ultra",     "refs": None,        "aspect_style": "ratio"},
    "z-image":            {"path": "/v1/ai/text-to-image/z-image",           "refs": None,        "aspect_style": "ratio"},
}

# aspect ratios "named" (Seedream/Mystic) — conversão de ratio simples -> nome
NAMED_ASPECT = {
    "1:1": "square_1_1", "16:9": "widescreen_16_9", "9:16": "social_story_9_16",
    "2:3": "portrait_2_3", "3:4": "traditional_3_4", "4:3": "classic_4_3",
}


def load_api_key() -> str:
    key = os.environ.get("MAGNIFIC_API_KEY", "").strip()
    if key:
        return key
    env_path = os.path.join(SCRIPT_DIR, ".env")
    if os.path.isfile(env_path):
        with open(env_path) as f:
            for line in f:
                line = line.strip()
                if line.startswith("MAGNIFIC_API_KEY="):
                    return line.split("=", 1)[1].strip()
    sys.exit(
        "ERRO: MAGNIFIC_API_KEY não encontrada.\n"
        f"Defina a env var MAGNIFIC_API_KEY ou crie {env_path} com:\n"
        "  MAGNIFIC_API_KEY=MS..."
    )


def _req(method: str, url: str, key: str, payload=None) -> dict:
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("x-magnific-api-key", key)
    if data is not None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        body = e.read().decode(errors="replace")
        sys.exit(f"ERRO {e.code} em {method} {url}:\n{body}")


def file_to_b64_datauri(path: str) -> str:
    mime = mimetypes.guess_type(path)[0] or "image/png"
    with open(path, "rb") as f:
        b = base64.b64encode(f.read()).decode()
    return f"data:{mime};base64,{b}"


def build_refs(modelo: dict, refs: list) -> dict:
    """Monta o campo de referências conforme o formato que o modelo aceita."""
    if not refs:
        return {}
    if modelo["refs"] is None:
        sys.exit(f"ERRO: o modelo escolhido não aceita imagens de referência. "
                 f"Use seedream-v4-5-edit ou nano-banana-pro.")
    field = modelo.get("ref_field")
    if field == "reference_images_obj":
        # nano-banana-pro: objetos {image, mime_type}. Exige URL pública (não base64).
        items = []
        for r in refs:
            if r.startswith("http"):
                mime = mimetypes.guess_type(r)[0] or "image/png"
                items.append({"image": r, "mime_type": mime})
            else:
                sys.exit("ERRO: nano-banana-pro exige URLs públicas nas referências "
                         "(não aceita arquivo local/base64). Suba a imagem antes ou "
                         "use seedream-v4-5-edit, que aceita arquivo local.")
        return {"reference_images": items[:14]}
    else:
        # seedream-v4-5-edit: array de strings (base64 data-uri OU URL), até 5
        items = []
        for r in refs:
            items.append(r if r.startswith("http") else file_to_b64_datauri(r))
        return {"reference_images": items[:5]}


def resolve_aspect(modelo: dict, aspect: str) -> str:
    if modelo["aspect_style"] == "named":
        return NAMED_ASPECT.get(aspect, aspect)  # aceita já-nomeado também
    return aspect  # ratio direto (9:16)


def gerar(args):
    key = load_api_key()
    if args.model not in MODELOS:
        sys.exit(f"ERRO: modelo '{args.model}' desconhecido. Opções: {', '.join(MODELOS)}")
    modelo = MODELOS[args.model]

    payload = {"prompt": args.prompt}
    if args.aspect:
        payload["aspect_ratio"] = resolve_aspect(modelo, args.aspect)
    if args.resolution:
        payload["resolution"] = args.resolution
    if args.model == "mystic" and args.engine:
        payload["engine"] = args.engine
    payload.update(build_refs(modelo, args.ref or []))

    url = BASE_URL + modelo["path"]
    print(f"→ criando task em {modelo['path']} …", file=sys.stderr)
    created = _req("POST", url, key, payload)
    task_id = (created.get("data") or {}).get("task_id") or created.get("task_id")
    if not task_id:
        sys.exit(f"ERRO: API não retornou task_id: {json.dumps(created)[:400]}")

    # polling
    status_url = f"{url}/{task_id}"
    generated = []
    for i in range(80):
        time.sleep(3)
        st = _req("GET", status_url, key)
        d = st.get("data") or st
        status = d.get("status", "")
        sys.stderr.write(f"\r  status: {status} ({(i+1)*3}s)   ")
        sys.stderr.flush()
        if status == "COMPLETED":
            generated = d.get("generated") or []
            break
        if status == "FAILED":
            sys.exit(f"\nERRO: task FAILED: {json.dumps(d)[:400]}")
    sys.stderr.write("\n")
    if not generated:
        sys.exit("ERRO: a geração não concluiu no tempo esperado.")

    # baixa a(s) imagem(ns)
    saidas = []
    for idx, img_url in enumerate(generated):
        out = args.out if len(generated) == 1 else _suffix(args.out, idx)
        with urllib.request.urlopen(img_url, timeout=120) as r:
            with open(out, "wb") as f:
                f.write(r.read())
        saidas.append(out)
    print(json.dumps({"ok": True, "model": args.model, "task_id": task_id, "saidas": saidas}))


def _suffix(path: str, idx: int) -> str:
    raiz, ext = os.path.splitext(path)
    return f"{raiz}_{idx}{ext or '.png'}"


def saldo(args):
    """Consulta o uso de créditos via Analytics API (Business/Enterprise)."""
    key = load_api_key()
    url = BASE_URL + "/v1/analytics/team-credit-usage"
    try:
        resp = _req("POST", url, key, {"granularity": "month"})
        print(json.dumps(resp, indent=2, ensure_ascii=False))
    except SystemExit as e:
        print(str(e), file=sys.stderr)
        print("(saldo só via dashboard se o plano não tiver Analytics API)", file=sys.stderr)


def main():
    p = argparse.ArgumentParser(description="Gera imagens via Magnific (Freepik) API")
    p.add_argument("--model", help="modelo: " + ", ".join(MODELOS))
    p.add_argument("--prompt", help="descrição da imagem")
    p.add_argument("--aspect", help="aspect ratio (ex: 9:16, 16:9, 1:1) — convertido automaticamente p/ o modelo")
    p.add_argument("--resolution", help="1K | 2K | 4K (quando o modelo suporta)")
    p.add_argument("--engine", help="(mystic) realism|fluid|zen|flexible|super_real|editorial_portraits")
    p.add_argument("--ref", action="append", help="imagem de referência (arquivo local ou URL). Repetível.")
    p.add_argument("--out", default="magnific-out.png", help="arquivo de saída")
    p.add_argument("--balance", action="store_true", help="mostra uso de créditos e sai")
    args = p.parse_args()

    if args.balance:
        saldo(args)
        return
    if not args.model or not args.prompt:
        p.error("--model e --prompt são obrigatórios (ou use --balance)")
    gerar(args)


if __name__ == "__main__":
    main()
