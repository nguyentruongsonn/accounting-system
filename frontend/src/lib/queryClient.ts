import { QueryClient, MutationCache } from '@tanstack/react-query';

/**
 * Phát tín hiệu và invalidate toàn bộ query cache khi có bất kỳ dữ liệu kế toán nào thay đổi
 * (thêm mới, sửa, xóa, ghi sổ, bỏ ghi sổ).
 */
export function notifyDataChanged(): void {
    try {
        void queryClient.invalidateQueries();
        if (typeof window !== 'undefined') {
            window.dispatchEvent(new CustomEvent('accounting-data-changed'));
            window.dispatchEvent(new CustomEvent('cash-receipts-invalidated'));
            window.dispatchEvent(new CustomEvent('cash-payments-invalidated'));
            window.dispatchEvent(new CustomEvent('purchase-invoices-invalidated'));
            window.dispatchEvent(new CustomEvent('sales-invoices-invalidated'));
            window.dispatchEvent(new CustomEvent('refresh-general-journals'));
            window.dispatchEvent(new CustomEvent('refresh-journal-entries'));
            window.dispatchEvent(new CustomEvent('refresh-inventory-issue'));
            window.dispatchEvent(new CustomEvent('refresh-inventory-receipt'));
            window.dispatchEvent(new CustomEvent('refresh-inventory-stock-count'));
            window.dispatchEvent(new CustomEvent('refresh-inventory-transfer'));
            window.dispatchEvent(new CustomEvent('refresh-bank-transactions'));
            window.dispatchEvent(new CustomEvent('refresh-fixed-assets'));
            window.dispatchEvent(new CustomEvent('refresh-tools'));
        }
    } catch {
        // Safe failover
    }
}

export const queryClient = new QueryClient({
    mutationCache: new MutationCache({
        onSuccess: () => {
            notifyDataChanged();
        },
    }),
    defaultOptions: {
        queries: {
            refetchOnWindowFocus: true,
            refetchOnMount: 'always',
            refetchOnReconnect: true,
            retry: 1,
            staleTime: 0, // Dữ liệu luôn coi là stale để khi chuyển tab/trang luôn tải dữ liệu mới nhất
            gcTime: 10 * 60 * 1000,
        },
    },
});
