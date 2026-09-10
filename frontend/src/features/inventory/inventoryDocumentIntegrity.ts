export const INVENTORY_DOCUMENT_ITEMS_QUERY_KEY = ['inventory-items', 'document-form'] as const;

export type InventoryItemCatalogueRecord = {
    id: number;
    code: string;
    name: string;
    unit?: string | null;
};

export type InventoryAccountCatalogueRecord = {
    id: number;
    code: string;
    name: string;
    is_parent: boolean;
    is_active: boolean;
};

export type InventoryWarehouseCatalogueRecord = {
    id: number;
    code: string;
    name: string;
};

export type InventoryContactCatalogueRecord = {
    id: number;
    name: string;
    [key: string]: unknown;
};

export type PersistedInventoryDocument = {
    id: number;
    is_posted?: boolean;
    status?: string;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);

export function parseInventoryItemCatalogue(value: unknown): InventoryItemCatalogueRecord[] {
    if (!Array.isArray(value) || !value.every((item) => (
        isRecord(item)
        && typeof item.id === 'number'
        && Number.isInteger(item.id)
        && item.id > 0
        && typeof item.code === 'string'
        && item.code.trim().length > 0
        && typeof item.name === 'string'
        && item.name.trim().length > 0
    ))) {
        throw new Error('Invalid inventory item catalogue response.');
    }

    return value as InventoryItemCatalogueRecord[];
}

export function parseInventoryAccountCatalogue(value: unknown): InventoryAccountCatalogueRecord[] {
    const rows = isRecord(value) && Array.isArray(value.data) ? value.data : value;
    if (!Array.isArray(rows) || !rows.every((account) => (
        isRecord(account)
        && typeof account.id === 'number'
        && Number.isInteger(account.id)
        && account.id > 0
        && typeof account.code === 'string'
        && account.code.trim().length > 0
        && typeof account.name === 'string'
        && account.name.trim().length > 0
        && typeof account.is_parent === 'boolean'
        && typeof account.is_active === 'boolean'
    ))) {
        throw new Error('Invalid chart of accounts response.');
    }
    return rows as InventoryAccountCatalogueRecord[];
}

export function parseInventoryWarehouseCatalogue(value: unknown): InventoryWarehouseCatalogueRecord[] {
    const rows = isRecord(value) && Array.isArray(value.data) ? value.data : value;
    if (!Array.isArray(rows) || !rows.every((warehouse) => (
        isRecord(warehouse)
        && typeof warehouse.id === 'number'
        && Number.isInteger(warehouse.id)
        && warehouse.id > 0
        && typeof warehouse.code === 'string'
        && warehouse.code.trim().length > 0
        && typeof warehouse.name === 'string'
        && warehouse.name.trim().length > 0
    ))) {
        throw new Error('Invalid warehouse catalogue response.');
    }
    return rows as InventoryWarehouseCatalogueRecord[];
}

export function parseInventoryContactCatalogue(
    value: unknown,
    resource: 'supplier' | 'customer',
): InventoryContactCatalogueRecord[] {
    const rows = isRecord(value) && Array.isArray(value.data) ? value.data : value;
    if (!Array.isArray(rows) || !rows.every((contact) => (
        isRecord(contact)
        && typeof contact.id === 'number'
        && Number.isInteger(contact.id)
        && contact.id > 0
        && typeof contact.name === 'string'
        && contact.name.trim().length > 0
    ))) {
        throw new Error(`Invalid ${resource} catalogue response.`);
    }
    return rows as InventoryContactCatalogueRecord[];
}

export function getActiveLeafInventoryAccounts(accounts: InventoryAccountCatalogueRecord[]): InventoryAccountCatalogueRecord[] {
    return accounts.filter((account) => account.is_parent === false && account.is_active === true);
}

export function parseInventoryDocumentList(value: unknown, resource: 'receipt' | 'issue'): PersistedInventoryDocument[] {
    const rows = isRecord(value) ? value.data : undefined;
    if (!Array.isArray(rows) || !rows.every((row) => isRecord(row) && typeof row.id === 'number' && row.id > 0)) {
        throw new Error(`Invalid inventory ${resource} list response.`);
    }

    return rows as PersistedInventoryDocument[];
}

export function requirePersistedInventoryDocument(value: unknown, requirePosted = false): PersistedInventoryDocument {
    const candidate = isRecord(value) && isRecord(value.data) ? value.data : value;
    if (!isRecord(candidate) || typeof candidate.id !== 'number' || candidate.id <= 0) {
        throw new Error('Máy chủ không trả về chứng từ kho đã lưu.');
    }

    const persisted = candidate as PersistedInventoryDocument;
    if (requirePosted && persisted.is_posted !== true && persisted.status !== 'posted') {
        throw new Error('Máy chủ không xác nhận chứng từ kho đã ghi sổ.');
    }

    return persisted;
}

export function canPostPersistedInventoryDocument(value: unknown, pending = false): boolean {
    if (pending || !isRecord(value) || typeof value.id !== 'number' || !Number.isInteger(value.id) || value.id <= 0) {
        return false;
    }
    return value.is_posted === false || value.status === 'draft';
}

export function getInventoryMutationError(value: unknown, fallback: string): string {
    const error = value as {
        response?: {
            status?: number;
            data?: { message?: unknown; error?: unknown; error_code?: unknown; errors?: Record<string, unknown> };
        };
        message?: unknown;
    };
    const data = error?.response?.data;
    const nestedErrors = Object.values(data?.errors ?? {})
        .flatMap((entry) => Array.isArray(entry) ? entry : [entry])
        .filter((entry): entry is string => typeof entry === 'string' && entry.trim() !== '');
    const messages = [data?.message, data?.error, ...nestedErrors]
        .filter((entry): entry is string => typeof entry === 'string' && entry.trim() !== '');
    const serverMessage = messages[0] ?? (typeof error?.message === 'string' ? error.message : '');
    const normalizedMessages = messages.join(' ').toLowerCase();
    if (/mapping tài khoản|account mapping|account_mappings/.test(normalizedMessages)) {
        const detail = nestedErrors.find((entry) => /mapping tài khoản|account mapping|account_mappings/i.test(entry));
        return detail
            ? `${fallback} Cần xử lý mapping tài khoản kho: ${detail}`
            : `${fallback} Cần cấu hình và phê duyệt mapping tài khoản kho trước khi thực hiện.`;
    }
    return serverMessage || fallback;
}
