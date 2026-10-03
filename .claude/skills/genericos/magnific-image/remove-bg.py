#!/usr/bin/env python3
"""Remove o fundo de uma imagem via Magnific e salva PNG/WebP com transparência real.

Usa POST /v1/ai/beta/remove-background — modelo de SEGMENTAÇÃO, não generativo.
Isso importa: ele apenas separa o sujeito do fundo, então é impossível que altere
o objeto (carro, produto, pessoa). Modelos generativos (gpt-image-1, nano-banana,
seedream) redesenham a imagem e destroem detalhes — não use para recorte.

A API só aceita URL pública, então a imagem precisa estar hospedada. Para arquivos
locais, suba antes (ex.: skill `api-upload-files`) e passe a URL resultante.

Uso:
  python3 remove-bg.py --url https://.../foto.webp --out recorte.webp
  python3 remove-bg.py --url https://.../foto.webp --out recorte.webp --size 1024x683
  python3 remove-bg.py --url https://.../foto.jpg  --out recorte.png

Só stdlib + Pillow (opcional, para validar o alpha) + cwebp (opcional, para .webp).
"""
import argparse, json, os, subprocess, sys, tempfile, urllib.parse, urllib.request

BASE = "https://api.magnific.com"
ENDPOINT = "/v1/ai/beta/remove-background"


def carregar_chave():
    chave = os.environ.get("MAGNIFIC_API_KEY")
    if chave:
        return chave
    env = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env")
    if os.path.exists(env):
        for linha in open(env):
            if linha.strip().startswith("MAGNIFIC_API_KEY"):
                return linha.split("=", 1)[1].strip().strip('"').strip("'")
    sys.exit("erro: MAGNIFIC_API_KEY não encontrada (.env ao lado do script ou env var)")


def remover_fundo(image_url, chave):
    """Chama a API (form-urlencoded, síncrona) e devolve a URL do resultado."""
    corpo = urllib.parse.urlencode({"image_url": image_url}).encode()
    req = urllib.request.Request(
        BASE + ENDPOINT,
        data=corpo,
        headers={
            "x-magnific-api-key": chave,
            "Content-Type": "application/x-www-form-urlencoded",
        },
    )
    with urllib.request.urlopen(req, timeout=180) as r:
        dados = json.loads(r.read())
    # a resposta traz original / preview / high_resolution / url (todas expiram em 5 min)
    saida = dados.get("url") or dados.get("high_resolution")
    if not saida:
        sys.exit(f"erro: resposta sem URL de saída: {dados}")
    return saida


def tem_alpha(caminho):
    try:
        from PIL import Image
    except ImportError:
        return None  # sem Pillow não dá pra validar; segue o jogo
    im = Image.open(caminho)
    if im.mode not in ("RGBA", "LA"):
        return False
    return im.getchannel("A").getextrema()[0] < 250


def main():
    p = argparse.ArgumentParser(description="Remove o fundo de uma imagem via Magnific")
    p.add_argument("--url", required=True, help="URL pública da imagem de entrada")
    p.add_argument("--out", required=True, help="arquivo de saída (.png ou .webp)")
    p.add_argument("--size", help="redimensiona a saída, ex: 1024x683 (mantém alpha)")
    args = p.parse_args()

    chave = carregar_chave()
    print("→ removendo fundo …", flush=True)
    url_saida = remover_fundo(args.url, chave)

    with tempfile.NamedTemporaryFile(suffix=".png", delete=False) as tmp:
        png = tmp.name
    urllib.request.urlretrieve(url_saida, png)

    alpha = tem_alpha(png)
    if alpha is False:
        os.unlink(png)
        sys.exit("erro: a API devolveu imagem SEM transparência")

    if args.out.lower().endswith(".webp"):
        cmd = ["cwebp", "-q", "92", "-alpha_q", "100"]
        if args.size:
            w, h = args.size.lower().split("x")
            cmd += ["-resize", w, h]
        cmd += [png, "-o", args.out]
        r = subprocess.run(cmd, capture_output=True)
        if r.returncode != 0:
            os.unlink(png)
            sys.exit(f"erro no cwebp: {r.stderr.decode()[:200]}")
    else:
        if args.size:
            from PIL import Image
            w, h = (int(v) for v in args.size.lower().split("x"))
            Image.open(png).resize((w, h), Image.LANCZOS).save(args.out)
        else:
            os.replace(png, args.out)
            png = None

    if png and os.path.exists(png):
        os.unlink(png)

    print(json.dumps({"ok": True, "out": args.out, "transparente": bool(alpha)}))


if __name__ == "__main__":
    main()
