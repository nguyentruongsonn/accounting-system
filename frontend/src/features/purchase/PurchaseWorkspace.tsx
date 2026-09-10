import React, { useEffect, useState, useMemo } from 'react';
import { Dropdown } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import type { MenuProps } from 'antd';
import { useSearchParams } from 'react-router-dom';
import { 
  ShopOutlined, 
  AppstoreOutlined,
  FileProtectOutlined,
  FileTextOutlined,
  DownOutlined,
  ToolOutlined,
  DollarOutlined
} from '@ant-design/icons';

import PurchaseDashboard from './PurchaseDashboard';
import PurchaseOrders from './PurchaseOrders';
import PurchaseContracts from './PurchaseContracts';
import PurchaseInvoices from './PurchaseInvoices';
import PurchaseReturns from './PurchaseReturns';
import PurchaseDiscounts from './PurchaseDiscounts';
import PurchaseReports from './PurchaseReports';

import PayVendorByInvoiceModal from './modals/PayVendorByInvoiceModal';

import { QuickAddContactModal, QuickAddPaymentTermModal, QuickAddItemModal, useFastWorkspaceTabs } from '../../components/misa';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';

const TABS = [
  { key: 'tab-process', label: 'Quy trình' },
  { key: 'tab-orders', label: 'Đơn mua hàng' },
  { key: 'tab-contracts', label: 'Hợp đồng mua hàng' },
  { key: 'tab-invoices', label: 'Mua hàng' },
  { key: 'tab-returns', label: 'Trả lại hàng mua' },
  { key: 'tab-discounts', label: 'Giảm giá hàng mua' },
  { key: 'tab-dashboard', label: 'Biểu đồ' },
  { key: 'tab-reports', label: 'Báo cáo' },
];

const TAB_KEYS = TABS.map(t => t.key);

const PURCHASE_QUERY_TABS: Record<string, string> = {
  '1': 'tab-process',
  '2': 'tab-orders',
  '3': 'tab-contracts',
  '4': 'tab-invoices',
  '6': 'tab-returns',
  '7': 'tab-discounts',
  '8': 'tab-dashboard',
  '9': 'tab-reports',
};

export const PurchaseWorkspace: React.FC = () => {
  const [searchParams] = useSearchParams();
  const { activeTabKey, handleTabChange, isTabMounted } = useFastWorkspaceTabs(TAB_KEYS, 'tab-process');
  const requestedTab = searchParams.get('tab');
  const requestedTabKey = requestedTab ? PURCHASE_QUERY_TABS[requestedTab] : undefined;

  useEffect(() => {
    handleTabChange(requestedTabKey ?? 'tab-process');
  }, [requestedTabKey, handleTabChange]);

  // Utilities Modal States
  const [isPayVendorModalOpen, setIsPayVendorModalOpen] = useState(false);

  // Master Data Modals
  const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
  const [isItemModalVisible, setIsItemModalVisible] = useState(false);
  const [isPaymentTermModalVisible, setIsPaymentTermModalVisible] = useState(false);

  // The query action is the durable counterpart to the sidebar's transient
  // navigation and opens the server-backed supplier payment modal.
  useEffect(() => {
    if (searchParams.get('action') === 'payment') setIsPayVendorModalOpen(true);
  }, [searchParams]);

  const purchaseMenuItems: MenuProps['items'] = useMemo(() => [
    { key: 'pm-1', label: 'Mua hàng trong nước nhập kho', onClick: () => handleTabChange('tab-invoices') },
    { key: 'pm-2', label: 'Mua hàng trong nước không qua kho', onClick: () => handleTabChange('tab-invoices') },
    { key: 'pm-3', label: 'Mua hàng nhập khẩu nhập kho', onClick: () => handleTabChange('tab-invoices') },
    { key: 'pm-4', label: 'Mua hàng nhập khẩu không qua kho', onClick: () => handleTabChange('tab-invoices') },
    { key: 'pm-5', label: 'Mua dịch vụ', onClick: () => handleTabChange('tab-invoices') },
  ], [handleTabChange]);

  const returnMenuItems: MenuProps['items'] = useMemo(() => [
    { key: 'rm-1', label: 'Trả lại hàng mua', onClick: () => handleTabChange('tab-returns') },
    { key: 'rm-2', label: 'Giảm giá hàng mua', onClick: () => handleTabChange('tab-discounts') },
  ], [handleTabChange]);

  const utilityMenu = {
    items: [
      {
        key: 'invoices',
        icon: <FileTextOutlined />,
        label: 'Xử lý hóa đơn đầu vào',
        onClick: () => window.location.href = '/invoices-management'
      },
      {
        key: 'payment',
        icon: <DollarOutlined />,
        label: 'Trả tiền nhà cung cấp',
        onClick: () => setIsPayVendorModalOpen(true),
      }
    ]
  };

  const processFlowContent = useMemo(() => (
    <div className="misa-ca-process-container">
      <div className="misa-ca-process-main">
        <div className="misa-ca-canvas">
          {/* Top Row: Đơn mua hàng -> Hợp đồng mua */}
          <div className="misa-ca-canvas-row">
            <div className="misa-ca-spacer-140" />
            <div className="misa-ca-spacer-60" />

            <div className="misa-ca-node" onClick={() => handleTabChange('tab-orders')}>
              <div className="misa-ca-node-badge">ĐƠN HÀNG</div>
              <div className="misa-ca-node-label">Đơn mua hàng</div>
            </div>

            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            <div className="misa-ca-node" onClick={() => handleTabChange('tab-contracts')}>
              <div className="misa-ca-node-badge">HỢP ĐỒNG</div>
              <div className="misa-ca-node-label">Hợp đồng mua</div>
            </div>
            
            <div className="misa-ca-spacer-140" />
          </div>

          {/* Bottom Row: Mua hàng -> Trả lại -> Thanh toán */}
          <div className="misa-ca-canvas-row">
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-invoices')}>
              <div className="misa-ca-node-badge">MUA HÀNG</div>
              <div className="misa-ca-node-label">Mua hàng hóa, dịch vụ</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: purchaseMenuItems }} placement="bottom">
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tùy chọn mua hàng <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            <div className="misa-ca-node" onClick={() => handleTabChange('tab-returns')}>
              <div className="misa-ca-node-badge">TRẢ LẠI</div>
              <div className="misa-ca-node-label">Trả lại / Giảm giá</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: returnMenuItems }} placement="bottom">
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tùy chọn <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            <div className="misa-ca-node" onClick={() => setIsPayVendorModalOpen(true)}>
              <div className="misa-ca-node-badge">TRẢ TIỀN</div>
              <div className="misa-ca-node-label">Trả tiền NCC</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link">Thanh toán tiền mặt</span>
              </div>
            </div>
          </div>
        </div>

        <div className="misa-ca-bottom-bar">
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
            onClick={() => setIsItemModalVisible(true)}
          >
            <AppstoreOutlined /> Hàng hóa, dịch vụ
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsPaymentTermModalVisible(true)}
          >
            <FileProtectOutlined /> Điều khoản thanh toán
          </button>
        </div>
      </div>

      <div className="misa-ca-sidebar">
        <div className="misa-ca-report-card">
          <div className="misa-ca-report-title">
            <FileTextOutlined className="text-blue-600" /> BÁO CÁO
          </div>
          <ul className="misa-ca-report-list">
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Tổng hợp mua hàng</span>
            </li>
          </ul>
          <span className="misa-ca-all-reports-link" onClick={() => handleTabChange('tab-reports')}>
            Tất cả báo cáo
          </span>
        </div>
      </div>
    </div>
  ), [purchaseMenuItems, returnMenuItems, handleTabChange]);

  const TABS = [
    { key: 'tab-process', label: 'Quy trình' },
    { key: 'tab-orders', label: 'Đơn mua hàng' },
    { key: 'tab-contracts', label: 'Hợp đồng mua hàng' },
    { key: 'tab-invoices', label: 'Mua hàng' },
    { key: 'tab-returns', label: 'Trả lại hàng mua' },
    { key: 'tab-discounts', label: 'Giảm giá hàng mua' },
    { key: 'tab-dashboard', label: 'Biểu đồ' },
    { key: 'tab-reports', label: 'Báo cáo' },
  ];

  return (
     <PageShell
       className="misa-workspace-shell"
       title={<PageHeader eyebrow="MUA HÀNG" title="Quy trình mua hàng" description="Từ đơn mua đến theo dõi công nợ nhà cung cấp." />}
       toolbar={<PageToolbar className="ui-page-toolbar--hidden" />}
     >
      <div className="ui-workspace flex flex-col flex-1" data-ui="workspace">
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
            <Dropdown menu={utilityMenu} placement="bottomRight">
              <button type="button" className="misa-btn-tool-outline misa-flex-center misa-gap-6 misa-mx-8">
                <ToolOutlined /> Tiện ích <DownOutlined className="misa-fs-10" />
              </button>
            </Dropdown>
           </div>
        </div>

        <div className="misa-tab-panel-container ui-workspace-body" data-ui="workspace-body">
          <div className={activeTabKey === 'tab-process' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {processFlowContent}
          </div>
          <div className={activeTabKey === 'tab-orders' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-orders') && <PurchaseOrders />}
          </div>
          <div className={activeTabKey === 'tab-contracts' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-contracts') && <PurchaseContracts />}
          </div>
          <div className={activeTabKey === 'tab-invoices' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-invoices') && <PurchaseInvoices />}
          </div>
          <div className={activeTabKey === 'tab-returns' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-returns') && <PurchaseReturns />}
          </div>
          <div className={activeTabKey === 'tab-discounts' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-discounts') && <PurchaseDiscounts />}
          </div>
          <div className={activeTabKey === 'tab-dashboard' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-dashboard') && <PurchaseDashboard />}
          </div>
          <div className={activeTabKey === 'tab-reports' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-reports') && <PurchaseReports embedded />}
          </div>
        </div>
      </div>

      <PayVendorByInvoiceModal
        open={isPayVendorModalOpen}
        onCancel={() => setIsPayVendorModalOpen(false)}
        onSuccess={() => window.dispatchEvent(new CustomEvent('purchase-invoices-invalidated'))}
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
      <QuickAddItemModal 
        open={isItemModalVisible}
        onCancel={() => setIsItemModalVisible(false)}
        onSuccess={() => {
          setIsItemModalVisible(false);
          message.success('Thêm hàng hóa/dịch vụ thành công');
        }}
      />
      <QuickAddPaymentTermModal 
        open={isPaymentTermModalVisible}
        onCancel={() => setIsPaymentTermModalVisible(false)}
        onSuccess={() => {
          setIsPaymentTermModalVisible(false);
          message.success('Thêm điều khoản thanh toán thành công');
        }}
       />
     </PageShell>
  );
};

export default PurchaseWorkspace;
