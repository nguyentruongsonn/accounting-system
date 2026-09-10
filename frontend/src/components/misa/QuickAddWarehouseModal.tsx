import React, { useEffect } from 'react';
import { Alert, Form, Input, Select, Button } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import ModalFrame from '../layout/ModalFrame';
import { MisaButton } from './MisaButton';

interface QuickAddWarehouseModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: (newWarehouse: any) => void;
}

export const QuickAddWarehouseModal: React.FC<QuickAddWarehouseModalProps> = ({
    open,
    onCancel,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const accountsQuery = useQuery({
        queryKey: ['accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            const rows = Array.isArray(data)
                ? data
                : data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)
                    ? (data as { data: unknown[] }).data
                    : null;
            if (!Array.isArray(rows)) throw new Error('Invalid account catalogue response');
            return rows;
        }
    });
    const { data: accounts = [] } = accountsQuery;

    const accountList = accounts;
    const invAccounts = accountList.filter((a: any) =>
        typeof a?.code === 'string' && (a.code.startsWith('15') || a.code.startsWith('611'))
    );

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const { data } = await api.post('/master/warehouses', {
                code: values.code,
                name: values.name,
                default_account: values.default_account || undefined,
                address: values.address || null,
                manager_name: values.manager_name || null,
                description: values.description || '',
            });
            return data;
        },
        onSuccess: (data, variables: any) => {
            if (!data || data.id === undefined || data.id === null) {
                message.error('Máy chủ không trả về kho đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success(`Đã thêm kho ${data.code} thành công!`);
            queryClient.invalidateQueries({ queryKey: ['warehouses'] });
            if (onSuccess) onSuccess(data);
            if (variables._keepOpen) {
                form.resetFields();
            } else {
                onCancel();
            }
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi lưu kho!');
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
            title={<span className="misa-modal-title">Thêm Kho</span>}
            open={open}
            onCancel={onCancel}
            width={580}
            zIndex={2200}
            centered={true}
            className="misa-clean-modal"
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
            {accountsQuery.isError ? (
                <Alert
                    className="misa-mb-12"
                    type="error"
                    showIcon
                    message="Không thể tải danh mục tài khoản kho"
                    description="Không có dữ liệu thay thế; hãy thử lại khi máy chủ sẵn sàng."
                    action={<Button size="small" onClick={() => void accountsQuery.refetch()}>Thử lại</Button>}
                />
            ) : null}
            <Form form={form} layout="vertical" size="small" className="misa-pt-8">
                <div className="misa-grid-2col-gap-16 misa-mb-12">
                    <div>
                        <div className="misa-label-bold">
                            Mã <span className="misa-text-red">*</span>
                        </div>
                        <Form.Item name="code" rules={[{ required: true, message: 'Nhập mã kho' }]} noStyle>
                            <Input placeholder="Mã kho..." autoFocus className="misa-input misa-text-bold" />
                        </Form.Item>
                    </div>

                    <div>
                        <div className="misa-label-bold">
                            Tên <span className="misa-text-red">*</span>
                        </div>
                        <Form.Item name="name" rules={[{ required: true, message: 'Nhập tên kho' }]} noStyle>
                            <Input placeholder="Tên kho lưu trữ..." className="misa-input" />
                        </Form.Item>
                    </div>

                    <div>
                        <div className="misa-label-bold">
                            Tài khoản kho
                        </div>
                        <Form.Item name="default_account" noStyle>
                            <Select
                                showSearch
                                allowClear
                                placeholder="Chọn tài khoản kho"
                                 className="misa-w-full misa-input"
                                 notFoundContent="Chưa có tài khoản kho từ máy chủ"
                                 getPopupContainer={() => document.body}
                                 options={invAccounts.map((a: any) => ({
                                    value: a.code,
                                    label: `${a.code} - ${a.name}`
                                }))}
                            />
                        </Form.Item>
                    </div>

                    <div>
                        <div className="misa-label-bold">
                            Thủ kho
                        </div>
                        <Form.Item name="manager_name" noStyle>
                            <Input placeholder="Tên nhân viên quản lý kho..." className="misa-input" />
                        </Form.Item>
                    </div>
                </div>

                <div className="misa-mb-12">
                    <div className="misa-label-bold">
                        Địa chỉ kho
                    </div>
                    <Form.Item name="address" noStyle>
                        <Input placeholder="Địa điểm đặt kho hàng..." className="misa-input" />
                    </Form.Item>
                </div>

                <div>
                    <div className="misa-label-bold">
                        Ghi chú
                    </div>
                    <Form.Item name="description" noStyle>
                        <Input.TextArea rows={2} placeholder="Thông tin ghi chú thêm về kho..." className="misa-input" />
                    </Form.Item>
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default QuickAddWarehouseModal;
