import { describe, expect, it } from 'vitest';
import source from './PurchaseVoucherDetailModal.tsx?raw';

describe('purchase voucher catalogue loading boundary', () => {
    it('keeps failed supplier/item/account/warehouse selectors visible and retryable', () => {
        expect(source).toContain('parsePurchaseVoucherCollection');
        expect(source).toContain('Thử lại danh mục nhà cung cấp');
        expect(source).toContain('Thử lại danh mục hàng hóa');
        expect(source).toContain('Thử lại danh mục tài khoản');
        expect(source).toContain('Thử lại danh mục kho');
        expect(source).toContain('Thử lại đơn mua hàng');
        expect(source).toContain("parsePurchaseVoucherCollection<any>(res.data, 'purchase orders')");
        expect(source).not.toContain('}).catch(() => {});');
        expect(source).not.toContain('} catch (e) {\n                return [];');
    });
});
