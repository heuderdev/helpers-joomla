import json, os, time

def num(x, d=0.0):
    try: return float(x)
    except (TypeError, ValueError): return d

def brl(v):
    return f"{v:,.2f}".replace(',', 'X').replace('.', ',').replace('X', '.')

def milhar(v):
    return f"{int(v):,}".replace(',', '.')

def barra(pct, n=20):
    c = max(0, min(n, int(round(pct / 100 * n))))
    return "█" * c + "░" * (n - c)

TETO_CPA = 40.0
VERDE, VERMELHO, AZUL, CINZA = 5763719, 15548997, 5793266, 10070709

ins    = json.loads(os.environ['INS'])
camps  = json.loads(os.environ['CAMPS'])
adsets = json.loads(os.environ['ADSETS'])
acum   = json.loads(os.environ['ACUM'])
repro  = json.loads(os.environ['REPROV'])
nome   = os.environ['NOME']
cor    = int(os.environ['COR'])

ts = int(time.time())
comps = []
data = ins.get('data', [])

if not data:
    comps.append({"type": 17, "accent_color": CINZA, "components": [
        {"type": 10, "content": f"# \U0001f4ca {nome}\n-# nenhuma entrega registrada hoje · <t:{ts}:R>"}]})
else:
    r = data[0]
    A = {x['action_type']: num(x['value']) for x in (r.get('actions') or [])}
    K = {x['action_type']: num(x['value']) for x in (r.get('cost_per_action_type') or [])}
    V = {x['action_type']: num(x['value']) for x in (r.get('action_values') or [])}

    gasto   = num(r.get('spend'))
    compras = int(A.get('purchase', 0))
    receita = V.get('purchase', 0.0)
    cpa     = K.get('purchase', 0.0)
    leads   = int(A.get('lead', 0) or A.get('onsite_web_lead', 0))
    ic      = int(A.get('initiate_checkout', 0))
    lpv     = int(A.get('landing_page_view', 0))
    roas    = receita / gasto if gasto else 0
    lucro   = receita - gasto
    alerta  = bool(compras and cpa > TETO_CPA)

    # ---- Container 1: cabecalho + resumo + funil ----
    c1 = [{"type": 10, "content":
           f"# \U0001f4ca {nome}\n" +
           ("## ⚠️ CPA acima do teto\n" if alerta else "") +
           f"-# atualizado <t:{ts}:R> · dados de <t:{ts}:D>"}]
    c1.append({"type": 14, "divider": True, "spacing": 2})

    L = ["```diff", f"  Investido   R$ {brl(gasto)}"]
    if compras:
        L.append(f"+ Receita     R$ {brl(receita)}")
        L.append(f"+ Compras     {compras}")
        L.append(f"{'-' if cpa > TETO_CPA else '+'} CPA         R$ {brl(cpa)}" +
                 (f"   (teto R$ {int(TETO_CPA)})" if cpa > TETO_CPA else ""))
        L.append(f"{'-' if roas < 1 else '+'} ROAS        {roas:.2f}x")
        L.append(f"{'-' if lucro < 0 else '+'} Resultado   {'+' if lucro >= 0 else '-'}R$ {brl(abs(lucro))}")
    else:
        L.append("- Compras     0   sem compra ainda")
    L.append("```")
    c1.append({"type": 10, "content": "\n".join(L)})
    c1.append({"type": 14, "divider": True, "spacing": 1})
    c1.append({"type": 10, "content":
        f"### Funil\n\U0001f441️ **{lpv}** visitas" +
        (f" _(R$ {brl(K.get('landing_page_view', 0))} cada)_" if lpv else "") +
        f"\n\U0001f4dd **{leads}** leads · \U0001f9fe **{ic}** checkouts\n"
        f"### Alcance\n\U0001f465 **{milhar(num(r.get('reach')))}** pessoas _({milhar(num(r.get('impressions')))} impressões)_\n"
        f"\U0001f5b1️ CTR **{num(r.get('ctr')):.2f}%** _({int(num(r.get('clicks')))} cliques)_ · "
        f"\U0001f4e3 CPM **R$ {brl(num(r.get('cpm')))}** · CPC **R$ {brl(num(r.get('cpc')))}**"})
    comps.append({"type": 17, "accent_color": VERMELHO if alerta else cor, "components": c1})

    # ---- Container 2: por conjunto ----
    adata = [a for a in adsets.get('data', []) if num(a.get('spend')) > 0]
    if adata:
        tot = sum(num(a.get('spend')) for a in adata) or 1
        c2 = [{"type": 10, "content": "## \U0001f3af Por conjunto"},
              {"type": 14, "divider": True, "spacing": 1}]
        sel = sorted(adata, key=lambda x: -num(x.get('spend')))[:8]
        for i, s in enumerate(sel):
            aa = {x['action_type']: num(x['value']) for x in (s.get('actions') or [])}
            kk = {x['action_type']: num(x['value']) for x in (s.get('cost_per_action_type') or [])}
            vv = {x['action_type']: num(x['value']) for x in (s.get('action_values') or [])}
            gg = num(s.get('spend')); pp = int(aa.get('purchase', 0))
            cc = kk.get('purchase', 0.0); rr = vv.get('purchase', 0.0)
            pct = gg / tot * 100
            if pp:
                st = (f"\U0001f6d2 **{pp}** compra(s) · CPA R$ {brl(cc)} "
                      f"{'⚠️' if cc > TETO_CPA else '✅'} · ROAS {rr/gg if gg else 0:.2f}x")
            else:
                st = "\U0001f6d2 sem compra"
            ld = int(aa.get('lead', 0) or aa.get('onsite_web_lead', 0))
            c2.append({"type": 10, "content":
                f"**{s.get('adset_name','?')[:44]}**\n`{barra(pct)}` **{pct:.0f}%** · `R$ {brl(gg)}`\n{st}\n"
                f"-# {ld} leads · {int(aa.get('initiate_checkout',0))} checkouts · "
                f"CTR {num(s.get('ctr')):.2f}% · CPM R$ {brl(num(s.get('cpm')))} · "
                f"freq {num(s.get('frequency')):.2f}"})
            if i < len(sel) - 1:
                c2.append({"type": 14, "divider": False, "spacing": 1})
        comps.append({"type": 17, "accent_color": AZUL, "components": c2})

    # ---- Container 3: por campanha + total acumulado ----
    amap = {}
    for c in acum.get('data', []):
        av = {x['action_type']: num(x['value']) for x in (c.get('action_values') or [])}
        ac = {x['action_type']: num(x['value']) for x in (c.get('actions') or [])}
        amap[c.get('campaign_id')] = {'g': num(c.get('spend')),
                                      'r': av.get('purchase', 0.0),
                                      'p': int(ac.get('purchase', 0))}
    cdata = [c for c in camps.get('data', []) if num(c.get('spend')) > 0]
    if cdata:
        c3 = [{"type": 10, "content": "## \U0001f4c8 Por campanha"},
              {"type": 14, "divider": True, "spacing": 1}]
        verde = True
        sel = sorted(cdata, key=lambda x: -num(x.get('spend')))[:5]
        for i, c in enumerate(sel):
            aa = {x['action_type']: num(x['value']) for x in (c.get('actions') or [])}
            kk = {x['action_type']: num(x['value']) for x in (c.get('cost_per_action_type') or [])}
            gg = num(c.get('spend')); pp = int(aa.get('purchase', 0)); cc = kk.get('purchase', 0.0)
            if pp:
                hoje = (f"hoje `R$ {brl(gg)}` → **{pp}** compra(s) · CPA R$ {brl(cc)} "
                        f"{'⚠️' if cc > TETO_CPA else '✅'}")
            else:
                hoje = f"hoje `R$ {brl(gg)}` → sem compra · CTR {num(c.get('ctr')):.2f}%"
            txt = f"**{c.get('campaign_name','?')[:44]}**\n{hoje}"
            t = amap.get(c.get('campaign_id'))
            if t and t['g'] > 0:
                lt = t['r'] - t['g']
                rt = t['r'] / t['g'] if t['g'] else 0
                if lt < 0: verde = False
                txt += (f"\n{'\U0001f7e2' if lt >= 0 else '\U0001f534'} **TOTAL:** R$ {brl(t['g'])} investido "
                        f"· R$ {brl(t['r'])} receita\n"
                        f"**{t['p']}** compras · ROAS **{rt:.2f}x** · "
                        f"resultado **{'+' if lt >= 0 else '−'}R$ {brl(abs(lt))}**")
            c3.append({"type": 10, "content": txt})
            if i < len(sel) - 1:
                c3.append({"type": 14, "divider": False, "spacing": 1})
        c3.append({"type": 14, "divider": True, "spacing": 1})
        c3.append({"type": 10, "content": f"-# teto de CPA R$ {int(TETO_CPA)} · relatório de <t:{ts}:f>"})
        comps.append({"type": 17, "accent_color": VERDE if verde else VERMELHO, "components": c3})

# ---- Container 4: anuncios com problema ----
rdata = [a for a in repro.get('data', [])
         if (a.get('campaign') or {}).get('effective_status') == 'ACTIVE']
if rdata:
    linhas = "\n".join(f"• {a.get('name','?')[:48]} — `{a.get('effective_status')}`" for a in rdata[:6])
    comps.append({"type": 17, "accent_color": VERMELHO, "components": [
        {"type": 10, "content": f"## \U0001f6a8 {len(rdata)} anúncio(s) com problema\n{linhas}"}]})

print(json.dumps({
    "flags": 32768 | 4096,   # IS_COMPONENTS_V2 + SUPPRESS_NOTIFICATIONS
    "components": comps[:40],
    "allowed_mentions": {"parse": []},
}))
