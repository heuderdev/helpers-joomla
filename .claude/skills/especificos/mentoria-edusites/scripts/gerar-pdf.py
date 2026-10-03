#!/usr/bin/env python3
"""
Gera o PDF de relatorio de uma call da Mentoria EduSites.

Identidade fixa: fundo branco, cor principal preta, logo EduSites no topo.

Uso:
    python3 gerar-pdf.py --json dados.json --saida "/caminho/Relatorio.pdf"

A estrutura do JSON esta em exemplo.json (mesma pasta). Todas as secoes sao
opcionais: o que nao vier no JSON simplesmente nao e renderizado.
"""

import argparse
import base64
import html
import json
import os
import subprocess
import sys
import tempfile

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOGO = os.path.join(BASE, "assets", "logo-edusites.png")
CHROME = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"


def esc(t):
    return html.escape(str(t), quote=False)


def logo_data_uri():
    """Logo embutida em base64 — o PDF precisa ser autocontido."""
    b64_cache = os.path.join(BASE, "assets", "logo-edusites.b64")
    if os.path.exists(b64_cache):
        with open(b64_cache) as f:
            return "data:image/png;base64," + f.read().strip()
    if os.path.exists(LOGO):
        with open(LOGO, "rb") as f:
            return "data:image/png;base64," + base64.b64encode(f.read()).decode()
    return ""


CSS = """
@page { size: A4; margin: 16mm 15mm 14mm; }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: -apple-system, "Helvetica Neue", Arial, sans-serif;
       color: #16161a; font-size: 10.5pt; line-height: 1.55; margin: 0; background: #fff; }

/* Cabecalho */
.top { display: flex; align-items: center; justify-content: space-between;
       border-bottom: 3px solid #0d0d0f; padding-bottom: 11px; margin-bottom: 20px; }
.top img { height: 30px; }
.top .et { font-size: 8pt; letter-spacing: .16em; text-transform: uppercase;
           color: #6b6b75; font-weight: 700; text-align: right; }

h1 { font-size: 25pt; margin: 0 0 5px; letter-spacing: -.6px; line-height: 1.14; }
.lead { color: #55555f; font-size: 11pt; margin: 0 0 14px; }
.facts { border: 1px solid #dcdce2; border-radius: 5px; padding: 10px 13px;
         font-size: 9.5pt; color: #44444d; margin-bottom: 6px; }
.facts b { color: #0d0d0f; }

h2 { font-size: 13.5pt; margin: 24px 0 9px; padding-bottom: 6px;
     border-bottom: 2px solid #0d0d0f; letter-spacing: -.2px; }
h2 .n { color: #8a8a93; margin-right: 8px; font-variant-numeric: tabular-nums; }
h3 { font-size: 10.5pt; margin: 15px 0 6px; text-transform: uppercase;
     letter-spacing: .07em; color: #6b6b75; }
p { margin: 0 0 8px; }
ul { margin: 0 0 10px; padding-left: 17px; }
li { margin-bottom: 4px; }

table { width: 100%; border-collapse: collapse; margin: 8px 0 12px; font-size: 9.5pt; }
th { background: #0d0d0f; color: #fff; text-align: left; padding: 7px 9px;
     font-size: 8.5pt; text-transform: uppercase; letter-spacing: .06em; }
td { padding: 6px 9px; border-bottom: 1px solid #e6e6ea; vertical-align: top; }
tr:nth-child(even) td { background: #fafafb; }

.box { background: #f7f7f9; border-left: 3px solid #0d0d0f;
       padding: 11px 14px; margin: 11px 0; border-radius: 0 4px 4px 0; }
.box.strong { background: #0d0d0f; color: #fff; }
.box.strong b { color: #fff; }
.box p:last-child { margin-bottom: 0; }

.kpi { display: flex; gap: 9px; margin: 12px 0; }
.kpi div { flex: 1; border: 1px solid #dcdce2; border-radius: 5px;
           padding: 11px 8px; text-align: center; }
.kpi .v { font-size: 15pt; font-weight: 700; color: #0d0d0f; display: block;
          letter-spacing: -.4px; }
.kpi .l { font-size: 7.6pt; color: #6b6b75; text-transform: uppercase; letter-spacing: .05em; }

.chk { list-style: none; padding-left: 0; }
.chk li { padding: 8px 10px 8px 32px; border: 1px solid #dcdce2; border-radius: 4px;
          margin-bottom: 6px; position: relative; background: #fff; }
.chk li:before { content: ""; position: absolute; left: 11px; top: 10px;
                 width: 13px; height: 13px; border: 1.5px solid #0d0d0f; border-radius: 3px; }
.chk li b { display: block; margin-bottom: 1px; }
.chk li span.d { color: #55555f; font-size: 9pt; }
.prazo { float: right; font-size: 7.4pt; font-weight: 700; text-transform: uppercase;
         padding: 2px 7px; border-radius: 3px; letter-spacing: .05em;
         border: 1px solid #0d0d0f; }
.p-alta { background: #0d0d0f; color: #fff; }
.p-media { background: #fff; color: #0d0d0f; }
.p-baixa { background: #fff; color: #6b6b75; border-color: #b8b8c0; }

.quote { border-left: 3px solid #0d0d0f; padding: 3px 0 3px 12px;
         color: #44444d; font-style: italic; margin: 9px 0; }
.pb { page-break-before: always; }
.avoid { page-break-inside: avoid; }
footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #e6e6ea;
         font-size: 8.5pt; color: #8a8a93; text-align: center; }
"""


def bloco_tabela(t):
    out = "<table>"
    if t.get("colunas"):
        out += "<tr>" + "".join(f"<th>{esc(c)}</th>" for c in t["colunas"]) + "</tr>"
    for linha in t.get("linhas", []):
        out += "<tr>" + "".join(f"<td>{c}</td>" for c in linha) + "</tr>"
    return out + "</table>"


def bloco_kpi(itens):
    out = '<div class="kpi">'
    for i in itens:
        out += f'<div><span class="v">{esc(i["valor"])}</span><span class="l">{esc(i["label"])}</span></div>'
    return out + "</div>"


def bloco_checklist(grupos):
    out = ""
    for g in grupos:
        cls = {"alta": "p-alta", "media": "p-media", "baixa": "p-baixa"}.get(
            g.get("nivel", "media"), "p-media")
        out += f'<h3>{esc(g["titulo"])}</h3><ul class="chk">'
        for it in g.get("itens", []):
            tag = esc(it.get("prazo", g["titulo"]))
            out += (f'<li class="avoid"><span class="prazo {cls}">{tag}</span>'
                    f'<b>{esc(it["acao"])}</b>'
                    f'<span class="d">{esc(it.get("detalhe", ""))}</span></li>')
        out += "</ul>"
    return out


def render_secao(s):
    out = ""
    if s.get("quebra"):
        out += '<div class="pb"></div>'
    num = f'<span class="n">{esc(s["numero"])}</span>' if s.get("numero") else ""
    out += f'<h2>{num}{esc(s["titulo"])}</h2>'
    for b in s.get("blocos", []):
        t = b.get("tipo")
        if t == "texto":
            out += f'<p>{b["conteudo"]}</p>'
        elif t == "subtitulo":
            out += f'<h3>{esc(b["conteudo"])}</h3>'
        elif t == "lista":
            out += "<ul>" + "".join(f"<li>{i}</li>" for i in b["itens"]) + "</ul>"
        elif t == "tabela":
            out += bloco_tabela(b)
        elif t == "kpi":
            out += bloco_kpi(b["itens"])
        elif t == "destaque":
            cls = "box strong" if b.get("forte") else "box"
            out += f'<div class="{cls}"><p>{b["conteudo"]}</p></div>'
        elif t == "citacao":
            out += f'<div class="quote">{b["conteudo"]}</div>'
        elif t == "checklist":
            out += bloco_checklist(b["grupos"])
    return out


def montar_html(d):
    logo = logo_data_uri()
    img = f'<img src="{logo}" alt="EduSites">' if logo else "<b>EduSites</b>"
    cab = d.get("cabecalho", {})

    facts = " &nbsp;·&nbsp; ".join(
        f"<b>{esc(k)}:</b> {esc(v)}" for k, v in cab.get("dados", {}).items())

    corpo = "".join(render_secao(s) for s in d.get("secoes", []))

    return f"""<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<title>{esc(cab.get('titulo', 'Mentoria EduSites'))}</title>
<style>{CSS}</style></head><body>
<div class="top">{img}<div class="et">{esc(cab.get('etiqueta', 'Mentoria'))}</div></div>
<h1>{esc(cab.get('titulo', ''))}</h1>
<p class="lead">{esc(cab.get('subtitulo', ''))}</p>
<div class="facts">{facts}</div>
{corpo}
<footer>{esc(d.get('rodape', 'Mentoria EduSites'))}</footer>
</body></html>"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--json", required=True)
    ap.add_argument("--saida", required=True)
    ap.add_argument("--manter-html", action="store_true",
                    help="mantem o .html gerado ao lado do PDF (para depurar layout)")
    a = ap.parse_args()

    with open(a.json, encoding="utf-8") as f:
        dados = json.load(f)

    htm = montar_html(dados)

    if a.manter_html:
        caminho_html = os.path.splitext(a.saida)[0] + ".html"
        with open(caminho_html, "w", encoding="utf-8") as f:
            f.write(htm)
    else:
        tmp = tempfile.NamedTemporaryFile("w", suffix=".html", delete=False,
                                          encoding="utf-8")
        tmp.write(htm)
        tmp.close()
        caminho_html = tmp.name

    os.makedirs(os.path.dirname(os.path.abspath(a.saida)), exist_ok=True)

    r = subprocess.run(
        [CHROME, "--headless", "--disable-gpu", "--no-pdf-header-footer",
         f"--print-to-pdf={a.saida}", f"file://{caminho_html}"],
        capture_output=True, text=True)

    if not os.path.exists(a.saida):
        print("ERRO ao gerar o PDF:", r.stderr[-500:], file=sys.stderr)
        sys.exit(1)

    kb = os.path.getsize(a.saida) / 1024
    print(f"PDF gerado: {a.saida} ({kb:.0f} KB)")


if __name__ == "__main__":
    main()
