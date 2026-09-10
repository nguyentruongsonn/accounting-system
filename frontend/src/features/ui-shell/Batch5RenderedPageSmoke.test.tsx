import { fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import InvoicesManagement from '../invoices-management/InvoicesManagement';
import PurchaseInvoices from '../purchase/PurchaseInvoices';
import SalesInvoices from '../sales/SalesInvoices';

vi.mock('../../api/axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
}));

const mockedApi = vi.mocked(api);

const renderWithQuery = (element: React.ReactElement) => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter>{element}</MemoryRouter>
  </QueryClientProvider>,
);

describe('batch 5 rendered page shell smoke coverage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockedApi.get.mockResolvedValue({ data: [] } as never);
  });

  it('renders purchase invoices with a semantic header, one toolbar, shared table surface, and framed create dialog', async () => {
    renderWithQuery(<PurchaseInvoices />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Chứng từ mua hàng' })).toBeNull();
    const toolbar = screen.getByTestId('ui-page-toolbar');
    expect(toolbar.parentElement).toHaveAttribute('data-region', 'toolbar');
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Thêm/ }));
    expect(await screen.findByTestId('ui-modal-frame')).toBeInTheDocument();
  }, 15_000);

  it('renders the sales invoice create dialog inside the shared modal frame', async () => {
    renderWithQuery(<SalesInvoices />);

    const toolbar = screen.getByTestId('ui-page-toolbar');
    expect(toolbar.parentElement).toHaveAttribute('data-region', 'toolbar');

    fireEvent.click(screen.getByRole('button', { name: /Thêm chứng từ bán hàng/ }));
    expect(await screen.findByTestId('ui-modal-frame')).toBeInTheDocument();
  });

  it('renders e-invoice management inside the shared page shell hierarchy', () => {
    render(<InvoicesManagement />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Quản lý hóa đơn điện tử' })).toBeNull();
    expect(screen.getAllByTestId('ui-page-toolbar')).toHaveLength(1);
    expect(screen.getAllByText('NOT IMPLEMENTED')[0]).toBeVisible();
  });
});
