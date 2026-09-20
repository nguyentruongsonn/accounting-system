import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useCashReportQuery } from './useCashReportQuery';

const { get } = vi.hoisted(() => ({
    get: vi.fn(() => new Promise(() => {})),
}));

vi.mock('../../api/axios', () => ({ default: { get } }));

describe('useCashReportQuery performance defaults', () => {
    let queryClient: QueryClient;

    afterEach(() => {
        queryClient.clear();
    });

    it('does not force a network refetch while the selected report is fresh', () => {
        queryClient = new QueryClient({
            defaultOptions: {
                queries: { retry: false },
            },
        });

        const wrapper = ({ children }: { children: ReactNode }) => (
            <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
        );

        const { unmount } = renderHook(() => useCashReportQuery({
            code: 'CA-01',
            filters: {
                date_from: '2026-01-01',
                date_to: '2026-01-31',
                status: 'posted',
                search: '',
            },
        }), { wrapper });

        const query = queryClient.getQueryCache().find({ queryKey: ['cash-reports'], exact: false });
        const performanceOptions = query?.options as { staleTime?: number; refetchOnMount?: boolean } | undefined;

        expect(performanceOptions?.staleTime).toBe(30_000);
        expect(performanceOptions?.refetchOnMount).toBe(true);

        unmount();
    });
});
