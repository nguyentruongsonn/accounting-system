import React, { useEffect, useMemo, useState } from 'react';
import { Button, Input, Select, Space } from 'antd';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { 
  BookOutlined, 
  FileTextOutlined,
  LockOutlined,
  PlusOutlined,
  ReloadOutlined,
  SearchOutlined,
} from '@ant-design/icons';
import { MisaWorkspaceLayout, useFastWorkspaceTabs } from '../../components/misa';
import GeneralJournals from './GeneralJournals';
import ClosingEntries from './ClosingEntries';
import PeriodLock from './PeriodLock';
import { displayInternalReportLabel, resolveInternalReportRoute, type ReportCapability, useReportCapabilities } from '../reports/reportCapabilities';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

const TABS = [
  { key: 'tab-process', label: 'Quy trình' },
  { key: 'tab-journals', label: 'Chứng từ NV khác' },
  { key: 'tab-closing', label: 'Kết chuyển lãi lỗ (911)' },
  { key: 'tab-lock', label: 'Khóa sổ kỳ kế toán' },
];

const TAB_KEYS = TABS.map(t => t.key);
const EMPTY_REPORT_CAPABILITIES: readonly ReportCapability[] = [];

export const GLWorkspace: React.FC = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { activeTabKey, handleTabChange, isTabMounted } = useFastWorkspaceTabs(TAB_KEYS, 'tab-process');
  const [period, setPeriod] = useState<string>('year');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [searchText, setSearchText] = useState<string>('');

  const handleFilterChange = (updates: { period?: string; statusFilter?: string; searchText?: string }) => {
    const nextPeriod = updates.period ?? period;
    const nextStatus = updates.statusFilter ?? statusFilter;
    const nextSearch = updates.searchText ?? searchText;
    if (updates.period !== undefined) setPeriod(updates.period);
    if (updates.statusFilter !== undefined) setStatusFilter(updates.statusFilter);
    if (updates.searchText !== undefined) setSearchText(updates.searchText);
    window.dispatchEvent(new CustomEvent('gl-filter-change', {
      detail: { period: nextPeriod, statusFilter: nextStatus, searchText: nextSearch }
    }));
  };

  const requestedTab = searchParams.get('tab');
  const requestedTabKey = TAB_KEYS.includes(requestedTab as typeof TAB_KEYS[number])
    ? requestedTab as typeof TAB_KEYS[number]
    : undefined;
  useEffect(() => {
    if (requestedTabKey) handleTabChange(requestedTabKey);
  }, [handleTabChange, requestedTabKey]);
  const {
    data: reportManifest,
    isLoading: isReportCapabilitiesLoading,
    isError: isReportCapabilitiesError,
    refetch: refetchReportCapabilities,
  } = useReportCapabilities();
  const reportCapabilities = reportManifest?.capabilities ?? EMPTY_REPORT_CAPABILITIES;
  const availableReportCapabilities = useMemo(
    () => reportCapabilities.filter((capability) => capability.available && capability.route),
    [reportCapabilities],
  );

  const processFlowContent = useMemo(() => (
    <div className="misa-ca-process-container">
      {/* Main Process Diagram Canvas */}
      <div className="misa-ca-process-main">
        <div className="misa-ca-canvas">
          {/* Top Row: Chứng từ khác -> Kết chuyển 911 */}
          <div className="misa-ca-canvas-row">
            {/* Node 1: Chứng từ nghiệp vụ khác */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-journals')}>
              <div className="misa-ca-node-badge">CHỨNG TỪ</div>
              <div className="misa-ca-node-label">Chứng từ nghiệp vụ khác</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Hạch toán tổng hợp</span>
              </div>
            </div>

            {/* Arrow: CT -> Kết chuyển */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 2: Kết chuyển lãi lỗ */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-closing')}>
              <div className="misa-ca-node-badge">KẾT CHUYỂN</div>
              <div className="misa-ca-node-label">Kết chuyển lãi lỗ (TK 911)</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Xác định kết quả KD</span>
              </div>
            </div>
          </div>

          {/* Bottom Row: Khóa sổ */}
          <div className="misa-ca-canvas-row">
            <div className="misa-ca-spacer-140" />
            <div className="misa-ca-spacer-60" />
            <div className="misa-ca-spacer-140" />
            <div className="misa-ca-spacer-60" />

            {/* Node 4: Khóa sổ */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-lock')}>
              <div className="misa-ca-node-badge">KHÓA SỔ</div>
              <div className="misa-ca-node-label">Khóa sổ kỳ kế toán</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Đóng kỳ & Chống sửa</span>
              </div>
            </div>
          </div>
        </div>

        {/* Bottom Master Data & Category Bar */}
        <div className="misa-ca-bottom-bar">
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => handleTabChange('tab-journals')}
          >
            <BookOutlined /> Hệ thống tài khoản (COA)
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => handleTabChange('tab-lock')}
          >
            <LockOutlined /> Quản lý kỳ kế toán
          </button>
        </div>
      </div>

      {/* Right Sidebar: Reports Panel */}
      <div className="misa-ca-sidebar">
        <div className="misa-ca-report-card">
          <div className="misa-ca-report-title">
            <FileTextOutlined className="misa-color-primary" /> BÁO CÁO KẾ TOÁN
          </div>
          <ul className="misa-ca-report-list">
            {isReportCapabilitiesLoading && (
              <li className="misa-ca-report-item misa-color-muted">Đang xác minh phạm vi báo cáo...</li>
            )}
            {isReportCapabilitiesError && (
              <li className="misa-ca-report-item misa-color-muted">
                <span>Không tải được danh sách báo cáo.</span>
                <Button type="link" size="small" onClick={() => void refetchReportCapabilities()}>
                  Thử lại báo cáo
                </Button>
              </li>
            )}
            {!isReportCapabilitiesLoading && !isReportCapabilitiesError && availableReportCapabilities.map((capability) => (
              <li
                key={capability.key}
                className="misa-ca-report-item"
                onClick={() => {
                  const route = resolveInternalReportRoute(capability);
                  if (route) navigate(route);
                }}
              >
                <span className="misa-ca-report-bullet">•</span>
                <span>{displayInternalReportLabel(capability)}</span>
              </li>
            ))}
            {!isReportCapabilitiesLoading && !isReportCapabilitiesError && availableReportCapabilities.length === 0 && (
              <li className="misa-ca-report-item misa-color-muted">Chưa có báo cáo vận hành khả dụng.</li>
            )}
          </ul>
          <span className="misa-ca-all-reports-link" onClick={() => navigate('/reports')}>
            Tất cả báo cáo
          </span>
          <div className="misa-fs-11 misa-color-muted misa-mt-6">
            {isReportCapabilitiesError
              ? 'Không xác minh được capability từ máy chủ; danh sách được fail-closed.'
              : 'Báo cáo vận hành lấy dữ liệu trực tiếp từ máy chủ.'}
          </div>
        </div>
      </div>
    </div>
  ), [availableReportCapabilities, handleTabChange, isReportCapabilitiesError, isReportCapabilitiesLoading, navigate, refetchReportCapabilities]);

  return (
    <PageShell
      className="misa-workspace-shell"
       title={<PageHeader eyebrow="Tổng hợp" title="Kế toán tổng hợp" description="Hạch toán nghiệp vụ khác, kết chuyển lãi lỗ và khóa sổ kỳ kế toán. Đây là báo cáo nội bộ." />}
    >
    <MisaWorkspaceLayout
      tabs={TABS}
      activeTabKey={activeTabKey}
      onTabChange={handleTabChange}
      toolbar={<PageToolbar
        className={activeTabKey === 'tab-process' ? 'ui-page-toolbar--hidden' : undefined}
        filters={(
          <div className="flex items-center gap-2">
            <Select
              value={period}
              onChange={(val) => handleFilterChange({ period: val })}
              className="misa-w-140"
              options={[
                { value: 'all', label: 'Tất cả kỳ' },
                { value: 'year', label: 'Năm nay' },
                { value: 'quarter', label: 'Quý này' },
                { value: 'month', label: 'Tháng này' },
              ]}
            />
            <Select
              value={statusFilter}
              onChange={(val) => handleFilterChange({ statusFilter: val })}
              className="misa-w-140"
              options={[
                { value: 'all', label: 'Tất cả trạng thái' },
                { value: 'posted', label: 'Đã ghi sổ' },
                { value: 'draft', label: 'Chưa ghi sổ' },
              ]}
            />
            <Input
              placeholder="Tìm số chứng từ, diễn giải..."
              prefix={<SearchOutlined className="misa-color-muted" />}
              className="misa-w-240"
              value={searchText}
              onChange={(e) => handleFilterChange({ searchText: e.target.value })}
              allowClear
            />
            <Button
              icon={<ReloadOutlined />}
              onClick={() => void runManualDataLoad(
                () => window.dispatchEvent(new Event('refresh-general-journals')),
                { success: 'Tải lại sổ nhật ký chung thành công.', failure: 'Không thể tải lại sổ nhật ký chung.' },
              )}
              title="Nạp lại dữ liệu"
              className="misa-btn-tool"
            />
          </div>
        )}
        actions={activeTabKey === 'tab-journals' ? (
          <Space>
            <Button type="primary" icon={<PlusOutlined />} className="misa-btn-primary" onClick={() => window.dispatchEvent(new Event('open-general-journal'))}>
              Thêm chứng từ
            </Button>
          </Space>
        ) : undefined}
      />}
    >
      {/* Tab 1 – Quy trình */}
      <div className={activeTabKey === 'tab-process' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {processFlowContent}
      </div>

      {/* Tab 2 – Chứng từ NV khác */}
      <div className={activeTabKey === 'tab-journals' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-journals') && <GeneralJournals embedded />}
      </div>

      {/* Tab 3 – Kết chuyển 911 */}
      <div className={activeTabKey === 'tab-closing' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-closing') && <ClosingEntries embedded />}
      </div>

      {/* Tab 4 – Khóa sổ */}
      <div className={activeTabKey === 'tab-lock' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-lock') && <PeriodLock embedded />}
      </div>
    </MisaWorkspaceLayout>
    </PageShell>
  );
};

export default GLWorkspace;
