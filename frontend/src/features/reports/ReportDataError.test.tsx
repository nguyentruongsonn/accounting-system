import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ReportDataError from './ReportDataError';

describe('ReportDataError', () => {
  it('does not look like an empty report and retries the exact failed request', () => {
    const retry = vi.fn();

    render(<ReportDataError reportName="Bảng cân đối tài khoản" onRetry={retry} />);

    expect(screen.getByText('Không thể tải dữ liệu báo cáo: Bảng cân đối tài khoản')).toBeInTheDocument();
    expect(screen.getByText(/Không hiển thị dữ liệu rỗng/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Thử lại' }));
    expect(retry).toHaveBeenCalledOnce();
  });

  it('shows a validated correlation ID for an authorization failure without raw details', () => {
    render(
      <ReportDataError
        reportName="Bảng cân đối tài khoản"
        onRetry={vi.fn()}
        error={{ response: { status: 403, data: { request_id: 'req_trial_balance_1' } } }}
      />,
    );

    expect(screen.getByText(/Máy chủ từ chối quyền tải báo cáo/)).toBeInTheDocument();
    expect(screen.getByText('req_trial_balance_1')).toBeInTheDocument();
    expect(screen.queryByText(/SQL|stack|token/i)).not.toBeInTheDocument();
  });
});
