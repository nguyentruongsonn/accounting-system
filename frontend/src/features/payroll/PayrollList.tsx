import { useState } from 'react';
import { Alert, Table, Typography, Form, DatePicker, Button, Tag, Tooltip } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { ReloadOutlined, CheckCircleOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import api from '../../api/axios';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

const { Text } = Typography;

type PayrollRecord = {
    id: number;
    month: string;
    voucher_number: string;
    voucher_date: string;
    posting_date: string;
    description?: string | null;
    total_amount: number | string;
    is_posted: boolean;
};

function money(value: number | string): string {
    return Number(value || 0).toLocaleString('vi-VN');
}

export default function PayrollList() {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [data, setData] = useState<PayrollRecord[]>([]);
    const [loadError, setLoadError] = useState(false);
    const [postingId, setPostingId] = useState<number | null>(null);

    const loadPayrolls = async (values: { period: dayjs.Dayjs }) => {
        setLoading(true);
        setLoadError(false);
        try {
            const response = await api.get('/payroll');
            const payload = response.data?.data ?? response.data;
            // PayrollController@index returns the tenant-scoped collection
            // directly.  A malformed 2xx response must not look like a valid
            // empty payroll list.
            if (!Array.isArray(payload)) {
                throw new Error('Máy chủ trả về danh sách bảng lương không hợp lệ.');
            }
            const records = payload;
            const month = values.period.format('YYYY-MM');

            setData(records.filter((record: PayrollRecord) => record.month === month));
            if (records.length === 0) {
                message.info('Chưa có bảng lương được lập trong tenant hiện tại.');
            }
        } catch (error) {
            setLoadError(true);
            message.error('Không thể tải bảng lương đã lập.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const retryLoad = () => {
        const period = form.getFieldValue('period');
        if (period) void loadPayrolls({ period });
    };

    const handlePost = async (record: PayrollRecord) => {
        setPostingId(record.id);
        try {
            const response = await api.post(`/payroll/${record.id}/post`);
            if (response?.data?.data?.id === undefined || response?.data?.data?.id === null) {
                throw new Error('Máy chủ không trả về bảng lương đã ghi sổ.');
            }
            message.success(`Đã ghi sổ bảng lương ${record.voucher_number}.`);
            await loadPayrolls({ period: form.getFieldValue('period') });
        } catch (error) {
            message.error('Không thể ghi sổ bảng lương.');
            console.error(error);
        } finally {
            setPostingId(null);
        }
    };

    const columns = [
        { title: 'Kỳ lương', dataIndex: 'month', key: 'month' },
        { title: 'Số chứng từ', dataIndex: 'voucher_number', key: 'voucher_number' },
        { title: 'Ngày chứng từ', dataIndex: 'voucher_date', key: 'voucher_date' },
        { title: 'Ngày hạch toán', dataIndex: 'posting_date', key: 'posting_date' },
        { title: 'Diễn giải', dataIndex: 'description', key: 'description', render: (value: string | null) => value || '—' },
        {
            title: 'Tổng thực nhận',
            dataIndex: 'total_amount',
            key: 'total_amount',
            align: 'right' as const,
            render: (value: number | string) => money(value),
        },
        {
            title: 'Trạng thái',
            dataIndex: 'is_posted',
            key: 'is_posted',
            align: 'center' as const,
            render: (posted: boolean) => <Tag color={posted ? 'green' : 'default'}>{posted ? 'Đã ghi sổ' : 'Bản nháp'}</Tag>,
        },
        {
            title: 'Chức năng',
            key: 'actions',
            render: (_: unknown, record: PayrollRecord) => !record.is_posted && (
                <Button
                    type="link"
                    icon={<CheckCircleOutlined />}
                    loading={postingId === record.id}
                    onClick={() => handlePost(record)}
                >
                    Ghi sổ
                </Button>
            ),
        },
    ];

    return (
        <PageShell title={<PageHeader eyebrow="Tiền lương" title="Bảng lương đã lập" description="Xem và ghi sổ bảng lương đã được lập từ quy trình chứng từ payroll." />}>
            <PageToolbar filters={(

            <div className="mb-4">
                <Form
                    form={form}
                    layout="inline"
                    onFinish={loadPayrolls}
                    initialValues={{ period: dayjs() }}
                >
                    <Form.Item name="period" label="Kỳ lương" rules={[{ required: true }]}>
                        <DatePicker picker="month" format="MM/YYYY" placeholder="Chọn tháng" />
                    </Form.Item>
                    <Form.Item>
                        <Tooltip title="Tải các bảng lương đã lập trong tenant hiện tại">
                            <Button type="primary" htmlType="submit" className="misa-btn-primary" icon={<ReloadOutlined />} loading={loading}>
                                Tải bảng lương
                            </Button>
                        </Tooltip>
                    </Form.Item>
                </Form>
            </div>
            )} />

            {loadError && <Alert
                className="mb-4"
                type="error"
                showIcon
                message="Không thể tải bảng lương"
                description="Các dòng đang hiển thị được giữ nguyên; máy chủ chưa trả về dữ liệu mới hợp lệ."
                action={<Button size="small" onClick={retryLoad}>Thử lại</Button>}
            />}
            <DataTableSurface className="payroll-list-table-surface">
            <Table
                columns={columns}
                dataSource={data}
                rowKey="id"
                pagination={false}
                loading={loading}
                size="middle"
                className="misa-voucher-table"
                locale={{ emptyText: loadError ? 'Không có dữ liệu mới do lỗi tải.' : 'Chưa có bảng lương đã lập trong kỳ này.' }}
            />
            </DataTableSurface>

            <Text type="secondary" className="mt-3 block">
                Tính lương tự động chưa được xác nhận trong backend; màn hình không tự tạo số liệu hoặc bút toán.
            </Text>
        </PageShell>
    );
}
