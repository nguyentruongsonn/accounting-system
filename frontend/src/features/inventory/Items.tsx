import React, { useState } from 'react';
import { Alert, Table, Button, Form, Input, Select } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { PlusOutlined } from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import PageHeader from '../../components/layout/PageHeader';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';

function parseItemsResponse(value: unknown): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error('Invalid inventory items response');
}

const EMPTY_ITEMS: any[] = [];

const Items: React.FC = () => {
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const { data: items, isLoading, isError, refetch: refetchItems } = useQuery({
        queryKey: ['items'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parseItemsResponse(data);
        },
    });

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            return api.post('/inventory/items', values);
        },
        onSuccess: (response: any) => {
            const persistedItem = response?.data?.data ?? response?.data;
            if (!persistedItem || persistedItem.id === undefined || persistedItem.id === null) {
                message.error('Máy chủ không trả về vật tư đã lưu; không thể báo thành công.');
                return;
            }
            message.success('Thêm mới thành công');
            setIsModalVisible(false);
            queryClient.invalidateQueries({ queryKey: ['items'] });
        },
    });

    const columns = [
        { title: 'Mã', dataIndex: 'code', key: 'code' },
        { title: 'Tên Vật tư / Hàng hóa', dataIndex: 'name', key: 'name' },
        { title: 'Loại', dataIndex: 'type', key: 'type', render: (val: string) => val === 'Goods' ? 'Hàng hóa' : 'Dịch vụ' },
        { title: 'ĐVT', dataIndex: 'unit', key: 'unit' },
        { 
            title: 'Trạng thái', 
            dataIndex: 'is_active', 
            key: 'is_active',
            render: (val: boolean) => <span className={`px-2 py-1 rounded text-xs ${val ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>{val ? 'Hoạt động' : 'Ngừng'}</span>
        },
    ];

    return (
        <PageShell
            title={<PageHeader eyebrow="Kho" title="Danh mục Vật tư hàng hóa" description="Danh mục vật tư và hàng hóa do máy chủ quản lý." />}
            toolbar={<PageToolbar actions={
                <Button type="primary" icon={<PlusOutlined />} onClick={() => { form.resetFields(); setIsModalVisible(true); }} className="misa-btn-primary">
                    Thêm mới
                </Button>
            } />}
        >

            {isError && (
                <Alert
                    className="mb-4"
                    type="error"
                    showIcon
                    title="Không thể tải danh sách vật tư hàng hóa"
                    description="Không hiển thị dữ liệu thay thế; hãy thử tải lại danh mục từ máy chủ."
                    action={<Button size="small" onClick={() => void refetchItems()}>Thử lại danh sách vật tư hàng hóa</Button>}
                />
            )}
            <DataTableSurface>
                <Table columns={columns} dataSource={items ?? EMPTY_ITEMS} rowKey="id" loading={isLoading} />
            </DataTableSurface>

            <Modal
                title="Thêm Vật tư hàng hóa"
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                onOk={() => form.submit()}
                confirmLoading={mutation.isPending}
            >
                <ModalFrame>
                <Form form={form} layout="vertical" onFinish={mutation.mutate}>
                    <Form.Item name="type" label="Loại" rules={[{ required: true }]} initialValue="Goods">
                        <Select>
                            <Select.Option value="Goods">Hàng hóa (Vật tư, Thành phẩm...)</Select.Option>
                            <Select.Option value="Service">Dịch vụ</Select.Option>
                        </Select>
                    </Form.Item>
                    <Form.Item name="code" label="Mã hàng" rules={[{ required: true }]}>
                        <Input />
                    </Form.Item>
                    <Form.Item name="name" label="Tên hàng hóa/dịch vụ" rules={[{ required: true }]}>
                        <Input />
                    </Form.Item>
                    <Form.Item name="unit" label="Đơn vị tính">
                        <Input placeholder="Cái, Chiếc, Kg..." />
                    </Form.Item>
                    <Form.Item name="description" label="Mô tả">
                        <Input.TextArea />
                    </Form.Item>
                </Form>
                </ModalFrame>
            </Modal>
        </PageShell>
    );
};

export default Items;
