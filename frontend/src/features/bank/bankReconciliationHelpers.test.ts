import { describe, expect, it } from 'vitest';
import { formatExactAmount, reconciliationStatusLabel } from './bankReconciliationHelpers';

describe('bank reconciliation display helpers', () => {
  it('formats immutable exact amounts without Number precision loss', () => {
    expect(formatExactAmount('9007199254740993.01', 2, 'VND')).toBe('9.007.199.254.740.993,01 VND');
    expect(formatExactAmount('125000', 0, 'VND')).toBe('125.000 VND');
  });

  it('uses explicit evidence statuses rather than inferring a reconciled balance', () => {
    expect(reconciliationStatusLabel('unmatched')).toBe('Chưa ghép');
    expect(reconciliationStatusLabel('exception_open')).toBe('Ngoại lệ đang mở');
  });
});
