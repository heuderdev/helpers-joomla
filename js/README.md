# Vigia.js

Micro framework JavaScript para formulários de componentes Joomla, par do `ValidationHelper` e do `ApiResponseHelper`.

- Valida no navegador com as **mesmas regras e mensagens** do `ValidationHelper` (`data-rules="required|cpf"`).
- Mostra os erros 422 do `ApiResponseHelper::fromValidation()` embaixo de cada campo (`itens.1.qtd` → `name="itens[1][qtd]"`).
- Máscaras: CPF, CNPJ, CPF/CNPJ, telefone, CEP, data, hora, dinheiro, decimal, placa, cartão, PIS e padrões próprios.
- Envia com axios, com o token CSRF do Joomla 3, 4 e 5.
- Alpine.js opcional: `x-data="vigia(...)"` e `x-mask`.

| Arquivo | O que é |
|---|---|
| `vigia.js` | A biblioteca (um arquivo, sem build). Requer o axios carregado antes. |
| `vigia.css` | Estilos mínimos. Opcional com Bootstrap (Joomla 4/5). |
| `vitrine.js` | Telas de listagem: paginação, ordenação e filtros na URL, busca com espera, ações em lote. Requer o Vigia.js. Docs: `docs/vitrine-js.html`. |
| `vitrine.css` | Estilos mínimos da Vitrine.js (setas de ordenação, carregando, seleção). |
| `exemplo-vitrine.html` | Demonstração completa Vitrine.js + Vigia.js (cadastro de clientes) que roda sem Joomla. O PHP correspondente: `exemplos/ComoUsarVitrineEVigia.php`. |
| `exemplo-duas-vitrines.html` | Duas vitrines na mesma página que conversam (clientes filtra pedidos; pagar um pedido atualiza os clientes), com o prefixo automático na URL. Roda sem Joomla. |
| `exemplo.html` | Demonstração que roda sem Joomla (servidor falso no formato do `ApiResponseHelper`). |

```html
<script src="https://cdnjs.cloudflare.com/ajax/libs/axios/1.7.7/axios.min.js"></script>
<script src="/media/com_loja/js/vigia.js"></script>

<form data-vigia action="index.php?option=com_loja&task=cliente.salvar&format=json" method="post">
    <div data-vigia-message></div>
    <input name="cpf" data-mask="cpf" data-rules="required|cpf">
    <?php echo JHtml::_('form.token'); ?>
    <button>Salvar</button>
</form>
```

Documentação completa: `docs/vigia-js.html`. Exemplo de controller e view: `exemplos/ComoUsarVigia.php`.
