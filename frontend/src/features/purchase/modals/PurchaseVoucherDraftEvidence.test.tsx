import { describe, expect, it } from 'vitest';
import serviceModal from './PurchaseServiceModal.tsx?raw';
import detailModal from './PurchaseVoucherDetailModal.tsx?raw';

describe('purchase voucher draft evidence boundary', () => {
    it('does not fabricate voucher identity, line amounts, or random values', () => {
        for (const source of [serviceModal, detailModal]) {
            expect(source).not.toContain('Math.random');
            expect(source).not.toContain('VT00001');
            expect(source).not.toContain('HDDT998822');
            expect(source).not.toContain('120000000');
            expect(source).not.toContain('0001000');
            expect(source).not.toContain('0011004288888');
            expect(source).not.toContain('190333888999');
            expect(source).not.toContain('0888999999');
            expect(source).toContain('persistedInvoice.id === undefined || persistedInvoice.id === null');
        }
    });

    it('loads account and warehouse choices from the tenant server catalogue only', () => {
        expect(serviceModal).toContain("api.get('/master/accounts')");
        expect(detailModal).toContain("api.get('/master/accounts')");
        expect(detailModal).toContain("api.get('/master/warehouses')");
        for (const source of [serviceModal, detailModal]) {
            expect(source).not.toContain('const ACCOUNT_OPTIONS =');
        }
        expect(detailModal).not.toContain('const WAREHOUSE_OPTIONS =');
        expect(serviceModal).toContain('Chưa có tài khoản từ máy chủ');
        expect(detailModal).toContain('Chưa có kho từ máy chủ');
        expect(detailModal).toContain('warehouse_code: l.warehouse');
        expect(detailModal).toContain('warehouse_id: warehouseList.find');
        expect(detailModal).toContain('Array.isArray(editRecord.lines) ? editRecord.lines : []');
        expect(detailModal).toContain('await api.put(`/purchase/invoices/${editRecord.id}`, payload)');
    });
});
