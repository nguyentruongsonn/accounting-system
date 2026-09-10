import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import JournalEntries from './JournalEntries';
import api from '../../api/axios';

vi.mock('../../api/axios', () => ({
  default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}));

const mockedApi = vi.mocked(api);

// Ant Design modal/select effects can take longer than the default 5s in the
// shared Windows CI/browser-like jsdom environment. Keep the workflow test
// bounded, but avoid treating a valid async render as a product regression.
vi.setConfig({ testTimeout: 15_000 });

const draftEntry = {
  id: 55,
  voucher_number: 'PKT-00055',
  voucher_date: '2026-01-20',
  posting_date: '2026-01-20',
  reason: 'Điều chỉnh công nợ',
  status: 'draft',
  total_amount: 110,
  lines: [
    { account_code: '1111', description: 'Thu tiền', debit_amount: 100, credit_amount: 0 },
    { account_code: '1331', description: 'Thuế', debit_amount: 10, credit_amount: 0 },
    { account_code: '331', description: 'Phải trả', debit_amount: 0, credit_amount: 110 },
  ],
};

const renderPage = () => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
    <JournalEntries />
  </QueryClientProvider>,
);

afterEach(() => vi.clearAllMocks());

describe('JournalEntries draft edit workflow', () => {
  it('submits a persisted balanced resource with paired editable accounts and its original total', async () => {
    mockedApi.get.mockImplementation((url: string) => {
      if (url.startsWith('/gl/journal-entries')) return Promise.resolve({ data: { data: [draftEntry], current_page: 1, per_page: 20, total: 1 } } as never);
      if (url === '/master/accounts') return Promise.resolve({ data: [
        { code: '1111', name: 'Tiền mặt', is_active: true, is_parent: false },
        { code: '1331', name: 'Thuế GTGT', is_active: true, is_parent: false },
        { code: '331', name: 'Phải trả', is_active: true, is_parent: false },
      ] } as never);
      throw new Error(`Unexpected GET ${url}`);
    });
    mockedApi.put.mockResolvedValue({ data: { data: { id: 55 } } } as never);

    renderPage();

    fireEvent.click((await screen.findByRole('img', { name: 'edit' })).closest('button')!);
    fireEvent.click(screen.getByRole('button', { name: 'OK' }));

    await waitFor(() => expect(mockedApi.put).toHaveBeenCalledWith('/gl/journal-entries/55', expect.objectContaining({
      total_amount: '110.00',
      lines: [
        expect.objectContaining({ debit_account: '1111', credit_account: '331', amount: '100.00' }),
        expect.objectContaining({ debit_account: '1331', credit_account: '331', amount: '10.00' }),
      ],
    })));
  });

  it('shows malformed persisted journal rows as fail-closed and never re-submits them', async () => {
    mockedApi.get.mockImplementation((url: string) => {
      if (url.startsWith('/gl/journal-entries')) return Promise.resolve({ data: { data: [{
        ...draftEntry,
        id: 56,
        voucher_number: 'PKT-00056',
        lines: [{ account_code: '1121', description: 'Dòng hai bên', debit_amount: 25, credit_amount: 25 }],
      }], current_page: 1, per_page: 20, total: 1 } } as never);
      if (url === '/master/accounts') return Promise.resolve({ data: [
        { code: '1121', name: 'Tiền gửi ngân hàng', is_active: true, is_parent: false },
      ] } as never);
      throw new Error(`Unexpected GET ${url}`);
    });

    renderPage();

    fireEvent.click((await screen.findByRole('img', { name: 'edit' })).closest('button')!);
    expect(await screen.findByText('Dữ liệu bút toán đã lưu không hợp lệ. Không thể lưu lại chứng từ này.')).toBeInTheDocument();
    const saveButton = screen.getByRole('button', { name: 'OK' });
    expect(saveButton).toBeDisabled();
    fireEvent.click(saveButton);

    expect(mockedApi.put).not.toHaveBeenCalled();
  });

  it('shows mixed-sign persisted rows as fail-closed and never drops their invalid side on save', async () => {
    mockedApi.get.mockImplementation((url: string) => {
      if (url.startsWith('/gl/journal-entries')) return Promise.resolve({ data: { data: [{
        ...draftEntry,
        id: 57,
        voucher_number: 'PKT-00057',
        lines: [{ account_code: '1121', description: 'Nợ âm', debit_amount: -25, credit_amount: 25 }],
      }], current_page: 1, per_page: 20, total: 1 } } as never);
      if (url === '/master/accounts') return Promise.resolve({ data: [
        { code: '1121', name: 'Tiền gửi ngân hàng', is_active: true, is_parent: false },
      ] } as never);
      throw new Error(`Unexpected GET ${url}`);
    });

    renderPage();

    fireEvent.click((await screen.findByRole('img', { name: 'edit' })).closest('button')!);
    expect(await screen.findByText('Dữ liệu bút toán đã lưu không hợp lệ. Không thể lưu lại chứng từ này.')).toBeInTheDocument();
    const saveButton = screen.getByRole('button', { name: 'OK' });
    expect(saveButton).toBeDisabled();
    fireEvent.click(saveButton);

    expect(mockedApi.put).not.toHaveBeenCalled();
  });
});
