import { useEffect } from 'react';
import { ConfigProvider } from 'antd';
import viVN from 'antd/locale/vi_VN';
import { QueryClientProvider } from '@tanstack/react-query';
import { AppRoutes } from './routes';
import ErrorBoundary from './components/common/ErrorBoundary';
import { queryClient } from './lib/queryClient';
import api from './api/axios';
import { useAuthStore } from './store/useAuthStore';
import { AdaptivePopupViewport } from './components/layout/AdaptivePopupViewport';
import ToastProvider from './components/feedback/ToastProvider';
import { TOAST_CONFIG } from './components/feedback/toast';

/**
 * A persisted bearer token is not enough for permission-gated screens: the
 * in-memory user object is empty after a hard refresh. Rehydrate it from the
 * server before relying on client-side permission gates. A failed refresh
 * invalidates the stale token instead of leaving a misleading half-session.
 */
function AuthSessionBootstrap() {
  const token = useAuthStore((state) => state.token);
  const user = useAuthStore((state) => state.user);
  const setAuth = useAuthStore((state) => state.setAuth);
  const logout = useAuthStore((state) => state.logout);

  useEffect(() => {
    if (!token || user) return;

    let active = true;
    api.get('/auth/user')
      .then(({ data }) => {
        if (active && data && typeof data.id === 'number') setAuth(data, token);
      })
      .catch(() => {
        if (active) logout();
      });

    return () => { active = false; };
  }, [logout, setAuth, token, user]);

  return null;
}

function App() {
  return (
    <ErrorBoundary>
      <ConfigProvider
        locale={viVN}
        // Keep popup geometry independent from modal/table overflow clipping.
        // rc-trigger still measures the real trigger and automatically flips
        // between top/bottom while listening for scroll, resize and zoom
        // (visualViewport) changes.
        getPopupContainer={() => document.body}
        popupOverflow="viewport"
      theme={{
        token: {
          colorPrimary: '#0064E0',
          colorInfo: '#0064E0',
          colorSuccess: '#10B981',
          colorWarning: '#F59E0B',
          colorError: '#EF4444',
          fontFamily: "'Optimistic', Helvetica, Arial, sans-serif",
          fontSize: 13,
          borderRadius: 8,
          controlHeight: 34,
          colorText: '#1C1E21',
          colorTextSecondary: '#666A72',
          colorTextPlaceholder: '#9CA3AF',
          colorBorder: '#E5E7EB',
          colorLink: '#0064E0',
          colorBgContainer: '#FFFFFF',
          colorBgLayout: '#FFFFFF',
        },
        components: {
          Table: {
            cellPaddingBlockSM: 6,
            cellPaddingInlineSM: 10,
            headerBg: '#F9FAFB',
            headerColor: '#4B5563',
            headerSortActiveBg: '#EBF5FF',
            rowHoverBg: '#F9FAFB',
            borderRadius: 8,
            headerSplitColor: '#E5E7EB',
            borderColor: '#E5E7EB',
          },
          Select: {
            controlHeight: 34,
            borderRadius: 8,
            fontSize: 13,
            colorBgContainer: '#FFFFFF',
            colorBorder: '#E5E7EB',
            colorPrimary: '#0064E0',
            colorPrimaryHover: '#0057C2',
            controlOutline: 'rgba(0, 100, 224, 0.15)',
          },
          Input: {
            controlHeight: 34,
            borderRadius: 8,
            fontSize: 13,
            colorBgContainer: '#FFFFFF',
            colorBorder: '#E5E7EB',
            colorPrimary: '#0064E0',
            colorPrimaryHover: '#0057C2',
          },
          InputNumber: {
            controlHeight: 34,
            borderRadius: 8,
            fontSize: 13,
            colorBgContainer: '#FFFFFF',
            colorBorder: '#E5E7EB',
            colorPrimary: '#0064E0',
            colorPrimaryHover: '#0057C2',
          },
          DatePicker: {
            controlHeight: 34,
            borderRadius: 8,
            fontSize: 13,
            colorBgContainer: '#FFFFFF',
            colorBorder: '#E5E7EB',
            colorPrimary: '#0064E0',
            colorPrimaryHover: '#0057C2',
          },
          Menu: {
            itemHeight: 40,
            iconSize: 16,
            itemBorderRadius: 6,
            itemColor: '#9CA3AF',
            itemHoverColor: '#FFFFFF',
            itemSelectedColor: '#FFFFFF',
            itemSelectedBg: '#0064E0',
            darkItemBg: '#0F172A',
            darkItemColor: '#9CA3AF',
            darkItemSelectedBg: '#0064E0',
            darkItemSelectedColor: '#FFFFFF',
          },
          Button: {
            primaryShadow: 'none',
            borderRadius: 8,
            fontWeight: 500,
            controlHeight: 34,
            colorPrimary: '#0064E0',
            colorPrimaryHover: '#0057C2',
            colorPrimaryActive: '#004BB5',
            defaultBorderColor: '#E5E7EB',
            defaultColor: '#1C1E21',
            defaultBg: '#FFFFFF',
          },
          Modal: {
            borderRadiusLG: 8,
            boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05)',
          },
          Card: {
            borderRadiusLG: 8,
            boxShadowTertiary: 'none',
          },
          Tabs: {
            itemSelectedColor: '#0064E0',
            itemHoverColor: '#0057C2',
            inkBarColor: '#0064E0',
          }
        },
      }}
    >
      <ToastProvider config={TOAST_CONFIG}>
        <QueryClientProvider client={queryClient}>
          <AdaptivePopupViewport />
          <AuthSessionBootstrap />
          <AppRoutes />
        </QueryClientProvider>
      </ToastProvider>
    </ConfigProvider>
    </ErrorBoundary>
  );
}

export default App;
