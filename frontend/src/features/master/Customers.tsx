import React, { useState } from 'react';
import { Table, Button, Space, Tag } from 'antd';
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
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

interface Customer {
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

function parseCustomerPage(value: unknown): { data: Customer[]; per_page?: number; total?: number } {
    if (!value || typeof value !== 'object' || !Array.isArray((value as { data?: unknown }).data)) {
        throw new Error('Invalid customer list response.');
    }
    return value as { data: Customer[]; per_page?: number; total?: number };
}

const Customers: React.FC = () => {
    const queryClient = useQueryClient();
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [page, setPage] = useState(1);

    const { data, isLoading, isError, refetch: refetchCustomers } = useQuery({
        queryKey: ['customers', page],
        queryFn: async () => {
            const { data } = await api.get(`/master/customers?page=${page}`);
            return parseCustomerPage(data);
        },
    });

    const handleDelete = async (id: number) => {
        try {
            const response = await api.delete(`/master/customers/${id}`);
            if (response?.status !== 204) {
                throw new Error('Máy chủ không xác nhận đã xóa khách hàng.');
            }
            message.success('Xóa khách hàng thành công!');
            queryClient.invalidateQueries({ queryKey: ['customers'] });
        } catch (error: any) {
            message.error(error?.response?.data?.message || 'Không thể xóa khách hàng này!');
        }
    };

    const columns = [
        { title: 'Mã KH', dataIndex: 'code', key: 'code', width: '10%' },
        { title: 'Tên Khách hàng', dataIndex: 'name', key: 'name' },
        { title: 'Mã số thuế', dataIndex: 'tax_code', key: 'tax_code', width: '15%' },
        { title: 'Điện thoại', dataIndex: 'phone', key: 'phone', width: '15%' },
        { title: 'TK Công nợ', dataIndex: 'default_account', key: 'default_account', width: '10%', render: (text: string) => <Tag color="blue">{text || '—'}</Tag> },
        {
            title: 'Trạng thái',
            dataIndex: 'is_active',
            key: 'is_active',
            width: '10%',
            align: 'center' as const,
            render: (active: boolean) => (
                <span className={`misa-apple-pill ${active ? 'misa-apple-pill-green' : 'misa-apple-pill-gray'}`}>
                    <span className="misa-apple-pill-dot" />
                    {active ? 'Hoạt động' : 'Ngừng'}
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: '10%',
            render: (_: any, record: Customer) => (
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
            title={<PageHeader eyebrow="DANH MỤC" title="Khách hàng" description="Quản lý thông tin khách hàng do máy chủ cung cấp." />}
            toolbar={<PageToolbar
                actions={
                    <Button 
                        type="primary" 
                        icon={<PlusOutlined />} 
                        className="misa-btn-primary"
                        onClick={() => setIsModalOpen(true)}
                    >
                        Thêm Khách hàng
                    </Button>
                }
            />}
        >

            {isError && (
                <div className="misa-p-12 misa-mb-12" style={{ background: '#FEF2F2', border: '1px solid #FECACA', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <div>
                        <div style={{ fontWeight: 600, color: '#991B1B', fontSize: 13 }}>Không thể tải danh sách khách hàng</div>
                        <div style={{ color: '#B91C1C', fontSize: 12 }}>Máy chủ trả về catalogue không hợp lệ. Danh sách trống bên dưới không phải dữ liệu thay thế.</div>
                    </div>
                    <Button size="small" onClick={() => void runManualDataLoad(() => refetchCustomers(), { success: 'Tải lại danh sách khách hàng thành công.', failure: 'Không thể tải lại danh sách khách hàng.' })}>Thử lại danh sách khách hàng</Button>
                </div>
            )}
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
                contactType="customer"
                onCancel={() => setIsModalOpen(false)}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['customers'] });
                }}
            />
        </PageShell>
    );
};

export default Customers;
