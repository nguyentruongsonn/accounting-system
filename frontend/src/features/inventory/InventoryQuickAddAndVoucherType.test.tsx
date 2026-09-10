import { describe, expect, it } from 'vitest';
import receiptSource from './InventoryReceipts.tsx?raw';
import issueSource from './InventoryIssues.tsx?raw';

describe('Inventory voucher type select and quick add item modal integration', () => {
    it('verifies voucher type select binding and hidden form item in InventoryReceipts', () => {
        expect(receiptSource).toContain('value={voucherType ?? receiptVoucherTypes[0].value}');
        expect(receiptSource).toContain("onChange={(value) => form.setFieldValue('voucher_type', value)}");
        expect(receiptSource).toContain('<Form.Item name="voucher_type" hidden initialValue={receiptVoucherTypes[0].value}>');
        expect(receiptSource).toContain('voucher_type: values.voucher_type ?? voucherType ?? receiptVoucherTypes[0].value');
    });

    it('verifies QuickAddItemModal integration in InventoryReceipts', () => {
        expect(receiptSource).toContain('QuickAddItemModal');
        expect(receiptSource).toContain('isItemModalVisible');
        expect(receiptSource).toContain('<QuickAddItemModal');
        expect(receiptSource).toContain('Thêm nhanh VTHH');
        expect(receiptSource).toContain('title="Thêm nhanh vật tư hàng hóa"');
    });

    it('verifies voucher type select binding and hidden form item in InventoryIssues', () => {
        expect(issueSource).toContain('value={voucherType ?? issueVoucherTypes[0].value}');
        expect(issueSource).toContain("onChange={(value) => form.setFieldValue('voucher_type', value)}");
        expect(issueSource).toContain('<Form.Item name="voucher_type" hidden initialValue={issueVoucherTypes[0].value}>');
    });

    it('verifies QuickAddItemModal integration in InventoryIssues', () => {
        expect(issueSource).toContain('QuickAddItemModal');
        expect(issueSource).toContain('isItemModalVisible');
        expect(issueSource).toContain('<QuickAddItemModal');
        expect(issueSource).toContain('Thêm nhanh VTHH');
        expect(issueSource).toContain('title="Thêm nhanh vật tư hàng hóa"');
    });
});
