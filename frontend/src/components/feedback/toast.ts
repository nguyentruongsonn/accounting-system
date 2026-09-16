import { message } from 'antd';
import type { ConfigOptions, MessageInstance, NoticeType } from 'antd/es/message/interface';
import type { ReactNode } from 'react';

export const TOAST_DURATION = 5;
export const TOAST_CONFIG = {
  duration: TOAST_DURATION,
  maxCount: 4,
  top: 24,
} satisfies ConfigOptions;

export type ToastOptions = {
  duration?: number;
};

const duration = (input?: ToastOptions) => input?.duration ?? TOAST_DURATION;

let toastApi: MessageInstance | null = null;

export const bindToastApi = (api: MessageInstance) => {
  toastApi = api;

  return () => {
    if (toastApi === api) toastApi = null;
  };
};

const present = (type: NoticeType, content: ReactNode, input?: ToastOptions) => {
  const args = { type, content, duration: duration(input) };
  if (toastApi) return toastApi.open(args);

  // Keep standalone components and existing integrations compatible when they
  // are rendered outside the application ToastProvider. The application path
  // above remains the preferred context-aware API; this fallback also keeps
  // Ant Design's familiar one-argument notification contract.
  const legacyMethod = message[type];
  return typeof legacyMethod === 'function' ? legacyMethod(content) : message.open(args);
};

export const toast = {
  success(content: ReactNode, input?: ToastOptions) {
    return present('success', content, input);
  },
  info(content: ReactNode, input?: ToastOptions) {
    return present('info', content, input);
  },
  warning(content: ReactNode, input?: ToastOptions) {
    return present('warning', content, input);
  },
  error(content: ReactNode, input?: ToastOptions) {
    return present('error', content, input);
  },
};
