import { utils, write, type WorkBook } from 'xlsx';
import { saveAs } from 'file-saver';
import type { CashReportResponse } from './cashReportContract';
import { formatCashReportCell } from './cashReportFormatting';
import { CASH_REPORT_SUMMARY_LABELS, cashReportFilterNote, cashReportMetadata } from './cashReportMetadata';

export function buildCashReportWorkbook(report: CashReportResponse): WorkBook {
  const note = cashReportFilterNote(report);
  const values = [
    [report.report.code, report.report.name],
    ...cashReportMetadata(report),
    ...(note ? [[note]] : []),
    ...Object.entries(report.summary).map(([key, value]) => [CASH_REPORT_SUMMARY_LABELS[key] ?? key, value]),
    [],
    report.columns.map(column => column.label),
    ...report.rows.map(row => report.columns.map(column => {
      const value = row[column.key];
      return (column.type === 'money' || column.type === 'number') && typeof value === 'number'
        ? value : formatCashReportCell(column, value);
    })),
  ];
  // aoa_to_sheet creates string cells (not formulas) for all string values,
  // including labels/search/descriptions beginning with '='.
  const sheet = utils.aoa_to_sheet(values);
  sheet['!cols'] = report.columns.map(column => ({ wch: column.type === 'text' ? 24 : 18 }));
  const workbook = utils.book_new();
  utils.book_append_sheet(workbook, sheet, report.report.code);
  return workbook;
}
export function cashReportFilename({ report }: CashReportResponse): string {
  return `${report.code}_${report.date_from}_${report.date_to}.xlsx`;
}
export function exportCashReport(report: CashReportResponse): void {
  const bytes = write(buildCashReportWorkbook(report), { type: 'array', bookType: 'xlsx' });
  saveAs(new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), cashReportFilename(report));
}
