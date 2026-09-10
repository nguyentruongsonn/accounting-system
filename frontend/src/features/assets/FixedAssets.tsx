import { useEffect, useState } from 'react';
import { Table, Typography, Button, Space, Tag, Tooltip, Alert } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { SettingOutlined, SyncOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

const { Text } = Typography;

interface FixedAssetRecord {
    id: number;
    asset_code: string;
    asset_name: string;
    original_cost: number | string | null;
    accumulated_depreciation: number | string | null;
    net_value: number | string | null;
    monthly_depreciation: number | string | null;
    status?: string;
    is_active?: boolean;
}

function formatAmount(value: number | string | null | undefined, negative = false): string {
    if (value === null || value === undefined || value === '') return '—';
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '—';
    return `${negative ? '-' : ''}${amount.toLocaleString('vi-VN')}`;
}

export default function FixedAssets() {
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState<FixedAssetRecord[]>([]);
    const [loadError, setLoadError] = useState(false);

    const loadAssets = async () => {
        setLoading(true);
        setLoadError(false);
        try {
            const { data: responseData } = await api.get('/fixed-assets');
            const payload: unknown = Array.isArray(responseData)
                ? responseData
                : (responseData as { data?: unknown } | null)?.data;
            if (!Array.isArray(payload)) {
                throw new Error('Invalid fixed-asset list response');
            }
            setData(payload as FixedAssetRecord[]);
            setLoadError(false);
        } catch (error) {
            setLoadError(true);
            message.error('Không thể tải danh sách tài sản cố định từ máy chủ.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        void loadAssets();
    }, []);

    const handleRunDepreciation = async () => {
        setLoading(true);
        try {
            const response = await api.post('/fixed-assets/depreciate', {
                month: dayjs().format('YYYY-MM')
            });

            const depreciationLog = response?.data?.data;
            if (!depreciationLog || depreciationLog.id === undefined || depreciationLog.id === null) {
                message.error('Máy chủ không trả về kỳ khấu hao đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success(`Khấu hao tháng ${dayjs().format('MM/YYYY')} đã chạy và tự động hạch toán thành công!`);
            await loadAssets();
        } catch (error) {
            message.error('Có lỗi xảy ra khi tính khấu hao.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const columns = [
        {
            title: 'Mã Tài sản',
            dataIndex: 'asset_code',
            key: 'asset_code',
            render: (text: string) => <a>{text}</a>,
        },
        {
            title: 'Tên Tài sản',
            dataIndex: 'asset_name',
            key: 'asset_name',
            render: (text: string) => <strong>{text}</strong>,
        },
        {
            title: 'Nguyên giá',
            dataIndex: 'original_cost',
            key: 'original_cost',
            align: 'right' as const,
            render: (val: number | string | null) => formatAmount(val),
        },
        {
            title: 'Khấu hao lũy kế',
            dataIndex: 'accumulated_depreciation',
            key: 'accumulated_depreciation',
            align: 'right' as const,
            render: (val: number | string | null) => <Text type="danger">{formatAmount(val, true)}</Text>,
        },
        {
            title: 'Giá trị còn lại',
            dataIndex: 'net_value',
            key: 'net_value',
            align: 'right' as const,
            render: (val: number | string | null) => <Text type="success">{formatAmount(val)}</Text>,
        },
        {
            title: 'Khấu hao hàng tháng',
            dataIndex: 'monthly_depreciation',
            key: 'monthly_depreciation',
            align: 'right' as const,
            render: (val: number | string | null) => formatAmount(val),
        },
        {
            title: 'Trạng thái',
            key: 'status',
            dataIndex: 'status',
            align: 'center' as const,
            render: (status: string, record: FixedAssetRecord) => (
                <Tag color={status === 'active' ? 'blue' : 'default'}>
                    {record.is_active === false ? 'Ngừng hoạt động' : (status || 'Chưa xác định')}
                </Tag>
            ),
        },
        {
            title: 'Thao tác',
            key: 'action',
            render: () => (
                <Space size="middle">
                    <Tooltip title="Chỉnh sửa TSCĐ (chưa khả dụng)"><Button type="text" className="misa-btn-tool" icon={<SettingOutlined />} disabled /></Tooltip>
                </Space>
            ),
        },
    ];

    return (
        <PageShell
            title={<PageHeader
                eyebrow="Tài sản & khấu hao"
                title="Tài sản cố định"
                description="Theo dõi nguyên giá, khấu hao và giá trị còn lại từ dữ liệu máy chủ."
            />}
            toolbar={<PageToolbar actions={<Button type="primary" onClick={handleRunDepreciation} icon={<SyncOutlined />} loading={loading} className="misa-btn-primary">
                    Chạy Khấu hao tháng {dayjs().format('MM')}
                </Button>} />}
        >

            {loadError && (
                <Alert
                    className="mb-4"
                    type="error"
                    showIcon
                    message="Không thể tải dữ liệu tài sản cố định"
                    description="Danh sách không thay thế bằng dữ liệu mẫu. Hãy thử lại khi máy chủ sẵn sàng."
                    action={<Button size="small" onClick={() => void loadAssets()}>Thử lại</Button>}
                />
            )}

            <DataTableSurface>
                <Table
                    columns={columns}
                    dataSource={data}
                    loading={loading}
                    locale={{ emptyText: loadError ? 'Không có dữ liệu do lỗi tải.' : 'Chưa có tài sản cố định.' }}
                    rowKey="id"
                    pagination={{ pageSize: 10 }}
                    size="middle"
                    className="misa-voucher-table"
                />
            </DataTableSurface>
        </PageShell>
    );
}
