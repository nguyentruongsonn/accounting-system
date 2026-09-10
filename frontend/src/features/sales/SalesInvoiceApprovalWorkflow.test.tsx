import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { message } from 'antd';
import { afterEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';
import SalesInvoiceApprovalWorkflow from './SalesInvoiceApprovalWorkflow';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

// Ant Design's real Drawer/Descriptions contract is intentionally exercised;
// jsdom needs the browser observer primitives it uses for responsive layout.
Object.defineProperty(window, 'matchMedia', { writable: true, value: vi.fn().mockImplementation((query: string) => ({ matches: false, media: query, onchange: null, addListener: vi.fn(), removeListener: vi.fn(), addEventListener: vi.fn(), removeEventListener: vi.fn(), dispatchEvent: vi.fn() })) });
class TestResizeObserver { observe() {} unobserve() {} disconnect() {} }
vi.stubGlobal('ResizeObserver', TestResizeObserver);

const mockedApi = vi.mocked(api);
const invoice = { id: 41, invoice_number: 'HD-000041', is_posted: false };

function approvalData(overrides: Partial<{ is_posted: boolean; requests: unknown[] }> = {}) {
  return {
    invoice_id: invoice.id,
    is_posted: false,
    snapshot_hash: 'current-sales-snapshot-hash-123456',
    limitation: 'Máy chủ là nguồn quyết định cuối cùng.',
    requests: [],
    ...overrides,
  };
}

function request(overrides: Record<string, unknown> = {}) {
  return {
    id: 71,
    status: 'pending',
    requested_by: 19,
    requested_at: '2026-08-01T01:02:03Z',
    separation_of_duties_required: true,
    evidence: { snapshot_hash: 'request-snapshot-abcdef', is_current: true, reference_note: 'Đã đối chiếu đơn hàng.' },
    steps: [{ step_order: 1, status: 'pending', required_approvals: 1, approved_count: 0, rejected_count: 0 }],
    ...overrides,
  };
}

function renderWorkflow(invoiceOverride = invoice) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><SalesInvoiceApprovalWorkflow invoice={invoiceOverride} open onClose={vi.fn()} /></QueryClientProvider>);
}

afterEach(() => {
  vi.restoreAllMocks();
  vi.clearAllMocks();
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false });
});

describe('SalesInvoiceApprovalWorkflow', () => {
  it('loads and renders both current and stale server evidence without offering decision controls', { timeout: 15_000 }, async () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: ['sales.invoices.view', 'sales.invoices.update'] } });
    mockedApi.get.mockResolvedValue({ data: { data: approvalData({ requests: [request(), request({ id: 70, evidence: { snapshot_hash: 'old-sales-snapshot-abcdef', is_current: false, reference_note: null } })] }) } });

    renderWorkflow();

    expect(await screen.findByText('Yêu cầu #71')).toBeInTheDocument();
    expect(screen.getByText('Hiện tại')).toBeInTheDocument();
    expect(screen.getByText('Đã cũ')).toBeInTheDocument();
    expect(screen.getByText('Chứng cứ đã cũ')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Phê duyệt$/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^Ghi sổ$/i })).not.toBeInTheDocument();
  });

  it('submits only a draft approval request and surfaces an API failure without false success', async () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: ['sales.invoices.view', 'sales.invoices.update'] } });
    mockedApi.get.mockResolvedValue({ data: { data: approvalData() } });
    mockedApi.post.mockRejectedValue({ response: { data: { message: 'Không thể gửi yêu cầu lúc này.' } } });
    const errorSpy = vi.spyOn(message, 'error');
    const successSpy = vi.spyOn(message, 'success');

    renderWorkflow();

    await screen.findByText('Chưa có yêu cầu phê duyệt');
    fireEvent.change(screen.getByLabelText(/Ghi chú đối chiếu chứng từ nguồn/i), { target: { value: 'Đã đối chiếu chứng từ bán hàng.' } });
    fireEvent.click(screen.getByRole('button', { name: /Gửi yêu cầu phê duyệt$/ }));

    await waitFor(() => expect(mockedApi.post).toHaveBeenCalledWith('/sales/invoices/41/approval-requests', { reference_note: 'Đã đối chiếu chứng từ bán hàng.' }));
    await waitFor(() => expect(errorSpy).toHaveBeenCalledWith('Không thể gửi yêu cầu lúc này.'));
    expect(successSpy).not.toHaveBeenCalled();
  });

  it('does not claim approval request success when a 2xx response has no persisted request id', async () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: ['sales.invoices.view', 'sales.invoices.update'] } });
    mockedApi.get.mockResolvedValue({ data: { data: approvalData() } });
    mockedApi.post.mockResolvedValue({ data: { data: null } } as any);
    const errorSpy = vi.spyOn(message, 'error');
    const successSpy = vi.spyOn(message, 'success');

    renderWorkflow();
    await screen.findByText('Chưa có yêu cầu phê duyệt');
    fireEvent.click(screen.getByRole('button', { name: /Gửi yêu cầu phê duyệt$/ }));

    await waitFor(() => expect(errorSpy).toHaveBeenCalledWith('Máy chủ chưa trả về yêu cầu phê duyệt đã lưu. Không thể xác nhận thành công.'));
    expect(successSpy).not.toHaveBeenCalled();
  });

  it('keeps a posted invoice read-only and does not submit any request', async () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: ['sales.invoices.view', 'sales.invoices.update'] } });
    mockedApi.get.mockResolvedValue({ data: { data: approvalData({ is_posted: true }) } });

    renderWorkflow({ ...invoice, is_posted: true });

    expect(await screen.findByText('Hóa đơn đã ghi sổ')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Gửi yêu cầu phê duyệt$/ })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: /Gửi yêu cầu phê duyệt$/ }));
    expect(mockedApi.post).not.toHaveBeenCalled();
  });
});
