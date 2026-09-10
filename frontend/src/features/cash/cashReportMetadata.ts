import type { CashReportResponse } from './cashReportContract';
import { formatCashReportCell } from './cashReportFormatting';

export const CASH_REPORT_SUMMARY_LABELS: Record<string, string> = { row_count: 'Số dòng', total_receipts: 'Tổng thu', total_payments: 'Tổng chi', net_cash_flow: 'Lưu chuyển tiền thuần', opening_balance: 'Số dư đầu kỳ', closing_balance: 'Số dư cuối kỳ' };

export function cashReportMetadata({ report }: CashReportResponse): [string, string][] {
  const date = (value: string) => formatCashReportCell({ key: '', label: '', type: 'date' }, value);
  return [
    ['Kỳ báo cáo', `${date(report.date_from)} – ${date(report.date_to)}`],
    ['Tài khoản tiền', report.cash_account ?? 'Tất cả'],
    ['Trạng thái', report.status === 'all' ? 'Tất cả' : formatCashReportCell({ key: '', label: '', type: 'status' }, report.status)],
    ['Tìm kiếm', report.search || 'Không lọc'],
  ];
}

export function cashReportFilterNote(report: CashReportResponse): string | null {
  return report.report.search && (report.report.code === 'CA-01' || report.report.code === 'CA-03')
    ? 'Tổng toàn kỳ · đã lọc dòng hiển thị' : null;
}
