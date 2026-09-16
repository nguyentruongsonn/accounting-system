import React from 'react';
import { Card, Tag, Typography } from 'antd';
import { SafetyCertificateOutlined, InfoCircleOutlined } from '@ant-design/icons';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';

/**
 * The backend currently exposes provider/lifecycle evidence only. It does not
 * expose a provider-sourced invoice list, issuance/cancellation endpoints, or
 * a legally effective invoice-template workflow.
 *
 * Keep this route visible for navigation, but do not render sample invoices or
 * actions that could be mistaken for persisted/issued documents.
 */
const InvoicesManagement: React.FC = () => (
    <PageShell title={<PageHeader eyebrow="Hóa đơn điện tử" title="Quản lý hóa đơn điện tử" description="Theo dõi phạm vi tích hợp và bằng chứng vòng đời hóa đơn." />}>
        <PageToolbar />
        <Card
            title={<><SafetyCertificateOutlined /> Boundary tích hợp hóa đơn</>}
            className="misa-w-full"
            style={{ borderRadius: 8, borderColor: '#E5E7EB' }}
        >
            <div style={{ padding: '16px 20px', background: '#F8FAFC', borderRadius: 8, border: '1px solid #E2E8F0', marginBottom: 16 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                    <InfoCircleOutlined style={{ color: 'var(--ui-primary, #0064E0)', fontSize: 16 }} />
                    <Typography.Text strong style={{ fontSize: 14, color: '#1E293B' }}>
                        Chưa có dữ liệu phát hành hóa đơn điện tử được máy chủ công bố
                    </Typography.Text>
                </div>
                <Typography.Text style={{ color: '#64748B', fontSize: 13, lineHeight: '1.6' }}>
                    Phiên bản hiện tại chỉ có boundary lưu bằng chứng vòng đời/provider; chưa có tích hợp nhà cung cấp, ký số, phát hành, hủy, tra cứu hoặc đồng bộ dữ liệu hóa đơn. Không hiển thị dữ liệu mẫu và không xác nhận hành động thành công khi chưa có bằng chứng server.
                </Typography.Text>
            </div>
            <div className="misa-flex-col-gap-8">
                <div><Tag color="default" style={{ borderRadius: 4, fontWeight: 600 }}>NOT IMPLEMENTED</Tag> Danh sách hóa đơn điện tử từ nhà cung cấp</div>
                <div><Tag color="default" style={{ borderRadius: 4, fontWeight: 600 }}>NOT IMPLEMENTED</Tag> Phát hành, hủy, điều chỉnh và thay thế có ký số</div>
                <div><Tag color="default" style={{ borderRadius: 4, fontWeight: 600 }}>NOT IMPLEMENTED</Tag> Mẫu hóa đơn, số hóa đơn và xác nhận hợp lệ</div>
            </div>
        </Card>
    </PageShell>
);

export default InvoicesManagement;
