/**
 * Tipos do Vitrine.js — usados só pelo editor (autocompletar e documentação ao passar o mouse).
 * Não são carregados no navegador. Depende de vigia.d.ts (VigiaResponse, VigiaError).
 *
 * Fonte: js/vitrine.js e docs/vitrine-js.html.
 */

/** meta.paginacao do ApiResponseHelper::paginated(). */
interface VitrinePaginacao {
    total: number;
    por_pagina: number;
    pagina_atual: number;
    total_paginas: number;
}

/** Estado atual da lista (página, tamanho, ordem e filtros). */
interface VitrineState {
    page: number;
    limit: number;
    sort: string;
    direction: 'ASC' | 'DESC';
    filters: Record<string, any>;
}

/** Detalhe recebido pelos callbacks (onLoaded...) e por vitrine.on(). */
interface VitrineEventDetails<T = any> {
    /** Antes de buscar (preventDefault() / onBefore devolvendo false cancela). */
    before: { params: Record<string, any> };
    /** Página carregada e desenhada. */
    loaded: { items: T[]; meta: VitrinePaginacao; response: VigiaResponse<T[]>; fromCache: boolean; appended: T[] | null };
    /** Falha ao buscar (não dispara em cancelamento). */
    error: { error: VigiaError };
    /** Começou/terminou de carregar. */
    state: { loading: boolean };
    /** Seleção mudou. */
    selection: { count: number; ids: Array<string | number> };
    /** Ação vai rodar (cancelável). item = linha; ids = selecionados no lote. */
    action: { name: string; item: T | null; ids: Array<string | number> };
    /** Ação concluída. */
    actionDone: { name: string; item: T | null; ids: Array<string | number>; response: VigiaResponse };
    /** Ação falhou. */
    actionError: { name: string; item: T | null; ids: Array<string | number>; error: VigiaError };
}

/** e.detail dos eventos do DOM (vitrine:*): o mesmo detalhe + a instância. */
type VitrineDomDetail<K extends keyof VitrineEventDetails> = VitrineEventDetails[K] & { vitrine: VitrineInstance };

/** Opções de uma ação (data-action / data-batch-action / vitrine.action()). */
interface VitrineActionOptions {
    name?: string;
    /** Para onde enviar ({campo} do item é substituído). */
    url: string;
    /** post (padrão); put/patch/delete vão em JSON; get na query. */
    method?: 'get' | 'post' | 'put' | 'patch' | 'delete';
    /** Confirmação: {campo} na linha, :count no lote. */
    confirm?: string;
    /** false: não recarrega depois do sucesso. */
    reload?: boolean;
}

/**
 * Ação com código próprio.
 * Linha: recebe o item. Lote (data-batch-action): recebe a lista de itens selecionados.
 */
type VitrineActionFn<T = any> = (this: VitrineInstance<T>, itemOuItens: T | T[], vitrine: VitrineInstance<T>, botao: HTMLElement) => void;

/** Opções (Vitrine.create(el, opções) ou atributos data-vitrine-*). */
interface VitrineOptions<T = any> {
    /** URL da listagem (padrão: valor do data-vitrine). */
    url?: string;
    /** Itens por página. Padrão 20. */
    limit?: number;
    /** Ordem inicial (campo). */
    sort?: string;
    direction?: 'ASC' | 'DESC';
    /** Filtros iniciais. */
    filters?: Record<string, any>;
    /** Parâmetros extras em toda busca (objeto ou função). */
    params?: Record<string, any> | ((vitrine: VitrineInstance<T>) => Record<string, any>);
    /** Campo usado como id na seleção e nas ações. Padrão 'id'. */
    idField?: string;
    /** Prefixo na URL quando há mais de uma vitrine (padrão: id do contêiner). */
    prefix?: string;
    /** false: não grava página/filtros na URL (lista em modal). */
    history?: boolean;
    /** false: não busca ao iniciar. */
    autoLoad?: boolean;
    /** Segundos que as páginas vistas ficam em memória (0 desliga). Padrão 60. */
    cache?: number;
    /** Busca a próxima página em segundo plano. Padrão true. */
    prefetch?: boolean;
    /** Linhas fantasma na primeira carga (0 desliga). Padrão 5. */
    skeleton?: number;
    /** Atualiza sozinho a cada N segundos (mínimo 5). */
    refresh?: number;
    /** Nome para guardar tamanho e ordem no navegador. */
    remember?: string;
    /** Estilo da paginação. */
    pagination?: 'numbers' | 'jpaginate' | 'more' | 'infinite';
    /** Quantas páginas a faixa jpaginate mostra. Padrão 7. */
    display?: number;

    /** Ajusta cada item antes de desenhar (campos para data-if, data-class-*, {campo}). */
    transform?: (this: VitrineInstance<T>, item: T, index: number) => T;
    /** Desenha a linha você mesmo (no lugar do template). Use textContent, nunca innerHTML com dados. */
    renderItem?: (item: T, index: number) => HTMLElement;
    /** Ajuste depois do template (classe, tooltip...). */
    onItem?: (item: T, el: HTMLElement, index: number) => void;
    /** Ações com código próprio, por nome do data-action / data-batch-action. */
    actions?: Record<string, VitrineActionFn<T>>;

    onBefore?: (this: VitrineInstance<T>, detalhe: VitrineEventDetails<T>['before'], vitrine: VitrineInstance<T>) => void | false;
    onLoaded?: (this: VitrineInstance<T>, detalhe: VitrineEventDetails<T>['loaded'], vitrine: VitrineInstance<T>) => void;
    onError?: (this: VitrineInstance<T>, detalhe: VitrineEventDetails<T>['error'], vitrine: VitrineInstance<T>) => void;
    onState?: (this: VitrineInstance<T>, detalhe: VitrineEventDetails<T>['state'], vitrine: VitrineInstance<T>) => void;
    onSelection?: (this: VitrineInstance<T>, detalhe: VitrineEventDetails<T>['selection'], vitrine: VitrineInstance<T>) => void;
    onAction?: (this: VitrineInstance<T>, detalhe: VitrineEventDetails<T>['action'], vitrine: VitrineInstance<T>) => void | false;
    onActionDone?: (this: VitrineInstance<T>, detalhe: VitrineEventDetails<T>['actionDone'], vitrine: VitrineInstance<T>) => void;
    onActionError?: (this: VitrineInstance<T>, detalhe: VitrineEventDetails<T>['actionError'], vitrine: VitrineInstance<T>) => void;
}

/** Instância: Vitrine.of('#pedidos') ou Vitrine.create('#pedidos', opções). */
interface VitrineInstance<T = any> {
    /** O contêiner [data-vitrine]. */
    el: HTMLElement;
    options: VitrineOptions<T>;
    url: string;
    prefix: string;
    idField: string;
    /** Itens desenhados (já passados pelo transform). */
    items: T[];
    meta: VitrinePaginacao;
    /** Última resposta normalizada. */
    response: VigiaResponse<T[]> | null;
    loading: boolean;
    error: VigiaError | null;
    state: VitrineState;

    /** Ouve um evento da instância (mesmos nomes, sem o prefixo vitrine:). */
    on<K extends keyof VitrineEventDetails>(name: K, callback: (detalhe: VitrineEventDetails<T>[K], vitrine: VitrineInstance<T>) => void): VitrineInstance<T>;

    /** Busca a página atual (cancela a anterior). */
    load(options?: { force?: boolean; append?: boolean; background?: boolean }): Promise<VigiaResponse<T[]> | null>;
    /** Esquece o cache e busca de novo. */
    reload(): Promise<VigiaResponse<T[]> | null>;
    /** Vai para a página. */
    page(page: number): Promise<VigiaResponse<T[]> | null>;
    /** Ordena (sem direção: alterna). */
    sort(field: string, direction?: 'ASC' | 'DESC'): Promise<VigiaResponse<T[]> | null>;
    /** Muda o tamanho da página. */
    limit(limit: number): Promise<VigiaResponse<T[]> | null>;
    /** Aplica filtros de fora do formulário (null remove o filtro). Volta para a página 1. */
    filter(filters: Record<string, any>): Promise<VigiaResponse<T[]> | null>;
    /** Volta filtros, ordem e tamanho iniciais. */
    reset(): Promise<VigiaResponse<T[]> | null>;
    /** Modos "more"/"infinite": soma a próxima página. */
    loadMore(): Promise<VigiaResponse<T[]> | null>;
    /** Parâmetros da próxima busca (page, limit, sort, direction, filtros, params). */
    params(): Record<string, any>;
    /** Esquece as páginas guardadas (ex.: depois de editar num modal). */
    clearCache(): void;

    /** Itens selecionados. */
    selected(): T[];
    /** Ids dos selecionados. */
    selectedIds(): Array<string | number>;
    clearSelection(): void;

    /** Executa a mesma ação dos botões (item = linha; sem item = lote com os selecionados). */
    action(options: VitrineActionOptions, item?: T): Promise<VigiaResponse | null>;
    /** URL de exportação com os filtros e a ordem atuais. */
    exportUrl(base: string, target?: HTMLElement): string;

    destroy(): void;
}

/** Configuração global (Vitrine.config). */
interface VitrineConfig {
    limit?: number;
    /** ms de espera ao digitar nos filtros. Padrão 350. */
    debounce?: number;
    history?: boolean;
    pagesAround?: number;
    paginationStyle?: 'numbers' | 'jpaginate' | 'more' | 'infinite';
    jpaginate?: { display?: number; holdDelay?: number; holdSpeed?: number; goto?: boolean };
    cache?: number;
    prefetch?: boolean;
    skeleton?: number;
    autoInit?: boolean;
    /** Nomes dos parâmetros na requisição. */
    params?: { page?: string; limit?: string; sort?: string; direction?: string };
    classes?: {
        pagination?: string;
        pageItem?: string;
        pageLink?: string;
        active?: string;
        disabled?: string;
        loading?: string;
        sortAsc?: string;
        sortDesc?: string;
        selected?: string;
    };
    messages?: Partial<Record<
        'error' | 'confirmBatch' | 'summary' | 'summaryEmpty' | 'previous' | 'next' | 'first' | 'last'
        | 'slidePrevious' | 'slideNext' | 'pageOf' | 'goTo' | 'go' | 'loadMore' | 'allLoaded',
        string
    >>;
}

declare const Vitrine: {
    version: string;
    config(options?: VitrineConfig): VitrineConfig;
    /** Ativa todo [data-vitrine] dentro do elemento. */
    init(root?: HTMLElement | Document): void;
    /**
     * Cria (ou devolve) a instância do contêiner. Chamar de novo soma as opções.
     * @example const lista = Vitrine.create('#pedidos', { transform: p => ({ ...p, pago: p.status === 'pago' }) });
     */
    create<T = any>(el: string | HTMLElement, options?: VitrineOptions<T>): VitrineInstance<T>;
    /** O mesmo que create: pega a instância criada pelo data-vitrine. */
    of<T = any>(el: string | HTMLElement, options?: VitrineOptions<T>): VitrineInstance<T>;
    formats: {
        /** Formato novo para data-format="nome". */
        add(name: string, fn: (valor: any, item?: any) => string): void;
        /** Formata um valor: money, number, integer, date, datetime, boolean, cpf_cnpj, phone... */
        format(value: any, name: string, item?: any): string;
    };
    utils: {
        /** Troca {campo} por valores do item (codificados para URL). */
        fillUrl(url: string, item: any): string;
        /** Troca {campo} por valores do item (texto). */
        fillText(text: string, item: any): string;
        /** getPath(item, 'cliente.nome') */
        getPath(item: any, path: string): any;
        parseDate(value: string): { date: Date; dateOnly: boolean } | null;
    };
};

/** Eventos vitrine:* disparados no contêiner (sobem pelo DOM). */
interface HTMLElementEventMap {
    'vitrine:before': CustomEvent<VitrineDomDetail<'before'>>;
    'vitrine:loaded': CustomEvent<VitrineDomDetail<'loaded'>>;
    'vitrine:error': CustomEvent<VitrineDomDetail<'error'>>;
    'vitrine:state': CustomEvent<VitrineDomDetail<'state'>>;
    'vitrine:selection': CustomEvent<VitrineDomDetail<'selection'>>;
    'vitrine:action': CustomEvent<VitrineDomDetail<'action'>>;
    'vitrine:actionDone': CustomEvent<VitrineDomDetail<'actionDone'>>;
    'vitrine:actionError': CustomEvent<VitrineDomDetail<'actionError'>>;
}
