import React, { useState } from 'react';
import { Alert, Table, Button, Form, Input, Space, Tag } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

function parseBankAccountList(value: unknown): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error('Invalid bank-account list response');
}

const BankAccounts: React.FC = () => {
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [editingAccount, setEditingAccount] = useState<any>(null);
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const { data: bankAccounts = [], isLoading, isError: isBankAccountsError, refetch: refetchBankAccounts } = useQuery({
        queryKey: ['bank-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/bank/accounts');
            return parseBankAccountList(data);
        },
    });

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            if (editingAccount) {
                return api.put(`/bank/accounts/${editingAccount.id}`, values);
            }
            return api.post('/bank/accounts', values);
        },
        onSuccess: (response) => {
            if (response?.data?.id === undefined || response?.data?.id === null) {
                message.error('Máy chủ không trả về tài khoản ngân hàng đã lưu; không thể báo thành công.');
                return;
            }
            message.success('Lưu thành công');
            setIsModalVisible(false);
            queryClient.invalidateQueries({ queryKey: ['bank-accounts'] });
        },
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.delete(`/bank/accounts/${id}`);
        },
        onSuccess: (response) => {
            if (response?.status !== 204) {
                message.error('Máy chủ không xác nhận đã xóa tài khoản ngân hàng; không thể báo thành công.');
                return;
            }
            message.success('Xóa thành công');
            queryClient.invalidateQueries({ queryKey: ['bank-accounts'] });
        },
    });

    const showModal = (record?: any) => {
        if (record) {
            setEditingAccount(record);
            form.setFieldsValue(record);
        } else {
            setEditingAccount(null);
            form.resetFields();
            form.setFieldsValue({ currency: 'VND', is_active: true });
        }
        setIsModalVisible(true);
    };

    const columns = [
        { title: 'Số tài khoản', dataIndex: 'account_number', key: 'account_number' },
        { title: 'Ngân hàng', dataIndex: 'bank_name', key: 'bank_name' },
        { title: 'Chi nhánh', dataIndex: 'branch', key: 'branch' },
        { title: 'Tiền tệ', dataIndex: 'currency', key: 'currency' },
        {
            title: 'Trạng thái',
            dataIndex: 'is_active',
            key: 'is_active',
            render: (isActive: boolean) => (
                <Tag color={isActive ? 'green' : 'red'}>{isActive ? 'Đang hoạt động' : 'Đã khóa'}</Tag>
            )
        },
        {
            title: 'Hành động',
            key: 'action',
            render: (_: any, record: any) => (
                <Space size="middle">
                    <Button className="misa-btn-tool" icon={<EditOutlined />} onClick={() => showModal(record)} />
                    <Button 
                        danger 
                        className="misa-btn-tool"
                        icon={<DeleteOutlined />} 
                        onClick={() => deleteMutation.mutate(record.id)} 
                    />
                </Space>
            ),
        },
    ];

    return (
        <PageShell
            title={<PageHeader eyebrow="Ngân hàng" title="Danh mục Tài khoản Ngân hàng" description="Quản lý các tài khoản ngân hàng dùng trong nghiệp vụ thu, chi và đối chiếu." />}
        >
            <PageToolbar
                actions={
                    <Button 
                        type="primary" 
                        icon={<PlusOutlined />} 
                        className="misa-btn-primary"
                        onClick={() => showModal()}
                    >
                        Thêm tài khoản
                    </Button>
                }
            />

            <DataTableSurface className="bank-accounts-table-surface">
                {isBankAccountsError && (
                    <Alert
                        className="mb-3"
                        type="error"
                        showIcon
                        message="Không thể tải danh mục tài khoản ngân hàng"
                        description="Không hiển thị danh sách rỗng thay cho lỗi tải dữ liệu từ máy chủ."
                        action={<Button size="small" onClick={() => void refetchBankAccounts()}>Thử lại danh mục tài khoản ngân hàng</Button>}
                    />
                )}
                <Table 
                    className="misa-voucher-table"
                    columns={columns} 
                    dataSource={bankAccounts}
                    rowKey="id" 
                    loading={isLoading} 
                />
            </DataTableSurface>

            <Modal
                title={editingAccount ? "Sửa tài khoản ngân hàng" : "Thêm tài khoản ngân hàng"}
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                onOk={() => form.submit()}
                confirmLoading={mutation.isPending}
            >
                <Form form={form} layout="vertical" onFinish={mutation.mutate}>
                    <Form.Item name="account_number" label="Số tài khoản" rules={[{ required: true, message: 'Vui lòng nhập số tài khoản' }]}>
                        <Input disabled={!!editingAccount} />
                    </Form.Item>
                    <Form.Item name="bank_name" label="Tên ngân hàng (VD: Vietcombank)" rules={[{ required: true, message: 'Vui lòng nhập tên ngân hàng' }]}>
                        <Input />
                    </Form.Item>
                    <Form.Item name="branch" label="Chi nhánh">
                        <Input />
                    </Form.Item>
                    <Form.Item name="account_holder" label="Tên chủ tài khoản">
                        <Input />
                    </Form.Item>
                    <Form.Item name="currency" label="Loại tiền tệ">
                        <Input />
                    </Form.Item>
                    <Form.Item name="description" label="Ghi chú">
                        <Input.TextArea />
                    </Form.Item>
                </Form>
            </Modal>
        </PageShell>
    );
};

export default BankAccounts;
