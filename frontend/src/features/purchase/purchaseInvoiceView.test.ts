import { describe, expect, it } from 'vitest';

import { filterPurchaseInvoices } from './purchaseInvoiceView';

describe('filterPurchaseInvoices', () => {
    it('matches voucher number, supplier and description without mutating the source list', () => {
        const invoices = [
            { invoice_number: 'M001', supplier_name: 'Nhà cung cấp A', description: 'Hàng hóa' },
            { invoice_number: 'M002', supplier_name: 'Nhà cung cấp B', description: 'Dịch vụ' },
        ];

        expect(filterPurchaseInvoices(invoices, '  dịch vụ ')).toEqual([invoices[1]]);
        expect(invoices).toHaveLength(2);
    });

    it('returns all invoices for an empty search', () => {
        const invoices = [{ invoice_number: 'M001' }];

        expect(filterPurchaseInvoices(invoices, '')).toEqual(invoices);
    });
});
