# Orquestração de Agentes

## Agentes Disponíveis

Localizados em `~/.claude/agents/`:

| Agente | Propósito | Quando Usar |
|--------|-----------|-------------|
| especialista-laravel | Especialista em backend Laravel 12 | Criar/modificar rotas, controllers, FormRequests, Actions/Services, models Eloquent, migrations, Policies, API Resources, Jobs e testes Pest |
| especialista-blade | Especialista em views Blade + Alpine.js | Criar layouts, componentes Blade (anônimos e de classe), páginas e formulários seguindo padrões de código limpo e organizado |
| especialista-js | JavaScript em projeto Laravel | Lógica Alpine.js (`Alpine.data`, stores) e ES modules em `resources/js` via Vite |
| especialista-css | Estilização com Tailwind + tokens | Todo CSS/estilização em `resources/css` e classes nas views |
| revisor-codigo | Revisão de qualidade de código | Após escrever ou modificar código - revisar qualidade, segurança e manutenibilidade (Pint + Larastan + leitura) |
| especialista-seguranca | Análise de segurança | Antes de commits, código que manipula entrada de usuário, autenticação, autorização (Policies) ou dados sensíveis |
| arquiteto-templates-unicpages | Arquiteto de Templates da UnicPages | Criar um template de LP completo e único a partir de um tema/nicho (pesquisa nicho, identidade, copy, personaliza seções-modelo, gera assets com gpt-image-1, monta desktop+mobile, salva no repo). Use para "cria template de LP para X" ou gerar templates em escala |
| criador-carrossel-social | Carrosséis (4:5) e stories (9:16) para Instagram dos produtos (EduSites, Gestão Dev, UnicPages) | Divulgar novidade/recurso (PIX, workshops, feature) nas redes mantendo a identidade REAL de cada produto (fonte/logo/cores/preço/linguagem extraídos do repo). Use para "cria carrossel do [produto]", "post/story avisando X", "carrossel de [tema]" |
| especialista-trafego | Gestor de tráfego pago (Meta Ads FB/IG) via MCP. Conhece a conta EduSites, pixel, públicos de ouro, histórico comprovado e regras 2026 | Subir/analisar/otimizar anúncios. Use para "sobe anúncio", "cria campanha no meta ads", "roda tráfego pro [produto]", "analisa minha conta de ads", "qual público usar". Sempre pausado pra revisar, nunca decide no achismo |

## Uso Imediato dos Agentes

Sem necessidade de prompt do usuário:
1. Alterações no backend Laravel (rotas, controllers, models, migrations) - Use o agente **especialista-laravel**
2. Criar/modificar views e componentes Blade - Use o agente **especialista-blade**
3. Código recém escrito/modificado - Use o agente **revisor-codigo**
4. Código de autenticação/autorização/segurança - Use o agente **especialista-seguranca**

## Execução Paralela de Tarefas

SEMPRE use execução paralela de Tasks para operações independentes:

```markdown
# BOM: Execução paralela
Lançar 3 agentes em paralelo:
1. Agente 1: Análise de segurança do AuthController + Policies
2. Agente 2: Revisão de código do OrderController + StoreOrderRequest
3. Agente 3: Verificação dos componentes Blade em resources/views/components

# RUIM: Sequencial quando desnecessário
Primeiro agente 1, depois agente 2, depois agente 3
```

## Análise Multi-Perspectiva

Para problemas complexos, use sub-agentes com papéis distintos:
- revisor-codigo: Qualidade e manutenibilidade
- especialista-seguranca: Vulnerabilidades e boas práticas de segurança
- especialista-laravel: Padrões de backend, Eloquent e arquitetura em camadas
- especialista-blade: Padrões de frontend Blade/Alpine e componentização
