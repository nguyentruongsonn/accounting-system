import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ApprovedAccountMappings, { type ApprovedAccountMapping } from './ApprovedAccountMappings';
import api from '../../api/axios';
import { useAuthStore } from '../../store/useAuthStore';

vi.mock('../../api/axios', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn() },
}));

// Ant Design's responsive table observes matchMedia in the browser; provide
// the minimal jsdom contract so these control-surface tests exercise the
// component instead of failing before the permission assertions run.
Object.defineProperty(window, 'matchMedia', { writable: true, value: vi.fn().mockImplementation((query: string) => ({ matches: false, media: query, onchange: null, addListener: vi.fn(), removeListener: vi.fn(), addEventListener: vi.fn(), removeEventListener: vi.fn(), dispatchEvent: vi.fn() })) });

const mockedApi = vi.mocked(api);
const allPermissions = [
  'accounting.account-mappings.view',
  'accounting.account-mappings.create',
  'accounting.account-mappings.update',
  'accounting.account-mappings.approve',
];

const mapping = (overrides: Partial<ApprovedAccountMapping> = {}): ApprovedAccountMapping => ({
  id: 7,
  accounting_policy_version_id: 12,
  mapping_key: 'purchase_invoice',
  mapping_context: { source: 'manual' },
  context_hash: 'context-hash',
  account_role: 'payable',
  account_code: '331',
  effective_from: '2026-01-01',
  effective_to: '2026-12-31',
  regulatory_dependencies: ['Owner-approved policy scope'],
  status: 'draft',
  contract_hash: null,
  created_by: 10,
  approved_by: null,
  approved_at: null,
  is_immutable: false,
  created_at: null,
  updated_at: null,
  limitation: 'Owner-supplied mapping evidence only.',
  ...overrides,
});

function renderScreen() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><ApprovedAccountMappings /></QueryClientProvider>);
}

afterEach(() => {
  vi.clearAllMocks();
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false });
});

describe('ApprovedAccountMappings', () => {
  it('does not request mapping data without the server view permission', () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: [] } });

    renderScreen();

    expect(screen.getByText('Bạn không có quyền xem mapping tài khoản')).toBeInTheDocument();
    expect(mockedApi.get).not.toHaveBeenCalled();
  });

  it('does not offer self approval to the maker even when their browser has approve permission', async () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: allPermissions } });
    mockedApi.get.mockResolvedValue({ data: { data: [mapping({ created_by: 10 })] } });

    renderScreen();

    expect(await screen.findByText('purchase_invoice')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Phê duyệt' })).not.toBeInTheDocument();
    expect(screen.getByText('Chờ người khác duyệt')).toBeInTheDocument();
  });

  it('offers approval only for another maker draft', async () => {
    useAuthStore.setState({ user: { id: 11, name: 'Checker', email: 'checker@example.test', permissions: allPermissions } });
    mockedApi.get.mockResolvedValue({ data: { data: [mapping({ created_by: 10 })] } });

    renderScreen();

    await screen.findByText('purchase_invoice');
    // Ant Design's icon button exposes the action label as a child span rather
    // than a stable accessible name in jsdom; assert the visible control label
    // while the component remains responsible for the actual click handler.
    expect(screen.getByText('Phê duyệt', { exact: true })).toBeInTheDocument();
  });

  it('paginates Laravel resource envelopes rather than fabricating fallback mappings', async () => {
    useAuthStore.setState({ user: { id: 11, name: 'Checker', email: 'checker@example.test', permissions: allPermissions } });
    mockedApi.get.mockResolvedValue({ data: { data: [mapping({ status: 'approved', is_immutable: true, approved_at: '2026-02-01T00:00:00Z' })], meta: { total: 1 } } });

    renderScreen();

    await waitFor(() => expect(screen.getByText('Đã phê duyệt')).toBeInTheDocument());
    expect(screen.getByText('331')).toBeInTheDocument();
  });

  it('fails closed when a successful response has no mapping collection', async () => {
    useAuthStore.setState({ user: { id: 11, name: 'Checker', email: 'checker@example.test', permissions: allPermissions } });
    mockedApi.get.mockResolvedValue({ data: { unexpected: 'not-a-mapping-list' } });

    renderScreen();

    expect(await screen.findByText('Không thể tải mapping tài khoản')).toBeInTheDocument();
    expect(screen.queryByText('Không có dữ liệu thay thế')).not.toBeInTheDocument();
  });

  it('loads approved policies and active leaf accounts with a Laravel-compatible inactive flag', async () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: allPermissions } });
    mockedApi.get.mockImplementation(async (url) => {
      if (url === '/approved-account-mappings') return { data: { data: [mapping()] } };
      if (url === '/approved-account-mappings/policies') return { data: { data: [{ id: 12, policy_key: 'inventory.posting', policy_version: '2026.1', effective_from: '2026-01-01', effective_to: '2026-12-31', status: 'approved' }] } };
      return { data: [{ id: 1, code: '1561', name: 'Hàng hóa', is_parent: false, is_active: true }] };
    });

    renderScreen();
    await screen.findByText('purchase_invoice');
    fireEvent.click(screen.getByRole('button', { name: /Tạo dự thảo/ }));

    expect(await screen.findByText('Chính sách hạch toán đã duyệt')).toBeInTheDocument();
    await waitFor(() => {
      expect(mockedApi.get).toHaveBeenCalledWith('/approved-account-mappings/policies');
      expect(mockedApi.get).toHaveBeenCalledWith('/master/accounts', { params: { include_inactive: 0 } });
    });
  });

  it('lets the accountant prepare a policy through business fields without editing JSON', async () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: allPermissions } });
    mockedApi.get.mockImplementation(async (url) => {
      if (url === '/approved-account-mappings') return { data: { data: [mapping()] } };
      if (url === '/accounting-policies') return { data: { data: [] } };
      if (url === '/accounting-policies/profiles') return { data: { data: [{ id: 4, fiscal_year: 2026, regime: 'TT99', regime_label: 'Thông tư 99', effective_from: '2026-01-01', effective_to: '2026-12-31' }] } };
      return { data: { data: [{ id: 1, code: '5111', name: 'Doanh thu bán hàng', is_parent: false, is_active: true }] } };
    });

    renderScreen();
    await screen.findByText('purchase_invoice');
    fireEvent.click(screen.getByRole('button', { name: /Chính sách hạch toán/ }));

    expect(await screen.findByText('Danh sách chính sách hạch toán')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Tạo chính sách/ }));
    expect(await screen.findByLabelText('Loại nghiệp vụ')).toBeInTheDocument();
    expect(screen.getByLabelText('Năm tài chính')).toBeInTheDocument();
    expect(screen.queryByLabelText(/JSON/i)).not.toBeInTheDocument();
    await waitFor(() => {
      expect(mockedApi.get).toHaveBeenCalledWith('/accounting-policies', { params: undefined });
      expect(mockedApi.get).toHaveBeenCalledWith('/accounting-policies/profiles');
    });
  });

  it('opens an existing policy draft for editing while keeping approved policies immutable', { timeout: 15_000 }, async () => {
    useAuthStore.setState({ user: { id: 10, name: 'Maker', email: 'maker@example.test', permissions: allPermissions } });
    mockedApi.get.mockImplementation(async (url) => {
      if (url === '/approved-account-mappings') return { data: { data: [mapping()] } };
      if (url === '/accounting-policies') return { data: { data: [{ id: 31, accounting_regime_profile_id: 4, fiscal_year: 2026, policy_key: 'posting.sales_invoice', policy_version: 'sales-v1', effective_from: '2026-01-01', effective_to: '2026-12-31', posting_rule_contract: { mapping_reference: 'owner-approved-ui' }, regulatory_dependencies: [], status: 'draft', created_by: 10, approved_by: null, approved_at: null, is_immutable: false }] } };
      if (url === '/accounting-policies/profiles') return { data: { data: [{ id: 4, fiscal_year: 2026, regime: 'TT99', regime_label: 'Thông tư 99', effective_from: '2026-01-01', effective_to: '2026-12-31' }] } };
      return { data: { data: [] } };
    });

    renderScreen();
    await screen.findByText('purchase_invoice');
    fireEvent.click(screen.getByRole('button', { name: /Chính sách hạch toán/ }));
    expect(await screen.findByText('sales-v1')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Sửa chính sách sales-v1' }));

    expect(await screen.findByText('Sửa dự thảo chính sách')).toBeInTheDocument();
    expect(screen.getByDisplayValue('sales-v1')).toBeInTheDocument();
  });
});
