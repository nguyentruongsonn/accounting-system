import { describe, expect, it } from 'vitest';
import { cashVoucherStatusLabel, cashVoucherStatusTone, isVoidedCashVoucher } from './cashVoucherStatus';

describe('cash voucher status semantics', () => {
    it('keeps voided and cancelled vouchers separate from drafts', () => {
        expect(isVoidedCashVoucher({ status: 'voided', is_posted: false })).toBe(true);
        expect(isVoidedCashVoucher({ status: 'cancelled', is_posted: false })).toBe(true);
        expect(cashVoucherStatusLabel({ status: 'voided', is_posted: false })).toBe('Đã hủy');
        expect(cashVoucherStatusTone({ status: 'cancelled', is_posted: false })).toBe('misa-apple-pill-red');
    });

    it('only reports posted or draft when the server provides explicit evidence', () => {
        expect(cashVoucherStatusLabel({ status: 'posted', is_posted: true })).toBe('Đã ghi sổ');
        expect(cashVoucherStatusLabel({ status: 'draft', is_posted: false })).toBe('Bản nháp');
        expect(cashVoucherStatusLabel({})).toBe('—');
        expect(cashVoucherStatusTone({})).toBe('misa-apple-pill-blue');
    });
});
