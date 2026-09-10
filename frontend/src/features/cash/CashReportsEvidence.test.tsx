import { describe, expect, it } from 'vitest';
import source from './CashReports.tsx?raw';
describe('cash management report evidence boundary', () => {
  it('uses only the reviewed report query surface without voucher mutations', () => {
    expect(source).toContain('useCashReportQuery'); expect(source).toContain('CashReportSelector'); expect(source).toContain('CashReportResult');
    expect(source).not.toContain("/cash/receipts"); expect(source).not.toContain("/cash/payments"); expect(source).not.toContain('api.post'); expect(source).not.toContain('api.put'); expect(source).not.toContain('PageHeader');
  });
});
