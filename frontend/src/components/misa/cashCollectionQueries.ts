import type { QueryFunctionContext } from '@tanstack/react-query';
import api from '../../api/axios';

type CashCollectionQueryContext = Pick<QueryFunctionContext, 'signal'>;

function asArray<T>(payload: unknown, label: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: unknown[] }).data as T[];
    }
    throw new Error(`Phản hồi ${label} không hợp lệ`);
}

export function createCustomerOutstandingInvoicesQuery(customerId: number | null, date: string) {
    return {
        queryKey: ['sales-invoice-outstanding', customerId, date] as const,
        queryFn: async ({ signal }: CashCollectionQueryContext) => {
            const { data } = await api.get('/sales/invoices/outstanding', {
                params: { ...(customerId ? { customer_id: customerId } : {}), as_of_date: date },
                signal,
            });
            return asArray<unknown>(data, 'hóa đơn còn phải thu');
        },
    };
}

export function createMultiCustomerOutstandingInvoicesQuery(date: string) {
    return {
        queryKey: ['sales-invoice-outstanding-all', date] as const,
        queryFn: async ({ signal }: CashCollectionQueryContext) => {
            const { data } = await api.get('/sales/invoices/outstanding', {
                params: { as_of_date: date },
                signal,
            });
            return asArray<unknown>(data, 'hóa đơn chưa thu');
        },
    };
}
