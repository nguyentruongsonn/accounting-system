import React, { useState, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { Dropdown } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import type { MenuProps } from 'antd';
import { 
  BulbOutlined, 
  SettingOutlined, 
  BankOutlined, 
  UserOutlined, 
  TeamOutlined, 
  ShopOutlined, 
  FileTextOutlined,
  DownOutlined
} from '@ant-design/icons';
import { BankTransactions } from './BankTransactions';
import { InternalTransfers } from './InternalTransfers';
import { CashForecast } from '../cash/CashForecast';
import BorrowingContracts from './BorrowingContracts';
import { LendingContracts } from './LendingContracts';
import BankReports from './BankReports';
import { BankReceipts } from './BankReceipts';
import { BankPayments } from './BankPayments';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import { QuickAddContactModal, QuickAddEmployeeModal, useFastWorkspaceTabs } from '../../components/misa';

const TABS = [
  { key: 'tab-process', label: 'Quy trình' },
  { key: 'tab-transactions', label: 'Thu, chi tiền gửi' },
  { key: 'tab-transfers', label: 'Chuyển tiền nội bộ' },
  { key: 'tab-forecast', label: 'Dự báo dòng tiền' },
  { key: 'tab-borrowing', label: 'Khế ước đi vay' },
  { key: 'tab-lending', label: 'Khế ước cho vay' },
  { key: 'tab-reports', label: 'Báo cáo' },
];

const TAB_KEYS = TABS.map(t => t.key);

export const BankWorkspace: React.FC = () => {
  const navigate = useNavigate();
  const { activeTabKey, handleTabChange, isTabMounted } = useFastWorkspaceTabs(TAB_KEYS, 'tab-process');

  // Modals for Quick Add Master Data
  const [isCustomerModalVisible, setIsCustomerModalVisible] = useState(false);
  const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
  const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);

  // Subaction menus for Receipt
  const receiptMenuItems: MenuProps['items'] = useMemo(() => [
    {
      key: 'rcpt-1',
      label: 'Thu tiền khách hàng (Báo Có)',
      onClick: () => window.dispatchEvent(new Event('open-bank-receipt'))
    },
    {
      key: 'rcpt-2',
      label: 'Thu hoàn ứng nhân viên qua TK',
      onClick: () => window.dispatchEvent(new Event('open-bank-receipt'))
    },
    {
      key: 'rcpt-3',
      label: 'Nộp tiền mặt vào tài khoản ngân hàng',
      onClick: () => window.dispatchEvent(new Event('open-bank-receipt'))
    },
    {
      key: 'rcpt-4',
      label: 'Thu hoàn vốn / lãi tiền gửi',
      onClick: () => window.dispatchEvent(new Event('open-bank-receipt'))
    },
    {
      key: 'rcpt-5',
      label: 'Thu tiền gửi khác',
      onClick: () => window.dispatchEvent(new Event('open-bank-receipt'))
    }
  ], []);

  // Subaction menus for Payment (UNC)
  const paymentMenuItems: MenuProps['items'] = useMemo(() => [
    {
      key: 'pmt-1',
      label: 'Ủy nhiệm chi trả nợ nhà cung cấp',
      onClick: () => window.dispatchEvent(new Event('open-bank-payment'))
    },
    {
      key: 'pmt-2',
      label: 'Tạm ứng cho nhân viên qua tài khoản',
      onClick: () => window.dispatchEvent(new Event('open-bank-payment'))
    },
    {
      key: 'pmt-3',
      label: 'Nộp thuế và ngân sách nhà nước qua NH',
      onClick: () => window.dispatchEvent(new Event('open-bank-payment'))
    },
    {
      key: 'pmt-4',
      label: 'Chi trả lãi vay / nợ gốc khế ước',
      onClick: () => window.dispatchEvent(new Event('open-bank-payment'))
    },
    {
      key: 'pmt-5',
      label: 'Ủy nhiệm chi khác',
      onClick: () => window.dispatchEvent(new Event('open-bank-payment'))
    }
  ], []);

  const processFlowContent = useMemo(() => (
    <div className="misa-ca-process-container">
      {/* Main Process Diagram Canvas */}
      <div className="misa-ca-process-main">
        <div className="misa-ca-canvas">
          {/* Top Row: Thu tiền gửi -> Chuyển tiền nội bộ -> Đối chiếu NH */}
          <div className="misa-ca-canvas-row">
            {/* Node 1: Thu tiền gửi (Báo Có) */}
            <div className="misa-ca-node" onClick={() => window.dispatchEvent(new Event('open-bank-receipt'))}>
              <div className="misa-ca-node-badge">THU TIỀN</div>
              <div className="misa-ca-node-label">Thu tiền gửi (Báo Có)</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: receiptMenuItems }} placement="bottom">
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tiện ích thu <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            {/* Arrow: Thu -> Chuyển nội bộ */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 2: Chuyển tiền nội bộ */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-transfers')}>
              <div className="misa-ca-node-badge">CHUYỂN KHOẢN</div>
              <div className="misa-ca-node-label">Chuyển tiền nội bộ</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Lập lệnh chuyển tiền</span>
              </div>
            </div>

            {/* Arrow: Chuyển nội bộ -> Đối chiếu NH */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 3: Đối chiếu NH */}
            <div className="misa-ca-node" onClick={() => navigate('/bank/reconciliation')}>
              <div className="misa-ca-node-badge">ĐỐI CHIẾU</div>
              <div className="misa-ca-node-label">Đối chiếu ngân hàng</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Sổ phụ & Báo Nợ/Có</span>
              </div>
            </div>
          </div>

          {/* Bottom Row: Đề nghị chi -> Ủy nhiệm chi -> Khế ước đi vay */}
          <div className="misa-ca-canvas-row">
            {/* Node 4: MISA exposes payment requests; this project has no bank-request contract. */}
            <div
              className="misa-ca-node misa-ca-node-disabled"
              aria-disabled="true"
              title="Đề nghị chi tiền chưa khả dụng: backend chưa công bố workflow ngân hàng"
            >
              <div className="misa-ca-node-badge">ĐỀ NGHỊ</div>
              <div className="misa-ca-node-label">Đề nghị chi tiền (chưa khả dụng)</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Backend chưa công bố workflow ngân hàng</span>
              </div>
            </div>

            {/* Arrow: Đề nghị -> Ủy nhiệm chi */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 5: Ủy nhiệm chi (UNC) */}
            <div className="misa-ca-node" onClick={() => window.dispatchEvent(new Event('open-bank-payment'))}>
              <div className="misa-ca-node-badge">CHI TIỀN</div>
              <div className="misa-ca-node-label">Ủy nhiệm chi (UNC)</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: paymentMenuItems }} placement="bottom">
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tiện ích chi <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            {/* Arrow: UNC -> Khế ước vay */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 6: Khế ước đi vay */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-borrowing')}>
              <div className="misa-ca-node-badge">KHẾ ƯỚC</div>
              <div className="misa-ca-node-label">Khế ước đi vay</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Hạn mức & Lãi suất</span>
              </div>
            </div>
          </div>
        </div>

        {/* Bottom Master Data & Category Bar */}
        <div className="misa-ca-bottom-bar">
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => handleTabChange('tab-reports')}
          >
            <BankOutlined /> Tài khoản ngân hàng
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsCustomerModalVisible(true)}
          >
            <UserOutlined /> Khách hàng
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsSupplierModalVisible(true)}
          >
            <ShopOutlined /> Nhà cung cấp
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsEmployeeModalVisible(true)}
          >
            <TeamOutlined /> Nhân viên
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => message.info('Mở tùy chọn phân hệ tiền gửi')}
          >
            <SettingOutlined /> Tùy chọn
          </button>
        </div>
      </div>

      {/* Right Sidebar: Reports Panel */}
      <div className="misa-ca-sidebar">
        <div className="misa-ca-report-card">
          <div className="misa-ca-report-title">
            <FileTextOutlined className="misa-color-blue" /> BÁO CÁO TIỀN GỬI
          </div>
          <ul className="misa-ca-report-list">
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>S04a-DNN: Sổ tiền gửi ngân hàng</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Bảng kê số dư theo tài khoản NH</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-forecast')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Dự báo dòng tiền ngân hàng</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-borrowing')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Theo dõi khế ước vay & lãi suất</span>
            </li>
          </ul>
          <span className="misa-ca-all-reports-link" onClick={() => handleTabChange('tab-reports')}>
            Tất cả báo cáo
          </span>
        </div>
      </div>
    </div>
  ), [receiptMenuItems, paymentMenuItems, handleTabChange, navigate]);

  return (
    <PageShell
      className="misa-workspace-shell"
      title={<PageHeader eyebrow="TIỀN GỬI" title="Quy trình tiền gửi" description="Từ thu, chi tiền gửi đến chuyển tiền nội bộ và đối chiếu ngân hàng." />}
    >
      <div className="ui-workspace flex flex-col flex-1" data-ui="workspace">
        {/* Native MISA Fast Tab Bar */}
        <div className="misa-workspace-tab-nav ui-workspace-tabs" data-ui="workspace-tabs">
          <div className="misa-workspace-tab-list">
            {TABS.map((tab) => (
              <button
                key={tab.key}
                type="button"
                className={`misa-workspace-tab-item ${activeTabKey === tab.key ? 'active' : ''}`}
                onClick={() => handleTabChange(tab.key)}
              >
                {tab.label}
              </button>
            ))}
          </div>
          <div className="misa-workspace-tab-extra">
            <button 
              type="button" 
              className="apple-help-button" 
              title="Hướng dẫn phân hệ Tiền gửi"
              onClick={() => message.info('Hướng dẫn quy trình nghiệp vụ Tiền gửi ngân hàng')}
            >
              <BulbOutlined className="misa-fs-13" />
            </button>
            <button 
              type="button" 
              className="misa-btn-tool-transparent" 
              title="Cài đặt phân hệ"
              onClick={() => message.info('Mở tùy chọn cấu hình Tiền gửi')}
            >
              <SettingOutlined className="misa-fs-16" />
            </button>
          </div>
        </div>

        {/* Tab Panels: Lazy-mount + instant CSS switch */}
        <div className="misa-tab-panel-container ui-workspace-body" data-ui="workspace-body">
          {/* Tab 1 – Quy trình */}
          <div className={activeTabKey === 'tab-process' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {processFlowContent}
          </div>

          {/* Tab 2 – Thu, chi tiền gửi */}
          <div className={activeTabKey === 'tab-transactions' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-transactions') && <BankTransactions active={activeTabKey === 'tab-transactions'} />}
          </div>

          {/* Tab 3 – Chuyển tiền nội bộ */}
          <div className={activeTabKey === 'tab-transfers' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-transfers') && <InternalTransfers />}
          </div>

          {/* Tab 4 – Dự báo dòng tiền */}
          <div className={activeTabKey === 'tab-forecast' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-forecast') && <CashForecast />}
          </div>

          {/* Tab 5 – Khế ước đi vay */}
          <div className={activeTabKey === 'tab-borrowing' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-borrowing') && <BorrowingContracts />}
          </div>

          {/* Tab 6 – Khế ước cho vay */}
          <div className={activeTabKey === 'tab-lending' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-lending') && <LendingContracts />}
          </div>

          {/* Tab 7 – Báo cáo */}
          <div className={activeTabKey === 'tab-reports' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-reports') && <BankReports />}
          </div>
        </div>
      </div>

      {/* Global Modals for Bank Receipts & Payments */}
      <BankReceipts />
      <BankPayments />

      {/* Quick Add Master Data Modals */}
      <QuickAddContactModal
        open={isCustomerModalVisible}
        contactType="customer"
        onCancel={() => setIsCustomerModalVisible(false)}
        onSuccess={() => {
          setIsCustomerModalVisible(false);
          message.success('Thêm khách hàng thành công');
        }}
      />

      <QuickAddContactModal
        open={isSupplierModalVisible}
        contactType="supplier"
        onCancel={() => setIsSupplierModalVisible(false)}
        onSuccess={() => {
          setIsSupplierModalVisible(false);
          message.success('Thêm nhà cung cấp thành công');
        }}
      />

      <QuickAddEmployeeModal
        open={isEmployeeModalVisible}
        onCancel={() => setIsEmployeeModalVisible(false)}
        onSuccess={() => {
          setIsEmployeeModalVisible(false);
          message.success('Thêm nhân viên thành công');
        }}
      />
    </PageShell>
  );
};

export default BankWorkspace;
