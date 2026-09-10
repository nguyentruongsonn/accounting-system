import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ToastProvider from './ToastProvider';

const spies = vi.hoisted(() => ({
  api: { open: vi.fn() },
  staticOpen: vi.fn(),
  useMessage: vi.fn(),
}));

const { api, staticOpen, useMessage } = spies;

vi.mock('antd', () => ({
  message: { open: spies.staticOpen, useMessage: spies.useMessage },
}));

import { TOAST_CONFIG, TOAST_DURATION, toast } from './toast';

describe('toast presentation contract', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useMessage.mockReturnValue([api, <span data-testid="toast-holder" />]);
  });

  it('mounts the hook-scoped top-right message host', () => {
    render(<ToastProvider><span>ERP</span></ToastProvider>);

    expect(screen.getByTestId('toast-holder')).toBeInTheDocument();
    expect(TOAST_CONFIG).toEqual({ duration: TOAST_DURATION, maxCount: 4, top: 24 });
    expect(useMessage).toHaveBeenCalledWith(TOAST_CONFIG);
  });

  it('uses the static boundary until the provider API is available', () => {
    toast.info('Đang khởi tạo');

    expect(staticOpen).toHaveBeenCalledWith({ type: 'info', content: 'Đang khởi tạo', duration: TOAST_DURATION });
  });

  it('forwards all feedback through the top-right five-second adapter', () => {
    render(<ToastProvider><span>ERP</span></ToastProvider>);
    toast.success('Đã cất');
    toast.info('Chưa khả dụng');
    toast.warning('Cần kiểm tra');
    toast.error('Không thể thực hiện');

    expect(api.open).toHaveBeenNthCalledWith(1, { type: 'success', content: 'Đã cất', duration: TOAST_DURATION });
    expect(api.open).toHaveBeenNthCalledWith(2, { type: 'info', content: 'Chưa khả dụng', duration: TOAST_DURATION });
    expect(api.open).toHaveBeenNthCalledWith(3, { type: 'warning', content: 'Cần kiểm tra', duration: TOAST_DURATION });
    expect(api.open).toHaveBeenNthCalledWith(4, { type: 'error', content: 'Không thể thực hiện', duration: TOAST_DURATION });
  });

  it('preserves an explicit duration override', () => {
    render(<ToastProvider><span>ERP</span></ToastProvider>);
    toast.success('Lâu hơn', { duration: 8 });
    expect(api.open).toHaveBeenLastCalledWith({ type: 'success', content: 'Lâu hơn', duration: 8 });
  });

});
