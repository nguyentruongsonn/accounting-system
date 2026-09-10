import { describe, expect, it } from 'vitest';
import source from './InventoryIssues.tsx?raw';

describe('inventory issue voucher type binding', () => {
    it('keeps the header voucher type select synchronized with the form state', () => {
        expect(source).toContain('value={voucherType ?? issueVoucherTypes[0].value}');
        expect(source).toContain("onChange={(value) => form.setFieldValue('voucher_type', value)}");
        expect(source).toContain('voucher_type: values.voucher_type ?? effectiveVoucherType');
    });
});
