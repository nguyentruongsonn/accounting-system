import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import Dashboard from '../dashboard/Dashboard';
import FixedAssets from '../assets/FixedAssets';
import Tools from '../assets/Tools';
import CostingWorkspace from '../costing/CostingWorkspace';
import ChartOfAccounts from '../master/ChartOfAccounts';
import Customers from '../master/Customers';
import Employees from '../master/Employees';
import Suppliers from '../master/Suppliers';
import AccountingAccountCatalogues from '../settings/AccountingAccountCatalogues';
import RoleManagement from '../settings/RoleManagement';
import SystemOptions from '../settings/SystemOptions';

vi.mock('../../api/axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

vi.mock('../../store/useAuthStore', () => ({
  useAuthStore: (selector: (state: { user: { roles: string[] } }) => unknown) => selector({ user: { roles: ['admin'] } }),
}));

const mockedApi = vi.mocked(api);

const renderWithQuery = (element: React.ReactElement) => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    {element}
  </QueryClientProvider>,
);

describe('batch 4 rendered page shell smoke coverage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockedApi.get.mockResolvedValue({ data: [] } as never);
  });

  it('renders the master account catalogue with a semantic header, one toolbar, table surface, and framed create dialog', async () => {
    renderWithQuery(<ChartOfAccounts />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Hệ thống tài khoản kế toán' })).toBeNull();
    expect(screen.getByTestId('ui-page-toolbar')).toBeInTheDocument();
    expect(screen.getByTestId('ui-page-toolbar')).not.toHaveTextContent('Hệ thống tài khoản');
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Thêm tài khoản/ }));
    expect(await screen.findByTestId('ui-modal-frame')).toBeInTheDocument();
  });

  it('keeps malformed master catalogue responses visibly distinct from an empty catalogue', async () => {
    mockedApi.get.mockResolvedValue({ data: {} } as never);

    const { unmount } = renderWithQuery(<ChartOfAccounts />);
    expect(await screen.findByText('Không thể tải hệ thống tài khoản')).toBeVisible();
    unmount();

    renderWithQuery(<Customers />);
    expect(await screen.findByText('Không thể tải danh sách khách hàng')).toBeVisible();
  });

  it('keeps malformed supplier and employee catalogues visibly distinct from empty lists', async () => {
    mockedApi.get.mockResolvedValue({ data: {} } as never);

    const { unmount } = renderWithQuery(<Suppliers />);
    expect(await screen.findByText('Không thể tải danh sách nhà cung cấp')).toBeVisible();
    unmount();

    render(<Employees />);
    expect(await screen.findByText('Không thể tải danh sách nhân viên')).toBeVisible();
  });

  it('keeps every account-catalogue tab inside a shared table surface with scroll and summary regions', () => {
    renderWithQuery(<MemoryRouter><AccountingAccountCatalogues /></MemoryRouter>);

    for (const tabName of [/Hệ thống tài khoản/, /Tài khoản ngầm định/, /Tài khoản kết chuyển/]) {
      fireEvent.click(screen.getByRole('tab', { name: tabName }));
    }

    expect(screen.getAllByTestId('ui-table-surface')).toHaveLength(3);
    expect(screen.getAllByTestId('ui-table-scroll')).toHaveLength(3);
    expect(screen.getAllByTestId('ui-table-summary')).toHaveLength(3);
  });

  it('renders settings unavailable actions inside the canonical toolbar region', () => {
    render(<SystemOptions />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Tùy chọn hệ thống' })).toBeNull();
    const toolbar = screen.getByTestId('ui-page-toolbar');
    expect(toolbar.parentElement).toHaveAttribute('data-region', 'toolbar');
    expect(toolbar).toContainElement(screen.getByRole('button', { name: 'Lưu tùy chọn (chưa khả dụng)' }));
  });

  it('renders the fixed two-role user-management action inside the canonical toolbar region', () => {
    render(<RoleManagement />);

    const toolbar = screen.getByTestId('ui-page-toolbar');
    expect(toolbar.parentElement).toHaveAttribute('data-region', 'toolbar');
    expect(toolbar).toContainElement(screen.getByRole('button', { name: 'Thêm người dùng' }));
  });

  it('renders the dashboard page shell without an empty toolbar', async () => {
    renderWithQuery(<MemoryRouter><Dashboard /></MemoryRouter>);

    expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
    expect(screen.queryByTestId('ui-page-toolbar')).toBeNull();
    await waitFor(() => expect(mockedApi.get).toHaveBeenCalled());
  });

  it('renders the tools surface with its warning, toolbar, and table inside the shared shell', () => {
    render(<Tools />);

    expect(screen.queryByRole('heading', { level: 1, name: /Công cụ dụng cụ/ })).toBeNull();
    expect(screen.getByTestId('ui-page-toolbar').parentElement).toHaveAttribute('data-region', 'toolbar');
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();
    expect(screen.getByText('Danh sách CCDC chưa khả dụng')).toBeVisible();
  });

  it('renders the fixed-assets action inside the canonical toolbar region', () => {
    render(<FixedAssets />);

    const toolbar = screen.getByTestId('ui-page-toolbar');
    expect(toolbar.parentElement).toHaveAttribute('data-region', 'toolbar');
    expect(toolbar).toContainElement(screen.getByRole('button', { name: /Chạy Khấu hao tháng/ }));
  });

  it('renders the costing workspace with one semantic heading and toolbar', () => {
    render(<MemoryRouter><CostingWorkspace /></MemoryRouter>);

    expect(screen.queryByRole('heading', { level: 1, name: 'Giá thành' })).toBeNull();
    expect(screen.getAllByTestId('ui-page-toolbar')).toHaveLength(1);
  });
});
