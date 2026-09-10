import { describe, expect, it } from 'vitest';
import source from './PurchaseReceiveInvoices.tsx?raw';

describe('purchase receive-invoice unavailable boundary', () => {
    it('does not prefill a disabled workflow with synthetic invoice or supplier data', () => {
        expect(source).not.toContain("useState('00012845')");
        expect(source).not.toContain("useState('1C26TAA')");
        expect(source).not.toContain('useState(50000000)');
        expect(source).not.toContain("Công ty Cổ phần Thép Hòa Phát");
        expect(source).toContain("useState<number | null>(null)");
        expect(source).toContain("subtotal == null || vatRate == null");
        expect(source).not.toContain('<Alert');
        expect(source).not.toContain('<Popconfirm');
        expect(source).not.toContain("message.success('Đã xóa hóa đơn')");
    });
});
