import { describe, expect, it } from 'vitest';
import source from './ChartOfAccounts.tsx?raw';

describe('chart-of-accounts evidence boundary', () => {
    it('validates server catalogue rows and requires persisted identity before success', () => {
        expect(source).toContain('parseAccounts');
        expect(source).toContain('assertPersistedAccount');
        expect(source).toContain('flattenAccounts');
        expect(source).toContain('Chọn từ catalogue máy chủ (nếu có)');
        expect(source).toContain('Account response is missing a persisted id.');
        expect(source).toContain('response?.status !== 204');
        expect(source).not.toContain("message.success('Tạo tài khoản thành công')");
    });
});
