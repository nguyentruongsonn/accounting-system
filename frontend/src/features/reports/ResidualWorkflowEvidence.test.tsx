import { describe, expect, it } from 'vitest';
import budgetReport from '../budget/BudgetReport.tsx?raw';
import payVendorByInvoice from '../purchase/modals/PayVendorByInvoiceModal.tsx?raw';

describe('residual unavailable workflow evidence', () => {
  it('fails closed on malformed budget-report envelopes instead of reporting success', () => {
    expect(budgetReport).toContain("if (!Array.isArray(apiData)) {");
    expect(budgetReport).toContain('Máy chủ không trả về dữ liệu báo cáo ngân sách hợp lệ.');
  });

  it('keeps vendor payment server-backed and does not seed a supplier', () => {
    expect(payVendorByInvoice).toContain('useState<number | null>(null)');
    expect(payVendorByInvoice).not.toContain('useState<number | null>(1)');
    expect(payVendorByInvoice).toContain("/purchase/invoices/outstanding");
    expect(payVendorByInvoice).toContain("/purchase/invoices/${invoice.id}/pay");
    expect(payVendorByInvoice).not.toContain('Backend chưa công bố API');
  });
});
