import { describe, expect, it } from 'vitest';
import source from './PurchaseInvoices.tsx?raw';

describe('purchase invoice duplicate evidence boundary', () => {
    it('requires a persisted id before reporting duplicate success', () => {
        expect(source).toContain('const persistedId = duplicated?.id;');
        expect(source).toContain('Máy chủ không trả về chứng từ nhân bản đã lưu');
        expect(source).toContain('Nhân bản chứng từ mua hàng thành công!');
    });
});
