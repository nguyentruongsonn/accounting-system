import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ChartOfAccounts from './ChartOfAccounts';
import api from '../../api/axios';

vi.mock('../../api/axios', () => ({
    default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const mockedApi = vi.mocked(api);
const accountRows = [
    { id: 1, code: '111', name: 'Tiền mặt', description: null, type: 'asset', nature: 'debit', level: 1, parent_code: null, is_parent: true, is_active: true },
    { id: 2, code: '1111', name: 'Tiền Việt Nam', description: null, type: 'asset', nature: 'debit', level: 2, parent_code: '111', is_parent: false, is_active: true },
];

const renderPage = () => render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ChartOfAccounts />
    </QueryClientProvider>,
);

describe('chart of accounts tree interaction', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockedApi.get.mockResolvedValue({ data: accountRows } as never);
    });

    it('uses one explicit toggle and keeps the account name non-interactive', async () => {
        const { container } = renderPage();

        await screen.findByText('111');
        expect(container.querySelectorAll('.coa-workbench__tree-toggle')).toHaveLength(1);
        expect(container.querySelectorAll('.ant-table-row-expand-icon')).toHaveLength(0);
        expect(screen.queryByText('1111')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Mở rộng 111' }));
        expect(await screen.findByText('1111')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Tiền mặt'));
        await waitFor(() => expect(screen.getByText('1111')).toBeInTheDocument());
    });
});
