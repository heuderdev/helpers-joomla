<?php

/*
 * Vigia.js + ValidationHelper + ApiResponseHelper: as regras ficam num
 * lugar só (o PHP), o navegador valida enquanto a pessoa digita e o
 * servidor valida de novo no envio. Os erros do servidor aparecem
 * sozinhos no campo certo.
 *
 * Arquivos:
 *   components/com_loja/helpers/  ValidationHelper.php, ApiResponseHelper.php, InputHelper.php, OrmTables.php
 *   media/com_loja/js/            vigia.js, vigia.css (copiados de js/ do repositório)
 */

defined('_JEXEC') or die;

require_once JPATH_COMPONENT . '/helpers/IncludeHelper.php';
IncludeHelper::registerAutoloader();

// ---------------------------------------------------------------------
// 1. Regras num lugar só (usadas pelo controller e pela view)
// ---------------------------------------------------------------------

class ClienteRegras
{
    public static function regras()
    {
        return array(
            'nome' => 'required|min_length:3|max_length:80',
            'email' => array(
                'required',
                'email',
                // Só o servidor confere: clientConfig() deixa esta de fora.
                'unique' => function ($valor) {
                    return !OrmTables::table('#__loja_clientes')->where('email', $valor)->exists();
                }
            ),
            'tipo' => 'required|in:pf,pj',
            'documento' => 'required|cpf_cnpj',
            'nascimento' => 'required_if:tipo,pf|date_br|before:today',
            'celular' => 'required|phone',
            'itens.*.produto' => 'required|min_length:2',
            'itens.*.quantidade' => 'required|integer|min:1'
        );
    }

    public static function mensagens()
    {
        return array(
            'email.unique' => 'Já existe um cliente com este e-mail.'
        );
    }

    public static function rotulos()
    {
        return array(
            'documento' => 'CPF ou CNPJ',
            'itens.*.produto' => 'Produto',
            'itens.*.quantidade' => 'Quantidade'
        );
    }
}

// ---------------------------------------------------------------------
// 2. Controller: responde JSON com o ApiResponseHelper
// ---------------------------------------------------------------------

class LojaControllerCliente extends JControllerLegacy
{
    public function salvar()
    {
        try {
            // O Vigia envia o token no corpo (Joomla 3) e no cabeçalho X-CSRF-Token (Joomla 4/5).
            if (!JSession::checkToken()) {
                return ApiResponseHelper::forbidden('Sua sessão expirou. Recarregue a página.');
            }

            $dados = InputHelper::post(array(
                'nome' => 'string',
                'email' => 'string',
                'tipo' => 'cmd',
                'documento' => 'string',
                'nascimento' => 'string',
                'celular' => 'string',
                'itens' => 'array'
            ));

            $validacao = ValidationHelper::validate(
                $dados,
                ClienteRegras::regras(),
                ClienteRegras::mensagens(),
                ClienteRegras::rotulos()
            );

            // HTTP 422 com { errors: { 'email': [...], 'itens.1.quantidade': [...] } }:
            // o Vigia mostra cada mensagem embaixo do campo correspondente.
            if (!$validacao['valid']) {
                return ApiResponseHelper::fromValidation($validacao);
            }

            $cliente = OrmTables::table('#__loja_clientes', array(
                'fillable' => array('nome', 'email', 'tipo', 'documento', 'celular')
            ))->create($dados);

            // 'redirect' em data faz o Vigia navegar depois do sucesso (opcional).
            return ApiResponseHelper::created('Cliente cadastrado com sucesso.', array(
                'id' => (int) $cliente->id
            ));
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }

    /*
     * Regra remote do Vigia: data-rules="remote:index.php?option=com_loja&task=cliente.emailLivre&format=json"
     * Recebe 'field' e 'value'; responde sucesso (livre) ou 422 com a mensagem.
     */
    public function emailLivre()
    {
        try {
            if (!JSession::checkToken()) {
                return ApiResponseHelper::forbidden('Sua sessão expirou. Recarregue a página.');
            }

            $email = InputHelper::string('value', '', 'post');
            $usado = OrmTables::table('#__loja_clientes')->where('email', $email)->exists();

            return $usado
                ? ApiResponseHelper::validationError(array('email' => array('Já existe um cliente com este e-mail.')))
                : ApiResponseHelper::success('Disponível.');
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }
}

// ---------------------------------------------------------------------
// 3. View (tmpl/default.php): as mesmas regras no navegador
// ---------------------------------------------------------------------
?>
<?php
JHtml::_('script', 'https://cdnjs.cloudflare.com/ajax/libs/axios/1.7.7/axios.min.js');
JHtml::_('script', 'com_loja/vigia.js', array('relative' => true, 'version' => 'auto'));
JHtml::_('stylesheet', 'com_loja/vigia.css', array('relative' => true, 'version' => 'auto'));

$vigia = ValidationHelper::clientConfig(
    ClienteRegras::regras(),
    ClienteRegras::mensagens(),
    ClienteRegras::rotulos()
);
?>
<form data-vigia='<?php echo htmlspecialchars(json_encode($vigia), ENT_QUOTES, 'UTF-8'); ?>'
      action="<?php echo JRoute::_('index.php?option=com_loja&task=cliente.salvar&format=json'); ?>"
      method="post">

    <div data-vigia-message></div>

    <label for="nome">Nome completo</label>
    <input id="nome" name="nome" class="form-control">

    <label for="email">E-mail</label>
    <input id="email" name="email" type="email" class="form-control"
           data-rules="remote:<?php echo JRoute::_('index.php?option=com_loja&task=cliente.emailLivre&format=json', false); ?>">

    <label for="tipo">Tipo</label>
    <select id="tipo" name="tipo" class="form-select">
        <option value="">Selecione…</option>
        <option value="pf">Pessoa física</option>
        <option value="pj">Pessoa jurídica</option>
    </select>

    <label for="documento">CPF ou CNPJ</label>
    <input id="documento" name="documento" class="form-control" data-mask="cpf_cnpj">

    <label for="nascimento">Nascimento</label>
    <input id="nascimento" name="nascimento" class="form-control" data-mask="date">

    <label for="celular">Celular</label>
    <input id="celular" name="celular" class="form-control" data-mask="phone">

    <?php foreach (array(0, 1) as $i) : ?>
        <input name="itens[<?php echo $i; ?>][produto]" class="form-control" placeholder="Produto">
        <input name="itens[<?php echo $i; ?>][quantidade]" class="form-control" data-mask="integer">
    <?php endforeach; ?>

    <?php echo JHtml::_('form.token'); ?>
    <button type="submit" class="btn btn-primary" data-loading-text="Salvando…">Salvar</button>
</form>
