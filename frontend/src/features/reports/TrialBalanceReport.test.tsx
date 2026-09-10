import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import TrialBalanceReport from './TrialBalanceReport';
import { useQuery } from '@tanstack/react-query';
import source from './TrialBalanceReport.tsx?raw';

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>();
  return { ...actual, useQuery: vi.fn() };
});

const mockedUseQuery = vi.mocked(useQuery);

describe('TrialBalanceReport data boundary', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('shows a retryable error instead of an empty table when the report request fails', () => {
    mockedUseQuery.mockReturnValue({
      data: undefined,
      error: { response: { status: 500, data: { request_id: 'req_trial_balance_500' } } },
      isError: true,
      isLoading: false,
      refetch: vi.fn(),
    } as unknown as ReturnType<typeof useQuery>);

    render(<TrialBalanceReport />);

    expect(screen.getByText('Không thể tải dữ liệu báo cáo: Bảng cân đối tài khoản')).toBeInTheDocument();
    expect(screen.getByText('req_trial_balance_500')).toBeInTheDocument();
    expect(screen.queryByText('Không có dữ liệu')).not.toBeInTheDocument();
  });

  it('rejects a malformed 2xx report envelope before rendering or exporting it', () => {
    expect(source).toContain("if (!Array.isArray(data))");
    expect(source).toContain("throw new Error('Invalid trial-balance report response')");
  });

  it('renders zero balances explicitly so an empty-looking report is not mistaken for missing data', () => {
    expect(source).not.toContain('emptyZero: true');
  });

  it('shows an explicit loading state instead of the empty-period message while the first request is pending', () => {
    expect(source).toContain("isLoading ? 'Đang tải dữ liệu báo cáo...' :");
  });
});
