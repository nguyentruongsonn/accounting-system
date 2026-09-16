import React, { useEffect } from 'react';
import { Form, Input, InputNumber, Select, DatePicker, Row, Col, Button } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { SettingOutlined, CloseOutlined, QuestionCircleOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import dayjs from 'dayjs';
import { useVoucherShortcuts } from '../../../hooks/useVoucherShortcuts';
import ModalFrame from '../../../components/layout/ModalFrame';
import { MisaMasterCard, MisaTotalCard } from '../../../components/misa';

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
            className="misa-voucher-modal"
            closable={false}
            centered
            destroyOnHidden
            title={
                <div className="misa-voucher-custom-header">
                    <div className="misa-voucher-header-left">
                        <span className="misa-voucher-title">Chứng từ Đánh giá lại TSCĐ</span>
                        <span className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded font-medium border border-blue-200 ml-3">
                            Hạch toán TK 412
                        </span>
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
                            onClick={() => message.info('Trợ giúp: Đánh giá lại nguyên giá, thời gian sử dụng và khấu hao theo biên bản thẩm định giá.')}
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
                        >
                            Cất & Ghi sổ
                        </Button>
                    </div>
                </div>
            }
        >
            <ModalFrame>
            <Form
                form={form}
                layout="vertical"
                onFinish={mutation.mutate}
                size="small"
                className="misa-voucher-form-container"
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
                <div className="misa-voucher-scroll-body">
                    <MisaMasterCard>
                        <MisaMasterCard.Left>
                            <div className="text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                                Thông tin tài sản đánh giá lại
                            </div>
                            <div className="grid grid-cols-12 gap-x-3 gap-y-2">
                                <div className="col-span-12">
                                    <Form.Item
                                        name="fixed_asset_id"
                                        label="Chọn Tài sản cố định đánh giá lại"
                                        rules={[{ required: true, message: 'Vui lòng chọn tài sản' }]}
                                        className="mb-0"
                                    >
                                        <Select
                                            showSearch
                                            placeholder="Tìm theo mã hoặc tên TSCĐ"
                                            optionFilterProp="children"
                                            onChange={handleAssetSelect}
                                            className="w-full"
                                            style={{ width: '100%' }}
                                        >
                                            {assets.map((a: any) => (
                                                <Select.Option key={a.id} value={a.id}>
                                                    [{a.asset_code}] {a.asset_name} (Nguyên giá cũ: {Number(a.original_cost).toLocaleString('vi-VN')} đ)
                                                </Select.Option>
                                            ))}
                                        </Select>
                                    </Form.Item>
                                </div>
                                <div className="col-span-6">
                                    <Form.Item name="decision_number" label="Số biên bản / Quyết định" className="mb-0">
                                        <Input placeholder="Số quyết định..." className="w-full" />
                                    </Form.Item>
                                </div>
                                <div className="col-span-6">
                                    <Form.Item name="decision_date" label="Ngày quyết định" className="mb-0">
                                        <DatePicker className="w-full" style={{ width: '100%' }} format="DD/MM/YYYY" />
                                    </Form.Item>
                                </div>
                                <div className="col-span-12">
                                    <Form.Item name="reason" label="Lý do đánh giá lại" className="mb-0">
                                        <Input placeholder="Lý do điều chỉnh..." className="w-full" />
                                    </Form.Item>
                                </div>
                            </div>
                        </MisaMasterCard.Left>

                        <MisaMasterCard.Right>
                            <MisaMasterCard.MetaRow label="Ngày chứng từ" required>
                                <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="w-full" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>
                            <MisaMasterCard.MetaRow label="Số chứng từ" required>
                                <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                    <Input placeholder="DGTS-YYYY-XXXX" className="font-semibold" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>
                            <MisaTotalCard
                                label="CHÊNH LỆCH ĐÁNH GIÁ"
                                value={Math.abs(costDiff || 0)}
                            />
                        </MisaMasterCard.Right>
                    </MisaMasterCard>

                    <div className="mt-3 bg-white rounded-md border border-slate-200 overflow-hidden p-3">
                        <h4 className="font-semibold text-slate-700 text-sm mb-2">So sánh Nguyên giá & Khấu hao Trước / Sau</h4>
                        <Row gutter={12}>
                            <Col span={8}>
                                <Form.Item name="old_original_cost" label="Nguyên giá cũ (VND)">
                                    <InputNumber
                                        className="w-full"
                                        style={{ width: '100%' }}
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
                                        style={{ width: '100%' }}
                                        min={0}
                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                    />
                                </Form.Item>
                            </Col>
                            <Col span={8}>
                                <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200 mt-5">
                                    <span className="text-xs text-slate-500 block">Chênh lệch nguyên giá</span>
                                    <span className={`text-base font-bold ${costDiff >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>
                                        {costDiff >= 0 ? '+' : ''}{costDiff.toLocaleString('vi-VN')} đ
                                    </span>
                                </div>
                            </Col>
                        </Row>

                        <Row gutter={12}>
                            <Col span={8}>
                                <Form.Item name="old_useful_life_months" label="Thời gian sử dụng cũ (tháng)">
                                    <InputNumber className="w-full" style={{ width: '100%' }} disabled />
                                </Form.Item>
                            </Col>
                            <Col span={8}>
                                <Form.Item name="new_useful_life_months" label="Thời gian sử dụng mới (tháng)">
                                    <InputNumber className="w-full" style={{ width: '100%' }} min={1} />
                                </Form.Item>
                            </Col>
                            <Col span={8}>
                                <Form.Item name="new_monthly_depreciation" label="Mức trích KH mới / tháng">
                                    <InputNumber
                                        className="w-full"
                                        style={{ width: '100%' }}
                                        disabled
                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                    />
                                </Form.Item>
                            </Col>
                        </Row>

                        <div className="text-xs text-slate-600 italic bg-blue-50 p-2 rounded border border-blue-200 mt-2">
                            {costDiff > 0 ? (
                                <span>✓ Tăng nguyên giá: <strong>Nợ TK 211 / Có TK 412</strong>: {costDiff.toLocaleString('vi-VN')} đ</span>
                            ) : costDiff < 0 ? (
                                <span>✓ Giảm nguyên giá: <strong>Nợ TK 412 / Có TK 211</strong>: {Math.abs(costDiff).toLocaleString('vi-VN')} đ</span>
                            ) : (
                                <span>Không có chênh lệch nguyên giá.</span>
                            )}
                        </div>
                    </div>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default AssetRevaluationModal;
