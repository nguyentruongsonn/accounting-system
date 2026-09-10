import { useState } from 'react';
import { Table, Typography, Button, Space, Tag, Tooltip, Alert } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { ToolOutlined, SettingOutlined, SyncOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

const { Text } = Typography;

export default function Tools() {
    const [loading, setLoading] = useState(false);
    const [data] = useState<any[]>([]);

    const handleRunAllocation = async () => {
        setLoading(true);
        try {
            const response = await api.post('/tools/allocate', {
                month: dayjs().format('YYYY-MM')
            });

            const allocationLog = response?.data?.data;
            // The endpoint returns `data: null` when there are no active tools
            // to allocate. A 2xx response alone is not evidence that an
            // allocation log or GL posting was persisted.
            if (!allocationLog || allocationLog.id === undefined || allocationLog.id === null) {
                message.info('Không có CCDC đủ điều kiện để phân bổ trong kỳ đã chọn.');
                return;
            }

            message.success(`Phân bổ CCDC tháng ${dayjs().format('MM/YYYY')} đã chạy và tự động hạch toán thành công!`);
        } catch (error) {
            message.error('Có lỗi xảy ra khi tính phân bổ.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const columns = [
        {
            title: 'Mã CCDC',
            dataIndex: 'toolCode',
            key: 'toolCode',
            render: (text: string) => <a>{text}</a>,
        },
        {
            title: 'Tên CCDC',
            dataIndex: 'name',
            key: 'name',
            render: (text: string) => <strong>{text}</strong>,
        },
        {
            title: 'Nguyên giá',
            dataIndex: 'originalValue',
            key: 'originalValue',
            align: 'right' as const,
            render: (val: number) => val.toLocaleString('vi-VN'),
        },
        {
            title: 'Đã phân bổ',
            dataIndex: 'allocatedAmount',
            key: 'allocatedAmount',
            align: 'right' as const,
            render: (val: number) => <Text type="danger">-{val.toLocaleString('vi-VN')}</Text>,
        },
        {
            title: 'Giá trị còn lại',
            dataIndex: 'remainingValue',
            key: 'remainingValue',
            align: 'right' as const,
            render: (val: number) => <Text type="success">{val.toLocaleString('vi-VN')}</Text>,
        },
        {
            title: 'Kỳ phân bổ',
            dataIndex: 'periods',
            key: 'periods',
            align: 'center' as const,
            render: (val: number, record: any) => `${record.periodsRemaining}/${val} tháng`,
        },
        {
            title: 'Phân bổ hàng tháng',
            dataIndex: 'monthlyAllocation',
            key: 'monthlyAllocation',
            align: 'right' as const,
            render: (val: number) => val.toLocaleString('vi-VN'),
        },
        {
            title: 'Trạng thái',
            key: 'status',
            dataIndex: 'status',
            align: 'center' as const,
            render: (status: string) => (
                <Tag color={status === 'active' ? 'purple' : 'default'}>
                    {status === 'active' ? 'Đang phân bổ' : 'Đã phân bổ hết'}
                </Tag>
            ),
        },
        {
            title: 'Thao tác',
            key: 'action',
            render: () => (
                <Space size="middle">
                    <Tooltip title="Chỉnh sửa CCDC (chưa khả dụng)"><Button type="text" className="misa-btn-tool" icon={<SettingOutlined />} disabled /></Tooltip>
                </Space>
            ),
        },
    ];

    return (
        <PageShell
            title={<PageHeader
            eyebrow="Tài sản & phân bổ"
            title={<><ToolOutlined className="mr-2 text-purple-600" />Công cụ dụng cụ</>}
            description="Quản lý chi phí trả trước (242) và phân bổ CCDC tự động hàng tháng."
        />}
            toolbar={<PageToolbar actions={<Button type="primary" onClick={handleRunAllocation} icon={<SyncOutlined />} loading={loading} className="misa-btn-primary">
                Chạy Phân bổ tháng {dayjs().format('MM')}
            </Button>} />}
        >

            <Alert
                className="mb-4"
                type="warning"
                showIcon
                message="Danh sách CCDC chưa khả dụng"
                description="Backend hiện chỉ công bố endpoint phân bổ CCDC, chưa công bố endpoint truy vấn danh sách. Giao diện không hiển thị dữ liệu mẫu."
            />

            <DataTableSurface>
                <Table
                    columns={columns}
                    dataSource={data}
                    rowKey="id"
                    pagination={{ pageSize: 10 }}
                    size="middle"
                    className="misa-voucher-table"
                    locale={{ emptyText: 'Backend chưa công bố dữ liệu CCDC.' }}
                />
            </DataTableSurface>
        </PageShell>
    );
}
