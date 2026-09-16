import React, { useState } from 'react';
import { Table, Button, Space, Input, Select } from 'antd';
import type { MenuProps } from 'antd';
import { PlusOutlined, ReloadOutlined, PrinterOutlined, SearchOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { formatDate } from '../../utils/dateUtils';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';
import { AssetRevaluationModal } from './modals/AssetRevaluationModal';
import { VoucherPrintModal } from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

type FixedAssetRevaluationsProps = { embedded?: boolean };

export const FixedAssetRevaluations: React.FC<FixedAssetRevaluationsProps> = ({ embedded = false }) => {
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printRecord, setPrintRecord] = useState<any>(null);

    // Filters
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'posted' | 'draft'>('all');

    React.useEffect(() => {
        const handleOpen = () => setIsCreateModalOpen(true);
        window.addEventListener('open-fixed-asset-revaluation', handleOpen);
        return () => window.removeEventListener('open-fixed-asset-revaluation', handleOpen);
    }, []);

    const queryClient = useQueryClient();

    const { data: revaluations = [], isLoading } = useQuery<any[]>({
        queryKey: ['fixed-assets-revaluations'],
        queryFn: async () => {
            const { data } = await api.get('/fixed-assets/revaluations');
            return Array.isArray(data) ? data : (data?.data || []);
        },
    });

    const filteredRevaluations = React.useMemo(() => {
        return revaluations.filter((item: any) => {
            if (searchText.trim()) {
                const q = searchText.toLowerCase();
                const matchVoucher = (item.voucher_number || '').toLowerCase().includes(q);
                const matchCode = (item.asset_code || '').toLowerCase().includes(q);
                const matchName = (item.asset_name || '').toLowerCase().includes(q);
                const matchReason = (item.reason || '').toLowerCase().includes(q);
                if (!matchVoucher && !matchCode && !matchName && !matchReason) return false;
            }
            if (statusFilter === 'posted' && !item.is_posted) return false;
            if (statusFilter === 'draft' && item.is_posted) return false;
            return true;
        });
    }, [revaluations, searchText, statusFilter]);

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
                        voucher_type_name: 'BIÊN BẢN ĐÁNH GIÁ LẠI TSCĐ',
                    });
                    setIsPrintModalOpen(true);
                };

                const menuItems: MenuProps['items'] = [
                    {
                        key: 'print',
                        icon: <PrinterOutlined />,
                        label: 'In biên bản đánh giá lại',
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
            title={<PageHeader eyebrow="Tài sản cố định" title="Chứng từ Đánh giá lại TSCĐ" description={`${revaluations.length} chứng từ trong danh sách.`} />}
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
                                    () => queryClient.invalidateQueries({ queryKey: ['fixed-assets-revaluations'] }),
                                    { success: 'Tải lại danh sách đánh giá TSCĐ thành công.', failure: 'Không thể tải lại danh sách đánh giá TSCĐ.' },
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
                                Đánh giá lại TSCĐ
                            </Button>
                        </Space>
                    }
                />
            }
        >
            <DataTableSurface className="fixed-asset-revaluations-table-surface">
                <div className="flex-1 flex flex-col p-3 overflow-hidden">
                    <div className="flex-1 bg-white rounded-md border border-slate-200 overflow-hidden flex flex-col">
                        <div className="px-3 py-2 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs font-bold text-slate-700 uppercase">
                                Danh sách chứng từ đánh giá lại TSCĐ
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
                                dataSource={filteredRevaluations}
                                rowKey="id"
                                size="small"
                                loading={isLoading}
                                pagination={{ pageSize: 10, size: 'small' }}
                            />
                        </div>
                    </div>
                </div>
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
