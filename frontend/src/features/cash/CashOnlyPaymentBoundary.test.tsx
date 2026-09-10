import { describe, expect, it } from 'vitest';
import salesInvoiceSource from '../sales/SalesInvoices.tsx?raw';
import salesReturnSource from '../sales/components/SalesReturnMasterCard.tsx?raw';
import salesDiscountSource from '../sales/components/SalesDiscountMasterCard.tsx?raw';
import purchaseReturnSource from '../purchase/modals/PurchaseReturnModal.tsx?raw';
import purchaseDiscountSource from '../purchase/modals/PurchaseDiscountModal.tsx?raw';
import purchaseServiceSource from '../purchase/modals/PurchaseServiceModal.tsx?raw';
import purchaseDetailSource from '../purchase/modals/PurchaseVoucherDetailModal.tsx?raw';
import purchasePaySource from '../purchase/modals/PayVendorByInvoiceModal.tsx?raw';
import purchaseMultipleSource from '../purchase/modals/PurchaseMultipleInvoicesModal.tsx?raw';
import disposalSource from '../fixed-asset/modals/AssetDisposalModal.tsx?raw';
import cashPaymentsSource from './CashPayments.tsx?raw';
import cashReceiptsSource from './CashReceipts.tsx?raw';
import cashTransactionsSource from './CashTransactions.tsx?raw';
import { isOutOfScopeCashPaymentType } from './cashVoucherScope';

describe('internal cash-only payment boundary', () => {
  it('does not expose deposit payment choices in internal transaction forms', () => {
    const forms = [
      salesInvoiceSource,
      salesReturnSource,
      salesDiscountSource,
      purchaseReturnSource,
      purchaseDiscountSource,
      purchaseServiceSource,
      purchaseDetailSource,
      purchasePaySource,
      purchaseMultipleSource,
      disposalSource,
    ].join('\n');

    expect(forms).not.toContain('Trả lại tiền gửi');
    expect(forms).not.toContain('Thu tiền gửi ngay');
    expect(forms).not.toContain('Thu tiền gửi</Radio>');
    expect(forms).not.toContain('Ủy nhiệm chi (1121)');
    expect(forms).not.toContain('Tiền gửi ngân hàng (Nợ TK 1121)');
    expect(cashPaymentsSource).not.toContain("{ value: 'Gửi tiền vào NH', label: 'Gửi tiền vào NH' }");
    expect(cashReceiptsSource).not.toContain("{ value: 'Rút tiền gửi về nhập quỹ', label: 'Rút tiền gửi về nhập quỹ' }");
    expect(cashPaymentsSource).not.toContain("api.get('/bank/accounts')");
    expect(cashReceiptsSource).not.toContain("api.get('/bank/accounts')");
    expect(cashPaymentsSource).not.toContain('QuickAddBankAccountModal');
    expect(cashReceiptsSource).not.toContain('QuickAddBankAccountModal');
  });

  it('does not expose out-of-scope insurance or payroll actions in the operational cash tab', () => {
    expect(cashTransactionsSource).not.toContain("key: 'tx-pmt-ins'");
    expect(cashTransactionsSource).not.toContain("key: 'tx-pmt-salary'");
    expect(cashTransactionsSource).not.toContain("label: 'Nộp bảo hiểm'");
    expect(cashTransactionsSource).not.toContain("label: 'Trả lương'");
    expect(cashPaymentsSource).not.toContain("key: 'pay-ins'");
    expect(cashPaymentsSource).not.toContain("key: 'pay-salary'");
    expect(cashReceiptsSource).not.toContain("key: 'pay-ins'");
    expect(cashReceiptsSource).not.toContain("key: 'pay-salary'");
  });

  it('classifies insurance and payroll payment types as legacy-only', () => {
    expect(isOutOfScopeCashPaymentType('4. Trả lương tạm ứng cho nhân viên')).toBe(true);
    expect(isOutOfScopeCashPaymentType('5. Trả lương cho nhân viên')).toBe(true);
    expect(isOutOfScopeCashPaymentType('Nộp bảo hiểm')).toBe(true);
    expect(isOutOfScopeCashPaymentType('8. Chi khác')).toBe(false);
    expect(isOutOfScopeCashPaymentType('1. Trả tiền cho nhà cung cấp (không theo hóa đơn)')).toBe(false);
  });

  it('keeps legacy deposit values fail-closed instead of saving them as cash', () => {
    expect(salesInvoiceSource).toContain('Thanh toán tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ');
    expect(purchaseReturnSource).toContain('Thu tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ');
    expect(purchaseDiscountSource).toContain('Thu tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ');
    expect(purchaseServiceSource).toContain('Thanh toán tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ');
    expect(purchaseDetailSource).toContain('Thanh toán tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ');
    expect(cashPaymentsSource).toContain('Nghiệp vụ tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ');
    expect(cashReceiptsSource).toContain('Nghiệp vụ tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ');
  });
});
