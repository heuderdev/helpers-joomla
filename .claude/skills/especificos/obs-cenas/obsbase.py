#!/usr/bin/env python3
"""Monta TPL · Live com os devices REAIS e alinhamento correto."""
import json, os, urllib.parse

SCENES = os.path.expanduser("~/Library/Application Support/obs-studio/basic/scenes")
OVL    = os.path.expanduser("~/Library/Application Support/obs-studio/overlays")
AUDIO  = os.path.expanduser("~/Library/Application Support/obs-studio/audio")


# ---- GUARDA_OBS: fecha o OBS com carinho antes de escrever (ele sobrescreve ao sair) ----
import subprocess as _sp, time as _t, sys as _sys
def _fechar_obs():
    if not _sp.run(["pgrep","-x","OBS"],capture_output=True).stdout.strip():
        return "ja_fechado"
    # quit pelo AppleScript = salva e sai limpo, igual Cmd+Q
    _sp.run(["osascript","-e",'tell application "OBS" to quit'],capture_output=True)
    for _ in range(30):
        _t.sleep(0.5)
        if not _sp.run(["pgrep","-x","OBS"],capture_output=True).stdout.strip():
            _t.sleep(1.0)   # deixa o disco assentar
            return "fechado"
    _sys.exit("ABORTADO: o OBS não fechou sozinho. Feche na mão (Cmd+Q) e rode de novo.")
print("OBS:", _fechar_obs())

W,H = 1920,1080

# --- devices reais, confirmados no sistema ---
CAM_UID   = "0x1200000046d0893"          # Logitech StreamCam (porta USB atual)
CAM_NAME  = "Logitech StreamCam"
MIC_UID   = "AppleUSBAudioEngine:Unknown Manufacturer:fifine Microphone:130000:2"  # uid que o OBS aceita (testado)
DISPLAY   = "37D8832A-2D66-02CA-B9F7-8F30A301B230"   # tela do Mac (principal)
DISP_W, DISP_H = 3024, 1964                          # Retina, pixels reais

def ovl(f, **p):
    qs = urllib.parse.urlencode(p, quote_via=urllib.parse.quote)
    return "file://" + urllib.parse.quote(os.path.join(OVL,f)) + (("?"+qs) if qs else "")

def src(name, sid, settings, mixers=0, muted=False, filters=None, vol=1.0):
    s={"prev_ver":520093699,"name":name,"uuid":"","id":sid,"versioned_id":sid,
       "settings":settings,"mixers":mixers,"sync":0,"flags":0,"volume":vol,
       "balance":0.5,"enabled":True,"muted":muted,"push-to-mute":False,
       "push-to-mute-delay":0,"push-to-talk":False,"push-to-talk-delay":0,
       "hotkeys":{},"deinterlace_mode":0,"deinterlace_field_order":0,
       "monitoring_type":0,"private_settings":{}}
    if filters: s["filters"]=filters
    return s

def f_mask_radius():
    """Cantos arredondados na webcam 16:9 — PNG com alpha (v1 não faz forma)."""
    return {"name":"Cantos arredondados","id":"mask_filter","enabled":True,
            "settings":{"type":"mask_alpha_filter.effect",
                        "image_path":os.path.join(OVL,"molde-radius.png"),
                        "stretch":True}}

def f_mask_circulo():
    """Máscara circular. ATENÇÃO: esta versão do OBS tem mask_filter (v1), que NÃO
    faz formas geométricas — exige um PNG com alpha. mask_filter_v2 não existe aqui."""
    return {"name":"Recorte circular","id":"mask_filter","enabled":True,
            "settings":{"type":"mask_alpha_filter.effect",
                        "image_path":os.path.join(OVL,"molde-circulo.png"),
                        "stretch":True}}
def f_realce():
    """Leve realce pra casar a webcam com o roxo da identidade.
    ATENÇÃO: color_filter (v1) EXIGE opacity e os color_*; sem eles o OBS
    assume opacity=0 e a imagem some. Valores conferidos nas coleções que funcionam."""
    return {"name":"Cor","id":"color_filter","enabled":True,
            "settings":{"gamma":0.0,"contrast":0.04,"brightness":0.01,
                        "saturation":0.06,"hue_shift":0.0,"opacity":1.0,
                        "color_multiply":4294967295,"color_add":0}}

def webcam():
    """FaceTime HD: câmera nativa do Mac, não é UVC — imune ao bug do Tahoe que
    afeta a StreamCam. SEM máscara: quando é retangular fica reta, e o
    arredondado circular vem da cena-fonte Cam Circular."""
    return src("Webcam","av_capture_input",
        {"device":"47B4B64B-7067-4B9C-AD2B-AE273A71F4B5",
         "device_name":"Câmera FaceTime HD"})

def tela():
    return src("Tela Mac","screen_capture",
        {"display_uuid":DISPLAY,"type":0,"window":0})

def mic():
    # "default" = segue o microfone padrão do sistema. Assim, quando o Fifine é
    # desplugado a cena não fica muda (cai no mic do MacBook) e, ao replugar,
    # volta pro Fifine sozinho — desde que ele seja o padrão em Ajustes do Sistema.
    return src("Microfone","coreaudio_input_capture",{"device_id":"default"},mixers=255)

def browser(name,url,w,h,css=""):
    st={"url":url,"width":w,"height":h,"reroute_audio":False,
        "restart_when_active":True,"shutdown":True,"fps_custom":False,"fps":30}
    if css: st["css"]=css
    return src(name,"browser_source",st)

def media(name, arquivo, loop, vol):
    """Trilha/SFX como fonte de mídia. mixers=255 = sai em todas as trilhas."""
    return src(name,"ffmpeg_source",
        {"local_file":os.path.join(AUDIO,arquivo),"looping":loop,
         "restart_on_activate":not loop,"clear_on_media_end":not loop,
         "is_local_file":True},
        mixers=255, vol=vol)

def cena_cam_circular():
    """Cena-fonte 1080x1080 com a webcam recortada em quadrado + máscara circular.
    Cena aninhada referencia a fonte, não reabre o device."""
    lado = CAM_H
    it = item(1,"Webcam", x=0, y=0, sx=1080/lado, sy=1080/lado,
              crop=((CAM_W-lado)//2, 0, (CAM_W-lado)//2, 0))
    return {"prev_ver":520093699,"name":"Cam Circular","uuid":"","id":"scene",
            "versioned_id":"scene",
            "settings":{"id_counter":2,"custom_size":True,"cx":1080,"cy":1080,
                        "items":[it]},
            "mixers":0,"sync":0,"flags":0,"volume":1.0,"balance":0.5,"enabled":True,
            "muted":False,"push-to-mute":False,"push-to-mute-delay":0,
            "push-to-talk":False,"push-to-talk-delay":0,"hotkeys":{},
            "deinterlace_mode":0,"deinterlace_field_order":0,"monitoring_type":0,
            "private_settings":{},"filters":[f_mask_circulo()]}

def item(i,name,x=0.0,y=0.0,sx=1.0,sy=1.0,visible=True,crop=(0,0,0,0)):
    return {"name":name,"source_uuid":"","visible":visible,"locked":False,"rot":0.0,
            "scale_ref":{"x":float(W),"y":float(H)},
            "pos":{"x":float(x),"y":float(y)},"scale":{"x":float(sx),"y":float(sy)},
            "align":5,"bounds_type":0,"bounds_align":0,"bounds":{"x":0.0,"y":0.0},
            "crop_left":crop[0],"crop_top":crop[1],"crop_right":crop[2],"crop_bottom":crop[3],
            "id":i,"group_item_backup":False,"scale_filter":"disable",
            "blend_method":"default","blend_type":"normal",
            "show_transition":{"duration":0},"hide_transition":{"duration":0},
            "private_settings":{}}

def caixa(i, name, x, y, w, h, align=0):
    """Item que se ENCAIXA numa caixa w×h mantendo a proporção (bounds_type=2).
    Melhor que calcular escala na mão: o OBS ajusta sozinho se a fonte mudar
    de resolução. bounds_type: 0=nenhum, 1=esticar, 2=caber interno (o certo)."""
    it = item(i, name, x, y, 1.0, 1.0)
    it["bounds_type"]  = 2
    it["bounds"]       = {"x": float(w), "y": float(h)}
    it["bounds_align"] = align
    return it

def scene(name, itens):
    """ATENÇÃO: no JSON do OBS o array de items vai do FUNDO para o TOPO —
    o inverso da lista que aparece na UI. Escrevemos as cenas na ordem visual
    (topo primeiro, que é como se pensa a composição) e invertemos aqui."""
    itens = list(reversed(itens))
    for n,it in enumerate(itens,1): it["id"]=n
    return {"prev_ver":520093699,"name":name,"uuid":"","id":"scene","versioned_id":"scene",
            "settings":{"id_counter":len(itens)+1,"custom_size":False,"items":itens},
            "mixers":0,"sync":0,"flags":0,"volume":1.0,"balance":0.5,"enabled":True,
            "muted":False,"push-to-mute":False,"push-to-mute-delay":0,"push-to-talk":False,
            "push-to-talk-delay":0,"hotkeys":{},"deinterlace_mode":0,
            "deinterlace_field_order":0,"monitoring_type":0,"private_settings":{}}

# ============ MATEMÁTICA DE ALINHAMENTO ============
# Tela Mac: 3024x1964 (Retina) num canvas 1920x1080.
# 3024/1964 = 1.540 (mais quadrada) vs canvas 1.778 (16:9).
# Pra encher a ALTURA sem distorcer: escala = 1080/1964 = 0.5499
# Largura resultante = 3024*0.5499 = 1663px -> centraliza com margem de (1920-1663)/2 = 128px
TELA_S  = H / 1964                     # 0.54990
TELA_W  = 3024 * TELA_S                # 1662.9
TELA_X  = (W - TELA_W) / 2             # 128.5
# Versão "com chat": tela ocupa a esquerda, chat na direita (400px)
CHAT_W  = 400
TELA2_S = (W - CHAT_W - 60) / 3024     # cabe em 1460px de largura
TELA2_H = 1964 * TELA2_S
TELA2_Y = (H - TELA2_H) / 2

# Webcam 1920x1080 no canto: 400px de largura
PIP_S   = 400 / 1920                   # 0.2083
PIP_H   = 1080 * PIP_S                 # 225
MARGEM  = 40

# Webcam circular: recorta 1920x1080 -> quadrado central 1080x1080
CIRC_LADO = 300
CAM_W, CAM_H = 1280, 720               # o que a FaceTime HD entrega de fato
PIP_W   = 420                          # largura da webcam pequena (16:9 arredondado)
PIP_H2  = PIP_W*CAM_H/CAM_W            # altura correspondente
CIRC_CROP = ((CAM_W-CAM_H)//2, 0, (CAM_W-CAM_H)//2, 0)   # 1280x720 -> quadrado 720

