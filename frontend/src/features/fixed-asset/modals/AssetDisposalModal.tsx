import React, { useEffect } from 'react';
import { Form, Input, InputNumber, Select, DatePicker, Row, Col, Button, Space } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import dayjs from 'dayjs';
import { useVoucherShortcuts } from '../../../hooks/useVoucherShortcuts';
import ModalFrame from '../../../components/layout/ModalFrame';

interface AssetDisposalModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
    selectedAsset?: any;
}

export const AssetDisposalModal: React.FC<AssetDisposalModalProps> = ({
    open,
    onCancel,
    onSuccess,
    selectedAsset
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const { data: assets = [] } = useQuery<any[]>({
        queryKey: ['fixed-assets-active'],
        queryFn: async () => {
            const { data } = await api.get('/fixed-assets?is_active=true');
            return Array.isArray(data) ? data : (data?.data || []);
        },
        enabled: open,
    });

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const assetId = values.fixed_asset_id;
            const payload = {
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date.format('YYYY-MM-DD'),
                disposal_date: values.disposal_date ? values.disposal_date.format('YYYY-MM-DD') : values.voucher_date.format('YYYY-MM-DD'),
                disposal_type: values.disposal_type || 'liquidation',
                disposal_reason: values.disposal_reason,
                disposal_price: values.disposal_price || 0,
                payment_method: values.payment_method,
                buyer_name: values.buyer_name,
            };
            return api.post(`/fixed-assets/${assetId}/dispose`, payload);
        },
        onSuccess: (response: any) => {
            const disposal = response?.data?.data;
            if (!disposal || disposal.id === undefined || disposal.id === null) {
                message.error('Máy chủ không trả về chứng từ thanh lý đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Lập chứng từ thanh lý / ghi giảm TSCĐ thành công!');
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-assets-disposals'] });
            form.resetFields();
            if (onSuccess) onSuccess();
            onCancel();
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.message || err?.response?.data?.error || 'Có lỗi xảy ra khi thanh lý TSCĐ!');
        }
    });

    const handleAssetSelect = (assetId: number) => {
        const found = assets.find((a: any) => a.id === assetId);
        if (found) {
            form.setFieldsValue({
                asset_code: found.asset_code,
                asset_name: found.asset_name,
                department_code: found.department_code,
                original_cost: Number(found.original_cost) || 0,
                accumulated_depreciation: Number(found.accumulated_depreciation) || 0,
                net_value: Number(found.net_value) || (Number(found.original_cost) - Number(found.accumulated_depreciation)),
            });
        }
    };

    useEffect(() => {
        if (open) {
            form.resetFields();
            const now = dayjs();
            form.setFieldsValue({
                voucher_date: now,
                disposal_date: now,
                disposal_type: 'liquidation',
                disposal_price: 0,
            });

            if (selectedAsset) {
                form.setFieldsValue({
                    fixed_asset_id: selectedAsset.id,
                    asset_code: selectedAsset.asset_code,
                    asset_name: selectedAsset.asset_name,
                    department_code: selectedAsset.department_code,
                    original_cost: Number(selectedAsset.original_cost) || 0,
                    accumulated_depreciation: Number(selectedAsset.accumulated_depreciation) || 0,
                    net_value: Number(selectedAsset.net_value) || 0,
                });
            }

            // Lấy số chứng từ tiếp theo
            api.get('/fixed-assets/next-code?type=GGTS').then(({ data }) => {
                if (data?.code) {
                    form.setFieldsValue({ voucher_number: data.code });
                }
            }).catch(() => {});
        }
    }, [open, selectedAsset, form]);

    const handleSave = () => {
        form.submit();
    };

    useVoucherShortcuts({
        onSave: handleSave,
        onPost: handleSave,
        onClose: onCancel,
        enabled: open,
    });

    const watchedOriginalCost = Form.useWatch('original_cost', form) || 0;
    const watchedAccumulated = Form.useWatch('accumulated_depreciation', form) || 0;
    const watchedNetValue = Form.useWatch('net_value', form) || 0;

    return (
        <Modal
            title={
                <div className="flex justify-between items-center pr-6">
                    <span className="text-lg font-bold text-slate-800">Chứng từ Thanh lý / Ghi giảm TSCĐ</span>
                    <span className="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded font-semibold">
                        Ghi giảm nguyên giá & Khấu hao
                    </span>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={850}
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
                        <Col span={12}>
                            <Form.Item 
                                name="fixed_asset_id" 
                                label="Chọn Tài sản cố định ghi giảm" 
                                rules={[{ required: true, message: 'Vui lòng chọn tài sản' }]}
                            >
                                <Select 
                                    showSearch 
                                    placeholder="Tìm theo mã hoặc tên TSCĐ"
                                    optionFilterProp="children"
                                    onChange={handleAssetSelect}
                                >
                                    {assets.map((a: any) => (
                                        <Select.Option key={a.id} value={a.id}>
                                            [{a.asset_code}] {a.asset_name} (GTCL: {Number(a.net_value).toLocaleString('vi-VN')} đ)
                                        </Select.Option>
                                    ))}
                                </Select>
                            </Form.Item>
                        </Col>
                        <Col span={6}>
                            <Form.Item name="voucher_number" label="Số chứng từ" rules={[{ required: true }]}>
                                <Input placeholder="GGTS-YYYY-XXXX" />
                            </Form.Item>
                        </Col>
                        <Col span={6}>
                            <Form.Item name="voucher_date" label="Ngày chứng từ" rules={[{ required: true }]}>
                                <DatePicker className="w-full" format="DD/MM/YYYY" />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item name="disposal_type" label="Hình thức ghi giảm">
                                <Select>
                                    <Select.Option value="liquidation">Thanh lý TSCĐ</Select.Option>
                                    <Select.Option value="sale">Nhượng bán TSCĐ</Select.Option>
                                    <Select.Option value="lost">Mất / Hỏng hóc</Select.Option>
                                    <Select.Option value="other">Ghi giảm khác</Select.Option>
                                </Select>
                            </Form.Item>
                        </Col>
                        <Col span={16}>
                            <Form.Item name="disposal_reason" label="Lý do ghi giảm" rules={[{ required: true, message: 'Nhập lý do ghi giảm' }]}>
                                <Input placeholder="Nhập lý do thanh lý, ghi giảm..." />
                            </Form.Item>
                        </Col>
                    </Row>
                </div>

                <div className="border border-slate-200 rounded-md p-3 mb-3">
                    <h4 className="font-semibold text-slate-700 text-sm mb-2">Giá trị sổ sách & Bút toán ghi giảm</h4>
                    <Row gutter={12} className="mb-2">
                        <Col span={8}>
                            <div className="bg-gray-50 p-2 rounded border">
                                <span className="text-xs text-gray-500 block">1. Hao mòn lũy kế</span>
                                <span className="text-base font-bold text-orange-600">
                                    {Number(watchedAccumulated).toLocaleString('vi-VN')} đ
                                </span>
                            </div>
                        </Col>
                        <Col span={8}>
                            <div className="bg-gray-50 p-2 rounded border">
                                <span className="text-xs text-gray-500 block">2. Giá trị còn lại</span>
                                <span className="text-base font-bold text-red-600">
                                    {Number(watchedNetValue).toLocaleString('vi-VN')} đ
                                </span>
                            </div>
                        </Col>
                        <Col span={8}>
                            <div className="bg-gray-50 p-2 rounded border">
                                <span className="text-xs text-gray-500 block">3. Nguyên giá giảm</span>
                                <span className="text-base font-bold text-blue-700">
                                    {Number(watchedOriginalCost).toLocaleString('vi-VN')} đ
                                </span>
                            </div>
                        </Col>
                    </Row>
                    <div className="text-xs text-gray-600 italic bg-amber-50 p-2 rounded border border-amber-200">
                        ✓ Kiểm tra cân đối theo dữ liệu tài sản hiện có: hao mòn lũy kế ({Number(watchedAccumulated).toLocaleString('vi-VN')} đ) + giá trị còn lại ({Number(watchedNetValue).toLocaleString('vi-VN')} đ) = nguyên giá ({Number(watchedOriginalCost).toLocaleString('vi-VN')} đ)
                    </div>
                </div>

                <div className="border border-slate-200 rounded-md p-3">
                    <h4 className="font-semibold text-slate-700 text-sm mb-2">Doanh thu / Thu nhập thanh lý nhượng bán (Tùy chọn)</h4>
                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item name="disposal_price" label="Số tiền thu thanh lý">
                                <InputNumber 
                                    className="w-full" 
                                    min={0}
                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={8}>
                            <Form.Item name="payment_method" label="Hình thức thu tiền" rules={[{ required: true, message: 'Chọn hình thức thu tiền' }]}>
                                <Select>
                                    <Select.Option value="cash">Tiền mặt</Select.Option>
                                    <Select.Option value="unpaid">Chưa thu tiền</Select.Option>
                                </Select>
                            </Form.Item>
                        </Col>
                        <Col span={8}>
                            <Form.Item name="buyer_name" label="Bên mua thanh lý">
                                <Input placeholder="Tên đơn vị/cá nhân mua..." />
                            </Form.Item>
                        </Col>
                    </Row>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default AssetDisposalModal;
