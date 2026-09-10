import { describe, expect, it } from 'vitest';
import receiptsSource from './InventoryReceipts.tsx?raw';
import issuesSource from './InventoryIssues.tsx?raw';

describe('inventory voucher catalogue error handling', () => {
    it('does not turn a malformed supplier response into an empty receipt form', () => {
        expect(receiptsSource).toContain("parseInventoryContactCatalogue(data, 'supplier')");
        expect(receiptsSource).toContain('isSuppliersError');
        expect(receiptsSource).toContain('refetchSuppliers');
        expect(receiptsSource).toContain('Thử lại danh mục phiếu nhập kho');
        expect(receiptsSource).not.toContain('(data?.data || [])');
    });

    it('does not turn a malformed customer response into an empty issue form', () => {
        expect(issuesSource).toContain("parseInventoryContactCatalogue(data, 'customer')");
        expect(issuesSource).toContain('isCustomersError');
        expect(issuesSource).toContain('refetchCustomers');
        expect(issuesSource).toContain('Thử lại danh mục phiếu xuất kho');
        expect(issuesSource).not.toContain('(data?.data || [])');
    });
});
