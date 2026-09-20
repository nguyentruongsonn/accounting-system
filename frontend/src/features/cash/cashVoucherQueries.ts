import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';

export const CASH_RECEIPTS_QUERY_KEY = ['cash-receipts'] as const;
export const CASH_PAYMENTS_QUERY_KEY = ['cash-payments'] as const;

export function parseCashReceiptCollection(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

export function parseCashPaymentCollection(value: unknown): any[] {
    return parseCashReceiptCollection(value, 'cash payments');
}

export function useCashReceiptsQuery(enabled = true) {
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: CASH_RECEIPTS_QUERY_KEY,
        queryFn: async () => {
            const { data } = await api.get('/cash/receipts');
            return parseCashReceiptCollection(data, 'cash receipts');
        },
        enabled,
    });

    useEffect(() => {
        const refresh = () => {
            void queryClient.invalidateQueries({ queryKey: CASH_RECEIPTS_QUERY_KEY });
            void queryClient.invalidateQueries({ queryKey: CASH_PAYMENTS_QUERY_KEY });
        };
        window.addEventListener('cash-receipts-invalidated', refresh);

        return () => {
            window.removeEventListener('cash-receipts-invalidated', refresh);
        };
    }, [queryClient]);

    return query;
}

export function useCashPaymentsQuery(enabled = true) {
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: CASH_PAYMENTS_QUERY_KEY,
        queryFn: async () => {
            const { data } = await api.get('/cash/payments');
            return parseCashPaymentCollection(data);
        },
        enabled,
    });
    const { refetch } = query;

    useEffect(() => {
        const refresh = () => {
            void queryClient.invalidateQueries({ queryKey: CASH_PAYMENTS_QUERY_KEY });
            void refetch();
        };
        window.addEventListener('cash-payments-invalidated', refresh);

        return () => {
            window.removeEventListener('cash-payments-invalidated', refresh);
        };
    }, [queryClient, refetch]);

    return query;
}
