import React, { useEffect } from 'react';
import { Alert, Form, Input, Select, Button } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import ModalFrame from '../layout/ModalFrame';
import { MisaButton } from './MisaButton';

interface QuickAddItemCategoryModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: (newCategory: any) => void;
}

const parseListPayload = (payload: unknown): any[] => {
    if (Array.isArray(payload)) return payload;
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: any[] }).data;
    }
    throw new Error('Phản hồi danh mục nhóm hàng hóa không hợp lệ');
};

export const QuickAddItemCategoryModal: React.FC<QuickAddItemCategoryModalProps> = ({
    open,
    onCancel,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const categoriesQuery = useQuery({
        queryKey: ['item-categories'],
        queryFn: async () => {
            const { data } = await api.get('/master/item-categories');
            return parseListPayload(data);
        }
    });
    const { data: categories = [], isError: categoriesLoadError } = categoriesQuery;

    const categoryList = Array.isArray(categories) ? categories : [];

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const { data } = await api.post('/master/item-categories', {
                code: values.code,
                name: values.name,
                parent_code: values.parent_code || null,
                description: values.description || '',
            });
            return data;
        },
        onSuccess: (data, variables: any) => {
            if (!data || data.id === undefined || data.id === null) {
                message.error('Máy chủ không trả về nhóm hàng hóa đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success(`Đã thêm nhóm VTHH ${data.code} thành công!`);
            queryClient.invalidateQueries({ queryKey: ['item-categories'] });
            if (onSuccess) onSuccess(data);
            if (variables._keepOpen) {
                form.resetFields();
            } else {
                onCancel();
            }
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi lưu nhóm VTHH!');
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
            title={<span className="misa-modal-title">Thêm Nhóm vật tư, hàng hóa, dịch vụ</span>}
            open={open}
            onCancel={onCancel}
            width={540}
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
            {categoriesLoadError && (
                <Alert
                    type="error"
                    showIcon
                    message="Không thể tải danh mục nhóm hàng hóa"
                    description="Danh sách nhóm cha chưa tải được. Hãy thử lại trước khi chọn nhóm."
                    action={<Button size="small" onClick={() => void categoriesQuery.refetch()}>Thử lại</Button>}
                    className="misa-mb-12"
                />
            )}
            <Form form={form} layout="vertical" size="small" className="misa-pt-8">
                <div className="misa-grid-1-2-gap8-12">
                    <div>
                        <div className="misa-form-label-semibold">
                            Mã <span className="misa-text-danger">*</span>
                        </div>
                        <Form.Item name="code" rules={[{ required: true, message: 'Nhập mã nhóm' }]} noStyle>
                            <Input placeholder="Mã nhóm..." autoFocus className="misa-input-h30-bold-700" />
                        </Form.Item>
                    </div>

                    <div>
                        <div className="misa-form-label-semibold">
                            Tên <span className="misa-text-danger">*</span>
                        </div>
                        <Form.Item name="name" rules={[{ required: true, message: 'Nhập tên nhóm' }]} noStyle>
                            <Input placeholder="Tên nhóm VTHH..." className="misa-input-h30" />
                        </Form.Item>
                    </div>

                    <div className="misa-col-span-2">
                        <div className="misa-form-label-semibold">
                            Thuộc nhóm
                        </div>
                        <Form.Item name="parent_code" noStyle>
                            <Select
                                allowClear
                                placeholder="Chọn nhóm cha (nếu có)"
                                className="misa-w-full misa-h-30"
                                options={categoryList.map((c: any) => ({ value: c.code, label: `${c.code} - ${c.name}` }))}
                                getPopupContainer={() => document.body}
                            />
                        </Form.Item>
                    </div>

                    <div className="misa-col-span-2">
                        <div className="misa-form-label-semibold">
                            Diễn giải
                        </div>
                        <Form.Item name="description" noStyle>
                            <Input.TextArea rows={2} placeholder="Mô tả nhóm hàng..." className="misa-border-radius-4" />
                        </Form.Item>
                    </div>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default QuickAddItemCategoryModal;
