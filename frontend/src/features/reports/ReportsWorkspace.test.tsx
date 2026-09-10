import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import ReportsWorkspace from './ReportsWorkspace';
import { useReportCapabilities, type ReportCapability } from './reportCapabilities';

vi.mock('./reportCapabilities', async () => {
  const actual = await vi.importActual<typeof import('./reportCapabilities')>('./reportCapabilities');
  return { ...actual, useReportCapabilities: vi.fn() };
});

vi.mock('../../components/ModuleWorkspace', () => ({
  default: ({ title, items, onChange }: { title: string; items: Array<{ key: string; label: string; disabled?: boolean }>; onChange: (key: string) => void }) => (
    <div data-testid="module-workspace">
      <h1>{title}</h1>
      {items.map((item) => (
        <button key={item.key} disabled={item.disabled} onClick={() => onChange(item.key)}>{item.label}</button>
      ))}
    </div>
  ),
}));

vi.mock('./TrialBalanceReport', () => ({ default: () => <div>Bảng cân đối tài khoản</div> }));
vi.mock('./BalanceSheetReport', () => ({ default: () => <div>Bảng cân đối kế toán</div> }));
vi.mock('./IncomeStatementReport', () => ({ default: () => <div>Kết quả kinh doanh</div> }));
vi.mock('./GeneralLedger', () => ({ default: () => <div>Sổ cái</div> }));
vi.mock('./GeneralJournal', () => ({ default: () => <div>Sổ nhật ký</div> }));

const mockedCapabilities = vi.mocked(useReportCapabilities);

const manifest = (overrides: { capabilities?: ReportCapability[]; meta?: { disclaimer?: string; manifest_version?: string } } = {}) => ({
  meta: {
    disclaimer: 'Manifest kiểm thử — không phải chứng nhận BCTC.',
    manifest_version: 'report-capabilities.v1',
  },
  capabilities: [
    capability(),
    {
      key: 'cash_flow_statement',
      label: 'Báo cáo lưu chuyển tiền tệ',
      category: 'financial_statement',
      status: 'not_implemented',
      implementation_status: 'not_implemented',
      available: false,
      appendix_iv_certified: false,
      definition_version: null,
      route: null,
      reason: 'Chưa có định nghĩa được phê duyệt.',
    },
  ],
  ...overrides,
});

function capability(): ReportCapability {
  return {
    key: 'trial_balance',
    label: 'Bảng cân đối tài khoản',
    category: 'ledger',
    status: 'available',
    implementation_status: 'operational_draft',
    available: true,
    appendix_iv_certified: false,
    definition_version: null,
    route: 'reports/trial-balance',
    reason: null,
  };
}

function renderWorkspace(requestedReportKey?: 'trial_balance' | 'balance_sheet') {
  return render(
    <MemoryRouter>
      <ReportsWorkspace requestedReportKey={requestedReportKey} />
    </MemoryRouter>,
  );
}

describe('ReportsWorkspace capability boundary', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('does not render report content while capability verification is loading', () => {
    mockedCapabilities.mockReturnValue({ data: undefined, isLoading: true, isError: false } as ReturnType<typeof useReportCapabilities>);

    renderWorkspace();

    expect(screen.getByText('Đang tải danh sách báo cáo...')).toBeInTheDocument();
    expect(screen.queryByTestId('module-workspace')).not.toBeInTheDocument();
  });

  it('does not reserve an empty toolbar region while report capabilities are loading', () => {
    mockedCapabilities.mockReturnValue({ data: undefined, isLoading: true, isError: false } as ReturnType<typeof useReportCapabilities>);

    const { container } = renderWorkspace();

    expect(container.querySelector('[data-region="toolbar"]')).not.toBeInTheDocument();
  });

  it('fails closed on an unavailable manifest and does not use a legacy fallback report', () => {
    const refetch = vi.fn();
    mockedCapabilities.mockReturnValue({ data: undefined, isLoading: false, isError: true, refetch } as unknown as ReturnType<typeof useReportCapabilities>);

    renderWorkspace('trial_balance');

    expect(screen.getByText('Liên kết báo cáo chưa thể mở ở chế độ kiểm soát')).toBeInTheDocument();
    expect(screen.getByText(/Không xác minh được capability từ máy chủ/)).toBeInTheDocument();
    expect(screen.getByText(/không tự mở báo cáo bằng danh sách hard-code hoặc màn hình cũ/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Thử lại danh sách báo cáo' }));
    expect(refetch).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId('module-workspace')).not.toBeInTheDocument();
  });

  it('renders only server-published capabilities that can actually be opened', () => {
    mockedCapabilities.mockReturnValue({ data: manifest(), isLoading: false, isError: false } as ReturnType<typeof useReportCapabilities>);

    renderWorkspace();

    expect(screen.getByTestId('module-workspace')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Bảng cân đối tài khoản' })).toBeEnabled();
    expect(screen.queryByRole('button', { name: 'Báo cáo lưu chuyển tiền tệ (chưa hỗ trợ)' })).not.toBeInTheDocument();
    expect(screen.queryByText('Manifest kiểm thử — không phải chứng nhận BCTC.')).not.toBeInTheDocument();
  });

  it('does not render a server-advertised report when this UI has no matching component', () => {
    mockedCapabilities.mockReturnValue({
      data: manifest({
        capabilities: [
          capability(),
          {
            key: 'future_statement',
            label: 'Báo cáo phiên bản tương lai',
            category: 'financial_statement',
            status: 'available',
            implementation_status: 'operational_draft',
            available: true,
            appendix_iv_certified: false,
            definition_version: null,
            route: '/reports/future-statement',
            reason: null,
          },
        ],
      }),
      isLoading: false,
      isError: false,
    } as ReturnType<typeof useReportCapabilities>);

    renderWorkspace();

    expect(screen.queryByRole('button', { name: 'Báo cáo phiên bản tương lai (chưa hỗ trợ)' })).not.toBeInTheDocument();
    expect(screen.queryByText(/Backend có capability nhưng phiên bản giao diện này chưa có màn hình tương ứng/)).not.toBeInTheDocument();
  });

  it('keeps legacy form codes out of the internal report tab labels', () => {
    mockedCapabilities.mockReturnValue({
      data: manifest({ capabilities: [{
        ...capability(),
        label: 'Bảng cân đối kế toán (B01-DN)',
      }] }),
      isLoading: false,
      isError: false,
    } as ReturnType<typeof useReportCapabilities>);

    renderWorkspace();

    expect(screen.getByRole('button', { name: 'Bảng cân đối kế toán' })).toBeEnabled();
    expect(screen.queryByRole('button', { name: /B01-DN/ })).not.toBeInTheDocument();
  });

  it('blocks an unavailable compatibility deep-link and provides a safe return path', () => {
    mockedCapabilities.mockReturnValue({
      data: manifest({ capabilities: [{ ...capability(), available: false, reason: 'Chưa được máy chủ công bố.' }] }),
      isLoading: false,
      isError: false,
    } as ReturnType<typeof useReportCapabilities>);

    renderWorkspace('trial_balance');

    expect(screen.getByText('Liên kết báo cáo chưa thể mở ở chế độ kiểm soát')).toBeInTheDocument();
    expect(screen.queryByTestId('module-workspace')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Quay lại danh sách báo cáo' }));
  });
});
