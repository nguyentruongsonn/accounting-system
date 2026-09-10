import { Alert, Button, Table } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import type { CashReportColumn, CashReportResponse } from './cashReportContract';
import { formatCashReportCell } from './cashReportFormatting';
import { CASH_REPORT_SUMMARY_LABELS as SUMMARY_LABELS } from './cashReportMetadata';
import { cashReportSourceRoute } from './cashReportDrilldown';

type CashReportResultProps = { report?: CashReportResponse; loading: boolean; error: boolean; onRetry: () => void };
const moneyColumn: CashReportColumn = { key: 'summary-money', label: '', type: 'money' };
const numberColumn: CashReportColumn = { key: 'summary-number', label: '', type: 'number' };

export function CashReportResult({ report, loading, error, onRetry }: CashReportResultProps) {
  if (error) return <section className="cash-report-result" aria-live="polite"><Alert type="error" showIcon message="Không thể tải báo cáo tiền mặt" description="Máy chủ không trả về dữ liệu báo cáo hợp lệ. Hãy thử lại." action={<Button size="small" onClick={onRetry}>Thử lại</Button>} /></section>;
  if (!report && !loading) return <section className="cash-report-result cash-report-result--instruction"><p>Chọn một mẫu báo cáo để thiết lập kỳ và điều kiện lọc.</p></section>;
  if (!report) return <section className="cash-report-result" aria-busy="true"><p>Đang tải báo cáo…</p></section>;
  const columns: ColumnsType<Record<string, unknown>> = report.columns.map((column) => ({
    title: column.label, dataIndex: column.key, key: column.key, align: column.type === 'money' || column.type === 'number' ? 'right' : undefined,
    render: (value: unknown, record: Record<string, unknown>) => {
      const formatted = formatCashReportCell(column, value);
      if (column.key !== 'voucher_number') return formatted;
      const href = cashReportSourceRoute(record);
      return href ? <a className="cash-report-source-link" href={href} title="Mở chứng từ nguồn">{formatted}</a> : formatted;
    },
  }));
  const summary = Object.entries(report.summary).filter(([key]) => key in SUMMARY_LABELS);
  const emptyMessage = report.summary.opening_balance !== undefined && report.summary.opening_balance !== 0
    ? 'Không có dòng phát sinh trong kỳ; số dư đầu kỳ vẫn được giữ nguyên.' : 'Không có dữ liệu trong kỳ đã chọn.';
  return (
    <section className="cash-report-result" aria-live="polite">
      <header className="cash-report-result__header">
        <div><h2>{report.report.name}</h2><p>{formatCashReportCell({ key: 'date_from', label: '', type: 'date' }, report.report.date_from)} – {formatCashReportCell({ key: 'date_to', label: '', type: 'date' }, report.report.date_to)}{report.report.cash_account ? ` · TK ${report.report.cash_account}` : ''}</p></div>
      </header>
      {report.report.search && (report.report.code === 'CA-01' || report.report.code === 'CA-03') && <p className="cash-report-result__filter-note">Tổng toàn kỳ · đã lọc dòng hiển thị</p>}
      <div className="cash-report-summary-grid" aria-label="Tổng hợp báo cáo">
        {summary.map(([key, value]) => <div className="cash-report-summary-card" key={key}><span>{SUMMARY_LABELS[key]}</span><strong>{formatCashReportCell(key === 'row_count' ? numberColumn : moneyColumn, value)}</strong></div>)}
      </div>
      <div className="cash-report-table-wrap">
        <Table<Record<string, unknown>> rowKey="key" columns={columns} dataSource={report.rows} loading={loading} size="small" pagination={false} sticky scroll={{ x: 'max-content' }} locale={{ emptyText: emptyMessage }} />
      </div>
    </section>
  );
}
