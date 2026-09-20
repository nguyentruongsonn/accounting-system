import { QueryClient } from '@tanstack/react-query';

export type AccountingDataScope =
    | 'all'
    | 'cash'
    | 'bank'
    | 'purchase'
    | 'sales'
    | 'inventory'
    | 'fixed-assets'
    | 'tools'
    | 'gl';

type QueryKeyPrefix = readonly [string, ...unknown[]];

const ACCOUNTING_SCOPE_CONFIG: Record<Exclude<AccountingDataScope, 'all'>, {
    queryPrefixes: readonly QueryKeyPrefix[];
    events: readonly string[];
}> = {
    cash: {
        queryPrefixes: [
            ['cash-receipts'], ['cash-payments'], ['cash-reports'], ['cash-inventories'],
            ['cash-collection-accounts'], ['sales-invoice-outstanding'],
        ],
        events: ['cash-receipts-invalidated', 'cash-payments-invalidated'],
    },
    bank: {
        queryPrefixes: [
            ['bank-receipts'], ['bank-payments'], ['bank-accounts'], ['bank-reports'],
            ['bank-reconciliation-imports'], ['bank-reconciliation-lines'], ['bank-reconciliation-timeline'],
            ['borrowing-contracts'],
        ],
        events: ['refresh-bank-transactions'],
    },
    purchase: {
        queryPrefixes: [
            ['purchase-invoices'], ['purchase-orders'], ['purchase-contracts'], ['purchase-returns'],
            ['purchase-discounts'], ['purchase-reports'],
        ],
        events: ['purchase-invoices-invalidated'],
    },
    sales: {
        queryPrefixes: [
            ['sales-invoices'], ['sales-orders'], ['sales-quotes'], ['sales-returns'],
            ['sales-discounts'], ['sales-reports'], ['sales-invoice-outstanding'], ['sales-report'],
        ],
        events: ['sales-invoices-invalidated'],
    },
    inventory: {
        queryPrefixes: [
            ['inventory-receipts'], ['inventory-issues'], ['inventory-transfers'], ['inventory-stock-counts'],
            ['inventory-items'], ['inventory-warehouses'], ['items'], ['stock-reports'], ['inventory-reports'],
        ],
        events: ['refresh-inventory-receipt', 'refresh-inventory-issue', 'refresh-inventory-transfer', 'refresh-inventory-stock-count'],
    },
    'fixed-assets': {
        queryPrefixes: [
            ['fixed-assets'], ['fixed-assets-depreciation-periods'], ['fixed-assets-depreciation-detail'],
            ['fixed-assets-disposals'], ['fixed-asset-detail'],
        ],
        events: ['refresh-fixed-assets'],
    },
    tools: {
        queryPrefixes: [['tools'], ['tool-allocations'], ['prepaid-expenses']],
        events: ['refresh-tools'],
    },
    gl: {
        queryPrefixes: [
            ['journal-entries'], ['general-journals'], ['gl-periods'], ['accounting-periods'],
            ['closing-entries'], ['period-close'],
        ],
        events: ['refresh-general-journals', 'refresh-journal-entries'],
    },
};

const DATA_CHANGE_EVENTS = [
    'accounting-data-changed',
    'cash-receipts-invalidated',
    'cash-payments-invalidated',
    'purchase-invoices-invalidated',
    'sales-invoices-invalidated',
    'refresh-general-journals',
    'refresh-journal-entries',
    'refresh-inventory-issue',
    'refresh-inventory-receipt',
    'refresh-inventory-stock-count',
    'refresh-inventory-transfer',
    'refresh-bank-transactions',
    'refresh-fixed-assets',
    'refresh-tools',
    'refresh-accounting-reports',
] as const;

let dataChangeScheduled = false;
const pendingDataChangeScopes = new Set<AccountingDataScope>();

function queryKeyMatchesPrefix(queryKey: readonly unknown[], prefixes: readonly QueryKeyPrefix[]): boolean {
    const firstKey = queryKey[0];
    return typeof firstKey === 'string' && prefixes.some(([prefix]) => prefix === firstKey);
}

function dispatchEvents(eventNames: readonly string[]): void {
    if (typeof window === 'undefined') return;
    eventNames.forEach((eventName) => {
        window.dispatchEvent(new CustomEvent(eventName));
    });
}

function flushDataChanged(scopes: ReadonlySet<AccountingDataScope>): void {
    try {
        if (scopes.has('all')) {
            void queryClient.invalidateQueries();
            dispatchEvents(DATA_CHANGE_EVENTS);
            return;
        }

        const configs = [...scopes]
            .filter((scope): scope is Exclude<AccountingDataScope, 'all'> => scope !== 'all')
            .map((scope) => ACCOUNTING_SCOPE_CONFIG[scope]);
        const prefixes = configs.flatMap((config) => config.queryPrefixes);
        const eventNames = new Set<string>(['refresh-accounting-reports']);
        configs.forEach((config) => config.events.forEach((eventName) => eventNames.add(eventName)));
        void queryClient.invalidateQueries({ predicate: (query) => queryKeyMatchesPrefix(query.queryKey, prefixes) });
        dispatchEvents([...eventNames]);
    } catch {
        // Safe failover
    }
}

export function getAccountingDataScope(url: string): AccountingDataScope {
    const path = url.split('?')[0].toLowerCase();
    if (path.includes('/cash/') || path.includes('/cash-inventories')) return 'cash';
    if (path.includes('/bank/') || path.includes('/borrowing-contracts') || path.includes('/bank-reconciliation')) return 'bank';
    if (path.includes('/purchase/')) return 'purchase';
    if (path.includes('/sales/')) return 'sales';
    if (path.includes('/inventory/')) return 'inventory';
    if (path.includes('/fixed-assets/')) return 'fixed-assets';
    if (path.includes('/tools/') || path.includes('/prepaid-expenses')) return 'tools';
    if (path.includes('/gl/')) return 'gl';
    return 'all';
}

/**
 * Phát tín hiệu và invalidate toàn bộ query cache khi có bất kỳ dữ liệu kế toán nào thay đổi
 * (thêm mới, sửa, xóa, ghi sổ, bỏ ghi sổ).
 */
export function notifyDataChanged(scope: AccountingDataScope = 'all'): void {
    pendingDataChangeScopes.add(scope);
    if (dataChangeScheduled) {
        return;
    }

    dataChangeScheduled = true;
    void Promise.resolve().then(() => {
        dataChangeScheduled = false;
        const scopes = new Set(pendingDataChangeScopes);
        pendingDataChangeScopes.clear();
        flushDataChanged(scopes);
    });
}

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: true,
            refetchOnMount: true,
            refetchOnReconnect: true,
            retry: 1,
            staleTime: 30_000,
            gcTime: 10 * 60 * 1000,
        },
    },
});
