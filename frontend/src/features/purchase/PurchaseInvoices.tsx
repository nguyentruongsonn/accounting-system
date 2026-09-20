import React, { useEffect, useState } from 'react';
import { Table, Button, Dropdown, Input, Select } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { MenuProps } from 'antd';
import {
    DollarOutlined,
    AuditOutlined,
    FileTextOutlined,
    ShoppingCartOutlined,
    DownOutlined,
    CheckCircleOutlined,
    CloseCircleOutlined,
    CopyOutlined,
    PrinterOutlined,
    DeleteOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    PlusOutlined,
    EyeOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { notifyDataChanged } from '../../lib/queryClient';
import {
    optimisticTogglePostStatus,
    instantRemoveVoucher,
    rollbackVoucherCache,
} from '../../lib/voucherCacheManager';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';
import { VoucherPrintModal } from '../../components/misa';
import { PurchaseVoucherDetailModal } from './modals/PurchaseVoucherDetailModal';
import { PurchaseServiceModal } from './modals/PurchaseServiceModal';
import { PurchaseMultipleInvoicesModal } from './modals/PurchaseMultipleInvoicesModal';
import { PayVendorByInvoiceModal } from './modals/PayVendorByInvoiceModal';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { useSourceRecordDeepLink } from '../reports/useSourceRecordDeepLink';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

export const PurchaseInvoices: React.FC = () => {
    const [searchParams, setSearchParams] = useSearchParams();
    // Modal states
    const [isVoucherModalOpen, setIsVoucherModalOpen] = useState(false);
    const [selectedVoucherType, setSelectedVoucherType] = useState('1. Mua hàng trong nước nhập kho');
    const [editRecord, setEditRecord] = useState<any>(null);
    const [isReadOnly, setIsReadOnly] = useState(false);

    const [isServiceModalOpen, setIsServiceModalOpen] = useState(false);
    const [isMultiInvoiceModalOpen, setIsMultiInvoiceModalOpen] = useState(false);
    const [isPayByInvoiceModalOpen, setIsPayByInvoiceModalOpen] = useState(false);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printRecord, setPrintRecord] = useState<any>(null);
    const [addMenuOpen, setAddMenuOpen] = useState(false);

    // Filter & view states
    const [datePreset, setDatePreset] = useState('Tháng này');
    const [searchText, setSearchText] = useState('');

    const queryClient = useQueryClient();

    useEffect(() => {
        const refreshPurchaseInvoices = () => queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] });
        window.addEventListener('purchase-invoices-invalidated', refreshPurchaseInvoices);
        window.addEventListener('accounting-data-changed', refreshPurchaseInvoices);
        return () => {
            window.removeEventListener('purchase-invoices-invalidated', refreshPurchaseInvoices);
            window.removeEventListener('accounting-data-changed', refreshPurchaseInvoices);
        };
    }, [queryClient]);

    const { data: invoices = [], isLoading, isError: isInvoicesError } = useQuery({
        queryKey: ['purchase-invoices'],
        queryFn: async () => {
            const { data } = await api.get('/purchase/invoices');
            const payload: unknown = data;
            const records = Array.isArray(payload)
                ? payload
                : (payload as { data?: unknown } | null)?.data;
            if (!Array.isArray(records)) {
                throw new Error('Invalid purchase-invoice list response');
            }
            return records;
        },
    });

    // queryFn validates the response envelope; do not turn malformed data into a valid empty list.
    const invoiceList = invoices;

    const postMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/purchase/invoices/${id}/post`);
        },
        onMutate: async (id: number) => {
            return optimisticTogglePostStatus(queryClient, ['purchase-invoices'], id, true);
        },
        onSuccess: (response: any) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || (evidence.id === undefined || evidence.id === null)) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã ghi sổ chứng từ mua hàng.');
                return;
            }
            message.success('Ghi sổ chứng từ mua hàng thành công!');
            notifyDataChanged();
        },
        onError: (err: any, _id: number, context) => {
            rollbackVoucherCache(queryClient, ['purchase-invoices'], context);
            message.error(err?.response?.data?.error || err?.response?.data?.message || 'Lỗi khi ghi sổ chứng từ mua hàng!');
        }
    });

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/purchase/invoices/${id}/unpost`);
        },
        onMutate: async (id: number) => {
            return optimisticTogglePostStatus(queryClient, ['purchase-invoices'], id, false);
        },
        onSuccess: (response: any) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || (evidence.id === undefined || evidence.id === null)) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã bỏ ghi sổ chứng từ mua hàng.');
                return;
            }
            message.success('Bỏ ghi sổ chứng từ mua hàng thành công!');
            notifyDataChanged();
        },
        onError: (err: any, _id: number, context) => {
            rollbackVoucherCache(queryClient, ['purchase-invoices'], context);
            message.error(err?.response?.data?.error || err?.response?.data?.message || 'Lỗi khi bỏ ghi sổ!');
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.delete(`/purchase/invoices/${id}`);
        },
        onMutate: async (id: number) => {
            return instantRemoveVoucher(queryClient, ['purchase-invoices'], id);
        },
        onSuccess: (response: any) => {
            if (typeof response?.data?.message !== 'string' && response?.data?.success !== true) {
                message.error('Máy chủ không xác nhận đã xóa chứng từ mua hàng.');
                return;
            }
            message.success('Đã xóa chứng từ mua hàng thành công!');
            notifyDataChanged();
        },
        onError: (err: any, _id: number, context) => {
            rollbackVoucherCache(queryClient, ['purchase-invoices'], context);
            message.error(err?.response?.data?.error || err?.response?.data?.message || 'Lỗi khi xóa chứng từ!');
        }
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/purchase/invoices/${id}/duplicate`);
        },
        onSuccess: (response) => {
            const body = response?.data;
            const duplicated = body?.data ?? body;
            const persistedId = duplicated?.id;
            if (persistedId === undefined || persistedId === null || persistedId === '') {
                message.error('Máy chủ không trả về chứng từ nhân bản đã lưu; không thể báo thành công.');
                return;
            }
            message.success('Nhân bản chứng từ mua hàng thành công!');
            notifyDataChanged();
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.error || err?.response?.data?.message || 'Lỗi khi nhân bản chứng từ!');
        }
    });

    const handleOpenCreateVoucher = (type = '1. Mua hàng trong nước nhập kho') => {
        setSelectedVoucherType(type);
        setEditRecord(null);
        setIsReadOnly(false);
        setIsVoucherModalOpen(true);
    };

    useEffect(() => {
        if (searchParams.get('action') === 'create' && !isVoucherModalOpen) {
            handleOpenCreateVoucher();
            const nextSearchParams = new URLSearchParams(searchParams);
            nextSearchParams.delete('action');
            setSearchParams(nextSearchParams, { replace: true });
        }
    }, [searchParams, setSearchParams, isVoucherModalOpen, handleOpenCreateVoucher]);


    const handleOpenViewRecord = (record: any) => {
        setEditRecord(record);
        setSelectedVoucherType(record.voucher_type || '1. Mua hàng trong nước nhập kho');
        setIsReadOnly(true);
        setIsVoucherModalOpen(true);
    };

    useSourceRecordDeepLink({
        records: invoiceList,
        isLoading,
        isError: isInvoicesError,
        onOpen: handleOpenViewRecord,
        onMissing: () => message.error('Không tìm thấy chứng từ mua hàng nguồn.'),
    });

    const handleOpenEditRecord = (record: any) => {
        if (record.is_posted) {
            Modal.confirm({
                title: 'Xác nhận bỏ ghi sổ để sửa',
                content: `Chứng từ ${record.invoice_number || record.voucher_number} đã ghi sổ. Bạn có muốn BỎ GHI SỔ để chỉnh sửa không?`,
                okText: 'Bỏ ghi và Sửa',
                okType: 'primary',
                cancelText: 'Hủy',
                onOk: async () => {
                    await unpostMutation.mutateAsync(record.id);
                    setEditRecord({ ...record, is_posted: false });
                    setSelectedVoucherType(record.voucher_type || '1. Mua hàng trong nước nhập kho');
                    setIsReadOnly(false);
                    setIsVoucherModalOpen(true);
                }
            });
        } else {
            setEditRecord(record);
            setSelectedVoucherType(record.voucher_type || '1. Mua hàng trong nước nhập kho');
            setIsReadOnly(false);
            setIsVoucherModalOpen(true);
        }
    };

    const handleOpenPrintRecord = (record: any) => {
        // Printing must reflect only server-provided source evidence.  An
        // absent line set is an empty state, not permission to invent an item,
        // amount, warehouse, or account mapping.
        const lines = Array.isArray(record.lines) ? record.lines : [];

        setPrintRecord({
            voucher_number: record.invoice_number || record.voucher_number || '—',
            invoice_number: record.invoice_code || record.invoice_number,
            voucher_date: record.invoice_date || record.accounting_date,
            supplier_name: record.supplier_name || record.supplier?.name,
            contact_name: record.supplier_name || record.supplier?.name,
            supplier_address: record.supplier_address || record.supplier?.address,
            tax_code: record.tax_code || record.supplier?.tax_code,
            description: record.description || '—',
            sub_total: Number(record.sub_total || record.total_amount || 0),
            tax_amount: Number(record.tax_amount || 0),
            total_amount: Number(record.total_amount || 0),
            warehouse_name: record.warehouse_name || record.warehouse?.name || '—',
            lines: lines
        });
        setIsPrintModalOpen(true);
    };

    const handlePayVendor = (record: any) => {
        setEditRecord(record);
        setIsPayByInvoiceModalOpen(true);
    };

    const handleDeleteRecord = (record: any) => {
        Modal.confirm({
            title: 'Xác nhận xóa chứng từ',
            content: `Bạn có chắc chắn muốn xóa chứng từ mua hàng ${record.invoice_number || record.voucher_number}? Thao tác này không thể hoàn tác.`,
            okText: 'Xóa',
            okType: 'danger',
            cancelText: 'Hủy',
            onOk: () => deleteMutation.mutate(record.id)
        });
    };

    const filteredInvoices = invoiceList.filter((inv: any) => {
        if (!searchText) return true;
        const matchNo = inv.invoice_number?.toLowerCase().includes(searchText.toLowerCase());
        const matchSupp = inv.supplier_name?.toLowerCase().includes(searchText.toLowerCase());
        const matchDesc = inv.description?.toLowerCase().includes(searchText.toLowerCase());
        return matchNo || matchSupp || matchDesc;
    });

    // 6 Add Menu Items matching MISA AMIS
    const addMenuItems: MenuProps['items'] = [
        {
            key: '1',
            icon: <FileTextOutlined className="misa-icon-blue" />,
            label: 'Chứng từ mua hàng',
            onClick: () => {
                setAddMenuOpen(false);
                handleOpenCreateVoucher('1. Mua hàng trong nước nhập kho');
            }
        },
        {
            key: '2',
            icon: <AuditOutlined className="misa-icon-cyan" />,
            label: 'Chứng từ mua dịch vụ',
            onClick: () => {
                setAddMenuOpen(false);
                setIsServiceModalOpen(true);
            }
        },
        {
            key: '3',
            icon: <ShoppingCartOutlined className="misa-icon-purple" />,
            label: 'Chứng từ mua hàng nhiều hóa đơn',
            onClick: () => {
                setAddMenuOpen(false);
                setIsMultiInvoiceModalOpen(true);
            }
        },
        {
            type: 'divider'
        },
        {
            key: '4',
            icon: <DollarOutlined className="misa-icon-green" />,
            label: 'Trả tiền theo hóa đơn',
            onClick: () => {
                setAddMenuOpen(false);
                setEditRecord(null);
                setIsPayByInvoiceModalOpen(true);
            }
        },
    ];

    const columns = [
        {
            title: 'Ngày hạch toán',
            dataIndex: 'accounting_date',
            key: 'accounting_date',
            render: (date: string, r: any) => {
                const d = date || r.posted_date || r.voucher_date || r.invoice_date || r.created_at;
                return d ? dayjs(d).format('DD/MM/YYYY') : '—';
            },
            width: 110,
            align: 'center' as const
        },
        {
            title: 'Ngày chứng từ',
            dataIndex: 'invoice_date',
            key: 'invoice_date',
            render: (date: string, r: any) => {
                const d = date || r.voucher_date || r.accounting_date || r.created_at;
                return d ? dayjs(d).format('DD/MM/YYYY') : '—';
            },
            width: 110,
            align: 'center' as const
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'invoice_number',
            key: 'invoice_number',
            render: (text: string, r: any) => <span className="misa-table-link-bold">{text || r.voucher_number || '—'}</span>,
            width: 110,
        },
        {
            title: 'Số hóa đơn',
            dataIndex: 'invoice_code',
            key: 'invoice_code',
            render: (t: string, r: any) => t || r.invoice_number || '—',
            width: 105,
        },
        {
            title: 'Nhà cung cấp',
            dataIndex: 'supplier_name',
            key: 'supplier_name',
            width: 260,
            render: (name: string, r: any) => (
                <div className="misa-cell-title-box">
                    <div className="misa-cell-main-title-dark">{name || r.supplier?.name || '—'}</div>
                    <div className="misa-cell-sub-title">MST: {r.tax_code || r.supplier?.tax_code || '—'}</div>
                </div>
            )
        },
        {
            title: 'Tổng tiền thanh toán',
            dataIndex: 'total_amount',
            key: 'total_amount',
            align: 'right' as const,
            render: (amount: number, record: any) => {
                const total = amount ?? record.lines?.reduce((s: number, l: any) => s + (Number(l.amount) || 0) + (Number(l.tax_amount) || 0), 0) ?? 0;
                return <span className="misa-table-amount-bold">{new Intl.NumberFormat('vi-VN').format(total)} ₫</span>;
            },
            width: 150,
        },
        {
            title: 'Chi phí mua hàng',
            key: 'expenses',
            align: 'right' as const,
            render: (_: any, r: any) => {
                const exp = Number(r.purchase_expense) || r.lines?.reduce((s: number, l: any) => s + (Number(l.purchase_expense) || 0), 0) || 0;
                return <span className={exp > 0 ? "misa-table-amount-bold" : "apple-muted-text"}>{new Intl.NumberFormat('vi-VN').format(exp)} ₫</span>;
            },
            width: 125,
        },
        {
            title: 'Giá trị nhập kho',
            key: 'stock_val',
            align: 'right' as const,
            render: (_: any, r: any) => {
                const exp = Number(r.purchase_expense) || r.lines?.reduce((s: number, l: any) => s + (Number(l.purchase_expense) || 0), 0) || 0;
                const stockVal = r.total_stock_value != null
                    ? Number(r.total_stock_value)
                    : (r.lines?.length
                        ? r.lines.reduce((s: number, l: any) => s + (Number(l.stock_value) || 0), 0)
                        : Number(r.sub_total) || 0) + exp;
                return <span className="misa-text-bold misa-text-primary">{new Intl.NumberFormat('vi-VN').format(stockVal)} ₫</span>;
            },
            width: 140,
        },
        {
            title: 'Trạng thái',
            key: 'status',
            align: 'center' as const,
            width: 120,
            render: (_: any, record: any) => <VoucherStatusBadge status={record} />
        },
        {
            title: 'TT Thanh toán',
            dataIndex: 'payment_status',
            key: 'payment_status',
            align: 'center' as const,
            render: (st: string) => <span>{st === 'Paid' ? 'Đã thanh toán' : st === 'Partially Paid' ? 'Thanh toán một phần' : 'Chưa thanh toán'}</span>,
            width: 130,
        },
        {
            title: 'Loại chứng từ',
            dataIndex: 'voucher_type',
            key: 'voucher_type',
            render: (t: string) => <span className="apple-muted-text">{t || 'Mua hàng trong nước nhập kho'}</span>,
            width: 180,
        },
        {
            title: 'Chức năng',
            key: 'action',
            align: 'center' as const,
            width: 140,
            render: (_: any, record: any) => {
                const menuItems: MenuProps['items'] = [
                    {
                        key: 'view',
                        label: 'Xem chi tiết',
                        icon: <EyeOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenViewRecord(record)
                    },
                    record.is_posted ? {
                        key: 'unpost',
                        label: 'Bỏ ghi sổ',
                        icon: <CloseCircleOutlined className="misa-icon-warning" />,
                        onClick: () => unpostMutation.mutate(record.id)
                    } : {
                        key: 'post',
                        label: 'Ghi sổ',
                        icon: <CheckCircleOutlined className="misa-icon-success" />,
                        onClick: () => postMutation.mutate(record.id)
                    },
                    {
                        key: 'edit',
                        label: record.is_posted ? 'Sửa (Bỏ ghi)' : 'Sửa',
                        icon: <EditOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenEditRecord(record)
                    },
                    {
                        key: 'duplicate',
                        label: 'Nhân bản',
                        icon: <CopyOutlined className="misa-icon-primary" />,
                        onClick: () => duplicateMutation.mutate(record.id)
                    },
                    {
                        key: 'pay',
                        label: 'Lập Phiếu chi / UNC',
                        icon: <DollarOutlined className="misa-icon-green" />,
                        onClick: () => handlePayVendor(record)
                    },
                    {
                        key: 'print',
                        label: 'In Phiếu nhập kho (01-VT)',
                        icon: <PrinterOutlined className="misa-icon-success" />,
                        onClick: () => handleOpenPrintRecord(record)
                    },
                    { type: 'divider' },
                    {
                        key: 'delete',
                        label: 'Xóa',
                        danger: true,
                        disabled: !!record.is_posted,
                        icon: <DeleteOutlined />,
                        onClick: () => handleDeleteRecord(record)
                    }
                ];

                return (
                    <VoucherActionCell
                        primaryActionLabel={record.is_posted ? 'Xem' : 'Sửa'}
                        onPrimaryAction={() => record.is_posted ? handleOpenViewRecord(record) : handleOpenEditRecord(record)}
                        menuItems={menuItems}
                    />
                );
            },
        },
    ];

    return (
        <PageShell
            className="apple-ledger-page"
            title={<PageHeader
                eyebrow="MUA HÀNG"
                title="Chứng từ mua hàng"
                description="Tra cứu và lập chứng từ mua hàng theo dữ liệu được máy chủ cung cấp."
            />}
            toolbar={(
                <PageToolbar
                    filters={(
                        <div className="ui-page-toolbar__filter-group flex items-center gap-2">
                            <Input
                                placeholder="Tìm số chứng từ, hóa đơn, NCC..."
                                prefix={<SearchOutlined className="misa-color-muted" />}
                                className="misa-w-280"
                                allowClear
                                value={searchText}
                                onChange={e => setSearchText(e.target.value)}
                            />
                            <Select
                                value={datePreset}
                                onChange={setDatePreset}
                                className="misa-w-140"
                                options={[
                                    { value: 'Hôm nay', label: 'Hôm nay' },
                                    { value: 'Tuần này', label: 'Tuần này' },
                                    { value: 'Tháng này', label: 'Tháng này' },
                                    { value: 'Quý này', label: 'Quý này' },
                                    { value: 'Năm nay', label: 'Năm nay' },
                                ]}
                            />
                        </div>
                    )}
                    actions={(
                        <div className="ui-page-toolbar__action-group flex items-center gap-2">
                            <Button
                                icon={<ReloadOutlined />}
                                className="misa-btn-tool"
                                title="Làm mới (F5)"
                                onClick={() => void runManualDataLoad(
                                    () => queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] }),
                                    { success: 'Tải lại danh sách chứng từ mua hàng thành công.', failure: 'Không thể tải lại danh sách chứng từ mua hàng.' },
                                )}
                            />
                            <Button
                                icon={<PrinterOutlined />}
                                className="misa-btn-tool"
                                title="In danh sách"
                                onClick={() => window.print()}
                            />
                            <Dropdown
                                menu={{ items: addMenuItems }}
                                placement="bottomRight"
                                trigger={['hover', 'click']}
                                open={addMenuOpen}
                                onOpenChange={setAddMenuOpen}
                            >
                                <div className="misa-toolbar-split-btn">
                                    <button
                                        type="button"
                                        className="misa-btn-primary"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setAddMenuOpen(false);
                                            handleOpenCreateVoucher('1. Mua hàng trong nước nhập kho');
                                        }}
                                    >
                                        <PlusOutlined style={{ marginRight: 6 }} />
                                        <span>Thêm</span>
                                    </button>
                                    <button
                                        type="button"
                                        className="misa-btn-split-arrow"
                                        title="Tùy chọn chứng từ mua hàng"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setAddMenuOpen((prev) => !prev);
                                        }}
                                    >
                                        <DownOutlined className="misa-fs-10" />
                                    </button>
                                </div>
                            </Dropdown>
                        </div>
                    )}
                />
            )}
        >
            <DataTableSurface className="misa-voucher-surface">
            {/* List Table */}
            <div className="misa-table-card">
                {isInvoicesError && (
                    <div
                        role="alert"
                        className="m-3 misa-p-12"
                        style={{
                            background: '#FEF2F2',
                            border: '1px solid #FECACA',
                            borderRadius: 8,
                            display: 'flex',
                            justifyContent: 'space-between',
                            alignItems: 'center',
                            gap: 12,
                        }}
                    >
                        <div>
                            <div style={{ fontWeight: 600, color: '#991B1B', fontSize: 13 }}>Không tải được danh sách chứng từ mua hàng</div>
                            <div style={{ color: '#B91C1C', fontSize: 12, marginTop: 2 }}>Giao diện không thay thế dữ liệu máy chủ bằng chứng từ mẫu. Hãy thử tải lại; nếu lỗi tiếp diễn, liên hệ quản trị hệ thống.</div>
                        </div>
                        <Button size="small" onClick={() => queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] })}>Tải lại</Button>
                    </div>
                )}
                <Table
                    className="misa-voucher-table"
                    columns={columns}
                    dataSource={filteredInvoices}
                    rowKey="id"
                    loading={isLoading}
                    size="small"
                    pagination={{ pageSize: 15 }}
                    locale={{ emptyText: isInvoicesError ? 'Không có dữ liệu để hiển thị do yêu cầu tải thất bại.' : 'Chưa có chứng từ mua hàng.' }}
                />
            </div>


            {/* Comprehensive MISA AMIS Purchase Voucher Detail Modal */}
            <PurchaseVoucherDetailModal
                open={isVoucherModalOpen}
                onCancel={() => setIsVoucherModalOpen(false)}
                initialVoucherType={selectedVoucherType}
                editRecord={editRecord}
                readOnly={isReadOnly}
                onSuccess={() => notifyDataChanged()}
            />

            {/* Service Purchase Modal */}
            <PurchaseServiceModal
                open={isServiceModalOpen}
                onCancel={() => setIsServiceModalOpen(false)}
                onSuccess={() => notifyDataChanged()}
            />

            {/* Multiple Invoices Purchase Modal */}
            <PurchaseMultipleInvoicesModal
                open={isMultiInvoiceModalOpen}
                onCancel={() => setIsMultiInvoiceModalOpen(false)}
                onSuccess={() => notifyDataChanged()}
            />

            {/* Pay Vendor By Invoice Modal */}
            <PayVendorByInvoiceModal
                open={isPayByInvoiceModalOpen}
                onCancel={() => setIsPayByInvoiceModalOpen(false)}
                initialInvoice={editRecord}
                onSuccess={() => notifyDataChanged()}
            />

            {/* Mẫu 01-VT: Phiếu nhập kho print modal */}
            <VoucherPrintModal
                open={isPrintModalOpen}
                type="01-VT"
                data={printRecord}
                onCancel={() => setIsPrintModalOpen(false)}
            />
            </DataTableSurface>
        </PageShell>
    );
};

export default PurchaseInvoices;
