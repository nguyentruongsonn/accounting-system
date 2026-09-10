import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AccountingAuditTrailExplorer from './AccountingAuditTrailExplorer';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn() } }));
const mockedApi = vi.mocked(api);
function renderScreen() { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><AccountingAuditTrailExplorer /></QueryClientProvider>); }
afterEach(() => { vi.clearAllMocks(); useAuthStore.setState({ user: null, token: null, isAuthenticated: false }); });

describe('AccountingAuditTrailExplorer', () => {
  it('does not load audit data without the server view permission', () => {
    useAuthStore.setState({ user: { id: 9, name: 'No access', email: 'none@example.test', permissions: [] } }); renderScreen();
    expect(screen.getByText('Bạn không có quyền xem audit trail')).toBeInTheDocument(); expect(mockedApi.get).not.toHaveBeenCalled();
  });
  it('shows only read-only safe audit projection and opens a supported entity trace', async () => {
    useAuthStore.setState({ user: { id: 9, name: 'Auditor', email: 'audit@example.test', permissions: ['accounting.audit-trail.view'] } });
    mockedApi.get.mockResolvedValueOnce({ data: { data: [{ id: 41, occurred_at: '2026-08-20T01:00:00Z', action: 'purchase_invoice.posted', entity_type: 'App\\Models\\PurchaseInvoice', entity_id: '81', actor_id: 7, correlation_id: 'c-1', metadata: { safe: true } }], meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 } } });
    renderScreen();
    expect(await screen.findByText('purchase_invoice.posted')).toBeInTheDocument(); expect(screen.getByText(/Payload gốc/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Xem trace 41' })).toBeInTheDocument();
  }, 15_000);
  it('fails closed when the audit endpoint returns a malformed 2xx body', async () => {
    useAuthStore.setState({ user: { id: 9, name: 'Auditor', email: 'audit@example.test', permissions: ['accounting.audit-trail.view'] } });
    mockedApi.get.mockResolvedValueOnce({ data: { data: null } });
    renderScreen();
    expect(await screen.findByText('Không thể tải audit trail')).toBeInTheDocument();
    expect(screen.getByText(/Không có dữ liệu thay thế/)).toBeInTheDocument();
  });
  it('offers a retry when the audit endpoint fails', async () => {
    useAuthStore.setState({ user: { id: 9, name: 'Auditor', email: 'audit@example.test', permissions: ['accounting.audit-trail.view'] } });
    mockedApi.get
      .mockRejectedValueOnce(new Error('temporary outage'))
      .mockResolvedValueOnce({ data: { data: [], meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 } } });
    renderScreen();
    expect(await screen.findByRole('button', { name: 'Thử lại audit trail' })).toBeInTheDocument();
    await screen.findByRole('button', { name: 'Thử lại audit trail' }).then((button) => button.click());
    expect(await screen.findByTestId('ui-table-surface')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByText('Không thể tải audit trail')).not.toBeInTheDocument());
    expect(mockedApi.get).toHaveBeenCalledTimes(2);
  }, 15_000);
});
