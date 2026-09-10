import { describe, expect, it } from 'vitest';
import source from './CashPaymentRequests.tsx?raw';

describe('cash payment request action evidence boundary', () => {
    it('does not claim an unlinked payment voucher', () => {
        expect(source).not.toContain("window.dispatchEvent(new Event('open-cash-payment'))");
        expect(source).toContain('Lập phiếu chi (chưa khả dụng)');
        expect(source).toContain('không tự suy diễn tài khoản kế toán');
        expect(source).toContain('amount: number | null');
        expect(source).toContain("value === null ? '—'");
    });
});
