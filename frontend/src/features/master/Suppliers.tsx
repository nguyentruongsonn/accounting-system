import React, { useState } from 'react';
import { Alert, Table, Button, Space, Tag } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { QuickAddContactModal } from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import MasterDataNavigation from './MasterDataNavigation';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

interface Supplier {
    id: number;
    code: string;
    name: string;
    tax_code: string;
    address: string;
    phone: string;
    email: string;
    default_account: string;
    is_active: boolean;
}

function parseSupplierPage(value: unknown): { data: Supplier[]; per_page?: number; total?: number } {
    if (!value || typeof value !== 'object' || !Array.isArray((value as { data?: unknown }).data)) {
        throw new Error('Invalid supplier list response.');
    }
    return value as { data: Supplier[]; per_page?: number; total?: number };
}

const Suppliers: React.FC = () => {
    const queryClient = useQueryClient();
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [page, setPage] = useState(1);

    const { data, isLoading, isError, refetch: refetchSuppliers } = useQuery({
        queryKey: ['suppliers', page],
        queryFn: async () => {
            const { data } = await api.get(`/master/suppliers?page=${page}`);
            return parseSupplierPage(data);
        },
    });

    const handleDelete = async (id: number) => {
        try {
            const response = await api.delete(`/master/suppliers/${id}`);
            if (response?.status !== 204) {
                throw new Error('Máy chủ không xác nhận đã xóa nhà cung cấp.');
            }
            message.success('Xóa nhà cung cấp thành công!');
            queryClient.invalidateQueries({ queryKey: ['suppliers'] });
        } catch (error) {
            message.error('Không thể xóa nhà cung cấp này!');
        }
    };

    const columns = [
        { title: 'Mã NCC', dataIndex: 'code', key: 'code', width: '10%' },
        { title: 'Tên Nhà cung cấp', dataIndex: 'name', key: 'name' },
        { title: 'Mã số thuế', dataIndex: 'tax_code', key: 'tax_code', width: '15%' },
        { title: 'Điện thoại', dataIndex: 'phone', key: 'phone', width: '15%' },
        { title: 'TK Công nợ', dataIndex: 'default_account', key: 'default_account', width: '10%', render: (text: string) => <Tag color="orange">{text || '—'}</Tag> },
        {
            title: 'Trạng thái',
            dataIndex: 'is_active',
            key: 'is_active',
            width: '10%',
            render: (active: boolean) => (
                <Tag color={active ? 'green' : 'default'}>{active ? 'Hoạt động' : 'Ngừng'}</Tag>
            )
        },
        {
            title: 'Hành động',
            key: 'action',
            width: '10%',
            render: (_: any, record: Supplier) => (
                <Space size="middle">
                    <Button 
                        type="text" 
                        danger 
                        icon={<DeleteOutlined />} 
                        className="misa-btn-tool"
                        onClick={() => handleDelete(record.id)}
                    />
                </Space>
            ),
        },
    ];

    return (
        <PageShell
            navigation={<MasterDataNavigation />}
            title={<PageHeader eyebrow="DANH MỤC" title="Nhà cung cấp" description="Quản lý thông tin nhà cung cấp do máy chủ cung cấp." />}
            toolbar={<PageToolbar
                actions={
                    <Button 
                        type="primary" 
                        icon={<PlusOutlined />} 
                        className="misa-btn-primary"
                        onClick={() => setIsModalOpen(true)}
                    >
                        Thêm Nhà cung cấp
                    </Button>
                }
            />}
        >

            {isError && <Alert
                className="mb-4"
                type="error"
                showIcon
                message="Không thể tải danh sách nhà cung cấp"
                description="Máy chủ trả về catalogue không hợp lệ. Danh sách trống bên dưới không phải dữ liệu thay thế."
                action={<Button size="small" onClick={() => void refetchSuppliers()}>Thử lại danh sách nhà cung cấp</Button>}
            />}
            <DataTableSurface>
                <Table
                    columns={columns}
                    dataSource={data?.data || []}
                    rowKey="id"
                    loading={isLoading}
                    pagination={{
                        current: page,
                        pageSize: data?.per_page || 20,
                        total: data?.total || 0,
                        onChange: (p) => setPage(p)
                    }}
                    className="misa-voucher-table"
                />
            </DataTableSurface>

            <QuickAddContactModal
                open={isModalOpen}
                contactType="supplier"
                onCancel={() => setIsModalOpen(false)}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['suppliers'] });
                }}
            />
        </PageShell>
    );
};

export default Suppliers;
