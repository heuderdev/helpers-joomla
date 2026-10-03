"""
Troca o PERFIL do OBS automaticamente conforme a coleção de cenas.

Por que: a resolução (1920x1080 vs 1080x1920) é do PERFIL, não da coleção.
Sem isso, ao abrir "TPL · Reels 9:16" o canvas continua 16:9 e tudo sai esmagado.

Instalar: OBS > Ferramentas > Scripts > + > escolher este arquivo.
"""
import obspython as obs

# coleção  ->  perfil
MAPA = {
    "TPL · Reels 9:16": "Vertical 9x16",
    "TPL · Live":       "Sem nome",
    "TPL · YouTube 16:9": "Sem nome",
    "TPL · Aula":       "Sem nome",
}

def ao_trocar_colecao(evento):
    if evento != obs.OBS_FRONTEND_EVENT_SCENE_COLLECTION_CHANGED:
        return
    colecao = obs.obs_frontend_get_current_scene_collection()
    alvo = MAPA.get(colecao)
    if not alvo:
        return
    atual = obs.obs_frontend_get_current_profile()
    if atual == alvo:
        return
    # lista os perfis existentes pra não tentar um que não existe
    perfis = obs.obs_frontend_get_profiles()
    if alvo in perfis:
        obs.obs_frontend_set_current_profile(alvo)
        print(f"[perfil-auto] {colecao} -> perfil '{alvo}'")
    else:
        print(f"[perfil-auto] perfil '{alvo}' não existe")

def script_description():
    return ("<b>Perfil automático</b><br>"
            "Troca o perfil (e portanto a resolução) conforme a coleção de cenas.<br>"
            "Reels 9:16 → perfil <i>Vertical 9x16</i> (1080x1920).<br>"
            "As demais → perfil <i>Sem nome</i> (1920x1080).")

def script_load(settings):
    obs.obs_frontend_add_event_callback(ao_trocar_colecao)
    print("[perfil-auto] ativo")

def script_unload():
    obs.obs_frontend_remove_event_callback(ao_trocar_colecao)
