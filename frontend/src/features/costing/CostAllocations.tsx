import { useState } from 'react';
import { Card, Table, Typography, Form, DatePicker, Button, Space, Steps, Row, Col } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { AppstoreAddOutlined, DollarOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import api from '../../api/axios';

const { Title, Text } = Typography;

const formatAmount = (value: unknown) => {
    if (value === null || value === undefined || value === '') return '—';
    const amount = Number(value);
    return Number.isFinite(amount) ? `${new Intl.NumberFormat('vi-VN').format(amount)} đ` : '—';
};

export default function CostAllocations() {
    const [form] = Form.useForm();
    const [loading, setLoading] = useState(false);
    const [step, setStep] = useState(0);
    const [data, setData] = useState<any[]>([]);

    const handleRunCosting = async (values: any) => {
        setLoading(true);
        try {
            const formattedMonth = values.period.format('YYYY-MM');
            
            setStep(1); // Bước 1: Tập hợp chi phí
            
            const response = await api.post('/costing/allocate', {
                month: formattedMonth
            });
            
            const apiData = response.data.data;
            // CostingService returns the allocation records directly. Keep a
            // defensive object-shape fallback for compatible API envelopes,
            // but never manufacture rows or KPI values in the browser.
            const allocationRows = Array.isArray(apiData)
                ? apiData
                : (Array.isArray(apiData?.lines) ? apiData.lines : null);
            if (!allocationRows) {
                throw new Error('Máy chủ không trả về dữ liệu phân bổ giá thành hợp lệ.');
            }

            setStep(2); // Bước 2: Phân bổ
            setStep(3); // Bước 3: Nghiệm thu
            setData(allocationRows);
            message.success('Đã hoàn tất phân bổ giá thành cho kỳ đã chọn.');
        } catch (error) {
            message.error('Lỗi khi tính giá thành.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const columns = [
        {
            title: 'Lệnh sản xuất',
            dataIndex: 'production_order_id',
            key: 'production_order_id',
            render: (value: number | null | undefined) => <strong>{value == null ? '—' : `#${value}`}</strong>,
        },
        {
            title: 'NVL trực tiếp (621)',
            dataIndex: 'direct_material_cost',
            key: 'direct_material_cost',
            align: 'right' as const,
            render: formatAmount,
        },
        {
            title: 'Nhân công (622)',
            dataIndex: 'direct_labor_cost',
            key: 'direct_labor_cost',
            align: 'right' as const,
            render: formatAmount,
        },
        {
            title: 'SXC (627)',
            dataIndex: 'manufacturing_overhead',
            key: 'manufacturing_overhead',
            align: 'right' as const,
            render: formatAmount,
        },
        {
            title: 'Dở dang cuối kỳ',
            dataIndex: 'wip_ending',
            key: 'wip_ending',
            align: 'right' as const,
            render: formatAmount,
        },
        {
            title: 'Tổng giá thành',
            dataIndex: 'total_cost',
            key: 'total_cost',
            align: 'right' as const,
            render: (value: unknown) => <Text strong>{formatAmount(value)}</Text>,
        },
    ];

    return (
        <Space direction="vertical" className="w-full apple-feature-page" size="large">
            <div className="flex justify-between items-center apple-feature-heading">
                <div>
                    <Title level={3} className="!mb-1">
                        <AppstoreAddOutlined className="mr-2 text-teal-600" />
                        Kỳ tính giá thành sản xuất (Costing)
                    </Title>
                    <Text type="secondary">Tự động tập hợp chi phí, phân bổ SXC, đánh giá dở dang và tính giá thành sản phẩm.</Text>
                </div>
            </div>

            <Row gutter={16}>
                <Col span={8}>
                    <Card className="shadow-sm">
                        <Form
                            form={form}
                            layout="vertical"
                            onFinish={handleRunCosting}
                            initialValues={{
                                period: dayjs(),
                            }}
                        >
                            <Form.Item name="period" label="Kỳ tính giá thành" rules={[{ required: true }]}>
                                <DatePicker picker="month" format="MM/YYYY" placeholder="Chọn tháng" className="w-full" />
                            </Form.Item>
                            <Form.Item>
                                <Button type="primary" htmlType="submit" icon={<DollarOutlined />} loading={loading} block className="misa-btn-primary">
                                    Tính Giá thành & Hạch toán tự động
                                </Button>
                            </Form.Item>
                        </Form>
                    </Card>
                </Col>
                <Col span={16}>
                    <Card className="shadow-sm" title="Tiến trình tính toán">
                        <Steps
                            current={step}
                            items={[
                                { title: 'Tập hợp chi phí', description: 'Gom Nợ 621, 622, 627' },
                                { title: 'Phân bổ chi phí chung', description: 'Phân bổ 627 cho LSX' },
                                { title: 'Nghiệm thu thành phẩm', description: 'Kết chuyển 154 -> 155' },
                            ]}
                        />
                    </Card>
                </Col>
            </Row>

            {data.length > 0 && (
                <Card className="shadow-sm" title="Chi tiết chi phí đã tập hợp trong kỳ">
                    <Table
                        columns={columns}
                        dataSource={data}
                        rowKey="id"
                        pagination={false}
                        size="middle"
                    />
                </Card>
            )}
        </Space>
    );
}
