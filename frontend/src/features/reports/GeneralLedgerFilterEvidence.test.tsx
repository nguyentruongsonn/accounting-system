import { describe, expect, it } from 'vitest';
import source from './GeneralLedger.tsx?raw';

describe('general ledger filter evidence boundary', () => {
    it('does not silently select account 111 when the server has not supplied a choice', () => {
        expect(source).toContain("const rows = Array.isArray(response?.data)");
        expect(source).toContain("response?.data?.data;");
        expect(source).toContain("disabled={accounts.length === 0}");
        expect(source).toContain('Chưa có tài khoản từ máy chủ');
        expect(source).not.toContain("account_code: '111'");
        expect(source).not.toContain("value: '111'");
    });
});
