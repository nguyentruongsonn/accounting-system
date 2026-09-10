import { describe, expect, it } from 'vitest';
import source from './PurchaseMultipleInvoicesModal.tsx?raw';

describe('purchase multiple-invoice catalogue loading boundary', () => {
    it('keeps failed supplier/item selectors visible and retryable', () => {
        expect(source).toContain('parsePurchaseMultipleCollection');
        expect(source).toContain('Thử lại danh mục nhà cung cấp');
        expect(source).toContain('Thử lại danh mục hàng hóa');
        expect(source).not.toContain('} catch (e) {\n                return [];');
    });
});
