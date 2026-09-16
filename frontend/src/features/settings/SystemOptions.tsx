import React from 'react';
import { Button, Empty } from 'antd';
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
      <div className="apple-section-gap misa-text-center misa-py-12">
        <Empty
          image={Empty.PRESENTED_IMAGE_SIMPLE}
          description="Tùy chọn hệ thống chưa khả dụng. Chưa có API máy chủ cho đọc/lưu cấu hình hệ thống. Không lưu thay đổi cục bộ hoặc báo thành công giả."
        />
      </div>
    </PageShell>
  );
};

export default SystemOptions;
