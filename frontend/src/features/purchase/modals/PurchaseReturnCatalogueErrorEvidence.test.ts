import { describe, expect, it } from 'vitest';
import source from './PurchaseReturnModal.tsx?raw';

describe('purchase return catalogue loading boundary', () => {
    it('keeps failed supplier/item/warehouse selectors visible and retryable', () => {
        expect(source).toContain('parsePurchaseReturnCollection');
        expect(source).toContain('Thử lại danh mục nhà cung cấp');
        expect(source).toContain('Thử lại danh mục hàng hóa');
        expect(source).toContain('Thử lại danh mục kho');
        expect(source).not.toContain('} catch {\n                return [];');
    });
});
