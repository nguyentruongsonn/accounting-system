import type { CashReportColumn } from './cashReportContract';

const STATUS_LABELS: Record<string, string> = {
  posted: 'Đã ghi sổ',
  draft: 'Bản nháp',
  voided: 'Đã hủy',
};

export function formatCashReportCell(column: CashReportColumn, value: unknown): string {
  if (value === null || value === undefined) return '—';
  if (column.type === 'money' && typeof value === 'number') return `${new Intl.NumberFormat('vi-VN').format(value)} ₫`;
  if (column.type === 'date' && typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
    const [year, month, day] = value.split('-');
    return `${day}/${month}/${year}`;
  }
  if (column.type === 'status' && typeof value === 'string') return STATUS_LABELS[value] ?? value;
  if (column.key === 'direction' && value === 'receipt') return 'Thu';
  if (column.key === 'direction' && value === 'payment') return 'Chi';
  return String(value);
}
