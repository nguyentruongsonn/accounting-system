import { useState } from 'react';
import { Card, Table, Typography, Form, DatePicker, Button, Space } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { PieChartOutlined, SearchOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import api from '../../api/axios';
import ManagementReportGate from '../reports/ManagementReportGate';
import ManagementReportDataError from '../reports/ManagementReportDataError';

const { Title, Text } = Typography;

function BudgetReportContent() {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    type BudgetReportLine = {
        account_code: string;
        total_budget: string | number;
        total_actual: string | number;
        total_variance: string | number;
    };
    const [data, setData] = useState<BudgetReportLine[]>([]);
    const [lastRequestedValues, setLastRequestedValues] = useState<any>(null);
    const [loadError, setLoadError] = useState(false);
    const [reportError, setReportError] = useState<unknown>(undefined);

    const formatOperationalAmount = (value: string | number | null | undefined) => {
        if (typeof value === 'number') {
            return value.toLocaleString('vi-VN');
        }

        // Preserve the server's decimal text rather than coercing it through a
        // browser float. This operational draft has no approved money/display
        // definition yet.
        return value ?? '—';
    };

    const handleSearch = async (values: any) => {
        setLastRequestedValues(values);
        setLoadError(false);
        setReportError(undefined);
        setLoading(true);
        try {
            const year = values.year.format('YYYY');
            const response = await api.get('/budgets/report', {
                params: { year },
            });

            const apiData = response.data.data;
            if (!Array.isArray(apiData)) {
                throw new Error('Máy chủ không trả về dữ liệu báo cáo ngân sách hợp lệ.');
            }
            setData(apiData);
            message.success('Đã tải dữ liệu phân tích ngân sách vận hành.');
        } catch (error) {
            setLoadError(true);
            setReportError(error);
            message.error('Lỗi khi tải báo cáo.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const columns = [
        {
            title: 'Tài khoản',
            dataIndex: 'account_code',
            key: 'account_code',
            width: 100,
            render: (text: string) => <strong>{text}</strong>,
        },
        {
            title: 'Kế hoạch theo dữ liệu hiện có',
            dataIndex: 'total_budget',
            key: 'total_budget',
            align: 'right' as const,
            render: formatOperationalAmount,
        },
        {
            title: 'Thực tế theo mapping hiện tại',
            dataIndex: 'total_actual',
            key: 'total_actual',
            align: 'right' as const,
            render: (val: string | number) => <Text strong>{formatOperationalAmount(val)}</Text>,
        },
        {
            title: 'Phương sai (API hiện tại)',
            dataIndex: 'total_variance',
            key: 'total_variance',
            align: 'right' as const,
            render: (val: string | number) => <Text>{formatOperationalAmount(val)}</Text>,
        },
    ];

    return (
        <Space direction="vertical" className="w-full apple-feature-page" size="large">
            <div className="flex justify-between items-center apple-feature-heading">
                <div>
                    <Title level={3} className="!mb-1">
                        <PieChartOutlined className="mr-2 text-pink-600" />
                        Phân tích ngân sách (bản vận hành)
                    </Title>
                    <Text type="secondary">Hiển thị tổng hợp vận hành từ contract API hiện tại; phương sai và cách đối chiếu chưa phải định nghĩa ngân sách được phê duyệt.</Text>
                </div>
            </div>

            <Card className="shadow-sm">
                <Form
                    form={form}
                    layout="inline"
                    onFinish={handleSearch}
                    initialValues={{
                        year: dayjs(),
                    }}
                >
                    <Form.Item name="year" label="Năm dương lịch (bản vận hành)" rules={[{ required: true }]}>
                        <DatePicker picker="year" format="YYYY" placeholder="Chọn năm" />
                    </Form.Item>
                    <Form.Item>
                        <Button type="primary" htmlType="submit" icon={<SearchOutlined />} loading={loading} className="misa-btn-primary">
                            Xem báo cáo
                        </Button>
                    </Form.Item>
                </Form>
            </Card>

            <Card className="shadow-sm" styles={{ body: { padding: 0 } }}>
                {loadError ? (
                    <ManagementReportDataError
                        reportName="báo cáo ngân sách vận hành"
                        onRetry={() => lastRequestedValues ? handleSearch(lastRequestedValues) : Promise.resolve()}
                        error={reportError}
                    />
                ) : (
                    <Table
                        columns={columns}
                        dataSource={data}
                        rowKey="account_code"
                        pagination={false}
                        loading={loading}
                        size="middle"
                    />
                )}
            </Card>
        </Space>
    );
}

export default function BudgetReport() {
    return (
        <ManagementReportGate capabilityKey="budget_vs_actual">
            <BudgetReportContent />
        </ManagementReportGate>
    );
}
