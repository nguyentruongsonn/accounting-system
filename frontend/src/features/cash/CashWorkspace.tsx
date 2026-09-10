import React, { useState, useMemo } from 'react';
import { Dropdown } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import type { MenuProps } from 'antd';
import {
  UserOutlined,
  TeamOutlined,
  ShopOutlined,
  FileTextOutlined,
  DownOutlined
} from '@ant-design/icons';
import { CashTransactions } from './CashTransactions';
import { CashPaymentRequests } from './CashPaymentRequests';
import { CashAdvanceSettlements } from './CashAdvanceSettlements';
import { CashForecast } from './CashForecast';
import { CashAudit } from './CashAudit';
import { CashReports } from './CashReports';
import { CashReceipts } from './CashReceipts';
import { CashPayments } from './CashPayments';

import {
  QuickAddContactModal,
  QuickAddEmployeeModal,
  useFastWorkspaceTabs,
  CollectByInvoiceModal,
  CollectMultiCustomerModal,
  PayByInvoiceModal,
  ExcelImportModal
} from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';

const TABS = [
  { key: 'tab-process', label: 'Quy trình' },
  { key: 'tab-transactions', label: 'Thu, chi tiền' },
  { key: 'tab-payment-requests', label: 'Đề nghị chi tiền' },
  { key: 'tab-advance-settlements', label: 'Đề nghị quyết toán tạm ứng' },
  { key: 'tab-forecast', label: 'Dự báo dòng tiền' },
  { key: 'tab-audit', label: 'Kiểm kê' },
  { key: 'tab-reports', label: 'Báo cáo' },
];

const TAB_KEYS = TABS.map(t => t.key);

export const CashWorkspace: React.FC = () => {
  const { activeTabKey, handleTabChange, isTabMounted } = useFastWorkspaceTabs(TAB_KEYS, 'tab-process');
  
  const [isReceiptModalOpen, setIsReceiptModalOpen] = useState(false);
  const [isPaymentModalOpen, setIsPaymentModalOpen] = useState(false);
  const [isCustomerModalVisible, setIsCustomerModalVisible] = useState(false);
  const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
  const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);

  // Extension Modals for "Thu tiền" & "Chi tiền" Dropdowns
  const [isCollectByInvoiceOpen, setIsCollectByInvoiceOpen] = useState(false);
  const [isCollectMultiCustomerOpen, setIsCollectMultiCustomerOpen] = useState(false);
  const [isPayByInvoiceOpen, setIsPayByInvoiceOpen] = useState(false);
  const [isExcelImportOpen, setIsExcelImportOpen] = useState(false);
  const [excelImportType, setExcelImportType] = useState<'receipt' | 'payment'>('receipt');

  const receiptMenuItems: MenuProps['items'] = useMemo(() => [
    {
      key: 'rcpt-menu-std',
      label: 'Phiếu thu',
      onClick: () => window.dispatchEvent(new Event('open-cash-receipt'))
    },
    {
      key: 'rcpt-menu-invoice',
      label: 'Thu tiền theo hóa đơn',
      onClick: () => setIsCollectByInvoiceOpen(true)
    },
    {
      key: 'rcpt-menu-multi-invoice',
      label: 'Thu tiền theo hóa đơn nhiều khách hàng',
      onClick: () => setIsCollectMultiCustomerOpen(true)
    },
    {
      type: 'divider'
    },
    {
      key: 'rcpt-menu-excel',
      label: 'Nhập từ excel',
      onClick: () => {
        setExcelImportType('receipt');
        setIsExcelImportOpen(true);
      }
    }
  ], []);

  const paymentMenuItems: MenuProps['items'] = useMemo(() => [
    {
      key: 'pmt-menu-std',
      label: 'Phiếu chi',
      onClick: () => window.dispatchEvent(new Event('open-cash-payment'))
    },
    {
      key: 'pmt-menu-invoice',
      label: 'Trả tiền theo hóa đơn',
      onClick: () => setIsPayByInvoiceOpen(true)
    },
    {
      type: 'divider'
    },
    {
      key: 'pmt-menu-excel',
      label: 'Nhập từ excel (chỉ chọn file cục bộ)',
      onClick: () => {
        setExcelImportType('payment');
        setIsExcelImportOpen(true);
      }
    }
  ], []);

  const processFlowContent = useMemo(() => (
    <div className="misa-ca-process-container">
      {/* Main Process Diagram Canvas */}
      <div className="misa-ca-process-main">
        <div className="misa-ca-canvas">
          {/* Top Row: Thu tiền -> Kiểm kê quỹ */}
          <div className="misa-ca-canvas-row">
            <div className="misa-ca-spacer-140" />
            <div className="misa-ca-spacer-60" />

            {/* Node 1: Thu tiền */}
            <div className="misa-ca-node" onClick={() => window.dispatchEvent(new Event('open-cash-receipt'))}>
              <div className="misa-ca-node-badge">THU QUỸ</div>
              <div className="misa-ca-node-label">Thu tiền mặt</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: receiptMenuItems }} placement="bottom" trigger={['hover']}>
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tiện ích thu <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            {/* Arrow Connector: Thu tiền -> Kiểm kê quỹ */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 2: Kiểm kê quỹ */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-audit')}>
              <div className="misa-ca-node-badge">KIỂM KÊ</div>
              <div className="misa-ca-node-label">Kiểm kê quỹ</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Xem biên bản</span>
              </div>
            </div>
          </div>

          {/* Bottom Row: Đề nghị chi tiền -> Chi tiền */}
          <div className="misa-ca-canvas-row">
            {/* Node 3: Đề nghị chi tiền */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-payment-requests')}>
              <div className="misa-ca-node-badge">ĐỀ NGHỊ</div>
              <div className="misa-ca-node-label">Đề nghị chi tiền</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link" onClick={(e) => { e.stopPropagation(); handleTabChange('tab-advance-settlements'); }}>
                  Tạm ứng & Quyết toán
                </span>
              </div>
            </div>

            {/* Arrow Connector: Đề nghị -> Chi tiền */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 4: Chi tiền */}
            <div className="misa-ca-node" onClick={() => window.dispatchEvent(new Event('open-cash-payment'))}>
              <div className="misa-ca-node-badge">CHI QUỸ</div>
              <div className="misa-ca-node-label">Chi tiền mặt</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: paymentMenuItems }} placement="bottom" trigger={['hover']}>
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tiện ích chi <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            {/* Spacers for symmetric canvas alignment */}
            <div className="misa-ca-spacer-60" />
            <div className="misa-ca-spacer-170" />
          </div>
        </div>

        {/* Bottom Master Data & Category Bar */}
        <div className="misa-ca-bottom-bar">
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
        </div>
      </div>

      {/* Right Sidebar: Reports Panel */}
      <div className="misa-ca-sidebar">
        <div className="misa-ca-report-card">
          <div className="misa-ca-report-title">
            <FileTextOutlined className="misa-color-primary" /> BÁO CÁO TIỀN MẶT
          </div>
          <ul className="misa-ca-report-list">
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Bảng kê số dư tiền theo ngày</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Dòng tiền</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>S03a1-DNN: Sổ nhật ký thu tiền</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Sổ kế toán chi tiết quỹ tiền mặt</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>S03a2-DNN: Sổ nhật ký chi tiền</span>
            </li>
          </ul>
          <span className="misa-ca-all-reports-link" onClick={() => handleTabChange('tab-reports')}>
            Tất cả báo cáo
          </span>
        </div>
      </div>
    </div>
  ), [receiptMenuItems, paymentMenuItems, handleTabChange]);

  return (
      <PageShell
        className="misa-workspace-shell"
       title={<PageHeader eyebrow="TIỀN MẶT" title="Quy trình tiền mặt" description="Từ thu, chi tiền đến tạm ứng, kiểm kê và dự báo dòng tiền." />}
     >
      <div className="ui-workspace flex flex-col flex-1" data-ui="workspace">
        {/* Native MISA Ultra-Fast Tab Bar (0ms latency, zero re-mount) */}
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
        </div>

        {/* Tab Panels: Fast pre-warm + zero-lag CSS switch */}
        <div className="misa-tab-panel-container ui-workspace-body" data-ui="workspace-body">
          {/* Tab 1 – Quy trình */}
          <div className={activeTabKey === 'tab-process' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {processFlowContent}
          </div>
          
          {/* Tab 2 – Thu, chi tiền */}
          <div className={activeTabKey === 'tab-transactions' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-transactions') && <CashTransactions active={activeTabKey === 'tab-transactions'} />}
          </div>
          
          {/* Tab 3 – Đề nghị chi tiền */}
          <div className={activeTabKey === 'tab-payment-requests' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-payment-requests') && <CashPaymentRequests />}
          </div>
          
          {/* Tab 4 – Quyết toán tạm ứng */}
          <div className={activeTabKey === 'tab-advance-settlements' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-advance-settlements') && <CashAdvanceSettlements />}
          </div>
          
          {/* Tab 5 – Dự báo dòng tiền */}
          <div className={activeTabKey === 'tab-forecast' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-forecast') && <CashForecast />}
          </div>
          
          {/* Tab 6 – Kiểm kê */}
          <div className={activeTabKey === 'tab-audit' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-audit') && <CashAudit active={activeTabKey === 'tab-audit'} />}
          </div>
          
          {/* Tab 7 – Báo cáo */}
          <div className={activeTabKey === 'tab-reports' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-reports') && <CashReports active={activeTabKey === 'tab-reports'} />}
          </div>
        </div>
      </div>

      {/* Embedded Modals Triggerable Directly from Process Flow */}
      <CashReceipts 
        modalOnly 
        open={isReceiptModalOpen} 
        onOpenChange={setIsReceiptModalOpen} 
      />
      <CashPayments 
        modalOnly 
        open={isPaymentModalOpen} 
        onOpenChange={setIsPaymentModalOpen} 
      />
      
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

      {/* Extension Modals for Process Flow Menus */}
      <CollectByInvoiceModal
        open={isCollectByInvoiceOpen}
        onClose={() => setIsCollectByInvoiceOpen(false)}
      />

      <CollectMultiCustomerModal
        open={isCollectMultiCustomerOpen}
        onClose={() => setIsCollectMultiCustomerOpen(false)}
      />

      <PayByInvoiceModal
        open={isPayByInvoiceOpen}
        onClose={() => setIsPayByInvoiceOpen(false)}
      />


       <ExcelImportModal
        open={isExcelImportOpen}
        onClose={() => setIsExcelImportOpen(false)}
        voucherType={excelImportType}
       />
     </PageShell>
  );
};

export default CashWorkspace;
