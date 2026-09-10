import { describe, expect, it } from 'vitest';
import source from './SalesInvoices.tsx?raw';

describe('sales invoice action boundary', () => {
    it('does not advertise out-of-scope e-invoice issuance while wiring receipt collection to the persisted workflow', () => {
        expect(source).toContain("throw new Error(`Invalid ${resource} response`)");
        expect(source).toContain("parseSalesInvoiceCollection(data, 'sales invoices')");
        expect(source).not.toContain('Phát hành Hóa đơn điện tử thành công');
        expect(source).not.toContain('Đã tự động lập');
        expect(source).not.toContain('backend hiện chỉ cung cấp hồ sơ/evidence provider');
        expect(source).not.toContain("label: 'Phát hành HĐĐT (chưa khả dụng)'");
        expect(source).not.toContain('handlePublishEInvoice');
        expect(source).toContain("label: 'Thu tiền theo hóa đơn'");
        expect(source).toContain('setIsCollectByInvoiceOpen(true)');
        expect(source).toContain('<CollectByInvoiceModal');
        expect(source).not.toContain("key: 'receipt_bank'");
        expect(source).not.toContain('Lập Báo Có tiền gửi');
        expect(source).not.toContain("handleCreateReceipt(record, 'bank')");
    });
});
