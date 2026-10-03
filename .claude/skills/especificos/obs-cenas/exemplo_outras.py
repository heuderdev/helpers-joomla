#!/usr/bin/env python3
"""Gera TPL Reels, YouTube e Aula reusando a base já validada da Live."""
import sys, json, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from obsbase import *          # helpers, devices, máscaras, fecha o OBS

CAM_W, CAM_H = 1280, 720       # o que a FaceTime HD entrega

def coll(nome, fontes, cenas, cena0, cx, cy):
    return {"current_scene":cena0,"current_program_scene":cena0,
            "current_transition":"Fade","transition_duration":300,
            "preview_locked":False,"scaling_enabled":False,"scaling_level":0,
            "scaling_off_x":0.0,"scaling_off_y":0.0,
            "sources":fontes+cenas,"groups":[],"quick_transitions":[],
            "scene_order":None,  # preenchido em grava()/salvar()
            
            "transitions":[],"saved_projectors":[],"name":nome,
            "resolution":{"x":cx,"y":cy},"migration_resolution":{"x":cx,"y":cy}}

def grava(arq, data):
    # scene_order = quais cenas aparecem na lista e em que ordem.
    # SEM isso o OBS reconstrói do próprio jeito e inventa cenas fantasma.
    # Cenas-fonte (custom_size) ficam de fora: são componentes, não cenas.
    data["scene_order"]=[{"name":s["name"]} for s in data["sources"]
                         if s["id"]=="scene" and not s["settings"].get("custom_size")]
    data["version"]=1
    p=os.path.join(SCENES,arq)
    json.dump(data,open(p,"w",encoding="utf-8"),ensure_ascii=False,indent=4)
    # validação: toda referência tem que existir
    nomes={s['name'] for s in data['sources']}
    ruins=[f"{s['name']}→{i['name']}" for s in data['sources'] if s['id']=='scene'
           for i in s['settings']['items'] if i['name'] not in nomes]
    assert not ruins, ruins
    print(f"  ✓ {arq}")

def cena_circular(nome="Cam Circular"):
    lado=CAM_H
    it=item(1,"Webcam",0,0,1080/lado,1080/lado,
            crop=((CAM_W-lado)//2,0,(CAM_W-lado)//2,0))
    return {"prev_ver":520093699,"name":nome,"uuid":"","id":"scene","versioned_id":"scene",
            "settings":{"id_counter":2,"custom_size":True,"cx":1080,"cy":1080,"items":[it]},
            "mixers":0,"sync":0,"flags":0,"volume":1.0,"balance":0.5,"enabled":True,
            "muted":False,"push-to-mute":False,"push-to-mute-delay":0,"push-to-talk":False,
            "push-to-talk-delay":0,"hotkeys":{},"deinterlace_mode":0,
            "deinterlace_field_order":0,"monitoring_type":0,"private_settings":{},
            "filters":[f_mask_circulo()]}

# =====================================================================
# 1) REELS / STORIES — 1080x1920
# =====================================================================
def reels():
    """9:16 — 1080x1920. Zonas seguras do Reels/TikTok:
       topo ~220px (perfil/nome) e base ~420px (legenda, botões, áudio).
       Todo texto fica ENTRE essas faixas."""
    VW,VH = 1080,1920
    SAFE_TOP, SAFE_BOT = 230, 430
    UTIL_Y  = SAFE_TOP
    UTIL_H  = VH - SAFE_TOP - SAFE_BOT          # 1260 de área livre

    fontes=[
        webcam(), tela(), mic(),
        browser("Gancho", ovl("vertical.html",
                safetop=str(SAFE_TOP), safebot=str(SAFE_BOT)), VW, VH,
                css=':root{ --tag: "olha isso"; --titulo: "Ninguém te conta *isso*"; --handle: "@edusites"; }'),
        media("Trilha","trilha/live.mp3",True,0.35),
    ]

    # --- 1 · Falando: você grande, centralizado na área útil ---
    cam_w1 = VW                                  # preenche a largura
    cam_h1 = cam_w1*CAM_H/CAM_W                  # 608
    falando = scene("1 · Falando", [
        item(0,"Gancho"),                        # TEXTO POR CIMA
        item(0,"Webcam", x=0, y=UTIL_Y+(UTIL_H-cam_h1)/2,
             sx=cam_w1/CAM_W, sy=cam_w1/CAM_W),
        item(0,"Microfone"),
    ])

    # --- 2 · Tela + Eu: tela em cima, você embaixo, ambos dentro da área útil ---
    GAP   = 26
    # divide a altura útil: tela 58%, webcam 42%
    tela_h = (UTIL_H-GAP)*0.58
    tela_s = tela_h/1964
    tela_w = 3024*tela_s
    if tela_w > VW:                              # não pode estourar a largura
        tela_s = VW/3024; tela_w = VW; tela_h = 1964*tela_s
    cam_h2 = (UTIL_H-GAP) - tela_h
    cam_s2 = cam_h2/CAM_H
    cam_w2 = CAM_W*cam_s2
    if cam_w2 > VW:                              # recorta lateral em vez de estourar
        cam_s2 = VW/CAM_W; cam_w2 = VW; cam_h2 = CAM_H*cam_s2
    tela_cam = scene("2 · Tela + Eu", [
        item(0,"Gancho"),
        item(0,"Webcam", x=(VW-cam_w2)/2, y=UTIL_Y+tela_h+GAP,
             sx=cam_s2, sy=cam_s2),
        item(0,"Tela Mac", x=(VW-tela_w)/2, y=UTIL_Y, sx=tela_s, sy=tela_s),
        item(0,"Microfone"),
    ])

    # --- 3 · Só a Tela: tela grande e centralizada ---
    ts = min(VW/3024, UTIL_H/1964)
    so_tela = scene("3 · Só a Tela", [
        item(0,"Gancho"),
        item(0,"Tela Mac", x=(VW-3024*ts)/2, y=UTIL_Y+(UTIL_H-1964*ts)/2,
             sx=ts, sy=ts),
        item(0,"Microfone"),
    ])

    print(f"     área útil: y {UTIL_Y}→{UTIL_Y+UTIL_H} ({UTIL_H}px)")
    print(f"     falando  : webcam {cam_w1:.0f}x{cam_h1:.0f}")
    print(f"     tela+eu  : tela {tela_w:.0f}x{tela_h:.0f} | webcam {cam_w2:.0f}x{cam_h2:.0f}")
    grava("TPL_Reels_9x16.json",
        coll("TPL · Reels 9:16", fontes, [falando,tela_cam,so_tela], "1 · Falando", VW,VH))

# =====================================================================
# 2) YOUTUBE — 1920x1080
# =====================================================================
def youtube():
    W,H=1920,1080
    TELA_S=H/1964; TELA_W=3024*TELA_S; TELA_X=(W-TELA_W)/2
    CIRC=300; M=40
    fontes=[
        webcam(), tela(), mic(),
        browser("Fundo", ovl("fundo-estudio.html"), W,H),
        browser("Abertura", ovl("abertura.html",
                camx="365",camy="150",camw="1190",camh="670"), W,H,
                css=':root{ --titulo: "Bora *codar*"; --handle: "@edusites"; }'),
        browser("Nome", ovl("lower-third.html", dur="0"), W,H,
                css=':root{ --nome: "Eduardo"; --handle: "@edusites"; }'),
        browser("Capítulo", ovl("capitulo.html"), W,H,
                css=':root{ --num: "1"; --modulo: "Parte 1"; --titulo: "Criando o projeto"; }'),
        media("Trilha","trilha/live.mp3",True,0.30),
        media("SFX Transição","sfx/transicao.mp3",False,0.55),
    ]
    abertura = scene("1 · Abertura", [
        item(0,"Abertura"),
        item(0,"Webcam", x=365, y=150, sx=1190/CAM_W, sy=1190/CAM_W),
        item(0,"Microfone"),
    ])
    tela_pip = scene("2 · Tela + Eu", [
        item(0,"Capítulo"),
        item(0,"Cam Circular", x=W-CIRC-M, y=H-CIRC-M, sx=CIRC/1080, sy=CIRC/1080),
        item(0,"Tela Mac", x=TELA_X, y=0, sx=TELA_S, sy=TELA_S),
        item(0,"Fundo"),
        item(0,"Microfone"),
    ])
    so_tela = scene("3 · Só a Tela", [
        item(0,"Capítulo"),
        item(0,"Tela Mac", x=TELA_X, y=0, sx=TELA_S, sy=TELA_S),
        item(0,"Fundo"),
        item(0,"Microfone"),
    ])
    so_eu = scene("4 · Só Eu", [
        item(0,"Nome"),
        item(0,"Webcam", sx=W/CAM_W, sy=H/CAM_H),
        item(0,"Microfone"),
    ])
    grava("TPL_Youtube_16x9.json",
        coll("TPL · YouTube 16:9", fontes,
             [cena_circular(),abertura,tela_pip,so_tela,so_eu], "1 · Abertura", W,H))

# =====================================================================
# 3) AULA — 1920x1080, foco em ensinar
# =====================================================================
def aula():
    W,H=1920,1080
    TELA_S=H/1964; TELA_W=3024*TELA_S; TELA_X=(W-TELA_W)/2
    CIRC=260; M=36
    fontes=[
        webcam(), tela(), mic(),
        browser("Fundo", ovl("fundo-estudio.html"), W,H),
        browser("Capítulo", ovl("capitulo.html"), W,H,
                css=':root{ --num: "1"; --modulo: "Aula 1"; --titulo: "O que vamos construir"; }'),
        browser("Nome", ovl("lower-third.html", dur="0"), W,H,
                css=':root{ --nome: "Eduardo"; --handle: "@edusites"; }'),
        browser("Abertura", ovl("abertura.html",
                camx="365",camy="150",camw="1190",camh="670"), W,H,
                css=':root{ --titulo: "Aula *1*"; --handle: "@edusites"; }'),
        browser("Intervalo", ovl("pausa.html", seg="300"), W,H),
        media("Trilha","trilha/live.mp3",True,0.28),
        media("Trilha Pausa","trilha/espera.mp3",True,0.5),
    ]
    intro = scene("1 · Intro da Aula", [
        item(0,"Abertura"),
        item(0,"Webcam", x=365, y=150, sx=1190/CAM_W, sy=1190/CAM_W),
        item(0,"Microfone"),
    ])
    # o principal da aula: tela grande + você pequeno, sempre visível
    ensinando = scene("2 · Ensinando", [
        item(0,"Capítulo"),
        item(0,"Cam Circular", x=W-CIRC-M, y=H-CIRC-M, sx=CIRC/1080, sy=CIRC/1080),
        item(0,"Tela Mac", x=TELA_X, y=0, sx=TELA_S, sy=TELA_S),
        item(0,"Fundo"),
        item(0,"Microfone"),
    ])
    # zoom pra detalhe de código
    zoom = scene("3 · Zoom no Código", [
        item(0,"Capítulo"),
        item(0,"Tela Mac", x=TELA_X-TELA_W*0.25, y=-H*0.25,
             sx=TELA_S*1.5, sy=TELA_S*1.5),
        item(0,"Fundo"),
        item(0,"Microfone"),
    ])
    explicando = scene("4 · Explicando", [
        item(0,"Nome"),
        item(0,"Webcam", sx=W/CAM_W, sy=H/CAM_H),
        item(0,"Microfone"),
    ])
    pausa = scene("5 · Intervalo", [
        item(0,"Intervalo"),
        item(0,"Trilha Pausa"),
    ])
    grava("TPL_Aula.json",
        coll("TPL · Aula", fontes,
             [cena_circular(),intro,ensinando,zoom,explicando,pausa], "1 · Intro da Aula", W,H))

print("Gerando coleções…")
reels(); youtube(); aula()
print("pronto")
