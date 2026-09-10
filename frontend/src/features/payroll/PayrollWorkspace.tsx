import React, { useState, useMemo } from 'react';
import { toast as message } from '../../components/feedback/toast';
import { 
  TeamOutlined, 
  UserOutlined, 
  FileTextOutlined,
  DollarOutlined,
  SettingOutlined
} from '@ant-design/icons';
import { MisaWorkspaceLayout, QuickAddEmployeeModal, useFastWorkspaceTabs } from '../../components/misa';
import PayrollVouchers from './PayrollVouchers';
import PayrollList from './PayrollList';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';

const TABS = [
  { key: 'tab-process', label: 'Quy trình' },
  { key: 'tab-vouchers', label: 'Chứng từ trả lương' },
  { key: 'tab-list', label: 'Bảng tính lương' },
  { key: 'tab-advances', label: 'Tạm ứng lương' },
  { key: 'tab-insurance', label: 'Bảo hiểm & Thuế' },
  { key: 'tab-reports', label: 'Báo cáo tiền lương' },
];

const TAB_KEYS = TABS.map(t => t.key);

export const PayrollWorkspace: React.FC = () => {
  const { activeTabKey, handleTabChange, isTabMounted } = useFastWorkspaceTabs(TAB_KEYS, 'tab-process');
  const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);

  const processFlowContent = useMemo(() => (
    <div className="misa-ca-process-container">
      {/* Main Process Diagram Canvas */}
      <div className="misa-ca-process-main">
        <div className="misa-ca-canvas">
          {/* Top Row: Chấm công / Bảng lương -> Hạch toán lương -> Trả lương */}
          <div className="misa-ca-canvas-row">
            {/* Node 1: Bảng tính lương */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-list')}>
              <div className="misa-ca-node-badge">TÍNH LƯƠNG</div>
              <div className="misa-ca-node-label">Bảng tính lương hàng tháng</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Bảng lương & phụ cấp</span>
              </div>
            </div>

            {/* Arrow: Bảng lương -> Hạch toán */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 2: Hạch toán chi phí lương */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-vouchers')}>
              <div className="misa-ca-node-badge">HẠCH TOÁN</div>
              <div className="misa-ca-node-label">Hạch toán chi phí lương & BH</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">TK 334 / TK 642 / TK 338</span>
              </div>
            </div>

            {/* Arrow: Hạch toán -> Trả lương */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 3: Trả lương */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-vouchers')}>
              <div className="misa-ca-node-badge">CHI TRẢ</div>
              <div className="misa-ca-node-label">Chi trả lương qua NH / Tiền mặt</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">UNC lương / Phiếu chi</span>
              </div>
            </div>
          </div>
        </div>

        {/* Bottom Master Data & Category Bar */}
        <div className="misa-ca-bottom-bar">
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsEmployeeModalVisible(true)}
          >
            <UserOutlined /> Hồ sơ nhân viên
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            disabled
            title="Cơ cấu phòng ban chưa khả dụng"
          >
            <TeamOutlined /> Phòng ban, bộ phận (chưa khả dụng)
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            disabled
            title="Quy định ngạch lương chưa khả dụng"
          >
            <DollarOutlined /> Bảng mức lương đóng BH (chưa khả dụng)
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            disabled
            title="Tùy chọn Tiền lương chưa khả dụng"
          >
            <SettingOutlined /> Tùy chọn (chưa khả dụng)
          </button>
        </div>
      </div>

      {/* Right Sidebar: Reports Panel */}
      <div className="misa-ca-sidebar">
        <div className="misa-ca-report-card">
          <div className="misa-ca-report-title">
            <FileTextOutlined className="misa-color-primary" /> BÁO CÁO LƯƠNG
          </div>
          <ul className="misa-ca-report-list">
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Bảng tổng hợp thanh toán tiền lương</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Báo cáo trích nộp bảo hiểm (BHXH, BHYT, BHTN)</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Báo cáo quyết toán thuế TNCN tạm tính</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Bảng phân bổ chi phí lương theo bộ phận</span>
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
      className="payroll-workspace-shell"
      title={<PageHeader eyebrow="Tiền lương" title="Quản lý tiền lương" description="Tính lương, hạch toán chi phí và theo dõi các khoản phải trả." />}
      toolbar={<PageToolbar />}
    >
    <MisaWorkspaceLayout
      tabs={TABS}
      activeTabKey={activeTabKey}
      onTabChange={handleTabChange}
    >
      {/* Tab 1 – Quy trình */}
      <div className={activeTabKey === 'tab-process' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {processFlowContent}
      </div>

      {/* Tab 2 – Chứng từ trả lương */}
      <div className={activeTabKey === 'tab-vouchers' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-vouchers') && <PayrollVouchers />}
      </div>

      {/* Tab 3 – Bảng tính lương */}
      <div className={activeTabKey === 'tab-list' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-list') && <PayrollList />}
      </div>

      {/* Tab 4 – Tạm ứng lương */}
      <div className={activeTabKey === 'tab-advances' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-advances') && (
          <div className="misa-p-16 misa-color-muted">Chức năng Theo dõi tạm ứng lương nhân viên...</div>
        )}
      </div>

      {/* Tab 5 – Bảo hiểm & Thuế */}
      <div className={activeTabKey === 'tab-insurance' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-insurance') && (
          <div className="misa-p-16 misa-color-muted">Chức năng Trích nộp BHXH, BHYT, BHTN và Thuế TNCN...</div>
        )}
      </div>

      {/* Tab 6 – Báo cáo */}
      <div className={activeTabKey === 'tab-reports' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-reports') && (
          <div className="misa-p-16 misa-color-muted">Bảng thanh toán tiền lương và phân bổ chi phí...</div>
        )}
      </div>

      <QuickAddEmployeeModal
        open={isEmployeeModalVisible}
        onCancel={() => setIsEmployeeModalVisible(false)}
        onSuccess={() => {
          setIsEmployeeModalVisible(false);
          message.success('Thêm nhân viên thành công');
        }}
      />
    </MisaWorkspaceLayout>
    </PageShell>
  );
};

export default PayrollWorkspace;
