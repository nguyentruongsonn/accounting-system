import { parseCashReportResponse, type CashReportResponse } from './cashReportContract';

/** Deterministic fixture for tests and the isolated diagnostics entry only. */
export function cashReportTestFixture(count = 101): CashReportResponse {
  return parseCashReportResponse({
    report: { code: 'CA-03', name: 'Sổ kế toán chi tiết quỹ tiền mặt', date_from: '2026-08-01', date_to: '2026-08-31', status: 'posted', cash_account: '1111', search: '=literal search' },
    columns: [
      { key: 'voucher_number', label: 'Số chứng từ', type: 'text' },
      { key: 'posting_date', label: 'Ngày hạch toán', type: 'date' },
      { key: 'voucher_date', label: 'Ngày chứng từ', type: 'date' },
      { key: 'direction', label: 'Loại', type: 'text' },
      { key: 'contact_name', label: 'Đối tượng', type: 'text' },
      { key: 'description', label: 'Diễn giải', type: 'text' },
      { key: 'cash_account', label: 'TK tiền', type: 'text' },
      { key: 'counterpart_account', label: 'TK đối ứng', type: 'text' },
      { key: 'receipt_amount', label: 'Thu', type: 'money' },
      { key: 'payment_amount', label: 'Chi', type: 'money' },
      { key: 'running_balance', label: 'Tồn', type: 'money' },
    ],
    summary: { opening_balance: -200, total_receipts: 0, total_payments: 100, closing_balance: -300 },
    rows: Array.from({ length: count }, (_, index) => ({
      key: `fixture-${index}`, voucher_number: index === count - 1 ? 'PT-LAST' : `PT-${index}`,
      posting_date: '2026-08-02', voucher_date: '2026-08-01', direction: 'payment', contact_name: '=1+1',
      description: 'Dữ liệu kiểm thử riêng biệt', cash_account: '1111', counterpart_account: '131',
      receipt_amount: 0, payment_amount: index === count - 1 ? 100 : 0, running_balance: -300,
    })),
  });
}
