import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Button, Result } from 'antd';
import { ReloadOutlined, WarningOutlined } from '@ant-design/icons';

interface Props {
  children?: ReactNode;
  fallback?: ReactNode;
}

interface State {
  hasError: boolean;
  error?: Error;
  errorInfo?: ErrorInfo;
}

export class ErrorBoundary extends Component<Props, State> {
  public state: State = {
    hasError: false
  };

  public static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error('Uncaught React Error caught by ErrorBoundary:', error, errorInfo);
  }

  private handleReset = () => {
    this.setState({ hasError: false, error: undefined, errorInfo: undefined });
  };

  private handleReload = () => {
    window.location.reload();
  };

  public render() {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback;
      }

      return (
        <div style={{ padding: '40px 20px', display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '400px' }}>
          <Result
            status="error"
            icon={<WarningOutlined style={{ color: '#faad14' }} />}
            title="Đã xảy ra lỗi giao diện"
            subTitle={
              <div>
                <p style={{ color: '#4b5563', marginBottom: 12 }}>
                  Hệ thống gặp sự cố hiển thị component: <strong>{this.state.error?.message || 'Lỗi không xác định'}</strong>
                </p>
                <div style={{
                  background: '#f9fafb',
                  border: '1px solid #e5e7eb',
                  borderRadius: 6,
                  padding: '8px 12px',
                  fontSize: 12,
                  color: '#ef4444',
                  fontFamily: 'monospace',
                  textAlign: 'left',
                  maxHeight: 120,
                  overflowY: 'auto'
                }}>
                  {this.state.error?.stack || this.state.error?.toString()}
                </div>
              </div>
            }
            extra={[
              <Button key="reset" type="primary" onClick={this.handleReset} style={{ borderRadius: 4 }}>
                Thử lại
              </Button>,
              <Button key="reload" icon={<ReloadOutlined />} onClick={this.handleReload} style={{ borderRadius: 4 }}>
                Tải lại trang
              </Button>
            ]}
          />
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
