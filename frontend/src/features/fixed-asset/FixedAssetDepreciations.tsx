import React, { useState } from 'react';
import { Table, Button, Space } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { MenuProps } from 'antd';
import {
    PlusOutlined,
    ReloadOutlined,
    CloseCircleOutlined,
    DeleteOutlined,
    PrinterOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { formatDate } from '../../utils/dateUtils';
import { VoucherPrintModal } from '../../components/misa';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';
import { RunDepreciationModal } from './modals/RunDepreciationModal';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { Input, Select } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

type FixedAssetDepreciationsProps = { embedded?: boolean };

export const FixedAssetDepreciations: React.FC<FixedAssetDepreciationsProps> = ({ embedded = false }) => {
    const [isRunModalOpen, setIsRunModalOpen] = useState(false);
    const [selectedLog, setSelectedLog] = useState<any>(null);
    const [selectedRowKeys, setSelectedRowKeys] = useState<React.Key[]>([]);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printRecord, setPrintRecord] = useState<any>(null);

    // Filters
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'posted' | 'draft'>('all');

    React.useEffect(() => {
        const handleOpen = () => setIsRunModalOpen(true);
        window.addEventListener('open-fixed-asset-depreciation', handleOpen);
        return () => window.removeEventListener('open-fixed-asset-depreciation', handleOpen);
    }, []);

    const queryClient = useQueryClient();

    const { data: periods = [], isLoading } = useQuery<any[]>({
        queryKey: ['fixed-assets-depreciation-periods'],
        queryFn: async () => {
            const { data } = await api.get('/fixed-assets/depreciation/periods');
            return Array.isArray(data) ? data : (data?.data || []);
        },
    });

    const filteredPeriods = React.useMemo(() => {
        return periods.filter((item: any) => {
            if (searchText.trim()) {
                const q = searchText.toLowerCase();
                const matchMonth = (item.month || '').toLowerCase().includes(q);
                const matchVoucher = (item.voucher_number || '').toLowerCase().includes(q);
                const matchDesc = (item.description || '').toLowerCase().includes(q);
                if (!matchMonth && !matchVoucher && !matchDesc) return false;
            }
            if (statusFilter === 'posted' && !item.is_posted) return false;
            if (statusFilter === 'draft' && item.is_posted) return false;
            return true;
        });
    }, [periods, searchText, statusFilter]);

    const { data: selectedLogDetail, isLoading: isDetailLoading } = useQuery<any>({
        queryKey: ['fixed-assets-depreciation-detail', selectedLog?.id],
        queryFn: async () => {
            if (!selectedLog?.id) return null;
            const { data } = await api.get(`/fixed-assets/depreciation/periods/${selectedLog.id}`);
            return data;
        },
        enabled: !!selectedLog?.id,
    });

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/fixed-assets/depreciation/${id}/unpost`);
        },
        onSuccess: (response: any) => {
            const depreciationLog = response?.data?.data;
            if (!depreciationLog || depreciationLog.id === undefined || depreciationLog.id === null) {
                message.error('Máy chủ không trả về kỳ khấu hao đã bỏ ghi sổ; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Bỏ ghi sổ và hoàn nhập trích khấu hao thành công!');
            queryClient.invalidateQueries({ queryKey: ['fixed-assets-depreciation-periods'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-assets-depreciation-detail'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.message || err?.response?.data?.error || 'Có lỗi khi bỏ ghi sổ kỳ khấu hao!');
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.delete(`/fixed-assets/depreciation/${id}`);
        },
        onSuccess: (response: any) => {
            if (!response?.data?.message) {
                message.error('Máy chủ không xác nhận đã xóa kỳ khấu hao; không thể báo thành công.');
                return;
            }

            message.success('Xóa kỳ trích khấu hao thành công!');
            setSelectedLog(null);
            queryClient.invalidateQueries({ queryKey: ['fixed-assets-depreciation-periods'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.message || err?.response?.data?.error || 'Có lỗi khi xóa kỳ khấu hao!');
        }
    });

    // Tự động chọn dòng đầu tiên nếu chưa chọn
    React.useEffect(() => {
        if (periods.length > 0 && !selectedLog) {
            setSelectedLog(periods[0]);
            setSelectedRowKeys([periods[0].id]);
        }
    }, [periods, selectedLog]);

    const masterColumns = [
        {
            title: 'Kỳ khấu hao',
            dataIndex: 'month',
            key: 'month',
            width: 120,
            render: (val: string) => <span className="font-semibold text-blue-700">{val}</span>
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 140,
        },
        {
            title: 'Ngày chứng từ',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 120,
            render: (v: any) => formatDate(v) || '—',
        },
        {
            title: 'Diễn giải',
            dataIndex: 'description',
            key: 'description',
            ellipsis: true,
        },
        {
            title: 'Tổng số tiền',
            dataIndex: 'total_amount',
            key: 'total_amount',
            align: 'right' as const,
            width: 160,
            render: (val: number) => <span className="font-bold text-slate-800">{Number(val || 0).toLocaleString('vi-VN')} đ</span>
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
            width: 140,
            align: 'center' as const,
            render: (_: any, record: any) => {
                const menuItems: MenuProps['items'] = [
                    {
                        key: 'print',
                        icon: <PrinterOutlined />,
                        label: 'In bảng tính khấu hao',
                        onClick: () => {
                            setPrintRecord({
                                ...record,
                                voucher_type_name: 'BẢNG TÍNH KHẤU HAO TSCĐ',
                                lines: selectedLogDetail?.lines || [],
                            });
                            setIsPrintModalOpen(true);
                        }
                    },
                    {
                        type: 'divider',
                    },
                    {
                        key: 'unpost',
                        icon: <CloseCircleOutlined />,
                        label: 'Bỏ ghi sổ / Hoàn nhập',
                        disabled: !record.is_posted,
                        onClick: () => unpostMutation.mutate(record.id)
                    },
                    {
                        key: 'delete',
                        icon: <DeleteOutlined />,
                        danger: true,
                        label: 'Xóa kỳ khấu hao',
                        onClick: () => {
                            Modal.confirm({
                                title: `Xác nhận xóa kỳ khấu hao tháng ${record.month}?`,
                                content: 'Hệ thống sẽ hoàn nhập lại nguyên trạng giá trị hao mòn của các tài sản.',
                                okText: 'Xóa',
                                okType: 'danger',
                                cancelText: 'Hủy',
                                onOk: () => deleteMutation.mutate(record.id)
                            });
                        }
                    }
                ];

                return (
                    <VoucherActionCell
                        primaryActionLabel="Xem"
                        onPrimaryAction={() => {
                            setSelectedLog(record);
                            setSelectedRowKeys([record.id]);
                        }}
                        menuItems={menuItems}
                    />
                );
            }
        }
    ];

    const detailColumns = [
        { title: 'STT', dataIndex: 'line_order', key: 'line_order', width: 50, align: 'center' as const },
        { title: 'Mã TSCĐ', dataIndex: 'asset_code', key: 'asset_code', width: 100 },
        { title: 'Tên tài sản', dataIndex: 'asset_name', key: 'asset_name', ellipsis: true },
        { title: 'Bộ phận', dataIndex: 'department_code', key: 'department_code', width: 100 },
        {
            title: 'Nguyên giá',
            dataIndex: 'original_cost',
            key: 'original_cost',
            align: 'right' as const,
            render: (v: number) => Number(v || 0).toLocaleString('vi-VN')
        },
        {
            title: 'Hao mòn trước',
            dataIndex: 'accumulated_depreciation_before',
            key: 'accumulated_depreciation_before',
            align: 'right' as const,
            render: (v: number) => Number(v || 0).toLocaleString('vi-VN')
        },
        {
            title: 'Trích kỳ này',
            dataIndex: 'monthly_depreciation',
            key: 'monthly_depreciation',
            align: 'right' as const,
            render: (v: number) => <strong className="text-blue-700">{Number(v || 0).toLocaleString('vi-VN')} đ</strong>
        },
        {
            title: 'Hao mòn sau',
            dataIndex: 'accumulated_depreciation_after',
            key: 'accumulated_depreciation_after',
            align: 'right' as const,
            render: (v: number) => Number(v || 0).toLocaleString('vi-VN')
        },
        {
            title: 'Giá trị còn lại',
            dataIndex: 'net_value_after',
            key: 'net_value_after',
            align: 'right' as const,
            render: (v: number) => Number(v || 0).toLocaleString('vi-VN')
        },
        { title: 'TK Nợ (Chi phí)', dataIndex: 'expense_account', key: 'expense_account', width: 110, align: 'center' as const },
        { title: 'TK Có (Hao mòn)', dataIndex: 'depreciation_account', key: 'depreciation_account', width: 110, align: 'center' as const },
    ];

    return (
        <PageShell
            embedded={embedded}
            title={<PageHeader eyebrow="Tài sản cố định" title="Bảng tính Khấu hao TSCĐ" description={`${periods.length} kỳ khấu hao trong danh sách.`} />}
            toolbar={
                <PageToolbar
                    filters={
                        <div className="flex items-center gap-2">
                            <Input
                                placeholder="Tìm theo kỳ, số chứng từ..."
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
                                    () => queryClient.invalidateQueries({ queryKey: ['fixed-assets-depreciation-periods'] }),
                                    { success: 'Tải lại kỳ khấu hao thành công.', failure: 'Không thể tải lại kỳ khấu hao.' },
                                )}
                            >
                                Nạp lại
                            </Button>
                            <Button
                                type="primary"
                                icon={<PlusOutlined />}
                                onClick={() => setIsRunModalOpen(true)}
                                className="misa-btn-primary"
                            >
                                Tính khấu hao
                            </Button>
                        </Space>
                    }
                />
            }
        >

            {/* Split Pane: Master (Top) / Detail (Bottom) */}
            <DataTableSurface className="fixed-asset-depreciations-table-surface">
                <div className="flex-1 flex flex-col p-3 gap-3 overflow-hidden">
                    {/* Master Table Panel */}
                    <div className="flex-1 bg-white rounded-md border border-slate-200 overflow-hidden flex flex-col">
                        <div className="px-3 py-2 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs font-bold text-slate-700 uppercase">
                                Danh sách các kỳ trích khấu hao TSCĐ
                            </span>
                            <div className="flex items-center gap-2">
                                <Input
                                    placeholder="Tìm theo kỳ, số chứng từ..."
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
                                columns={masterColumns}
                                dataSource={filteredPeriods}
                                rowKey="id"
                                size="small"
                                loading={isLoading}
                                pagination={{ pageSize: 6, size: 'small' }}
                                rowSelection={{
                                    type: 'radio',
                                    selectedRowKeys,
                                    onChange: (keys, rows) => {
                                        setSelectedRowKeys(keys);
                                        if (rows.length > 0) {
                                            setSelectedLog(rows[0]);
                                        }
                                    }
                                }}
                                onRow={(record) => ({
                                    onClick: () => {
                                        setSelectedRowKeys([record.id]);
                                        setSelectedLog(record);
                                    }
                                })}
                            />
                        </div>
                    </div>

                    {/* Detail Sub-Table Panel */}
                    <div className="h-[260px] bg-white rounded-md border border-slate-200 overflow-hidden flex flex-col">
                        <div className="px-3 py-2 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
                            <span className="text-xs font-bold text-slate-700 uppercase">
                                Chi tiết phân bổ khấu hao kỳ: {selectedLog?.month || '---'} ({selectedLogDetail?.lines?.length || 0} tài sản)
                            </span>
                            {selectedLog && (
                                <span className="text-xs font-bold text-blue-700">
                                    Tổng cộng: {Number(selectedLog.total_amount || 0).toLocaleString('vi-VN')} đ
                                </span>
                            )}
                        </div>
                        <div className="flex-1 overflow-auto">
                            <Table
                                columns={detailColumns}
                                dataSource={selectedLogDetail?.lines || []}
                                rowKey="id"
                                size="small"
                                loading={isDetailLoading}
                                pagination={false}
                            />
                        </div>
                    </div>
                </div>
            </DataTableSurface>

            {/* Run Depreciation Modal */}
            <RunDepreciationModal
                open={isRunModalOpen}
                onCancel={() => setIsRunModalOpen(false)}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['fixed-assets-depreciation-periods'] });
                }}
            />

            {/* Print Modal */}
            <VoucherPrintModal
                open={isPrintModalOpen}
                onClose={() => setIsPrintModalOpen(false)}
                data={printRecord}
            />
        </PageShell>
    );
};

export default FixedAssetDepreciations;
