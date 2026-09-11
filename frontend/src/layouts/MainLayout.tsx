import React, { Suspense, useState, useRef, useEffect } from 'react';
import { Layout, Menu, Dropdown, Avatar, Button, Input, Tag } from 'antd';
import Modal from '../components/layout/AppModal';
import {
  MenuFoldOutlined,
  MenuUnfoldOutlined,
  BellOutlined,
  UserOutlined,
  DashboardOutlined,
  DollarOutlined,
  ShoppingCartOutlined,
  ShopOutlined,
  DatabaseOutlined,
  BookOutlined,
  FileTextOutlined,
  RightOutlined,
  DownOutlined,
  SearchOutlined,
  PlusOutlined,
  SettingOutlined,
  LogoutOutlined,
  AuditOutlined
} from '@ant-design/icons';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import api from '../api/axios';
import { clearClientSession } from '../auth/session';
import { useAuthStore } from '../store/useAuthStore';
import RouteLoadingFallback from '../components/common/RouteLoadingFallback';

const { Header, Sider, Content } = Layout;
const SIDEBAR_WIDTH = 232;
const SIDEBAR_COLLAPSED_WIDTH = 72;

export const resolveBreadcrumb = (pathname: string): { section: string; page: string; tag?: string } => {
  if (pathname.startsWith('/cash/receipts')) return { section: 'Tiền mặt', page: 'Phiếu thu tiền mặt', tag: 'PT' };
  if (pathname.startsWith('/cash/payments')) return { section: 'Tiền mặt', page: 'Phiếu chi tiền mặt', tag: 'PC' };
  if (pathname.startsWith('/cash')) return { section: 'Tiền mặt', page: 'Quy trình thu chi quỹ' };
  if (pathname.startsWith('/purchase/invoices')) return { section: 'Mua hàng', page: 'Chứng từ mua hàng', tag: 'PO' };
  if (pathname.startsWith('/purchase/ap-aging')) return { section: 'Mua hàng', page: 'Đối chiếu công nợ phải trả' };
  if (pathname.startsWith('/purchase')) return { section: 'Mua hàng', page: 'Quy trình mua hàng' };
  if (pathname.startsWith('/sales/invoices')) return { section: 'Bán hàng', page: 'Chứng từ bán hàng', tag: 'HĐ' };
  if (pathname.startsWith('/sales/ar-aging')) return { section: 'Bán hàng', page: 'Đối chiếu công nợ phải thu' };
  if (pathname.startsWith('/sales')) return { section: 'Bán hàng', page: 'Quy trình bán hàng' };
  if (pathname.startsWith('/inventory/receipts')) return { section: 'Kho', page: 'Phiếu nhập kho', tag: 'PN' };
  if (pathname.startsWith('/inventory/issues')) return { section: 'Kho', page: 'Phiếu xuất kho', tag: 'PX' };
  if (pathname.startsWith('/inventory/transfers')) return { section: 'Kho', page: 'Phiếu chuyển kho', tag: 'CK' };
  if (pathname.startsWith('/inventory/stock-counts')) return { section: 'Kho', page: 'Biên bản kiểm kê kho', tag: 'KK' };
  if (pathname.startsWith('/inventory/items')) return { section: 'Kho', page: 'Danh mục hàng hóa, dịch vụ' };
  if (pathname.startsWith('/inventory')) return { section: 'Kho', page: 'Quản lý kho & Giá vốn' };
  if (pathname.startsWith('/fixed-assets') || pathname.startsWith('/assets')) {
    return { section: 'Tài sản', page: 'Công cụ dụng cụ & TSCĐ' };
  }
  if (pathname.startsWith('/gl')) return { section: 'Tổng hợp', page: 'Sổ nhật ký chung & Khóa sổ' };
  if (pathname.startsWith('/reports')) return { section: 'Báo cáo', page: 'Báo cáo tài chính & Quản trị' };
  if (pathname.startsWith('/master/accounts') || pathname.startsWith('/settings/account-catalogues')) return { section: 'Danh mục', page: 'Hệ thống tài khoản' };
  if (pathname.startsWith('/master')) return { section: 'Danh mục', page: 'Dữ liệu danh mục hệ thống' };
  if (pathname.startsWith('/settings')) return { section: 'Thiết lập', page: 'Cấu hình hệ thống & Phân quyền' };
  return { section: 'Tổng quan', page: 'Bảng điều khiển hoạt động' };
};

/** Resolve the owning sidebar item for both workspace roots and deep links. */
export const resolveActiveMenuKey = (pathname: string): string | undefined => {
  if (pathname === '/') return '/';
  if (pathname.startsWith('/cash')) return '/cash';
  if (pathname.startsWith('/purchase')) return 'purchase-group';
  if (pathname.startsWith('/sales')) return 'sales-group';
  if (pathname.startsWith('/inventory')) return '/inventory';
  if (pathname.startsWith('/fixed-assets') || pathname.startsWith('/assets')) return '/fixed-assets';
  if (pathname.startsWith('/gl')) return '/gl';
  if (pathname.startsWith('/reports')) return '/reports';
  if (pathname.startsWith('/master/accounts') || pathname.startsWith('/settings/account-catalogues')) return '/master/accounts';
  if (pathname.startsWith('/master')) return '/master/customers';
  if (pathname.startsWith('/settings')) return undefined;
  if (pathname.startsWith('/invoices-management')) return '/invoices-management';
  return undefined;
};

interface FlyoutConfig {
  title: string;
  operations: { label: string; route?: string; badge?: string }[];
  utilities: { label: string; route?: string }[];
}

const FLYOUT_CONFIGS: Record<string, FlyoutConfig> = {
  'purchase-group': {
    title: 'MUA HÀNG',
    operations: [
      { label: 'Đơn mua hàng', route: '/purchase?tab=2', badge: 'PO' },
      { label: 'Hợp đồng mua', route: '/purchase?tab=3' },
      { label: 'Mua hàng hóa, dịch vụ', route: '/purchase?tab=4' },
      { label: 'Trả lại hàng mua', route: '/purchase?tab=6' },
      { label: 'Giảm giá hàng mua', route: '/purchase?tab=7' },
      { label: 'Đối chiếu công nợ', route: '/purchase/ap-aging' },
      { label: 'Biểu đồ', route: '/purchase?tab=8' },
    ],
    utilities: [
      { label: 'Nhà cung cấp', route: '/master/suppliers' },
      { label: 'Hàng hóa, dịch vụ', route: '/inventory/items' },
      { label: 'Báo cáo mua hàng', route: '/purchase?tab=9' },
    ]
  },
  'sales-group': {
    title: 'BÁN HÀNG',
    operations: [
      { label: 'Báo giá', route: '/sales?tab=quote' },
      { label: 'Đơn đặt hàng', route: '/sales?tab=order', badge: 'SO' },
      { label: 'Chứng từ bán hàng', route: '/sales?tab=invoice' },
      { label: 'Trả lại hàng bán', route: '/sales?tab=return' },
      { label: 'Giảm giá hàng bán', route: '/sales?tab=discount' },
      { label: 'Đối chiếu công nợ', route: '/sales/ar-aging' },
    ],
    utilities: [
      { label: 'Thu tiền khách hàng hàng loạt', route: '/cash/receipts?action=collect-multi' },
      { label: 'Khách hàng', route: '/master/customers' },
      { label: 'Báo cáo bán hàng', route: '/sales?tab=report' },
    ]
  }
};

const SEARCH_DIRECTORY = [
  { label: 'Phiếu thu tiền mặt', category: 'Tiền mặt', route: '/cash/receipts' },
  { label: 'Phiếu chi tiền mặt', category: 'Tiền mặt', route: '/cash/payments' },
  { label: 'Quy trình tiền mặt', category: 'Tiền mặt', route: '/cash' },
  { label: 'Chứng từ mua hàng', category: 'Mua hàng', route: '/purchase/invoices' },
  { label: 'Đơn mua hàng (PO)', category: 'Mua hàng', route: '/purchase?tab=2' },
  { label: 'Công nợ phải trả (AP Aging)', category: 'Mua hàng', route: '/purchase/ap-aging' },
  { label: 'Chứng từ bán hàng', category: 'Bán hàng', route: '/sales/invoices' },
  { label: 'Báo giá bán hàng', category: 'Bán hàng', route: '/sales?tab=quote' },
  { label: 'Đơn đặt hàng (SO)', category: 'Bán hàng', route: '/sales?tab=order' },
  { label: 'Công nợ phải thu (AR Aging)', category: 'Bán hàng', route: '/sales/ar-aging' },
  { label: 'Danh mục Vật tư hàng hóa', category: 'Kho', route: '/inventory/items' },
  { label: 'Phiếu nhập kho', category: 'Kho', route: '/inventory/receipts' },
  { label: 'Phiếu xuất kho', category: 'Kho', route: '/inventory/issues' },
  { label: 'Báo cáo tồn kho', category: 'Kho', route: '/inventory' },
  { label: 'Đối chiếu tồn kho – sổ cái', category: 'Kho', route: '/reports/inventory-reconciliation' },
  { label: 'Phiếu kế toán tổng hợp', category: 'Tổng hợp', route: '/gl' },
  { label: 'Bảng cân đối kế toán', category: 'Báo cáo', route: '/reports' },
  { label: 'Báo cáo kết quả hoạt động kinh doanh', category: 'Báo cáo', route: '/reports' },
  { label: 'Báo cáo lưu chuyển tiền tệ', category: 'Báo cáo', route: '/reports' },
  { label: 'Danh mục Khách hàng', category: 'Danh mục', route: '/master/customers' },
  { label: 'Danh mục Nhà cung cấp', category: 'Danh mục', route: '/master/suppliers' },
  { label: 'Hệ thống Tài khoản (COA)', category: 'Danh mục', route: '/master/accounts' },
  { label: 'Danh mục Nhân viên', category: 'Danh mục', route: '/master/employees' },
];

export const MainLayout: React.FC = () => {
  const [collapsed, setCollapsed] = useState(false);
  const [activeFlyout, setActiveFlyout] = useState<{ key: string; top: number } | null>(null);
  const [searchModalOpen, setSearchModalOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const flyoutTimerRef = useRef<any>(null);
  const navigate = useNavigate();
  const location = useLocation();
  const breadcrumb = resolveBreadcrumb(location.pathname);
  const activeMenuKey = resolveActiveMenuKey(location.pathname);
  const authUser = useAuthStore((state) => state.user);
  const displayUserName = authUser?.name?.trim() || authUser?.email?.trim() || 'Kế toán viên';

  // Keep business screens usable when the browser is zoomed in or the app is
  // shown in a narrow desktop panel. The user can still expand the sidebar
  // manually after the automatic breakpoint has been applied.
  useEffect(() => {
    if (typeof window.matchMedia !== 'function') return;
    const narrowViewport = window.matchMedia('(max-width: 900px)');
    const syncSidebar = (event: MediaQueryListEvent | MediaQueryList) => setCollapsed(event.matches);
    syncSidebar(narrowViewport);
    narrowViewport.addEventListener('change', syncSidebar);
    return () => narrowViewport.removeEventListener('change', syncSidebar);
  }, []);

  // Global Ctrl+K / Cmd+K listener
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setSearchModalOpen((prev) => !prev);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  const handleLogout = async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Local logout fallback
    } finally {
      clearClientSession();
      navigate('/login', { replace: true });
    }
  };

  const handleMouseEnterMenuItem = (key: string, e: React.MouseEvent) => {
    if (FLYOUT_CONFIGS[key]) {
      if (flyoutTimerRef.current) clearTimeout(flyoutTimerRef.current);
      const rect = e.currentTarget.getBoundingClientRect();
      setActiveFlyout({ key, top: rect.top });
    }
  };

  const handleMouseLeaveMenuItem = () => {
    flyoutTimerRef.current = setTimeout(() => {
      setActiveFlyout(null);
    }, 200);
  };

  const handleMouseEnterFlyout = () => {
    if (flyoutTimerRef.current) clearTimeout(flyoutTimerRef.current);
  };

  const handleMouseLeaveFlyout = () => {
    setActiveFlyout(null);
  };

  const menuItems = [
    // Tiền gửi/Ngân hàng được ẩn khỏi menu nội bộ; compatibility routes remain available for existing deep links.
    {
      key: '/',
      icon: <DashboardOutlined style={{ fontSize: 16 }} />,
      label: 'Tổng quan',
    },
    {
      key: '/cash',
      icon: <DollarOutlined style={{ fontSize: 16 }} />,
      label: 'Tiền mặt',
    },
    {
      key: 'purchase-group',
      icon: <ShoppingCartOutlined style={{ fontSize: 16 }} />,
      label: (
        <div 
          className="misa-sidebar-item-wrapper"
          onMouseEnter={(e) => handleMouseEnterMenuItem('purchase-group', e)}
          onMouseLeave={handleMouseLeaveMenuItem}
        >
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' }}>
            <span>Mua hàng</span>
            {!collapsed && <RightOutlined style={{ fontSize: 10, opacity: 0.6 }} />}
          </div>
        </div>
      ),
    },
    {
      key: 'sales-group',
      icon: <ShopOutlined style={{ fontSize: 16 }} />,
      label: (
        <div 
          className="misa-sidebar-item-wrapper"
          onMouseEnter={(e) => handleMouseEnterMenuItem('sales-group', e)}
          onMouseLeave={handleMouseLeaveMenuItem}
        >
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', width: '100%' }}>
            <span>Bán hàng</span>
            {!collapsed && <RightOutlined style={{ fontSize: 10, opacity: 0.6 }} />}
          </div>
        </div>
      ),
    },
    {
      key: '/inventory',
      icon: <DatabaseOutlined style={{ fontSize: 16 }} />,
      label: 'Kho & Giá vốn',
    },
    {
      key: '/reports',
      icon: <FileTextOutlined style={{ fontSize: 16 }} />,
      label: 'Báo cáo',
    },
    {
      key: '/master/customers',
      icon: <AuditOutlined style={{ fontSize: 16 }} />,
      label: 'Danh mục',
    },
    {
      key: '/master/accounts',
      icon: <AuditOutlined style={{ fontSize: 16 }} />,
      label: 'Hệ thống tài khoản',
    },
  ];

  const userMenu = [
    {
      key: 'user-info',
      label: (
        <div style={{ padding: '4px 0' }}>
          <div style={{ fontWeight: 600, color: '#0F172A' }}>{displayUserName}</div>
          <div style={{ fontSize: 11, color: '#64748B' }}>{authUser?.email || 'admin@accounting.vn'}</div>
        </div>
      ),
      disabled: true,
    },
    { type: 'divider' as const },
    {
      key: 'opening-balances',
      icon: <BookOutlined />,
      label: 'Số dư đầu kỳ',
      onClick: () => navigate('/settings/opening-balances'),
    },
    ...(authUser?.roles?.includes('admin') ? [{
      key: 'roles',
      icon: <UserOutlined />,
      label: 'Người dùng & phân quyền',
      onClick: () => navigate('/settings/roles'),
    }] : []),
    {
      key: 'settings',
      icon: <SettingOutlined />,
      label: 'Thiết lập hệ thống',
      onClick: () => navigate('/settings/company'),
    },
    { type: 'divider' as const },
    {
      key: 'logout',
      icon: <LogoutOutlined />,
      label: 'Đăng xuất',
      danger: true,
      onClick: handleLogout,
    },
  ];

  const quickAddItems = [
    { key: 'cash-receipt', label: 'Lập phiếu thu tiền mặt', onClick: () => navigate('/cash/receipts?action=create') },
    { key: 'cash-payment', label: 'Lập phiếu chi tiền mặt', onClick: () => navigate('/cash/payments?action=create') },
    { type: 'divider' as const },
    { key: 'purchase-invoice', label: 'Lập chứng từ mua hàng', onClick: () => navigate('/purchase/invoices?action=create') },
    { key: 'sales-invoice', label: 'Lập chứng từ bán hàng', onClick: () => navigate('/sales/invoices?action=create') },
    { type: 'divider' as const },
    { key: 'inventory-receipt', label: 'Lập phiếu nhập kho', onClick: () => navigate('/inventory/receipts?action=create') },
    { key: 'inventory-issue', label: 'Lập phiếu xuất kho', onClick: () => navigate('/inventory/issues?action=create') },
    { type: 'divider' as const },
    { key: 'item', label: 'Thêm vật tư hàng hóa', onClick: () => navigate('/inventory/items') },
    { key: 'customer', label: 'Thêm khách hàng', onClick: () => navigate('/master/customers') },
    { key: 'supplier', label: 'Thêm nhà cung cấp', onClick: () => navigate('/master/suppliers') },
  ];

  const filteredSearch = SEARCH_DIRECTORY.filter((item) =>
    item.label.toLowerCase().includes(searchQuery.toLowerCase()) ||
    item.category.toLowerCase().includes(searchQuery.toLowerCase())
  );

  return (
    <Layout className="app-shell" style={{ minHeight: '100vh', background: '#FFFFFF' }}>
      {/* Sleek Dark Sider */}
      <Sider
        trigger={null}
        collapsible
        collapsed={collapsed}
        width={SIDEBAR_WIDTH}
        collapsedWidth={SIDEBAR_COLLAPSED_WIDTH}
        style={{
          background: '#0F172A',
          borderRight: '1px solid #1E293B',
          position: 'fixed',
          top: 0,
          left: 0,
          bottom: 0,
          zIndex: 100,
          overflowY: 'auto',
          transition: 'all 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
        }}
      >
        {/* Brand Header */}
        <div
          onClick={() => navigate('/')}
          style={{
            height: 56,
            padding: collapsed ? '0 18px' : '0 16px',
            display: 'flex',
            alignItems: 'center',
            gap: 12,
            borderBottom: '1px solid #1E293B',
            cursor: 'pointer',
            background: '#0F172A',
          }}
        >
          <div
            style={{
              width: 34,
              height: 34,
              borderRadius: 8,
              background: '#0064E0',
              color: '#FFFFFF',
              display: 'grid',
              placeItems: 'center',
              fontWeight: 700,
              fontSize: 16,
              boxShadow: 'none',
              flexShrink: 0,
            }}
          >
            A
          </div>
          {!collapsed && (
            <div style={{ display: 'flex', alignItems: 'center', overflow: 'hidden' }}>
              <span style={{ color: '#F8FAFC', fontSize: 15, fontWeight: 700, letterSpacing: '0.02em' }}>
                Kế toán
              </span>
            </div>
          )}
        </div>

        {/* Quick Add Button */}
        {!collapsed && (
          <div style={{ padding: '12px 14px 6px' }}>
            <Dropdown menu={{ items: quickAddItems }} trigger={['click']} placement="bottomLeft">
              <Button
                type="default"
                icon={<PlusOutlined style={{ fontSize: 13, color: '#0064E0' }} />}
                style={{
                  width: '100%',
                  height: 36,
                  borderRadius: 8,
                  fontWeight: 600,
                  fontSize: 13,
                  background: 'rgba(255, 255, 255, 0.08)',
                  color: '#F8FAFC',
                  border: '1px solid rgba(255, 255, 255, 0.15)',
                  boxShadow: 'none',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 6,
                  transition: 'all 0.15s ease',
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.background = 'rgba(255, 255, 255, 0.15)';
                  e.currentTarget.style.borderColor = 'rgba(255, 255, 255, 0.3)';
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.background = 'rgba(255, 255, 255, 0.08)';
                  e.currentTarget.style.borderColor = 'rgba(255, 255, 255, 0.15)';
                }}
              >
                Tạo mới chứng từ
              </Button>
            </Dropdown>
          </div>
        )}

        {/* Sidebar Menu */}
        <Menu
          theme="dark"
          mode="inline"
          selectedKeys={activeMenuKey ? [activeMenuKey] : []}
          defaultOpenKeys={[]}
          onClick={({ key }) => {
            if (key !== 'purchase-group' && key !== 'sales-group') {
              navigate(key);
            }
          }}
          items={menuItems}
          style={{
            background: 'transparent',
            borderRight: 'none',
            padding: '6px 8px',
            fontSize: 13,
            fontWeight: 500,
          }}
        />

        {/* Flyout Mega Menu */}
        {activeFlyout && FLYOUT_CONFIGS[activeFlyout.key] && (
          <div 
            className="misa-flyout-menu"
            style={{ 
              top: activeFlyout.top,
              left: collapsed ? SIDEBAR_COLLAPSED_WIDTH + 8 : SIDEBAR_WIDTH + 8,
              background: '#FFFFFF',
              border: '1px solid #E5E7EB',
              borderRadius: 8,
              boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05)',
            }}
            onMouseEnter={handleMouseEnterFlyout}
            onMouseLeave={handleMouseLeaveFlyout}
          >
            <div className="misa-flyout-header" style={{ borderBottom: '1px solid #E5E7EB', paddingBottom: 8 }}>
              <span className="misa-flyout-title" style={{ color: '#1C1E21', fontWeight: 700 }}>
                {FLYOUT_CONFIGS[activeFlyout.key].title}
              </span>
              <Button 
                type="link" 
                size="small" 
                style={{ color: '#0064E0', fontWeight: 600 }}
                onClick={() => {
                  navigate(activeFlyout.key === 'purchase-group' ? '/purchase' : '/sales');
                  setActiveFlyout(null);
                }}
              >
                Vào phân hệ →
              </Button>
            </div>

            <div className="misa-flyout-body">
              {/* Cột 1: NGHIỆP VỤ */}
              <div>
                <div className="misa-flyout-col-title" style={{ color: '#666A72' }}>Nghiệp vụ</div>
                <div className="misa-flyout-list">
                  {FLYOUT_CONFIGS[activeFlyout.key].operations.map((op, idx) => (
                    <div 
                      key={`flyout-op-${idx}-${op.label}`}
                      className="misa-flyout-item"
                      style={{ borderRadius: 6, padding: '6px 8px' }}
                      onClick={() => {
                        if (!op.route) return;
                        navigate(op.route);
                        setActiveFlyout(null);
                      }}
                    >
                      <span>{op.label}</span>
                      {op.badge && (
                        <Tag color="blue" style={{ borderRadius: 4, margin: 0, fontSize: 10, lineHeight: '16px', padding: '0 4px' }}>
                          {op.badge}
                        </Tag>
                      )}
                    </div>
                  ))}
                </div>
              </div>

              {/* Cột 2: TIỆN ÍCH / DANH MỤC */}
              <div>
                <div className="misa-flyout-col-title" style={{ color: '#666A72' }}>Tiện ích & Danh mục</div>
                <div className="misa-flyout-list">
                  {FLYOUT_CONFIGS[activeFlyout.key].utilities.map((ut, idx) => (
                    <div 
                      key={`flyout-ut-${idx}-${ut.label}`}
                      className="misa-flyout-item"
                      style={{ borderRadius: 6, padding: '6px 8px' }}
                      onClick={() => {
                        if (!ut.route) return;
                        navigate(ut.route);
                        setActiveFlyout(null);
                      }}
                    >
                      <span>{ut.label}</span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>
        )}
      </Sider>

      {/* Main Container Layout */}
      <Layout 
        style={{ 
          marginLeft: collapsed ? SIDEBAR_COLLAPSED_WIDTH : SIDEBAR_WIDTH, 
          transition: 'all 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
          minHeight: '100vh',
          background: '#FFFFFF',
        }}
      >
        {/* Top Header Bar */}
        <Header 
          style={{ 
            height: 56, 
            padding: '0 20px', 
            background: '#FFFFFF',
            borderBottom: '1px solid #E5E7EB',
            position: 'sticky',
            top: 0,
            zIndex: 90,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            // Ant Design's header token supplies a large inherited line-height.
            // Reset it so user text cannot wrap into a tall flex item at narrow
            // widths and overlap the workspace tab bar below.
            lineHeight: 'normal',
            boxShadow: 'none',
          }}
        >
          {/* Leading: Sider Toggle & Breadcrumb */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
            <Button
              type="text"
              icon={collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}
              onClick={() => setCollapsed(!collapsed)}
              style={{
                width: 32,
                height: 32,
                borderRadius: 6,
                display: 'grid',
                placeItems: 'center',
                color: '#4B5563',
              }}
            />
            
            <div className="app-breadcrumb" style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13 }}>
              <span style={{ color: '#666A72', fontWeight: 500 }}>{breadcrumb.section}</span>
              <span style={{ color: '#E5E7EB' }}>/</span>
              <span style={{ color: '#1C1E21', fontWeight: 600 }}>{breadcrumb.page}</span>
              {breadcrumb.tag && (
                <Tag color="processing" style={{ borderRadius: 4, margin: 0, fontSize: 11, lineHeight: '18px', padding: '0 6px', fontWeight: 600 }}>
                  {breadcrumb.tag}
                </Tag>
              )}
            </div>
          </div>

          {/* Center / Search Trigger */}
          <div 
            onClick={() => setSearchModalOpen(true)}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              background: '#FAFBFC',
              border: '1px solid #E5E7EB',
              borderRadius: 8,
              padding: '0 12px',
              height: 34,
              boxSizing: 'border-box',
              cursor: 'pointer',
              width: 270,
              color: '#666A72',
              fontSize: 12,
              transition: 'all 0.15s ease',
            }}
          >
            <SearchOutlined style={{ fontSize: 13, color: '#9CA3AF' }} />
            <span style={{ flex: 1, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>Tìm nhanh chứng từ...</span>
            <span style={{ 
              background: '#FFFFFF', 
              border: '1px solid #E5E7EB', 
              borderRadius: 4, 
              padding: '0 5px', 
              fontSize: 10, 
              fontWeight: 600,
              color: '#4B5563',
              height: 18,
              lineHeight: '16px',
              display: 'inline-flex',
              alignItems: 'center',
            }}>
              Ctrl K
            </span>
          </div>

          {/* Trailing: Notifications & User */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
            <Button 
              type="text" 
              icon={<BellOutlined style={{ fontSize: 16, color: '#4B5563' }} />} 
              style={{ width: 34, height: 34, borderRadius: 6 }} 
            />

            <Dropdown menu={{ items: userMenu }} placement="bottomRight" trigger={['click']}>
              <div 
                className="app-user-menu-trigger"
                style={{ 
                  display: 'flex', 
                  alignItems: 'center',
                  gap: 8,
                  padding: '4px 8px',
                  minHeight: 36,
                  lineHeight: 'normal',
                  whiteSpace: 'nowrap',
                  borderRadius: 8,
                  cursor: 'pointer',
                  border: '1px solid transparent',
                  transition: 'all 0.15s ease',
                }}
              >
                <Avatar 
                  size={28} 
                  style={{ background: '#0064E0', fontWeight: 600, fontSize: 12 }} 
                  icon={<UserOutlined />} 
                />
                <span style={{ fontWeight: 600, fontSize: 13, color: '#1C1E21' }}>{displayUserName}</span>
                <DownOutlined style={{ fontSize: 10, color: '#666A72' }} />
              </div>
            </Dropdown>
          </div>
        </Header>

        {/* Dynamic Content Area */}
        <Content className="app-content" style={{ minHeight: 'calc(100vh - 56px)' }}>
          <Suspense fallback={<RouteLoadingFallback />}>
            <Outlet />
          </Suspense>
        </Content>
      </Layout>

      {/* Global Quick Command Palette (Ctrl+K) */}
      <Modal
        open={searchModalOpen}
        onCancel={() => {
          setSearchModalOpen(false);
          setSearchQuery('');
        }}
        footer={null}
        closable={false}
        width={560}
        styles={{ body: { padding: 0 } }}
        style={{ top: 80 }}
      >
        <div style={{ padding: '14px 18px', borderBottom: '1px solid #E5E7EB' }}>
          <Input
            autoFocus
            prefix={<SearchOutlined style={{ color: '#0064E0', fontSize: 16, marginRight: 6 }} />}
            placeholder="Tìm kiếm chứng từ, danh mục, phân hệ..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            style={{ border: 'none', boxShadow: 'none', fontSize: 14, padding: 0 }}
          />
        </div>

        <div style={{ maxHeight: 360, overflowY: 'auto', padding: '8px 10px' }}>
          {filteredSearch.length === 0 ? (
            <div style={{ textAlign: 'center', padding: '30px 0', color: '#9CA3AF', fontSize: 13 }}>
              Không tìm thấy kết quả phù hợp cho "{searchQuery}"
            </div>
          ) : (
            filteredSearch.map((item, idx) => (
              <div
                key={`search-item-${idx}`}
                onClick={() => {
                  navigate(item.route);
                  setSearchModalOpen(false);
                  setSearchQuery('');
                }}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  padding: '8px 12px',
                  borderRadius: 6,
                  cursor: 'pointer',
                  fontSize: 13,
                  color: '#1C1E21',
                  transition: 'all 0.1s ease',
                }}
                onMouseEnter={(e) => (e.currentTarget.style.background = '#F3F4F6')}
                onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
              >
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <RightOutlined style={{ fontSize: 10, color: '#0064E0' }} />
                  <span style={{ fontWeight: 500 }}>{item.label}</span>
                </div>
                <Tag color="default" style={{ borderRadius: 4, margin: 0, fontSize: 11 }}>
                  {item.category}
                </Tag>
              </div>
            ))
          )}
        </div>

        <div style={{ padding: '8px 18px', background: '#FAFBFC', borderTop: '1px solid #E5E7EB', display: 'flex', justifyContent: 'space-between', fontSize: 11, color: '#9CA3AF' }}>
          <span>Dùng phím <strong>↑</strong> <strong>↓</strong> để điều hướng</span>
          <span>Nhấn <strong>Esc</strong> để đóng</span>
        </div>
      </Modal>
    </Layout>
  );
};

export default MainLayout;
