import React, { useEffect, useState, useMemo } from 'react';
import { Dropdown } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import type { MenuProps } from 'antd';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { 
  UserOutlined, 
  FileTextOutlined,
  DownOutlined,
  ShoppingOutlined
} from '@ant-design/icons';
import { SalesInvoices } from './SalesInvoices';
import { SalesQuotes } from './SalesQuotes';
import { SalesOrders } from './SalesOrders';
import { SalesReturns } from './SalesReturns';
import { SalesDiscounts } from './SalesDiscounts';
import ARAgingReport from './ARAgingReport';
import SalesReports from './SalesReports';

import { QuickAddContactModal, useFastWorkspaceTabs } from '../../components/misa';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';

const TABS = [
  { key: 'tab-process', label: 'Quy trình' },
  { key: 'tab-quotes', label: 'Báo giá' },
  { key: 'tab-orders', label: 'Đơn đặt hàng' },
  { key: 'tab-invoices', label: 'Hóa đơn bán hàng' },
  { key: 'tab-returns', label: 'Hàng bán trả lại' },
  { key: 'tab-discounts', label: 'Giảm giá hàng bán' },
  { key: 'tab-ar-aging', label: 'Công nợ phải thu (AR Aging)' },
  { key: 'tab-reports', label: 'Báo cáo bán hàng' },
];

const TAB_KEYS = TABS.map(t => t.key);

const SALES_QUERY_TABS: Record<string, string> = {
  quote: 'tab-quotes',
  order: 'tab-orders',
  invoice: 'tab-invoices',
  return: 'tab-returns',
  discount: 'tab-discounts',
  report: 'tab-reports',
};

export const SalesWorkspace: React.FC = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { activeTabKey, handleTabChange, isTabMounted } = useFastWorkspaceTabs(TAB_KEYS, 'tab-process');
  const requestedTab = searchParams.get('tab');
  const requestedTabKey = requestedTab ? SALES_QUERY_TABS[requestedTab] : undefined;

  useEffect(() => {
    handleTabChange(requestedTabKey ?? 'tab-process');
  }, [requestedTabKey, handleTabChange]);
  const [isCustomerModalVisible, setIsCustomerModalVisible] = useState(false);

  const quoteMenuItems: MenuProps['items'] = useMemo(() => [
    {
      key: 'quote-menu-1',
      label: 'Lập Báo giá mới',
      onClick: () => handleTabChange('tab-quotes')
    },
    {
      key: 'quote-menu-2',
      label: 'Xem danh sách báo giá',
      onClick: () => handleTabChange('tab-quotes')
    }
  ], [handleTabChange]);

  const orderMenuItems: MenuProps['items'] = useMemo(() => [
    {
      key: 'order-menu-1',
      label: 'Lập Đơn đặt hàng mới',
      onClick: () => handleTabChange('tab-orders')
    },
    {
      key: 'order-menu-2',
      label: 'Xem danh sách đơn đặt hàng',
      onClick: () => handleTabChange('tab-orders')
    }
  ], [handleTabChange]);

  const invoiceMenuItems: MenuProps['items'] = useMemo(() => [
    {
      key: 'inv-menu-1',
      label: 'Bán hàng thu tiền ngay',
      onClick: () => handleTabChange('tab-invoices')
    },
    {
      key: 'inv-menu-2',
      label: 'Bán hàng chưa thu tiền',
      onClick: () => handleTabChange('tab-invoices')
    },
    {
      key: 'inv-menu-3',
      label: 'Bán hàng kiêm phiếu xuất',
      onClick: () => handleTabChange('tab-invoices')
    }
  ], [handleTabChange]);

  const receiptMenuItems: MenuProps['items'] = useMemo(() => [
    {
      key: 'rcpt-menu-1',
      label: 'Thu tiền mặt',
      onClick: () => navigate('/cash/receipts?action=create')
    }
  ], [navigate]);

  const processFlowContent = useMemo(() => (
    <div className="misa-ca-process-container">
      {/* Main Process Diagram Canvas */}
      <div className="misa-ca-process-main">
        <div className="misa-ca-canvas">
          {/* Top Row: Báo giá -> Đơn đặt hàng -> Hóa đơn -> Thu tiền */}
          <div className="misa-ca-canvas-row">
            {/* Node 1: Báo giá */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-quotes')}>
              <div className="misa-ca-node-badge">BÁO GIÁ</div>
              <div className="misa-ca-node-label">Báo giá</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: quoteMenuItems }} placement="bottom">
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tùy chọn <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            {/* Arrow */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 2: Đơn đặt hàng */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-orders')}>
              <div className="misa-ca-node-badge">ĐƠN HÀNG</div>
              <div className="misa-ca-node-label">Đơn đặt hàng</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: orderMenuItems }} placement="bottom">
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tùy chọn <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            {/* Arrow */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 3: Hóa đơn bán ra */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-invoices')}>
              <div className="misa-ca-node-badge">HÓA ĐƠN</div>
              <div className="misa-ca-node-label">Hóa đơn bán ra</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: invoiceMenuItems }} placement="bottom">
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tùy chọn <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>

            {/* Arrow */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 4: Thu tiền */}
            <div className="misa-ca-node" onClick={() => navigate('/cash/receipts?action=create')}>
              <div className="misa-ca-node-badge">THU TIỀN</div>
              <div className="misa-ca-node-label">Thu tiền mặt KH</div>
              <div className="misa-ca-node-subactions">
                <Dropdown menu={{ items: receiptMenuItems }} placement="bottom">
                  <span className="misa-ca-subaction-link" onClick={(e) => e.stopPropagation()}>
                    Tùy chọn <DownOutlined className="misa-fs-9" />
                  </span>
                </Dropdown>
              </div>
            </div>
          </div>

          {/* Bottom Row: Công nợ phải thu */}
          <div className="misa-ca-canvas-row">
            <div className="misa-ca-spacer-140" />
            <div className="misa-ca-spacer-60" />
            
            <div className="misa-ca-spacer-140" />
            <div className="misa-ca-spacer-60" />

            {/* Node 5: Công nợ */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-ar-aging')}>
              <div className="misa-ca-node-badge">CÔNG NỢ</div>
              <div className="misa-ca-node-label">Công nợ phải thu</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link" onClick={(e) => { e.stopPropagation(); handleTabChange('tab-ar-aging'); }}>
                  Xem báo cáo tuổi nợ AR
                </span>
              </div>
            </div>
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
            onClick={() => handleTabChange('tab-quotes')}
          >
            <FileTextOutlined /> Báo giá
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => handleTabChange('tab-orders')}
          >
            <ShoppingOutlined /> Đơn đặt hàng
          </button>
        </div>
      </div>

      {/* Right Sidebar: Reports Panel */}
      <div className="misa-ca-sidebar">
        <div className="misa-ca-report-card">
          <div className="misa-ca-report-title">
            <FileTextOutlined className="misa-color-primary" /> BÁO CÁO BÁN HÀNG
          </div>
          <ul className="misa-ca-report-list">
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Tổng hợp doanh thu bán hàng</span>
            </li>
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-ar-aging')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Báo cáo công nợ phải thu theo KH</span>
            </li>
          </ul>
          <span className="misa-ca-all-reports-link" onClick={() => handleTabChange('tab-reports')}>
            Tất cả báo cáo
          </span>
        </div>
      </div>
    </div>
  ), [quoteMenuItems, orderMenuItems, invoiceMenuItems, receiptMenuItems, handleTabChange, navigate]);

  return (
     <PageShell
       className="misa-workspace-shell"
       title={<PageHeader eyebrow="BÁN HÀNG" title="Quy trình bán hàng" description="Từ báo giá, đơn đặt hàng đến chứng từ và công nợ khách hàng." />}
       toolbar={<PageToolbar className="ui-page-toolbar--hidden" />}
     >
      <div className="ui-workspace flex flex-col flex-1" data-ui="workspace">
        {/* Native MISA Ultra-Fast Tab Bar */}
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
           </div>
        </div>

        {/* Tab Panels: Fast pre-warm + zero-lag CSS switch */}
        <div className="misa-tab-panel-container ui-workspace-body" data-ui="workspace-body">
          {/* Tab 1 – Quy trình */}
          <div className={activeTabKey === 'tab-process' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {processFlowContent}
          </div>

          {/* Tab 2 – Báo giá */}
          <div className={activeTabKey === 'tab-quotes' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-quotes') && <SalesQuotes />}
          </div>

          {/* Tab 3 – Đơn đặt hàng */}
          <div className={activeTabKey === 'tab-orders' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-orders') && <SalesOrders />}
          </div>
          
          {/* Tab 4 – Hóa đơn */}
          <div className={activeTabKey === 'tab-invoices' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-invoices') && <SalesInvoices />}
          </div>

          {/* Tab 5 – Hàng bán trả lại */}
          <div className={activeTabKey === 'tab-returns' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-returns') && <SalesReturns />}
          </div>

          {/* Tab 6 – Giảm giá hàng bán */}
          <div className={activeTabKey === 'tab-discounts' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-discounts') && <SalesDiscounts />}
          </div>
          
          {/* Tab 7 – Công nợ */}
          <div className={activeTabKey === 'tab-ar-aging' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-ar-aging') && <ARAgingReport embedded />}
          </div>
          
          {/* Tab 8 – Báo cáo */}
          <div className={activeTabKey === 'tab-reports' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
            {isTabMounted('tab-reports') && (
              <SalesReports active={activeTabKey === 'tab-reports'} embedded />
            )}
          </div>
        </div>
      </div>

      <QuickAddContactModal 
        open={isCustomerModalVisible}
        contactType="customer"
        onCancel={() => setIsCustomerModalVisible(false)}
        onSuccess={() => {
          setIsCustomerModalVisible(false);
          message.success('Thêm khách hàng thành công');
        }}
       />
     </PageShell>
  );
};

export default SalesWorkspace;
