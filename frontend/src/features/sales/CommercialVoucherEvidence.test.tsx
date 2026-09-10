import { describe, expect, it } from 'vitest';
import salesReturnModal from './modals/SalesReturnModal.tsx?raw';
import salesDiscountModal from './modals/SalesDiscountModal.tsx?raw';
import salesReturnAccounting from './components/SalesReturnAccountingTab.tsx?raw';
import salesReturnTax from './components/SalesReturnTaxTab.tsx?raw';
import salesReturnCogs from './components/SalesReturnCogsTab.tsx?raw';
import salesDiscountAccounting from './components/SalesDiscountAccountingTab.tsx?raw';
import salesDiscountTax from './components/SalesDiscountTaxTab.tsx?raw';
import purchaseReturnModal from '../purchase/modals/PurchaseReturnModal.tsx?raw';
import purchaseDiscountModal from '../purchase/modals/PurchaseDiscountModal.tsx?raw';
import purchaseMultipleModal from '../purchase/modals/PurchaseMultipleInvoicesModal.tsx?raw';
import purchaseServiceModal from '../purchase/modals/PurchaseServiceModal.tsx?raw';
import purchaseVoucherDetailModal from '../purchase/modals/PurchaseVoucherDetailModal.tsx?raw';
import payVendorByInvoiceModal from '../purchase/modals/PayVendorByInvoiceModal.tsx?raw';
import debtOffsetModal from '../purchase/utilities/DebtOffsetModal.tsx?raw';
import salesReturnMasterCard from './components/SalesReturnMasterCard.tsx?raw';
import salesInvoices from './SalesInvoices.tsx?raw';
import autoVoucherNumber from '../../hooks/useAutoVoucherNumber.ts?raw';

describe('commercial voucher evidence boundary', () => {
    it('does not synthesize source lines or accounting mappings in return/discount forms', () => {
        const sources = [
            salesReturnModal,
            salesDiscountModal,
            salesReturnAccounting,
            salesReturnTax,
            salesReturnCogs,
            salesDiscountAccounting,
            salesDiscountTax,
            purchaseReturnModal,
            purchaseDiscountModal,
        ];

        for (const source of sources) {
            expect(source).not.toMatch(/\|\|\s*['`](?:131|1111|1121|1331|1561|5212|5213|632)['`]/);
            expect(source).not.toMatch(/(?:item_code|item_name|warehouse_code|voucher_number):\s*['`][^'`]*00001[^'`]*['`]/);
        }

        expect(salesReturnModal).toContain('credit_account: undefined');
        expect(salesDiscountModal).toContain('credit_account: undefined');
        expect(purchaseReturnModal).toContain('debit_account: l.debit_account');
        expect(purchaseDiscountModal).toContain('tax_account: l.tax_account');
    });

    it('leaves voucher numbering to the server when next-code is unavailable', () => {
        expect(autoVoucherNumber).not.toContain('Math.random');
        expect(autoVoucherNumber).toContain("setVoucherNumber('')");
    });

    it('does not seed multi-invoice purchase drafts with sample records or mappings', () => {
        expect(purchaseMultipleModal).not.toContain('Math.random');
        expect(purchaseMultipleModal).not.toContain('VT01');
        expect(purchaseMultipleModal).not.toContain('Bàn làm việc Hòa Phát');
        expect(purchaseMultipleModal).not.toMatch(/(?:warehouse|debit_account|credit_account|tax_account):\s*['`](?:1561|331|1331)['`]/);
        expect(purchaseMultipleModal).toContain('useState<MultiInvoiceLine[]>([])');
    });

    it('does not seed service or purchase-detail drafts with browser samples', () => {
        for (const source of [purchaseServiceModal, purchaseVoucherDetailModal]) {
            expect(source).not.toContain('Math.random');
            expect(source).not.toMatch(/(?:item_code|service_code|voucher_number|invoice_number):\s*['`][^'`]*(?:VT00001|DV_VANCHUYEN|0000842|MDV\d+|NK\d+)[^'`]*['`]/);
            expect(source).not.toContain("initialValue={120000000}");
            expect(source).not.toContain("initialValue={1200000}");
        }
        expect(purchaseServiceModal).toContain('const [lines, setLines] = useState<ServiceLine[]>([])');
        expect(purchaseVoucherDetailModal).toContain('lines: []');
        expect(purchaseVoucherDetailModal).toContain('item_id: it.item_id');
    });

    it('does not expose unsupported purchase print or attachment actions as successful capabilities', () => {
        expect(purchaseServiceModal).not.toContain('In (chưa khả dụng)');
        expect(purchaseMultipleModal).not.toContain('In (chưa khả dụng)');
        expect(purchaseVoucherDetailModal).not.toContain('Đính kèm chứng từ gốc (chưa khả dụng)');
        expect(purchaseVoucherDetailModal).not.toContain('API lưu tệp chưa được cung cấp');
        expect(purchaseServiceModal).toContain('chưa lưu vì backend chưa cung cấp API ghi nhận phân bổ chi phí');
        expect(purchaseServiceModal).not.toContain("message.success('Đã phân bổ chi phí thành công!')");
        expect(purchaseServiceModal).toContain('const persistedInvoice = response?.data?.data ?? response?.data;');
        expect(purchaseServiceModal).toContain('Máy chủ không trả về chứng từ mua dịch vụ đã lưu');
        expect(purchaseMultipleModal).toContain('Máy chủ không trả về chứng từ mua nhiều hóa đơn đã lưu');
        expect(purchaseVoucherDetailModal).toContain('Máy chủ không trả về chứng từ mua hàng đã lưu');
        expect(purchaseVoucherDetailModal).toContain('Máy chủ không xác nhận đã bỏ ghi sổ chứng từ');
    });

    it('does not retain the removed AVA branding in purchase-service presentation', () => {
        expect(purchaseServiceModal).not.toContain('misa-badge-ava');
        expect(purchaseServiceModal).toContain('misa-badge-account');
    });

    it('requires persisted evidence for sales invoice lifecycle actions', () => {
        expect(salesInvoices).toContain('Máy chủ không trả về chứng từ bán hàng đã lưu');
        expect(salesInvoices).toContain('Máy chủ không xác nhận đã ghi sổ chứng từ bán hàng');
        expect(salesInvoices).toContain('Máy chủ không trả về chứng từ bán hàng nhân bản đã lưu');
        expect(salesInvoices).toContain('Máy chủ không xác nhận đã xóa chứng từ bán hàng');
    });

    it('does not present legacy account codes as an approved payment mapping', () => {
        expect(payVendorByInvoiceModal).not.toMatch(/render:\s*\(\)\s*=>\s*['"](?:131|331|1111)['"]/);
        expect(payVendorByInvoiceModal).toContain('accountOptions');
        expect(payVendorByInvoiceModal).toContain('Chọn tài khoản');
        expect(debtOffsetModal).not.toContain('Nợ 331 / Có 131');
        expect(debtOffsetModal).not.toContain('Phải thu (131)');
        expect(debtOffsetModal).not.toContain('Phải trả (331)');
        expect(salesReturnMasterCard).not.toMatch(/KH\$\{String\(c\.id\)/);
    });
});
