import React from 'react';
import { Alert, Card, Tag } from 'antd';
import { SafetyCertificateOutlined } from '@ant-design/icons';
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
        >
            <Alert
                type="warning"
                showIcon
                message="Chưa có dữ liệu phát hành hóa đơn điện tử được máy chủ công bố"
                description="Phiên bản hiện tại chỉ có boundary lưu bằng chứng vòng đời/provider; chưa có tích hợp nhà cung cấp, ký số, phát hành, hủy, tra cứu hoặc đồng bộ dữ liệu hóa đơn. Không hiển thị dữ liệu mẫu và không xác nhận hành động thành công khi chưa có bằng chứng server."
            />
            <div className="misa-flex-col-gap-8 misa-mt-16">
                <div><Tag color="orange">NOT IMPLEMENTED</Tag> Danh sách hóa đơn điện tử từ nhà cung cấp</div>
                <div><Tag color="orange">NOT IMPLEMENTED</Tag> Phát hành, hủy, điều chỉnh và thay thế có ký số</div>
                <div><Tag color="orange">NOT IMPLEMENTED</Tag> Mẫu hóa đơn, số hóa đơn và xác nhận hợp lệ</div>
            </div>
        </Card>
    </PageShell>
);

export default InvoicesManagement;
