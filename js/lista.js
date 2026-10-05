/*!
 * Lista.js 1.0.0
 * Telas de listagem para componentes Joomla: paginação, ordenação e
 * filtros na URL, busca com espera, cancelamento da requisição anterior,
 * ações por linha e em lote. Par do Vigia.js, sobre
 * ApiResponseHelper::paginated() e InputHelper::pagination()/sorting()/filters()
 * (https://github.com/heuderdev/helpers-joomla).
 *
 * Requer o Vigia.js (e o axios) carregados antes.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory(root);
    } else {
        root.Lista = factory(root);
    }
}(typeof window !== 'undefined' ? window : this, function (window) {
    'use strict';

    var document = window.document;
    var VERSION = '1.0.0';

    var config = {
        limit: 20,
        debounce: 350,
        // Atualiza a URL do navegador (F5 e "voltar" mantêm página e filtros).
        history: true,
        pagesAround: 2,
        // 'numbers' (padrão: « ‹ 1 … 4 5 6 … 20 › ») ou 'jpaginate' (faixa de páginas que desliza, como o jQuery Paginate).
        paginationStyle: 'numbers',
        // Estilo 'jpaginate': quantas páginas a faixa mostra e a velocidade ao segurar a seta (ms).
        jpaginate: { display: 7, holdDelay: 350, holdSpeed: 90 },
        autoInit: true,
        params: { page: 'page', limit: 'limit', sort: 'sort', direction: 'direction' },
        classes: {
            pagination: 'pagination',
            pageItem: 'page-item',
            pageLink: 'page-link',
            active: 'active',
            disabled: 'disabled',
            loading: 'lista-loading',
            sortAsc: 'lista-sort-asc',
            sortDesc: 'lista-sort-desc',
            selected: 'lista-selected'
        },
        messages: {
            error: 'Não foi possível carregar a lista.',
            confirmBatch: 'Aplicar a :count itens selecionados?',
            summary: 'Mostrando :from–:to de :total',
            summaryEmpty: 'Nenhum registro',
            previous: 'Anterior',
            next: 'Próxima',
            first: 'Primeira',
            last: 'Última',
            slidePrevious: 'Mostrar páginas anteriores',
            slideNext: 'Mostrar próximas páginas',
            pageOf: 'Página :page de :total'
        }
    };

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    function str(value) {
        return value === null || value === undefined ? '' : String(value);
    }

    function extend(target) {
        for (var i = 1; i < arguments.length; i++) {
            var source = arguments[i] || {};

            Object.keys(source).forEach(function (key) {
                target[key] = source[key];
            });
        }

        return target;
    }

    function deepExtend(target, source) {
        Object.keys(source || {}).forEach(function (key) {
            if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key]) && target[key] && typeof target[key] === 'object') {
                deepExtend(target[key], source[key]);
            } else {
                target[key] = source[key];
            }
        });

        return target;
    }

    function getPath(item, path) {
        var value = item;

        str(path).split('.').forEach(function (part) {
            value = value !== null && value !== undefined ? value[part] : undefined;
        });

        return value;
    }

    // 'index.php?id={id}&c={cliente.nome}' -> valores codificados para URL
    function fillUrl(template, item) {
        // O atributo inteiro é um campo ({link}): é uma URL pronta, vai sem codificar (safeUrl confere o esquema).
        var whole = /^\{([a-zA-Z0-9_.]+)\}$/.exec(str(template).trim());

        if (whole) {
            return str(getPath(item, whole[1]));
        }

        return str(template).replace(/\{([a-zA-Z0-9_.]+)\}/g, function (m, path) {
            return encodeURIComponent(str(getPath(item, path)));
        });
    }

    // Mesmo, sem codificar (textos: confirmações, títulos)
    function fillText(template, item) {
        return str(template).replace(/\{([a-zA-Z0-9_.]+)\}/g, function (m, path) {
            return str(getPath(item, path));
        });
    }

    function addClasses(el, names) {
        str(names).split(/\s+/).filter(Boolean).forEach(function (name) {
            el.classList.add(name);
        });
    }

    function removeClasses(el, names) {
        str(names).split(/\s+/).filter(Boolean).forEach(function (name) {
            el.classList.remove(name);
        });
    }

    function emit(el, name, detail, cancelable) {
        var event = new window.CustomEvent(name, { bubbles: true, cancelable: !!cancelable, detail: detail });

        return el.dispatchEvent(event);
    }

    function message(name, values) {
        var text = config.messages[name] || '';

        // Chaves maiores primeiro: ':total' antes de ':to'.
        Object.keys(values || {}).sort(function (a, b) { return b.length - a.length; }).forEach(function (key) {
            text = text.split(':' + key).join(str(values[key]));
        });

        return text;
    }

    function vigia() {
        if (!window.Vigia || !window.Vigia.http) {
            throw new Error('Lista.js precisa do Vigia.js (e do axios) carregados antes.');
        }

        return window.Vigia;
    }

    // ------------------------------------------------------------------
    // Formatos (data-format)
    // ------------------------------------------------------------------

    var moneyFormat = typeof window.Intl !== 'undefined' ? new window.Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }) : null;
    var numberFormat = typeof window.Intl !== 'undefined' ? new window.Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : null;

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    /*
     * Datas do banco são UTC (DateHelper): 'Y-m-d H:i:s' é convertida para
     * o fuso do navegador; 'Y-m-d' (só data) não muda de dia.
     */
    function parseDate(value) {
        var text = str(value).trim();
        var onlyDate = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);

        if (onlyDate) {
            return { date: new Date(+onlyDate[1], +onlyDate[2] - 1, +onlyDate[3]), dateOnly: true };
        }

        var sql = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/.exec(text);

        if (sql) {
            return { date: new Date(Date.UTC(+sql[1], +sql[2] - 1, +sql[3], +sql[4], +sql[5], +(sql[6] || 0))), dateOnly: false };
        }

        var parsed = new Date(text);

        return isNaN(parsed.getTime()) ? null : { date: parsed, dateOnly: false };
    }

    var FORMATS = {
        text: function (v) { return str(v); },
        money: function (v) {
            var n = parseFloat(v);

            if (isNaN(n)) {
                return '';
            }

            return moneyFormat ? moneyFormat.format(n) : 'R$ ' + n.toFixed(2).replace('.', ',');
        },
        number: function (v) {
            var n = parseFloat(v);

            return isNaN(n) ? '' : (numberFormat ? numberFormat.format(n) : n.toFixed(2).replace('.', ','));
        },
        integer: function (v) {
            var n = parseInt(v, 10);

            return isNaN(n) ? '' : n.toLocaleString('pt-BR');
        },
        date: function (v) {
            var p = parseDate(v);

            return p ? pad(p.date.getDate()) + '/' + pad(p.date.getMonth() + 1) + '/' + p.date.getFullYear() : '';
        },
        datetime: function (v) {
            var p = parseDate(v);

            if (!p) {
                return '';
            }

            return FORMATS.date(v) + (p.dateOnly ? '' : ' ' + pad(p.date.getHours()) + ':' + pad(p.date.getMinutes()));
        },
        boolean: function (v) {
            return v === true || v === 1 || v === '1' || v === 'true' ? 'Sim' : 'Não';
        },
        cpf_cnpj: function (v) {
            return window.Vigia && window.Vigia.mask ? window.Vigia.mask.format(str(v), 'cpf_cnpj') : str(v);
        },
        phone: function (v) {
            return window.Vigia && window.Vigia.mask ? window.Vigia.mask.format(str(v), 'phone') : str(v);
        }
    };

    function format(value, name, item) {
        if (!name) {
            return str(value);
        }

        return own(FORMATS, name) ? str(FORMATS[name](value, item)) : str(value);
    }

    function own(object, key) {
        return Object.prototype.hasOwnProperty.call(object, key);
    }

    // ------------------------------------------------------------------
    // Lista
    // ------------------------------------------------------------------

    function ListaInstance(el, options) {
        this.el = el;
        this.options = extend({}, options || {});
        this.items = [];
        this.meta = {};
        this.response = null;
        this.loading = false;
        this.error = null;
        this.selection = {};
        this._controller = null;
        this._sequence = 0;
        this._timer = null;
        this._listeners = {};

        var data = el.dataset || {};

        this.url = this.options.url || data.lista || el.getAttribute('data-lista') || '';
        this.prefix = this.options.prefix !== undefined ? this.options.prefix : (data.listaPrefix || '');
        this.idField = this.options.idField || data.listaId || 'id';
        this.useHistory = this.options.history !== undefined ? this.options.history : (data.listaHistory !== undefined ? data.listaHistory !== 'false' : config.history);

        this.state = {
            page: 1,
            limit: parseInt(this.options.limit || data.listaLimit || config.limit, 10),
            sort: this.options.sort || data.listaSort || '',
            direction: (this.options.direction || data.listaDirection || 'ASC').toUpperCase(),
            filters: extend({}, this.options.filters || {})
        };

        this._defaults = JSON.parse(JSON.stringify(this.state));

        this.parts = {
            items: el.querySelector('[data-lista-items]'),
            template: el.querySelector('template[data-lista-template]'),
            filters: el.querySelector('[data-lista-filters]'),
            pagination: el.querySelector('[data-lista-pagination]'),
            empty: el.querySelector('[data-lista-empty]'),
            error: el.querySelector('[data-lista-error]'),
            loadingBox: el.querySelector('[data-lista-loading]'),
            summary: el.querySelector('[data-lista-summary]'),
            limitSelect: el.querySelector('[data-lista-limit-select]'),
            selectAll: el.querySelector('[data-select-all]'),
            batch: el.querySelector('[data-lista-batch]'),
            selectedCount: el.querySelector('[data-lista-selected-count]')
        };

        if (!this.parts.items) {
            throw new Error('Lista.js: falta o elemento [data-lista-items] (o <tbody> ou a lista onde os itens entram).');
        }

        this._readUrl();
        this._writeFiltersToForm();
        this._bind();
        this._renderSortHeaders();

        if (this.parts.limitSelect) {
            this.parts.limitSelect.value = String(this.state.limit);
        }

        if (this.options.autoLoad !== false) {
            this.load();
        }
    }

    ListaInstance.prototype.on = function (name, callback) {
        (this._listeners[name] = this._listeners[name] || []).push(callback);

        return this;
    };

    ListaInstance.prototype._trigger = function (name, detail, cancelable) {
        var callbackName = 'on' + name.charAt(0).toUpperCase() + name.slice(1);
        var result;

        if (typeof this.options[callbackName] === 'function') {
            result = this.options[callbackName].call(this, detail, this);
        }

        (this._listeners[name] || []).forEach(function (callback) {
            callback.call(this, detail, this);
        }, this);

        var allowed = emit(this.el, 'lista:' + name, extend({ lista: this }, detail), cancelable);

        return result !== false && allowed;
    };

    // Parâmetros da requisição: page, limit, sort, direction + filtros.
    ListaInstance.prototype.params = function () {
        var p = config.params;
        var params = {};

        params[p.page] = this.state.page;
        params[p.limit] = this.state.limit;

        if (this.state.sort) {
            params[p.sort] = this.state.sort;
            params[p.direction] = this.state.direction;
        }

        Object.keys(this.state.filters).forEach(function (key) {
            var value = this.state.filters[key];

            if (value !== '' && value !== null && value !== undefined && !(Array.isArray(value) && !value.length)) {
                params[key] = value;
            }
        }, this);

        return extend(params, typeof this.options.params === 'function' ? this.options.params(this) : this.options.params);
    };

    /*
     * Busca a página atual. Uma requisição em andamento é cancelada:
     * a última busca digitada sempre vence.
     */
    ListaInstance.prototype.load = function () {
        var self = this;
        var sequence = ++this._sequence;

        if (this._controller) {
            this._controller.abort();
        }

        this._controller = typeof window.AbortController !== 'undefined' ? new window.AbortController() : null;

        var params = this.params();

        if (this._trigger('before', { params: params }, true) === false) {
            return Promise.resolve(null);
        }

        this._setLoading(true);
        this._writeUrl();

        return vigia().http.get(this.url, params, this._controller ? { signal: this._controller.signal } : {}).then(function (response) {
            if (sequence !== self._sequence) {
                return null;
            }

            var paginacao = response.meta && response.meta.paginacao ? response.meta.paginacao : null;

            self.response = response;
            self.items = Array.isArray(response.data) ? response.data : [];
            self.meta = paginacao || { total: self.items.length, por_pagina: self.state.limit, pagina_atual: 1, total_paginas: 1 };
            self.error = null;

            // Página além do fim (ex.: excluiu o último item da última página): volta uma.
            if (!self.items.length && self.meta.total > 0 && self.state.page > 1 && self.state.page > self.meta.total_paginas) {
                self.state.page = Math.max(1, self.meta.total_paginas);

                return self.load();
            }

            self._render();
            self._setLoading(false);
            self._trigger('loaded', { items: self.items, meta: self.meta, response: response });

            return response;
        }, function (error) {
            if (error && error.isCancel) {
                return null;
            }

            if (sequence !== self._sequence) {
                return null;
            }

            self.error = error;
            self._setLoading(false);
            self._renderError(error);
            self._trigger('error', { error: error });

            return null;
        });
    };

    ListaInstance.prototype.reload = function () {
        return this.load();
    };

    ListaInstance.prototype.page = function (page) {
        this.state.page = Math.max(1, parseInt(page, 10) || 1);

        return this.load();
    };

    // Ordena por um campo; sem direção, alterna (ASC -> DESC) no mesmo campo.
    ListaInstance.prototype.sort = function (field, direction) {
        if (!direction) {
            direction = this.state.sort === field && this.state.direction === 'ASC' ? 'DESC' : 'ASC';
        }

        this.state.sort = field;
        this.state.direction = String(direction).toUpperCase() === 'DESC' ? 'DESC' : 'ASC';
        this.state.page = 1;
        this._renderSortHeaders();

        return this.load();
    };

    ListaInstance.prototype.limit = function (limit) {
        this.state.limit = Math.max(1, parseInt(limit, 10) || config.limit);
        this.state.page = 1;

        return this.load();
    };

    // Junta filtros ({ status: 'pago' }); null/'' remove. Volta para a página 1.
    ListaInstance.prototype.filter = function (filters) {
        Object.keys(filters || {}).forEach(function (key) {
            var value = filters[key];

            if (value === null || value === undefined || value === '') {
                delete this.state.filters[key];
            } else {
                this.state.filters[key] = value;
            }
        }, this);

        this.state.page = 1;
        this.clearSelection();

        return this.load();
    };

    // Volta aos filtros, ordem e tamanho iniciais.
    ListaInstance.prototype.reset = function () {
        this.state = JSON.parse(JSON.stringify(this._defaults));

        if (this.parts.filters && typeof this.parts.filters.reset === 'function') {
            this.parts.filters.reset();
        }

        this._writeFiltersToForm();
        this._renderSortHeaders();
        this.clearSelection();

        return this.load();
    };

    ListaInstance.prototype.selected = function () {
        var self = this;

        return Object.keys(this.selection).map(function (key) {
            return self.selection[key];
        });
    };

    ListaInstance.prototype.selectedIds = function () {
        var self = this;

        return this.selected().map(function (item) {
            return getPath(item, self.idField);
        });
    };

    ListaInstance.prototype.clearSelection = function () {
        this.selection = {};
        this._renderSelection();
    };

    /*
     * Executa uma ação no servidor (excluir, mudar status). item: um item
     * (ação de linha) ou null (lote: manda os ids selecionados em ids[]).
     */
    ListaInstance.prototype.action = function (options, item) {
        var self = this;
        var ids = item ? [getPath(item, this.idField)] : this.selectedIds();
        var url = item ? fillUrl(options.url, item) : options.url;
        var method = str(options.method || 'post').toLowerCase();
        var confirmText = item ? fillText(options.confirm, item) : str(options.confirm).split(':count').join(String(ids.length));

        if (!url) {
            return Promise.reject(new Error('Lista.js: ação sem data-url.'));
        }

        if (!item && !ids.length) {
            return Promise.resolve(null);
        }

        if (confirmText && !window.confirm(confirmText)) {
            return Promise.resolve(null);
        }

        var detail = { name: options.name, item: item, ids: ids };

        if (this._trigger('action', detail, true) === false) {
            return Promise.resolve(null);
        }

        var body = extend({}, options.data || {});

        if (!item) {
            body.ids = ids;
        }

        // PUT/PATCH/DELETE vão em JSON: o PHP só lê corpo de formulário em POST
        // (no controller, leia com InputHelper::request() ou json()).
        var request = method === 'get'
            ? vigia().http.get(url, body)
            : vigia().http.request({ url: url, method: method, data: body, json: method !== 'post' });

        return request.then(function (response) {
            self._notify(response.message, 'success');
            self._trigger('actionDone', extend({ response: response }, detail));

            if (!item) {
                self.clearSelection();
            }

            if (options.reload !== false) {
                return self.load().then(function () {
                    return response;
                });
            }

            return response;
        }, function (error) {
            self._notify(error.message, 'error');
            self._trigger('actionError', extend({ error: error }, detail));

            return null;
        });
    };

    ListaInstance.prototype.destroy = function () {
        if (this._controller) {
            this._controller.abort();
        }

        this.el.removeEventListener('click', this._onClick);
        this.el.removeEventListener('input', this._onInput);
        this.el.removeEventListener('change', this._onChange);
        this.el.removeEventListener('submit', this._onSubmit);
        window.removeEventListener('popstate', this._onPopState);
        delete this.el.__lista;
    };

    // ------------------------------------------------------------------
    // Eventos do DOM
    // ------------------------------------------------------------------

    ListaInstance.prototype._bind = function () {
        var self = this;

        this._onClick = function (event) {
            var target = event.target;
            var link = target.closest('[data-lista-page]');

            if (link && self.el.contains(link)) {
                event.preventDefault();

                if (!link.closest('.' + config.classes.disabled.split(' ')[0])) {
                    self.page(link.getAttribute('data-lista-page'));
                }

                return;
            }

            var header = target.closest('[data-sort]');

            if (header && self.el.contains(header) && !target.closest('input, select, textarea')) {
                event.preventDefault();
                self.sort(header.getAttribute('data-sort'));

                return;
            }

            var actionEl = target.closest('[data-action], [data-batch-action]');

            if (actionEl && self.el.contains(actionEl)) {
                event.preventDefault();

                var isBatch = actionEl.hasAttribute('data-batch-action');
                var row = actionEl.closest('[data-lista-index]');
                var item = !isBatch && row ? self.items[parseInt(row.getAttribute('data-lista-index'), 10)] : null;
                var name = actionEl.getAttribute(isBatch ? 'data-batch-action' : 'data-action');
                var handler = self.options.actions && self.options.actions[name];

                if (typeof handler === 'function') {
                    handler.call(self, isBatch ? self.selected() : item, self, actionEl);

                    return;
                }

                self.action({
                    name: name,
                    url: actionEl.getAttribute('data-url'),
                    method: actionEl.getAttribute('data-method'),
                    confirm: actionEl.getAttribute('data-confirm'),
                    reload: actionEl.getAttribute('data-reload') !== 'false'
                }, isBatch ? null : item);

                return;
            }

            if (target.closest('[data-lista-retry]')) {
                event.preventDefault();
                self.load();

                return;
            }

            if (target.closest('[data-lista-reset]')) {
                event.preventDefault();
                self.reset();
            }
        };

        this._onInput = function (event) {
            var field = event.target;

            if (!self.parts.filters || !self.parts.filters.contains(field) || !field.name) {
                return;
            }

            var type = str(field.type).toLowerCase();

            // Texto: espera a pessoa parar de digitar. O resto vai no change.
            if (type === 'text' || type === 'search' || field.tagName === 'TEXTAREA' || type === 'email' || type === 'tel' || type === '') {
                window.clearTimeout(self._timer);
                self._timer = window.setTimeout(function () {
                    self._applyFormFilters();
                }, field.hasAttribute('data-debounce') ? parseInt(field.getAttribute('data-debounce'), 10) : config.debounce);
            }
        };

        this._onChange = function (event) {
            var field = event.target;

            if (field.hasAttribute('data-select-all')) {
                self._toggleAll(field.checked);

                return;
            }

            if (field.hasAttribute('data-select')) {
                var row = field.closest('[data-lista-index]');
                var item = row ? self.items[parseInt(row.getAttribute('data-lista-index'), 10)] : null;

                if (item) {
                    var id = String(getPath(item, self.idField));

                    if (field.checked) {
                        self.selection[id] = item;
                    } else {
                        delete self.selection[id];
                    }

                    self._renderSelection();
                }

                return;
            }

            if (self.parts.limitSelect && field === self.parts.limitSelect) {
                self.limit(field.value);

                return;
            }

            if (self.parts.filters && self.parts.filters.contains(field) && field.name) {
                window.clearTimeout(self._timer);
                self._applyFormFilters();
            }
        };

        // Enter no campo de busca: aplica já (sem recarregar a página).
        this._onSubmit = function (event) {
            if (self.parts.filters && event.target === self.parts.filters) {
                event.preventDefault();
                window.clearTimeout(self._timer);
                self._applyFormFilters();
            }
        };

        this._onPopState = function () {
            self._readUrl();
            self._writeFiltersToForm();
            self._renderSortHeaders();
            self.load();
        };

        this.el.addEventListener('click', this._onClick);
        this.el.addEventListener('input', this._onInput);
        this.el.addEventListener('change', this._onChange);
        this.el.addEventListener('submit', this._onSubmit);

        if (this.useHistory) {
            window.addEventListener('popstate', this._onPopState);
        }
    };

    // Lê o formulário de filtros (mesmas regras de nomes do Vigia: x[] vira lista).
    ListaInstance.prototype._formFilters = function () {
        var filters = {};
        var form = this.parts.filters;

        if (!form) {
            return filters;
        }

        var fields = form.elements ? Array.from(form.elements) : Array.from(form.querySelectorAll('input, select, textarea'));

        fields.forEach(function (field) {
            var type = str(field.type).toLowerCase();

            if (!field.name || field.disabled || ['button', 'submit', 'reset', 'file'].indexOf(type) !== -1) {
                return;
            }

            var name = field.name.replace(/\[\]$/, '');
            var isList = /\[\]$/.test(field.name) || (field.tagName === 'SELECT' && field.multiple);

            if (isList && !own(filters, name)) {
                filters[name] = [];
            }

            if ((type === 'checkbox' || type === 'radio') && !field.checked) {
                return;
            }

            var value = field.tagName === 'SELECT' && field.multiple
                ? Array.from(field.options).filter(function (o) { return o.selected; }).map(function (o) { return o.value; })
                : (field.__vigiaMask && field.hasAttribute('data-unmask') && window.Vigia ? window.Vigia.mask.unmask(field.value, field.__vigiaMask) : field.value);

            if (isList) {
                filters[name] = filters[name].concat(value);
            } else {
                filters[name] = value;
            }
        });

        return filters;
    };

    ListaInstance.prototype._applyFormFilters = function () {
        var formFilters = this._formFilters();
        var changes = {};

        Object.keys(formFilters).forEach(function (key) {
            changes[key] = formFilters[key];
        });

        // Campos esvaziados saem do estado.
        Object.keys(this.state.filters).forEach(function (key) {
            if (!own(formFilters, key) && this._formHasField(key)) {
                changes[key] = null;
            }
        }, this);

        if (JSON.stringify(this._cleanFilters(extend({}, this.state.filters, changes))) === JSON.stringify(this._cleanFilters(this.state.filters))) {
            return;
        }

        this.filter(changes);
    };

    ListaInstance.prototype._formHasField = function (key) {
        return !!(this.parts.filters && this.parts.filters.querySelector('[name="' + key + '"], [name="' + key + '[]"]'));
    };

    ListaInstance.prototype._cleanFilters = function (filters) {
        var clean = {};

        Object.keys(filters).sort().forEach(function (key) {
            var value = filters[key];

            if (value !== null && value !== undefined && value !== '' && !(Array.isArray(value) && !value.length)) {
                clean[key] = value;
            }
        });

        return clean;
    };

    ListaInstance.prototype._writeFiltersToForm = function () {
        var form = this.parts.filters;
        var filters = this.state.filters;

        if (!form) {
            return;
        }

        Array.from(form.querySelectorAll('input, select, textarea')).forEach(function (field) {
            if (!field.name) {
                return;
            }

            var name = field.name.replace(/\[\]$/, '');
            var type = str(field.type).toLowerCase();

            if (!own(filters, name)) {
                return;
            }

            var value = filters[name];
            var values = Array.isArray(value) ? value.map(String) : [str(value)];

            if (type === 'checkbox' || type === 'radio') {
                field.checked = values.indexOf(field.value) !== -1;
            } else if (field.tagName === 'SELECT' && field.multiple) {
                Array.from(field.options).forEach(function (o) {
                    o.selected = values.indexOf(o.value) !== -1;
                });
            } else {
                field.value = values[0];

                if (field.__vigiaMask && window.Vigia) {
                    field.value = window.Vigia.mask.format(field.value, field.__vigiaMask);
                }
            }
        });
    };

    // ------------------------------------------------------------------
    // URL (F5 e "voltar")
    // ------------------------------------------------------------------

    ListaInstance.prototype._key = function (name) {
        return this.prefix ? this.prefix + '_' + name : name;
    };

    ListaInstance.prototype._readUrl = function () {
        if (!this.useHistory || !window.URLSearchParams) {
            return;
        }

        var query = new window.URLSearchParams(window.location.search);
        var p = config.params;
        var self = this;
        var page = parseInt(query.get(this._key(p.page)), 10);
        var limit = parseInt(query.get(this._key(p.limit)), 10);

        this.state = JSON.parse(JSON.stringify(this._defaults || this.state));

        if (page > 0) {
            this.state.page = page;
        }

        if (limit > 0) {
            this.state.limit = limit;
        }

        if (query.get(this._key(p.sort))) {
            this.state.sort = query.get(this._key(p.sort));
            this.state.direction = str(query.get(this._key(p.direction))).toUpperCase() === 'DESC' ? 'DESC' : 'ASC';
        }

        var reserved = [p.page, p.limit, p.sort, p.direction].map(function (name) {
            return self._key(name);
        });
        var filterPrefix = this._key('f_');

        query.forEach(function (value, key) {
            if (reserved.indexOf(key) !== -1 || key.indexOf(filterPrefix) !== 0) {
                return;
            }

            var name = key.slice(filterPrefix.length).replace(/\[\]$/, '');

            if (/\[\]$/.test(key)) {
                self.state.filters[name] = (Array.isArray(self.state.filters[name]) ? self.state.filters[name] : []).concat(value);
            } else {
                self.state.filters[name] = value;
            }
        });
    };

    // Só os valores diferentes do padrão vão para a URL; o resto da query (option, view...) é mantido.
    ListaInstance.prototype._writeUrl = function () {
        if (!this.useHistory || !window.history || !window.history.replaceState || !window.URLSearchParams) {
            return;
        }

        var query = new window.URLSearchParams(window.location.search);
        var p = config.params;
        var self = this;
        var filterPrefix = this._key('f_');

        Array.from(query.keys()).forEach(function (key) {
            if (key.indexOf(filterPrefix) === 0 || [p.page, p.limit, p.sort, p.direction].map(function (n) { return self._key(n); }).indexOf(key) !== -1) {
                query.delete(key);
            }
        });

        if (this.state.page > 1) {
            query.set(this._key(p.page), this.state.page);
        }

        if (this.state.limit !== this._defaults.limit) {
            query.set(this._key(p.limit), this.state.limit);
        }

        if (this.state.sort && (this.state.sort !== this._defaults.sort || this.state.direction !== this._defaults.direction)) {
            query.set(this._key(p.sort), this.state.sort);
            query.set(this._key(p.direction), this.state.direction);
        }

        var clean = this._cleanFilters(this.state.filters);

        Object.keys(clean).forEach(function (key) {
            if (Array.isArray(clean[key])) {
                clean[key].forEach(function (v) {
                    query.append(filterPrefix + key + '[]', v);
                });
            } else {
                query.set(filterPrefix + key, clean[key]);
            }
        });

        var search = query.toString();
        var url = window.location.pathname + (search ? '?' + search : '') + window.location.hash;

        if (url !== window.location.pathname + window.location.search + window.location.hash) {
            window.history.pushState(null, '', url);
        }
    };

    // ------------------------------------------------------------------
    // Renderização
    // ------------------------------------------------------------------

    ListaInstance.prototype._setLoading = function (loading) {
        this.loading = loading;

        if (loading) {
            addClasses(this.el, config.classes.loading);
            this.el.setAttribute('aria-busy', 'true');
        } else {
            removeClasses(this.el, config.classes.loading);
            this.el.removeAttribute('aria-busy');
        }

        if (this.parts.loadingBox) {
            this.parts.loadingBox.hidden = !loading;
        }

        this._trigger('state', { loading: loading });
    };

    ListaInstance.prototype._render = function () {
        var self = this;
        var container = this.parts.items;
        var fragment = document.createDocumentFragment();

        this.items.forEach(function (item, index) {
            var node = self._renderItem(item, index);

            if (node) {
                fragment.appendChild(node);
            }
        });

        container.textContent = '';
        container.appendChild(fragment);

        if (this.parts.error) {
            this.parts.error.hidden = true;
        }

        if (this.parts.empty) {
            this.parts.empty.hidden = this.items.length > 0;
        }

        this._renderPagination();
        this._renderSummary();
        this._renderSelection();
    };

    /*
     * Preenche o <template data-lista-template> com o item:
     *   data-field="cliente.nome"            texto (sempre escapado)
     *   data-format="money|date|datetime..." formato do texto
     *   data-href / data-src / data-title / data-value  com {campo}
     *   data-attr-NOME="... {campo} ..."     qualquer atributo
     *   data-if="campo" / data-unless="campo" mostra/esconde
     *   data-class-NOME="campo"              classe se o campo for verdadeiro
     * Ou options.renderItem(item, index) devolvendo um elemento.
     */
    ListaInstance.prototype._renderItem = function (item, index) {
        var node;

        if (typeof this.options.renderItem === 'function') {
            node = this.options.renderItem.call(this, item, index);
        } else if (this.parts.template) {
            var clone = this.parts.template.content.cloneNode(true);
            node = clone.firstElementChild;

            if (!node) {
                return null;
            }

            fillNode(node, item);
        } else {
            throw new Error('Lista.js: defina <template data-lista-template> ou a opção renderItem.');
        }

        node.setAttribute('data-lista-index', String(index));

        var id = String(getPath(item, this.idField));
        var checkbox = node.querySelector('[data-select]');

        if (checkbox) {
            checkbox.value = id;
            checkbox.checked = own(this.selection, id);
            checkbox.setAttribute('aria-label', checkbox.getAttribute('aria-label') || 'Selecionar ' + id);
        }

        if (typeof this.options.onItem === 'function') {
            this.options.onItem.call(this, item, node, index);
        }

        return node;
    };

    function fillNode(root, item) {
        var all = [root].concat(Array.from(root.querySelectorAll('*')));

        all.forEach(function (el) {
            if (el.hasAttribute('data-if')) {
                if (!truthy(getPath(item, el.getAttribute('data-if')))) {
                    el.parentNode ? el.parentNode.removeChild(el) : (el.hidden = true);
                    return;
                }
            }

            if (el.hasAttribute('data-unless')) {
                if (truthy(getPath(item, el.getAttribute('data-unless')))) {
                    el.parentNode ? el.parentNode.removeChild(el) : (el.hidden = true);
                    return;
                }
            }

            if (el.hasAttribute('data-field')) {
                el.textContent = format(getPath(item, el.getAttribute('data-field')), el.getAttribute('data-format'), item);
            }

            ['href', 'src', 'title', 'value'].forEach(function (attr) {
                if (el.hasAttribute('data-' + attr) && !(attr === 'value' && el.hasAttribute('data-select'))) {
                    var raw = el.getAttribute('data-' + attr);
                    var value = attr === 'href' || attr === 'src' ? safeUrl(fillUrl(raw, item)) : fillText(raw, item);

                    el.setAttribute(attr, value);
                }
            });

            Array.from(el.attributes).forEach(function (attribute) {
                if (attribute.name.indexOf('data-attr-') === 0) {
                    var name = attribute.name.slice(10);

                    if (/^on/i.test(name)) {
                        return;   // nunca cria manipuladores de evento a partir de dados
                    }

                    el.setAttribute(name, name === 'href' || name === 'src' ? safeUrl(fillUrl(attribute.value, item)) : fillText(attribute.value, item));
                } else if (attribute.name.indexOf('data-class-') === 0) {
                    if (truthy(getPath(item, attribute.value))) {
                        el.classList.add(attribute.name.slice(11));
                    }
                }
            });
        });
    }

    function truthy(value) {
        return !(value === null || value === undefined || value === false || value === 0 || value === '0' || value === '' || (Array.isArray(value) && !value.length));
    }

    // Bloqueia javascript:, data: e afins em links montados com dados.
    function safeUrl(url) {
        return /^\s*(javascript|data|vbscript):/i.test(url) ? '#' : url;
    }

    ListaInstance.prototype._paginationStyle = function () {
        var nav = this.parts.pagination;
        var attribute = nav ? nav.getAttribute('data-lista-pagination') : '';

        return this.options.pagination || attribute || config.paginationStyle;
    };

    ListaInstance.prototype._renderPagination = function () {
        var nav = this.parts.pagination;

        if (!nav) {
            return;
        }

        if (this._paginationStyle() === 'jpaginate') {
            this._renderJPaginate();

            return;
        }

        var current = this.meta.pagina_atual || this.state.page;
        var total = this.meta.total_paginas || 0;
        var classes = config.classes;

        nav.textContent = '';

        if (total <= 1) {
            nav.hidden = true;
            return;
        }

        nav.hidden = false;

        if (!nav.getAttribute('aria-label')) {
            nav.setAttribute('aria-label', 'Paginação');
        }

        var list = document.createElement('ul');
        addClasses(list, classes.pagination);

        var add = function (label, page, options) {
            options = options || {};

            var li = document.createElement('li');
            addClasses(li, classes.pageItem);

            var link = document.createElement(options.disabled || options.gap ? 'span' : 'a');
            addClasses(link, classes.pageLink);
            link.textContent = label;

            if (options.ariaLabel) {
                link.setAttribute('aria-label', options.ariaLabel);
            }

            if (options.active) {
                addClasses(li, classes.active);
                link.setAttribute('aria-current', 'page');
            }

            if (options.disabled || options.gap) {
                addClasses(li, classes.disabled);
            } else {
                link.href = '#';
                link.setAttribute('data-lista-page', String(page));
            }

            li.appendChild(link);
            list.appendChild(li);
        };

        add('«', 1, { disabled: current <= 1, ariaLabel: config.messages.first });
        add('‹', current - 1, { disabled: current <= 1, ariaLabel: config.messages.previous });

        var around = config.pagesAround;
        var start = Math.max(1, current - around);
        var end = Math.min(total, current + around);

        if (start > 1) {
            add('1', 1);

            if (start > 2) {
                add('…', 0, { gap: true });
            }
        }

        for (var page = start; page <= end; page++) {
            add(String(page), page, { active: page === current });
        }

        if (end < total) {
            if (end < total - 1) {
                add('…', 0, { gap: true });
            }

            add(String(total), total);
        }

        add('›', current + 1, { disabled: current >= total, ariaLabel: config.messages.next });
        add('»', total, { disabled: current >= total, ariaLabel: config.messages.last });

        nav.appendChild(list);
    };

    /*
     * Estilo jQuery Paginate: [Primeira] [‹] 4 5 [6] 7 8 [›] [Última].
     * As setas só deslizam a faixa (sem requisição; segurar desliza
     * contínuo); clicar num número busca aquela página no servidor.
     * Depois de cada carga, a faixa centraliza a página atual.
     */
    ListaInstance.prototype._renderJPaginate = function () {
        var self = this;
        var nav = this.parts.pagination;
        var current = this.meta.pagina_atual || this.state.page;
        var total = this.meta.total_paginas || 0;
        var display = this._jpDisplay();

        nav.textContent = '';

        if (total <= 1) {
            nav.hidden = true;
            return;
        }

        nav.hidden = false;

        if (!nav.getAttribute('aria-label')) {
            nav.setAttribute('aria-label', 'Paginação');
        }

        // Centraliza a página atual na faixa.
        this._jpStart = Math.max(1, Math.min(current - Math.floor(display / 2), total - display + 1));

        var box = document.createElement('div');
        box.className = 'lista-jp';

        var edge = function (label, page, disabled, cls) {
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'lista-jp-edge ' + cls;
            button.textContent = label;
            button.disabled = disabled;

            if (!disabled) {
                button.setAttribute('data-lista-page', String(page));
            }

            return button;
        };

        var arrow = function (direction) {
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'lista-jp-arrow ' + (direction < 0 ? 'lista-jp-arrow-prev' : 'lista-jp-arrow-next');
            button.textContent = direction < 0 ? '‹' : '›';
            button.setAttribute('aria-label', direction < 0 ? config.messages.slidePrevious : config.messages.slideNext);
            self._bindJpArrow(button, direction);

            return button;
        };

        this._jpPrev = arrow(-1);
        this._jpNext = arrow(1);
        this._jpList = document.createElement('ol');
        this._jpList.className = 'lista-jp-pages';

        var strip = document.createElement('div');
        strip.className = 'lista-jp-strip';
        strip.appendChild(this._jpList);

        box.appendChild(edge(config.messages.first, 1, current <= 1, 'lista-jp-first'));
        box.appendChild(this._jpPrev);
        box.appendChild(strip);
        box.appendChild(this._jpNext);
        box.appendChild(edge(config.messages.last, total, current >= total, 'lista-jp-last'));

        var status = document.createElement('span');
        status.className = 'lista-jp-status';
        status.setAttribute('aria-live', 'polite');
        status.textContent = message('pageOf', { page: current.toLocaleString('pt-BR'), total: total.toLocaleString('pt-BR') });
        box.appendChild(status);

        nav.appendChild(box);
        this._renderJpPages();
    };

    ListaInstance.prototype._jpDisplay = function () {
        var nav = this.parts.pagination;
        var fromAttribute = nav ? parseInt(nav.getAttribute('data-lista-display'), 10) : NaN;
        var display = this.options.display || (fromAttribute > 0 ? fromAttribute : config.jpaginate.display);

        return Math.max(3, parseInt(display, 10) || 7);
    };

    // Redesenha só os números (deslizar não refaz a navegação inteira nem busca nada).
    ListaInstance.prototype._renderJpPages = function () {
        var list = this._jpList;
        var current = this.meta.pagina_atual || this.state.page;
        var total = this.meta.total_paginas || 0;
        var display = Math.min(this._jpDisplay(), total);
        var start = this._jpStart;

        list.textContent = '';

        for (var page = start; page < start + display; page++) {
            var li = document.createElement('li');
            var button = document.createElement('button');

            button.type = 'button';
            button.className = 'lista-jp-page';
            button.textContent = page.toLocaleString('pt-BR');

            if (page === current) {
                li.className = 'lista-jp-current';
                button.setAttribute('aria-current', 'page');
                button.disabled = true;
            } else {
                button.setAttribute('data-lista-page', String(page));
                button.setAttribute('aria-label', message('pageOf', { page: page, total: total }));
            }

            li.appendChild(button);
            list.appendChild(li);
        }

        this._jpPrev.disabled = start <= 1;
        this._jpNext.disabled = start + display - 1 >= total;
    };

    ListaInstance.prototype._jpShift = function (direction) {
        var total = this.meta.total_paginas || 0;
        var display = Math.min(this._jpDisplay(), total);
        var next = Math.max(1, Math.min(this._jpStart + direction, total - display + 1));

        if (next === this._jpStart) {
            return false;
        }

        this._jpStart = next;
        this._renderJpPages();

        return true;
    };

    // Clique desliza 1 página; segurar (mouse, toque ou Enter/Espaço) desliza contínuo.
    ListaInstance.prototype._bindJpArrow = function (button, direction) {
        var self = this;
        var delay = null;
        var repeat = null;
        var held = false;

        var stop = function () {
            window.clearTimeout(delay);
            window.clearInterval(repeat);
            delay = repeat = null;
        };

        var start = function (event) {
            if (button.disabled || (event.button !== undefined && event.button !== 0)) {
                return;
            }

            held = false;
            stop();
            delay = window.setTimeout(function () {
                held = true;
                repeat = window.setInterval(function () {
                    if (!self._jpShift(direction)) {
                        stop();
                    }
                }, config.jpaginate.holdSpeed);
            }, config.jpaginate.holdDelay);
        };

        button.addEventListener('pointerdown', start);
        ['pointerup', 'pointerleave', 'pointercancel', 'blur'].forEach(function (name) {
            button.addEventListener(name, stop);
        });

        button.addEventListener('click', function () {
            // Depois de segurar, o click final não desliza mais uma.
            if (!held) {
                self._jpShift(direction);
            }

            held = false;
        });
    };

    ListaInstance.prototype._renderSummary = function () {
        if (!this.parts.summary) {
            return;
        }

        var total = this.meta.total || 0;

        if (!total) {
            this.parts.summary.textContent = config.messages.summaryEmpty;
            return;
        }

        var perPage = this.meta.por_pagina || this.state.limit;
        var current = this.meta.pagina_atual || this.state.page;
        var from = (current - 1) * perPage + 1;

        this.parts.summary.textContent = message('summary', {
            from: from.toLocaleString('pt-BR'),
            to: Math.min(total, from + this.items.length - 1).toLocaleString('pt-BR'),
            total: total.toLocaleString('pt-BR')
        });
    };

    ListaInstance.prototype._renderSortHeaders = function () {
        var state = this.state;
        var classes = config.classes;

        Array.from(this.el.querySelectorAll('[data-sort]')).forEach(function (header) {
            var active = header.getAttribute('data-sort') === state.sort;

            removeClasses(header, classes.sortAsc + ' ' + classes.sortDesc);

            if (active) {
                addClasses(header, state.direction === 'DESC' ? classes.sortDesc : classes.sortAsc);
            }

            if (header.tagName === 'TH') {
                header.setAttribute('aria-sort', active ? (state.direction === 'DESC' ? 'descending' : 'ascending') : 'none');
            }

            if (!header.hasAttribute('tabindex') && header.tagName !== 'BUTTON' && header.tagName !== 'A') {
                header.setAttribute('tabindex', '0');
                header.setAttribute('role', header.getAttribute('role') || 'button');
                header.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        header.click();
                    }
                });
            }
        });
    };

    ListaInstance.prototype._toggleAll = function (checked) {
        var self = this;

        this.items.forEach(function (item) {
            var id = String(getPath(item, self.idField));

            if (checked) {
                self.selection[id] = item;
            } else {
                delete self.selection[id];
            }
        });

        this._renderSelection();
    };

    ListaInstance.prototype._renderSelection = function () {
        var self = this;
        var count = Object.keys(this.selection).length;
        var pageIds = this.items.map(function (item) { return String(getPath(item, self.idField)); });
        var selectedOnPage = pageIds.filter(function (id) { return own(self.selection, id); }).length;

        Array.from(this.parts.items.querySelectorAll('[data-select]')).forEach(function (box) {
            box.checked = own(self.selection, box.value);

            var row = box.closest('[data-lista-index]');

            if (row) {
                row.classList.toggle(config.classes.selected, box.checked);
            }
        });

        if (this.parts.selectAll) {
            this.parts.selectAll.checked = pageIds.length > 0 && selectedOnPage === pageIds.length;
            this.parts.selectAll.indeterminate = selectedOnPage > 0 && selectedOnPage < pageIds.length;
        }

        if (this.parts.batch) {
            this.parts.batch.hidden = count === 0;
        }

        if (this.parts.selectedCount) {
            this.parts.selectedCount.textContent = String(count);
        }

        this._trigger('selection', { count: count, ids: this.selectedIds() });
    };

    ListaInstance.prototype._renderError = function (error) {
        var text = (error && error.message) || config.messages.error;

        if (this.parts.error) {
            var target = this.parts.error.querySelector('[data-lista-error-message]') || this.parts.error;

            target.textContent = text;
            this.parts.error.hidden = false;
        } else {
            this._notify(text, 'error');
        }

        if (this.parts.empty) {
            this.parts.empty.hidden = true;
        }
    };

    // Mensagem geral: [data-vigia-message] da lista, ou Joomla.renderMessages.
    ListaInstance.prototype._notify = function (text, type) {
        if (!text) {
            return;
        }

        var box = this.el.querySelector('[data-lista-message]');

        if (box) {
            box.textContent = text;
            box.hidden = false;
            box.className = box.className.replace(/\balert-\w+\b/g, '').trim();
            addClasses(box, 'alert ' + (type === 'error' ? 'alert-danger' : 'alert-success'));
            box.setAttribute('role', type === 'error' ? 'alert' : 'status');

            return;
        }

        if (window.Joomla && typeof window.Joomla.renderMessages === 'function') {
            var payload = {};

            payload[type === 'error' ? 'error' : 'message'] = [text];
            window.Joomla.renderMessages(payload);
        }
    };

    // ------------------------------------------------------------------
    // API pública
    // ------------------------------------------------------------------

    function listaFor(el, options) {
        if (typeof el === 'string') {
            el = document.querySelector(el);
        }

        if (!el) {
            throw new Error('Lista.js: elemento não encontrado.');
        }

        if (!el.__lista) {
            el.__lista = new ListaInstance(el, options);
        } else if (options) {
            extend(el.__lista.options, options);
        }

        return el.__lista;
    }

    function init(root) {
        root = root || document;

        var found = root.querySelectorAll ? Array.from(root.querySelectorAll('[data-lista]')) : [];

        if (root.matches && root.matches('[data-lista]')) {
            found.unshift(root);
        }

        found.forEach(function (el) {
            if (!el.__lista) {
                listaFor(el);
            }
        });
    }

    var Lista = {
        version: VERSION,
        config: function (options) {
            if (options) {
                deepExtend(config, options);
            }

            return config;
        },
        init: init,
        create: listaFor,
        of: listaFor,
        formats: {
            add: function (name, fn) {
                FORMATS[name] = fn;
            },
            format: format
        },
        utils: { fillUrl: fillUrl, fillText: fillText, getPath: getPath, parseDate: parseDate }
    };

    if (document) {
        var start = function () {
            if (config.autoInit) {
                init(document);
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            setTimeout(start, 0);
        }
    }

    return Lista;
}));
