#!/usr/bin/env python3
"""Valida coleções do OBS ANTES de abrir. Pega os erros que deixam a cena quebrada."""
import json, glob, os, sys, urllib.parse

SCENES=os.path.expanduser("~/Library/Application Support/obs-studio/basic/scenes")
OVER={'Gancho','Abertura','Capítulo','Nome','Nome na Tela','Selo Ao Vivo','Tópico',
      'Eu + Chat','Tela de Espera','Tela de Pausa','Intervalo','Legenda'}
MED={'Webcam','Tela Mac','Cam Circular','Cam Redonda'}

def valida(f):
    d=json.load(open(f)); p=[]
    nomes={s['name'] for s in d['sources']}
    if not d.get('scene_order'): p.append("scene_order AUSENTE (o OBS vai inventar cenas)")
    for s in d['sources']:
        st=s.get('settings',{})
        if s['id']=='scene':
            items=st.get('items',[])
            p+=[f"{s['name']}: fonte inexistente '{i['name']}'" for i in items if i['name'] not in nomes]
            ids=[i['id'] for i in items]
            if len(ids)!=len(set(ids)): p.append(f"{s['name']}: ids duplicados")
            if st.get('id_counter',0) <= (max(ids) if ids else 0):
                p.append(f"{s['name']}: id_counter baixo")
            ui=list(reversed([i['name'] for i in items]))
            io=[n for n,x in enumerate(ui) if x in OVER]; im=[n for n,x in enumerate(ui) if x in MED]
            if io and im and max(io)>min(im):
                p.append(f"{s['name']}: overlay ABAIXO de mídia? conferir ordem")
        for k in ('local_file','file','image_path'):
            if st.get(k) and not os.path.exists(st[k]): p.append(f"{s['name']}: sumido {st[k]}")
        for fl in s.get('filters',[]):
            fs=fl.get('settings',{})
            if 'color_filter' in fl['id'] and 'opacity' not in fs:
                p.append(f"{s['name']}/{fl['name']}: color_filter SEM opacity = invisível")
            ip=fs.get('image_path')
            if ip and not os.path.exists(ip): p.append(f"{s['name']}: máscara sumida {ip}")
        u=st.get('url','')
        if u.startswith('file://'):
            fp=urllib.parse.unquote(u[7:].split('?')[0])
            if not os.path.exists(fp): p.append(f"{s['name']}: overlay sumido {fp}")
    return d.get('name',f), p

if __name__=="__main__":
    alvo=sys.argv[1:] or sorted(glob.glob(os.path.join(SCENES,'TPL_*.json')))
    ruim=False
    for f in alvo:
        nome,p=valida(f)
        print(f"\n{nome}: " + ("OK ✅" if not p else "❌"))
        for x in p: print("   -",x); 
        ruim = ruim or bool(p)
    sys.exit(1 if ruim else 0)
