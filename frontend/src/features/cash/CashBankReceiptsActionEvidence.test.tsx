import { describe, expect, it } from 'vitest';
import cashSource from './CashReceipts.tsx?raw';
import bankSource from '../bank/BankReceipts.tsx?raw';

describe('cash/bank receipt action evidence boundary', () => {
    it('requires persisted resources or server messages before reporting success', () => {
        expect(cashSource).toContain("throw new Error(`Invalid ${resource} response`)");
        expect(cashSource).toContain("parseCashReceiptCollection(data, 'cash receipts')");
        expect(cashSource).toContain("parseCashReceiptCollection(data, 'chart-of-accounts catalogue')");
        expect(cashSource).toContain('const persistedReceipt = data?.res?.data?.data ?? data?.res?.data;');
        expect(cashSource).toContain('Máy chủ không trả về phiếu thu đã lưu');
        expect(cashSource).toContain('Máy chủ không trả về phiếu thu nhân bản');
        expect(cashSource).toContain("getApiErrorMessage(err, 'Không thể ghi sổ phiếu thu.')");
        expect(cashSource).toContain("getApiErrorMessage(err, 'Không thể bỏ ghi sổ phiếu thu!')");
        expect(cashSource).toContain("typeof response?.data?.message !== 'string'");
        expect(bankSource).toContain('const persistedReceipt = response?.data?.data ?? response?.data;');
        expect(bankSource).toContain('Máy chủ không trả về phiếu thu tiền gửi đã lưu');
        expect(bankSource.indexOf('const persistedReceipt = response?.data?.data ?? response?.data;'))
            .toBeLessThan(bankSource.indexOf("message.success('Lưu chứng từ Thu tiền gửi thành công!');"));
    });
});
