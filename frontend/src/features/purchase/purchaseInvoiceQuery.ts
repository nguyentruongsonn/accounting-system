import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';

export const PURCHASE_INVOICES_QUERY_KEY = ['purchase-invoices'] as const;

export type PurchaseInvoiceRecord = Record<string, unknown>;

export function normalizePurchaseInvoiceListResponse(payload: unknown): PurchaseInvoiceRecord[] {
    const records = Array.isArray(payload)
        ? payload
        : (payload as { data?: unknown } | null)?.data;

    if (!Array.isArray(records)) {
        throw new Error('Invalid purchase-invoice list response');
    }

    return records as PurchaseInvoiceRecord[];
}

export function usePurchaseInvoicesQuery() {
    const queryClient = useQueryClient();

    useEffect(() => {
        const refreshPurchaseInvoices = () => queryClient.invalidateQueries({ queryKey: PURCHASE_INVOICES_QUERY_KEY });
        window.addEventListener('purchase-invoices-invalidated', refreshPurchaseInvoices);

        return () => {
            window.removeEventListener('purchase-invoices-invalidated', refreshPurchaseInvoices);
        };
    }, [queryClient]);

    const query = useQuery({
        queryKey: PURCHASE_INVOICES_QUERY_KEY,
        queryFn: async () => {
            const { data } = await api.get('/purchase/invoices');
            return normalizePurchaseInvoiceListResponse(data);
        },
    });

    return {
        ...query,
        invoices: query.data ?? [],
    };
}
