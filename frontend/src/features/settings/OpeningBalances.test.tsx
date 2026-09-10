import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import api from '../../api/axios';
import OpeningBalances from './OpeningBalances';
import openingBalancesSource from './OpeningBalances.tsx?raw';

vi.mock('../../api/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));

describe('opening balance workbench', () => {
  it('keeps the opening-balance date and status in the shared page toolbar', () => {
    expect(openingBalancesSource).toContain("from '../../components/layout/PageToolbar'");
    expect(openingBalancesSource).toContain('<PageToolbar');
    expect(openingBalancesSource).toContain('filters={');
    expect(openingBalancesSource).not.toContain('className="misa-toolbar"');
  });

  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(api.get).mockImplementation(async (url: string) => {
      if (url === '/opening-balances') return { data: { data: [] } };
      if (url === '/master/accounts') return { data: [{ id: 1, code: '131', name: 'Phải thu khách hàng', is_parent: false, is_active: true }] };
      return { data: [] };
    });
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: 1, status: 'draft', effective_date: '2026-01-01', account_lines: [], party_lines: [], inventory_lines: [] } } });
  });

  it('shows the three reconciled opening sections and persists a draft through the API', async () => {
    render(<OpeningBalances />);
    expect(await screen.findByRole('tab', { name: 'Tài khoản' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Công nợ' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Tồn kho' })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Ngày bắt đầu dữ liệu'), { target: { value: '2026-01-01' } });
    fireEvent.click(screen.getByRole('button', { name: /Lưu bản nháp/i }));
    await waitFor(() => expect(api.post).toHaveBeenCalledWith('/opening-balances', expect.objectContaining({ effective_date: '2026-01-01' })));
  });

  it('keeps a malformed catalogue response distinct from a valid empty catalogue', async () => {
    vi.mocked(api.get).mockImplementation(async (url: string) => {
      if (url === '/opening-balances') return { data: { data: [] } };
      if (url === '/master/accounts') return { data: { unexpected: [] } };
      return { data: [] };
    });

    render(<OpeningBalances />);

    expect(await screen.findByText('Phản hồi danh mục tài khoản không hợp lệ.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Thử lại số dư đầu kỳ' })).toBeInTheDocument();
  });
});
