import React from 'react';
import { Button, Space, Typography } from 'antd';
import { InfoCircleOutlined } from '@ant-design/icons';
import { Link } from 'react-router-dom';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';

/**
 * The internal pilot is cash-only. Keep legacy bank URLs resolvable so old
 * bookmarks do not produce a blank page, but never mount a deposit workflow
 * or imply that bank data is available for mutation in this scope.
 */
const BankCompatibilityBoundary: React.FC = () => (
  <PageShell
    title={(
      <PageHeader
        eyebrow="Tiền gửi & ngân hàng"
        title="Chưa thuộc phạm vi pilot"
        description="Các URL ngân hàng cũ vẫn được giữ để không làm hỏng liên kết, nhưng không mở workflow tiền gửi."
      />
    )}
    toolbar={(
      <PageToolbar
        actions={(
          <Space>
            <Link to="/cash"><Button type="primary">Mở phân hệ Tiền mặt</Button></Link>
            <Link to="/settings/account-catalogues"><Button>Thiết lập tài khoản</Button></Link>
          </Space>
        )}
      />
    )}
  >
    <div style={{ padding: '16px 20px', background: '#F8FAFC', borderRadius: 8, border: '1px solid #E2E8F0', marginBottom: 16 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
        <InfoCircleOutlined style={{ color: 'var(--ui-primary, #0064E0)', fontSize: 16 }} />
        <Typography.Text strong style={{ fontSize: 14, color: '#1E293B' }}>
          Chức năng tiền gửi đã ẩn trong hệ thống nội bộ
        </Typography.Text>
      </div>
      <Typography.Text style={{ color: '#64748B', fontSize: 13, lineHeight: '1.6' }}>
        Các URL ngân hàng cũ được giữ để tránh liên kết hỏng, nhưng không mở màn hình, không tải dữ liệu và không cho tạo/sửa/ghi sổ. Chỉ phân hệ Tiền mặt được sử dụng trong pilot hiện tại.
      </Typography.Text>
    </div>
  </PageShell>
);

export default BankCompatibilityBoundary;
