import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CashReportResult } from './CashReportResult';
import { parseCashReportResponse } from './cashReportContract';

describe('CashReportResult source drilldown', () => {
  it('renders a safe source link for a cash voucher row', () => {
    const report = parseCashReportResponse({
      report: { code: 'S03a1-DNN', name: 'Sổ nhật ký thu tiền', date_from: '2026-08-01', date_to: '2026-08-31', status: 'posted', cash_account: null, search: '' },
      columns: [
        { key: 'posting_date', label: 'Ngày hạch toán', type: 'date' }, { key: 'voucher_date', label: 'Ngày chứng từ', type: 'date' },
        { key: 'voucher_number', label: 'Số chứng từ', type: 'text' }, { key: 'contact_name', label: 'Đối tượng', type: 'text' },
        { key: 'description', label: 'Diễn giải', type: 'text' }, { key: 'cash_account', label: 'Tài khoản tiền', type: 'text' },
        { key: 'counterpart_account', label: 'Tài khoản đối ứng', type: 'text' }, { key: 'amount', label: 'Số tiền', type: 'money' },
        { key: 'status', label: 'Trạng thái', type: 'status' },
      ],
      summary: { row_count: 1, total_receipts: 1000 },
      rows: [{ key: 'receipt:42', posting_date: '2026-08-10', voucher_date: '2026-08-10', voucher_number: 'PT-42', contact_name: 'Khách hàng', description: 'Thu tiền', cash_account: '1111', counterpart_account: '131', amount: 1000, status: 'posted', source_type: 'receipt', source_id: 42 }],
    });

    render(<CashReportResult report={report} loading={false} error={false} onRetry={() => undefined} />);

    expect(screen.getByRole('link', { name: 'PT-42' })).toHaveAttribute('href', '/cash/receipts?source_id=42');
  });
});
