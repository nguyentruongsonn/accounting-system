import { describe, expect, it } from 'vitest';
import source from './BorrowingContracts.tsx?raw';

describe('borrowing contract accounting evidence boundary', () => {
    it('rejects malformed account and contract catalogue envelopes', () => {
        expect(source).toContain("throw new Error(`Invalid ${resource} response`)");
        expect(source).toContain("parseBorrowingCollection(data, 'chart-of-accounts catalogue')");
        expect(source).toContain("parseBorrowingCollection(data, 'borrowing contracts')");
    });

    it('uses tenant account evidence and does not ship lender/account samples', () => {
        expect(source).toContain("api.get('/master/accounts')");
        expect(source).toContain('const accountOptions = accountPayload');
        expect(source).toContain('Chưa có tài khoản từ máy chủ');
        expect(source).toContain('Máy chủ không trả về khế ước đã lưu');
        expect(source).not.toContain("debit_account: values.debit_account || '3411'");
        expect(source).not.toContain("interest_account: values.interest_account || '635'");
        expect(source).not.toContain('Ngân hàng TMCP Ngoại thương Việt Nam');
        expect(source).not.toContain("initialValue=\"3411\"");
        expect(source).not.toContain("initialValue=\"635\"");
    });
});
