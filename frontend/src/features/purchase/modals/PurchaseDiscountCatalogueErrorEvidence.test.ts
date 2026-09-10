import { describe, expect, it } from 'vitest';
import source from './PurchaseDiscountModal.tsx?raw';

describe('purchase discount catalogue loading boundary', () => {
    it('keeps failed supplier/item selectors visible and retryable', () => {
        expect(source).toContain('parsePurchaseDiscountCollection');
        expect(source).toContain('Thử lại danh mục nhà cung cấp');
        expect(source).toContain('Thử lại danh mục hàng hóa');
        expect(source).not.toContain('} catch {\n                return [];');
    });
});
