import React, { useState, useEffect } from 'react';
import { Form, Input, DatePicker, Table, Button, Spin } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { ReloadOutlined, CheckCircleOutlined, SettingOutlined, CloseOutlined, QuestionCircleOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import dayjs from 'dayjs';
import { useVoucherShortcuts } from '../../../hooks/useVoucherShortcuts';
import ModalFrame from '../../../components/layout/ModalFrame';
import { MisaMasterCard, MisaTotalCard } from '../../../components/misa';

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
            className="misa-voucher-modal"
            closable={false}
            centered
            destroyOnHidden
            title={
                <div className="misa-voucher-custom-header">
                    <div className="misa-voucher-header-left">
                        <span className="misa-voucher-title">Lập bảng tính Khấu hao TSCĐ</span>
                    </div>
                    <div className="misa-voucher-header-right">
                        <button type="button" className="misa-voucher-header-icon-btn" title="Thiết lập">
                            <SettingOutlined />
                        </button>
                        <button
                            type="button"
                            className="misa-voucher-header-icon-btn"
                            title="Đóng (Esc)"
                            onClick={onCancel}
                        >
                            <CloseOutlined />
                        </button>
                    </div>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width="min(1820px, calc(100vw - 32px))"
            footer={
                <div className="misa-voucher-fixed-footer">
                    <div className="misa-footer-left">
                        <Button
                            className="misa-btn-footer-cancel"
                            icon={<QuestionCircleOutlined />}
                            onClick={() => message.info('Trợ giúp: Kiểm tra số tài sản đủ điều kiện trích khấu hao trước khi lưu và ghi sổ.')}
                        >
                            Giúp
                        </Button>
                    </div>
                    <div className="misa-footer-right">
                        <Button onClick={onCancel} className="misa-btn-footer-cancel">Hủy</Button>
                        <Button
                            type="primary"
                            onClick={handleSave}
                            loading={mutation.isPending}
                            className="misa-btn-footer-save-add"
                            icon={<CheckCircleOutlined />}
                        >
                            Cất & Ghi sổ
                        </Button>
                    </div>
                </div>
            }
        >
            <ModalFrame>
            <Form form={form} layout="vertical" onFinish={mutation.mutate} size="small" className="misa-voucher-form-container">
                <div className="misa-voucher-scroll-body">
                    <MisaMasterCard>
                        <MisaMasterCard.Left>
                            <div className="text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                                Thông tin chung
                            </div>
                            <div className="mb-3">
                                <Form.Item name="description" label="Diễn giải" className="mb-0">
                                    <Input placeholder="Trích khấu hao tài sản cố định..." className="w-full" />
                                </Form.Item>
                            </div>
                            <div className="flex items-center justify-between mt-2 pt-2 border-t border-slate-100">
                                <span className="text-xs text-slate-500">
                                    Định khoản tự động: Nợ TK Chi phí (642, 641, 154) / Có TK 2141
                                </span>
                                <Button
                                    icon={<ReloadOutlined />}
                                    onClick={() => handleFetchPreview(form.getFieldValue('month')?.format('YYYY-MM'))}
                                    loading={isPreviewLoading}
                                    size="small"
                                >
                                    Lấy lại dữ liệu
                                </Button>
                            </div>
                        </MisaMasterCard.Left>

                        <MisaMasterCard.Right>
                            <MisaMasterCard.MetaRow label="Kỳ trích khấu hao" required>
                                <Form.Item name="month" noStyle rules={[{ required: true }]}>
                                    <DatePicker
                                        picker="month"
                                        className="w-full"
                                        format="MM/YYYY"
                                        onChange={handleMonthChange}
                                    />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>
                            <MisaMasterCard.MetaRow label="Ngày chứng từ" required>
                                <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="w-full" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>
                            <MisaMasterCard.MetaRow label="Số chứng từ" required>
                                <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                    <Input className="font-semibold" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>
                            <MisaTotalCard
                                label="TỔNG TIỀN KHẤU HAO"
                                value={Number(previewData?.total_amount || 0)}
                            />
                        </MisaMasterCard.Right>
                    </MisaMasterCard>

                    <div className="mt-3 bg-white rounded-md border border-slate-200 overflow-hidden">
                        <div className="px-3 py-2 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
                            <span className="text-xs font-bold text-slate-700 uppercase">
                                Chi tiết bảng phân bổ trích khấu hao ({previewData?.lines?.length || 0} tài sản)
                            </span>
                        </div>
                        <div className="p-3">
                            <Spin spinning={isPreviewLoading}>
                                <Table
                                    dataSource={previewData?.lines || []}
                                    rowKey="asset_code"
                                    size="small"
                                    pagination={{ pageSize: 10, size: 'small', showTotal: (total) => `Tổng ${total} tài sản` }}
                                    bordered
                                    className="misa-voucher-table"
                                    summary={(pageData) => {
                                        const totalAlloc = (pageData as any[]).reduce((acc: number, curr: any) => acc + Number(curr?.monthly_depreciation || 0), 0);
                                        return (
                                            <Table.Summary fixed>
                                                <Table.Summary.Row className="bg-slate-50 font-bold">
                                                    <Table.Summary.Cell index={0} colSpan={3}>Tổng cộng</Table.Summary.Cell>
                                                    <Table.Summary.Cell index={1} align="right" colSpan={2} />
                                                    <Table.Summary.Cell index={2} align="right">
                                                        <span className="text-blue-700">{totalAlloc.toLocaleString('vi-VN')}</span>
                                                    </Table.Summary.Cell>
                                                    <Table.Summary.Cell index={3} colSpan={3} />
                                                </Table.Summary.Row>
                                            </Table.Summary>
                                        );
                                    }}
                                    columns={[
                                        { title: 'Mã TSCĐ', dataIndex: 'asset_code', width: 100 },
                                        { title: 'Tên tài sản', dataIndex: 'asset_name', ellipsis: true },
                                        { title: 'Bộ phận', dataIndex: 'department_code', width: 90, align: 'center' },
                                        {
                                            title: 'Nguyên giá (đ)',
                                            dataIndex: 'original_cost',
                                            align: 'right',
                                            width: 130,
                                            render: v => Number(v).toLocaleString('vi-VN')
                                        },
                                        {
                                            title: 'Hao mòn trước (đ)',
                                            dataIndex: 'accumulated_depreciation_before',
                                            align: 'right',
                                            width: 140,
                                            render: v => Number(v).toLocaleString('vi-VN')
                                        },
                                        {
                                            title: 'Trích kỳ này (đ)',
                                            dataIndex: 'monthly_depreciation',
                                            align: 'right',
                                            width: 130,
                                            render: v => <strong className="text-blue-700">{Number(v).toLocaleString('vi-VN')}</strong>
                                        },
                                        {
                                            title: 'GT còn lại sau trích (đ)',
                                            dataIndex: 'net_value_after',
                                            align: 'right',
                                            width: 160,
                                            render: v => Number(v).toLocaleString('vi-VN')
                                        },
                                        { title: 'TK Nợ', dataIndex: 'expense_account', align: 'center', width: 75 },
                                        { title: 'TK Có', dataIndex: 'depreciation_account', align: 'center', width: 75 },
                                    ]}
                                />
                            </Spin>
                        </div>
                    </div>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default RunDepreciationModal;
