import React, { useEffect, useState } from 'react';
import { Form, Input, InputNumber, Radio } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import ModalFrame from '../layout/ModalFrame';
import { MisaButton } from './MisaButton';

interface QuickAddPaymentTermModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: (newTerm: any) => void;
}

export const QuickAddPaymentTermModal: React.FC<QuickAddPaymentTermModalProps> = ({
    open,
    onCancel,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();
    const [calcType, setCalcType] = useState<'due_days' | 'fixed_day'>('due_days');

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const { data } = await api.post('/master/payment-terms', {
                code: values.code,
                name: values.name,
                calc_type: calcType,
                due_days: calcType === 'due_days' ? (Number(values.due_days) || 0) : ((Number(values.fixed_months) || 0) * 30),
                fixed_day: calcType === 'fixed_day' ? Number(values.fixed_day) : null,
                fixed_months: calcType === 'fixed_day' ? Number(values.fixed_months) : null,
                discount_days: Number(values.discount_days) || 0,
                discount_rate: Number(values.discount_rate) || 0,
                description: values.description || '',
            });
            return data;
        },
        onSuccess: (data, variables: any) => {
            if (!data || data.id === undefined || data.id === null) {
                message.error('Máy chủ không trả về điều khoản thanh toán đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success(`Đã thêm điều khoản thanh toán ${data.code} thành công!`);
            queryClient.invalidateQueries({ queryKey: ['payment-terms'] });
            if (onSuccess) onSuccess(data);
            if (variables._keepOpen) {
                form.resetFields();
                form.setFieldsValue({
                    code: 'ĐKTT',
                    due_days: 0,
                    fixed_day: 25,
                    fixed_months: 1,
                    discount_days: 0,
                    discount_rate: 0
                });
                setCalcType('due_days');
            } else {
                onCancel();
            }
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi lưu điều khoản thanh toán!');
        }
    });

    useEffect(() => {
        if (open) {
            form.resetFields();
            form.setFieldsValue({
                code: 'ĐKTT',
                due_days: 30,
                fixed_day: 25,
                fixed_months: 1,
                discount_days: 0,
                discount_rate: 0
            });
            setCalcType('due_days');
        }
    }, [open, form]);

    const handleSave = (keepOpen: boolean = false) => {
        form.validateFields().then(values => {
            mutation.mutate({ ...values, _keepOpen: keepOpen });
        });
    };

    return (
        <Modal
            title={<span className="misa-modal-title">Thêm Điều khoản thanh toán</span>}
            open={open}
            onCancel={onCancel}
            width={580}
            zIndex={2500}
            className="misa-clean-modal"
            centered={true}
            footer={
                <div className="misa-modal-footer">
                    <MisaButton onClick={onCancel}>
                        Hủy
                    </MisaButton>
                    <div className="misa-flex-center misa-gap-8">
                        <MisaButton
                            onClick={() => handleSave(false)}
                            loading={mutation.isPending}
                        >
                            Cất
                        </MisaButton>
                        <MisaButton
                            variant="primary"
                            loading={mutation.isPending}
                            onClick={() => handleSave(true)}
                        >
                            Cất và Thêm
                        </MisaButton>
                    </div>
                </div>
            }
        >
            <ModalFrame className="ui-modal-frame--simple">
            <Form form={form} layout="vertical" size="small" className="misa-pt-8">
                {/* 1. Mã & Tên */}
                <div className="misa-grid-2col-gap-16 misa-mb-12">
                    <div>
                        <div className="misa-label-bold">
                            Mã <span className="misa-text-red">*</span>
                        </div>
                        <Form.Item name="code" rules={[{ required: true, message: 'Vui lòng nhập mã điều khoản' }]} noStyle>
                            <Input placeholder="Mã điều khoản..." autoFocus className="misa-input misa-text-bold" />
                        </Form.Item>
                    </div>

                    <div>
                        <div className="misa-label-bold">
                            Tên <span className="misa-text-red">*</span>
                        </div>
                        <Form.Item name="name" rules={[{ required: true, message: 'Vui lòng nhập tên điều khoản' }]} noStyle>
                            <Input placeholder="Tên điều khoản thanh toán..." className="misa-input" />
                        </Form.Item>
                    </div>
                </div>

                {/* 2. Cách tính hạn thanh toán */}
                <div className="misa-filter-box-compact misa-flex-col misa-mb-12">
                    <div className="misa-font-13-bold-dark misa-mb-8">
                        Cách tính hạn thanh toán
                    </div>

                    <Radio.Group value={calcType} onChange={e => setCalcType(e.target.value)} className="misa-w-full misa-flex-col-gap-10">
                        {/* Radio 1: Số ngày được nợ */}
                        <div className="misa-flex-col-gap-4">
                            <Radio value="due_days">
                                <span className="misa-font-13">
                                    Thanh toán sau{' '}
                                </span>
                                <Form.Item name="due_days" noStyle initialValue={30}>
                                    <InputNumber
                                        min={0}
                                        size="small"
                                        className="misa-input-w70 misa-mx-4 misa-text-center"
                                        disabled={calcType !== 'due_days'}
                                    />
                                </Form.Item>
                                <span className="misa-font-13">
                                    ngày kể từ ngày chứng từ.
                                </span>
                            </Radio>

                            {calcType === 'due_days' && (
                                <div className="apple-muted-text misa-pl-24">
                                    Hạn thanh toán = Ngày chứng từ + Số ngày được nợ
                                </div>
                            )}
                        </div>

                        {/* Radio 2: Cố định ngày thanh toán hàng tháng */}
                        <div className="misa-flex-col-gap-4">
                            <Radio value="fixed_day">
                                <span className="misa-font-13">
                                    Thanh toán vào ngày{' '}
                                </span>
                                <Form.Item name="fixed_day" noStyle initialValue={25}>
                                    <InputNumber
                                        min={1}
                                        max={31}
                                        size="small"
                                        className="misa-input-w70 misa-mx-4 misa-text-center"
                                        disabled={calcType !== 'fixed_day'}
                                    />
                                </Form.Item>
                                <span className="misa-font-13">
                                    hàng tháng, sau{' '}
                                </span>
                                <Form.Item name="fixed_months" noStyle initialValue={1}>
                                    <InputNumber
                                        min={0}
                                        size="small"
                                        className="misa-input-w70 misa-mx-4 misa-text-center"
                                        disabled={calcType !== 'fixed_day'}
                                    />
                                </Form.Item>
                                <span className="misa-font-13">
                                    tháng kể từ ngày chứng từ.
                                </span>
                            </Radio>
                        </div>
                    </Radio.Group>
                </div>

                {/* 3. Chiết khấu */}
                <div className="misa-table-card misa-p-10-12">
                    <div className="misa-font-13-bold-dark misa-mb-8">
                        Chiết khấu
                    </div>

                    <div className="misa-grid-2col-gap-16">
                        <div>
                            <div className="misa-label-bold">
                                Thời hạn hưởng chiết khấu
                            </div>
                            <Form.Item name="discount_days" noStyle initialValue={0}>
                                <InputNumber
                                    min={0}
                                    className="misa-w-full misa-input"
                                    addonAfter="Ngày"
                                />
                            </Form.Item>
                        </div>

                        <div>
                            <div className="misa-label-bold">
                                Tỷ lệ chiết khấu
                            </div>
                            <Form.Item name="discount_rate" noStyle initialValue={0}>
                                <InputNumber
                                    min={0}
                                    max={100}
                                    step={0.1}
                                    className="misa-w-full misa-input"
                                    addonAfter="%"
                                />
                            </Form.Item>
                        </div>
                    </div>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default QuickAddPaymentTermModal;
