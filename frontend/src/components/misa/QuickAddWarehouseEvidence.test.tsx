import { describe, expect, it } from 'vitest';
import source from './QuickAddWarehouseModal.tsx?raw';

describe('quick-add warehouse evidence boundary', () => {
    it('does not invent a warehouse code/account or report malformed save success', () => {
        expect(source).toContain('default_account: values.default_account || undefined');
        expect(source).toContain('data.id === undefined || data.id === null');
        expect(source).toContain('Chưa có tài khoản kho từ máy chủ');
        expect(source).not.toContain('Math.random');
        expect(source).not.toContain("default_account: '156'");
        expect(source).not.toContain('KHO_${Math.floor');
        expect(source).not.toContain("{ value: '156', label: '156 - Hàng hóa' }");
        expect(source).toContain('isError');
        expect(source).toContain('Thử lại');
        expect(source).toContain('getPopupContainer={() => document.body}');
        expect(source).not.toContain('catch (e) {\n                return [];');
    });
});
