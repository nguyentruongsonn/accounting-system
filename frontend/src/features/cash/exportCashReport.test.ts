import { describe, expect, it, vi } from 'vitest';
import * as XLSX from 'xlsx';
import { buildCashReportWorkbook, cashReportFilename, exportCashReport } from './exportCashReport';
import { cashReportTestFixture } from './cashReportTestFixture';

vi.mock('file-saver', () => ({ saveAs: vi.fn() }));
import { saveAs } from 'file-saver';

describe('cash report XLSX', () => {
  it('exports every server-ordered row and column as a real workbook with literal text and numeric money', () => {
    const report = cashReportTestFixture();
    const workbook = buildCashReportWorkbook(report);
    const bytes = XLSX.write(workbook, { type: 'array', bookType: 'xlsx' });
    const roundtrip = XLSX.read(bytes, { type: 'array' });
    const sheet = roundtrip.Sheets[roundtrip.SheetNames[0]];
    const cells = XLSX.utils.sheet_to_json<unknown[]>(sheet, { header: 1 });
    const header = cells.findIndex((row) => row[0] === 'Số chứng từ');
    expect(cells[header]).toEqual(['Số chứng từ', 'Ngày hạch toán', 'Ngày chứng từ', 'Loại', 'Đối tượng', 'Diễn giải', 'TK tiền', 'TK đối ứng', 'Thu', 'Chi', 'Tồn']);
    expect(cells.slice(header + 1)).toHaveLength(101);
    expect(cells[header + 1]).toEqual(['PT-0', '02/08/2026', '01/08/2026', 'Chi', '=1+1', 'Dữ liệu kiểm thử riêng biệt', '1111', '131', 0, 0, -300]);
    expect(cells.at(-1)?.[0]).toBe('PT-LAST');
    expect(sheet[`E${header + 2}`]).toMatchObject({ t: 's', v: '=1+1' });
    expect(sheet[`E${header + 2}`].f).toBeUndefined();
    expect(sheet[`K${header + 2}`]).toMatchObject({ t: 'n', v: -300 });
    expect(cells).toContainEqual(['Số dư đầu kỳ', -200]);
    expect(cells).toContainEqual(['Tổng thu', 0]);
    expect(cells).toContainEqual(['Số dư cuối kỳ', -300]);
    expect(cells).toContainEqual(['Tài khoản tiền', '1111']);
    expect(cells).toContainEqual(['Tìm kiếm', '=literal search']);
    expect(cells).toContainEqual(['Kỳ báo cáo', '01/08/2026 – 31/08/2026']);
    expect(cells).toContainEqual(['CA-03', 'Sổ kế toán chi tiết quỹ tiền mặt']);
  });
  it('downloads xlsx with the active code and dates, retaining balances for an empty report', () => {
    const report = cashReportTestFixture(0);
    expect(cashReportFilename(report)).toBe('CA-03_2026-08-01_2026-08-31.xlsx');
    const workbook = buildCashReportWorkbook(report);
    expect(XLSX.utils.sheet_to_json(workbook.Sheets[workbook.SheetNames[0]], { header: 1 })).toContainEqual(['Số dư cuối kỳ', -300]);
    exportCashReport(report);
    expect(saveAs).toHaveBeenCalledWith(expect.any(Blob), 'CA-03_2026-08-01_2026-08-31.xlsx');
  });
});
