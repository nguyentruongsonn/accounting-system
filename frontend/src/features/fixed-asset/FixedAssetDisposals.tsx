import React, { useState } from 'react';
import { Table, Button, Space, Input, Select } from 'antd';
import type { MenuProps } from 'antd';
import { PlusOutlined, ReloadOutlined, PrinterOutlined, SearchOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { formatDate } from '../../utils/dateUtils';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';
import { AssetDisposalModal } from './modals/AssetDisposalModal';
import { VoucherPrintModal } from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

type FixedAssetDisposalsProps = { embedded?: boolean };

export const FixedAssetDisposals: React.FC<FixedAssetDisposalsProps> = ({ embedded = false }) => {
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printRecord, setPrintRecord] = useState<any>(null);

    // Filters
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'posted' | 'draft'>('all');

    React.useEffect(() => {
        const handleOpen = () => setIsCreateModalOpen(true);
        window.addEventListener('open-fixed-asset-disposal', handleOpen);
        return () => window.removeEventListener('open-fixed-asset-disposal', handleOpen);
    }, []);

    const queryClient = useQueryClient();

    const { data: disposals = [], isLoading } = useQuery<any[]>({
        queryKey: ['fixed-assets-disposals'],
        queryFn: async () => {
            const { data } = await api.get('/fixed-assets/disposals');
            return Array.isArray(data) ? data : (data?.data || []);
        },
    });

    const filteredDisposals = React.useMemo(() => {
        return disposals.filter((item: any) => {
            if (searchText.trim()) {
                const q = searchText.toLowerCase();
                const matchVoucher = (item.voucher_number || '').toLowerCase().includes(q);
                const matchCode = (item.asset_code || '').toLowerCase().includes(q);
                const matchName = (item.asset_name || '').toLowerCase().includes(q);
                const matchReason = (item.disposal_reason || '').toLowerCase().includes(q);
                if (!matchVoucher && !matchCode && !matchName && !matchReason) return false;
            }
            if (statusFilter === 'posted' && !item.is_posted) return false;
            if (statusFilter === 'draft' && item.is_posted) return false;
            return true;
        });
    }, [disposals, searchText, statusFilter]);

    const columns = [
        {
            title: 'Ngày chứng từ',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 120,
            render: (v: any) => formatDate(v) || '—',
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
            key: 'is_posted',
            width: 120,
            align: 'center' as const,
            render: (_: any, record: any) => <VoucherStatusBadge status={record} />
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 120,
            align: 'center' as const,
            render: (_: any, record: any) => {
                const handlePrint = () => {
                    setPrintRecord({
                        ...record,
                        voucher_type_name: 'BIÊN BẢN THANH LÝ / GHI GIẢM TSCĐ',
                    });
                    setIsPrintModalOpen(true);
                };

                const menuItems: MenuProps['items'] = [
                    {
                        key: 'print',
                        icon: <PrinterOutlined />,
                        label: 'In biên bản thanh lý TSCĐ',
                        onClick: handlePrint,
                    }
                ];

                return (
                    <VoucherActionCell
                        primaryActionLabel="In"
                        onPrimaryAction={handlePrint}
                        menuItems={menuItems}
                    />
                );
            }
        }
    ];

    return (
        <PageShell
            embedded={embedded}
            title={<PageHeader eyebrow="Tài sản cố định" title="Chứng từ Thanh lý / Ghi giảm TSCĐ" description={`${disposals.length} chứng từ trong danh sách.`} />}
            toolbar={
                <PageToolbar
                    filters={
                        <div className="flex items-center gap-2">
                            <Input
                                placeholder="Tìm theo số chứng từ, mã TSCĐ..."
                                prefix={<SearchOutlined className="text-slate-400" />}
                                value={searchText}
                                onChange={(e) => setSearchText(e.target.value)}
                                style={{ width: 240 }}
                                size="small"
                                allowClear
                            />
                            <Select
                                value={statusFilter}
                                onChange={setStatusFilter}
                                size="small"
                                style={{ width: 140 }}
                                options={[
                                    { label: 'Tất cả trạng thái', value: 'all' },
                                    { label: 'Đã ghi sổ', value: 'posted' },
                                    { label: 'Chưa ghi sổ', value: 'draft' },
                                ]}
                            />
                        </div>
                    }
                    actions={
                        <Space>
                            <Button
                                icon={<ReloadOutlined />}
                                onClick={() => void runManualDataLoad(
                                    () => queryClient.invalidateQueries({ queryKey: ['fixed-assets-disposals'] }),
                                    { success: 'Tải lại danh sách thanh lý TSCĐ thành công.', failure: 'Không thể tải lại danh sách thanh lý TSCĐ.' },
                                )}
                            >
                                Nạp lại
                            </Button>
                            <Button
                                type="primary"
                                icon={<PlusOutlined />}
                                onClick={() => setIsCreateModalOpen(true)}
                                className="misa-btn-primary"
                            >
                                Thanh lý TSCĐ
                            </Button>
                        </Space>
                    }
                />
            }
        >
            <DataTableSurface className="fixed-asset-disposals-table-surface">
                <div className="flex-1 flex flex-col p-3 overflow-hidden">
                    <div className="flex-1 bg-white rounded-md border border-slate-200 overflow-hidden flex flex-col">
                        <div className="px-3 py-2 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs font-bold text-slate-700 uppercase">
                                Danh sách chứng từ thanh lý / ghi giảm TSCĐ
                            </span>
                            <div className="flex items-center gap-2">
                                <Input
                                    placeholder="Tìm chứng từ, mã TSCĐ..."
                                    prefix={<SearchOutlined className="text-slate-400" />}
                                    value={searchText}
                                    onChange={(e) => setSearchText(e.target.value)}
                                    style={{ width: 220 }}
                                    size="small"
                                    allowClear
                                />
                                <Select
                                    value={statusFilter}
                                    onChange={setStatusFilter}
                                    size="small"
                                    style={{ width: 130 }}
                                    options={[
                                        { label: 'Tất cả', value: 'all' },
                                        { label: 'Đã ghi sổ', value: 'posted' },
                                        { label: 'Chưa ghi sổ', value: 'draft' },
                                    ]}
                                />
                            </div>
                        </div>
                        <div className="flex-1 overflow-auto">
                            <Table
                                columns={columns}
                                dataSource={filteredDisposals}
                                rowKey="id"
                                size="small"
                                loading={isLoading}
                                pagination={{ pageSize: 10, size: 'small' }}
                            />
                        </div>
                    </div>
                </div>
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
