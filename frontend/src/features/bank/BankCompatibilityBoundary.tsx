import React from 'react';
import { Alert, Button, Space } from 'antd';
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
    <Alert
      className="mb-4"
      type="warning"
      showIcon
      message="Chức năng tiền gửi đã ẩn trong hệ thống nội bộ"
      description="Các URL ngân hàng cũ được giữ để tránh liên kết hỏng, nhưng không mở màn hình, không tải dữ liệu và không cho tạo/sửa/ghi sổ. Chỉ phân hệ Tiền mặt được sử dụng trong pilot hiện tại."
    />
  </PageShell>
);

export default BankCompatibilityBoundary;
