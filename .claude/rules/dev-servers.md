# Servidores de Desenvolvimento — Portas Fixas

## Regra de ouro

**Na maioria das vezes o Eduardo JÁ ESTÁ RODANDO os serviços.** Antes de subir qualquer app Laravel (`php artisan serve`) ou o Vite:

1. **Cheque se já está no ar** na porta pré-definida do projeto (tabela abaixo).
2. **Se estiver no ar e for o serviço correto → USE.** Não suba outra instância, não mate o processo, não troque a porta.
3. **Só suba se a porta estiver livre.**

```bash
# Checagem obrigatória antes de subir qualquer coisa
lsof -iTCP:<PORTA> -sTCP:LISTEN -P -n

# Confirmar que é o serviço certo (apps respondem na raiz; APIs em /up — health check nativo do Laravel 11+)
curl -s -m 3 http://localhost:<PORTA>/up | head -c 120
```

**NUNCA** rode `pkill -f "artisan serve"`, `pkill -f "php -S"`, `pkill -f vite` ou equivalente sem o Eduardo pedir — isso derruba o ambiente dele no meio do trabalho. Se o projeto usa `composer run dev` (serve + queue + logs + vite via `concurrently`), ele já está cuidando de tudo; não suba peças avulsas por cima.

## Portas por projeto (fronts N000-N009 · backs N010+)

Num monólito Laravel com Blade, o "front" e o "back" são o mesmo `artisan serve`: use a porta da coluna **Fronts**. Apps que expõem só API (Sanctum) usam a coluna **Backs**. O **Vite** de cada app roda em `porta + 100` (ex: app 7200 → Vite 7300) para não colidir.

| Projeto | Fronts | Backs |
|---|---|---|
| **Edu Sites** 65xx | web 6510, admin 6511, app 6512 | api-upload 6513, api-admin 6514, api-app 6515 |
| **PagZero** 70xx | web 7000, admin 7001, app 7002, checkout 7003, area 7004 | api-app 7010, api-admin 7011, api-checkout 7012, api-upload 7013, api-webhooks 7014, api-area 7015 |
| **UnicPages** 72xx | web 7200, admin 7201, app 7202 | api-app 7210, api-admin 7211, api-conversor 7212, api-sites 7213, api-upload 7214, api-web 7215, api-worker 7216, mcp 7217 |
| **Gestão Dev** 74xx | web 7400, admin 7401, app 7402, checkout 7403, contrato 7404, proposta 7405 | api-admin 7410, api-app 7411, api-checkout 7412, api-contrato 7413, api-proposta 7414, api-upload 7415, api-web 7416 |
| **Oligo Analytics** 76xx | web 7600, admin 7601, app 7602 | api-upload 7610, api-admin 7611, api-app 7612, api-jobs 7613 |
| **CarbonoPay** 78xx | admin 7800, app 7801 | api-upload 7810, api-admin 7811, api-app 7812 |
| **Kaze Import** 80xx | admin 8000, web 8001 | api-upload 8010, api-admin 8011, api-web 8012 |
| **Home Lucrativo** 82xx | admin 8200, forms 8201, supervisor 8202, vendedor 8203 | api-upload 8210, api-admin 8211, api-forms 8212, api-supervisor 8213, api-vendedor 8214 |
| **Roas Transfer** 84xx | admin 8400, web 8401 | api-upload 8410, api-admin 8411, api-web 8412 |
| **Dominnus** 86xx | admin 8600, app 8601 | api-upload 8610, api-admin 8611, api-app 8612 |

A porta **8000** (default do `artisan serve`) e a **5173** (default do Vite) ficam **LIVRES de propósito**. Se um app subir na 8000/8001… ou o Vite na 5173, a porta configurada estava ocupada ou não foi passada — investigue em vez de aceitar. (Exceção: a tabela coloca o Kaze Import em 8000/8001 — nesse projeto passe a porta sempre explicitamente e confirme pela raiz qual app respondeu.)

Workers não têm porta, mas também não devem ser duplicados: antes de rodar `php artisan queue:work`/`horizon`, cheque com `pgrep -af "queue:work|horizon"`.

## Onde os projetos moram

- **Produtos próprios** (5): `/Users/eduardolecdt/Empresas/<Empresa>/Repositórios/{frontend,backend,devops}/<servico>` — **um repo git por serviço**, não é monorepo. Pastas: `UnicPages`, `PagZero`, `Gestão Dev`, `Oligo Analytics`, `Edu Sites` (com espaço).
- **Projetos de cliente**: `/Users/eduardolecdt/Empresas/Team Lecdt/Repositórios/<Cliente>/<Projeto>/{frontend,backend,devops}` — esses são monorepos (ex: `Pedro Tescaro/Kaze Import`, `Lucas Roas/Roas Transfer`, `Yuri Santos/CarbonoPay`, `Yuri Santos/Dominnus`, `Erick Bocardi/Home Lucrativo`).
- **Apps Laravel novos** seguem a mesma árvore; o monólito Blade fica em `frontend/<servico>` quando serve páginas e em `backend/<servico>` quando é só API.

## Onde cada porta é definida

- **App Laravel**: `.env` → `APP_URL=http://localhost:NNNN` e `APP_PORT=NNNN`; `composer.json` → script `dev` com `php artisan serve --port=NNNN`. Só mexa nessas linhas — o resto do `.env` tem secrets reais de produção.
- **Vite**: `vite.config.js` → `server: { port: NNNN + 100, strictPort: true }` (o `strictPort` faz o Vite falhar em vez de pular pra outra porta).
- **Sail/Docker**: `APP_PORT` e `VITE_PORT` no `.env`, lidos pelo `docker-compose.yml`.
