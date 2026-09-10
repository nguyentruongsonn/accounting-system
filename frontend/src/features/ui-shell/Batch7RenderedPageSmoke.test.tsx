import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import Items from '../inventory/Items';
import { InventoryReceipts } from '../inventory/InventoryReceipts';
import { InventoryIssues } from '../inventory/InventoryIssues';
import JournalEntries from '../gl/JournalEntries';
import Periods from '../gl/Periods';
import { PeriodLock } from '../gl/PeriodLock';
import StatutoryFinancialStatementReadiness from '../reports/StatutoryFinancialStatementReadiness';
import { useAuthStore } from '../../store/useAuthStore';

vi.mock('../../api/axios', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const mockedApi = vi.mocked(api);

const renderPage = (page: React.ReactElement) => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
    {page}
  </QueryClientProvider>,
);

describe('batch 7 rendered page shell smoke coverage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockedApi.get.mockResolvedValue({ data: [] } as never);
  });

  afterEach(() => {
    useAuthStore.setState({ user: null, token: null, isAuthenticated: false });
  });

  it('renders the inventory item list and create dialog through the shared page, table, and modal regions', async () => {
    renderPage(<Items />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Danh mục Vật tư hàng hóa' })).toBeNull();
    expect(screen.queryByTestId('page-title-content')).toBeNull();
    expect(screen.getByTestId('ui-page-toolbar')).toBeInTheDocument();
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Thêm mới/ }));
    expect(await screen.findByTestId('ui-modal-frame')).toBeInTheDocument();
  });

  it.each([
    ['Phiếu nhập kho', 'Thêm Nhập kho', InventoryReceipts],
    ['Phiếu xuất kho', 'Thêm Xuất kho', InventoryIssues],
  ])('renders the %s create form inside the shared modal frame', async (heading, createLabel, Component) => {
    renderPage(<Component />);

    expect(screen.queryByRole('heading', { level: 1, name: heading })).toBeNull();
    expect(screen.getByTestId('ui-page-toolbar')).toBeInTheDocument();
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: new RegExp(createLabel) }));
    expect(await screen.findByTestId('ui-modal-frame')).toBeInTheDocument();
  });

  it('renders the GL journal list and create dialog inside the shared modal frame', async () => {
    renderPage(<JournalEntries />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Chứng từ kế toán tổng hợp' })).toBeNull();
    expect(screen.getByTestId('ui-page-toolbar')).toBeInTheDocument();
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /Thêm Chứng từ/ }));
    expect(await screen.findByTestId('ui-modal-frame')).toBeInTheDocument();
  });

  it('keeps the accounting-period failure warning visible with the table surface', async () => {
    mockedApi.get.mockRejectedValueOnce(new Error('offline'));
    renderPage(<Periods />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Kỳ kế toán' })).toBeNull();
    expect(screen.getByTestId('ui-table-surface')).toBeInTheDocument();
    expect(await screen.findByText('Không thể tải trạng thái kỳ kế toán')).toBeVisible();
    expect(await screen.findByRole('button', { name: 'Thử lại kỳ kế toán' })).toBeVisible();
  });

  it('does not add an empty page toolbar around the period-lock workspace', () => {
    renderPage(<PeriodLock />);

    expect(screen.queryAllByTestId('ui-page-toolbar')).toHaveLength(0);
  });

  it('keeps the statutory readiness permission warning visible and avoids any request without reports.view', () => {
    useAuthStore.setState({ user: { id: 701, name: 'No access', email: 'none@example.test', permissions: [] } });
    renderPage(<StatutoryFinancialStatementReadiness />);

    expect(screen.queryByRole('heading', { level: 1, name: 'Đánh giá sẵn sàng lập BCTC' })).toBeNull();
    expect(screen.getByText('Bạn không có quyền xem readiness BCTC')).toBeVisible();
    expect(mockedApi.get).not.toHaveBeenCalled();
  });
});
