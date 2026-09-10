import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import { CashReceipts } from './CashReceipts';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn() } }));

const mockedApi = vi.mocked(api);

function renderReceipts() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter>
                <CashReceipts />
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

describe('cash receipt accounting summary', () => {
    afterEach(() => {
        mockedApi.get.mockReset();
    });

    it('excludes a draft receipt from the cash totals while retaining it in the voucher list', async () => {
        mockedApi.get.mockImplementation(async (url) => {
            if (url === '/cash/receipts') {
                return {
                    data: [
                        { id: 1, voucher_number: 'PT-NHAP', voucher_date: '2026-09-05', posting_date: '2026-09-05', reason: 'Nháp', total_amount: 100000, contact_name: 'Khách hàng A', is_posted: false },
                        { id: 2, voucher_number: 'PT-GHISO', voucher_date: '2026-09-05', posting_date: '2026-09-05', reason: 'Đã ghi sổ', total_amount: 250000, contact_name: 'Khách hàng B', is_posted: true },
                    ],
                };
            }
            if (url === '/cash/payments') return { data: [] };
            if (typeof url === 'string' && url.startsWith('/master/voucher-type-settings')) return { data: [] };
            if (url === '/master/accounts' || url === '/master/customers' || url === '/master/employees') return { data: [] };
            throw new Error(`Unexpected GET ${url}`);
        });

        renderReceipts();

        await waitFor(() => expect(screen.getByText('PT-NHAP')).toBeInTheDocument());
        expect(screen.getByText('PT-GHISO')).toBeInTheDocument();

        const receiptTotalCard = screen.getByText('Tổng thu đầu năm đến hiện tại').parentElement;
        expect(receiptTotalCard).toHaveTextContent('250.000 ₫');
        expect(receiptTotalCard).not.toHaveTextContent('350.000 ₫');
    });
});
