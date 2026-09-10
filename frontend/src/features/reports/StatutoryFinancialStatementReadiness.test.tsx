import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import StatutoryFinancialStatementReadiness from './StatutoryFinancialStatementReadiness';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn() } }));
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});
const mockedApi = vi.mocked(api);
const readiness = {
  meta: { capability_version: 'statutory-statement-readiness.v1', fiscal_year_id: 12, accounting_regime: 'tt99', form_key: 'owner-controlled-form', as_of_date: '2026-06-30', read_only: true as const, statutory_output_available: false as const, legal_compliance_certified: false as const, disclaimer: 'Không phải BCTC.' },
  definition: { id: 8, definition_version: '2026.1', contract_hash: 'abc123', effective_from: '2026-01-01', effective_to: '2026-12-31' },
  definition_evidence_ready: true,
  execution_ready: false as const,
  missing_conditions: [{ key: 'notes_and_cashflow_execution', status: 'missing', reason: 'Chưa có engine thuyết minh hoặc lưu chuyển tiền tệ.' }],
};
function renderScreen() { return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><StatutoryFinancialStatementReadiness /></QueryClientProvider>); }
afterEach(() => { vi.clearAllMocks(); useAuthStore.setState({ user: null, token: null, isAuthenticated: false }); });

describe('StatutoryFinancialStatementReadiness', () => {
  it('does not request or present a statutory result without reports.view', () => {
    useAuthStore.setState({ user: { id: 4, name: 'No access', email: 'none@example.test', permissions: [] } });
    renderScreen();
    expect(screen.getByText('Bạn không có quyền xem readiness BCTC')).toBeInTheDocument();
    expect(mockedApi.get).not.toHaveBeenCalled();
  });

  it('sends an explicit fiscal context and keeps every execution action disabled', async () => {
    useAuthStore.setState({ user: { id: 5, name: 'Controller', email: 'controller@example.test', permissions: ['reports.view'] } });
    mockedApi.get.mockResolvedValue({ data: readiness });
    renderScreen();
    fireEvent.change(screen.getByLabelText('ID năm tài chính'), { target: { value: '12' } });
    fireEvent.change(screen.getByLabelText('Mã biểu mẫu từ catalogue'), { target: { value: 'owner-controlled-form' } });
    fireEvent.change(screen.getByLabelText('Ngày cutoff đánh giá'), { target: { value: '2026-06-30' } });
    fireEvent.click(screen.getByRole('button', { name: /Kiểm tra readiness/ }));
    expect(await screen.findByText('Backend chưa cấp quyền chạy BCTC')).toBeInTheDocument();
    expect(mockedApi.get).toHaveBeenCalledWith('/reports/statutory-financial-statement-readiness', { params: { fiscal_year_id: 12, form_key: 'owner-controlled-form', to_date: '2026-06-30' } });
    expect(screen.getByRole('button', { name: /Lập \/ chạy báo cáo/ })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Tải xuống' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Ký / phát hành' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Nộp hồ sơ' })).toBeDisabled();
    expect(screen.getByText('notes_and_cashflow_execution')).toBeInTheDocument();
  }, 15000);

  it('does not present a malformed 2xx response as statutory readiness evidence', async () => {
    useAuthStore.setState({ user: { id: 6, name: 'Controller', email: 'controller2@example.test', permissions: ['reports.view'] } });
    mockedApi.get.mockResolvedValue({ data: { meta: { read_only: true } } });
    renderScreen();
    fireEvent.change(screen.getByLabelText('ID năm tài chính'), { target: { value: '12' } });
    fireEvent.change(screen.getByLabelText('Mã biểu mẫu từ catalogue'), { target: { value: 'owner-controlled-form' } });
    fireEvent.change(screen.getByLabelText('Ngày cutoff đánh giá'), { target: { value: '2026-06-30' } });
    fireEvent.click(screen.getByRole('button', { name: /Kiểm tra readiness/ }));
    expect(await screen.findByText('Không thể xác minh readiness BCTC')).toBeInTheDocument();
    expect(screen.queryByText('Backend chưa cấp quyền chạy BCTC')).not.toBeInTheDocument();
  });

  it('does not present an incomplete catalogue object as readiness evidence', async () => {
    useAuthStore.setState({ user: { id: 7, name: 'Controller', email: 'controller3@example.test', permissions: ['reports.view'] } });
    mockedApi.get.mockResolvedValue({ data: { ...readiness, definition: {} } });
    renderScreen();
    fireEvent.change(screen.getByLabelText('ID năm tài chính'), { target: { value: '12' } });
    fireEvent.change(screen.getByLabelText('Mã biểu mẫu từ catalogue'), { target: { value: 'owner-controlled-form' } });
    fireEvent.change(screen.getByLabelText('Ngày cutoff đánh giá'), { target: { value: '2026-06-30' } });
    fireEvent.click(screen.getByRole('button', { name: /Kiểm tra readiness/ }));
    expect(await screen.findByText('Không thể xác minh readiness BCTC')).toBeInTheDocument();
    expect(screen.queryByText('Đủ evidence catalogue')).not.toBeInTheDocument();
  });
});
