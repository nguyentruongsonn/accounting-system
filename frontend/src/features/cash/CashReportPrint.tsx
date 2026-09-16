import type { CashReportResponse } from './cashReportContract';
import { formatCashReportCell } from './cashReportFormatting';
import { CASH_REPORT_SUMMARY_LABELS, cashReportFilterNote, cashReportMetadata } from './cashReportMetadata';

export function CashReportPrint({ report }: { report: CashReportResponse }) {
  const note = cashReportFilterNote(report);
  return <article className="cash-report-print">
    <header><h1>{report.report.name}</h1><p>{report.report.code}</p>
      {cashReportMetadata(report).map(([label, value]) => <p key={label}>{label}: {value}</p>)}
      {note && <p>{note}</p>}
    </header>
    <section aria-label="Tổng hợp báo cáo">{Object.entries(report.summary).map(([key, value]) => <p key={key}>
      {CASH_REPORT_SUMMARY_LABELS[key] ?? key}: {formatCashReportCell({ key, label: '', type: key === 'row_count' ? 'number' : 'money' }, value)}
    </p>)}</section>
    <table><thead><tr>{report.columns.map(column => <th key={column.key} scope="col">{column.label}</th>)}</tr></thead>
      <tbody>{report.rows.map(row => <tr key={String(row.key)}>{report.columns.map(column => <td key={column.key} className={column.type === 'money' || column.type === 'number' ? 'cash-report-print__number' : undefined}>{formatCashReportCell(column, row[column.key])}</td>)}</tr>)}</tbody>
    </table>
    {report.rows.length === 0 && <p>Không có dòng phát sinh trong kỳ.</p>}
  </article>;
}
