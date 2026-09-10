import React, { useEffect } from 'react';
import { Form, Input } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import ModalFrame from '../layout/ModalFrame';
import { MisaButton } from './MisaButton';

interface QuickAddUnitModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: (newUnit: any) => void;
}

export const QuickAddUnitModal: React.FC<QuickAddUnitModalProps> = ({
    open,
    onCancel,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const { data } = await api.post('/master/units', {
                code: values.name,
                name: values.name,
                description: values.description || '',
            });
            return data;
        },
        onSuccess: (data, variables: any) => {
            if (!data || data.id === undefined || data.id === null) {
                message.error('Máy chủ không trả về đơn vị tính đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success(`Đã thêm đơn vị tính ${data.name} thành công!`);
            queryClient.invalidateQueries({ queryKey: ['units'] });
            if (onSuccess) onSuccess(data);
            if (variables._keepOpen) {
                form.resetFields();
            } else {
                onCancel();
            }
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi lưu đơn vị tính!');
        }
    });

    useEffect(() => {
        if (open) {
            form.resetFields();
        }
    }, [open, form]);

    const handleSave = (keepOpen: boolean = false) => {
        form.validateFields().then(values => {
            mutation.mutate({ ...values, _keepOpen: keepOpen });
        });
    };

    return (
        <Modal
            title={<span className="misa-modal-title">Thêm Đơn vị tính</span>}
            open={open}
            onCancel={onCancel}
            width={480}
            zIndex={2200}
            centered={true}
            className="misa-clean-modal"
            footer={
                <div className="misa-modal-footer">
                    <MisaButton onClick={onCancel}>
                        Hủy
                    </MisaButton>
                    <div className="misa-flex-gap-8">
                        <MisaButton
                            onClick={() => handleSave(false)}
                            loading={mutation.isPending}
                        >
                            Cất (Ctrl+S)
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
                <div className="misa-mb-12">
                    <div className="misa-form-label-semibold">
                        Đơn vị tính <span className="misa-text-danger">*</span>
                    </div>
                    <Form.Item name="name" rules={[{ required: true, message: 'Nhập tên đơn vị tính' }]} noStyle>
                        <Input placeholder="Ví dụ: Cái, Hộp, Thùng, Kg, Tấn..." autoFocus className="misa-input-bold" />
                    </Form.Item>
                </div>

                <div>
                    <div className="misa-form-label-semibold">
                        Mô tả
                    </div>
                    <Form.Item name="description" noStyle>
                        <Input.TextArea rows={3} placeholder="Mô tả đơn vị tính..." className="misa-border-radius-4" />
                    </Form.Item>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default QuickAddUnitModal;
