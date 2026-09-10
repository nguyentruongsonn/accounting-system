import React, { useState } from 'react';
import { Table, Button, Space, Tag, Dropdown } from 'antd';
import type { MenuProps } from 'antd';
import { PlusOutlined, ReloadOutlined, PrinterOutlined, MoreOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { AssetDisposalModal } from './modals/AssetDisposalModal';
import { VoucherPrintModal } from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

type FixedAssetDisposalsProps = { embedded?: boolean };

export const FixedAssetDisposals: React.FC<FixedAssetDisposalsProps> = ({ embedded = false }) => {
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printRecord, setPrintRecord] = useState<any>(null);
    const queryClient = useQueryClient();

    const { data: disposals = [], isLoading } = useQuery<any[]>({
        queryKey: ['fixed-assets-disposals'],
        queryFn: async () => {
            const { data } = await api.get('/fixed-assets/disposals');
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
            title: 'Nguyên giá giảm (Có 211)',
            dataIndex: 'original_cost',
            key: 'original_cost',
            align: 'right' as const,
            render: (v: number) => Number(v || 0).toLocaleString('vi-VN') + ' đ'
        },
        {
            title: 'Đã hao mòn (Nợ 2141)',
            dataIndex: 'accumulated_depreciation',
            key: 'accumulated_depreciation',
            align: 'right' as const,
            render: (v: number) => <span className="text-orange-600">{Number(v || 0).toLocaleString('vi-VN')} đ</span>
        },
        {
            title: 'Giá trị còn lại (Nợ 811)',
            dataIndex: 'net_value',
            key: 'net_value',
            align: 'right' as const,
            render: (v: number) => <strong className="text-red-600">{Number(v || 0).toLocaleString('vi-VN')} đ</strong>
        },
        {
            title: 'Thu thanh lý (Có 711)',
            dataIndex: 'disposal_price',
            key: 'disposal_price',
            align: 'right' as const,
            render: (v: number) => <span className="text-emerald-700 font-semibold">{Number(v || 0).toLocaleString('vi-VN')} đ</span>
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
                        label: 'In biên bản thanh lý TSCĐ',
                        onClick: () => {
                            setPrintRecord({
                                ...record,
                                voucher_type_name: 'BIÊN BẢN THANH LÝ / GHI GIẢM TSCĐ',
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
        <PageShell embedded={embedded} title={<PageHeader eyebrow="Tài sản cố định" title="Chứng từ Thanh lý / Ghi giảm TSCĐ" description={`${disposals.length} chứng từ trong danh sách.`} />} toolbar={<PageToolbar actions={<Space>
                        <Button 
                            icon={<ReloadOutlined />} 
                            onClick={() => queryClient.invalidateQueries({ queryKey: ['fixed-assets-disposals'] })}
                        >
                            Nạp lại
                        </Button>
                        <Button 
                            type="primary" 
                            icon={<PlusOutlined />} 
                            onClick={() => setIsCreateModalOpen(true)}
                            className="misa-btn-primary"
                        >
                            Thanh lý TSCĐ (F8/F9)
                        </Button>
                    </Space>} />}>

                <DataTableSurface className="fixed-asset-disposals-table-surface">
                    <Table
                        columns={columns}
                        dataSource={disposals}
                        rowKey="id"
                        size="small"
                        loading={isLoading}
                        pagination={{ pageSize: 10, size: 'small' }}
                    />
                </DataTableSurface>

            <AssetDisposalModal
                open={isCreateModalOpen}
                onCancel={() => setIsCreateModalOpen(false)}
                onSuccess={() => queryClient.invalidateQueries({ queryKey: ['fixed-assets-disposals'] })}
            />

            <VoucherPrintModal
                open={isPrintModalOpen}
                onClose={() => setIsPrintModalOpen(false)}
                data={printRecord}
            />
        </PageShell>
    );
};

export default FixedAssetDisposals;
