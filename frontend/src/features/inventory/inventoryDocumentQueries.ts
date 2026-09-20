import { useEffect } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { parseInventoryDocumentList } from './inventoryDocumentIntegrity';

export const INVENTORY_RECEIPTS_QUERY_KEY = ['inventory-receipts'] as const;
export const INVENTORY_ISSUES_QUERY_KEY = ['inventory-issues'] as const;

export function parseInventoryReceiptList(value: unknown): any[] {
    return parseInventoryDocumentList(value, 'receipt') as any[];
}

export function parseInventoryIssueList(value: unknown): any[] {
    return parseInventoryDocumentList(value, 'issue') as any[];
}

export function useInventoryReceiptsQuery() {
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: INVENTORY_RECEIPTS_QUERY_KEY,
        queryFn: async () => {
            const { data } = await api.get('/inventory/receipts');
            return parseInventoryReceiptList(data);
        },
    });

    useEffect(() => {
        const refresh = () => { void queryClient.invalidateQueries({ queryKey: INVENTORY_RECEIPTS_QUERY_KEY }); };
        window.addEventListener('refresh-inventory-receipt', refresh);
        return () => {
            window.removeEventListener('refresh-inventory-receipt', refresh);
        };
    }, [queryClient]);

    return query;
}

export function useInventoryIssuesQuery() {
    const queryClient = useQueryClient();
    const query = useQuery({
        queryKey: INVENTORY_ISSUES_QUERY_KEY,
        queryFn: async () => {
            const { data } = await api.get('/inventory/issues');
            return parseInventoryIssueList(data);
        },
    });

    useEffect(() => {
        const refresh = () => { void queryClient.invalidateQueries({ queryKey: INVENTORY_ISSUES_QUERY_KEY }); };
        window.addEventListener('refresh-inventory-issue', refresh);
        return () => {
            window.removeEventListener('refresh-inventory-issue', refresh);
        };
    }, [queryClient]);

    return query;
}
