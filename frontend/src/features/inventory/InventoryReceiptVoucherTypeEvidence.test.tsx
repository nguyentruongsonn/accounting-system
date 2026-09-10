import { describe, expect, it } from 'vitest';
import source from './InventoryReceipts.tsx?raw';

describe('inventory receipt voucher type binding', () => {
    it('binds the header voucher type select to the form state', () => {
        expect(source).toContain('value={voucherType ?? receiptVoucherTypes[0].value}');
        expect(source).toContain('onChange={(value) => form.setFieldValue(\'voucher_type\', value)}');
    });
});
