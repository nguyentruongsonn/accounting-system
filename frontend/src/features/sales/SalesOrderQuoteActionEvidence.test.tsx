import { describe, expect, it } from 'vitest';
import ordersSource from './SalesOrders.tsx?raw';
import quotesSource from './SalesQuotes.tsx?raw';

describe('sales order and quote action evidence boundary', () => {
    it('requires persisted resources or an explicit delete confirmation before success', () => {
        expect(ordersSource).toContain('function hasOrderEvidence(response: any)');
        expect(ordersSource).toContain('Máy chủ không trả về đơn hàng đã lưu');
        expect(ordersSource).toContain('Máy chủ không xác nhận đã xóa đơn hàng');
        expect(quotesSource).toContain('function hasQuoteEvidence(response: any)');
        expect(quotesSource).toContain('Máy chủ không trả về báo giá đã lưu');
        expect(quotesSource).toContain('Máy chủ không xác nhận đã xóa báo giá');
    });

    it('does not advertise conversion actions without a server-backed handoff', () => {
        expect(ordersSource).not.toContain("key: 'create_invoice'");
        expect(ordersSource).not.toContain("key: 'create_delivery'");
        expect(ordersSource).not.toContain('Backend chưa công bố API lập chứng từ bán hàng từ đơn đặt hàng');
        expect(ordersSource).not.toContain('Backend chưa công bố API lập phiếu xuất kho từ đơn đặt hàng');
        expect(quotesSource).not.toContain("key: 'create_order'");
        expect(quotesSource).not.toContain("key: 'create_invoice'");
        expect(quotesSource).not.toContain('Backend chưa công bố API lập đơn đặt hàng từ báo giá');
        expect(quotesSource).not.toContain('Backend chưa công bố API lập chứng từ bán hàng từ báo giá');
    });
});
