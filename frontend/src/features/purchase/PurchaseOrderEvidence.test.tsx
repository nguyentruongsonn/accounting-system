import { describe, expect, it } from 'vitest';
import source from './PurchaseOrders.tsx?raw';

describe('purchase order display/action evidence boundary', () => {
    it('does not render sample orders or report unpersisted actions as success', () => {
        expect(source).toContain('dataSource={orderList}');
        expect(source).not.toContain("order_number: 'ĐMH00001'");
        expect(source).not.toContain('Công ty Cổ phần Thép Hòa Phát');
        expect(source).not.toContain('363000000');
        expect(source).toContain('response?.data?.id === undefined || response?.data?.id === null');
        expect(source).toContain('Máy chủ không trả về đơn mua hàng đã lưu');
        expect(source).toContain('onSuccess: async (response)');
        expect(source).toContain("await queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })");
        expect(source).toContain('Không thể in đơn mua hàng vì máy chủ chưa cung cấp dòng chi tiết đã lưu.');
        expect(source).not.toContain('description: record.description || `Đơn mua hàng từ');
        expect(source).toContain('const hasMissingTotal = orderList.some');
        expect(source).toContain("totalSum == null ? '—'");
        expect(source).not.toContain("setSearchParams({ tab: '5', from_po: record.order_number })");
        expect(source).not.toContain('Backend chưa công bố API lập chứng từ mua hàng từ đơn');
        expect(source).not.toContain("key: 'create_invoice'");
        expect(source).not.toContain("key: 'create_multi_invoice'");
        expect(source).not.toContain("key: 'create_service'");
        expect(source).not.toContain("key: 'create_contract'");
        expect(source).not.toContain('Lập CT mua hàng');
        expect(source).not.toContain('tax_rate: 10');
        expect(source).toContain("initialValue={0}");
    });
});
