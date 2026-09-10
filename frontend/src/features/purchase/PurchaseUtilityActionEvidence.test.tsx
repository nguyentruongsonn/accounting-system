import { describe, expect, it } from 'vitest';
import source from './PurchaseWorkspace.tsx?raw';

describe('purchase utility action wiring', () => {
  it('does not expose utilities whose server contracts are unavailable', () => {
    for (const unsupportedUtility of [
      'Đối trừ chứng từ công nợ',
      'Bỏ đối trừ chứng từ',
      'Bù trừ công nợ',
      'Đối chiếu công nợ nhà cung cấp (Excel)',
    ]) {
      expect(source).not.toContain(unsupportedUtility);
    }

    expect(source).not.toContain('AgainstVoucherModal');
    expect(source).not.toContain('UnAgainstVoucherModal');
    expect(source).not.toContain('DebtOffsetModal');
    expect(source).not.toContain('DebtReconciliationModal');
  });
});
