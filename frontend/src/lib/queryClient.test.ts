import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    getAccountingDataScope,
    notifyDataChanged,
    queryClient,
} from './queryClient';

describe('queryClient defaults', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('keeps queries fresh briefly and refetches only when they are stale', () => {
        const queryOptions = queryClient.getDefaultOptions().queries;

        expect(queryOptions?.staleTime).toBe(30_000);
        expect(queryOptions?.refetchOnMount).toBe(true);
    });

    it('coalesces repeated data-change notifications in the same microtask', async () => {
        const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();

        notifyDataChanged();
        notifyDataChanged();

        await Promise.resolve();

        expect(invalidateQueries).toHaveBeenCalledTimes(1);
    });

    it('maps mutation endpoints to a bounded accounting scope', () => {
        expect(getAccountingDataScope('/cash/receipts/42/post')).toBe('cash');
        expect(getAccountingDataScope('/bank/payments/42')).toBe('bank');
        expect(getAccountingDataScope('/purchase/invoices/42')).toBe('purchase');
        expect(getAccountingDataScope('/sales/invoices/42')).toBe('sales');
        expect(getAccountingDataScope('/inventory/issues/42')).toBe('inventory');
        expect(getAccountingDataScope('/fixed-assets/42/post')).toBe('fixed-assets');
        expect(getAccountingDataScope('/gl/journal-entries/42/post')).toBe('gl');
        expect(getAccountingDataScope('/unknown/write')).toBe('all');
    });

    it('invalidates only the query prefixes in the selected scope', async () => {
        const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries').mockResolvedValue();

        notifyDataChanged('cash');
        await Promise.resolve();

        expect(invalidateQueries).toHaveBeenCalledTimes(1);
        const filters = invalidateQueries.mock.calls[0]?.[0] as {
            predicate?: (query: { queryKey: readonly unknown[] }) => boolean;
        };
        expect(filters.predicate?.({ queryKey: ['cash-receipts'] })).toBe(true);
        expect(filters.predicate?.({ queryKey: ['cash-reports', 'CA-01'] })).toBe(true);
        expect(filters.predicate?.({ queryKey: ['sales-invoices'] })).toBe(false);
    });
});
