import { describe, expect, it } from 'vitest';
import bankSource from './BankVoucherPrintModal.tsx?raw';
import voucherSource from './VoucherPrintModal.tsx?raw';

describe('voucher print evidence boundary', () => {
  it('does not print synthetic voucher numbers, account pairs, or bank identities', () => {
    for (const source of [bankSource, voucherSource]) {
      expect(source).not.toContain('PT00001');
      expect(source).not.toContain('PC00001');
      expect(source).not.toContain('NK00001');
      expect(source).not.toContain('XK00001');
      expect(source).not.toContain('BH00001');
      expect(source).not.toContain('001100998877');
      expect(source).not.toContain('0101243150');
    }

    expect(bankSource).toContain("const sourceAccount = printData.bank_account_number ?? '—'");
    expect(bankSource).toContain("const debitAccounts = Array.from(new Set((printData.lines || []).map(l => l.debit_account).filter(Boolean))).join(', ') || '—'");
    expect(voucherSource).toContain("const debitAccounts = Array.from(new Set(rawLines.map(l => l.debit_account).filter(Boolean))).join(', ') || '—'");
    expect(voucherSource).toContain("const voucherNo = printData.voucher_number || printData.invoice_number || printData.order_number || printData.quote_number || '—'");
    expect(voucherSource).not.toContain('`VT000${idx + 1}`');
    expect(voucherSource).not.toContain("l.item_name || l.description || 'Hàng hóa / Dịch vụ'");
    expect(voucherSource).not.toContain("l.unit || 'Cái'");
    expect(voucherSource).not.toContain("'Chứng từ nghiệp vụ khác'");
    expect(voucherSource).toContain("const hasLineAmount = l.amount != null || (qty != null && price != null);");
  });
});
