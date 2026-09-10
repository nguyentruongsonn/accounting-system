import React, { useEffect } from 'react';
import { Form, Input, InputNumber, Select, DatePicker, Row, Col, Button, Space } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import dayjs from 'dayjs';
import { useVoucherShortcuts } from '../../../hooks/useVoucherShortcuts';
import ModalFrame from '../../../components/layout/ModalFrame';

interface AssetRevaluationModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
    selectedAsset?: any;
}

export const AssetRevaluationModal: React.FC<AssetRevaluationModalProps> = ({
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
                new_original_cost: values.new_original_cost,
                new_useful_life_months: values.new_useful_life_months,
                decision_number: values.decision_number,
                decision_date: values.decision_date ? values.decision_date.format('YYYY-MM-DD') : null,
                reason: values.reason,
            };
            return api.post(`/fixed-assets/${assetId}/revalue`, payload);
        },
        onSuccess: (response: any) => {
            const revaluation = response?.data?.data;
            if (!revaluation || revaluation.id === undefined || revaluation.id === null) {
                message.error('Máy chủ không trả về chứng từ đánh giá lại đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Lập chứng từ đánh giá lại TSCĐ thành công!');
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-assets-revaluations'] });
            form.resetFields();
            if (onSuccess) onSuccess();
            onCancel();
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.message || err?.response?.data?.error || 'Có lỗi xảy ra khi đánh giá lại TSCĐ!');
        }
    });

    const handleAssetSelect = (assetId: number) => {
        const found = assets.find((a: any) => a.id === assetId);
        if (found) {
            form.setFieldsValue({
                asset_code: found.asset_code,
                asset_name: found.asset_name,
                old_original_cost: Number(found.original_cost) || 0,
                new_original_cost: Number(found.original_cost) || 0,
                old_useful_life_months: Number(found.useful_life_months) || 0,
                new_useful_life_months: Number(found.useful_life_months) || 0,
                old_monthly_depreciation: Number(found.monthly_depreciation) || 0,
                new_monthly_depreciation: Number(found.monthly_depreciation) || 0,
            });
        }
    };

    useEffect(() => {
        if (open) {
            form.resetFields();
            const now = dayjs();
            form.setFieldsValue({
                voucher_date: now,
                reason: 'Đánh giá lại TSCĐ theo quyết định thẩm định giá',
            });

            if (selectedAsset) {
                form.setFieldsValue({
                    fixed_asset_id: selectedAsset.id,
                    asset_code: selectedAsset.asset_code,
                    asset_name: selectedAsset.asset_name,
                    old_original_cost: Number(selectedAsset.original_cost) || 0,
                    new_original_cost: Number(selectedAsset.original_cost) || 0,
                    old_useful_life_months: Number(selectedAsset.useful_life_months) || 0,
                    new_useful_life_months: Number(selectedAsset.useful_life_months) || 0,
                    old_monthly_depreciation: Number(selectedAsset.monthly_depreciation) || 0,
                    new_monthly_depreciation: Number(selectedAsset.monthly_depreciation) || 0,
                });
            }

            api.get('/fixed-assets/next-code?type=DGTS').then(({ data }) => {
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

    const watchedOldCost = Form.useWatch('old_original_cost', form) || 0;
    const watchedNewCost = Form.useWatch('new_original_cost', form) || 0;
    const costDiff = watchedNewCost - watchedOldCost;

    return (
        <Modal
            title={
                <div className="flex justify-between items-center pr-6">
                    <span className="text-lg font-bold text-slate-800">Chứng từ Đánh giá lại TSCĐ</span>
                    <span className="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-semibold">
                        Hạch toán TK 412
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
            <Form 
                form={form} 
                layout="vertical" 
                onFinish={mutation.mutate} 
                size="small"
                onValuesChange={(changed, all) => {
                    if (changed.new_original_cost !== undefined || changed.new_useful_life_months !== undefined) {
                        const newCost = Number(all.new_original_cost) || 0;
                        const newLife = Number(all.new_useful_life_months) || 0;
                        if (newLife > 0) {
                            form.setFieldsValue({
                                new_monthly_depreciation: Math.round(newCost / newLife),
                            });
                        }
                    }
                }}
            >
                <div className="bg-slate-50 p-3 rounded-md border border-slate-200 mb-3">
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item 
                                name="fixed_asset_id" 
                                label="Chọn Tài sản cố định đánh giá lại" 
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
                                            [{a.asset_code}] {a.asset_name} (Nguyên giá cũ: {Number(a.original_cost).toLocaleString('vi-VN')} đ)
                                        </Select.Option>
                                    ))}
                                </Select>
                            </Form.Item>
                        </Col>
                        <Col span={6}>
                            <Form.Item name="voucher_number" label="Số chứng từ" rules={[{ required: true }]}>
                                <Input placeholder="DGTS-YYYY-XXXX" />
                            </Form.Item>
                        </Col>
                        <Col span={6}>
                            <Form.Item name="voucher_date" label="Ngày chứng từ" rules={[{ required: true }]}>
                                <DatePicker className="w-full" format="DD/MM/YYYY" />
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row gutter={12}>
                        <Col span={6}>
                            <Form.Item name="decision_number" label="Số biên bản / quyết định">
                                <Input placeholder="Số quyết định..." />
                            </Form.Item>
                        </Col>
                        <Col span={6}>
                            <Form.Item name="decision_date" label="Ngày quyết định">
                                <DatePicker className="w-full" format="DD/MM/YYYY" />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="reason" label="Lý do đánh giá lại">
                                <Input placeholder="Lý do điều chỉnh..." />
                            </Form.Item>
                        </Col>
                    </Row>
                </div>

                <div className="border border-slate-200 rounded-md p-3 mb-3">
                    <h4 className="font-semibold text-slate-700 text-sm mb-2">So sánh Nguyên giá & Khấu hao Trước / Sau</h4>
                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item name="old_original_cost" label="Nguyên giá cũ (VND)">
                                <InputNumber 
                                    className="w-full" 
                                    disabled 
                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={8}>
                            <Form.Item 
                                name="new_original_cost" 
                                label="Nguyên giá mới sau ĐGL (VND)" 
                                rules={[{ required: true, message: 'Nhập nguyên giá mới' }]}
                            >
                                <InputNumber 
                                    className="w-full" 
                                    min={0}
                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={8}>
                            <div className="bg-gray-50 p-2 rounded border mt-4">
                                <span className="text-xs text-gray-500 block">Chênh lệch nguyên giá</span>
                                <span className={`text-base font-bold ${costDiff >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {costDiff >= 0 ? '+' : ''}{costDiff.toLocaleString('vi-VN')} đ
                                </span>
                            </div>
                        </Col>
                    </Row>

                    <Row gutter={12}>
                        <Col span={8}>
                            <Form.Item name="old_useful_life_months" label="Thời gian sử dụng cũ (tháng)">
                                <InputNumber className="w-full" disabled />
                            </Form.Item>
                        </Col>
                        <Col span={8}>
                            <Form.Item name="new_useful_life_months" label="Thời gian sử dụng mới (tháng)">
                                <InputNumber className="w-full" min={1} />
                            </Form.Item>
                        </Col>
                        <Col span={8}>
                            <Form.Item name="new_monthly_depreciation" label="Mức trích KH mới / tháng">
                                <InputNumber 
                                    className="w-full" 
                                    disabled
                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                />
                            </Form.Item>
                        </Col>
                    </Row>

                    <div className="text-xs text-gray-600 italic bg-blue-50 p-2 rounded border border-blue-200 mt-2">
                        {costDiff > 0 ? (
                            <span>✓ Tăng nguyên giá: <strong>Nợ TK 211 / Có TK 412</strong>: {costDiff.toLocaleString('vi-VN')} đ</span>
                        ) : costDiff < 0 ? (
                            <span>✓ Giảm nguyên giá: <strong>Nợ TK 412 / Có TK 211</strong>: {Math.abs(costDiff).toLocaleString('vi-VN')} đ</span>
                        ) : (
                            <span>Không có chênh lệch nguyên giá.</span>
                        )}
                    </div>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default AssetRevaluationModal;
