import React, { useState, useEffect } from 'react';
import { Form, Input, DatePicker, Table, Button, Space, Row, Col, Spin } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { EyeOutlined, CheckCircleOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import dayjs from 'dayjs';
import { useVoucherShortcuts } from '../../../hooks/useVoucherShortcuts';
import ModalFrame from '../../../components/layout/ModalFrame';

interface RunDepreciationModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
}

export const RunDepreciationModal: React.FC<RunDepreciationModalProps> = ({
    open,
    onCancel,
    onSuccess,
}) => {
    const [form] = Form.useForm();
    const [previewData, setPreviewData] = useState<any>(null);
    const [isPreviewLoading, setIsPreviewLoading] = useState(false);
    const queryClient = useQueryClient();

    useEffect(() => {
        if (open) {
            const currentMonth = dayjs();
            form.setFieldsValue({
                month: currentMonth,
                voucher_date: currentMonth.endOf('month'),
                voucher_number: 'KHTS-' + currentMonth.format('YYYY-MM'),
                description: 'Trích khấu hao TSCĐ tháng ' + currentMonth.format('MM/YYYY'),
            });
            handleFetchPreview(currentMonth.format('YYYY-MM'));
        } else {
            setPreviewData(null);
        }
    }, [open, form]);

    const handleFetchPreview = async (monthStr: string) => {
        setIsPreviewLoading(true);
        try {
            const { data } = await api.get(`/fixed-assets/depreciation/preview?month=${monthStr}`);
            if (!data || typeof data !== 'object' || !Array.isArray(data.lines) || data.total_amount === undefined) {
                throw new Error('Máy chủ không trả về bản xem trước khấu hao hợp lệ.');
            }
            setPreviewData(data);
        } catch (err: any) {
            message.warning(err?.response?.data?.message || 'Không tìm thấy dữ liệu khấu hao cho kỳ này');
            setPreviewData(null);
        } finally {
            setIsPreviewLoading(false);
        }
    };

    const handleMonthChange = (date: any) => {
        if (date) {
            const monthStr = date.format('YYYY-MM');
            form.setFieldsValue({
                voucher_date: date.endOf('month'),
                voucher_number: 'KHTS-' + monthStr,
                description: 'Trích khấu hao TSCĐ tháng ' + date.format('MM/YYYY'),
            });
            handleFetchPreview(monthStr);
        }
    };

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const monthStr = values.month.format('YYYY-MM');
            const payload = {
                month: monthStr,
                voucher_date: values.voucher_date?.format('YYYY-MM-DD'),
                voucher_number: values.voucher_number,
                description: values.description,
            };
            return api.post('/fixed-assets/depreciation/run', payload);
        },
        onSuccess: (response: any) => {
            const depreciationLog = response?.data?.data;
            if (!depreciationLog || depreciationLog.id === undefined || depreciationLog.id === null) {
                message.error('Máy chủ không trả về kỳ khấu hao đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Chạy trích khấu hao và sinh bút toán Sổ cái GL thành công!');
            queryClient.invalidateQueries({ queryKey: ['fixed-assets-depreciation-periods'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
            if (onSuccess) onSuccess();
            onCancel();
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.message || err?.response?.data?.error || 'Có lỗi xảy ra khi chạy khấu hao!');
        }
    });

    const handleSave = () => {
        form.submit();
    };

    useVoucherShortcuts({
        onSave: handleSave,
        onPost: handleSave,
        onClose: onCancel,
        enabled: open,
    });

    return (
        <Modal
            title={
                <div className="flex justify-between items-center pr-6">
                    <span className="text-lg font-bold text-slate-800">Lập bảng tính Khấu hao TSCĐ định kỳ</span>
                    <span className="text-xs bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded font-semibold">
                        Tự động sinh GL Nợ 6424/6414/154 - Có 2141
                    </span>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={1000}
            footer={
                <div className="flex justify-between items-center">
                    <div className="text-xs text-gray-500">
                        <span className="font-semibold">Phím tắt:</span> <kbd className="px-1 bg-gray-100 border rounded">F8</kbd> Cất / <kbd className="px-1 bg-gray-100 border rounded">F9</kbd> Ghi sổ | <kbd className="px-1 bg-gray-100 border rounded">Esc</kbd> Đóng
                    </div>
                    <Space>
                        <Button onClick={onCancel}>Hủy</Button>
                        <Button 
                            type="primary" 
                            onClick={handleSave} 
                            loading={mutation.isPending}
                            className="misa-btn-primary"
                            icon={<CheckCircleOutlined />}
                        >
                            Cất & Ghi sổ (F8/F9)
                        </Button>
                    </Space>
                </div>
            }
        >
            <ModalFrame>
            <Form form={form} layout="vertical" onFinish={mutation.mutate} size="small">
                <div className="bg-slate-50 p-3 rounded-md border border-slate-200 mb-3">
                    <Row gutter={12}>
                        <Col span={6}>
                            <Form.Item name="month" label="Kỳ trích khấu hao" rules={[{ required: true }]}>
                                <DatePicker 
                                    picker="month" 
                                    className="w-full" 
                                    format="MM/YYYY" 
                                    onChange={handleMonthChange}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={6}>
                            <Form.Item name="voucher_number" label="Số chứng từ" rules={[{ required: true }]}>
                                <Input />
                            </Form.Item>
                        </Col>
                        <Col span={6}>
                            <Form.Item name="voucher_date" label="Ngày chứng từ" rules={[{ required: true }]}>
                                <DatePicker className="w-full" format="DD/MM/YYYY" />
                            </Form.Item>
                        </Col>
                        <Col span={6}>
                            <div className="pt-6">
                                <Button 
                                    icon={<EyeOutlined />} 
                                    onClick={() => handleFetchPreview(form.getFieldValue('month')?.format('YYYY-MM'))}
                                    loading={isPreviewLoading}
                                    className="w-full"
                                >
                                    Tính thử / Xem lại
                                </Button>
                            </div>
                        </Col>
                    </Row>
                    <Row gutter={12}>
                        <Col span={24}>
                            <Form.Item name="description" label="Diễn giải" className="mb-0">
                                <Input />
                            </Form.Item>
                        </Col>
                    </Row>
                </div>

                <div className="border border-slate-200 rounded-md p-3">
                    <div className="flex justify-between items-center mb-2">
                        <h4 className="font-semibold text-slate-700 text-sm">
                            Xem trước chi tiết bảng phân bổ trích khấu hao ({previewData?.lines?.length || 0} tài sản)
                        </h4>
                        {previewData && (
                            <span className="text-sm font-bold text-blue-700 bg-blue-50 px-2 py-0.5 rounded border border-blue-200">
                                Tổng trích: {Number(previewData.total_amount || 0).toLocaleString('vi-VN')} đ
                            </span>
                        )}
                    </div>

                    <Spin spinning={isPreviewLoading}>
                        <Table
                            dataSource={previewData?.lines || []}
                            rowKey="asset_code"
                            size="small"
                            pagination={{ pageSize: 5, size: 'small' }}
                            bordered
                            columns={[
                                { title: 'Mã TSCĐ', dataIndex: 'asset_code', width: 90 },
                                { title: 'Tên tài sản', dataIndex: 'asset_name' },
                                { title: 'Bộ phận', dataIndex: 'department_code', width: 100 },
                                { 
                                    title: 'Nguyên giá', 
                                    dataIndex: 'original_cost', 
                                    align: 'right',
                                    render: v => Number(v).toLocaleString('vi-VN')
                                },
                                { 
                                    title: 'Hao mòn trước', 
                                    dataIndex: 'accumulated_depreciation_before', 
                                    align: 'right',
                                    render: v => Number(v).toLocaleString('vi-VN')
                                },
                                { 
                                    title: 'Trích kỳ này', 
                                    dataIndex: 'monthly_depreciation', 
                                    align: 'right',
                                    render: v => <strong className="text-blue-700">{Number(v).toLocaleString('vi-VN')}</strong>
                                },
                                { 
                                    title: 'GT còn lại sau trích', 
                                    dataIndex: 'net_value_after', 
                                    align: 'right',
                                    render: v => Number(v).toLocaleString('vi-VN')
                                },
                                { title: 'TK Nợ', dataIndex: 'expense_account', align: 'center', width: 70 },
                                { title: 'TK Có', dataIndex: 'depreciation_account', align: 'center', width: 70 },
                            ]}
                        />
                    </Spin>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default RunDepreciationModal;
