import { describe, expect, it } from 'vitest';
import source from './PurchaseInvoices.tsx?raw';

describe('purchase invoice data-truth boundary', () => {
    it('does not synthesize print lines, supplier identity, or totals', () => {
        expect(source).not.toContain("item_code: 'VT00001'");
        expect(source).not.toContain("debit_account: '1561'");
        expect(source).not.toContain("credit_account: '331'");
        expect(source).not.toContain("'NK00001'");
        expect(source).not.toContain("'Công ty Cổ phần MISA'");
        expect(source).not.toContain("'0101243150'");
        expect(source).not.toContain('132000000');
        expect(source).not.toContain('120000000');
        expect(source).not.toContain("'16/08/2026'");
        expect(source).not.toContain("'00012845'");
        expect(source).toContain('const lines = Array.isArray(record.lines) ? record.lines : []');
    });

    it('does not expose intake actions without a persisted server workflow', () => {
        expect(source).not.toContain("label: 'Lập từ hóa đơn đầu vào (meInvoice)'");
        expect(source).not.toContain("label: 'Nhập từ Excel'");
        expect(source).not.toContain('setIsMeInvoiceModalOpen(true)');
        expect(source).not.toContain('setIsExcelImportModalOpen(true)');
    });
});
