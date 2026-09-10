import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ManagementReportGate from './ManagementReportGate';
import { useManagementReportCapabilities } from './managementReportCapabilities';
import type { ManagementReportCapabilityManifest } from './managementReportCapabilities';

vi.mock('./managementReportCapabilities', () => ({
  useManagementReportCapabilities: vi.fn(),
}));

const mockedCapabilities = vi.mocked(useManagementReportCapabilities);

const capability = (overrides = {}) => ({
  schema: 'management-report-capability.v1' as const,
  key: 'accounts_payable_aging',
  label: 'Phân tích tuổi nợ phải trả',
  route: 'purchase/ap-aging',
  status: 'available',
  implementation_status: 'operational_draft',
  available: true,
  read_only: true,
  required_permission: 'purchase.reports.view',
  authorized_for_current_actor: true,
  statutory_or_appendix_iv_certified: false,
  production_ready: false,
  definition_version: null,
  reason: 'Operational draft.',
  ...overrides,
});

const manifest = (capabilities = [capability()]): ManagementReportCapabilityManifest => ({
  meta: {
    manifest_version: 'management-report-capabilities.v1',
    classification: 'operational_management_reporting',
    read_only: true,
    statutory_or_appendix_iv_certified: false,
    disclaimer: 'Không phải biểu mẫu BCTC, báo cáo thuế hoặc căn cứ TT99.',
  },
  capabilities,
});

function renderGate() {
  return render(
    <ManagementReportGate capabilityKey="accounts_payable_aging">
      <div>Dữ liệu báo cáo được bảo vệ</div>
    </ManagementReportGate>,
  );
}

describe('ManagementReportGate', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('does not render report content while capability verification is loading', () => {
    mockedCapabilities.mockReturnValue({ data: undefined, isLoading: true, isError: false } as ReturnType<typeof useManagementReportCapabilities>);

    renderGate();

    expect(screen.getByText('Đang xác minh phạm vi và quyền báo cáo vận hành...')).toBeInTheDocument();
    expect(screen.queryByText('Dữ liệu báo cáo được bảo vệ')).not.toBeInTheDocument();
  });

  it('fails closed when the manifest cannot be loaded', () => {
    mockedCapabilities.mockReturnValue({ data: undefined, isLoading: false, isError: true } as ReturnType<typeof useManagementReportCapabilities>);

    renderGate();

    expect(screen.getByText('Không thể xác minh quyền báo cáo vận hành')).toBeInTheDocument();
    expect(screen.queryByText('Dữ liệu báo cáo được bảo vệ')).not.toBeInTheDocument();
  });

  it('fails closed when the requested capability is missing', () => {
    mockedCapabilities.mockReturnValue({ data: manifest([]), isLoading: false, isError: false } as ReturnType<typeof useManagementReportCapabilities>);

    renderGate();

    expect(screen.getByText('Chưa có nguồn dữ liệu báo cáo')).toBeInTheDocument();
    expect(screen.queryByText(/Không phải biểu mẫu BCTC|TT99|Phụ lục IV/)).not.toBeInTheDocument();
    expect(screen.queryByText('Dữ liệu báo cáo được bảo vệ')).not.toBeInTheDocument();
  });

  it.each([
    ['unavailable', capability({ available: false })],
    ['unauthorized', capability({ authorized_for_current_actor: false })],
  ])('does not render content when the capability is %s', (_caseName, blockedCapability) => {
    mockedCapabilities.mockReturnValue({ data: manifest([blockedCapability]), isLoading: false, isError: false } as ReturnType<typeof useManagementReportCapabilities>);

    renderGate();

    expect(screen.getByText(/Chưa có nguồn dữ liệu báo cáo|Bạn không có quyền mở báo cáo vận hành này/)).toBeInTheDocument();
    expect(screen.getByText('purchase.reports.view')).toBeInTheDocument();
    expect(screen.queryByText('Dữ liệu báo cáo được bảo vệ')).not.toBeInTheDocument();
  });

  it('renders children only for an authorized capability without a decorative context note', () => {
    mockedCapabilities.mockReturnValue({ data: manifest(), isLoading: false, isError: false } as ReturnType<typeof useManagementReportCapabilities>);

    renderGate();

    expect(screen.queryByText(/báo cáo nội bộ, chỉ đọc/)).not.toBeInTheDocument();
    expect(screen.queryByText('operational_draft')).not.toBeInTheDocument();
    expect(screen.queryByText(/chưa công bố definition version/)).not.toBeInTheDocument();
    expect(screen.queryByText(/không tự chuyển sang v2/)).not.toBeInTheDocument();
    expect(screen.getByText('Dữ liệu báo cáo được bảo vệ')).toBeInTheDocument();
  });

  it('shows the server-published definition version without claiming certification', () => {
    mockedCapabilities.mockReturnValue({
      data: manifest([capability({ definition_version: 'ap-aging-2026.1' })]),
      isLoading: false,
      isError: false,
    } as ReturnType<typeof useManagementReportCapabilities>);

    renderGate();

    expect(screen.queryByText(/báo cáo nội bộ, chỉ đọc/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Không phải biểu mẫu BCTC/)).not.toBeInTheDocument();
  });

  it('does not add a decorative context note while preserving the capability gate', () => {
    mockedCapabilities.mockReturnValue({ data: manifest(), isLoading: false, isError: false } as ReturnType<typeof useManagementReportCapabilities>);

    renderGate();

    expect(screen.queryByText(/báo cáo nội bộ, chỉ đọc/)).not.toBeInTheDocument();
    expect(screen.getByText('Dữ liệu báo cáo được bảo vệ')).toBeInTheDocument();
  });
});
