import { describe, expect, it } from 'vitest';
import salesInvoices from './SalesInvoices.tsx?raw';
import salesReturns from './SalesReturns.tsx?raw';
import salesDiscounts from './SalesDiscounts.tsx?raw';
import purchaseReturns from '../purchase/PurchaseReturns.tsx?raw';
import purchaseDiscounts from '../purchase/PurchaseDiscounts.tsx?raw';
import purchaseContracts from '../purchase/PurchaseContracts.tsx?raw';
import salesOrders from './SalesOrders.tsx?raw';
import salesQuotes from './SalesQuotes.tsx?raw';
import salesReports from './SalesReports.tsx?raw';

describe('sales/purchase display evidence boundary', () => {
    it('does not fabricate voucher print identity or detail lines', () => {
        expect(salesInvoices).toContain('const lines = Array.isArray(record.lines) ? record.lines : []');
        expect(salesInvoices).toContain('Không thể in chứng từ vì máy chủ chưa cung cấp dòng chi tiết đã lưu.');
        expect(salesInvoices).toContain('voucher_number: record.invoice_number || record.invoice_code');
        expect(salesInvoices).toContain('warehouse_name: record.warehouse_name || record.warehouse?.name');
        expect(salesInvoices).not.toContain("item_code: 'VT00001'");
        expect(salesInvoices).not.toContain("voucher_number: record.invoice_number || 'BH00001'");
        expect(salesInvoices).not.toContain("debit_account: l.debit_account || '131'");
        expect(salesInvoices).not.toContain("credit_account: l.credit_account || '5111'");
        expect(salesInvoices).not.toContain("cogs_unit_price: Number(l.cogs_unit_price) || (l.unit_price ? Number(l.unit_price) * 0.7 : 0)");
        expect(salesInvoices).not.toContain('1000000');

        for (const source of [salesReturns, salesDiscounts, purchaseReturns, purchaseDiscounts]) {
            expect(source).not.toMatch(/voucher_number:\s*record\.voucher_number\s*\|\|\s*['`][^'`]+00001['`]/);
            expect(source).not.toMatch(/description:\s*record\.description\s*\|\|\s*`/);
        }
    });

    it('keeps empty purchase-contract responses empty instead of rendering sample records', () => {
        expect(purchaseContracts).toContain('dataSource={contractList}');
        expect(purchaseContracts).toContain('dataSource={activeContract?.lines || []}');
        expect(purchaseContracts).toContain('contract_name: undefined');
        expect(purchaseContracts).toContain('lines: []');
        expect(purchaseContracts).not.toContain("{t || 'VT00001'}");
        expect(purchaseContracts).not.toContain("{editingContract?.id ? `Sửa Hợp đồng mua ${form.getFieldValue('contract_number')}` : `Hợp đồng mua ${form.getFieldValue('contract_number') || 'HĐM00001'}`}");
    });

    it('does not fabricate sales order/quote numbers or first detail lines', () => {
        for (const source of [salesOrders, salesQuotes]) {
            expect(source).not.toMatch(/(?:order|quote)_number[^\n]*00001/);
            expect(source).toContain('lines: [{}]');
            expect(source).toContain('total_amount: record.total_amount == null ? undefined : Number(record.total_amount)');
            expect(source).not.toMatch(/description: record\.description \|\| `(?:Báo giá cho|Đơn đặt hàng từ)/);
        }
    });

    it('connects the sales report tab to server evidence without statutory claims', () => {
        expect(salesReports).toContain("api.get('/sales/reports'");
        expect(salesReports).toContain('parseSalesReportResponse');
        expect(salesReports).toContain('posted_only');
        expect(salesReports).toContain('parseCustomerOptions');
        expect(salesReports).toContain('Thử lại danh mục khách hàng');
        expect(salesReports).toContain('Báo cáo bán hàng');
        expect(salesReports).toContain('Số liệu lấy trực tiếp từ chứng từ bán hàng đã ghi sổ theo ngày hạch toán.');
        expect(salesReports).not.toContain('Không phải báo cáo thuế');
        expect(salesReports).not.toContain('Báo cáo bán hàng đang được cập nhật');
        expect(salesReports).not.toContain('Công ty Cổ phần');
    });
});
