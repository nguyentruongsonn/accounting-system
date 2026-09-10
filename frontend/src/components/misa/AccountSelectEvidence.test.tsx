import { describe, expect, it } from 'vitest';
import source from './AccountSelect.tsx?raw';

describe('account select evidence boundary', () => {
    it('uses only server-provided accounts and does not ship a fallback chart', () => {
        expect(source).toContain('const source = Array.isArray(accounts) ? accounts.filter(Boolean) : [];');
        expect(source).toContain('Chưa có tài khoản từ máy chủ');
        expect(source).not.toContain('DEFAULT_CHART_OF_ACCOUNTS');
        expect(source).not.toContain("code: '1111'");
        expect(source).not.toContain("code: '131'");
        expect(source).not.toContain("code: '331'");
        expect(source).not.toContain('Math.random');
    });

    it('keeps the account popup body scrollable through AdaptiveSelect geometry', () => {
        expect(source).toContain('misa-account-dropdown-body');
        expect(source).not.toContain("maxHeight: 'none'");
        expect(source).not.toContain("overflow: 'visible'");
    });
});
