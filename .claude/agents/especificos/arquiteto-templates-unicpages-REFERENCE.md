# REFERENCE — Arquiteto de Templates UnicPages (cola técnica pronta)

Leia este arquivo no INÍCIO. Ele elimina a fase de redescoberta (schema, upload, jwt, prompts). NÃO reinvestigue o que está aqui — só confirme se um path mudou se algo falhar.

## Paths fixos
- Seções-modelo: `/Users/eduardolecdt/Empresas/UnicPages/Repositórios/frontend/nuxt-app/components/pages/editor/sections/models/<categoria>/<nome>.json`
- Templates: `/Users/eduardolecdt/Empresas/UnicPages/Repositórios/frontend/nuxt-app/components/pages/templates/models/sales-page/`
- index.js: `.../templates/models/index.js`
- Skill de imagem: `~/.claude/skills/openai-image-edit/edit-image.py`
- api-upload: `/Users/eduardolecdt/Empresas/UnicPages/Repositórios/backend/api-upload/`
- Chave OpenAI: variável `OPENAI_API_KEY` em `backend/api-worker/.env`
- Uploader pronto: `backend/api-app/scripts/` (ou use o snippet abaixo)

## Schema de seção (resumo)
Cada seção-modelo: `{id, name, description, category, thumbnail, cover, [script], desktop, mobile}`.
- `desktop`/`mobile`: `{id, name, tag(section|nav|footer|header), style{}, children[]}`.
- Elemento: `{id, name, tag(div|h1|h2|h3|p|span|a|img|...), content{}, style{}, children[]}`.
- `content`: `text`, `link{url,target}`, img usa `src` E `imageSrc` (ponha os DOIS), `customId` (id fixo no DOM).
- `style` valores: `{value,unit}` p/ dimensões; `padding/margin/borderRadius{top,right,bottom,left,unit}`; `background{type:'solid',solid:{color,opacity}}` ou gradient/image; `border{top,right,bottom,left,unit,style,color,opacity}`; `boxShadow{x,y,blur,spread,color,opacity,inset}`; strings simples (ex `textWrap:"balance"`, `objectFit:"contain"`) passam direto como CSS.
- Mobile: ids com sufixo `_m`.

## Gerar assets (gpt-image, custo mínimo)
```bash
export OPENAI_API_KEY=$(grep -E "^OPENAI_API_KEY=" /Users/eduardolecdt/Empresas/UnicPages/Repositórios/backend/api-worker/.env | cut -d= -f2-)
cd ~/.claude/skills/openai-image-edit
```
- **Ícone transparente**: `python3 edit-image.py gen --model gpt-image-1 --size 1024x1024 --quality low --background transparent --output-format png -p "Flat minimal line icon of <X>. Thin uniform <cor> strokes hex <HEX>. Simple outline style like Lucide icons. Fully transparent background, no fill, no glow, no shadow, no box, no frame, no text, no background color. Centered with generous padding." -o OUT/<nome>.png`
- **Foto/hero**: `--model gpt-image-2 --quality low` (sem transparent). Prompt do ambiente do nicho na paleta.
- **Logo (símbolo+wordmark)**: gere em fundo BRANCO: `--model gpt-image-1 --size 1536x1024 --quality low -p "...pure solid white background, high contrast, fully opaque crisp serif/sans wordmark '<Nome>' in hex <HEX>, symbol on the left, flat, no blur/glow/shadow"`. Depois recorte: `magick logo.png -fuzz 12% -transparent white -trim +repage logo-transp.png`.
- Rode em LOTES paralelos em background (10 ícones num script `&`, brand noutro). Revise: `magick montage *.png -tile 5x2 -geometry 180x180+6+6 -background "<HEX_FUNDO>" mosaico.png`.

## Upload ao CDN (api-upload usa TOKEN_USER, jwt em .pnpm)
Snippet Node autocontido (ajuste FILES). Sobe a api numa porta livre, assina JWT, posta:
```js
import { readFileSync, existsSync } from 'fs'; import { resolve, basename } from 'path';
import { spawn } from 'child_process'; import { createServer } from 'net';
const API_DIR='/Users/eduardolecdt/Empresas/UnicPages/Repositórios/backend/api-upload';
const FILES=process.argv.slice(2);
const env=Object.fromEntries(readFileSync(API_DIR+'/.env','utf8').split('\n').filter(l=>l.includes('=')).map(l=>[l.slice(0,l.indexOf('=')).trim(),l.slice(l.indexOf('=')+1).trim()]));
const secret=env.TOKEN_USER||env.TOKEN_ADMIN;
const jwt=(await import(API_DIR+'/node_modules/.pnpm/jsonwebtoken@9.0.2/node_modules/jsonwebtoken/index.js')).default;
const token=jwt.sign({data:{origem:'claude-cli'}},secret,{expiresIn:'15m'});
const port=await new Promise(r=>{const s=createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>r(p))})});
const child=spawn('node',['app.js'],{cwd:API_DIR,env:{...process.env,PORT:String(port)},stdio:['ignore','pipe','pipe']});
const up=async()=>{const s=Date.now();while(Date.now()-s<15000){try{const r=await fetch(`http://localhost:${port}/`);if(r.status>0)return 1}catch{}await new Promise(x=>setTimeout(x,500))}};
await up();
const MIME={png:'image/png',jpg:'image/jpeg',jpeg:'image/jpeg',webp:'image/webp'};const map={};
for(const f of FILES){const buf=readFileSync(resolve(f));const nm=basename(f);const fd=new FormData();fd.append('file',new Blob([buf],{type:MIME[nm.split('.').pop()]||'application/octet-stream'}),nm);
 const r=await fetch(`http://localhost:${port}/storage/imagem`,{method:'POST',headers:{'x-access-token':token},body:fd});const j=await r.json();if(j.status==='ok'){map[nm]=j.body.url;console.error('OK '+nm)}else console.error('FAIL '+nm+' '+JSON.stringify(j))}
child.kill();console.log(JSON.stringify(map,null,2));process.exit(0);
```
O api-upload converte pra webp PRESERVANDO alpha dos PNGs transparentes. Troque `src` E `imageSrc` das seções pela URL.

## Montar template + validar (UM script só, no fim)
```js
// node monta.js  -> lê 0*.json do dir de trabalho, valida tudo, escreve template + patcha index.js
import { readFileSync, writeFileSync, readdirSync } from 'fs'; import { resolve } from 'path';
const DIR=process.argv[2], SLUG=process.argv[3], NAME=process.argv[4], DESC=process.argv[5]||'Landing Page';
const files=readdirSync(DIR).filter(f=>/^0\d.*\.json$/.test(f)).sort();
const sections=files.map(f=>{const d=JSON.parse(readFileSync(resolve(DIR,f)));const s={id:d.desktop.id,name:d.name||d.desktop.name,desktop:d.desktop,mobile:d.mobile||d.desktop};if(d.script)s.script=d.script;return s});
// validações
const ids=[];const walk=(n,fn)=>{if(!n||typeof n!=='object')return;fn(n);(n.children||[]).forEach(c=>walk(c,fn))};
sections.forEach(s=>{walk(s.desktop,n=>n.id&&ids.push(n.id));walk(s.mobile,n=>n.id&&ids.push(n.id))});
const dup=ids.filter((x,i)=>ids.indexOf(x)!==i);
const all=JSON.stringify(sections);
const problems=[];
if(dup.length)problems.push('IDs duplicados: '+[...new Set(dup)].slice(0,5));
if(all.includes('"display":"grid"')||all.includes('"display": "grid"'))problems.push('display:grid presente');
if(all.includes('—'))problems.push('travessão — presente');
const bigBlur=[...all.matchAll(/"blur":\s*(\d+)/g)].filter(m=>+m[1]>10);if(bigBlur.length)problems.push(bigBlur.length+' shadows com blur>10');
if(problems.length){console.error('PROBLEMAS:\n'+problems.join('\n'));process.exit(1)}
const tpl={id:SLUG,name:NAME,description:DESC,category:'sales-page',slug:SLUG,thumbnail:SLUG,seo:{siteName:NAME,author:NAME,language:'pt-BR'},sections};
const OUT='/Users/eduardolecdt/Empresas/UnicPages/Repositórios/frontend/nuxt-app/components/pages/templates/models/sales-page/'+SLUG+'.json';
writeFileSync(OUT,JSON.stringify(tpl,null,1));
// patch index.js
const idxPath='/Users/eduardolecdt/Empresas/UnicPages/Repositórios/frontend/nuxt-app/components/pages/templates/models/index.js';
let idx=readFileSync(idxPath,'utf8');const varName='salesPage'+SLUG.split('-').map(w=>w[0].toUpperCase()+w.slice(1)).join('');
if(!idx.includes(varName)){idx=idx.replace(/(import bioBioAlex)/,`import ${varName} from './sales-page/${SLUG}.json'\n$1`);idx=idx.replace(/(, bioBioAlex)/,`, ${varName}$1`);writeFileSync(idxPath,idx)}
console.log('OK '+SLUG+' | '+sections.length+' seções | '+ids.length+' ids únicos | registrado no index.js');
```

## Regras que o validador cobre (não repita à mão): IDs únicos, sem grid, sem travessão, sem shadow-blur>10.
Ainda cheque manualmente só o que o script não pega: acentuação pt-BR e emojis reais.
