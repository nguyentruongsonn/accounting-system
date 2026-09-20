import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AxiosRequestConfig } from 'axios';
import api from '../../api/axios';
import OpeningBalances from './OpeningBalances';

vi.mock('../../api/axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
  },
}));

vi.mock('../../components/feedback/toast', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    info: vi.fn(),
  },
}));

type Deferred<T> = {
  promise: Promise<T>;
  resolve: (value: T) => void;
};

const deferred = <T,>(): Deferred<T> => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => { resolve = next; });
  return { promise, resolve };
};

const packageResponse = {
  data: {
    data: [{
      id: 1,
      status: 'draft' as const,
      effective_date: '2026-01-01',
      account_lines: [],
      party_lines: [],
      inventory_lines: [],
      tool_lines: [],
      fixed_asset_lines: [],
      prepaid_lines: [],
      wip_lines: [],
    }],
  },
};

const catalogueResponses: Record<string, unknown> = {
  '/master/accounts': { data: [{ id: 1, code: '111', name: 'Tiền mặt' }] },
  '/master/customers': { data: [] },
  '/master/suppliers': { data: [] },
  '/inventory/items': { data: [] },
  '/master/warehouses': { data: [] },
  '/opening-balances/catalogues': { data: { employees: [], tools: [], fixed_assets: [] } },
};

describe('OpeningBalances', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('does not allow edit mode to start while a date reload is still applying', async () => {
    const dateLoad = deferred<typeof packageResponse>();

    vi.mocked(api.get).mockImplementation((url: string, config?: AxiosRequestConfig) => {
      if (url === '/opening-balances') {
        const effectiveDate = (config?.params as { effective_date?: string } | undefined)?.effective_date;
        if (effectiveDate === '2026-02-01') return dateLoad.promise as never;
        return Promise.resolve(packageResponse) as never;
      }
      return Promise.resolve(catalogueResponses[url]) as never;
    });

    render(<OpeningBalances />);
    await waitFor(() => expect(screen.getByText('Bản nháp')).toBeInTheDocument());
    const editButton = await screen.findByRole('button', { name: /Chỉnh sửa/ });
    await waitFor(() => expect(editButton).toBeEnabled());

    fireEvent.change(screen.getByLabelText('Ngày bắt đầu dữ liệu'), { target: { value: '2026-02-01' } });
    await waitFor(() => expect(editButton).toBeDisabled());

    dateLoad.resolve({ data: { data: [{ ...packageResponse.data.data[0], effective_date: '2026-02-01' }] } });
    await waitFor(() => expect(screen.getByRole('button', { name: /Chỉnh sửa/ })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: /Chỉnh sửa/ }));
    await waitFor(() => expect(screen.getByRole('button', { name: /Lưu thay đổi/ })).toBeInTheDocument());
  });
});
