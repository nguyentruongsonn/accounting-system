import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ManagementReportDataError from './ManagementReportDataError';

describe('ManagementReportDataError', () => {
  it('keeps a failed request visible and exposes an explicit retry action', () => {
    const retry = vi.fn();

    render(<ManagementReportDataError reportName="báo cáo vận hành" onRetry={retry} />);

    expect(screen.getByText('Không thể tải dữ liệu báo cáo: báo cáo vận hành')).toBeInTheDocument();
    expect(screen.getByText(/Không hiển thị dữ liệu rỗng/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Thử lại' }));
    expect(retry).toHaveBeenCalledOnce();
  });

  it('shows a neutral authorization denial instead of falling back to an empty report', () => {
    render(
      <ManagementReportDataError
        reportName="báo cáo vận hành"
        onRetry={vi.fn()}
        error={{ response: { status: 403, data: { request_id: 'req_abc123' } } }}
      />,
    );

    expect(screen.getByText(/Máy chủ từ chối quyền tải dữ liệu báo cáo/)).toBeInTheDocument();
    expect(screen.getByText('req_abc123')).toBeInTheDocument();
    expect(screen.queryByText(/Không thể tải dữ liệu báo cáo vận hành$/)).not.toBeInTheDocument();
  });

  it('makes v2 definition unavailability explicit without exposing a raw API response', () => {
    render(
      <ManagementReportDataError
        reportName="báo cáo tuổi nợ phải trả"
        onRetry={vi.fn()}
        error={{ response: { status: 409, data: { error_code: 'DEFINITION_UNAVAILABLE' } } }}
      />,
    );

    expect(screen.getByText(/Definition version báo cáo chưa thể thực thi/)).toBeInTheDocument();
    expect(screen.getByText(/không thay thế bằng phép tính legacy/)).toBeInTheDocument();
  });

  it('uses endpoint-oriented copy when the requested report version has no endpoint', () => {
    render(
      <ManagementReportDataError
        reportName="báo cáo tuổi nợ phải trả"
        onRetry={vi.fn()}
        error={{ response: { status: 404, data: { request_id: 'req_missing_report' } } }}
      />,
    );

    expect(screen.getByText(/Không có endpoint cho phiên bản báo cáo này/)).toBeInTheDocument();
    expect(screen.queryByText(/chưa khả dụng|chưa được công bố/)).not.toBeInTheDocument();
  });
});
