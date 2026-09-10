import { describe, expect, it } from 'vitest';
import source from './PurchaseServiceModal.tsx?raw';

describe('purchase service catalogue loading boundary', () => {
    it('keeps failed selectors visible and retryable', () => {
        expect(source).toContain('parsePurchaseServiceCollection');
        expect(source).toContain('Thử lại danh mục nhà cung cấp');
        expect(source).toContain('Thử lại danh mục dịch vụ');
        expect(source).toContain('Thử lại danh mục tài khoản');
        expect(source).not.toContain('} catch (e) {\n                return [];');
    });
});
