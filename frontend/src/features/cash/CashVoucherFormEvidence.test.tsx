import { describe, expect, it } from 'vitest';
import receiptSource from './CashReceipts.tsx?raw';
import paymentSource from './CashPayments.tsx?raw';
import reasonSource from '../../components/misa/QuickAddReasonModal.tsx?raw';
import referenceSource from '../../components/misa/ReferenceVoucherModal.tsx?raw';

describe('cash voucher form accounting evidence contract', () => {
    it('does not synthesize accounts, contacts, or voucher numbers', () => {
        const source = `${receiptSource}\n${paymentSource}`;

        for (const sample of [
            'KH00001',
            'NCC00001',
            'PT00001',
            'PC00001',
            'Math.random()',
            "debit_account: '1111'",
            "credit_account: '1111'",
            "debit_account: '331'",
            "credit_account: '131'",
            "credit_account: '1121'",
        ]) {
            expect(source).not.toContain(sample);
        }

        expect(source).toContain("api.get('/cash/receipts/next-code')");
        expect(source).toContain("api.get('/cash/payments/next-code')");
        expect(receiptSource).toContain('Không thể cấp số phiếu thu tự động');
        expect(paymentSource).toContain('Không thể cấp số phiếu chi tự động');
        expect(source).not.toContain('}).catch(() => {});');
        expect(source).toContain("message: 'Chọn TK Nợ'");
        expect(source).toContain("message: 'Chọn TK Có'");
        expect(source).not.toContain("line.debit_account || '331'");
        expect(source).not.toContain("line.credit_account || '1111'");
        expect(source).not.toContain("s.debit_account || '6428'");
        expect(source).not.toContain('amount: Number(v.total_amount || 0)');
        expect(source).not.toContain('amount: l.amount || 0');
        expect(receiptSource).toContain('readCashVoucherAmount(v.total_amount ?? v.amount)');
        expect(paymentSource).toContain('readCashPaymentAmount(v.total_amount ?? v.amount)');
        expect(source).not.toContain("value: '6. Gửi tiền vào ngân hàng'");
        expect(receiptSource).not.toContain("value: '3. Rút tiền gửi về nhập quỹ'");
        expect(receiptSource).toContain('isDepositTransferVoucherType');
        expect(source).toContain('isDepositVoucherType');
        expect(paymentSource).toContain('legacyDepositRecord');
        expect(paymentSource).toContain('isLegacyDepositPaymentRecord');
        expect(paymentSource).toContain('menu={{ items: legacyDeposit || isVoided ? viewOnlyItems : mutationItems }}');
        expect(paymentSource).toContain("message.info('Chứng từ tiền gửi ngân hàng chỉ được xem trong phạm vi nội bộ hiện tại.')");
        expect(paymentSource).toContain("handleViewPayment(record, 'view')");
        expect(receiptSource).toContain('isLegacyDepositReceiptRecord');
        expect(receiptSource).toContain("menu={{ items: legacyDeposit || isVoided ? viewOnlyItems : mutationItems }}");
        expect(source).not.toContain('tax_year: 2026');
        expect(source).not.toContain('initialValue={2026}');
        expect(receiptSource).toContain('<Space.Compact');
        expect(receiptSource).not.toContain('<Dropdown.Button');
        expect(reasonSource).toContain("/master/voucher-type-settings/types");
        expect(reasonSource).toContain('serverOptions.filter');
        expect(reasonSource).toContain("option.value !== 'thu_tien_gui'");
        expect(reasonSource).toContain('options={voucherTypeOptions}');
        expect(referenceSource).toContain("includeBankVouchers = false");
        expect(referenceSource).toContain("group.label !== 'Ngân hàng'");
        expect(referenceSource).toContain('options={voucherTypeGroups}');
        expect(referenceSource).toContain('getPopupContainer={() => document.body}');
        expect(receiptSource).not.toContain('fallback to record');
        expect(paymentSource).not.toContain('fallback to record');
    });
});
