import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';

export const SALES_INVOICES_QUERY_KEY = ['sales-invoices'] as const;

export function parseSalesInvoiceCollection(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

export function useSalesInvoicesQuery() {
    const queryClient = useQueryClient();

    useEffect(() => {
        const refresh = () => { void queryClient.invalidateQueries({ queryKey: SALES_INVOICES_QUERY_KEY }); };
        window.addEventListener('sales-invoices-invalidated', refresh);

        return () => {
            window.removeEventListener('sales-invoices-invalidated', refresh);
        };
    }, [queryClient]);

    const query = useQuery({
        queryKey: SALES_INVOICES_QUERY_KEY,
        queryFn: async () => {
            const { data } = await api.get('/sales/invoices');
            return parseSalesInvoiceCollection(data, 'sales invoices');
        },
    });

    return {
        ...query,
        invoices: query.data ?? [],
    };
}
