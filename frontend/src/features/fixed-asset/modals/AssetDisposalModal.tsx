import React, { useEffect } from 'react';
import { Form, Input, InputNumber, Select, DatePicker, Button } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { SettingOutlined, CloseOutlined, QuestionCircleOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import dayjs from 'dayjs';
import { useVoucherShortcuts } from '../../../hooks/useVoucherShortcuts';
import ModalFrame from '../../../components/layout/ModalFrame';
import { MisaMasterCard, MisaTotalCard } from '../../../components/misa';

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
            className="misa-voucher-modal"
            closable={false}
            centered
            destroyOnHidden
            title={
                <div className="misa-voucher-custom-header">
                    <div className="misa-voucher-header-left">
                        <span className="misa-voucher-title">Chứng từ Thanh lý / Ghi giảm TSCĐ</span>
                        <span className="text-xs bg-red-50 text-red-700 px-2 py-0.5 rounded font-medium border border-red-200 ml-3">
                            Ghi giảm nguyên giá & Khấu hao
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
                            onClick={() => message.info('Trợ giúp: Ghi giảm tài sản do thanh lý, nhượng bán và bảo toàn lịch sử nguyên giá theo TT200/TT133.')}
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
            <Form form={form} layout="vertical" onFinish={mutation.mutate} size="small" className="misa-voucher-form-container">
                <div className="misa-voucher-scroll-body">
                    <MisaMasterCard>
                        <MisaMasterCard.Left>
                            <div className="text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                                Thông tin tài sản ghi giảm
                            </div>
                            <div className="grid grid-cols-12 gap-x-3 gap-y-2">
                                <div className="col-span-8">
                                    <Form.Item
                                        name="fixed_asset_id"
                                        label="Chọn Tài sản cố định ghi giảm"
                                        rules={[{ required: true, message: 'Vui lòng chọn tài sản' }]}
                                        className="mb-0"
                                    >
                                        <Select
                                            showSearch
                                            placeholder="Tìm theo mã hoặc tên TSCĐ"
                                            optionFilterProp="children"
                                            onChange={handleAssetSelect}
                                            style={{ width: '100%' }}
                                        >
                                            {assets.map((a: any) => (
                                                <Select.Option key={a.id} value={a.id}>
                                                    [{a.asset_code}] {a.asset_name} (GTCL: {Number(a.net_value).toLocaleString('vi-VN')} đ)
                                                </Select.Option>
                                            ))}
                                        </Select>
                                    </Form.Item>
                                </div>
                                <div className="col-span-4">
                                    <Form.Item name="disposal_type" label="Hình thức ghi giảm" className="mb-0">
                                        <Select style={{ width: '100%' }}>
                                            <Select.Option value="liquidation">Thanh lý TSCĐ</Select.Option>
                                            <Select.Option value="sale">Nhượng bán TSCĐ</Select.Option>
                                            <Select.Option value="lost">Mất / Hỏng hóc</Select.Option>
                                            <Select.Option value="other">Ghi giảm khác</Select.Option>
                                        </Select>
                                    </Form.Item>
                                </div>
                                <div className="col-span-12">
                                    <Form.Item name="disposal_reason" label="Lý do ghi giảm" rules={[{ required: true, message: 'Nhập lý do ghi giảm' }]} className="mb-0">
                                        <Input placeholder="Nhập lý do thanh lý, ghi giảm..." style={{ width: '100%' }} />
                                    </Form.Item>
                                </div>
                            </div>
                        </MisaMasterCard.Left>

                        <MisaMasterCard.Right>
                            <MisaMasterCard.MetaRow label="Ngày chứng từ" required>
                                <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="w-full" format="DD/MM/YYYY" style={{ width: '100%' }} />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>
                            <MisaMasterCard.MetaRow label="Số chứng từ" required>
                                <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                    <Input placeholder="GGTS-YYYY-XXXX" className="font-semibold" style={{ width: '100%' }} />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>
                            <MisaTotalCard
                                label="GIÁ TRỊ CÒN LẠI"
                                value={Number(watchedNetValue || 0)}
                            />
                        </MisaMasterCard.Right>
                    </MisaMasterCard>

                    <div className="mt-3 bg-white rounded-md border border-slate-200 overflow-hidden p-3">
                        <h4 className="font-semibold text-slate-700 text-sm mb-2">Giá trị sổ sách & Bút toán ghi giảm</h4>
                        <div className="grid grid-cols-12 gap-3 mb-2">
                            <div className="col-span-4">
                                <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200">
                                    <span className="text-xs text-slate-500 block">1. Hao mòn lũy kế</span>
                                    <span className="text-base font-bold text-amber-600">
                                        {Number(watchedAccumulated).toLocaleString('vi-VN')} đ
                                    </span>
                                </div>
                            </div>
                            <div className="col-span-4">
                                <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200">
                                    <span className="text-xs text-slate-500 block">2. Giá trị còn lại</span>
                                    <span className="text-base font-bold text-red-600">
                                        {Number(watchedNetValue).toLocaleString('vi-VN')} đ
                                    </span>
                                </div>
                            </div>
                            <div className="col-span-4">
                                <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200">
                                    <span className="text-xs text-slate-500 block">3. Nguyên giá giảm</span>
                                    <span className="text-base font-bold text-blue-700">
                                        {Number(watchedOriginalCost).toLocaleString('vi-VN')} đ
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="mt-3 bg-white rounded-md border border-slate-200 overflow-hidden p-3">
                        <h4 className="font-semibold text-slate-700 text-sm mb-2">Doanh thu / Thu nhập thanh lý nhượng bán (Tùy chọn)</h4>
                        <div className="grid grid-cols-12 gap-3">
                            <div className="col-span-4">
                                <Form.Item name="disposal_price" label="Số tiền thu thanh lý" className="mb-0">
                                    <InputNumber
                                        className="w-full"
                                        style={{ width: '100%' }}
                                        min={0}
                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                    />
                                </Form.Item>
                            </div>
                            <div className="col-span-4">
                                <Form.Item name="payment_method" label="Hình thức thu tiền" rules={[{ required: true, message: 'Chọn hình thức thu tiền' }]} className="mb-0">
                                    <Select style={{ width: '100%' }}>
                                        <Select.Option value="cash">Tiền mặt</Select.Option>
                                        <Select.Option value="unpaid">Chưa thu tiền</Select.Option>
                                    </Select>
                                </Form.Item>
                            </div>
                            <div className="col-span-4">
                                <Form.Item name="buyer_name" label="Bên mua thanh lý" className="mb-0">
                                    <Input placeholder="Tên đơn vị/cá nhân mua..." style={{ width: '100%' }} />
                                </Form.Item>
                            </div>
                        </div>
                    </div>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default AssetDisposalModal;
