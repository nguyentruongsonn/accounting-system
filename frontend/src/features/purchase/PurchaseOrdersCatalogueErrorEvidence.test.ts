import { describe, expect, it } from 'vitest';
import source from './PurchaseOrders.tsx?raw';

describe('purchase order catalogue loading boundary', () => {
    it('does not hide catalogue failures behind empty selectors', () => {
        expect(source).toContain('parsePurchaseOrderCollection');
        expect(source).toContain('action={<Button size="small" onClick={() => void refetchOrders()}>Thử lại danh sách đơn mua hàng</Button>}');
        expect(source).not.toContain('} catch (e) {\n                return [];');
        expect(source).not.toContain("return Array.isArray(data) ? data : (data?.data || []);");
    });
});
