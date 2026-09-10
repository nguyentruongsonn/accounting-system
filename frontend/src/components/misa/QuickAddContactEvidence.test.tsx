import { describe, expect, it } from 'vitest';
import source from './QuickAddContactModal.tsx?raw';

describe('quick-add contact evidence boundary', () => {
    it('requires explicit code/account evidence and a persisted response', () => {
        expect(source).toContain('const autoCode = values.code?.trim();');
        expect(source).toContain('Mã ${isCustomer ? \'khách hàng\' : \'nhà cung cấp\'} phải do người dùng nhập hoặc máy chủ cấp.');
        expect(source).toContain('default_account: values.debt_account || undefined');
        expect(source).toContain('debt_account: values.debt_account || undefined');
        expect(source).toContain("api.get('/master/accounts')");
        expect(source).toContain('notFoundContent="Chưa có tài khoản từ máy chủ"');
        expect(source).toContain('const persistedContact = res?.data;');
        expect(source).toContain('persistedContact.id === undefined || persistedContact.id === null');

        expect(source).not.toContain('Math.random');
        expect(source).not.toContain("values.debt_account || (isCustomer ? '131' : '331')");
        expect(source).not.toContain("initialValue={isCustomer ? \"131\" : \"331\"}");
        expect(source).not.toContain("{ value: '131', label: '131 - Phải thu của khách hàng' }");
        expect(source).not.toContain("{ value: '331', label: '331 - Phải trả cho người bán' }");
        expect(source).not.toContain("values.payment_term || 'Gối đầu 30 ngày'");
    });
});
