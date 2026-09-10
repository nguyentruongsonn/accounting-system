import React, { useState, useMemo } from 'react';
import { Button } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { 
  AppstoreAddOutlined, 
  SettingOutlined, 
  FileTextOutlined,
  UserOutlined,
  ShopOutlined
} from '@ant-design/icons';
import { MisaWorkspaceLayout, QuickAddContactModal, useFastWorkspaceTabs } from '../../components/misa';
import { ErrorBoundary } from '../../components/common/ErrorBoundary';
import FixedAssetRegistrations from './FixedAssetRegistrations';
import FixedAssetDepreciations from './FixedAssetDepreciations';
import FixedAssetRevaluations from './FixedAssetRevaluations';
import FixedAssetDisposals from './FixedAssetDisposals';
import FixedAssetReports from './FixedAssetReports';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';

const TABS = [
  { key: 'tab-process', label: 'Quy trình' },
  { key: 'tab-registrations', label: 'Ghi tăng' },
  { key: 'tab-depreciation', label: 'Tính khấu hao' },
  { key: 'tab-revaluation', label: 'Đánh giá lại' },
  { key: 'tab-reduction', label: 'Ghi giảm' },
  { key: 'tab-reports', label: 'Báo cáo TSCĐ' },
];

const TAB_KEYS = TABS.map(t => t.key);

export const FixedAssetWorkspace: React.FC = () => {
  const { activeTabKey, handleTabChange, isTabMounted } = useFastWorkspaceTabs(TAB_KEYS, 'tab-process');
  const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
  const activeTabLabel = TABS.find(tab => tab.key === activeTabKey)?.label ?? 'Quy trình';
  const activeTabAction = activeTabKey === 'tab-process'
    ? 'Điều hướng quy trình'
    : activeTabKey === 'tab-registrations'
      ? 'Thêm hồ sơ ghi tăng'
      : activeTabKey === 'tab-depreciation'
        ? 'Tính khấu hao'
        : activeTabKey === 'tab-revaluation'
          ? 'Lập chứng từ đánh giá lại'
          : activeTabKey === 'tab-reduction'
            ? 'Lập chứng từ ghi giảm'
            : 'Xem phạm vi báo cáo';

  const processFlowContent = useMemo(() => (
    <div className="misa-ca-process-container">
      {/* Main Process Diagram Canvas */}
      <div className="misa-ca-process-main">
        <div className="misa-ca-canvas">
          {/* Top Row: Ghi tăng -> Khấu hao -> Ghi giảm */}
          <div className="misa-ca-canvas-row">
            {/* Node 1: Ghi tăng */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-registrations')}>
              <div className="misa-ca-node-badge">GHI TĂNG</div>
              <div className="misa-ca-node-label">Ghi tăng tài sản cố định</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Hồ sơ tài sản</span>
              </div>
            </div>

            {/* Arrow: Tăng -> Khấu hao */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 2: Tính khấu hao */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-depreciation')}>
              <div className="misa-ca-node-badge">KHẤU HAO</div>
              <div className="misa-ca-node-label">Tính khấu hao TSCĐ định kỳ</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Bảng tính khấu hao</span>
              </div>
            </div>

            {/* Arrow: Khấu hao -> Ghi giảm */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 3: Ghi giảm */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-reduction')}>
              <div className="misa-ca-node-badge">GHI GIẢM</div>
              <div className="misa-ca-node-label">Thanh lý / Ghi giảm TSCĐ</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Biên bản thanh lý</span>
              </div>
            </div>
          </div>
        </div>

        {/* Bottom Master Data & Category Bar */}
        <div className="misa-ca-bottom-bar">
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => handleTabChange('tab-registrations')}
          >
            <AppstoreAddOutlined /> Danh mục loại TSCĐ
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsSupplierModalVisible(true)}
          >
            <ShopOutlined /> Nhà cung cấp TSCĐ
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => handleTabChange('tab-revaluation')}
          >
            <UserOutlined /> Đánh giá lại TSCĐ
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => message.info('Tùy chọn cấu hình phân hệ TSCĐ')}
          >
            <SettingOutlined /> Tùy chọn
          </button>
        </div>
      </div>

      {/* Right Sidebar: Reports Panel */}
      <div className="misa-ca-sidebar">
        <div className="misa-ca-report-card">
          <div className="misa-ca-report-title">
            <FileTextOutlined className="misa-color-primary" /> BÁO CÁO TÀI SẢN
          </div>
          <ul className="misa-ca-report-list">
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Sổ tài sản cố định</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-depreciation')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Bảng trích khấu hao TSCĐ theo kỳ</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Báo cáo tổng hợp tăng giảm TSCĐ</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Biên bản kiểm kê và đối soát TSCĐ</span>
            </li>
          </ul>
          <span className="misa-ca-all-reports-link" onClick={() => handleTabChange('tab-reports')}>
            Tất cả báo cáo
          </span>
        </div>
      </div>
    </div>
  ), [handleTabChange]);

  return (
    <PageShell
      className="misa-workspace-shell"
      title={<PageHeader eyebrow="Tài sản cố định" title="Quản lý tài sản cố định" description="Ghi tăng, khấu hao, đánh giá lại và thanh lý tài sản." />}
      toolbar={<PageToolbar
        className={activeTabKey === 'tab-process' ? 'ui-page-toolbar--hidden' : undefined}
        leading={<span className="ui-page-toolbar__context">{activeTabLabel}</span>}
        actions={<Button type="link" onClick={() => message.info(`${activeTabLabel}: ${activeTabAction}`)}>{activeTabAction}</Button>}
      />}
    >
    <MisaWorkspaceLayout
      tabs={TABS}
      activeTabKey={activeTabKey}
      onTabChange={handleTabChange}
    >
      {/* Tab 1 – Quy trình */}
      <div className={activeTabKey === 'tab-process' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        <ErrorBoundary fallback={<div className="p-6 text-red-500 font-semibold">Lỗi khi tải Quy trình TSCĐ</div>}>
          {processFlowContent}
        </ErrorBoundary>
      </div>

      {/* Tab 2 – Ghi tăng */}
      <div className={activeTabKey === 'tab-registrations' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-registrations') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500 font-semibold">Lỗi khi tải Ghi tăng TSCĐ</div>}>
             <FixedAssetRegistrations embedded />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 3 – Tính khấu hao */}
      <div className={activeTabKey === 'tab-depreciation' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-depreciation') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500 font-semibold">Lỗi khi tải Bảng tính khấu hao TSCĐ</div>}>
             <FixedAssetDepreciations embedded />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 4 – Đánh giá lại */}
      <div className={activeTabKey === 'tab-revaluation' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-revaluation') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500 font-semibold">Lỗi khi tải Đánh giá lại TSCĐ</div>}>
             <FixedAssetRevaluations embedded />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 5 – Ghi giảm */}
      <div className={activeTabKey === 'tab-reduction' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-reduction') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500 font-semibold">Lỗi khi tải Ghi giảm / Thanh lý TSCĐ</div>}>
             <FixedAssetDisposals embedded />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 6 – Báo cáo */}
      <div className={activeTabKey === 'tab-reports' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-reports') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500 font-semibold">Lỗi khi tải Báo cáo TSCĐ</div>}>
             <FixedAssetReports embedded />
          </ErrorBoundary>
        )}
      </div>

      <QuickAddContactModal
        open={isSupplierModalVisible}
        contactType="supplier"
        onCancel={() => setIsSupplierModalVisible(false)}
        onSuccess={() => {
          setIsSupplierModalVisible(false);
          message.success('Thêm nhà cung cấp thành công');
        }}
      />
    </MisaWorkspaceLayout>
    </PageShell>
  );
};

export default FixedAssetWorkspace;
