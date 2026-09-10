import { describe, expect, it } from 'vitest';
import xlsxExportSource from './excelExport.ts?raw';
import cashExportSource from '../features/cash/exportToExcel.ts?raw';
import bankExportSource from '../features/bank/exportToExcel.ts?raw';
import genericExportSource from '../components/misa/exportUtility.ts?raw';

describe('academic export company-evidence boundary', () => {
  it('never exports a synthetic ABC identity and requires explicit company evidence', () => {
    const sources = [xlsxExportSource, cashExportSource, bankExportSource, genericExportSource];
    const combined = sources.join('\n');

    expect(combined).not.toContain('CÔNG TY CỔ PHẦN KẾ TOÁN ABC');
    expect(combined).not.toContain('CÔNG TY CỔ PHẦN CÔNG NGHỆ ABC');
    expect(combined).not.toContain('ABC Tower');
    expect(combined).not.toContain('0109988776');

    for (const source of sources) {
      expect(source).toContain('CHƯA CÓ THÔNG TIN DOANH NGHIỆP TỪ MÁY CHỦ');
      expect(source).toContain('company?.name?.trim()');
    }
    expect(xlsxExportSource).toContain("company?.address?.trim()");
    expect(xlsxExportSource).toContain("company?.tax_code?.trim()");
  });
});
