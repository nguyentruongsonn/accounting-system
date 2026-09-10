import { describe, expect, it } from 'vitest';
import {
    INVENTORY_DOCUMENT_ITEMS_QUERY_KEY,
    parseInventoryDocumentList,
    parseInventoryItemCatalogue,
    parseInventoryAccountCatalogue,
    getActiveLeafInventoryAccounts,
    canPostPersistedInventoryDocument,
    requirePersistedInventoryDocument,
    parseInventoryWarehouseCatalogue,
    parseInventoryContactCatalogue,
    getInventoryMutationError,
} from './inventoryDocumentIntegrity';

describe('inventory document integrity helpers', () => {
    it('uses one distinct query key for the canonical inventory item catalogue', () => {
        expect(INVENTORY_DOCUMENT_ITEMS_QUERY_KEY).toEqual(['inventory-items', 'document-form']);
    });

    it('accepts only a complete raw inventory item catalogue', () => {
        expect(parseInventoryItemCatalogue([{ id: 7, code: 'VT-07', name: 'Vật tư 07' }]))
            .toEqual([{ id: 7, code: 'VT-07', name: 'Vật tư 07' }]);
        expect(() => parseInventoryItemCatalogue({ data: [] })).toThrow('Invalid inventory item catalogue response.');
        expect(() => parseInventoryItemCatalogue([{ id: 7, code: '', name: 'Vật tư 07' }]))
            .toThrow('Invalid inventory item catalogue response.');
    });

    it('requires a persisted document id before treating a mutation as successful', () => {
        expect(requirePersistedInventoryDocument({ data: { id: 31 } })).toEqual({ id: 31 });
        expect(() => requirePersistedInventoryDocument({ data: {} }))
            .toThrow('Máy chủ không trả về chứng từ kho đã lưu.');
    });

    it('rejects a document list that does not use the resource collection envelope', () => {
        expect(parseInventoryDocumentList({ data: [{ id: 31 }] }, 'receipt')).toEqual([{ id: 31 }]);
        expect(() => parseInventoryDocumentList([], 'issue'))
            .toThrow('Invalid inventory issue list response.');
    });

    it('requires posted evidence from the persisted post response', () => {
        expect(requirePersistedInventoryDocument({ data: { id: 31, is_posted: true } }, true))
            .toEqual({ id: 31, is_posted: true });
        expect(() => requirePersistedInventoryDocument({ data: { id: 31, is_posted: false } }, true))
            .toThrow('Máy chủ không xác nhận chứng từ kho đã ghi sổ.');
    });

    it('requires explicit account flags and only exposes active leaf accounts', () => {
        const accounts = parseInventoryAccountCatalogue({ data: [
            { id: 1, code: '156', name: 'Kho', is_parent: false, is_active: true },
            { id: 2, code: '15', name: 'Tài sản', is_parent: true, is_active: true },
            { id: 3, code: '632', name: 'Giá vốn', is_parent: false, is_active: false },
        ] });
        expect(getActiveLeafInventoryAccounts(accounts)).toHaveLength(1);
        expect(() => parseInventoryAccountCatalogue([{ id: 4, code: '642', name: 'CP' }]))
            .toThrow('Invalid chart of accounts response.');
    });

    it('accepts only tenant warehouse catalogue rows', () => {
        expect(parseInventoryWarehouseCatalogue([{ id: 4, code: 'SIM-WH-001', name: 'Kho chính' }]))
            .toEqual([{ id: 4, code: 'SIM-WH-001', name: 'Kho chính' }]);
        expect(() => parseInventoryWarehouseCatalogue({ data: [{ id: 4, code: '', name: 'Kho chính' }] }))
            .toThrow('Invalid warehouse catalogue response.');
    });

    it('accepts contact catalogues only when the server envelope is valid', () => {
        expect(parseInventoryContactCatalogue({ data: [{ id: 1, name: 'Nhà cung cấp A' }] }, 'supplier'))
            .toEqual([{ id: 1, name: 'Nhà cung cấp A' }]);
        expect(() => parseInventoryContactCatalogue({ unexpected: [] }, 'customer'))
            .toThrow('Invalid customer catalogue response.');
        expect(() => parseInventoryContactCatalogue([{ id: 0, name: 'Không hợp lệ' }], 'supplier'))
            .toThrow('Invalid supplier catalogue response.');
    });

    it('turns the inventory mapping gate into an actionable UI message', () => {
        expect(getInventoryMutationError({ response: { status: 422, data: { message: 'Không thể ghi sổ phiếu nhập kho khi chưa có mapping tài khoản được phê duyệt cho phân hệ kho.' } } }, 'Không thể ghi sổ.'))
            .toContain('Cần cấu hình và phê duyệt mapping tài khoản kho');
    });

    it('surfaces the safe mapping role detail returned by a 409 resolver response', () => {
        expect(getInventoryMutationError({
            response: {
                status: 409,
                data: {
                    error: 'Approved posting account mapping evidence is not available.',
                    error_code: 'ACCOUNT_MAPPING_UNAVAILABLE',
                    errors: { account_mapping: ['Account mapping [inventory.voucher/inventory_debit] must resolve to exactly one approved mapping; found 0.'] },
                },
            },
        }, 'Không thể ghi sổ.'))
            .toContain('inventory.voucher/inventory_debit');
    });

    it('allows posting only for a persisted draft row, independent of catalogues', () => {
        expect(canPostPersistedInventoryDocument({ id: 31, is_posted: false }, false)).toBe(true);
        expect(canPostPersistedInventoryDocument({ id: 31, status: 'draft' }, false)).toBe(true);
        expect(canPostPersistedInventoryDocument({ id: 31, is_posted: true }, false)).toBe(false);
        expect(canPostPersistedInventoryDocument({ id: 31 }, false)).toBe(false);
        expect(canPostPersistedInventoryDocument({ id: 31, is_posted: false }, true)).toBe(false);
    });
});
