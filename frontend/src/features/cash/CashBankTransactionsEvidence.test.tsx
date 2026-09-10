import { describe, expect, it } from 'vitest';
import cashTransactionsSource from './CashTransactions.tsx?raw';
import cashReceiptsSource from './CashReceipts.tsx?raw';
import cashPaymentsSource from './CashPayments.tsx?raw';
import bankPaymentsSource from '../bank/BankPayments.tsx?raw';
import bankTransactionsSource from '../bank/BankTransactions.tsx?raw';
import cashExportSource from './exportToExcel.ts?raw';
import bankExportSource from '../bank/exportToExcel.ts?raw';

describe('cash/bank transaction evidence display contract', () => {
    it('does not synthesize sample contacts, bank accounts, or voucher details', () => {
        const source = `${cashTransactionsSource}\n${bankTransactionsSource}`;

        for (const sample of [
            'KH00001',
            'NCC00001',
            '0011001234567',
            '1903009876543',
            'NTTK00001',
        ]) {
            expect(source).not.toContain(sample);
        }

        expect(source).toContain('Chưa có dòng hạch toán được máy chủ cung cấp.');
        expect(source).toContain('r.bank_account?.account_number');
        expect(source).toContain('p.bank_account?.account_number');
        expect(source).toContain("api.post(`${endpoint}/duplicate`)");
        expect(source).not.toContain('message.success(`Đã nhân bản chứng từ ${record.voucher_number}`)');
    });

    it('fails closed when cash/bank transaction catalogues have malformed 2xx envelopes', () => {
        expect(cashTransactionsSource).toContain('parseCashTransactionsResponse(data, \'cash receipts\')');
        expect(cashTransactionsSource).toContain('parseCashTransactionsResponse(data, \'cash payments\')');
        expect(bankTransactionsSource).toContain('parseBankTransactionsResponse(data, \'bank receipts\')');
        expect(bankTransactionsSource).toContain('parseBankTransactionsResponse(data, \'bank payments\')');
        expect(cashTransactionsSource).not.toContain('(data?.data || [])');
        expect(bankTransactionsSource).not.toContain('(data?.data || [])');
        expect(cashReceiptsSource).toContain('parseCashReceiptCollection(data, \'cash receipts\')');
        expect(cashPaymentsSource).toContain('parseCashPaymentCollection(data)');
        expect(cashPaymentsSource).toContain('parseCashPaymentCatalogue(data, \'chart-of-accounts catalogue\')');
        expect(cashPaymentsSource).not.toContain('return Array.isArray(data) ? data : (data?.data || [])');
    });

    it('requires server evidence before cash/bank mutation success', () => {
        expect(cashPaymentsSource).toContain('Máy chủ không trả về phiếu chi đã lưu');
        expect(cashPaymentsSource).toContain('Number.isInteger(Number(persistedPayment.id))');
        expect(cashPaymentsSource).toContain('Máy chủ không xác nhận đã ghi sổ phiếu chi');
        expect(cashPaymentsSource).toContain("getApiErrorMessage(err, 'Không thể ghi sổ phiếu chi.')");
        expect(cashPaymentsSource).toContain("getApiErrorMessage(err, 'Không thể bỏ ghi sổ phiếu chi!')");
        expect(cashPaymentsSource).toContain('Máy chủ không trả về phiếu chi nhân bản đã lưu');
        expect(cashReceiptsSource).toContain('Number.isInteger(Number(persistedReceipt.id))');
        expect(cashTransactionsSource).toContain('Máy chủ không xác nhận trạng thái ghi sổ/bỏ ghi sổ chứng từ tiền mặt.');
        expect(cashTransactionsSource).toContain('Máy chủ không xác nhận đã xóa chứng từ tiền mặt.');
        expect(bankPaymentsSource).toContain('Máy chủ không trả về ủy nhiệm chi đã lưu');
        expect(cashPaymentsSource).toContain('Máy chủ không trả về phiếu chi đã lưu');
        expect(cashPaymentsSource).toContain('Máy chủ không xác nhận đã ghi sổ phiếu chi');
        expect(cashPaymentsSource).toContain('Máy chủ không trả về phiếu chi nhân bản đã lưu');
        expect(bankTransactionsSource).toContain('Máy chủ không xác nhận trạng thái ghi sổ/bỏ ghi sổ chứng từ tiền gửi');
        expect(bankTransactionsSource).toContain('Máy chủ không xác nhận đã xóa chứng từ tiền gửi');
        expect(bankTransactionsSource).toContain('Máy chủ không trả về chứng từ tiền gửi đã cập nhật');
    });

    it('does not turn missing cash amounts or statuses into zero/posted evidence', () => {
        expect(cashTransactionsSource).toContain('sumCashAmounts');
        expect(cashTransactionsSource).toContain("return amount === null ? '—'");
        expect(cashTransactionsSource).not.toContain('format(amt || 0)');
        expect(cashTransactionsSource).not.toContain('format(selectedRow?.total_amount || 0)');
        expect(cashExportSource).toContain('t.total_amount ??');
        expect(cashExportSource).toContain('t.is_posted === true');
        expect(cashExportSource).not.toContain('t.total_amount || 0');
        expect(cashReceiptsSource).toContain('sumCashVoucherAmounts');
        expect(cashReceiptsSource).toContain("return amount === null ? '—'");
        expect(cashReceiptsSource).not.toContain('format(totalReceipts)');
        expect(cashPaymentsSource).toContain("return Number.isFinite(amount)");
        expect(cashPaymentsSource).not.toContain("format(val || 0)");
    });

    it('labels only explicit posting states and does not misclassify missing evidence', () => {
        expect(cashTransactionsSource).toContain('function cashPostingStatusLabel');
        expect(cashTransactionsSource).toContain("if (value === true) return 'Đã ghi sổ'");
        expect(cashTransactionsSource).toContain("if (value === false) return 'Bản nháp'");
        expect(cashTransactionsSource).toContain("return '—'");
        expect(cashTransactionsSource).not.toContain("? 'Đã ghi sổ' : 'Bỏ ghi sổ'");
        expect(cashTransactionsSource).toContain("typeof isPosted === 'boolean'");
        expect(cashTransactionsSource).toContain('Chưa xác minh trạng thái');
        expect(bankTransactionsSource).toContain('function bankPostingStatusLabel');
        expect(bankTransactionsSource).toContain("if (value === true) return 'Đã ghi sổ'");
        expect(bankTransactionsSource).toContain("if (value === false) return 'Bản nháp'");
        expect(bankTransactionsSource).toContain("return '—'");
        expect(bankTransactionsSource).not.toContain("? 'Đã ghi sổ' : 'Bỏ ghi sổ'");
        expect(bankTransactionsSource).toContain("typeof isPosted === 'boolean'");
        expect(bankTransactionsSource).toContain('Chưa xác minh trạng thái');
        expect(cashExportSource).toContain("t.is_posted === false ? 'Bản nháp'");
        expect(bankExportSource).toContain("t.is_posted === true ? 'Đã ghi sổ'");
        expect(bankExportSource).toContain("t.is_posted === false ? 'Bản nháp'");
        expect(bankTransactionsSource).toContain('sumBankAmounts');
        expect(bankTransactionsSource).toContain("return amount === null ? '—'");
        expect(bankTransactionsSource).not.toContain('format(amt || 0)');
        expect(bankTransactionsSource).not.toContain('selectedRow?.total_amount || 0');
        expect(bankExportSource).toContain('t.total_amount ?? t.amount ??');
        expect(bankExportSource).not.toContain('t.total_amount || t.amount || 0');
        expect(cashTransactionsSource).toContain("return '—'");
        expect(bankTransactionsSource).toContain("return '—'");
        expect(bankExportSource).toContain(": ''");
    });

    it('keeps voided vouchers distinct from drafts in the combined cash list', () => {
        expect(cashTransactionsSource).toContain("if (normalizedStatus === 'voided' || normalizedStatus === 'cancelled') return 'Đã hủy'");
        expect(cashTransactionsSource).toContain('cashPostingStatusLabel(isPosted, record?.status)');
        expect(cashTransactionsSource).toContain('misa-apple-pill-red');
    });

    it('keeps standalone receipt/payment lists aligned with canceled-voucher semantics', () => {
        for (const source of [cashReceiptsSource, cashPaymentsSource]) {
            expect(source).toContain("import { cashVoucherStatusLabel, cashVoucherStatusTone, isVoidedCashVoucher } from './cashVoucherStatus';");
            expect(source).toContain('cashVoucherStatusLabel(record)');
            expect(source).toContain('cashVoucherStatusTone(record)');
            expect(source).toContain('const isVoided = isVoidedCashVoucher(record);');
            expect(source).toContain('legacyDeposit || isVoided ? viewOnlyItems : mutationItems');
        }
    });
});
