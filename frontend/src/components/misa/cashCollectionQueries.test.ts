import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    createCustomerOutstandingInvoicesQuery,
    createMultiCustomerOutstandingInvoicesQuery,
} from './cashCollectionQueries';

const { get } = vi.hoisted(() => ({
    get: vi.fn().mockResolvedValue({ data: [] }),
}));

vi.mock('../../api/axios', () => ({ default: { get } }));

describe('cash collection query builders', () => {
    afterEach(() => {
        get.mockClear();
    });

    it('passes the current customer/date and abort signal to the customer query', async () => {
        const controller = new AbortController();
        const query = createCustomerOutstandingInvoicesQuery(12, '2026-09-20');

        await query.queryFn({ signal: controller.signal } as never);

        expect(query.queryKey).toEqual(['sales-invoice-outstanding', 12, '2026-09-20']);
        expect(get).toHaveBeenCalledWith('/sales/invoices/outstanding', {
            params: { customer_id: 12, as_of_date: '2026-09-20' },
            signal: controller.signal,
        });
    });

    it('passes the current date and abort signal to the multi-customer query', async () => {
        const controller = new AbortController();
        const query = createMultiCustomerOutstandingInvoicesQuery('2026-09-21');

        await query.queryFn({ signal: controller.signal } as never);

        expect(query.queryKey).toEqual(['sales-invoice-outstanding-all', '2026-09-21']);
        expect(get).toHaveBeenCalledWith('/sales/invoices/outstanding', {
            params: { as_of_date: '2026-09-21' },
            signal: controller.signal,
        });
    });
});
