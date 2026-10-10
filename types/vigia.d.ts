/**
 * Tipos do Vigia.js — usados só pelo editor (autocompletar e documentação ao passar o mouse).
 * Não são carregados no navegador.
 *
 * Fonte: js/vigia.js e docs/vigia-js.html.
 */

/** Erros por campo, no formato do ValidationHelper: { 'itens.1.qtd': ['mensagem'] } */
type VigiaErrors = Record<string, string[]>;

type VigiaMessageType = 'success' | 'error' | 'warning' | 'info' | '';

/**
 * Regra de validação. Texto como no PHP ('required|cpf|max_length:150'),
 * lista (['required', 'integer', 'min:1']), objeto ({ in: ['a', 'b'], required: true })
 * ou lista com funções.
 */
type VigiaRule =
    | string
    | Array<string | VigiaRuleFn>
    | Record<string, boolean | string | number | Array<string | number> | VigiaRuleFn>;

/**
 * Função de regra usada direto em `rules`.
 * Devolve true (válido), false (inválido com a mensagem da regra),
 * um texto (inválido com esta mensagem; :field vira o rótulo) ou uma Promise disso.
 * Não roda com o campo vazio.
 * @param valor valor do campo
 * @param dados todos os dados do formulário (getData())
 * @param campo chave do campo (ex.: 'itens.2.quantidade')
 */
type VigiaRuleFn = (valor: any, dados: Record<string, any>, campo: string) => boolean | string | Promise<boolean | string>;

/**
 * Função de regra registrada com Vigia.rules.add (assinatura diferente da de `rules`).
 * @param valor valor do campo
 * @param params parâmetros da regra ('multiplo:5' -> ['5'])
 * @param contexto contexto.data = todos os dados; contexto.field = chave do campo
 */
type VigiaGlobalRuleFn = (
    valor: any,
    params: string[],
    contexto: { data: Record<string, any>; field: string }
) => boolean | string | Promise<boolean | string>;

/** Resposta normalizada do ApiResponseHelper (sucesso). */
interface VigiaResponse<T = any> {
    success: boolean;
    /** 'sucesso', 'aviso', 'info'... (status do ApiResponseHelper) */
    status: string;
    /** Campo "mensagem" do ApiResponseHelper */
    message: string;
    /** Campo "data" do ApiResponseHelper */
    data: T;
    errors: VigiaErrors;
    /** Ex.: { paginacao: {...} } em ApiResponseHelper::paginated() */
    meta: Record<string, any>;
    httpStatus: number;
    /** Para procurar no log do servidor */
    requestId: string | null;
    /** A resposta veio em JSON */
    json: boolean;
    /** Resposta original do axios */
    raw: any;
}

/** Erro rejeitado pelo Vigia.http (4xx/5xx, rede, timeout, cancelamento). */
interface VigiaError extends Error {
    name: 'VigiaError';
    /** 403, 404, 422, 500... (0 sem conexão) */
    httpStatus: number;
    errors: VigiaErrors;
    data: any;
    requestId: string | null;
    response: VigiaResponse | null;
    /** HTTP 422 */
    isValidation: boolean;
    isNetwork: boolean;
    isTimeout: boolean;
    isCancel: boolean;
}

/** Resultado de validate(). */
interface VigiaValidationResult {
    valid: boolean;
    errors: VigiaErrors;
    first_error: string | null;
}

/** Opções extras de uma requisição (repassadas ao axios). */
interface VigiaRequestOptions {
    /** Corpo em JSON em vez de multipart. Atenção: no Joomla 3 o checkToken() não lê JSON. */
    json?: boolean;
    /** Em ms. 0 = sem limite. Padrão 30000. */
    timeout?: number;
    /** AbortController.signal: abort() rejeita com isCancel */
    signal?: AbortSignal;
    onUploadProgress?: (evento: { loaded: number; total?: number }) => void;
    headers?: Record<string, string>;
    /** false: não envia o token CSRF (rota pública / API de terceiros) */
    vigiaToken?: boolean;
    /** true: resolve com a resposta crua do axios, sem normalizar */
    vigiaRaw?: boolean;
    [axiosOption: string]: any;
}

/** Estado enviado ao onState a cada mudança. */
interface VigiaState {
    errors: VigiaErrors;
    loading: boolean;
    progress: number;
    response: VigiaResponse | null;
    message: string;
    messageType: VigiaMessageType;
    valid: boolean;
}

/** Detalhe recebido pelos callbacks (onSuccess...) e por vigia.on(). */
interface VigiaEventDetails {
    /** Validação no navegador falhou. */
    invalid: { errors: VigiaErrors };
    /** Vai enviar. Último ponto para cancelar (preventDefault ou onBefore devolvendo false). */
    before: { data: Record<string, any> };
    /** Resposta de sucesso. data = response.data */
    success: { response: VigiaResponse; data: any };
    /** Falha (4xx/5xx, rede, timeout). */
    error: { error: VigiaError; errors: VigiaErrors };
    /** Sempre, no fim. */
    complete: { response: VigiaResponse | VigiaError | null };
}

/** e.detail dos eventos do DOM (vigia:*): o mesmo detalhe + o formulário e a instância. */
type VigiaDomDetail<K extends keyof VigiaEventDetails> = VigiaEventDetails[K] & { form: HTMLElement; vigia: VigiaForm };

/** Opções de um formulário (Vigia.form(el, opções), JSON do data-vigia ou atributos data-vigia-*). */
interface VigiaFormOptions {
    /** Para onde enviar (padrão: action do form / data-vigia-url / URL atual). */
    url?: string;
    /** 'get' envia como query string (sem arquivos). Padrão 'post'. */
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    /** 'blur' (padrão), 'input' (a cada tecla) ou 'submit' (só no envio). */
    validateOn?: 'blur' | 'input' | 'submit';
    /** 'ajax' (padrão) ou 'native' (valida e deixa o navegador enviar, com recarga). */
    submit?: 'ajax' | 'native';
    /** Texto de confirmação antes de validar e enviar. */
    confirm?: string;
    /** Limpa o formulário depois do sucesso. */
    reset?: boolean;
    /** Navega depois do sucesso (também usa data.redirect da resposta). */
    redirect?: string;
    /** false esconde a mensagem de sucesso. */
    successMessage?: boolean;
    /** 'auto' (padrão), 'joomla' (sempre Joomla.renderMessages) ou 'none'. */
    notify?: 'auto' | 'joomla' | 'none';
    /** Foca e rola até o primeiro campo inválido. Padrão true. */
    focusInvalid?: boolean;
    /** false: não mexe no DOM (você mostra os erros, ex.: com Alpine). */
    render?: boolean;
    /** Regras por chave: { nome: 'required|min_length:3', 'itens.*.qtd': 'required|integer' } */
    rules?: Record<string, VigiaRule>;
    /** Mensagens: { 'email.required': '...', 'itens.*.qtd.min': '...', required: '...' } */
    messages?: Record<string, string>;
    /** Rótulos usados no lugar de :field: { documento: 'CPF ou CNPJ' } */
    labels?: Record<string, string>;
    /** Opções extras do axios no envio. Não passe `data`: substitui o corpo montado pelo Vigia. */
    request?: VigiaRequestOptions;

    /** Validação no navegador falhou. */
    onInvalid?: (this: VigiaForm, detalhe: VigiaEventDetails['invalid'], vigia: VigiaForm) => void;
    /** Vai enviar. Devolva false para cancelar. */
    onBefore?: (this: VigiaForm, detalhe: VigiaEventDetails['before'], vigia: VigiaForm) => void | false;
    /** Resposta de sucesso. */
    onSuccess?: (this: VigiaForm, detalhe: VigiaEventDetails['success'], vigia: VigiaForm) => void;
    /** Falha no envio. */
    onError?: (this: VigiaForm, detalhe: VigiaEventDetails['error'], vigia: VigiaForm) => void;
    /** Sempre, no fim. */
    onComplete?: (this: VigiaForm, detalhe: VigiaEventDetails['complete'], vigia: VigiaForm) => void;
    /** A cada mudança de estado. */
    onState?: (estado: VigiaState) => void;
}

/** Instância de um formulário: Vigia.form('#id'). */
interface VigiaForm {
    /** O <form> ou contêiner [data-vigia-form]. */
    form: HTMLElement;
    options: VigiaFormOptions;
    /** Erros atuais por campo. */
    errors: VigiaErrors;
    /** Envio em andamento. */
    loading: boolean;
    /** % do upload. */
    progress: number;
    /** Última resposta normalizada. */
    response: VigiaResponse | null;
    /** Mensagem geral atual. */
    message: string;
    messageType: VigiaMessageType;

    /** Soma opções novas (regras, mensagens e rótulos são mesclados). */
    configure(options: VigiaFormOptions): VigiaForm;
    /** Lê uma opção: JS > atributo data-vigia-* > padrão. */
    option(name: string, attribute?: string, fallback?: any): any;
    /** Ouve um evento da instância (mesmos nomes dos eventos, sem o prefixo vigia:). */
    on<K extends keyof VigiaEventDetails>(name: K, callback: (detalhe: VigiaEventDetails[K], vigia: VigiaForm) => void): VigiaForm;
    on(name: string, callback: (detalhe: any, vigia: VigiaForm) => void): VigiaForm;

    /** Valida tudo e mostra os erros. */
    validate(): Promise<VigiaValidationResult>;
    /** Valida um campo pela chave ('email', 'itens.2.quantidade'). */
    validateField(key: string): Promise<VigiaValidationResult>;
    /** Valida um campo pelo elemento. */
    validateElement(el: Element): Promise<VigiaValidationResult>;
    /**
     * Valida e envia. Nunca rejeita: resolve com a resposta, com { valid: false, errors }
     * (validação local falhou) ou com null (cancelado / já enviando).
     */
    submit(submitter?: HTMLElement): Promise<VigiaResponse | { valid: false; errors: VigiaErrors } | null>;

    /** Soma aos erros atuais. Devolve as mensagens que não acharam campo. */
    showErrors(errors: VigiaErrors, options?: { focus?: boolean }): string[];
    /** Limpa e mostra estes erros. */
    setErrors(errors: VigiaErrors): void;
    /** Limpa erros e a mensagem geral (keepMessage: true mantém a mensagem). */
    clearErrors(options?: { keepMessage?: boolean }): void;
    /** Mensagem geral. '' esconde. */
    notify(message: string, type?: VigiaMessageType): void;
    focusFirstError(): void;

    /** Dados como o PHP lê o $_POST: { nome: '...', itens: { 0: { qtd: '2' } } }. */
    getData(): Record<string, any>;
    /** FormData pronto para enviar (mesmos nomes e arquivos do envio normal). */
    toFormData(): FormData;
    /** Regras finais por campo (JS + PHP + atributos). Útil para depurar. */
    rules(): Record<string, Array<{ name: string; params: any[] }>>;
    /** Campos do formulário (inclusive os de fora com form="id"). */
    elements(): HTMLElement[];
    /** Chave de um campo: 'itens.0.qtd', 'tags.1'. */
    keyOf(el: Element): string;

    /** form.reset() + limpa erros. */
    reset(): void;
    /** Remove os ouvintes (antes de remover o formulário do DOM). */
    destroy(): void;
}

/** Cliente HTTP (axios configurado para o Joomla: token, X-Requested-With, multipart). */
interface VigiaHttp {
    /** GET. params vira query string: { ids: [1, 2] } -> ids[]=1&ids[]=2 */
    get<T = any>(url: string, params?: Record<string, any>, options?: VigiaRequestOptions): Promise<VigiaResponse<T>>;
    /** POST multipart com o token. Rejeita com VigiaError. */
    post<T = any>(url: string, data?: Record<string, any> | FormData | URLSearchParams, options?: VigiaRequestOptions): Promise<VigiaResponse<T>>;
    put<T = any>(url: string, data?: Record<string, any> | FormData | URLSearchParams, options?: VigiaRequestOptions): Promise<VigiaResponse<T>>;
    patch<T = any>(url: string, data?: Record<string, any> | FormData | URLSearchParams, options?: VigiaRequestOptions): Promise<VigiaResponse<T>>;
    delete<T = any>(url: string, data?: Record<string, any> | FormData | URLSearchParams, options?: VigiaRequestOptions): Promise<VigiaResponse<T>>;
    /** Qualquer configuração do axios. */
    request<T = any>(config: VigiaRequestOptions & { url: string; method?: string; data?: any; params?: any }): Promise<VigiaResponse<T>>;
    /** A instância do axios (interceptors, defaults). */
    instance(): any;
    /** O token CSRF encontrado na página. */
    token(): string;
    /** Normaliza uma resposta do axios obtida por fora. */
    normalize<T = any>(axiosResponse: any): VigiaResponse<T>;
}

/** Vigia.mask(el, 'cpf') aplica ou troca a máscara de um campo. */
interface VigiaMask {
    (el: Element, mask: string, options?: { decimals?: number; prefix?: string; suffix?: string; thousands?: string; negative?: boolean }): void;
    /** Vigia.mask.format('11222333000181', 'cnpj') -> '11.222.333/0001-81' */
    format(value: string | number, mask: string, options?: Record<string, any>): string;
    /** Vigia.mask.unmask('R$ 1.234,56', 'money') -> '1234.56' */
    unmask(value: string, mask: string, options?: Record<string, any>): string;
    /** Máscara nova: padrão ('9999-99'), objeto ({ pattern, uppercase }) ou função que devolve o padrão. */
    add(name: string, spec: string | { pattern: string; uppercase?: boolean; lowercase?: boolean } | ((digitado: string) => string)): void;
    masks: Record<string, any>;
}

/** Configuração global (Vigia.config). */
interface VigiaConfig {
    /** Vazio: descobre sozinho (Joomla.getOptions, form.token, meta csrf-token). */
    token?: string;
    timeout?: number;
    validateOn?: 'blur' | 'input' | 'submit';
    focusInvalid?: boolean;
    /** false mostra todas as mensagens do campo. */
    firstErrorOnly?: boolean;
    notify?: 'auto' | 'joomla' | 'none';
    /** false: chame Vigia.init() você mesmo. */
    autoInit?: boolean;
    classes?: {
        invalid?: string;
        valid?: string;
        feedback?: string;
        loading?: string;
        alert?: { success?: string; error?: string; warning?: string; info?: string };
    };
    /** Troca mensagens padrão das regras ({ required: 'Preencha :field.' }). */
    messages?: Record<string, string>;
    /** Troca mensagens de erro HTTP ({ 401: '...', network: '...', timeout: '...', unexpected: '...' }). */
    httpMessages?: Record<string | number, string>;
}

declare const Vigia: {
    version: string;

    /**
     * Instância do formulário (a mesma do data-vigia). Chamar de novo soma as opções.
     * @example const vigia = Vigia.form('#cliente', { validateOn: 'submit' });
     */
    form(el: string | HTMLElement, options?: VigiaFormOptions): VigiaForm;

    /** Ativa formulários [data-vigia] e máscaras dentro de um elemento (ex.: modal). */
    init(root?: HTMLElement | Document): void;

    /** Lê/altera a configuração global. */
    config(options?: VigiaConfig): VigiaConfig;

    /**
     * Valida dados sem formulário.
     * @example await Vigia.validate({ email: 'x' }, { email: 'required|email' })
     */
    validate(
        data: Record<string, any>,
        rules: Record<string, VigiaRule>,
        messages?: Record<string, string>,
        labels?: Record<string, string>
    ): Promise<VigiaValidationResult>;

    /** Requisições com token CSRF e resposta normalizada. */
    http: VigiaHttp;

    rules: {
        /** Regra nova: data-rules="multiplo:5". */
        add(name: string, fn: VigiaGlobalRuleFn, message?: string): void;
        /** Confere se a regra existe (regra com nome errado passa em silêncio). */
        has(name: string): boolean;
        /** 'required|max:5' -> [{ name, params }] */
        parse(rules: string | any[]): Array<{ name: string; params: any[] }>;
        /** Testa uma regra isolada. */
        test(name: string, value: any, params?: any[], data?: Record<string, any>): Promise<boolean | string>;
    };

    /** Máscaras: cpf, cnpj, cpf_cnpj, phone, cep, date, time, datetime, money, decimal, integer, percent, placa... */
    mask: VigiaMask;

    /** Mensagens padrão das regras. */
    messages: Record<string, string>;

    /** Plugin do Alpine (registrado sozinho no alpine:init). */
    alpine: (Alpine: any) => void;

    /** Construtor do erro padronizado. */
    Error: (message: string, details?: Partial<VigiaError>) => VigiaError;

    utils: {
        /** 'itens[0][sku]' -> 'itens.0.sku' */
        nameToKey(name: string): string;
        /** 'itens.0.sku' -> 'itens[0][sku]' */
        keyToName(key: string): string;
        /** 'R$ 1.234,56' -> 1234.56 (null se não for número) */
        toNumber(value: any): number | null;
        /** Data estrita: '31/02/2026' -> null */
        parseDate(value: string, format?: string): Date | null;
        /** formatDate(new Date(), 'Y-m-d H:i:s') */
        formatDate(date: Date, format: string): string;
        cpf(value: string): boolean;
        cnpj(value: string): boolean;
    };
};

/** Eventos vigia:* disparados no formulário (sobem pelo DOM). */
interface HTMLElementEventMap {
    'vigia:invalid': CustomEvent<VigiaDomDetail<'invalid'>>;
    'vigia:before': CustomEvent<VigiaDomDetail<'before'>>;
    'vigia:success': CustomEvent<VigiaDomDetail<'success'>>;
    'vigia:error': CustomEvent<VigiaDomDetail<'error'>>;
    'vigia:complete': CustomEvent<VigiaDomDetail<'complete'>>;
}

interface DocumentEventMap {
    'vigia:invalid': CustomEvent<VigiaDomDetail<'invalid'>>;
    'vigia:before': CustomEvent<VigiaDomDetail<'before'>>;
    'vigia:success': CustomEvent<VigiaDomDetail<'success'>>;
    'vigia:error': CustomEvent<VigiaDomDetail<'error'>>;
    'vigia:complete': CustomEvent<VigiaDomDetail<'complete'>>;
}
