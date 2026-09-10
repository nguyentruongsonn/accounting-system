import React from 'react';
import { Alert, Button, Space } from 'antd';
import { Link } from 'react-router-dom';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';

/**
 * The legacy costing landing page used to render sample cost centres,
 * products and an empty table without loading a server contract. Keep the
 * route useful for old bookmarks, but direct users to the server-backed
 * allocation surface instead of presenting fabricated costing evidence.
 */
const CostingWorkspace: React.FC = () => (
    <PageShell
        title={<PageHeader eyebrow="Giá thành" title="Giá thành" description="Điều hướng tới kỳ tính giá thành do máy chủ cung cấp." />}
        toolbar={<PageToolbar actions={<Space><Link to="/costing/allocations"><Button type="primary">Mở kỳ tính giá thành</Button></Link><Link to="/inventory"><Button>Xem dữ liệu kho</Button></Link></Space>} />}
    >
        <Alert
            className="mb-4"
            type="warning"
            showIcon
            message="Tổng hợp chi phí theo mẫu cũ chưa khả dụng"
            description="Màn hình cũ không có catalogue trung tâm chi phí/sản phẩm và không được phép hiển thị số liệu mẫu. Chỉ sử dụng màn hình tính giá thành khi máy chủ trả về dữ liệu phân bổ hợp lệ và mapping sản xuất còn hiệu lực."
        />
    </PageShell>
);

export default CostingWorkspace;
