import { describe, expect, it } from 'vitest';
import detailModal from './PurchaseVoucherDetailModal.tsx?raw';
import serviceModal from './PurchaseServiceModal.tsx?raw';
import multipleInvoicesModal from './PurchaseMultipleInvoicesModal.tsx?raw';
import dimensionAssignments from '../PurchaseInvoiceDimensionAssignments.tsx?raw';

describe('purchase modal form mounting contract', () => {
    it('does not call the dimension form before its conditional Drawer form mounts', () => {
        expect(dimensionAssignments).toContain("if (!open || context?.status !== 'available') return;");
    });

    it('does not read form values during modal render before the Form portal mounts', () => {
        expect(detailModal).not.toContain("form.getFieldValue('voucher_number')");
        expect(detailModal).not.toContain("supplierId={form.getFieldValue('supplier_id')}");
        expect(serviceModal).not.toContain("form.getFieldValue('voucher_number')");
        expect(multipleInvoicesModal).not.toContain("form.getFieldValue('voucher_number')");
    });

    it('keeps newly added line text fields controlled before an item is selected', () => {
        expect(detailModal).toContain("item_name: '',");
        expect(detailModal).toContain("unit: '',");
    });
});
