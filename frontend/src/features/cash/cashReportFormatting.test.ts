import { describe, expect, it } from 'vitest';
import { formatCashReportCell } from './cashReportFormatting';
describe('formatCashReportCell', () => {
  it('formats money, zero, ISO dates, direction, and statuses for the report table', () => {
    expect(formatCashReportCell({ key: 'amount', label: 'Số tiền', type: 'money' }, 1200)).toBe('1.200 ₫'); expect(formatCashReportCell({ key: 'amount', label: 'Số tiền', type: 'money' }, 0)).toBe('0 ₫'); expect(formatCashReportCell({ key: 'posting_date', label: 'Ngày', type: 'date' }, '2026-08-01')).toBe('01/08/2026'); expect(formatCashReportCell({ key: 'direction', label: 'Loại', type: 'text' }, 'receipt')).toBe('Thu'); expect(formatCashReportCell({ key: 'direction', label: 'Loại', type: 'text' }, 'payment')).toBe('Chi'); expect(formatCashReportCell({ key: 'description', label: 'Diễn giải', type: 'text' }, 'receipt')).toBe('receipt'); expect(formatCashReportCell({ key: 'status', label: 'Trạng thái', type: 'status' }, 'posted')).toBe('Đã ghi sổ'); expect(formatCashReportCell({ key: 'status', label: 'Trạng thái', type: 'status' }, 'draft')).toBe('Bản nháp'); expect(formatCashReportCell({ key: 'status', label: 'Trạng thái', type: 'status' }, 'voided')).toBe('Đã hủy');
  });
});
