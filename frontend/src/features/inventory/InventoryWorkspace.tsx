import React, { useState, useMemo, useCallback } from 'react';
import { Button, Input, Select } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { 
  InboxOutlined, 
  ShopOutlined, 
  AppstoreOutlined, 
  FileTextOutlined,
  BlockOutlined,
  PlusOutlined,
  ReloadOutlined,
  PrinterOutlined,
  SearchOutlined
} from '@ant-design/icons';
import { MisaWorkspaceLayout, QuickAddWarehouseModal, QuickAddItemModal, QuickAddUnitModal, QuickAddItemCategoryModal, useFastWorkspaceTabs } from '../../components/misa';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import { ErrorBoundary } from '../../components/common/ErrorBoundary';
import InventoryReceipts from './InventoryReceipts';
import InventoryIssues from './InventoryIssues';
import InventoryTransfers from './InventoryTransfers';
import InventoryStockCounts from './InventoryStockCounts';
import CostCalculation from './CostCalculation';
import StockReport from './StockReport';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';

const TABS = [
  { key: 'tab-process', label: 'Quy trình' },
  { key: 'tab-receipts', label: 'Nhập kho' },
  { key: 'tab-issues', label: 'Xuất kho' },
  { key: 'tab-transfers', label: 'Chuyển kho' },
  { key: 'tab-audit', label: 'Kiểm kê kho' },
  { key: 'tab-costing', label: 'Tính giá xuất kho' },
  { key: 'tab-reports', label: 'Báo cáo kho' },
];

const TAB_KEYS = TABS.map(t => t.key);

export const InventoryWorkspace: React.FC = () => {
  const { activeTabKey, handleTabChange, isTabMounted } = useFastWorkspaceTabs(TAB_KEYS, 'tab-process');
  const [pendingDraft, setPendingDraft] = useState<{ kind: 'receipt' | 'issue'; id: number } | null>(null);
  const openAdjustmentDraft = useCallback((kind: 'receipt' | 'issue', id: number) => {
    setPendingDraft({ kind, id });
    handleTabChange(kind === 'receipt' ? 'tab-receipts' : 'tab-issues');
  }, [handleTabChange]);
  const clearPendingDraft = useCallback(() => setPendingDraft(null), []);
  const catalogue = useQuery({
    queryKey: ['inventory-workspace-catalogue'],
    queryFn: async () => {
      const [warehousesResponse, itemsResponse] = await Promise.all([
        api.get('/master/warehouses'),
        api.get('/inventory/items'),
      ]);
      const readRows = (payload: unknown): unknown[] => {
        if (Array.isArray(payload)) return payload;
        if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) return (payload as { data: unknown[] }).data;
        return [];
      };
      return {
        warehouses: readRows(warehousesResponse.data).filter((row) => row && typeof row === 'object' && (row as { is_active?: unknown }).is_active !== false),
        items: readRows(itemsResponse.data).filter((row) => row && typeof row === 'object' && (row as { is_active?: unknown }).is_active !== false),
      };
    },
  });
  const canCreateActive = activeTabKey === 'tab-receipts' || activeTabKey === 'tab-issues'
    ? true
    : activeTabKey === 'tab-transfers'
    ? (catalogue.data?.warehouses.length ?? 0) >= 2
    : activeTabKey === 'tab-audit'
      ? (catalogue.data?.warehouses.length ?? 0) >= 1
      : false;

  // Master Data Modals
  const [isItemModalVisible, setIsItemModalVisible] = useState(false);
  const [isWarehouseModalVisible, setIsWarehouseModalVisible] = useState(false);
  const [isUnitModalVisible, setIsUnitModalVisible] = useState(false);
  const [isCategoryModalVisible, setIsCategoryModalVisible] = useState(false);

  const processFlowContent = useMemo(() => (
    <div className="misa-ca-process-container">
      {/* Main Process Diagram Canvas */}
      <div className="misa-ca-process-main">
        <div className="misa-ca-canvas">
          {/* Top Row: Nhập kho -> Xuất kho -> Kiểm kê */}
          <div className="misa-ca-canvas-row">
            {/* Node 1: Nhập kho */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-receipts')}>
              <div className="misa-ca-node-badge">NHẬP KHO</div>
              <div className="misa-ca-node-label">Nhập kho vật tư, hàng hóa</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link" onClick={(e) => {
                  e.stopPropagation();
                  handleTabChange('tab-receipts');
                  setTimeout(() => window.dispatchEvent(new Event('open-inventory-receipt')), 50);
                }}>
                  Lập phiếu nhập kho
                </span>
              </div>
            </div>

            {/* Arrow: Nhập -> Xuất */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 2: Xuất kho */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-issues')}>
              <div className="misa-ca-node-badge">XUẤT KHO</div>
              <div className="misa-ca-node-label">Xuất kho bán hàng, sản xuất</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link" onClick={(e) => {
                  e.stopPropagation();
                  handleTabChange('tab-issues');
                  setTimeout(() => window.dispatchEvent(new Event('open-inventory-issue')), 50);
                }}>
                  Lập phiếu xuất kho
                </span>
              </div>
            </div>

            {/* Arrow: Xuất -> Kiểm kê */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 3: Kiểm kê kho */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-audit')}>
              <div className="misa-ca-node-badge">KIỂM KÊ</div>
              <div className="misa-ca-node-label">Kiểm kê số lượng tồn kho</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link" onClick={(e) => {
                  e.stopPropagation();
                  handleTabChange('tab-audit');
                  setTimeout(() => window.dispatchEvent(new Event('open-inventory-stock-count')), 50);
                }}>
                  Biên bản kiểm kê
                </span>
              </div>
            </div>
          </div>

          {/* Bottom Row: Chuyển kho -> Tính giá xuất kho */}
          <div className="misa-ca-canvas-row">
            {/* Node 4: Chuyển kho */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-transfers')}>
              <div className="misa-ca-node-badge">ĐIỀU CHUYỂN</div>
              <div className="misa-ca-node-label">Chuyển kho nội bộ</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link" onClick={(e) => {
                  e.stopPropagation();
                  handleTabChange('tab-transfers');
                  setTimeout(() => window.dispatchEvent(new Event('open-inventory-transfer')), 50);
                }}>
                  Lệnh xuất chuyển kho
                </span>
              </div>
            </div>

            {/* Arrow: Chuyển kho -> Tính giá */}
            <div className="misa-ca-arrow-right">
              <div className="misa-ca-arrow-line" />
              <div className="misa-ca-arrow-head" />
            </div>

            {/* Node 5: Tính giá xuất kho */}
            <div className="misa-ca-node" onClick={() => handleTabChange('tab-costing')}>
              <div className="misa-ca-node-badge">GIÁ VỐN</div>
              <div className="misa-ca-node-label">Tính giá xuất kho tự động</div>
              <div className="misa-ca-node-subactions">
                <span className="misa-ca-subaction-link" onClick={(e) => {
                  e.stopPropagation();
                  handleTabChange('tab-costing');
                }}>
                  Bình quân gia quyền cuối kỳ
                </span>
              </div>
            </div>

            {/* Spacer */}
            <div className="misa-ca-spacer-140" />
            <div className="misa-ca-spacer-60" />
          </div>
        </div>

        {/* Bottom Master Data & Category Bar */}
        <div className="misa-ca-bottom-bar">
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsItemModalVisible(true)}
          >
            <AppstoreOutlined /> Hàng hóa, vật tư
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsWarehouseModalVisible(true)}
          >
            <InboxOutlined /> Kho bãi
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsUnitModalVisible(true)}
          >
            <BlockOutlined /> Đơn vị tính
          </button>
          <button 
            type="button" 
            className="misa-ca-category-btn"
            onClick={() => setIsCategoryModalVisible(true)}
          >
            <ShopOutlined /> Nhóm VTHH
          </button>
        </div>
      </div>

      {/* Right Sidebar: Reports Panel */}
      <div className="misa-ca-sidebar">
        <div className="misa-ca-report-card">
          <div className="misa-ca-report-title">
            <FileTextOutlined className="misa-color-primary" /> BÁO CÁO KHO
          </div>
          <ul className="misa-ca-report-list">
            <li className="misa-ca-report-item" onClick={() => handleTabChange('tab-reports')}>
              <span className="misa-ca-report-bullet">•</span>
              <span>Tổng hợp nhập - xuất - tồn</span>
            </li>
          </ul>
          <span className="misa-ca-all-reports-link" onClick={() => handleTabChange('tab-reports')}>
            Tất cả báo cáo
          </span>
        </div>
      </div>
    </div>
  ), [handleTabChange]);

  const [searchText, setSearchText] = useState('');
  const [datePreset, setDatePreset] = useState('Tháng này');

  const getSearchPlaceholder = (tab: string) => {
    switch (tab) {
      case 'tab-receipts':
        return 'Tìm theo số phiếu nhập, đối tượng, lý do...';
      case 'tab-issues':
        return 'Tìm theo số phiếu xuất, đối tượng, lý do...';
      case 'tab-transfers':
        return 'Tìm theo số phiếu điều chuyển, kho...';
      case 'tab-audit':
        return 'Tìm theo số biên bản, kho...';
      default:
        return 'Tìm kiếm số chứng từ, đối tượng...';
    }
  };

  const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    setSearchText(val);
    const eventName = activeTabKey === 'tab-receipts' ? 'search-inventory-receipt'
      : activeTabKey === 'tab-issues' ? 'search-inventory-issue'
      : activeTabKey === 'tab-transfers' ? 'search-inventory-transfer'
      : activeTabKey === 'tab-audit' ? 'search-inventory-audit'
      : '';
    if (eventName) {
      window.dispatchEvent(new CustomEvent(eventName, { detail: val }));
    }
  };

  const handleDatePresetChange = (val: string) => {
    setDatePreset(val);
    window.dispatchEvent(new CustomEvent('filter-inventory-date', { detail: val }));
  };

  const handleRefresh = () => {
    if (activeTabKey === 'tab-receipts') {
      window.dispatchEvent(new Event('refresh-inventory-receipt'));
    } else if (activeTabKey === 'tab-issues') {
      window.dispatchEvent(new Event('refresh-inventory-issue'));
    } else if (activeTabKey === 'tab-transfers') {
      window.dispatchEvent(new Event('refresh-inventory-transfer'));
    } else if (activeTabKey === 'tab-audit') {
      window.dispatchEvent(new Event('refresh-inventory-stock-count'));
    }
    message.success('Đã làm mới dữ liệu!');
  };

  return (
    <PageShell
      className="misa-workspace-shell"
      title={<PageHeader eyebrow="Kho" title="Quản lý kho" description="Quy trình nhập, xuất, chuyển và kiểm kê hàng hóa." />}
    >
    <MisaWorkspaceLayout
      tabs={TABS}
      activeTabKey={activeTabKey}
      onTabChange={handleTabChange}
      toolbar={<PageToolbar
        className={!['tab-receipts', 'tab-issues', 'tab-transfers', 'tab-audit'].includes(activeTabKey) ? 'ui-page-toolbar--hidden' : undefined}
        filters={['tab-receipts', 'tab-issues', 'tab-transfers', 'tab-audit'].includes(activeTabKey) ? (
          <div className="misa-toolbar-left flex items-center gap-2">
            <Input
              placeholder={getSearchPlaceholder(activeTabKey)}
              prefix={<SearchOutlined className="misa-color-muted" />}
              className="misa-w-280"
              value={searchText}
              onChange={handleSearchChange}
              allowClear
            />
            <Select
              value={datePreset}
              onChange={handleDatePresetChange}
              className="misa-w-140"
              options={['Hôm nay', 'Tuần này', 'Tháng này', 'Quý này', 'Năm nay'].map((value) => ({ value, label: value }))}
            />
          </div>
        ) : undefined}
        actions={(['tab-receipts', 'tab-issues', 'tab-transfers', 'tab-audit'].includes(activeTabKey)) ? (
          <div className="misa-toolbar-right flex items-center gap-2">
            <Button
              icon={<ReloadOutlined />}
              className="misa-btn-tool"
              title="Làm mới (F5)"
              onClick={handleRefresh}
            />
            <Button
              icon={<PrinterOutlined />}
              className="misa-btn-tool"
              title="In danh sách"
              onClick={() => window.print()}
            />
            <Button
              type="primary"
              className="misa-btn-primary"
              icon={<PlusOutlined />}
              disabled={!canCreateActive}
              onClick={() => window.dispatchEvent(new Event(
                activeTabKey === 'tab-receipts' ? 'open-inventory-receipt' : activeTabKey === 'tab-issues' ? 'open-inventory-issue' : activeTabKey === 'tab-transfers' ? 'open-inventory-transfer' : 'open-inventory-stock-count'
              ))}
            >
              {activeTabKey === 'tab-receipts' ? 'Thêm phiếu nhập' : activeTabKey === 'tab-issues' ? 'Thêm phiếu xuất' : activeTabKey === 'tab-transfers' ? 'Thêm phiếu điều chuyển' : 'Thêm biên bản nháp'}
            </Button>
          </div>
        ) : undefined}
      />}
    >
      {/* Tab 1 – Quy trình */}
      <div className={activeTabKey === 'tab-process' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        <ErrorBoundary fallback={<div className="p-6 text-red-500">Lỗi khi tải Quy trình kho</div>}>
          {processFlowContent}
        </ErrorBoundary>
      </div>

      {/* Tab 2 – Nhập kho */}
      <div className={activeTabKey === 'tab-receipts' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-receipts') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500">Lỗi khi tải giao diện Nhập kho</div>}>
            <InventoryReceipts embedded openDraftId={pendingDraft?.kind === 'receipt' ? pendingDraft.id : null} onDraftOpened={clearPendingDraft} />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 3 – Xuất kho */}
      <div className={activeTabKey === 'tab-issues' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-issues') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500">Lỗi khi tải giao diện Xuất kho</div>}>
            <InventoryIssues embedded openDraftId={pendingDraft?.kind === 'issue' ? pendingDraft.id : null} onDraftOpened={clearPendingDraft} />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 4 – Chuyển kho */}
      <div className={activeTabKey === 'tab-transfers' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-transfers') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500">Lỗi khi tải giao diện Chuyển kho</div>}>
            <InventoryTransfers embedded />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 5 – Kiểm kê kho */}
      <div className={activeTabKey === 'tab-audit' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-audit') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500">Lỗi khi tải giao diện Kiểm kê kho</div>}>
            <InventoryStockCounts embedded onOpenAdjustmentDraft={openAdjustmentDraft} />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 6 – Tính giá xuất kho */}
      <div className={activeTabKey === 'tab-costing' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-costing') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500">Lỗi khi tải giao diện Tính giá xuất kho</div>}>
            <CostCalculation embedded />
          </ErrorBoundary>
        )}
      </div>

      {/* Tab 7 – Báo cáo kho */}
      <div className={activeTabKey === 'tab-reports' ? 'misa-tab-pane' : 'misa-tab-pane-hidden'}>
        {isTabMounted('tab-reports') && (
          <ErrorBoundary fallback={<div className="p-6 text-red-500">Lỗi khi tải giao diện Báo cáo kho</div>}>
            <StockReport embedded />
          </ErrorBoundary>
        )}
      </div>

      {/* Quick Add Master Data Modals */}
      <QuickAddItemModal
        open={isItemModalVisible}
        onCancel={() => setIsItemModalVisible(false)}
        onSuccess={() => {
          setIsItemModalVisible(false);
          message.success('Thêm vật tư hàng hóa thành công');
        }}
      />

      <QuickAddWarehouseModal
        open={isWarehouseModalVisible}
        onCancel={() => setIsWarehouseModalVisible(false)}
        onSuccess={() => {
          setIsWarehouseModalVisible(false);
          message.success('Thêm kho thành công');
        }}
      />

      <QuickAddUnitModal
        open={isUnitModalVisible}
        onCancel={() => setIsUnitModalVisible(false)}
        onSuccess={() => {
          setIsUnitModalVisible(false);
          message.success('Thêm đơn vị tính thành công');
        }}
      />

      <QuickAddItemCategoryModal
        open={isCategoryModalVisible}
        onCancel={() => setIsCategoryModalVisible(false)}
        onSuccess={() => {
          setIsCategoryModalVisible(false);
          message.success('Thêm nhóm hàng hóa thành công');
        }}
      />
    </MisaWorkspaceLayout>
    </PageShell>
  );
};

export default InventoryWorkspace;
