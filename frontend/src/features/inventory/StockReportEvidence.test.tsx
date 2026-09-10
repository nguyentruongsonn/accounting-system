import { describe, expect, it } from 'vitest';
// @ts-expect-error Node filesystem access is intentional for static CSS evidence.
import { readFileSync } from 'node:fs';
import source from './StockReport.tsx?raw';

const compactStyles = readFileSync('src/styles/ui-compact.css', 'utf8');

describe('stock report response boundary', () => {
    it('rejects malformed 2xx report payloads instead of rendering an empty report', () => {
        expect(source).toContain("throw new Error('Invalid stock-report response')");
        expect(source).toContain('parseStockReportRows(rows)');
        expect(source).toContain('Invalid stock-report row');
        expect(source).toContain('<ManagementReportDataError');
        expect(source).not.toContain('return Array.isArray(data) ? data : (data?.data ?? []);');
    });

    it('locks report execution when warehouse or item catalogue envelopes are malformed', () => {
        expect(source).toContain("parseOptionsResponse((await api.get('/master/warehouses')).data, 'warehouse catalogue')");
        expect(source).toContain("parseOptionsResponse((await api.get('/inventory/items')).data, 'inventory item catalogue')");
        expect(source).toContain('const catalogueUnavailable = isWarehousesError || isItemsError;');
        expect(source).toContain('disabled={catalogueUnavailable}');
    });

    it('makes the posted-only scope explicit before showing report rows', () => {
        expect(source).toContain('Báo cáo chỉ tính chứng từ đã ghi sổ');
        expect(source).toContain('Phiếu nhập/xuất đang ở trạng thái Bản nháp chưa ảnh hưởng');
        expect(source).toContain('Dữ liệu theo ngày và kho đã chọn; chỉ tính chứng từ đã ghi sổ và số dư đầu kỳ đã xác nhận.');
        expect(source).not.toContain('không có kỳ mặc định, phương pháp định giá hay xác nhận tuân thủ');
        expect(source).not.toContain('Phạm vi lọc kho không đầy đủ cho mọi nguồn');
    });

    it('keeps export and print actions bound to the currently loaded report rows', () => {
        expect(source).toContain("import ExportExcelButton from '../../components/ExportExcelButton';");
        expect(source).toContain('filename="Bao_Cao_Nhap_Xuat_Ton"');
        expect(source).toContain('<ExportExcelButton');
        expect(source).toContain('window.print()');
        expect(source).toContain('exportRows');
        expect(source).toContain('<Table.Summary');
    });

    it('keeps report actions visible and wrapping inside a narrow responsive toolbar', () => {
        expect(source).toContain('stock-report-actions');
        expect(compactStyles).toMatch(/\.stock-report-actions\s*\{[^}]*width:\s*100%/s);
        expect(compactStyles).toMatch(/@media \(max-width:\s*900px\)[\s\S]*?\.stock-report-actions\s*\{[^}]*justify-content:\s*flex-start/s);
    });
});
