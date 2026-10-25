# Diretrizes de Segurança (Laravel)

## Verificações de Segurança Obrigatórias

Antes de QUALQUER commit:
- [ ] Sem segredos hardcoded (chaves de API, senhas, tokens) — tudo em `.env`, lido via `config/*.php`
- [ ] `.env` fora do git e `APP_DEBUG=false` em produção
- [ ] Todas as entradas de usuário validadas por **FormRequest** (`rules()` + `authorize()`)
- [ ] Prevenção de injeção SQL: Eloquent/Query Builder com bindings; nada de variável concatenada em `DB::raw`, `whereRaw`, `orderByRaw`
- [ ] Prevenção de XSS: `{{ }}` sempre; `{!! !!}` só com HTML sanitizado e justificado
- [ ] Proteção CSRF: `@csrf` em todo form, header `X-CSRF-TOKEN`/`X-XSRF-TOKEN` em fetch/axios
- [ ] Mass assignment: `$fillable` explícito; `$request->validated()` em vez de `$request->all()`
- [ ] Autenticação/autorização: middleware `auth`/`auth:sanctum` + Policies (`$this->authorize()` / `can:`) contra IDOR
- [ ] Rate limiting em todos os endpoints (`RateLimiter::for` + middleware `throttle`)
- [ ] Uploads validados (`mimes`, `max`) e salvos via `Storage`, nunca em `public/` com o nome original
- [ ] Mensagens de erro não vazam dados sensíveis (exceptions tratadas em `bootstrap/app.php`)
- [ ] `composer audit` sem vulnerabilidades altas/críticas

## Gerenciamento de Segredos

```php
// NUNCA: Segredos hardcoded
$apiKey = 'sk-proj-xxxxx';

// NUNCA: env() fora de config/ (retorna null com config:cache)
$apiKey = env('OPENAI_API_KEY');

// SEMPRE: config/services.php
'openai' => [
    'key' => env('OPENAI_API_KEY'),
],

// ...e no código
$apiKey = config('services.openai.key')
    ?? throw new RuntimeException('OPENAI_API_KEY não configurada');
```

## Protocolo de Resposta a Segurança

Se encontrar problema de segurança:
1. PARE imediatamente
2. Use o agente **especialista-seguranca**
3. Corrija problemas CRÍTICOS antes de continuar
4. Rotacione quaisquer segredos expostos (inclusive `APP_KEY`, se vazou)
5. Revise toda a base de código para problemas similares
