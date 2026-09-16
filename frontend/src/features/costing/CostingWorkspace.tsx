import React from 'react';
import { Button, Space, Typography } from 'antd';
import { InfoCircleOutlined } from '@ant-design/icons';
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
        <div style={{ padding: '16px 20px', background: '#F8FAFC', borderRadius: 8, border: '1px solid #E2E8F0', marginBottom: 16 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                <InfoCircleOutlined style={{ color: 'var(--ui-primary, #0064E0)', fontSize: 16 }} />
                <Typography.Text strong style={{ fontSize: 14, color: '#1E293B' }}>
                    Tổng hợp chi phí theo mẫu cũ chưa khả dụng
                </Typography.Text>
            </div>
            <Typography.Text style={{ color: '#64748B', fontSize: 13, lineHeight: '1.6' }}>
                Màn hình cũ không có catalogue trung tâm chi phí/sản phẩm và không được phép hiển thị số liệu mẫu. Chỉ sử dụng màn hình tính giá thành khi máy chủ trả về dữ liệu phân bổ hợp lệ và mapping sản xuất còn hiệu lực.
            </Typography.Text>
        </div>
    </PageShell>
);

export default CostingWorkspace;
