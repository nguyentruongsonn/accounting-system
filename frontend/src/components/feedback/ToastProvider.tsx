import React, { useEffect } from 'react';
import { message } from 'antd';
import type { ConfigOptions } from 'antd/es/message/interface';
import { bindToastApi, TOAST_CONFIG } from './toast';

type ToastProviderProps = {
  children: React.ReactNode;
  config?: ConfigOptions;
};

const ToastProvider: React.FC<ToastProviderProps> = ({ children, config = TOAST_CONFIG }) => {
  const [api, contextHolder] = message.useMessage(config);

  useEffect(() => {
    return bindToastApi(api);
  }, [api]);

  return <>{contextHolder}{children}</>;
};

export default ToastProvider;
