import { describe, expect, it } from 'vitest';
import collectByInvoice from './CollectByInvoiceModal.tsx?raw';
import collectMultiCustomer from './CollectMultiCustomerModal.tsx?raw';
import payByInvoice from './PayByInvoiceModal.tsx?raw';
import payrollPayment from './PayrollPaymentModal.tsx?raw';
import taxPayment from './TaxPaymentModal.tsx?raw';
import insurancePayment from './InsurancePaymentModal.tsx?raw';

describe('cash special-payment source evidence', () => {
  it('does not manufacture invoice identities or dates when API fields are absent', () => {
    for (const source of [collectByInvoice, collectMultiCustomer, payByInvoice]) {
      expect(source).not.toContain('`HĐ-${inv.id}`');
      expect(source).not.toContain('`MH-${inv.id}`');
      expect(source).not.toContain("dayjs().add(30, 'day').format('YYYY-MM-DD')");
      expect(source).not.toContain('KH${String(inv.customer_id).padStart(4, \'0\')}');
      expect(source).toContain('const sourceMoney = (value: unknown): number | null');
      expect(source).toContain("value === null || value === undefined || value === ''");
      expect(source).toContain("? '—'");
      expect(source).not.toContain('Number(inv.total_amount || 0)');
    }
  });

  it('does not turn the employee master catalogue into a synthetic payroll statement', () => {
    expect(payrollPayment).toContain('The employee master endpoint is not a payroll statement endpoint.');
    expect(payrollPayment).not.toContain('15000000 + (idx * 2500000)');
    expect(payrollPayment).not.toContain('`NV${String(idx + 1).padStart(4, \'0\')}`');
    expect(payrollPayment).not.toContain('`Nhân viên ${idx + 1}`');
    expect(payrollPayment).not.toContain("contact_id: 'ALL_EMP'");
    expect(payrollPayment).toContain('Chi trả lương chưa khả dụng');
    expect(payrollPayment).toContain('disabled={selectedEmployees.length === 0}');
  });

  it('keeps tax and insurance authorities empty until a source contract exists', () => {
    expect(taxPayment).toContain('Nộp thuế chưa khả dụng');
    expect(taxPayment).toContain('placeholder="Chưa có cơ quan thuế/kho bạc từ máy chủ"');
    expect(taxPayment).not.toContain('defaultValue="kbnn-hcm"');
    expect(taxPayment).not.toContain('Kho bạc Nhà nước TP. Hồ Chí Minh');
    expect(insurancePayment).toContain('Nộp bảo hiểm chưa khả dụng');
    expect(insurancePayment).toContain('placeholder="Chưa có cơ quan BHXH từ máy chủ"');
    expect(insurancePayment).not.toContain('defaultValue="bhxh-q1"');
    expect(insurancePayment).not.toContain('Bảo hiểm Xã hội Quận 1, TP.HCM');
    expect(taxPayment).not.toContain("credit_account: '1111'");
    expect(taxPayment).not.toContain("debit_account: tax.debit_account || '33311'");
    expect(insurancePayment).not.toContain("credit_account: '1111'");
    expect(insurancePayment).not.toContain("debit_account: ins.debit_account || '3383'");
    expect(taxPayment).toContain('Nộp thuế chưa khả dụng: chưa có nguồn nghĩa vụ thuế và API chứng từ máy chủ.');
    expect(insurancePayment).toContain('Nộp bảo hiểm chưa khả dụng: chưa có bảng kê và API chứng từ máy chủ.');
    for (const source of [collectByInvoice, collectMultiCustomer, payByInvoice]) {
        expect(source).not.toContain("debit_account: '1111'");
        expect(source).not.toContain("credit_account: '1111'");
        expect(source).not.toContain("debit_account: '331'");
        expect(source).not.toContain("credit_account: '131'");
    }
    expect(payrollPayment).not.toContain("debit_account: '3341'");
    expect(payrollPayment).not.toContain("credit_account: '1111'");
  });

  it('keeps invoice-payment lookups fail-visible and outside modal clipping', () => {
    expect(collectByInvoice).toContain('Không thể tải dữ liệu thu tiền theo hóa đơn');
    expect(collectByInvoice).toContain('Thử lại');
    expect(collectByInvoice).toContain('getPopupContainer={() => document.body}');
    expect(collectByInvoice).not.toContain('} catch {\n                return [];');
    expect(payByInvoice).toContain('Không thể tải dữ liệu trả tiền theo hóa đơn');
    expect(payByInvoice).toContain('Thử lại');
    expect(payByInvoice).toContain('getPopupContainer={() => document.body}');
    expect(payByInvoice).not.toContain('} catch {\n                return [];');
    expect(collectMultiCustomer).toContain('Không thể tải hóa đơn chưa thu');
    expect(collectMultiCustomer).toContain('Thử lại');
    expect(collectMultiCustomer).not.toContain('} catch {\n                return [];');
  });

  it('uses persisted sales-invoice collection endpoints instead of opening a fake handoff', () => {
    expect(collectByInvoice).toContain("api.get('/sales/invoices/outstanding'");
    expect(collectByInvoice).toContain('`/sales/invoices/${invoice.id}/collect`');
    expect(collectByInvoice).toContain("response.data?.data?.receipt?.id");
    expect(collectByInvoice).not.toContain("window.dispatchEvent(new CustomEvent('open-cash-receipt'");
    expect(collectMultiCustomer).toContain("api.get('/sales/invoices/outstanding'",);
    expect(collectMultiCustomer).toContain('`/sales/invoices/${invoice.id}/collect`');
    expect(collectMultiCustomer).not.toContain("window.dispatchEvent(new CustomEvent('open-cash-receipt'");
  });
});
