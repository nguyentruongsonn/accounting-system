import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { message } from 'antd';
import ApArReconciliationInputBoundary from './ApArReconciliationInputBoundary';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
const mockedApi = vi.mocked(api);
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false, media: query, onchange: null, addListener: vi.fn(), removeListener: vi.fn(),
    addEventListener: vi.fn(), removeEventListener: vi.fn(), dispatchEvent: vi.fn(),
  })),
});
class ResizeObserverMock {
  observe() {}
  unobserve() {}
  disconnect() {}
}
vi.stubGlobal('ResizeObserver', ResizeObserverMock);
const page = { data: [{ uuid: 'run-ap-1', ledger: 'ap', as_of_date: '2026-06-30', status: 'not_available', algorithm_version: 'v1', input_cutoff_at: null, input_boundary: null, snapshot_hash: 'hash', amounts: null, amounts_calculated: false, tie_out_calculated: false, close_authority: false, limitation: 'Capture only.' }] };
function renderScreen() { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}><ApArReconciliationInputBoundary /></QueryClientProvider>); }
afterEach(() => { vi.clearAllMocks(); useAuthStore.setState({ user: null, token: null, isAuthenticated: false }); });

describe('ApArReconciliationInputBoundary', () => {
  it('does not load data without the distinct view permission', () => {
    useAuthStore.setState({ user: { id: 1, name: 'none', email: 'none@test', permissions: [] } });
    renderScreen();
    expect(screen.getByText('Bạn không có quyền xem bằng chứng AP/AR')).toBeInTheDocument();
    expect(mockedApi.get).not.toHaveBeenCalled();
  });

  it('renders server evidence but keeps capture hidden for a view-only user', async () => {
    useAuthStore.setState({ user: { id: 2, name: 'viewer', email: 'viewer@test', permissions: ['apar.reconciliations.view'] } });
    mockedApi.get.mockResolvedValue({ data: page });
    renderScreen();
    expect(await screen.findByText('Chưa khả dụng')).toBeInTheDocument();
    expect(screen.getByText('Chỉ có quyền xem evidence')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Capture bằng chứng cutoff/ })).not.toBeInTheDocument();
  });

  it('does not claim a successful capture when the server rejects submission', async () => {
    useAuthStore.setState({ user: { id: 3, name: 'capturer', email: 'capture@test', permissions: ['apar.reconciliations.view', 'apar.reconciliations.capture'] } });
    mockedApi.get.mockResolvedValue({ data: page });
    mockedApi.post.mockRejectedValue(new Error('forbidden'));
    const error = vi.spyOn(message, 'error').mockImplementation(() => undefined as never);
    renderScreen();
    await screen.findByText('Chưa khả dụng');
    fireEvent.mouseDown(screen.getByLabelText('Ledger AP AR'));
    fireEvent.click((await screen.findAllByText('Phải trả (AP)')).at(-1)!);
    fireEvent.change(screen.getByLabelText('Ngày cutoff AP AR'), { target: { value: '2026-06-30' } });
    fireEvent.click(screen.getByRole('button', { name: /Capture bằng chứng cutoff/ }));
    await waitFor(() => expect(mockedApi.post).toHaveBeenCalledWith('/ap-ar/reconciliation-input-boundaries', { ledger: 'ap', as_of_date: '2026-06-30' }));
    await waitFor(() => expect(error).toHaveBeenCalledWith(expect.stringMatching(/Máy chủ từ chối capture/)));
  });

  it('does not claim a capture when a 2xx response lacks persisted evidence identity', async () => {
    useAuthStore.setState({ user: { id: 6, name: 'capturer', email: 'capture2@test', permissions: ['apar.reconciliations.view', 'apar.reconciliations.capture'] } });
    mockedApi.get.mockResolvedValue({ data: page });
    mockedApi.post.mockResolvedValue({ data: { data: { status: 'not_available' } } });
    const success = vi.spyOn(message, 'success').mockImplementation(() => undefined as never);
    const error = vi.spyOn(message, 'error').mockImplementation(() => undefined as never);
    renderScreen();
    await screen.findByText('Chưa khả dụng');
    fireEvent.mouseDown(screen.getByLabelText('Ledger AP AR'));
    fireEvent.click((await screen.findAllByText('Phải trả (AP)')).at(-1)!);
    fireEvent.change(screen.getByLabelText('Ngày cutoff AP AR'), { target: { value: '2026-06-30' } });
    fireEvent.click(screen.getByRole('button', { name: /Capture bằng chứng cutoff/ }));
    await waitFor(() => expect(error).toHaveBeenCalledWith(expect.stringMatching(/Máy chủ từ chối capture/)));
    expect(success).not.toHaveBeenCalled();
  });

  it('presents the server-calculated party roll-forward in the evidence drawer', { timeout: 15_000 }, async () => {
    useAuthStore.setState({ user: { id: 7, name: 'reviewer', email: 'reviewer@test', permissions: ['apar.reconciliations.view'] } });
    const calculated = {
      ...page.data[0],
      status: 'approved',
      input_cutoff_at: '2026-06-30T10:00:00.000Z',
      amounts_calculated: true,
      tie_out_calculated: true,
      close_authority: true,
      amounts: {
        subledger_balance: '60000.00', gl_control_balance: '60000.00', difference: '0.00',
        party_rollforward: [{ party_type: 'supplier', party_id: 42, opening_balance: '0.00', invoice_balance: '100000.00', settlement_reduction: '40000.00', settlement_reversal: '0.00', ending_balance: '60000.00' }],
      },
      limitation: 'Server-calculated controlled reconciliation.',
    };
    mockedApi.get.mockImplementation(async (url) => url.includes('run-ap-1') ? { data: { data: calculated } } : { data: { data: [calculated] } });
    renderScreen();
    await screen.findByText('Đã đối chiếu');
    fireEvent.click(screen.getByRole('button', { name: /Xem evidence run-ap-1/ }));
    expect(await screen.findByText('Chi tiết theo đối tượng')).toBeInTheDocument();
    expect(screen.getByText('Nhà cung cấp')).toBeInTheDocument();
    expect(screen.getByText('60000.00')).toBeInTheDocument();
  });

  it('fails closed when the server sends a malformed party roll-forward', async () => {
    useAuthStore.setState({ user: { id: 8, name: 'reviewer', email: 'reviewer2@test', permissions: ['apar.reconciliations.view'] } });
    mockedApi.get.mockResolvedValue({ data: { data: [{ ...page.data[0], amounts_calculated: true, tie_out_calculated: true, amounts: { party_rollforward: 'not-an-array' } }] } });
    renderScreen();
    expect(await screen.findByText('Không thể tải bằng chứng cutoff AP/AR')).toBeInTheDocument();
  });
});
