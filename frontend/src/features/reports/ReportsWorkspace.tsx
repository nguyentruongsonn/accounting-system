import React from 'react';
import { Button, Typography } from 'antd';
import { useNavigate } from 'react-router-dom';
import ModuleWorkspace from '../../components/ModuleWorkspace';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import TrialBalanceReport from './TrialBalanceReport';
import BalanceSheetReport from './BalanceSheetReport';
import IncomeStatementReport from './IncomeStatementReport';
import GeneralLedger from './GeneralLedger';
import GeneralJournal from './GeneralJournal';
import {
  type ReportCapability,
  displayInternalReportLabel,
  useReportCapabilities,
} from './reportCapabilities';

export type ReportWorkspaceKey = keyof typeof REPORT_COMPONENTS;

type ReportsWorkspaceProps = {
  /**
   * Used by compatibility routes such as /reports/trial-balance.  A legacy
   * URL is not allowed to bypass the server capability manifest.
   */
  requestedReportKey?: ReportWorkspaceKey;
};

const REPORT_ROUTES: Record<ReportWorkspaceKey, string> = {
  trial_balance: '/reports/trial-balance',
  balance_sheet: '/reports/balance-sheet',
  income_statement: '/reports/income-statement',
  general_ledger: '/reports/general-ledger',
  general_journal: '/reports/general-journal',
};

const ReportsWorkspace: React.FC<ReportsWorkspaceProps> = ({ requestedReportKey }) => {
  const navigate = useNavigate();
  const { data: manifest, isLoading, isError, refetch } = useReportCapabilities();

  if (isLoading) {
    return (
      <PageShell
        className="misa-workspace-shell"
        title={<PageHeader eyebrow="Báo cáo" title="Báo cáo kế toán" description="Số liệu được tổng hợp từ các chứng từ đã ghi sổ trong kỳ đã chọn." />}
      >
        <Typography.Text type="secondary">Đang tải danh sách báo cáo...</Typography.Text>
      </PageShell>
    );
  }

  const capabilities = manifest?.capabilities ?? FALLBACK_UNAVAILABLE_CAPABILITIES;
  const availableItems = capabilities
    .filter((capability) => capability.available)
    .flatMap((capability) => {
      const children = REPORT_COMPONENTS[capability.key];
      return children ? [{ key: capability.key, label: displayInternalReportLabel(capability), children }] : [];
    });
  // Keep the operational workspace focused on reports that can actually be
  // opened. Unsupported/statutory catalogue entries are not rendered as
  // disabled tabs; a requested deep-link still receives an explicit notice.
  const items = availableItems;
  const requestedCapability = requestedReportKey === undefined
    ? undefined
    : capabilities.find((capability) => capability.key === requestedReportKey);
  const canOpenRequestedReport = !isError && (requestedReportKey === undefined
    ? availableItems.length > 0
    : Boolean(requestedCapability?.available && REPORT_COMPONENTS[requestedReportKey]));
  const activeKey = requestedReportKey && canOpenRequestedReport
    ? requestedReportKey
    : availableItems[0]?.key;

  const requestedReportNotice = requestedReportKey !== undefined && !canOpenRequestedReport && (
    <div className="apple-report-inline-status">
      <Typography.Text strong>Liên kết báo cáo chưa thể mở ở chế độ kiểm soát</Typography.Text>
      <Typography.Text type="secondary">
        {requestedCapability
          ? `${requestedCapability.label}: ${requestedCapability.reason ?? 'Máy chủ không công bố báo cáo này là khả dụng.'}`
          : 'Không xác minh được capability từ máy chủ; giao diện không tự mở báo cáo bằng danh sách hard-code hoặc màn hình cũ.'}
      </Typography.Text>
      <Button size="small" onClick={() => navigate('/reports')}>Quay lại danh sách báo cáo</Button>
    </div>
  );

  return (
    <PageShell
      className="misa-workspace-shell"
      title={<PageHeader eyebrow="Báo cáo" title="Báo cáo kế toán" description="Số liệu được tổng hợp từ các chứng từ đã ghi sổ trong kỳ đã chọn." />}
    >
      {requestedReportNotice}
      {isError && <div className="apple-report-inline-status">
        <Typography.Text type="secondary">Không xác minh được danh sách báo cáo từ máy chủ. Không hiển thị danh sách thay thế.</Typography.Text>
        <Button size="small" onClick={() => void refetch()}>Thử lại danh sách báo cáo</Button>
      </div>}
      {!isError && availableItems.length === 0 && <Typography.Text type="secondary">Chưa có báo cáo vận hành khả dụng trong phạm vi hiện tại.</Typography.Text>}
      {canOpenRequestedReport && (
        <ModuleWorkspace
          nativeTabs
          items={items}
          defaultActiveKey={activeKey ?? ''}
          activeKey={activeKey}
          onChange={(key) => {
            const route = REPORT_ROUTES[key as ReportWorkspaceKey];
            navigate(route ?? '/reports');
          }}
        />
      )}
    </PageShell>
  );
};

export default ReportsWorkspace;

const REPORT_COMPONENTS: Record<string, React.ReactNode> = {
  // The workspace owns the page frame, tabs and scroll region. Reports keep
  // their own filters/actions, but must not mount a second PageShell inside a
  // tab, otherwise every report gets a different padding/header pattern.
  trial_balance: <TrialBalanceReport embedded />,
  balance_sheet: <BalanceSheetReport embedded />,
  income_statement: <IncomeStatementReport embedded />,
  general_ledger: <GeneralLedger embedded />,
  general_journal: <GeneralJournal embedded />,
};

const FALLBACK_UNAVAILABLE_CAPABILITIES: ReportCapability[] = [
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
    reason: 'Chưa triển khai; không có báo cáo hay công thức để xuất dữ liệu.',
  },
  {
    key: 'financial_statement_notes',
    label: 'Thuyết minh báo cáo tài chính',
    category: 'financial_statement',
    status: 'not_implemented',
    implementation_status: 'not_implemented',
    available: false,
    appendix_iv_certified: false,
    definition_version: null,
    route: null,
    reason: 'Chưa triển khai; không có catalogue chỉ tiêu hoặc quy trình phê duyệt.',
  },
];
