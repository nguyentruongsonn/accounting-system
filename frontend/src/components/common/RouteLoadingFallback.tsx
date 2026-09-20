import { Spin } from 'antd';

interface RouteLoadingFallbackProps {
  fullScreen?: boolean;
}

const RouteLoadingFallback = ({ fullScreen = false }: RouteLoadingFallbackProps) => (
  <div
    role="status"
    aria-live="polite"
    aria-label="Đang tải màn hình"
    className={`route-loading-fallback flex w-full items-center justify-center gap-3 text-slate-600 ${
      fullScreen ? 'min-h-screen bg-slate-50' : 'min-h-60'
    }`}
  >
    <Spin size="large" />
    <span>Đang tải...</span>
  </div>
);

export default RouteLoadingFallback;
