import React from 'react';
import { Alert, Button } from 'antd';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';

const SystemOptions: React.FC = () => {
  return (
    <PageShell
      title={<PageHeader eyebrow="Thiết lập" title="Tùy chọn hệ thống" description="Cấu hình vận hành chung của hệ thống kế toán." />}
      toolbar={<PageToolbar
        actions={<Button type="primary" className="misa-btn-primary" disabled>Lưu tùy chọn (chưa khả dụng)</Button>}
      />}
    >
      <Alert className="apple-section-gap" type="warning" showIcon message="Tùy chọn hệ thống chưa khả dụng" description="Chưa có API máy chủ cho đọc/lưu cấu hình hệ thống. Không lưu thay đổi cục bộ hoặc báo thành công giả." />
    </PageShell>
  );
};

export default SystemOptions;
