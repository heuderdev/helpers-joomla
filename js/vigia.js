/*!
 * Vigia.js 1.0.0
 * Validação, máscaras e requisições para componentes Joomla.
 * Fala a mesma língua do ValidationHelper e do ApiResponseHelper
 * (https://github.com/heuderdev/helpers-joomla).
 *
 * Requer o axios para as requisições. O Alpine.js é opcional.
 * Licença: a mesma do repositório.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(root);
    } else {
        root.Vigia = factory(root);
    }
}(typeof window !== 'undefined' ? window : this, function (window) {
    'use strict';

    var document = window.document;
    var VERSION = '1.0.0';

    // ------------------------------------------------------------------
    // Configuração
    // ------------------------------------------------------------------

    var config = {
        // Token CSRF do Joomla. Vazio = descobre sozinho (Joomla.getOptions,
        // campo oculto do JHtml::_('form.token') ou <meta name="csrf-token">).
        token: '',
        timeout: 30000,
        // 'blur': valida ao sair do campo e, depois de um erro, a cada tecla.
        // 'input': a cada tecla. 'submit': só ao enviar.
        validateOn: 'blur',
        focusInvalid: true,
        // Mostra só a primeira mensagem de cada campo (como first_error).
        firstErrorOnly: true,
        // Onde mostrar a mensagem geral quando o formulário não tem
        // [data-vigia-message]: 'auto' usa Joomla.renderMessages se existir.
        notify: 'auto',
        autoInit: true,
        classes: {
            invalid: 'is-invalid invalid vigia-invalid',
            valid: '',
            feedback: 'invalid-feedback vigia-feedback',
            loading: 'vigia-loading',
            alert: {
                success: 'alert alert-success vigia-alert',
                error: 'alert alert-danger alert-error vigia-alert',
                warning: 'alert alert-warning vigia-alert',
                info: 'alert alert-info vigia-alert'
            }
        },
        messages: {},
        httpMessages: {}
    };

    // Mensagens idênticas às do ValidationHelper (mesmos placeholders).
    var MESSAGES = {
        required: 'O campo :field é obrigatório.',
        string: 'O campo :field deve ser um texto válido.',
        integer: 'O campo :field deve ser um número inteiro válido.',
        numeric: 'O campo :field deve ser numérico.',
        decimal: 'O campo :field deve ser um valor decimal válido.',
        boolean: 'O campo :field deve ser verdadeiro ou falso.',
        email: 'O campo :field deve conter um e-mail válido.',
        url: 'O campo :field deve conter uma URL válida.',
        cpf: 'O campo :field deve conter um CPF válido.',
        cnpj: 'O campo :field deve conter um CNPJ válido.',
        cpf_cnpj: 'O campo :field deve conter um CPF ou CNPJ válido.',
        phone: 'O campo :field deve conter um telefone válido.',
        cep: 'O campo :field deve conter um CEP válido.',
        date: 'O campo :field deve conter uma data válida.',
        date_br: 'O campo :field deve conter uma data válida no formato DD/MM/AAAA.',
        datetime: 'O campo :field deve conter uma data e hora válidas.',
        time: 'O campo :field deve conter um horário válido.',
        array: 'O campo :field deve ser uma lista válida.',
        json: 'O campo :field deve conter um JSON válido.',
        min: 'O campo :field deve ser maior ou igual a :value.',
        max: 'O campo :field deve ser menor ou igual a :value.',
        between: 'O campo :field deve estar entre :min e :max.',
        min_length: 'O campo :field deve ter pelo menos :value caracteres.',
        max_length: 'O campo :field deve ter no máximo :value caracteres.',
        length: 'O campo :field deve ter exatamente :value caracteres.',
        in: 'O valor informado no campo :field não é permitido.',
        not_in: 'O valor informado no campo :field não é permitido.',
        regex: 'O formato informado no campo :field é inválido.',
        same: 'O campo :field deve ser igual ao campo :other.',
        different: 'O campo :field deve ser diferente do campo :other.',
        required_if: 'O campo :field é obrigatório nesta situação.',
        required_with: 'O campo :field é obrigatório quando :other for informado.',
        unique: 'O valor informado no campo :field já está em uso.',
        exists: 'O valor informado no campo :field não foi encontrado.',
        file: 'O campo :field deve conter um arquivo válido.',
        file_size: 'O arquivo do campo :field excede o tamanho permitido.',
        file_extension: 'A extensão do arquivo informado no campo :field não é permitida.',
        file_mime: 'O tipo do arquivo informado no campo :field não é permitido.',
        image: 'O campo :field deve conter uma imagem válida.',
        image_dimensions: 'A imagem do campo :field possui dimensões inválidas.',
        accepted: 'O campo :field deve ser aceito.',
        alpha: 'O campo :field deve conter apenas letras.',
        alpha_num: 'O campo :field deve conter apenas letras e números.',
        alpha_dash: 'O campo :field deve conter apenas letras, números, hífen e underline.',
        uuid: 'O campo :field deve conter um UUID válido.',
        ip: 'O campo :field deve conter um endereço IP válido.',
        after: 'O campo :field deve ser uma data posterior a :date.',
        after_or_equal: 'O campo :field deve ser uma data igual ou posterior a :date.',
        before: 'O campo :field deve ser uma data anterior a :date.',
        before_or_equal: 'O campo :field deve ser uma data igual ou anterior a :date.',
        callback: 'O campo :field é inválido.',

        // Regras só do navegador (o ValidationHelper as ignora).
        digits: 'O campo :field deve ter exatamente :value dígitos.',
        digits_between: 'O campo :field deve ter entre :min e :max dígitos.',
        starts_with: 'O campo :field deve começar com: :values.',
        ends_with: 'O campo :field deve terminar com: :values.',
        confirmed: 'A confirmação do campo :field não confere.',
        password: 'O campo :field deve ter pelo menos :value caracteres, com letra maiúscula, minúscula, número e símbolo.',
        credit_card: 'O campo :field deve conter um número de cartão válido.',
        placa: 'O campo :field deve conter uma placa de veículo válida.',
        pis: 'O campo :field deve conter um PIS/PASEP/NIS válido.',
        cnh: 'O campo :field deve conter uma CNH válida.',
        remote: 'O valor informado no campo :field é inválido.'
    };

    var HTTP_MESSAGES = {
        network: 'Não foi possível conectar ao servidor. Verifique sua conexão.',
        timeout: 'O servidor demorou para responder. Tente novamente.',
        unexpected: 'O servidor enviou uma resposta inesperada.',
        400: 'Requisição inválida.',
        401: 'Sua sessão expirou. Entre novamente.',
        403: 'Você não possui permissão para esta operação.',
        404: 'Registro não encontrado.',
        405: 'Método de requisição não permitido.',
        409: 'A operação não pode ser concluída devido a um conflito de dados.',
        413: 'O envio é maior do que o servidor aceita.',
        422: 'Corrija os campos informados.',
        429: 'Muitas tentativas. Aguarde alguns instantes e tente novamente.',
        500: 'Ocorreu um erro interno ao processar a solicitação.',
        503: 'Serviço temporariamente indisponível.'
    };

    // Regras avaliadas mesmo com o campo vazio (as outras só valem com valor).
    var PRESENCE_RULES = ['required', 'required_if', 'required_with', 'accepted'];

    // Regras que exigem um valor simples: uma lista nelas é inválida.
    var SCALAR_RULES = [
        'integer', 'int', 'numeric', 'decimal', 'boolean', 'bool', 'email', 'url',
        'cpf', 'cnpj', 'cpf_cnpj', 'phone', 'telefone', 'cep', 'date', 'date_br',
        'datetime', 'time', 'min_length', 'max_length', 'length', 'in', 'not_in',
        'regex', 'same', 'different', 'accepted', 'alpha', 'alpha_num', 'alpha_dash',
        'uuid', 'ip', 'after', 'after_or_equal', 'before', 'before_or_equal',
        'digits', 'digits_between', 'starts_with', 'ends_with', 'credit_card',
        'placa', 'pis', 'cnh', 'password'
    ];

    // Só o servidor sabe conferir: no navegador passam sempre.
    var SERVER_ONLY_RULES = ['unique', 'exists', 'callback'];

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    // Só objetos literais ({}): FormData, File, Date etc. não contam.
    function isObject(value) {
        return Object.prototype.toString.call(value) === '[object Object]';
    }

    function isFile(value) {
        return (typeof window.File !== 'undefined' && value instanceof window.File)
            || (typeof window.Blob !== 'undefined' && value instanceof window.Blob);
    }

    function extend(target) {
        for (var i = 1; i < arguments.length; i++) {
            var source = arguments[i];

            if (!source) {
                continue;
            }

            Object.keys(source).forEach(function (key) {
                target[key] = source[key];
            });
        }

        return target;
    }

    function deepExtend(target, source) {
        Object.keys(source || {}).forEach(function (key) {
            if (isObject(source[key]) && isObject(target[key])) {
                deepExtend(target[key], source[key]);
            } else {
                target[key] = source[key];
            }
        });

        return target;
    }

    function own(object, key) {
        return object !== null && object !== undefined && Object.prototype.hasOwnProperty.call(object, key);
    }

    function str(value) {
        return value === null || value === undefined ? '' : String(value);
    }

    // Comprimento em caracteres (como mb_strlen): emojis e acentos contam 1.
    function strlen(value) {
        return Array.from(str(value)).length;
    }

    function onlyDigits(value) {
        return str(value).replace(/\D+/g, '');
    }

    function escapeRegExp(text) {
        return String(text).replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&');
    }

    // ucfirst do PHP: só letras ASCII mudam (como no servidor).
    function ucfirst(text) {
        return text.replace(/^[a-z]/, function (c) {
            return c.toUpperCase();
        });
    }

    function classList(names) {
        return str(names).split(/\s+/).filter(Boolean);
    }

    function addClasses(el, names) {
        classList(names).forEach(function (name) {
            el.classList.add(name);
        });
    }

    function removeClasses(el, names) {
        classList(names).forEach(function (name) {
            el.classList.remove(name);
        });
    }

    function parseJsonAttribute(el, name) {
        var raw = el.getAttribute(name);

        if (!raw || !raw.trim()) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (e) {
            warn('JSON inválido em ' + name + ':', raw);
            return null;
        }
    }

    function warn() {
        if (window.console && window.console.warn) {
            window.console.warn.apply(window.console, ['[Vigia]'].concat([].slice.call(arguments)));
        }
    }

    function emit(el, name, detail, cancelable) {
        var event;

        if (typeof window.CustomEvent === 'function') {
            event = new window.CustomEvent(name, { bubbles: true, cancelable: !!cancelable, detail: detail });
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(name, true, !!cancelable, detail);
        }

        return el.dispatchEvent(event);
    }

    // 'itens[0][nome]' -> 'itens.0.nome'; 'tags[]' -> 'tags'
    function nameToKey(name) {
        return str(name)
            .replace(/\[\]$/, '')
            .replace(/\]\[/g, '.')
            .replace(/\[/g, '.')
            .replace(/\]/g, '');
    }

    // 'itens.0.nome' -> 'itens[0][nome]'
    function keyToName(key) {
        var parts = str(key).split('.');

        return parts[0] + parts.slice(1).map(function (part) {
            return '[' + part + ']';
        }).join('');
    }

    // 'itens.2.quantidade' -> 'itens.*.quantidade'
    function wildcardPattern(field) {
        return str(field).split('.').map(function (part) {
            return /^\d+$/.test(part) ? '*' : part;
        }).join('.');
    }

    function getNested(data, field) {
        if (own(data, field)) {
            return data[field];
        }

        var value = data;
        var keys = str(field).split('.');

        for (var i = 0; i < keys.length; i++) {
            if (value === null || typeof value !== 'object' || isFile(value) || !own(value, keys[i])) {
                return null;
            }

            value = value[keys[i]];
        }

        return value === undefined ? null : value;
    }

    function valueExists(value) {
        if (value === null || value === undefined) {
            return false;
        }

        if (typeof value === 'string') {
            return value.trim() !== '';
        }

        if (Array.isArray(value)) {
            return value.length > 0;
        }

        if (isObject(value)) {
            return Object.keys(value).length > 0;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Números e datas (mesmas regras do ValidationHelper)
    // ------------------------------------------------------------------

    /*
     * Número digitado por pessoas: "10,5", "1.234,56", "1,234.56",
     * "R$ 99,90". Com um único separador, ele é o decimal. Devolve null
     * se inválido.
     */
    function toNumber(value) {
        if (typeof value === 'number') {
            return isFinite(value) ? value : null;
        }

        if (typeof value !== 'string') {
            return null;
        }

        value = value.replace(/R\$|US\$|\$|€|\s| /g, '');

        if (value === '') {
            return null;
        }

        if (/^[+-]?(\d+([.,]\d+)?|[.,]\d+)$/.test(value)) {
            return parseFloat(value.replace(',', '.'));
        }

        if (/^[+-]?\d{1,3}(\.\d{3})+(,\d+)?$/.test(value)) {
            return parseFloat(value.replace(/\./g, '').replace(',', '.'));
        }

        if (/^[+-]?\d{1,3}(,\d{3})+(\.\d+)?$/.test(value)) {
            return parseFloat(value.replace(/,/g, ''));
        }

        if (isNumeric(value)) {
            return parseFloat(value);
        }

        return null;
    }

    // is_numeric() do PHP.
    function isNumeric(value) {
        if (typeof value === 'number') {
            return isFinite(value);
        }

        return typeof value === 'string'
            && /^\s*[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?\s*$/.test(value);
    }

    function pad(number, size) {
        var text = String(number);

        while (text.length < size) {
            text = '0' + text;
        }

        return text;
    }

    var DATE_TOKENS = {
        d: { re: '(\\d{2})', part: 'd' },
        j: { re: '(\\d{1,2})', part: 'd' },
        m: { re: '(\\d{2})', part: 'm' },
        n: { re: '(\\d{1,2})', part: 'm' },
        Y: { re: '(\\d{4})', part: 'Y' },
        y: { re: '(\\d{2})', part: 'y' },
        H: { re: '(\\d{2})', part: 'H' },
        G: { re: '(\\d{1,2})', part: 'H' },
        i: { re: '(\\d{2})', part: 'i' },
        s: { re: '(\\d{2})', part: 's' }
    };

    function formatDate(date, format) {
        var out = '';

        for (var i = 0; i < format.length; i++) {
            var c = format[i];

            if (c === '\\') {
                i++;
                out += format[i] || '';
                continue;
            }

            switch (c) {
                case 'd': out += pad(date.getDate(), 2); break;
                case 'j': out += date.getDate(); break;
                case 'm': out += pad(date.getMonth() + 1, 2); break;
                case 'n': out += date.getMonth() + 1; break;
                case 'Y': out += pad(date.getFullYear(), 4); break;
                case 'y': out += pad(date.getFullYear() % 100, 2); break;
                case 'H': out += pad(date.getHours(), 2); break;
                case 'G': out += date.getHours(); break;
                case 'i': out += pad(date.getMinutes(), 2); break;
                case 's': out += pad(date.getSeconds(), 2); break;
                default: out += c;
            }
        }

        return out;
    }

    /*
     * DateTime::createFromFormat estrito, como o isDate() do PHP: data
     * impossível (31/02) é inválida e o texto precisa voltar idêntico ao
     * ser formatado. Formatos: d j m n Y y H G i s e \ para escapar.
     */
    function parseDate(value, format) {
        if (typeof value !== 'string' || value.trim() === '') {
            return null;
        }

        var re = '';
        var parts = [];

        for (var i = 0; i < format.length; i++) {
            var c = format[i];

            if (c === '\\') {
                i++;
                re += escapeRegExp(format[i] || '');
            } else if (DATE_TOKENS[c]) {
                re += DATE_TOKENS[c].re;
                parts.push(DATE_TOKENS[c].part);
            } else {
                re += escapeRegExp(c);
            }
        }

        var match = new RegExp('^' + re + '$').exec(value);

        if (!match) {
            return null;
        }

        var p = { Y: 1970, m: 1, d: 1, H: 0, i: 0, s: 0 };

        parts.forEach(function (part, index) {
            var number = parseInt(match[index + 1], 10);

            if (part === 'y') {
                p.Y = number < 70 ? 2000 + number : 1900 + number;
            } else {
                p[part] = number;
            }
        });

        if (p.m < 1 || p.m > 12 || p.d < 1 || p.H > 23 || p.i > 59 || p.s > 59) {
            return null;
        }

        var date = new Date(p.Y, p.m - 1, p.d, p.H, p.i, p.s);

        if (date.getFullYear() !== p.Y || date.getMonth() !== p.m - 1 || date.getDate() !== p.d) {
            return null;
        }

        return formatDate(date, format) === value ? date : null;
    }

    /*
     * Data para timestamp (ms), para after/before. Datas brasileiras são
     * lidas como DD/MM/AAAA; aceita também today, now, tomorrow, yesterday.
     */
    function toTimestamp(value) {
        if (value instanceof Date) {
            return value.getTime();
        }

        if (typeof value !== 'string' || value.trim() === '') {
            return null;
        }

        value = value.trim();

        var today = new Date();
        today.setHours(0, 0, 0, 0);

        var keywords = {
            now: Date.now(),
            today: today.getTime(),
            tomorrow: today.getTime() + 86400000,
            yesterday: today.getTime() - 86400000
        };

        if (own(keywords, value.toLowerCase())) {
            return keywords[value.toLowerCase()];
        }

        var formats = /^\d{2}\/\d{2}\/\d{4}/.test(value)
            ? ['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y']
            : ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\\TH:i:s', 'Y-m-d\\TH:i', 'Y-m-d'];

        for (var i = 0; i < formats.length; i++) {
            var date = parseDate(value, formats[i]);

            if (date) {
                return date.getTime();
            }
        }

        if (/^\d{2}\/\d{2}\/\d{4}/.test(value)) {
            return null;
        }

        var parsed = Date.parse(value);

        return isNaN(parsed) ? null : parsed;
    }

    // ------------------------------------------------------------------
    // Documentos brasileiros
    // ------------------------------------------------------------------

    function validateCpf(value) {
        var cpf = onlyDigits(value);

        if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
            return false;
        }

        for (var position = 9; position < 11; position++) {
            var sum = 0;

            for (var index = 0; index < position; index++) {
                sum += parseInt(cpf[index], 10) * ((position + 1) - index);
            }

            if (parseInt(cpf[position], 10) !== ((sum * 10) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    function validateCnpj(value) {
        var cnpj = onlyDigits(value);

        if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) {
            return false;
        }

        var digit = function (length) {
            var weights = length === 12
                ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
                : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            var sum = 0;

            for (var i = 0; i < length; i++) {
                sum += parseInt(cnpj[i], 10) * weights[i];
            }

            var remainder = sum % 11;

            return remainder < 2 ? 0 : 11 - remainder;
        };

        return parseInt(cnpj[12], 10) === digit(12) && parseInt(cnpj[13], 10) === digit(13);
    }

    function validatePis(value) {
        var pis = onlyDigits(value);

        if (pis.length !== 11 || /^(\d)\1{10}$/.test(pis)) {
            return false;
        }

        var weights = [3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        var sum = 0;

        for (var i = 0; i < 10; i++) {
            sum += parseInt(pis[i], 10) * weights[i];
        }

        var check = 11 - (sum % 11);

        return parseInt(pis[10], 10) === (check >= 10 ? 0 : check);
    }

    function validateCnh(value) {
        var cnh = onlyDigits(value);

        if (cnh.length !== 11 || /^(\d)\1{10}$/.test(cnh)) {
            return false;
        }

        var sum = 0;
        var i;

        for (i = 0; i < 9; i++) {
            sum += parseInt(cnh[i], 10) * (9 - i);
        }

        var first = sum % 11;
        var discount = 0;

        if (first >= 10) {
            first = 0;
            discount = 2;
        }

        sum = 0;

        for (i = 0; i < 9; i++) {
            sum += parseInt(cnh[i], 10) * (1 + i);
        }

        var second = sum % 11;
        second = second >= 10 ? 0 : second - discount;

        if (second < 0) {
            second += 11;
        }

        if (second >= 10) {
            second = 0;
        }

        return parseInt(cnh[9], 10) === first && parseInt(cnh[10], 10) === second;
    }

    function validateLuhn(value) {
        var number = onlyDigits(value);

        if (number.length < 13 || number.length > 19 || /[^\d\s.-]/.test(str(value))) {
            return false;
        }

        var sum = 0;
        var double = false;

        for (var i = number.length - 1; i >= 0; i--) {
            var digit = parseInt(number[i], 10);

            if (double) {
                digit *= 2;

                if (digit > 9) {
                    digit -= 9;
                }
            }

            sum += digit;
            double = !double;
        }

        return sum % 10 === 0;
    }

    // ------------------------------------------------------------------
    // Regras
    // ------------------------------------------------------------------

    function measure(value) {
        if (Array.isArray(value)) {
            return value.length;
        }

        // Lista indexada (itens[0][...]) chega como objeto: conta os itens, como o count() do PHP.
        if (isObject(value)) {
            return Object.keys(value).length;
        }

        var number = toNumber(value);

        return number !== null ? number : strlen(value);
    }

    function parseSize(size) {
        var match = /^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/.exec(str(size).trim().toUpperCase());
        var multipliers = { '': 1, K: 1024, M: 1048576, G: 1073741824 };

        return match ? Math.round(parseFloat(match[1]) * multipliers[match[2]]) : 0;
    }

    function fileExtension(file) {
        var match = /\.([^.]+)$/.exec(file && file.name ? file.name : '');

        return match ? match[1].toLowerCase() : '';
    }

    function filesOf(value) {
        if (Array.isArray(value)) {
            return value.filter(isFile);
        }

        return isFile(value) ? [value] : [];
    }

    function everyFile(value, test) {
        var files = filesOf(value);

        return files.length > 0 && files.every(test);
    }

    // '/^a+$/iu' (padrão do PHP) -> RegExp
    function phpRegex(pattern) {
        var match = /^(.)([\s\S]*)\1([a-zA-Z]*)$/.exec(str(pattern));

        if (!match) {
            return new RegExp(pattern, 'u');
        }

        var flags = match[3].replace(/[^gimsuy]/g, '');

        // \p{L} e afins exigem a flag u no JavaScript.
        if (flags.indexOf('u') === -1 && /\\[pP]\{/.test(match[2])) {
            flags += 'u';
        }

        return new RegExp(match[2], flags);
    }

    var EMAIL_RE = /^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+(\.[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+)*@([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,}$/;
    var IPV4_RE = /^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$/;
    var IPV6_RE = /^(([0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|([0-9a-f]{1,4}:){1,7}:|([0-9a-f]{1,4}:){1,6}:[0-9a-f]{1,4}|([0-9a-f]{1,4}:){1,5}(:[0-9a-f]{1,4}){1,2}|([0-9a-f]{1,4}:){1,4}(:[0-9a-f]{1,4}){1,3}|([0-9a-f]{1,4}:){1,3}(:[0-9a-f]{1,4}){1,4}|([0-9a-f]{1,4}:){1,2}(:[0-9a-f]{1,4}){1,5}|[0-9a-f]{1,4}:(:[0-9a-f]{1,4}){1,6}|:((:[0-9a-f]{1,4}){1,7}|:))$/i;
    var BOOLEANS = ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no', 'y', 'n', 'sim', 's', 'nao', 'não'];

    /*
     * Cada regra recebe (valor, parâmetros, contexto) e devolve true,
     * false, uma mensagem (string) ou uma Promise de um desses.
     */
    var RULES = {
        required: valueExists,
        string: function (v) { return typeof v === 'string'; },
        integer: function (v) {
            return typeof v === 'number' ? Number.isInteger(v) : /^-?\d+$/.test(str(v));
        },
        numeric: isNumeric,
        decimal: function (v) { return toNumber(v) !== null; },
        boolean: function (v) {
            return typeof v === 'boolean' || v === 0 || v === 1
                || (typeof v === 'string' && BOOLEANS.indexOf(v.trim().toLowerCase()) !== -1);
        },
        email: function (v) { return strlen(v) <= 254 && EMAIL_RE.test(str(v)); },
        url: function (v, p) {
            var url;

            try {
                url = new window.URL(str(v));
            } catch (e) {
                return false;
            }

            var schemes = p.length ? p.map(function (s) { return s.toLowerCase(); }) : ['http', 'https'];

            return schemes.indexOf(url.protocol.replace(/:$/, '').toLowerCase()) !== -1 && /^[a-z][a-z0-9+.-]*:\/\/./i.test(str(v));
        },
        cpf: validateCpf,
        cnpj: validateCnpj,
        cpf_cnpj: function (v) { return validateCpf(v) || validateCnpj(v); },
        phone: function (v) {
            var length = onlyDigits(v).length;

            return length >= 10 && length <= 13;
        },
        cep: function (v) { return onlyDigits(v).length === 8; },
        date: function (v, p) { return parseDate(v, p[0] || 'Y-m-d') !== null; },
        date_br: function (v) { return parseDate(v, 'd/m/Y') !== null; },
        datetime: function (v, p) { return parseDate(v, p[0] || 'Y-m-d H:i:s') !== null; },
        time: function (v, p) {
            if (p[0]) {
                return parseDate(v, p[0]) !== null;
            }

            return parseDate(v, 'H:i') !== null || parseDate(v, 'H:i:s') !== null;
        },
        array: function (v) { return Array.isArray(v) || isObject(v); },
        json: function (v) {
            if (Array.isArray(v) || isObject(v)) {
                return true;
            }

            if (typeof v !== 'string' || v.trim() === '') {
                return false;
            }

            try {
                JSON.parse(v);
                return true;
            } catch (e) {
                return false;
            }
        },
        min: function (v, p) { return measure(v) >= parseFloat(p[0] || 0); },
        max: function (v, p) { return measure(v) <= parseFloat(p[0] || 0); },
        between: function (v, p) {
            if (p.length < 2) {
                return false;
            }

            var current = measure(v);

            return current >= parseFloat(p[0]) && current <= parseFloat(p[1]);
        },
        min_length: function (v, p) { return strlen(v) >= parseInt(p[0], 10); },
        max_length: function (v, p) { return strlen(v) <= parseInt(p[0], 10); },
        length: function (v, p) { return strlen(v) === parseInt(p[0], 10); },
        in: function (v, p) { return p.map(String).indexOf(str(v)) !== -1; },
        not_in: function (v, p) { return p.map(String).indexOf(str(v)) === -1; },
        regex: function (v, p) {
            if (!p[0]) {
                return false;
            }

            try {
                return phpRegex(p[0]).test(str(v));
            } catch (e) {
                warn('Expressão regular inválida:', p[0]);
                return false;
            }
        },
        same: function (v, p, ctx) { return !!p[0] && v === getNested(ctx.data, p[0]); },
        different: function (v, p, ctx) { return !!p[0] && v !== getNested(ctx.data, p[0]); },
        required_if: function (v, p, ctx) {
            if (p.length < 2) {
                return false;
            }

            var other = str(getNested(ctx.data, p[0]));

            return p.slice(1).map(String).indexOf(other) !== -1 ? valueExists(v) : true;
        },
        required_with: function (v, p, ctx) {
            if (!p[0]) {
                return false;
            }

            for (var i = 0; i < p.length; i++) {
                if (valueExists(getNested(ctx.data, p[i]))) {
                    return valueExists(v);
                }
            }

            return true;
        },
        accepted: function (v) {
            return v === true || v === 1 || (typeof v === 'string' && ['1', 'true', 'on', 'yes', 'sim'].indexOf(v.trim().toLowerCase()) !== -1);
        },
        alpha: function (v) { return /^[\p{L}\s]+$/u.test(str(v)); },
        alpha_num: function (v) { return /^[\p{L}\p{N}]+$/u.test(str(v)); },
        alpha_dash: function (v) { return /^[\p{L}\p{N}_-]+$/u.test(str(v)); },
        uuid: function (v) { return /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(str(v)); },
        ip: function (v) { return IPV4_RE.test(str(v)) || IPV6_RE.test(str(v)); },
        after: compareDate(function (a, b) { return a > b; }),
        after_or_equal: compareDate(function (a, b) { return a >= b; }),
        before: compareDate(function (a, b) { return a < b; }),
        before_or_equal: compareDate(function (a, b) { return a <= b; }),

        file: function (v) { return filesOf(v).length > 0; },
        file_size: function (v, p) {
            var max = parseSize(p[0]);

            return max > 0 && everyFile(v, function (f) { return f.size <= max; });
        },
        file_extension: function (v, p) {
            var allowed = p.map(function (e) { return e.toLowerCase(); });

            return everyFile(v, function (f) { return allowed.indexOf(fileExtension(f)) !== -1; });
        },
        // O navegador informa o tipo pela extensão; o servidor confere o conteúdo.
        file_mime: function (v, p) {
            var allowed = p.map(function (e) { return e.toLowerCase(); });

            return everyFile(v, function (f) { return !f.type || allowed.indexOf(f.type.toLowerCase()) !== -1; });
        },
        image: function (v) {
            return everyFile(v, function (f) { return /^image\//.test(f.type || '') && f.type !== 'image/svg+xml'; });
        },
        image_dimensions: function (v, p) {
            var files = filesOf(v);
            var maxWidth = parseInt(p[0] || 0, 10);
            var maxHeight = parseInt(p[1] || 0, 10);

            if (!files.length) {
                return false;
            }

            return Promise.all(files.map(imageSize)).then(function (sizes) {
                return sizes.every(function (size) {
                    return size !== null
                        && (!maxWidth || size.width <= maxWidth)
                        && (!maxHeight || size.height <= maxHeight);
                });
            });
        },

        digits: function (v, p) { return /^\d+$/.test(str(v)) && str(v).length === parseInt(p[0], 10); },
        digits_between: function (v, p) {
            var length = str(v).length;

            return /^\d+$/.test(str(v)) && length >= parseInt(p[0], 10) && length <= parseInt(p[1], 10);
        },
        starts_with: function (v, p) {
            return p.some(function (prefix) { return str(v).indexOf(prefix) === 0; });
        },
        ends_with: function (v, p) {
            return p.some(function (suffix) {
                return suffix !== '' && str(v).slice(-suffix.length) === suffix;
            });
        },
        confirmed: function (v, p, ctx) {
            return v === getNested(ctx.data, p[0] || ctx.field + '_confirmation');
        },
        password: function (v, p) {
            var text = str(v);

            return strlen(text) >= parseInt(p[0] || 8, 10)
                && /[a-z]/.test(text) && /[A-Z]/.test(text) && /\d/.test(text) && /[^A-Za-z0-9]/.test(text);
        },
        credit_card: validateLuhn,
        placa: function (v) { return /^[A-Z]{3}-?\d[A-Z0-9]\d{2}$/i.test(str(v).trim()); },
        pis: validatePis,
        cnh: validateCnh,
        remote: function (v, p, ctx) {
            if (!p[0]) {
                return true;
            }

            var payload = { field: ctx.field, value: v };

            return http.post(p[0], payload).then(function (res) {
                return res.success !== false ? true : (firstMessage(res.errors) || res.message || false);
            }, function (error) {
                if (error.httpStatus === 422) {
                    return firstMessage(error.errors) || error.message || false;
                }

                // Falha de rede ou de servidor não bloqueia: o envio valida de novo.
                return true;
            });
        }
    };

    // Apelidos usados pelo ValidationHelper.
    RULES.int = RULES.integer;
    RULES.bool = RULES.boolean;
    RULES.telefone = RULES.phone;

    var CUSTOM_MESSAGES = {};

    function compareDate(test) {
        return function (v, p, ctx) {
            if (!p[0]) {
                return false;
            }

            var current = toTimestamp(v);
            var fromField = getNested(ctx.data, p[0]);
            var reference = toTimestamp(fromField !== null ? fromField : p[0]);

            return current !== null && reference !== null && test(current, reference);
        };
    }

    function imageSize(file) {
        return new Promise(function (resolve) {
            if (!window.URL || !window.URL.createObjectURL || typeof window.Image === 'undefined') {
                resolve(null);
                return;
            }

            var url = window.URL.createObjectURL(file);
            var image = new window.Image();

            image.onload = function () {
                window.URL.revokeObjectURL(url);
                resolve({ width: image.naturalWidth, height: image.naturalHeight });
            };

            image.onerror = function () {
                window.URL.revokeObjectURL(url);
                resolve(null);
            };

            image.src = url;
        });
    }

    function firstMessage(errors) {
        var keys = Object.keys(errors || {});

        for (var i = 0; i < keys.length; i++) {
            var list = errors[keys[i]];

            if (Array.isArray(list) && list.length) {
                return String(list[0]);
            }

            if (typeof list === 'string' && list) {
                return list;
            }
        }

        return null;
    }

    function parseRule(rule) {
        var text = str(rule).trim();
        var colon = text.indexOf(':');
        var name = (colon === -1 ? text : text.slice(0, colon)).trim().toLowerCase();
        var params = [];

        if (colon !== -1) {
            var rest = text.slice(colon + 1);

            // regex e remote: o parâmetro pode conter vírgulas.
            params = name === 'regex' || name === 'remote'
                ? [rest]
                : rest.split(',').map(function (p) { return p.trim(); });
        }

        return { name: name, params: params, fn: null };
    }

    /*
     * Mesmos formatos do ValidationHelper: 'required|email|max_length:150'
     * (regex sempre por último), lista ['required', 'email'], objeto
     * { in: ['a', 'b'], regex: '/^x$/' } e funções (regra 'callback').
     */
    function parseRules(rules) {
        var list = [];

        if (typeof rules === 'string') {
            var position = rules.toLowerCase().indexOf('regex:');
            var before = position === -1 ? rules : rules.slice(0, position);

            before.split('|').forEach(function (rule) {
                if (rule.trim() !== '') {
                    list.push(parseRule(rule));
                }
            });

            if (position !== -1) {
                list.push(parseRule(rules.slice(position)));
            }

            return list;
        }

        if (typeof rules === 'function') {
            return [{ name: 'callback', params: [], fn: rules }];
        }

        if (Array.isArray(rules)) {
            rules.forEach(function (rule) {
                if (typeof rule === 'function') {
                    list.push({ name: 'callback', params: [], fn: rule });
                } else if (typeof rule === 'string' && rule.trim() !== '') {
                    list.push(parseRule(rule));
                } else if (isObject(rule)) {
                    list = list.concat(parseRules(rule));
                }
            });

            return list;
        }

        if (isObject(rules)) {
            Object.keys(rules).forEach(function (key) {
                var value = rules[key];
                var name = key.trim().toLowerCase();

                if (typeof value === 'function') {
                    list.push({ name: name, params: [], fn: value });
                } else if (value === true) {
                    list.push({ name: name, params: [], fn: null });
                } else if (value !== false && value !== null && value !== undefined) {
                    list.push({ name: name, params: Array.isArray(value) ? value.map(String) : [String(value)], fn: null });
                }
            });
        }

        return list;
    }

    function hasRule(rules, name) {
        return parseRules(rules).some(function (rule) {
            return rule.name === name;
        });
    }

    // ------------------------------------------------------------------
    // Rótulos e mensagens (mesmo algoritmo do ValidationHelper)
    // ------------------------------------------------------------------

    function labelOf(field, options) {
        var labels = options.labels || {};

        if (own(labels, field)) {
            return labels[field];
        }

        var domLabel = options.labelFor ? options.labelFor(field) : '';
        var index = /(?:^|\.)(\d+)(?:\.|$)/.exec(field);

        if (index) {
            var pattern = wildcardPattern(field);
            var segments = field.split('.');
            var last = segments[segments.length - 1];
            var label = own(labels, pattern)
                ? labels[pattern]
                : (domLabel || ucfirst((/^\d+$/.test(last) ? segments[0] : last).replace(/[_-]/g, ' ')));

            return label + ' (item ' + (parseInt(index[1], 10) + 1) + ')';
        }

        if (domLabel) {
            return domLabel;
        }

        return ucfirst(field.replace(/[_.\-]/g, ' '));
    }

    function replacements(rule, params, data, options) {
        var first = params.length ? str(params[0]) : '';

        switch (rule) {
            case 'min':
            case 'max':
            case 'min_length':
            case 'max_length':
            case 'length':
            case 'file_size':
            case 'digits':
                return { value: first };

            case 'password':
                return { value: first || '8' };

            case 'between':
            case 'digits_between':
                return { min: first, max: params.length > 1 ? str(params[1]) : '' };

            case 'same':
            case 'different':
            case 'required_with':
                return { other: first === '' ? '' : labelOf(first, options) };

            case 'starts_with':
            case 'ends_with':
                return { values: params.join(', ') };

            case 'after':
            case 'after_or_equal':
            case 'before':
            case 'before_or_equal':
                if (first !== '' && getNested(data, first) !== null) {
                    return { date: labelOf(first, options) };
                }

                var names = { today: 'hoje', now: 'agora', tomorrow: 'amanhã', yesterday: 'ontem' };

                return { date: own(names, first.toLowerCase()) ? names[first.toLowerCase()] : first };
        }

        return {};
    }

    function replaceMessage(message, field, values, options) {
        values = extend({}, values, { field: labelOf(field, options) });

        Object.keys(values).forEach(function (key) {
            message = message.split(':' + key).join(str(values[key]));
        });

        return message;
    }

    function messageFor(field, rule, values, options) {
        var custom = options.messages || {};
        var keys = [field + '.' + rule, wildcardPattern(field) + '.' + rule, rule];

        for (var i = 0; i < keys.length; i++) {
            if (own(custom, keys[i]) && str(custom[keys[i]]).trim() !== '') {
                return replaceMessage(custom[keys[i]], field, values, options);
            }
        }

        var element = options.elementMessage ? options.elementMessage(field, rule) : '';

        if (element) {
            return replaceMessage(element, field, values, options);
        }

        var message = own(config.messages, rule) ? config.messages[rule]
            : own(CUSTOM_MESSAGES, rule) ? CUSTOM_MESSAGES[rule]
            : own(MESSAGES, rule) ? MESSAGES[rule]
            : 'O campo :field é inválido.';

        return replaceMessage(message, field, values, options);
    }

    // ------------------------------------------------------------------
    // Validação (sem DOM): Vigia.validate(dados, regras, mensagens, rótulos)
    // ------------------------------------------------------------------

    // 'itens.*.quantidade' vira uma entrada por item existente nos dados.
    function expandRules(data, rules) {
        var expanded = {};

        Object.keys(rules || {}).forEach(function (field) {
            if (field.indexOf('*') === -1) {
                expanded[field] = rules[field];
                return;
            }

            expandField(data, field.split('.'), '').forEach(function (concrete) {
                expanded[concrete] = rules[field];
            });
        });

        return expanded;
    }

    function expandField(value, segments, prefix) {
        if (!segments.length) {
            return [prefix];
        }

        var segment = segments[0];
        var rest = segments.slice(1);
        var join = function (key) {
            return prefix === '' ? String(key) : prefix + '.' + key;
        };

        if (segment !== '*') {
            var next = value !== null && typeof value === 'object' && own(value, segment) ? value[segment] : null;

            return expandField(next, rest, join(segment));
        }

        if (value === null || typeof value !== 'object' || isFile(value)) {
            return [];
        }

        var fields = [];

        Object.keys(value).forEach(function (key) {
            fields = fields.concat(expandField(value[key], rest, join(key)));
        });

        return fields;
    }

    function runRule(rule, value, field, data, options) {
        var context = { data: data, field: field, options: options };
        var result;

        if (rule.fn) {
            result = rule.fn(value, data, field);
        } else if (SERVER_ONLY_RULES.indexOf(rule.name) !== -1) {
            return Promise.resolve(null);
        } else if ((Array.isArray(value) || isObject(value)) && SCALAR_RULES.indexOf(rule.name) !== -1) {
            result = false;
        } else if (own(RULES, rule.name)) {
            result = RULES[rule.name](value, rule.params, context);
        } else {
            // Regra desconhecida passa, como no ValidationHelper.
            result = true;
        }

        return Promise.resolve(result).then(function (outcome) {
            if (typeof outcome === 'string') {
                return outcome.trim() === ''
                    ? messageFor(field, rule.name, {}, options)
                    : replaceMessage(outcome, field, {}, options);
            }

            if (outcome) {
                return null;
            }

            return messageFor(field, rule.name, replacements(rule.name, rule.params, data, options), options);
        }, function (error) {
            warn('Falha na regra ' + rule.name + ':', error);
            return messageFor(field, rule.name, {}, options);
        });
    }

    function validateField(field, fieldRules, data, options) {
        var value = getNested(data, field);
        var isEmpty = !valueExists(value);
        var errors = [];
        var chain = Promise.resolve();

        parseRules(fieldRules).forEach(function (rule) {
            if (rule.name === '' || rule.name === 'nullable') {
                return;
            }

            // Campo vazio: só as regras de presença se aplicam.
            if (isEmpty && PRESENCE_RULES.indexOf(rule.name) === -1) {
                return;
            }

            chain = chain.then(function () {
                return runRule(rule, value, field, data, options).then(function (message) {
                    if (message !== null) {
                        errors.push(message);
                    }
                });
            });
        });

        return chain.then(function () {
            return errors;
        });
    }

    /*
     * Mesmo contrato do ValidationHelper::validate():
     * Promise de { valid, errors: { campo: [mensagens] }, first_error }.
     * options.only limita a validação a alguns campos (já expandidos).
     */
    function validate(data, rules, messages, labels, extra) {
        var options = extend({ messages: messages || {}, labels: labels || {} }, extra || {});
        var expanded = expandRules(data || {}, rules || {});
        var fields = Object.keys(expanded);

        if (options.only) {
            fields = fields.filter(function (field) {
                return options.only.some(function (key) {
                    return field === key || field.indexOf(key + '.') === 0;
                });
            });
        }

        var errors = {};

        return Promise.all(fields.map(function (field) {
            return validateField(field, expanded[field], data || {}, options).then(function (list) {
                if (list.length) {
                    errors[field] = list;
                }
            });
        })).then(function () {
            // Mantém a ordem das regras declaradas.
            var ordered = {};

            fields.forEach(function (field) {
                if (errors[field]) {
                    ordered[field] = errors[field];
                }
            });

            return { valid: !Object.keys(ordered).length, errors: ordered, first_error: firstMessage(ordered) };
        });
    }

    // ------------------------------------------------------------------
    // Máscaras
    // ------------------------------------------------------------------

    /*
     * Padrões: 9 = dígito, a = letra, * = letra ou dígito; o resto é
     * literal. Uma função recebe os caracteres digitados e devolve o
     * padrão (máscaras que mudam de tamanho, como telefone e CPF/CNPJ).
     */
    var MASKS = {
        cpf: '999.999.999-99',
        cnpj: '99.999.999/9999-99',
        // Com até 11 dígitos aceita um a mais, para poder virar CNPJ.
        cpf_cnpj: function (raw) {
            return onlyDigits(raw).length <= 11 ? '999.999.999-999' : '99.999.999/9999-99';
        },
        cep: '99999-999',
        phone: function (raw) {
            return onlyDigits(raw).length <= 10 ? '(99) 9999-99999' : '(99) 99999-9999';
        },
        celular: '(99) 99999-9999',
        fixo: '(99) 9999-9999',
        date: '99/99/9999',
        time: '99:99',
        time_seconds: '99:99:99',
        datetime: '99/99/9999 99:99',
        credit_card: '9999 9999 9999 9999',
        validade_cartao: '99/99',
        pis: '999.99999.99-9',
        cnh: '99999999999',
        placa: { pattern: 'aaa-9*99', uppercase: true },
        uuid: '********-****-****-****-************',
        money: { number: true, decimals: 2, prefix: 'R$ ' },
        decimal: { number: true, decimals: 2 },
        integer: { number: true, decimals: 0 },
        percent: { number: true, decimals: 2, suffix: '%' },
        uppercase: { transform: 'upper' },
        lowercase: { transform: 'lower' },
        digits: { transform: 'digits' }
    };

    MASKS.telefone = MASKS.phone;
    MASKS.cartao = MASKS.credit_card;
    MASKS.data = MASKS.date;
    MASKS.hora = MASKS.time;
    MASKS.moeda = MASKS.money;
    MASKS.dinheiro = MASKS.money;
    MASKS.inteiro = MASKS.integer;

    function isMaskToken(c) {
        return c === '9' || c === 'a' || c === '*';
    }

    function tokenAccepts(token, c) {
        if (token === '9') {
            return /\d/.test(c);
        }

        if (token === 'a') {
            return /[A-Za-z]/.test(c);
        }

        return /[A-Za-z0-9]/.test(c);
    }

    function applyPattern(pattern, value) {
        var raw = str(value).replace(/[^A-Za-z0-9]/g, '');
        var out = '';
        var lastToken = 0;
        var r = 0;

        for (var p = 0; p < pattern.length && r < raw.length; p++) {
            var token = pattern[p];

            if (!isMaskToken(token)) {
                out += token;
                continue;
            }

            while (r < raw.length && !tokenAccepts(token, raw[r])) {
                r++;
            }

            if (r >= raw.length) {
                break;
            }

            out += raw[r++];
            lastToken = out.length;
        }

        // Sem literais pendurados no fim ("123." -> "123").
        return out.slice(0, lastToken);
    }

    function resolveMask(spec, options) {
        options = options || {};

        if (spec && typeof spec === 'object' && !Array.isArray(spec)) {
            return extend({}, spec, options);
        }

        var name = str(spec).trim();
        var known = own(MASKS, name.toLowerCase()) ? MASKS[name.toLowerCase()] : null;

        if (known === null) {
            return extend({ pattern: name }, options);
        }

        if (typeof known === 'string' || typeof known === 'function') {
            return extend({ pattern: known, name: name.toLowerCase() }, options);
        }

        return extend({ name: name.toLowerCase() }, known, options);
    }

    function formatNumber(value, mask) {
        var decimals = mask.decimals === undefined ? 2 : parseInt(mask.decimals, 10);
        var text = str(value);
        var negative = mask.negative && /-/.test(text);

        // Valor vindo do banco ("1234.5"): respeita o ponto decimal.
        if (decimals > 0 && /^-?\d+\.\d+$/.test(text.trim())) {
            text = Number(text).toFixed(decimals);
        }

        var digits = onlyDigits(text).replace(/^0+(?=\d)/, '');

        if (digits === '') {
            return negative ? '-' : '';
        }

        if (decimals > 0) {
            while (digits.length <= decimals) {
                digits = '0' + digits;
            }
        }

        var intPart = decimals > 0 ? digits.slice(0, -decimals) : digits;
        var decPart = decimals > 0 ? digits.slice(-decimals) : '';
        var thousands = mask.thousands === undefined ? '.' : mask.thousands;
        var separator = mask.separator === undefined ? ',' : mask.separator;

        intPart = intPart.replace(/^0+(?=\d)/, '');

        if (thousands) {
            intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
        }

        return (negative ? '-' : '') + (mask.prefix || '') + intPart
            + (decimals > 0 ? separator + decPart : '') + (mask.suffix || '');
    }

    // Literais antes do primeiro marcador: 'AA-' em 'AA-999'.
    function literalPrefix(pattern) {
        var prefix = '';

        for (var i = 0; i < pattern.length && !isMaskToken(pattern[i]); i++) {
            prefix += pattern[i];
        }

        return prefix;
    }

    function stripPrefix(text, prefix) {
        if (!prefix) {
            return text;
        }

        if (text.indexOf(prefix) === 0) {
            return text.slice(prefix.length);
        }

        return prefix.indexOf(text) === 0 ? '' : text;
    }

    function patternFor(mask, text) {
        return str(typeof mask.pattern === 'function' ? mask.pattern(text) : mask.pattern);
    }

    function format(value, spec, options) {
        var mask = resolveMask(spec, options);

        if (value === null || value === undefined) {
            return '';
        }

        if (mask.number) {
            return formatNumber(value, mask);
        }

        if (mask.transform === 'upper') {
            return str(value).toUpperCase();
        }

        if (mask.transform === 'lower') {
            return str(value).toLowerCase();
        }

        if (mask.transform === 'digits') {
            return onlyDigits(value);
        }

        var text = mask.uppercase ? str(value).toUpperCase() : str(value);
        var pattern = patternFor(mask, text);

        return applyPattern(pattern, stripPrefix(text, literalPrefix(pattern)));
    }

    /*
     * Valor sem máscara: dígitos/letras; número com ponto decimal
     * ("1234.56"); data dd/mm/aaaa -> aaaa-mm-dd (como o InputHelper::date).
     */
    function unmask(value, spec, options) {
        var mask = resolveMask(spec, options);
        var text = str(value);

        if (mask.number) {
            var decimals = mask.decimals === undefined ? 2 : parseInt(mask.decimals, 10);
            var digits = onlyDigits(text);

            if (digits === '') {
                return '';
            }

            var number = decimals > 0
                ? (parseInt(digits, 10) / Math.pow(10, decimals)).toFixed(decimals)
                : String(parseInt(digits, 10));

            return (mask.negative && /-/.test(text) ? '-' : '') + number;
        }

        if (mask.name === 'date' || mask.name === 'data') {
            var date = parseDate(text, 'd/m/Y');

            return date ? formatDate(date, 'Y-m-d') : text;
        }

        if (mask.name === 'datetime') {
            var dateTime = parseDate(text, 'd/m/Y H:i');

            return dateTime ? formatDate(dateTime, 'Y-m-d H:i') : text;
        }

        if (mask.name === 'time' || mask.name === 'hora' || mask.name === 'time_seconds') {
            return text;
        }

        if (mask.transform) {
            return format(text, mask);
        }

        return text.replace(/[^A-Za-z0-9]/g, '');
    }

    function maskSpecOf(el) {
        var options = {};
        var data = el.dataset || {};

        if (data.maskDecimals !== undefined) {
            options.decimals = parseInt(data.maskDecimals, 10);
        }

        if (data.maskPrefix !== undefined) {
            options.prefix = data.maskPrefix;
        }

        if (data.maskSuffix !== undefined) {
            options.suffix = data.maskSuffix;
        }

        if (data.maskThousands !== undefined) {
            options.thousands = data.maskThousands;
        }

        if (data.maskNegative !== undefined) {
            options.negative = data.maskNegative !== 'false';
        }

        return resolveMask(el.getAttribute('data-mask'), options);
    }

    // Posição logo depois do n-ésimo caractere digitado (marcador do padrão).
    function caretAfterRaw(text, count, pattern) {
        var position = literalPrefix(pattern).length;
        var seen = 0;

        for (var i = 0; i < text.length && seen < count; i++) {
            if (isMaskToken(pattern[i])) {
                seen++;
                position = i + 1;
            }
        }

        return Math.min(position, text.length);
    }

    function onMaskInput(event) {
        var el = event.target;
        var mask = el.__vigiaMask;

        if (!mask || event.isComposing) {
            return;
        }

        var old = el.value;
        var formatted = format(old, mask);

        if (formatted === old) {
            return;
        }

        var focused = document.activeElement === el;
        var caret = focused && typeof el.selectionStart === 'number' ? el.selectionStart : old.length;
        var pattern = mask.number || mask.transform ? '' : patternFor(mask, mask.uppercase ? old.toUpperCase() : old);
        var raw = stripPrefix(old.slice(0, caret), literalPrefix(pattern)).replace(/[^A-Za-z0-9]/g, '').length;

        el.value = formatted;

        if (focused && el.setSelectionRange) {
            var position;

            if (mask.number) {
                position = formatted.length - str(mask.suffix).length;
            } else if (mask.transform) {
                position = Math.min(caret, formatted.length);
            } else {
                position = caretAfterRaw(formatted, raw, patternFor(mask, formatted));

                // Cursor estava depois de um literal (ex.: apagou o dígito após o ponto): mantém.
                if (caret > 0 && /[^A-Za-z0-9]/.test(old.charAt(caret - 1))) {
                    while (position < formatted.length && !isMaskToken(patternFor(mask, formatted)[position])) {
                        position++;
                    }
                }
            }

            try {
                el.setSelectionRange(position, position);
            } catch (e) {
                // type="email"/"number" não aceitam seleção.
            }
        }
    }

    /*
     * Aplica uma máscara a um campo. spec: nome ('cpf', 'money'...),
     * padrão ('99/99') ou objeto. Também lida com data-mask-*.
     */
    function maskElement(el, spec, options) {
        var mask = spec === undefined || spec === null ? maskSpecOf(el) : resolveMask(spec, options);

        el.__vigiaMask = mask;

        if (!el.__vigiaMaskBound) {
            // capture: roda antes do x-model do Alpine e de outros ouvintes.
            el.addEventListener('input', onMaskInput, true);
            el.__vigiaMaskBound = true;
        }

        if (!el.getAttribute('inputmode')) {
            var digitsOnly = mask.transform === 'digits'
                || ['phone', 'telefone', 'cpf_cnpj'].indexOf(mask.name) !== -1
                || (typeof mask.pattern === 'string' && /^[^a*]*$/.test(mask.pattern) && mask.pattern.indexOf('9') !== -1);

            if (mask.number) {
                el.setAttribute('inputmode', mask.decimals === 0 ? 'numeric' : 'decimal');
            } else if (digitsOnly) {
                el.setAttribute('inputmode', 'numeric');
            }
        }

        if (el.value) {
            el.value = format(el.value, mask);
        }

        return el;
    }

    // ------------------------------------------------------------------
    // HTTP (axios)
    // ------------------------------------------------------------------

    var axiosInstance = null;

    function findToken(form) {
        if (config.token) {
            return config.token;
        }

        if (window.Joomla && typeof window.Joomla.getOptions === 'function') {
            var option = window.Joomla.getOptions('csrf.token', '');

            if (option) {
                return option;
            }
        }

        // JHtml::_('form.token'): <input type="hidden" name="<32 hex>" value="1">
        var scopes = [form, document].filter(Boolean);

        for (var i = 0; i < scopes.length; i++) {
            var inputs = scopes[i].querySelectorAll('input[type="hidden"][value="1"]');

            for (var j = 0; j < inputs.length; j++) {
                if (/^[a-f0-9]{32}$/i.test(inputs[j].name)) {
                    return inputs[j].name;
                }
            }
        }

        var meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : '';
    }

    function setHeader(cfg, name, value) {
        if (!cfg.headers) {
            cfg.headers = {};
        }

        if (typeof cfg.headers.set === 'function') {
            if (!cfg.headers.has || !cfg.headers.has(name)) {
                cfg.headers.set(name, value);
            }
        } else if (!own(cfg.headers, name)) {
            cfg.headers[name] = value;
        }
    }

    // { itens: [{ nome: 'a' }] } -> itens[0][nome]=a
    function appendFormData(formData, value, key) {
        if (value === undefined) {
            return;
        }

        if (isFile(value)) {
            formData.append(key, value);
        } else if (Array.isArray(value)) {
            value.forEach(function (item, index) {
                appendFormData(formData, item, isObject(item) || Array.isArray(item) ? key + '[' + index + ']' : key + '[]');
            });
        } else if (isObject(value)) {
            Object.keys(value).forEach(function (child) {
                appendFormData(formData, value[child], key ? key + '[' + child + ']' : child);
            });
        } else if (typeof value === 'boolean') {
            formData.append(key, value ? '1' : '0');
        } else {
            formData.append(key, value === null ? '' : String(value));
        }
    }

    function objectToFormData(object) {
        var formData = new window.FormData();

        appendFormData(formData, object, '');

        return formData;
    }

    function addTokenToData(data, token, json) {
        if (typeof window.FormData !== 'undefined' && data instanceof window.FormData) {
            if (!data.has(token)) {
                data.append(token, '1');
            }

            return data;
        }

        if (typeof window.URLSearchParams !== 'undefined' && data instanceof window.URLSearchParams) {
            if (!data.has(token)) {
                data.append(token, '1');
            }

            return data;
        }

        if (data === undefined || data === null) {
            data = {};
        }

        if (isObject(data) && !own(data, token)) {
            data = extend({}, data);
            data[token] = json ? 1 : '1';
        }

        return data;
    }

    // Envelope do ApiResponseHelper -> objeto do Vigia.
    function normalizeResponse(response) {
        var body = response ? response.data : null;
        var headers = response && response.headers ? response.headers : {};
        var requestId = typeof headers.get === 'function' ? headers.get('x-request-id') : headers['x-request-id'];

        if (typeof body === 'string' && /^\s*[{[]/.test(body)) {
            try {
                body = JSON.parse(body);
            } catch (e) {
                // Continua como texto.
            }
        }

        var isEnvelope = isObject(body) && (own(body, 'success') || own(body, 'mensagem') || own(body, 'errors'));
        var httpStatus = response ? response.status : 0;

        if (!isEnvelope) {
            return {
                success: httpStatus >= 200 && httpStatus < 300,
                status: '',
                message: '',
                data: body,
                errors: {},
                meta: {},
                requestId: requestId || null,
                httpStatus: httpStatus,
                json: isObject(body) || Array.isArray(body) || httpStatus === 204,
                raw: response
            };
        }

        return {
            success: own(body, 'success') ? !!body.success : httpStatus >= 200 && httpStatus < 300,
            status: str(body.status),
            message: str(own(body, 'mensagem') ? body.mensagem : body.message),
            data: own(body, 'data') ? body.data : null,
            errors: normalizeErrors(body.errors),
            meta: isObject(body.meta) ? body.meta : {},
            requestId: body.request_id || requestId || null,
            httpStatus: httpStatus,
            json: true,
            raw: response
        };
    }

    function normalizeErrors(errors) {
        if (!errors) {
            return {};
        }

        if (typeof errors === 'string') {
            return { _general: [errors] };
        }

        if (Array.isArray(errors)) {
            return errors.length ? { _general: errors.map(String) } : {};
        }

        var normalized = {};

        Object.keys(errors).forEach(function (field) {
            var list = errors[field];

            normalized[field] = Array.isArray(list) ? list.map(String) : [String(list)];
        });

        return normalized;
    }

    function httpMessage(key) {
        if (own(config.httpMessages, key)) {
            return config.httpMessages[key];
        }

        return own(HTTP_MESSAGES, key) ? HTTP_MESSAGES[key] : HTTP_MESSAGES[500];
    }

    function VigiaError(message, details) {
        var error = new Error(message);

        error.name = 'VigiaError';
        extend(error, details);

        return error;
    }

    function toVigiaError(error) {
        if (error && error.name === 'VigiaError') {
            return error;
        }

        var axios = window.axios;
        var isCancel = !!(axios && axios.isCancel && axios.isCancel(error)) || (error && error.name === 'CanceledError');
        var response = error && error.response ? normalizeResponse(error.response) : null;
        var isTimeout = !!error && (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT');
        var httpStatus = response ? response.httpStatus : 0;
        var message = response && response.message
            ? response.message
            : isCancel ? 'Requisição cancelada.'
            : isTimeout ? httpMessage('timeout')
            : !response ? httpMessage('network')
            : httpMessage(httpStatus >= 500 && !own(HTTP_MESSAGES, httpStatus) ? 500 : httpStatus);

        return VigiaError(message, {
            httpStatus: httpStatus,
            status: response ? response.status : '',
            errors: response ? response.errors : {},
            data: response ? response.data : null,
            meta: response ? response.meta : {},
            requestId: response ? response.requestId : null,
            response: response,
            isValidation: httpStatus === 422,
            isNetwork: !response && !isCancel && !isTimeout,
            isTimeout: isTimeout,
            isCancel: isCancel,
            original: error
        });
    }

    function getAxios() {
        if (axiosInstance) {
            return axiosInstance;
        }

        if (!window.axios || typeof window.axios.create !== 'function') {
            throw new Error('Vigia.js precisa do axios para requisições. Carregue o axios antes de usar Vigia.http ou enviar formulários.');
        }

        axiosInstance = window.axios.create({ timeout: config.timeout });

        axiosInstance.interceptors.request.use(function (cfg) {
            var method = str(cfg.method || 'get').toLowerCase();
            var token = cfg.vigiaToken === false ? '' : findToken(cfg.vigiaForm);

            setHeader(cfg, 'X-Requested-With', 'XMLHttpRequest');
            setHeader(cfg, 'Accept', 'application/json, text/plain, */*');

            if (token) {
                // Joomla 4/5 aceita o cabeçalho; o Joomla 3 confere o campo.
                setHeader(cfg, 'X-CSRF-Token', token);
            }

            if (method !== 'get' && method !== 'head') {
                if (token) {
                    cfg.data = addTokenToData(cfg.data, token, cfg.json);
                }

                // Padrão: multipart, que o JInput e o $_FILES entendem no Joomla 3, 4 e 5.
                if (!cfg.json && (isObject(cfg.data) || Array.isArray(cfg.data))) {
                    cfg.data = objectToFormData(cfg.data);
                }
            }

            return cfg;
        });

        axiosInstance.interceptors.response.use(function (response) {
            return response.config && response.config.vigiaRaw ? response : normalizeResponse(response);
        }, function (error) {
            return Promise.reject(toVigiaError(error));
        });

        return axiosInstance;
    }

    /*
     * Vigia.http.post(url, dados, opções) -> Promise da resposta
     * normalizada { success, status, message, data, errors, meta,
     * requestId, httpStatus }. HTTP 4xx/5xx rejeita com um VigiaError
     * (message, httpStatus, errors, isValidation...).
     * Opções extras: json: true (envia JSON), vigiaToken: false,
     * vigiaRaw: true (resposta crua do axios).
     */
    var http = {
        request: function (options) {
            return getAxios().request(options);
        },
        get: function (url, params, options) {
            return getAxios().request(extend({ url: url, method: 'get', params: params }, options));
        },
        delete: function (url, data, options) {
            return getAxios().request(extend({ url: url, method: 'delete', data: data }, options));
        },
        post: function (url, data, options) {
            return getAxios().request(extend({ url: url, method: 'post', data: data }, options));
        },
        put: function (url, data, options) {
            return getAxios().request(extend({ url: url, method: 'put', data: data }, options));
        },
        patch: function (url, data, options) {
            return getAxios().request(extend({ url: url, method: 'patch', data: data }, options));
        },
        instance: getAxios,
        token: findToken,
        normalize: normalizeResponse
    };

    // ------------------------------------------------------------------
    // Formulários
    // ------------------------------------------------------------------

    var NATIVE_TYPES = { email: 'email', url: 'url', number: 'numeric', date: 'date', time: 'time' };
    var feedbackCounter = 0;

    function isSubmittable(el) {
        var type = str(el.type).toLowerCase();

        return !!el.name && !el.disabled
            && ['button', 'submit', 'reset', 'image'].indexOf(type) === -1
            && el.tagName !== 'FIELDSET' && el.tagName !== 'OBJECT' && el.tagName !== 'OUTPUT';
    }

    function elementValue(el) {
        var type = str(el.type).toLowerCase();

        if (type === 'file') {
            var files = Array.from(el.files || []);

            return el.multiple ? files : (files[0] || null);
        }

        if (el.tagName === 'SELECT' && el.multiple) {
            return Array.from(el.options).filter(function (o) { return o.selected; }).map(function (o) { return o.value; });
        }

        if (el.__vigiaMask && el.hasAttribute('data-unmask')) {
            return unmask(el.value, el.__vigiaMask);
        }

        return el.value;
    }

    // Coloca o valor no caminho do nome: itens[0][nome], tags[], a[b][].
    function assign(data, name, value) {
        var match = /^([^\[]+)((?:\[[^\]]*\])*)$/.exec(name);

        if (!match) {
            data[name] = value;
            return;
        }

        var path = [match[1]];
        var brackets = match[2].match(/\[[^\]]*\]/g) || [];

        brackets.forEach(function (b) {
            path.push(b.slice(1, -1));
        });

        var target = data;

        for (var i = 0; i < path.length; i++) {
            var key = path[i];
            var last = i === path.length - 1;
            var nextIsPush = !last && path[i + 1] === '';

            if (key === '') {
                if (!Array.isArray(target)) {
                    return;
                }

                if (last) {
                    if (Array.isArray(value)) {
                        value.forEach(function (v) { target.push(v); });
                    } else {
                        target.push(value);
                    }

                    return;
                }

                var item = {};
                target.push(item);
                target = item;
                continue;
            }

            if (last) {
                target[key] = value;
                return;
            }

            if (!own(target, key) || target[key] === null || typeof target[key] !== 'object') {
                target[key] = nextIsPush ? [] : {};
            }

            target = target[key];
        }
    }

    function labelText(el) {
        if (el.getAttribute('data-label')) {
            return el.getAttribute('data-label');
        }

        var label = null;

        if (el.id) {
            label = (el.form || document).querySelector('label[for="' + cssEscape(el.id) + '"]');
        }

        if (!label && el.closest) {
            label = el.closest('label');
        }

        if (label) {
            var clone = label.cloneNode(true);

            // Remove a estrela de obrigatório do Joomla e campos aninhados.
            Array.from(clone.querySelectorAll('.star, .required, input, select, textarea')).forEach(function (node) {
                node.parentNode.removeChild(node);
            });

            var text = clone.textContent.replace(/\s+/g, ' ').replace(/\s*\*\s*$/, '').trim();

            if (text) {
                return text;
            }
        }

        return el.getAttribute('aria-label') || '';
    }

    function cssEscape(value) {
        if (window.CSS && window.CSS.escape) {
            return window.CSS.escape(value);
        }

        return String(value).replace(/["\\\]\[]/g, '\\$&');
    }

    // Regras vindas dos atributos do HTML (data-rules e os nativos).
    function elementRules(el) {
        var rules = [];
        var type = str(el.type).toLowerCase();
        var declared = el.getAttribute('data-rules');

        if (declared) {
            rules = rules.concat(parseRules(declared));
        }

        var has = function (name) {
            return rules.some(function (r) { return r.name === name; });
        };
        var add = function (name, params) {
            if (!has(name)) {
                rules.push({ name: name, params: params || [], fn: null });
            }
        };

        if (el.required) {
            add('required');
        }

        if (own(NATIVE_TYPES, type)) {
            add(NATIVE_TYPES[type]);
        }

        if (type === 'datetime-local') {
            add('datetime', [el.step && parseFloat(el.step) < 60 ? 'Y-m-d\\TH:i:s' : 'Y-m-d\\TH:i']);
        }

        if (el.hasAttribute('minlength')) {
            add('min_length', [el.getAttribute('minlength')]);
        }

        if (el.hasAttribute('maxlength') && el.getAttribute('maxlength') !== '-1' && !el.__vigiaMask) {
            add('max_length', [el.getAttribute('maxlength')]);
        }

        if (type === 'number' || type === 'range') {
            if (el.hasAttribute('min')) {
                add('min', [el.getAttribute('min')]);
            }

            if (el.hasAttribute('max')) {
                add('max', [el.getAttribute('max')]);
            }
        }

        if (el.hasAttribute('pattern') && el.getAttribute('pattern')) {
            add('regex', ['/^(?:' + el.getAttribute('pattern') + ')$/u']);
        }

        return rules;
    }

    function serializeRules(list) {
        return list.map(function (rule) {
            return rule.fn ? rule.fn : (rule.params.length ? rule.name + ':' + rule.params.join(',') : rule.name);
        });
    }

    function VigiaForm(form, options) {
        this.form = form;
        this.options = {};
        this.errors = {};
        this.loading = false;
        this.progress = 0;
        this.response = null;
        this.message = '';
        this.messageType = '';
        this._sequence = {};
        this._listeners = {};
        this.configure(options);
        this._bind();

        var initial = parseJsonAttribute(form, 'data-vigia-errors');

        if (initial) {
            this.showErrors(normalizeErrors(initial));
        }
    }

    VigiaForm.prototype.configure = function (options) {
        var fromAttribute = parseJsonAttribute(this.form, 'data-vigia');

        if (!isObject(fromAttribute)) {
            fromAttribute = {};
        }

        var o = extend({}, this.options, fromAttribute, options || {});

        o.rules = extend({}, fromAttribute.rules, parseJsonAttribute(this.form, 'data-vigia-rules'), this.options.rules, options && options.rules);
        o.messages = extend({}, fromAttribute.messages, parseJsonAttribute(this.form, 'data-vigia-messages'), this.options.messages, options && options.messages);
        o.labels = extend({}, fromAttribute.labels, parseJsonAttribute(this.form, 'data-vigia-labels'), this.options.labels, options && options.labels);

        this.options = o;

        return this;
    };

    VigiaForm.prototype.option = function (name, attribute, fallback) {
        if (own(this.options, name) && this.options[name] !== undefined) {
            return this.options[name];
        }

        if (attribute && this.form.hasAttribute(attribute)) {
            var value = this.form.getAttribute(attribute);

            return value === '' ? true : value === 'false' ? false : value;
        }

        return fallback;
    };

    VigiaForm.prototype.on = function (name, callback) {
        (this._listeners[name] = this._listeners[name] || []).push(callback);

        return this;
    };

    VigiaForm.prototype._trigger = function (name, detail, cancelable) {
        var callbackName = 'on' + name.charAt(0).toUpperCase() + name.slice(1);
        var result;

        if (typeof this.options[callbackName] === 'function') {
            result = this.options[callbackName].call(this, detail, this);
        }

        (this._listeners[name] || []).forEach(function (callback) {
            callback.call(this, detail, this);
        }, this);

        var allowed = emit(this.form, 'vigia:' + name, extend({ form: this.form, vigia: this }, detail), cancelable);

        return result !== false && allowed;
    };

    VigiaForm.prototype._setState = function (state) {
        extend(this, state);

        if (typeof this.options.onState === 'function') {
            this.options.onState({
                errors: this.errors,
                loading: this.loading,
                progress: this.progress,
                response: this.response,
                message: this.message,
                messageType: this.messageType,
                valid: !Object.keys(this.errors).length
            });
        }
    };

    VigiaForm.prototype._bind = function () {
        var self = this;
        var form = this.form;

        if (form.tagName === 'FORM') {
            form.noValidate = true;
        }

        this._onSubmit = function (event) {
            if (self.option('submit', 'data-vigia-submit', 'ajax') === 'native' && self._nativeOk) {
                return;
            }

            event.preventDefault();
            self.submit(event.submitter || null);
        };

        this._onFocusOut = function (event) {
            var mode = self.option('validateOn', 'data-vigia-validate-on', config.validateOn);

            if (mode !== 'submit' && isSubmittable(event.target)) {
                self.validateElement(event.target);
            }
        };

        this._onInput = function (event) {
            var el = event.target;
            var mode = self.option('validateOn', 'data-vigia-validate-on', config.validateOn);

            if (!isSubmittable(el)) {
                return;
            }

            var key = self.keyOf(el);
            var type = str(el.type).toLowerCase();
            var immediate = event.type === 'change' && (type === 'checkbox' || type === 'radio' || type === 'file' || el.tagName === 'SELECT');

            if (mode === 'input' || immediate || own(self.errors, key)) {
                self.validateElement(el);
            }

            self._revalidateDependents(key);
        };

        form.addEventListener('submit', this._onSubmit);
        form.addEventListener('focusout', this._onFocusOut);
        form.addEventListener('input', this._onInput);
        form.addEventListener('change', this._onInput);
    };

    VigiaForm.prototype.destroy = function () {
        this.form.removeEventListener('submit', this._onSubmit);
        this.form.removeEventListener('focusout', this._onFocusOut);
        this.form.removeEventListener('input', this._onInput);
        this.form.removeEventListener('change', this._onInput);
        this.clearErrors();
        delete this.form.__vigia;
    };

    VigiaForm.prototype.elements = function () {
        var form = this.form;
        var list = form.elements ? Array.from(form.elements) : Array.from(form.querySelectorAll('input, select, textarea'));

        return list.filter(isSubmittable);
    };

    // Chave de erro de um campo: 'itens[0][nome]' -> 'itens.0.nome';
    // num 'tags[]' de texto, a posição: 'tags.0', 'tags.1'.
    VigiaForm.prototype.keyOf = function (el) {
        var key = nameToKey(el.name);
        var type = str(el.type).toLowerCase();

        if (/\[\]$/.test(el.name) && type !== 'checkbox' && type !== 'radio' && !(el.tagName === 'SELECT' && el.multiple) && type !== 'file') {
            var same = this.elements().filter(function (other) { return other.name === el.name; });

            return key + '.' + same.indexOf(el);
        }

        return key;
    };

    VigiaForm.prototype._index = function () {
        var index = {};
        var self = this;

        this.elements().forEach(function (el) {
            var keys = [nameToKey(el.name)];
            var specific = self.keyOf(el);

            if (specific !== keys[0]) {
                keys.push(specific);
            }

            if (el.getAttribute('data-field')) {
                keys.push(el.getAttribute('data-field'));
            }

            keys.forEach(function (key) {
                (index[key] = index[key] || []).push(el);
            });
        });

        return index;
    };

    VigiaForm.prototype.getData = function () {
        var data = {};

        this.elements().forEach(function (el) {
            var type = str(el.type).toLowerCase();

            if ((type === 'checkbox' || type === 'radio') && !el.checked) {
                return;
            }

            assign(data, el.name, elementValue(el));
        });

        return data;
    };

    VigiaForm.prototype.toFormData = function () {
        var formData = new window.FormData();

        this.elements().forEach(function (el) {
            var type = str(el.type).toLowerCase();

            if ((type === 'checkbox' || type === 'radio') && !el.checked) {
                return;
            }

            var value = elementValue(el);

            if (type === 'file') {
                Array.from(el.files || []).forEach(function (file) {
                    formData.append(el.name, file);
                });
            } else if (Array.isArray(value)) {
                value.forEach(function (v) {
                    formData.append(el.name, v);
                });
            } else {
                formData.append(el.name, value);
            }
        });

        return formData;
    };

    // Regras finais: as do PHP/JS (inclusive com *) + as dos atributos.
    VigiaForm.prototype.rules = function () {
        var rules = extend({}, this.options.rules);
        var self = this;

        this.elements().forEach(function (el) {
            var fromElement = elementRules(el);

            if (!fromElement.length) {
                return;
            }

            var type = str(el.type).toLowerCase();
            var key = nameToKey(el.name);

            // Lista de textos (tags[]): as regras valem para cada item.
            if (/\[\]$/.test(el.name) && type !== 'checkbox' && type !== 'radio' && type !== 'file' && !(el.tagName === 'SELECT' && el.multiple)) {
                key += '.*';
            }

            var pattern = wildcardPattern(key);
            var existing = own(rules, key) ? parseRules(rules[key])
                : own(rules, pattern) ? parseRules(rules[pattern]) : [];
            var names = existing.map(function (r) { return r.name; });

            fromElement.forEach(function (rule) {
                if (names.indexOf(rule.name) === -1) {
                    existing.push(rule);
                    names.push(rule.name);
                }
            });

            rules[key] = serializeRules(existing);
        });

        self._rulesCache = rules;

        return rules;
    };

    VigiaForm.prototype._validationOptions = function () {
        var self = this;
        var index = this._index();

        return {
            messages: this.options.messages,
            labels: this.options.labels,
            labelFor: function (field) {
                var elements = index[field] || index[wildcardPattern(field).replace(/\.\*$/, '')] || [];

                return elements.length ? labelText(elements[0]) : '';
            },
            elementMessage: function (field, rule) {
                var elements = index[field] || [];
                var attribute = 'data-msg-' + rule.replace(/_/g, '-');

                for (var i = 0; i < elements.length; i++) {
                    if (elements[i].getAttribute(attribute)) {
                        return elements[i].getAttribute(attribute);
                    }
                }

                return '';
            }
        };
    };

    // Valida o formulário inteiro e mostra os erros.
    VigiaForm.prototype.validate = function () {
        var self = this;
        var data = this.getData();

        return validate(data, this.rules(), null, null, this._validationOptions()).then(function (result) {
            result = self._inDomOrder(result);
            self.clearErrors({ keepMessage: true });

            // Erros sem campo na tela vão para a mensagem geral: nunca bloqueiam em silêncio.
            var unmapped = self.showErrors(result.errors, { focus: false, general: false });

            if (unmapped.length) {
                self.notify(unmapped.join(' '), 'error');
            }

            return result;
        });
    };

    // Reordena { campo: [...] } pela posição dos campos no formulário.
    VigiaForm.prototype._inDomOrder = function (result) {
        var index = this._index();
        var elements = this.elements();
        var position = function (key) {
            var list = index[key] || [];

            return list.length ? elements.indexOf(list[0]) : elements.length;
        };
        var keys = Object.keys(result.errors);
        var ordered = {};

        keys.map(function (key, i) {
            return { key: key, pos: position(key), i: i };
        }).sort(function (a, b) {
            return a.pos - b.pos || a.i - b.i;
        }).forEach(function (item) {
            ordered[item.key] = result.errors[item.key];
        });

        return { valid: result.valid, errors: ordered, first_error: firstMessage(ordered) };
    };

    VigiaForm.prototype.validateElement = function (el) {
        return this.validateField(this.keyOf(el));
    };

    // Valida um campo (chave 'email', 'itens.0.nome') e atualiza a tela.
    VigiaForm.prototype.validateField = function (key) {
        var self = this;
        var sequence = (this._sequence[key] || 0) + 1;
        var data = this.getData();
        var rules = this.rules();

        this._sequence[key] = sequence;

        return validate(data, rules, null, null, extend(this._validationOptions(), { only: [key] })).then(function (result) {
            if (self._sequence[key] !== sequence) {
                return result;
            }

            var errors = extend({}, self.errors);
            var related = function (field) {
                return field === key || field.indexOf(key + '.') === 0;
            };
            var touched = Object.keys(errors).filter(related);

            Object.keys(result.errors).forEach(function (field) {
                if (touched.indexOf(field) === -1) {
                    touched.push(field);
                }
            });

            touched.forEach(function (field) {
                delete errors[field];

                if (result.errors[field]) {
                    errors[field] = result.errors[field];
                }

                self._renderField(field, result.errors[field] || []);
            });

            self._setState({ errors: errors });

            return result;
        });
    };

    // Campos com erro que dependem de outro (same, confirmed, required_if...).
    VigiaForm.prototype._revalidateDependents = function (key) {
        var self = this;
        var rules = this._rulesCache || this.rules();

        Object.keys(this.errors).forEach(function (field) {
            if (field === key) {
                return;
            }

            var fieldRules = rules[field] || rules[wildcardPattern(field)] || [];
            var depends = field === key.replace(/_confirmation$/, '') || parseRules(fieldRules).some(function (rule) {
                return rule.params.indexOf(key) !== -1;
            });

            if (depends) {
                self.validateField(field);
            }
        });
    };

    VigiaForm.prototype._feedbackFor = function (key, elements, create) {
        var form = this.form;
        var explicit = form.querySelector('[data-error-for="' + cssEscape(key) + '"]')
            || form.querySelector('[data-error-for="' + cssEscape(keyToName(key)) + '"]');

        if (explicit) {
            return explicit;
        }

        var existing = form.querySelector('[data-vigia-feedback-for="' + cssEscape(key) + '"]');

        if (existing || !create || !elements.length) {
            return existing;
        }

        var anchor = elements[elements.length - 1];
        var type = str(anchor.type).toLowerCase();

        if ((type === 'checkbox' || type === 'radio') && anchor.closest) {
            anchor = anchor.closest('.form-check, .checkbox, .radio, .controls, label') || anchor;
        }

        if (anchor.parentNode && anchor.parentNode.classList && (anchor.parentNode.classList.contains('input-group') || anchor.parentNode.classList.contains('input-append') || anchor.parentNode.classList.contains('input-prepend'))) {
            anchor = anchor.parentNode;
        }

        var feedback = document.createElement('div');

        feedbackCounter++;
        feedback.id = 'vigia-erro-' + feedbackCounter;
        feedback.setAttribute('data-vigia-feedback-for', key);
        feedback.setAttribute('aria-live', 'polite');
        addClasses(feedback, config.classes.feedback);
        anchor.parentNode.insertBefore(feedback, anchor.nextSibling);

        return feedback;
    };

    /*
     * Campos de uma chave de erro. Item de lista sem campo próprio
     * ('categorias.1', 'fotos.0') usa o campo da lista ('categorias[]').
     */
    VigiaForm.prototype._elementsFor = function (key) {
        var index = this._index();
        var current = key;

        while (!index[current] && /\.\d+$/.test(current)) {
            current = current.replace(/\.\d+$/, '');
        }

        return index[current] || [];
    };

    VigiaForm.prototype._renderField = function (key, messages) {
        if (this.option('render', null, true) === false) {
            return this._elementsFor(key).length > 0;
        }

        var elements = this._elementsFor(key);
        var feedback = this._feedbackFor(key, elements, messages.length > 0);
        var invalid = messages.length > 0;

        elements.forEach(function (el) {
            if (invalid) {
                removeClasses(el, config.classes.valid);
                addClasses(el, config.classes.invalid);
                el.setAttribute('aria-invalid', 'true');

                if (feedback && feedback.id) {
                    var described = str(el.getAttribute('aria-describedby')).split(/\s+/).filter(Boolean);

                    if (described.indexOf(feedback.id) === -1) {
                        described.push(feedback.id);
                        el.setAttribute('aria-describedby', described.join(' '));
                    }
                }
            } else {
                removeClasses(el, config.classes.invalid);
                el.removeAttribute('aria-invalid');

                if (el.value && config.classes.valid) {
                    addClasses(el, config.classes.valid);
                }
            }
        });

        if (feedback) {
            var shown = config.firstErrorOnly ? messages.slice(0, 1) : messages;

            feedback.textContent = '';

            shown.forEach(function (message, index) {
                if (index > 0) {
                    feedback.appendChild(document.createElement('br'));
                }

                feedback.appendChild(document.createTextNode(message));
            });

            feedback.style.display = invalid ? 'block' : 'none';

            if (feedback.hasAttribute('data-vigia-feedback-for') && !invalid && feedback.parentNode) {
                feedback.parentNode.removeChild(feedback);
            }
        }

        return elements.length > 0 || !!feedback;
    };

    /*
     * Mostra erros { campo: [mensagens] } nos campos. Erros sem campo
     * correspondente (e _general/_system) vão para a mensagem geral.
     */
    VigiaForm.prototype.showErrors = function (errors, options) {
        options = extend({ focus: true, general: true }, options);
        errors = normalizeErrors(errors);

        var self = this;
        var unmapped = [];
        var merged = extend({}, this.errors);

        this._mapped = 0;

        Object.keys(errors).forEach(function (key) {
            if (!errors[key].length) {
                return;
            }

            merged[key] = errors[key];

            if (key.charAt(0) === '_' || !self._renderField(key, errors[key])) {
                unmapped = unmapped.concat(config.firstErrorOnly ? errors[key].slice(0, 1) : errors[key]);
            } else {
                self._mapped++;
            }
        });

        this._setState({ errors: merged });

        if (options.general && unmapped.length) {
            this.notify(unmapped.join(' '), 'error');
        }

        if (options.focus) {
            this.focusFirstError();
        }

        return unmapped;
    };

    VigiaForm.prototype.focusFirstError = function () {
        if (!this.option('focusInvalid', 'data-vigia-focus', config.focusInvalid)) {
            return;
        }

        var self = this;
        var keys = Object.keys(this.errors);
        var first = this.elements().filter(function (el) {
            return keys.some(function (key) {
                return self._elementsFor(key).indexOf(el) !== -1;
            });
        })[0];

        if (first && typeof first.focus === 'function') {
            first.focus();

            if (first.scrollIntoView && !isInViewport(first)) {
                first.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        }
    };

    function isInViewport(el) {
        if (!el.getBoundingClientRect || !window.innerHeight) {
            return true;
        }

        var rect = el.getBoundingClientRect();

        return rect.top >= 0 && rect.bottom <= window.innerHeight;
    }

    VigiaForm.prototype.clearErrors = function (options) {
        var self = this;

        Object.keys(this.errors).forEach(function (key) {
            self._renderField(key, []);
        });

        Array.from(this.form.querySelectorAll('[data-vigia-feedback-for]')).forEach(function (node) {
            node.parentNode.removeChild(node);
        });

        this._setState({ errors: {} });

        if (!options || !options.keepMessage) {
            this.notify('', '');
        }
    };

    VigiaForm.prototype.setErrors = function (errors) {
        this.clearErrors({ keepMessage: true });

        return this.showErrors(errors);
    };

    /*
     * Mensagem geral (sucesso/erro). Ordem: [data-vigia-message] no
     * formulário, Joomla.renderMessages (notify: 'auto' ou 'joomla'),
     * ou uma caixa criada no topo do formulário.
     */
    VigiaForm.prototype.notify = function (message, type) {
        var target = this.form.querySelector('[data-vigia-message]');
        var notify = this.option('notify', 'data-vigia-notify', config.notify);

        this._setState({ message: message || '', messageType: message ? type : '' });

        if (notify === false || notify === 'none') {
            return;
        }

        var joomla = window.Joomla && typeof window.Joomla.renderMessages === 'function';

        if (!target && message && (notify === 'joomla' || (notify === 'auto' && joomla))) {
            var types = { success: 'message', error: 'error', warning: 'warning', info: 'notice' };
            var payload = {};

            payload[types[type] || 'message'] = [message];
            window.Joomla.renderMessages(payload);

            return;
        }

        if (!target) {
            if (!message) {
                return;
            }

            target = document.createElement('div');
            target.setAttribute('data-vigia-message', '');
            target.setAttribute('data-vigia-created', '');
            this.form.insertBefore(target, this.form.firstChild);
        }

        Object.keys(config.classes.alert).forEach(function (key) {
            removeClasses(target, config.classes.alert[key]);
        });

        target.textContent = message || '';
        target.setAttribute('role', type === 'error' ? 'alert' : 'status');
        target.style.display = message ? '' : 'none';

        if (message && config.classes.alert[type]) {
            addClasses(target, config.classes.alert[type]);
        }
    };

    VigiaForm.prototype._setLoading = function (loading, submitter) {
        var form = this.form;

        if (loading) {
            addClasses(form, config.classes.loading);
            form.setAttribute('aria-busy', 'true');
        } else {
            removeClasses(form, config.classes.loading);
            form.removeAttribute('aria-busy');
        }

        Array.from(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')).forEach(function (button) {
            if (loading) {
                button.__vigiaDisabled = button.disabled;
                button.disabled = true;

                if (button === submitter || !submitter) {
                    addClasses(button, config.classes.loading);
                }

                var text = button.getAttribute('data-loading-text');

                if (text && (button === submitter || !submitter)) {
                    button.__vigiaText = button.tagName === 'INPUT' ? button.value : button.innerHTML;

                    if (button.tagName === 'INPUT') {
                        button.value = text;
                    } else {
                        button.textContent = text;
                    }
                }
            } else {
                button.disabled = !!button.__vigiaDisabled;
                removeClasses(button, config.classes.loading);

                if (button.__vigiaText !== undefined) {
                    if (button.tagName === 'INPUT') {
                        button.value = button.__vigiaText;
                    } else {
                        button.innerHTML = button.__vigiaText;
                    }

                    delete button.__vigiaText;
                }
            }
        });

        this._setState({ loading: loading, progress: loading ? 0 : this.progress });
    };

    VigiaForm.prototype._setProgress = function (percent) {
        var bar = this.form.querySelector('[data-vigia-progress]');

        if (bar) {
            if (bar.tagName === 'PROGRESS') {
                bar.max = 100;
                bar.value = percent;
            } else {
                bar.style.width = percent + '%';
                bar.setAttribute('aria-valuenow', String(percent));
                bar.textContent = percent + '%';
            }
        }

        this._setState({ progress: percent });
    };

    /*
     * Valida e envia pelo axios. Resolve com a resposta normalizada (ou
     * { valid: false, errors } se a validação falhar); nunca rejeita: os
     * erros aparecem na tela e nos eventos vigia:invalid / vigia:error.
     */
    VigiaForm.prototype.submit = function (submitter) {
        var self = this;
        var form = this.form;

        if (this.loading) {
            return Promise.resolve(null);
        }

        var confirmText = this.option('confirm', 'data-vigia-confirm', '');

        if (confirmText && confirmText !== true && !window.confirm(confirmText)) {
            return Promise.resolve(null);
        }

        this.notify('', '');

        return this.validate().then(function (result) {
            if (!result.valid) {
                self.focusFirstError();
                self._trigger('invalid', { errors: result.errors });

                return { valid: false, errors: result.errors };
            }

            var data = self.getData();

            if (self._trigger('before', { data: data }, true) === false) {
                return null;
            }

            if (self.option('submit', 'data-vigia-submit', 'ajax') === 'native') {
                self._nativeOk = true;

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit(submitter || undefined);
                } else {
                    form.submit();
                }

                self._nativeOk = false;

                return { valid: true, native: true };
            }

            return self._send(submitter);
        });
    };

    VigiaForm.prototype._send = function (submitter) {
        var self = this;
        var form = this.form;
        var method = str(this.option('method', null, form.getAttribute('method') || 'post')).toLowerCase();
        var url = this.option('url', 'data-vigia-url', form.getAttribute('action') || window.location.href);
        var body = this.toFormData();

        if (submitter && submitter.name) {
            body.append(submitter.name, submitter.value);
        }

        var request = {
            url: url,
            method: method,
            vigiaForm: form,
            onUploadProgress: function (event) {
                if (event.total) {
                    self._setProgress(Math.round((event.loaded / event.total) * 100));
                }
            }
        };

        if (method === 'get') {
            // URLSearchParams mantém todos os valores de listas (c[]=1&c[]=2).
            var params = new window.URLSearchParams();

            body.forEach(function (value, key) {
                if (!isFile(value)) {
                    params.append(key, value);
                }
            });

            request.params = params;
        } else {
            request.data = body;
        }

        var extra = extend({}, this.options.request);
        var ownProgress = request.onUploadProgress;

        // onUploadProgress próprio roda junto com o do Vigia (barra e estado progress).
        if (typeof extra.onUploadProgress === 'function') {
            var userProgress = extra.onUploadProgress;

            extra.onUploadProgress = function (event) {
                ownProgress(event);
                userProgress(event);
            };
        }

        this._setLoading(true, submitter);

        return http.request(extend(request, extra)).then(function (response) {
            if (!response.json) {
                throw VigiaError(httpMessage('unexpected'), { httpStatus: response.httpStatus, errors: {}, response: response });
            }

            if (response.success === false) {
                throw VigiaError(response.message || httpMessage(400), {
                    httpStatus: response.httpStatus,
                    status: response.status,
                    errors: response.errors,
                    data: response.data,
                    response: response
                });
            }

            self._setState({ response: response });
            self.clearErrors({ keepMessage: true });

            if (response.message && self.option('successMessage', 'data-vigia-success-message', true) !== false) {
                self.notify(response.message, response.status === 'aviso' ? 'warning' : response.status === 'info' ? 'info' : 'success');
            }

            self._trigger('success', { response: response, data: response.data });

            if (self.option('reset', 'data-vigia-reset', false)) {
                form.reset();
            }

            var redirect = self.option('redirect', 'data-vigia-redirect', '')
                || (isObject(response.data) && response.data.redirect ? response.data.redirect : '');

            if (redirect && redirect !== true) {
                window.location.href = redirect;
            }

            return response;
        }).catch(function (error) {
            error = toVigiaError(error);

            self._setState({ response: error.response || null });

            // Erros nos campos já explicam o problema; a mensagem geral só
            // aparece para o que não tem campo (ou quando não há erros).
            var unmapped = self.showErrors(error.errors || {}, { general: false });

            if (unmapped.length) {
                self.notify(unmapped.join(' '), 'error');
            } else if (!self._mapped) {
                self.notify(error.message, 'error');
            }

            self._trigger('error', { error: error, errors: error.errors || {} });

            return error.response || { success: false, message: error.message, errors: error.errors || {} };
        }).then(function (outcome) {
            self._setLoading(false, submitter);
            self._trigger('complete', { response: outcome });

            return outcome;
        });
    };

    VigiaForm.prototype.reset = function () {
        if (typeof this.form.reset === 'function') {
            this.form.reset();
        }

        this.clearErrors();
    };

    function formFor(el, options) {
        if (typeof el === 'string') {
            el = document.querySelector(el);
        }

        if (!el) {
            throw new Error('Vigia.form: formulário não encontrado.');
        }

        if (el.__vigia) {
            if (options) {
                el.__vigia.configure(options);
            }

            return el.__vigia;
        }

        el.__vigia = new VigiaForm(el, options);

        return el.__vigia;
    }

    // ------------------------------------------------------------------
    // Inicialização automática ([data-vigia] e [data-mask])
    // ------------------------------------------------------------------

    function init(root) {
        root = root || document;

        var scan = function (selector) {
            var found = root.querySelectorAll ? Array.from(root.querySelectorAll(selector)) : [];

            if (root.matches && root.matches(selector)) {
                found.unshift(root);
            }

            return found;
        };

        scan('[data-mask]').forEach(function (el) {
            if (!el.__vigiaMaskBound) {
                maskElement(el);
            }
        });

        scan('form[data-vigia], [data-vigia-form]').forEach(function (form) {
            formFor(form);
        });
    }

    var observer = null;

    function observe() {
        if (observer || typeof window.MutationObserver === 'undefined' || !document.body) {
            return;
        }

        observer = new window.MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.from(mutation.addedNodes).forEach(function (node) {
                    if (node.nodeType === 1) {
                        init(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    // ------------------------------------------------------------------
    // Alpine.js (opcional)
    // ------------------------------------------------------------------

    /*
     * x-data="vigia({ ...opções }, { ...seu estado })" no <form>: errors,
     * loading, progress, message reativos; error('campo'),
     * hasError('campo'), submit(). O segundo objeto entra no componente
     * (campos do x-model, flags da tela, métodos).
     * x-mask="'cpf'" (ou x-mask:cpf) aplica máscara.
     */
    function alpinePlugin(Alpine) {
        Alpine.directive('mask', function (el, directive, utils) {
            var spec = directive.value || (directive.expression ? utils.evaluate(directive.expression) : el.getAttribute('data-mask'));

            maskElement(el, spec);
        });

        Alpine.data('vigia', function (options, state) {
            var instance = null;

            options = options || {};

            return extend({
                errors: {},
                loading: false,
                progress: 0,
                message: '',
                messageType: '',
                response: null,
                valid: true,

                init: function () {
                    var self = this;
                    var form = this.$el.tagName === 'FORM' ? this.$el : this.$el.querySelector('form');

                    instance = formFor(form, extend({}, options, {
                        onState: function (state) {
                            Object.keys(state).forEach(function (key) {
                                self[key] = state[key];
                            });

                            if (typeof options.onState === 'function') {
                                options.onState(state);
                            }
                        }
                    }));
                },
                destroy: function () {
                    if (instance) {
                        instance.destroy();
                    }
                },
                submit: function () {
                    return instance.submit();
                },
                validate: function () {
                    return instance.validate();
                },
                reset: function () {
                    instance.reset();
                },
                error: function (field) {
                    var list = this.errors[field];

                    return list && list.length ? list[0] : '';
                },
                hasError: function (field) {
                    return !!(this.errors[field] && this.errors[field].length);
                },
                setErrors: function (errors) {
                    return instance.setErrors(errors);
                },
                form: function () {
                    return instance;
                }
            }, state || {});
        });
    }

    // ------------------------------------------------------------------
    // API pública
    // ------------------------------------------------------------------

    var Vigia = {
        version: VERSION,
        config: function (options) {
            if (options) {
                deepExtend(config, options);
            }

            return config;
        },
        init: init,
        form: formFor,
        validate: validate,
        http: http,
        rules: {
            add: function (name, fn, message) {
                RULES[name] = fn;

                if (message) {
                    CUSTOM_MESSAGES[name] = message;
                }
            },
            has: function (name) {
                return own(RULES, name);
            },
            parse: parseRules,
            test: function (name, value, params, data) {
                return Promise.resolve(RULES[name](value, params || [], { data: data || {}, field: '' }));
            }
        },
        mask: extend(maskElement, {
            format: format,
            unmask: unmask,
            add: function (name, spec) {
                MASKS[name] = spec;
            },
            masks: MASKS
        }),
        messages: MESSAGES,
        alpine: alpinePlugin,
        Error: VigiaError,
        utils: {
            nameToKey: nameToKey,
            keyToName: keyToName,
            toNumber: toNumber,
            parseDate: parseDate,
            formatDate: formatDate,
            cpf: validateCpf,
            cnpj: validateCnpj
        }
    };

    if (document) {
        document.addEventListener('alpine:init', function () {
            if (window.Alpine && !window.Alpine.__vigia) {
                window.Alpine.__vigia = true;
                window.Alpine.plugin(alpinePlugin);
            }
        });

        var start = function () {
            if (config.autoInit) {
                init(document);
                observe();
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            setTimeout(start, 0);
        }
    }

    return Vigia;
}));
