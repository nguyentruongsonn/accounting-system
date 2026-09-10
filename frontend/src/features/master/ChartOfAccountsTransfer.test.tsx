import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, afterEach, expect, it, vi } from 'vitest';
import ChartOfAccounts from './ChartOfAccounts';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }));
const accounts = ['1111', '1112'].map((code, index) => ({ id: index + 1, code, name: `TK ${code}`, type: 'asset', nature: 'debit', level: 1, parent_code: null, is_parent: false, is_active: true }));
beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockResolvedValue({ data: accounts });
    useAuthStore.setState({ user: { id: 1, name: 'Admin', email: 'admin@example.test', permissions: ['master.accounts.transfer', 'master.accounts.delete'] } });
});
afterEach(() => useAuthStore.setState({ user: null }));
const renderPage = () => render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><ChartOfAccounts /></QueryClientProvider>);

it('requires a preview before executing transfer and shows the server reference count', async () => {
    const previewToken = 'a'.repeat(64);
    vi.mocked(api.post).mockImplementation(async (_url, payload) => {
        if ((payload as { preview?: boolean }).preview) return { data: { preview: true, affected_references: 2, preview_token: previewToken, references: { 'journal_entry_lines.account_code': 2 } } };
        return { data: { account: { ...accounts[0], is_active: false }, affected_references: 2 } };
    });
    renderPage();
    await screen.findByText('1111');
    fireEvent.click(screen.getByRole('button', { name: /Chuyển tài khoản hạch toán/ }));
    const modal = within(await screen.findByRole('dialog'));
    fireEvent.mouseDown(modal.getByRole('combobox'));
    fireEvent.click(await screen.findByText('1112 — TK 1112'));
    fireEvent.click(modal.getByRole('button', { name: 'Xem trước' }));
    expect(await modal.findByText(/2 tham chiếu/)).toBeInTheDocument();
    expect(api.post).toHaveBeenCalledTimes(1);
    expect(api.post).toHaveBeenLastCalledWith('/master/accounts/1/transfer', { target_code: '1112', preview: true });
    fireEvent.click(modal.getByRole('button', { name: 'Xác nhận chuyển' }));
    await waitFor(() => expect(api.post).toHaveBeenCalledTimes(2));
    expect(api.post).toHaveBeenLastCalledWith('/master/accounts/1/transfer', { target_code: '1112', preview_token: previewToken });
}, 15_000);

it('does not allow accountant to start privileged account transfer', async () => {
    useAuthStore.setState({ user: { id: 2, name: 'Kế toán', email: 'accountant@example.test', permissions: ['master.accounts.view', 'master.accounts.update'] } });
    renderPage();
    await screen.findByText('1111');
    expect(screen.getByRole('button', { name: /Chuyển tài khoản hạch toán/ })).toBeDisabled();
});
