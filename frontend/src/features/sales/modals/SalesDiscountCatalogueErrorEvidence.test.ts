import { describe, expect, it } from 'vitest';
import source from './SalesDiscountModal.tsx?raw';

describe('sales discount catalogue loading boundary', () => {
    it('keeps failed customer/item selectors visible and retryable', () => {
        expect(source).toContain('parseSalesDiscountCollection');
        expect(source).toContain('Thử lại danh mục khách hàng');
        expect(source).toContain('Thử lại danh mục hàng hóa');
        expect(source).not.toContain('} catch {\n                return [];');
    });
});
