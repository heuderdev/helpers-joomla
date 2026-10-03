#!/usr/bin/env python3
"""
edit-image.py — Edição de imagens via OpenAI gpt-image-1 (endpoint /v1/images/edits)
                e geração via /v1/images/generations.

Sem dependências externas obrigatórias (usa urllib da stdlib).
Pillow é OPCIONAL — só é necessário para o subcomando `mask` (gerar máscara retangular).

A API key é lida, nesta ordem:
  1. variável de ambiente OPENAI_API_KEY
  2. arquivo .env ao lado deste script (chave OPENAI_API_KEY=...)

Subcomandos
-----------
  edit    Edita uma imagem (com prompt; opcionalmente com máscara).
  rmbg    Remove o fundo -> PNG com transparência (background=transparent).
  gen     Gera uma imagem nova a partir de um prompt (sem imagem de entrada).
  mask    Gera uma máscara PNG (área retangular transparente = a ser editada). Requer Pillow.

Exemplos
--------
  # Apagar a placa "ROAS" de um carro usando uma máscara, preenchendo de forma realista
  python3 edit-image.py mask carro.png --rect 120,300,260,360 -o carro.mask.png
  python3 edit-image.py edit carro.png --mask carro.mask.png \
      -p "remove the license plate emblem/logo, fill with a clean blank dark license plate matching the car" \
      -o carro.novo.png --quality medium

  # Editar a imagem inteira (sem máscara) — usa input_fidelity=high p/ preservar detalhes
  python3 edit-image.py edit foto.png \
      -p "remove the golden ROAS TRANSFER logo, keep everything else identical" \
      -o foto.novo.png --quality high

  # Remover fundo (PNG transparente)
  python3 edit-image.py rmbg produto.jpg -o produto.png

  # Gerar imagem nova
  python3 edit-image.py gen -p "a sleek black executive van, studio lighting" -o van.png
"""
import argparse
import json
import mimetypes
import os
import sys
import urllib.request
import uuid

API_BASE = "https://api.openai.com/v1"
DEFAULT_MODEL = "gpt-image-2"  # gpt-image-2 faz inpainting de verdade (preserva pixels fora da máscara)
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

# gpt-image-2 NÃO suporta background=transparent; gpt-image-1 suporta.
MODELS_NO_TRANSPARENT = {"gpt-image-2"}
# gpt-image-2 NÃO aceita input_fidelity (erro 400). gpt-image-1 aceita, mas
# com máscara cai para dall-e-2 (bug). Logo: input_fidelity só é seguro no
# gpt-image-1 SEM máscara.
MODELS_NO_FIDELITY = {"gpt-image-2"}
MODELS_FIDELITY_BREAKS_WITH_MASK = {"gpt-image-1"}


# --------------------------------------------------------------------------- #
# API key                                                                     #
# --------------------------------------------------------------------------- #
def load_api_key() -> str:
    key = os.environ.get("OPENAI_API_KEY", "").strip()
    if key:
        return key
    env_path = os.path.join(SCRIPT_DIR, ".env")
    if os.path.exists(env_path):
        with open(env_path, "r", encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if line.startswith("OPENAI_API_KEY="):
                    return line.split("=", 1)[1].strip().strip('"').strip("'")
    sys.exit(
        "ERRO: OPENAI_API_KEY não encontrada.\n"
        f"Defina a env var OPENAI_API_KEY ou crie {env_path} com:\n"
        "  OPENAI_API_KEY=sk-..."
    )


# --------------------------------------------------------------------------- #
# multipart/form-data (sem dependências)                                       #
# --------------------------------------------------------------------------- #
def _encode_multipart(fields: dict, files: dict):
    """fields: {name: str}; files: {name: filepath}. Retorna (body_bytes, content_type)."""
    boundary = "----edit-image-" + uuid.uuid4().hex
    crlf = b"\r\n"
    body = bytearray()

    for name, value in fields.items():
        if value is None:
            continue
        body += b"--" + boundary.encode() + crlf
        body += f'Content-Disposition: form-data; name="{name}"'.encode() + crlf + crlf
        body += str(value).encode("utf-8") + crlf

    for name, filepath in files.items():
        if filepath is None:
            continue
        # Um campo pode ter vários arquivos (ex.: image[] com foto + logos de referência).
        paths = filepath if isinstance(filepath, (list, tuple)) else [filepath]
        for p in paths:
            if p is None:
                continue
            fname = os.path.basename(p)
            ctype = mimetypes.guess_type(fname)[0] or "application/octet-stream"
            with open(p, "rb") as fh:
                data = fh.read()
            body += b"--" + boundary.encode() + crlf
            body += (
                f'Content-Disposition: form-data; name="{name}"; filename="{fname}"'.encode()
                + crlf
            )
            body += f"Content-Type: {ctype}".encode() + crlf + crlf
            body += data + crlf

    body += b"--" + boundary.encode() + b"--" + crlf
    return bytes(body), f"multipart/form-data; boundary={boundary}"


def _post(url: str, api_key: str, fields: dict, files: dict) -> dict:
    body, content_type = _encode_multipart(fields, files)
    req = urllib.request.Request(url, data=body, method="POST")
    req.add_header("Authorization", f"Bearer {api_key}")
    req.add_header("Content-Type", content_type)
    try:
        with urllib.request.urlopen(req, timeout=300) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")
        sys.exit(f"ERRO HTTP {e.code} da OpenAI:\n{detail}")
    except urllib.error.URLError as e:
        sys.exit(f"ERRO de rede: {e}")


def _post_json(url: str, api_key: str, payload: dict) -> dict:
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=data, method="POST")
    req.add_header("Authorization", f"Bearer {api_key}")
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=300) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        detail = e.read().decode("utf-8", "replace")
        sys.exit(f"ERRO HTTP {e.code} da OpenAI:\n{detail}")
    except urllib.error.URLError as e:
        sys.exit(f"ERRO de rede: {e}")


def _decode_b64(resp: dict) -> bytes:
    import base64

    try:
        b64 = resp["data"][0]["b64_json"]
    except (KeyError, IndexError):
        sys.exit(f"Resposta inesperada da API:\n{json.dumps(resp, indent=2)[:1000]}")
    return base64.b64decode(b64)


def _usage_str(resp: dict) -> str:
    usage = resp.get("usage")
    if not usage:
        return ""
    return (
        f"  (input_tokens={usage.get('input_tokens', '?')}, "
        f"output_tokens={usage.get('output_tokens', '?')})"
    )


def _save_b64(resp: dict, out_path: str):
    with open(out_path, "wb") as fh:
        fh.write(_decode_b64(resp))
    print(f"✅ Salvo: {out_path}{_usage_str(resp)}")


# --------------------------------------------------------------------------- #
# Subcomandos                                                                  #
# --------------------------------------------------------------------------- #
def cmd_edit(args):
    api_key = load_api_key()
    model = args.model
    fields = {
        "model": model,
        "prompt": args.prompt,
        "size": args.size,
        "quality": args.quality,
        "n": "1",
        "output_format": args.output_format,
    }
    if args.background:
        if args.background == "transparent" and model in MODELS_NO_TRANSPARENT:
            print(
                f"⚠️  {model} não suporta background=transparent. Ignorando. "
                "(Use --model gpt-image-1 para transparência, ou recomponha o alpha depois.)",
                file=sys.stderr,
            )
        else:
            fields["background"] = args.background
    # Imagens de referência (logos, estilos): vão JUNTO da imagem base no campo image[].
    # O gpt-image-2 usa todas como referência visual — é assim que se envia uma logo real
    # para ela reproduzir fiel, em vez de descrever a logo em texto.
    refs = getattr(args, "ref", None) or []
    if refs and args.mask:
        sys.exit("ERRO: --ref não pode ser usado com --mask (máscara exige campo image único).")
    if refs:
        files = {"image[]": [args.image, *refs]}
    else:
        files = {"image": args.image}

    if args.mask:
        files["mask"] = args.mask
        # gpt-image-2: não aceita input_fidelity (já preserva pixels fora da máscara).
        # gpt-image-1: input_fidelity + mask cai para dall-e-2 (bug) -> omitir.
        if args.input_fidelity:
            if model in MODELS_NO_FIDELITY:
                pass  # gpt-image-2 já preserva o resto; silencioso.
            elif model in MODELS_FIDELITY_BREAKS_WITH_MASK:
                print(
                    f"⚠️  Ignorando --input-fidelity: com {model} + máscara a API cai "
                    "para dall-e-2. A máscara já limita a edição.",
                    file=sys.stderr,
                )
    else:
        # Sem máscara: input_fidelity=high preserva detalhes (só onde suportado).
        if args.input_fidelity and model not in MODELS_NO_FIDELITY:
            fields["input_fidelity"] = args.input_fidelity

    resp = _post(f"{API_BASE}/images/edits", api_key, fields, files)
    _save_b64(resp, args.output)


def cmd_rmbg(args):
    api_key = load_api_key()
    # rmbg exige transparência -> usa gpt-image-1 (gpt-image-2 não suporta transparent).
    model = "gpt-image-1"
    if args.model and args.model != model:
        print(
            f"⚠️  rmbg precisa de background transparente; forçando --model gpt-image-1 "
            f"(você pediu {args.model}, que não suporta transparência).",
            file=sys.stderr,
        )
    prompt = args.prompt or (
        "Remove the background completely, keeping only the main subject. "
        "Output a clean cutout with a fully transparent background."
    )
    fields = {
        "model": model,
        "prompt": prompt,
        "size": args.size,
        "quality": args.quality,
        "n": "1",
        "background": "transparent",
        "output_format": "png",  # transparência exige png (ou webp)
    }
    if args.input_fidelity:
        fields["input_fidelity"] = args.input_fidelity
    files = {"image": args.image}
    resp = _post(f"{API_BASE}/images/edits", api_key, fields, files)
    _save_b64(resp, args.output)


def cmd_delogo(args):
    """Fluxo completo: remove logo (gpt-image-2) -> tira fundo (gpt-image-1) ->
    recompõe no tamanho/proporção do original. Preserva PNG transparente.

    Foi validado para fotos de veículos com logo/marca na placa, sobre fundo
    transparente ou chapado. Cada execução faz DUAS chamadas de API.
    """
    try:
        from PIL import Image
    except ImportError:
        sys.exit("ERRO: `delogo` requer Pillow.  python3 -m pip install Pillow")
    import io

    api_key = load_api_key()
    orig = Image.open(args.image)
    ow, oh = orig.size
    print(f"→ original: {ow}x{oh}")

    # Passo 1 — remover o logo com gpt-image-2 (entende a cena, sem máscara).
    logo_desc = args.logo or "logo/badge/emblem"
    prompt1 = (
        f"Remove COMPLETELY any '{logo_desc}' branding/text from this image "
        "(typically on the vehicle's license plate or body). Leave the area "
        "clean, neutral and realistic. Do NOT change anything else: keep the "
        "subject, colors, position, angle, lighting and background exactly identical."
    )
    print("→ passo 1/2: removendo logo (gpt-image-2)…")
    r1 = _post(
        f"{API_BASE}/images/edits",
        api_key,
        {"model": "gpt-image-2", "prompt": prompt1, "size": args.size,
         "quality": args.quality, "n": "1", "output_format": "png"},
        {"image": args.image},
    )
    print("  " + _usage_str(r1).strip())
    step1_path = args.output + ".step1.png"
    with open(step1_path, "wb") as fh:
        fh.write(_decode_b64(r1))

    # Passo 2 — remover o fundo com gpt-image-1 (background=transparent).
    prompt2 = (
        "Remove the background completely, keeping only the subject(s) "
        "(the vehicle/vehicles), with clean edges. Output a transparent cutout. "
        "Keep the subject exactly as it is."
    )
    print("→ passo 2/2: removendo fundo (gpt-image-1, transparente)…")
    r2 = _post(
        f"{API_BASE}/images/edits",
        api_key,
        {"model": "gpt-image-1", "prompt": prompt2, "size": args.size,
         "quality": args.quality, "n": "1", "background": "transparent",
         "output_format": "png"},
        {"image": step1_path},
    )
    print("  " + _usage_str(r2).strip())

    # Passo 3 — recompõe no tamanho original.
    cut = Image.open(io.BytesIO(_decode_b64(r2))).convert("RGBA")
    final = cut.resize((ow, oh), Image.LANCZOS)
    final.save(args.output)
    if not args.keep_steps:
        os.remove(step1_path)
    px = list(final.getdata())
    transp = sum(1 for p in px if p[3] < 10) / len(px) * 100
    print(f"✅ Salvo: {args.output}  ({ow}x{oh}, {transp:.0f}% transparente)")


def cmd_gen(args):
    api_key = load_api_key()
    payload = {
        "model": args.model,
        "prompt": args.prompt,
        "size": args.size,
        "quality": args.quality,
        "n": 1,
        "output_format": args.output_format,
    }
    if args.background:
        payload["background"] = args.background
    resp = _post_json(f"{API_BASE}/images/generations", api_key, payload)
    _save_b64(resp, args.output)


def cmd_mask(args):
    try:
        from PIL import Image
    except ImportError:
        sys.exit(
            "ERRO: o subcomando `mask` requer Pillow.\n"
            "Instale com:  python3 -m pip install Pillow"
        )
    src = Image.open(args.image)
    w, h = src.size
    # Máscara totalmente OPACA (não editar) ...
    mask = Image.new("RGBA", (w, h), (0, 0, 0, 255))
    from PIL import ImageDraw

    draw = ImageDraw.Draw(mask)
    for rect in args.rect:
        try:
            x1, y1, x2, y2 = (int(v) for v in rect.split(","))
        except ValueError:
            sys.exit(f"--rect inválido: {rect!r} (use x1,y1,x2,y2)")
        # ... e transparente (alpha=0) na área a editar.
        draw.rectangle([x1, y1, x2, y2], fill=(0, 0, 0, 0))
    mask.save(args.output)
    print(f"✅ Máscara salva: {args.output}  ({w}x{h}, {len(args.rect)} área(s) a editar)")
    print("   Confira-a e depois rode `edit` com --mask " + args.output)


# --------------------------------------------------------------------------- #
# CLI                                                                          #
# --------------------------------------------------------------------------- #
def build_parser():
    p = argparse.ArgumentParser(
        description="Edição de imagens via OpenAI gpt-image-1.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    sub = p.add_subparsers(dest="command", required=True)

    def add_common(sp, with_image=True):
        if with_image:
            sp.add_argument("image", help="Imagem de entrada (PNG/JPG/WebP)")
        sp.add_argument("-o", "--output", required=True, help="Arquivo de saída")
        sp.add_argument(
            "--model",
            default=DEFAULT_MODEL,
            help="gpt-image-2 (default, inpainting real) | gpt-image-1 (suporta transparência)",
        )
        sp.add_argument(
            "--size",
            default="auto",
            help="auto | 1024x1024 | 1024x1536 | 1536x1024 (default: auto)",
        )
        sp.add_argument(
            "--quality",
            default="medium",
            choices=["low", "medium", "high", "auto"],
            help="default: medium",
        )

    # edit
    e = sub.add_parser("edit", help="Edita uma imagem (com/sem máscara)")
    add_common(e)
    e.add_argument("-p", "--prompt", required=True, help="O que mudar")
    e.add_argument(
        "--ref",
        action="append",
        metavar="IMG",
        help="Imagem de referência extra (logo, estilo). Pode repetir: --ref logo1.png --ref logo2.png. "
        "Enviadas junto da base no campo image[] para o modelo reproduzi-las fielmente.",
    )
    e.add_argument("--mask", help="Máscara PNG (alpha=0 = área a editar)")
    e.add_argument(
        "--input-fidelity",
        choices=["high", "low"],
        default="high",
        help="Preserva detalhes do original (só SEM máscara). default: high",
    )
    e.add_argument("--background", choices=["auto", "transparent", "opaque"])
    e.add_argument("--output-format", default="png", choices=["png", "jpeg", "webp"])
    e.set_defaults(func=cmd_edit)

    # rmbg
    r = sub.add_parser("rmbg", help="Remove fundo -> PNG transparente")
    add_common(r)
    r.add_argument("-p", "--prompt", help="(opcional) ajuste o prompt de recorte")
    r.add_argument("--input-fidelity", choices=["high", "low"], default="high")
    r.set_defaults(func=cmd_rmbg)

    # gen
    g = sub.add_parser("gen", help="Gera imagem nova a partir de prompt")
    add_common(g, with_image=False)
    g.add_argument("-p", "--prompt", required=True)
    g.add_argument("--background", choices=["auto", "transparent", "opaque"])
    g.add_argument("--output-format", default="png", choices=["png", "jpeg", "webp"])
    g.set_defaults(func=cmd_gen)

    # delogo (fluxo completo: remove logo + tira fundo + tamanho original)
    dl = sub.add_parser(
        "delogo",
        help="Remove logo (gpt-image-2) + fundo transparente (gpt-image-1) + tamanho original",
    )
    add_common(dl)  # --model é ignorado aqui (usa os dois fixos), mas mantém --size/--quality
    dl.add_argument(
        "--logo",
        help="Descrição do logo a remover (ex: 'ROAS TRANSFER golden badge'). Default genérico.",
    )
    dl.add_argument(
        "--keep-steps",
        action="store_true",
        help="Mantém o PNG intermediário (.step1.png) para inspeção.",
    )
    dl.set_defaults(func=cmd_delogo)

    # mask
    m = sub.add_parser("mask", help="Gera máscara retangular (requer Pillow)")
    m.add_argument("image", help="Imagem de referência (para pegar dimensões)")
    m.add_argument("-o", "--output", required=True, help="Máscara PNG de saída")
    m.add_argument(
        "--rect",
        action="append",
        required=True,
        metavar="x1,y1,x2,y2",
        help="Área a editar (alpha=0). Pode repetir para várias áreas.",
    )
    m.set_defaults(func=cmd_mask)

    return p


def main():
    parser = build_parser()
    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
