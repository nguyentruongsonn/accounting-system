import React, { useState } from 'react';
import { Table, Button, Space, Tag, Dropdown } from 'antd';
import type { MenuProps } from 'antd';
import { PlusOutlined, ReloadOutlined, PrinterOutlined, MoreOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { AssetRevaluationModal } from './modals/AssetRevaluationModal';
import { VoucherPrintModal } from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

type FixedAssetRevaluationsProps = { embedded?: boolean };

export const FixedAssetRevaluations: React.FC<FixedAssetRevaluationsProps> = ({ embedded = false }) => {
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printRecord, setPrintRecord] = useState<any>(null);
    const queryClient = useQueryClient();

    const { data: revaluations = [], isLoading } = useQuery<any[]>({
        queryKey: ['fixed-assets-revaluations'],
        queryFn: async () => {
            const { data } = await api.get('/fixed-assets/revaluations');
            return Array.isArray(data) ? data : (data?.data || []);
        },
    });

    const columns = [
        {
            title: 'Ngày chứng từ',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 120,
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 140,
            render: (text: string) => <span className="font-semibold text-blue-700">{text}</span>
        },
        {
            title: 'Mã TSCĐ',
            dataIndex: 'asset_code',
            key: 'asset_code',
            width: 110,
        },
        {
            title: 'Tên tài sản',
            dataIndex: 'asset_name',
            key: 'asset_name',
            ellipsis: true,
        },
        {
            title: 'Nguyên giá cũ',
            dataIndex: 'old_original_cost',
            key: 'old_original_cost',
            align: 'right' as const,
            render: (v: number) => Number(v || 0).toLocaleString('vi-VN') + ' đ'
        },
        {
            title: 'Nguyên giá mới',
            dataIndex: 'new_original_cost',
            key: 'new_original_cost',
            align: 'right' as const,
            render: (v: number) => <strong className="text-slate-800">{Number(v || 0).toLocaleString('vi-VN')} đ</strong>
        },
        {
            title: 'Chênh lệch (TK 412)',
            dataIndex: 'cost_difference',
            key: 'cost_difference',
            align: 'right' as const,
            render: (v: number) => {
                const num = Number(v || 0);
                return (
                    <span className={`font-bold ${num >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                        {num >= 0 ? '+' : ''}{num.toLocaleString('vi-VN')} đ
                    </span>
                );
            }
        },
        {
            title: 'Lý do đánh giá lại',
            dataIndex: 'reason',
            key: 'reason',
            ellipsis: true,
        },
        {
            title: 'Trạng thái',
            dataIndex: 'is_posted',
            key: 'is_posted',
            width: 120,
            align: 'center' as const,
            render: (isPosted: boolean) => (
                <Tag color={isPosted ? 'success' : 'default'}>
                    {isPosted ? 'Đã ghi sổ' : 'Bản nháp'}
                </Tag>
            )
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 100,
            align: 'center' as const,
            render: (_: any, record: any) => {
                const menuItems: MenuProps['items'] = [
                    {
                        key: 'print',
                        icon: <PrinterOutlined />,
                        label: 'In biên bản đánh giá lại',
                        onClick: () => {
                            setPrintRecord({
                                ...record,
                                voucher_type_name: 'BIÊN BẢN ĐÁNH GIÁ LẠI TSCĐ',
                            });
                            setIsPrintModalOpen(true);
                        }
                    }
                ];

                return (
                    <Dropdown menu={{ items: menuItems }} trigger={['click']}>
                        <Button type="text" size="small" icon={<MoreOutlined />} />
                    </Dropdown>
                );
            }
        }
    ];

    return (
        <PageShell embedded={embedded} title={<PageHeader eyebrow="Tài sản cố định" title="Chứng từ Đánh giá lại TSCĐ" description={`${revaluations.length} chứng từ trong danh sách.`} />} toolbar={<PageToolbar actions={<Space>
                        <Button 
                            icon={<ReloadOutlined />} 
                            onClick={() => queryClient.invalidateQueries({ queryKey: ['fixed-assets-revaluations'] })}
                        >
                            Nạp lại
                        </Button>
                        <Button 
                            type="primary" 
                            icon={<PlusOutlined />} 
                            onClick={() => setIsCreateModalOpen(true)}
                            className="misa-btn-primary"
                        >
                            Đánh giá lại TSCĐ (F8/F9)
                        </Button>
                    </Space>} />}>

                <DataTableSurface className="fixed-asset-revaluations-table-surface">
                    <Table
                        columns={columns}
                        dataSource={revaluations}
                        rowKey="id"
                        size="small"
                        loading={isLoading}
                        pagination={{ pageSize: 10, size: 'small' }}
                    />
                </DataTableSurface>

            <AssetRevaluationModal
                open={isCreateModalOpen}
                onCancel={() => setIsCreateModalOpen(false)}
                onSuccess={() => queryClient.invalidateQueries({ queryKey: ['fixed-assets-revaluations'] })}
            />

            <VoucherPrintModal
                open={isPrintModalOpen}
                onClose={() => setIsPrintModalOpen(false)}
                data={printRecord}
            />
        </PageShell>
    );
};

export default FixedAssetRevaluations;
