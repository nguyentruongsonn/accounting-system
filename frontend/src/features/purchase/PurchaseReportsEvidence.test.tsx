import { describe, expect, it } from 'vitest';
import source from './PurchaseReports.tsx?raw';

describe('purchase management report evidence boundary', () => {
  it('renders only server-backed posted activity and never includes sample report data', () => {
    expect(source).toContain("api.get('/purchase/reports'");
    expect(source).toContain('parsePurchaseReportResponse');
    expect(source).toContain('accounting_date');
    expect(source).toContain('signed_total_amount');
    expect(source).toContain('parseSupplierOptions');
    expect(source).toContain('Thử lại danh mục nhà cung cấp');
    expect(source).not.toContain('reportList: ReportTemplate[]');
    expect(source).not.toContain('CÔNG TY CỔ PHẦN CÔNG NGHỆ ABC');
    expect(source).not.toContain('PN00001');
    expect(source).not.toContain('Tháng 08/2026');
    expect(source).not.toContain('Thông tư 200/2014/TT-BTC');
  });
});
