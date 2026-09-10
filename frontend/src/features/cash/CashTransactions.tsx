import React, { useState, useMemo } from 'react';
import { Alert, Table, Button, Input, Select, Dropdown } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import {
    ReloadOutlined,
    FilterOutlined,
    ExportOutlined,
    DownOutlined,
    PrinterOutlined,
    EditOutlined,
    DeleteOutlined,
    EyeOutlined,
    CopyOutlined,
    CheckCircleOutlined,
    CloseOutlined,
    ExclamationCircleOutlined,
    SearchOutlined,
    InboxOutlined
} from '@ant-design/icons';


import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import isBetween from 'dayjs/plugin/isBetween';
import quarterOfYear from 'dayjs/plugin/quarterOfYear';
import api from '../../api/axios';
import { exportCashTransactionsToExcel } from './exportToExcel';
import {
    CollectByInvoiceModal,
    CollectMultiCustomerModal,
    PayByInvoiceModal,
    ExcelImportModal
} from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

dayjs.extend(isBetween);
dayjs.extend(quarterOfYear);

function parseCashTransactionsResponse(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

function readCashAmount(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value);
    return null;
}

function sumCashAmounts(rows: any[], loading: boolean, error: boolean): number | null {
    if (loading || error) return null;
    if (rows.length === 0) return 0;
    const amounts = rows.map((row) => readCashAmount(row?.total_amount));
    if (amounts.some((amount) => amount === null)) return null;
    return (amounts as number[]).reduce((sum, amount) => sum + amount, 0);
}

function formatCashAmount(value: unknown): string {
    const amount = readCashAmount(value);
    return amount === null ? '—' : `${new Intl.NumberFormat('vi-VN').format(amount)} ₫`;
}

function cashPostingStatusLabel(value: unknown, status?: unknown): string {
    const normalizedStatus = typeof status === 'string' ? status.toLowerCase() : '';
    if (normalizedStatus === 'voided' || normalizedStatus === 'cancelled') return 'Đã hủy';
    if (value === true) return 'Đã ghi sổ';
    if (value === false) return 'Bản nháp';
    return '—';
}

function cashPostingStatusTone(value: unknown, status?: unknown): string {
    const normalizedStatus = typeof status === 'string' ? status.toLowerCase() : '';
    if (normalizedStatus === 'voided' || normalizedStatus === 'cancelled') return 'misa-apple-pill-red';
    if (value === true) return 'misa-apple-pill-green';
    if (value === false) return 'misa-apple-pill-orange';
    return 'misa-apple-pill-blue';
}

// Receipts and payments use independent numeric id sequences.  The combined
// catalogue must therefore include the voucher type in its row identity;
// using only `id` makes a receipt #1 and payment #1 share React/selection keys.
function cashTransactionRowKey(record: any): string {
    const type = String(record?.type ?? record?.type_label ?? 'transaction');
    const identifier = record?.id ?? record?.voucher_number ?? record?.voucher_code ?? 'unknown';
    return `${type}-${identifier}`;
}

interface CashTransactionsProps {
    active?: boolean;
}

export const CashTransactions: React.FC<CashTransactionsProps> = React.memo(() => {
    const [receiptMenuOpen, setReceiptMenuOpen] = useState(false);
    const [paymentMenuOpen, setPaymentMenuOpen] = useState(false);

    // Extension Modals for "Thu tiền" & "Chi tiền" Dropdowns
     const [isCollectByInvoiceOpen, setIsCollectByInvoiceOpen] = useState(false);
     const [isCollectMultiCustomerOpen, setIsCollectMultiCustomerOpen] = useState(false);
     const [isPayByInvoiceOpen, setIsPayByInvoiceOpen] = useState(false);
     const [isExcelImportOpen, setIsExcelImportOpen] = useState(false);
    const [excelImportType, setExcelImportType] = useState<'receipt' | 'payment'>('receipt');

    const queryClient = useQueryClient();
    const [selectedRow, setSelectedRow] = useState<any>(null);
    const [statsVisible] = useState(true);
    const [selectedType, setSelectedType] = useState<'all' | 'receipt' | 'payment'>('all');
    const [period, setPeriod] = useState<string>('year');
    const [searchText, setSearchText] = useState('');
    const [filterAccount, setFilterAccount] = useState('');
    const [isAdvancedFilterOpen, setIsAdvancedFilterOpen] = useState(false);

    // Modals
    const [deleteVoucher, setDeleteVoucher] = useState<any>(null);
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);

    // Fetch Receipts (On-Demand when active)
    const { data: receipts = [], isLoading: isLoadingReceipts, isError: isReceiptsError, refetch: refetchReceipts } = useQuery({
        queryKey: ['cash-receipts'],
        queryFn: async () => {
            const { data } = await api.get('/cash/receipts');
            const list = parseCashTransactionsResponse(data, 'cash receipts');
            return list.map((item: any) => ({
                ...item,
                type: 'receipt',
                type_label: 'Phiếu thu',
                // A missing source value is not evidence of a default customer.
                // Keep it empty instead of fabricating a contact code/name that
                // could be mistaken for an accounting or sub-ledger link.
                contact_name: item.contact_name ?? item.payer_name ?? null,
                contact_code: item.contact_code ?? null,
                reason: item.reason ?? item.description ?? null
            }));
        },
        staleTime: 10 * 60 * 1000,
        gcTime: 30 * 60 * 1000,
    });

    // Fetch Payments (On-Demand when active)
    const { data: payments = [], isLoading: isLoadingPayments, isError: isPaymentsError, refetch: refetchPayments } = useQuery({
        queryKey: ['cash-payments'],
        queryFn: async () => {
            const { data } = await api.get('/cash/payments');
            const list = parseCashTransactionsResponse(data, 'cash payments');
            return list.map((item: any) => ({
                ...item,
                type: 'payment',
                type_label: 'Phiếu chi',
                contact_name: item.contact_name ?? item.receiver_name ?? null,
                contact_code: item.contact_code ?? null,
                reason: item.reason ?? item.description ?? null
            }));
        },
        staleTime: 10 * 60 * 1000,
        gcTime: 30 * 60 * 1000,
    });


    // Toggle Post/Void Mutation
    const togglePostMutation = useMutation({
        mutationFn: async ({ id, type, isPosted }: { id: any; type: string; isPosted: boolean }) => {
            const endpoint = type === 'receipt' ? `/cash/receipts/${id}` : `/cash/payments/${id}`;
            const action = isPosted ? '/unpost' : '/post';
            return api.post(`${endpoint}${action}`);
        },
        onSuccess: (response: any, variables) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || evidence.id === undefined || evidence.id === null) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận trạng thái ghi sổ/bỏ ghi sổ chứng từ tiền mặt.');
                return;
            }
            message.success(variables.isPosted ? 'Đã bỏ ghi sổ chứng từ!' : 'Đã ghi sổ chứng từ thành công!');
            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.error || err.response?.data?.message || 'Thao tác không thành công!');
        }
    });

    // Delete Mutation
    const deleteMutation = useMutation({
        mutationFn: async ({ id, type }: { id: any; type: string }) => {
            const endpoint = type === 'receipt' ? `/cash/receipts/${id}` : `/cash/payments/${id}`;
            return api.delete(endpoint);
        },
        onSuccess: (response: any) => {
            if (typeof response?.data?.message !== 'string' && response?.data?.success !== true) {
                message.error('Máy chủ không xác nhận đã xóa chứng từ tiền mặt.');
                return;
            }
            message.success('Đã xóa chứng từ thành công!');
            setIsDeleteModalOpen(false);
            setDeleteVoucher(null);
            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.error || err.response?.data?.message || 'Có lỗi xảy ra khi xóa chứng từ!');
        }
    });

    // Duplication is a server-side source-document operation.  Do not claim
    // success merely by opening a blank form; the API must return a persisted
    // draft before the UI reports or edits the duplicate.
    const duplicateMutation = useMutation({
        mutationFn: async ({ id, type }: { id: any; type: string }) => {
            const endpoint = type === 'receipt' ? `/cash/receipts/${id}` : `/cash/payments/${id}`;
            const response = await api.post(`${endpoint}/duplicate`);
            return { response, type };
        },
        onSuccess: ({ response, type }) => {
            const record = response?.data?.data ?? response?.data;
            if (!record?.id) {
                message.error('Máy chủ không trả về chứng từ nhân bản đã lưu.');
                return;
            }
            message.success('Đã nhân bản chứng từ thành công.');
            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
            window.dispatchEvent(new CustomEvent(type === 'receipt' ? 'open-cash-receipt' : 'open-cash-payment', { detail: { record, mode: 'edit' } }));
        },
        onError: (err: any) => {
            message.error(err.response?.data?.error || err.response?.data?.message || 'Không thể nhân bản chứng từ.');
        }
    });

    // Date formatter helper
    const formatDate = (dateStr: any) => {
        if (!dateStr) return '';
        const d = dayjs(dateStr);
        return d.isValid() ? d.format('DD/MM/YYYY') : String(dateStr).split('T')[0];
    };

    // Filtered Transactions
    const allTransactions = useMemo(() => {
        let list = [...receipts, ...payments].sort((a, b) =>
            dayjs(b.voucher_date || b.posting_date).unix() - dayjs(a.voucher_date || a.posting_date).unix()
        );



        // 1. Filter by Type
        if (selectedType === 'receipt') {
            list = list.filter(t => t.type === 'receipt');
        } else if (selectedType === 'payment') {
            list = list.filter(t => t.type === 'payment');
        }

        // 2. Filter by Period
        const now = dayjs();
        if (period === 'today') {
            list = list.filter(t => dayjs(t.voucher_date).isSame(now, 'day'));
        } else if (period === 'this_week') {
            list = list.filter(t => dayjs(t.voucher_date).isSame(now, 'week'));
        } else if (period === 'month') {
            list = list.filter(t => dayjs(t.voucher_date).isSame(now, 'month'));
        } else if (period === 'quarter') {
            list = list.filter(t => dayjs(t.voucher_date).isSame(now, 'quarter'));
        } else if (period === 'year') {
            list = list.filter(t => dayjs(t.voucher_date).isSame(now, 'year'));
        }

        // 3. Filter by Account
        if (filterAccount) {
            list = list.filter(t => (Array.isArray(t?.lines) ? t.lines : []).some((l: any) =>
                String(l?.debit_account || '').includes(filterAccount) || String(l?.credit_account || '').includes(filterAccount)
            ));
        }

        // 4. Search Text
        if (searchText.trim()) {
            const query = searchText.trim().toLowerCase();
            list = list.filter(t =>
                String(t?.voucher_number || '').toLowerCase().includes(query) ||
                String(t?.reason || '').toLowerCase().includes(query) ||
                String(t?.contact_name || '').toLowerCase().includes(query) ||
                String(t?.total_amount || '').includes(query)
            );
        }

        return list;
    }, [receipts, payments, selectedType, period, searchText, filterAccount]);

    // Select first row by default
    React.useEffect(() => {
        if (Array.isArray(allTransactions) && allTransactions.length > 0 && !selectedRow) {
            setSelectedRow(allTransactions[0]);
        }
    }, [allTransactions, selectedRow]);

    // Calculate Summary Stats
    const totalReceipts = sumCashAmounts(Array.isArray(receipts) ? receipts : [], isLoadingReceipts, isReceiptsError);
    const totalPayments = sumCashAmounts(Array.isArray(payments) ? payments : [], isLoadingPayments, isPaymentsError);
    const netBalance = totalReceipts !== null && totalPayments !== null ? totalReceipts - totalPayments : null;
    const currentTime = dayjs().format('HH:mm DD/MM/YYYY');

    // Export Excel
    const handleExportExcel = () => {
        const periodText = period === 'ytd' ? 'Đầu năm tới hiện tại' : (period === 'month' ? 'Tháng này' : (period === 'quarter' ? 'Quý này' : 'Cả năm 2026'));
        exportCashTransactionsToExcel(allTransactions, periodText);
        message.success('Đã xuất file Excel sổ thu chi tiền mặt với đầy đủ tiêu đề và định dạng chuẩn!');
    };

    // Helper to accurately identify Receipt vs Payment
    const isReceiptVoucher = (record: any) => {
        return record.type === 'receipt' ||
               record.type_label === 'Phiếu thu' ||
               String(record.voucher_number || '').toUpperCase().startsWith('PT');
    };

    // Open Voucher Details Modal (Full Master-Detail MISA Modal)
    const handleViewVoucherDetails = (record: any) => {
        if (isReceiptVoucher(record)) {
            window.dispatchEvent(new CustomEvent('open-cash-receipt', { detail: { record, mode: 'view' } }));
        } else {
            window.dispatchEvent(new CustomEvent('open-cash-payment', { detail: { record, mode: 'view' } }));
        }
    };

    // Open Edit Modal (Full Master-Detail MISA Modal)
    const handleEditVoucher = (record: any) => {
        if (isReceiptVoucher(record)) {
            window.dispatchEvent(new CustomEvent('open-cash-receipt', { detail: { record, mode: 'edit' } }));
        } else {
            window.dispatchEvent(new CustomEvent('open-cash-payment', { detail: { record, mode: 'edit' } }));
        }
    };

    // Open Delete Modal
    const handleDeleteClick = (record: any) => {
        setDeleteVoucher(record);
        setIsDeleteModalOpen(true);
    };

    const receiptMenu = useMemo(() => [
        {
            key: 'tx-rcpt-std',
            label: 'Phiếu thu',
            onClick: () => {
                setReceiptMenuOpen(false);
                window.dispatchEvent(new CustomEvent('open-cash-receipt'));
            }
        },
        {
            key: 'tx-rcpt-invoice',
            label: 'Thu tiền theo hóa đơn',
            onClick: () => {
                setReceiptMenuOpen(false);
                setIsCollectByInvoiceOpen(true);
            }
        },
        {
            key: 'tx-rcpt-multi-invoice',
            label: 'Thu tiền theo hóa đơn nhiều khách hàng',
            onClick: () => {
                setReceiptMenuOpen(false);
                setIsCollectMultiCustomerOpen(true);
            }
        },
        {
            type: 'divider' as const
        },
        {
            key: 'tx-rcpt-excel',
            label: 'Nhập từ excel',
            onClick: () => {
                setReceiptMenuOpen(false);
                setExcelImportType('receipt');
                setIsExcelImportOpen(true);
            }
        },
    ], []);

    const paymentMenu = useMemo(() => [
        {
            key: 'tx-pmt-std',
            label: 'Phiếu chi',
            onClick: () => {
                setPaymentMenuOpen(false);
                window.dispatchEvent(new CustomEvent('open-cash-payment'));
            }
        },
        {
            key: 'tx-pmt-invoice',
            label: 'Trả tiền theo hóa đơn',
            onClick: () => {
                setPaymentMenuOpen(false);
                setIsPayByInvoiceOpen(true);
            }
        },
        {
            type: 'divider' as const
        },
        {
            key: 'tx-pmt-excel',
            label: 'Nhập từ excel',
            onClick: () => {
                setPaymentMenuOpen(false);
                setExcelImportType('payment');
                setIsExcelImportOpen(true);
            }
        },
    ], []);

    // Memoized Columns to prevent Ant Design Table from recalculating layout on every render
    const columns = useMemo(() => [
        {
            title: 'Ngày hạch toán',
            dataIndex: 'posting_date',
            key: 'posting_date',
            width: 110,
            render: (val: any) => formatDate(val)
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 120,
            render: (t: string, r: any) => (
                <button
                    type="button"
                    className="misa-btn-link-action-bold"
                    onClick={() => handleViewVoucherDetails(r)}
                >
                    {t}
                </button>
            )
        },
        {
            title: 'Diễn giải',
            dataIndex: 'reason',
            key: 'reason',
            minWidth: 180
        },
        {
            title: 'Số tiền',
            dataIndex: 'total_amount',
            key: 'total_amount',
            width: 140,
            align: 'right' as const,
            render: (amt: number | null) => <span className="misa-text-bold">{formatCashAmount(amt)}</span>
        },
        {
            title: 'Đối tượng',
            dataIndex: 'contact_name',
            key: 'contact_name',
            minWidth: 170
        },
        {
            title: 'Lý do thu/chi',
            dataIndex: 'reason',
            key: 'reason_type',
            width: 150,
            render: (r: string, rec: any) => rec.voucher_type || r || (rec.type === 'receipt' ? 'Thu tiền mặt' : 'Chi tiền mặt')
        },
        {
            title: 'Loại chứng từ',
            dataIndex: 'type_label',
            key: 'type_label',
            width: 110,
            render: (label: string, r: any) => (
                <span className={`misa-badge-type misa-badge-${r.type}`}>
                    {label}
                </span>
            )
        },
        {
            title: 'Hạch toán gộp nhiều hóa đơn',
            key: 'batch_invoice',
            width: 140,
            align: 'center' as const,
            render: () => <span className="misa-color-muted">Không</span>
        },
        {
            title: 'Trạng thái',
            dataIndex: 'is_posted',
            key: 'is_posted',
            width: 110,
            align: 'center' as const,
            render: (isPosted: boolean, record: any) => (
                <span className={`misa-apple-pill ${cashPostingStatusTone(isPosted, record?.status)}`}>
                    <span className="misa-apple-pill-dot" />
                    {cashPostingStatusLabel(isPosted, record?.status)}
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 140,
            align: 'center' as const,
            render: (_: any, record: any) => {
                const isPosted = record.is_posted;
                const normalizedStatus = typeof record.status === 'string' ? record.status.toLowerCase() : '';
                const isVoided = normalizedStatus === 'voided' || normalizedStatus === 'cancelled';
                const isDraft = isPosted === false && !isVoided;
                const actionItems = [
                    {
                        key: 'view',
                        icon: <EyeOutlined />,
                        label: 'Xem chi tiết',
                        onClick: () => handleViewVoucherDetails(record)
                    },
                    ...(isDraft ? [{
                        key: 'edit',
                        icon: <EditOutlined />,
                        label: 'Sửa chứng từ',
                        onClick: () => handleEditVoucher(record)
                    }] : []),
                    ...(!isVoided && typeof isPosted === 'boolean' ? [{
                        key: 'toggle-post',
                        icon: isPosted ? <CloseOutlined /> : <CheckCircleOutlined />,
                        label: isPosted ? 'Bỏ ghi sổ' : 'Ghi sổ',
                        onClick: () => togglePostMutation.mutate({ id: record.id, type: record.type, isPosted })
                    }] : !isVoided ? [{
                        key: 'toggle-post-unverified',
                        icon: <ExclamationCircleOutlined />,
                        label: 'Chưa xác minh trạng thái',
                        disabled: true
                    }] : []),
                    {
                        key: 'duplicate',
                        icon: <CopyOutlined />,
                        label: 'Nhân bản',
                        onClick: () => duplicateMutation.mutate({ id: record.id, type: record.type })
                    },
                    {
                        key: 'print',
                        icon: <PrinterOutlined />,
                        label: 'In chứng từ',
                        onClick: () => handleViewVoucherDetails(record)
                    },
                    {
                        type: 'divider' as const
                    },
                    {
                        key: 'delete',
                        icon: <DeleteOutlined />,
                        danger: true,
                        label: 'Xóa',
                        onClick: () => handleDeleteClick(record)
                    }
                ];

                return (
                    <div className="misa-flex-center misa-gap-4">
                        <Button
                            type="link"
                            size="small"
                            className="misa-btn-link-action"
                            onClick={() => isDraft ? handleEditVoucher(record) : handleViewVoucherDetails(record)}
                        >
                            {isDraft ? 'Sửa' : 'Xem'}
                        </Button>
                        <Dropdown menu={{ items: actionItems }} trigger={['click']}>
                            <Button type="text" size="small">
                                <DownOutlined className="misa-fs-10 misa-color-blue" />
                            </Button>
                        </Dropdown>
                    </div>
                );
            }
        }
    ], [togglePostMutation]);

    return (
        <PageShell
            className="cash-transactions-page"
            title={<PageHeader eyebrow="Tiền mặt" title="Thu, chi tiền" description="Danh sách chứng từ tiền mặt và chi tiết hạch toán." />}
        >
            <PageToolbar
                filters={(
                    <>
                        <Select value={selectedType} onChange={setSelectedType} className="misa-w-130" options={[{ value: 'all', label: 'Tất cả' }, { value: 'receipt', label: 'Thu tiền' }, { value: 'payment', label: 'Chi tiền' }]} />
                        <Select value={period} onChange={setPeriod} className="misa-w-140" options={[{ value: 'today', label: 'Hôm nay' }, { value: 'this_week', label: 'Tuần này' }, { value: 'month', label: 'Tháng này' }, { value: 'quarter', label: 'Quý này' }, { value: 'year', label: 'Cả năm' }]} />
                        <Button icon={<FilterOutlined />} onClick={() => setIsAdvancedFilterOpen(!isAdvancedFilterOpen)} className={isAdvancedFilterOpen ? 'misa-btn-tool-active' : 'misa-btn-tool'} title="Lọc nâng cao" />
                        <Input placeholder="Tìm theo số CT, đối tượng, lý do..." prefix={<SearchOutlined className="misa-color-muted" />} className="misa-w-240" value={searchText} onChange={e => setSearchText(e.target.value)} allowClear />
                    </>
                )}
                actions={(
                    <>
                        <Button icon={<ReloadOutlined />} onClick={() => { queryClient.invalidateQueries({ queryKey: ['cash-receipts'] }); queryClient.invalidateQueries({ queryKey: ['cash-payments'] }); message.success('Đã làm mới danh sách dữ liệu!'); }} title="Nạp lại dữ liệu" className="misa-btn-tool" />
                        <Button icon={<ExportOutlined />} onClick={handleExportExcel}>Xuất dữ liệu</Button>
                        <Dropdown menu={{ items: receiptMenu }} placement="bottomRight" trigger={['hover']} open={receiptMenuOpen} onOpenChange={setReceiptMenuOpen}>
                            <Button type="primary" className="misa-btn-primary" onClick={() => { setReceiptMenuOpen(false); window.dispatchEvent(new Event('open-cash-receipt')); }}><span>+ Thu tiền</span><DownOutlined className="misa-fs-10" /></Button>
                        </Dropdown>
                        <Dropdown menu={{ items: paymentMenu }} placement="bottomRight" trigger={['hover']} open={paymentMenuOpen} onOpenChange={setPaymentMenuOpen}>
                            <Button type="primary" className="misa-btn-primary" onClick={() => { setPaymentMenuOpen(false); window.dispatchEvent(new Event('open-cash-payment')); }}><span>+ Chi tiền</span><DownOutlined className="misa-fs-10" /></Button>
                        </Dropdown>
                    </>
                )}
            />
            <div className="ui-page-content-stack">
            {/* Top 3 Stat Cards Banner */}
            {statsVisible && (
                <div className="misa-stat-grid-3">
                    {/* Card 1: Tổng thu */}
                    <div className="misa-stat-card">
                        <div className="misa-stat-label">Tổng thu đầu năm đến hiện tại</div>
                        <div className="misa-stat-value">
                            {formatCashAmount(totalReceipts)}
                        </div>
                        <div className="misa-stat-time">
                            Cập nhật: {currentTime}
                        </div>
                    </div>

                    {/* Card 2: Tổng chi */}
                    <div className="misa-stat-card">
                        <div className="misa-stat-label">Tổng chi đầu năm đến hiện tại</div>
                        <div className="misa-stat-value">
                            {formatCashAmount(totalPayments)}
                        </div>
                        <div className="misa-stat-time">
                            Cập nhật: {currentTime}
                        </div>
                    </div>

                    {/* Card 3: Tồn quỹ */}
                    <div className="misa-stat-card">
                        <div className="misa-stat-label">Tồn quỹ đến ngày hiện tại</div>
                        <div className="misa-stat-value text-blue-600">
                            {formatCashAmount(netBalance)}
                        </div>
                        <div className="misa-stat-time">
                            Cập nhật: {currentTime}
                        </div>
                    </div>
                </div>
            )}

            {/* Advanced Filter Sub-Bar */}
            {isAdvancedFilterOpen && (
                <div className="misa-filter-subbar">
                    <span className="misa-filter-label">Lọc theo tài khoản:</span>
                    <Input
                        placeholder="VD: 1111, 131, 331..."
                        className="misa-w-140"
                        size="small"
                        value={filterAccount}
                        onChange={e => setFilterAccount(e.target.value)}
                        allowClear
                    />
                    <Button size="small" className="misa-btn-secondary" onClick={() => setFilterAccount('')}>Đặt lại bộ lọc</Button>
                </div>
            )}

            {/* Master Table */}
            <DataTableSurface>
                {isReceiptsError && (
                    <Alert
                        className="m-3"
                        type="error"
                        showIcon
                        message="Không thể tải giao dịch tiền mặt (phiếu thu)"
                        description="Không thể xác minh phần phiếu thu; bảng không coi lỗi máy chủ là danh sách rỗng."
                        action={<Button size="small" onClick={() => void refetchReceipts()}>Thử lại giao dịch tiền mặt</Button>}
                    />
                )}
                {isPaymentsError && (
                    <Alert
                        className="m-3"
                        type="error"
                        showIcon
                        message="Không thể tải giao dịch tiền mặt (phiếu chi)"
                        description="Không thể xác minh phần phiếu chi; bảng không coi lỗi máy chủ là danh sách rỗng."
                        action={<Button size="small" onClick={() => void refetchPayments()}>Thử lại giao dịch tiền mặt</Button>}
                    />
                )}
                <Table
                     loading={isLoadingReceipts || isLoadingPayments}
                     columns={columns}
                     dataSource={allTransactions}
                     rowKey={cashTransactionRowKey}
                     pagination={{ pageSize: 15 }}
                     size="small"
                     className="misa-voucher-table"
                     rowSelection={{
                         type: 'radio',
                         selectedRowKeys: selectedRow ? [cashTransactionRowKey(selectedRow)] : [],
                         onChange: (_, rows) => setSelectedRow(rows[0])
                     }}
                     onRow={(record) => ({
                         onClick: () => setSelectedRow(record),
                         className: selectedRow && cashTransactionRowKey(selectedRow) === cashTransactionRowKey(record)
                             ? 'misa-table-row-selected'
                             : ''
                     })}
                    locale={{
                        emptyText: isReceiptsError || isPaymentsError
                            ? 'Không thể xác định dữ liệu giao dịch tiền mặt.'
                            : (
                                <div className="misa-empty-box">
                                    <InboxOutlined className="misa-empty-icon" />
                                    Không có chứng từ thu chi trong phạm vi đã chọn.
                                </div>
                            ),
                    }}
                />
            </DataTableSurface>

            {/* Bottom Split Master-Detail Summary */}
            <div className="misa-voucher-detail-header-tab">
                <div className="misa-voucher-detail-tab active">
                    Chi tiết hạch toán chứng từ: <span className="misa-color-blue misa-ml-4">{selectedRow?.voucher_number || 'Chưa chọn'}</span>
                </div>
            </div>

            {/* Detail Table */}
            <DataTableSurface className="cash-detail-table-surface">
                <table className="misa-voucher-table">
                    <thead>
                        <tr>
                            <th className="misa-w-40 misa-text-center">#</th>
                            <th className="misa-min-w-220">Diễn giải</th>
                            <th className="misa-w-100">TK Nợ</th>
                            <th className="misa-w-100">TK Có</th>
                            <th className="misa-w-140 misa-text-right">Số tiền</th>
                            <th className="misa-w-130">Đối tượng</th>
                            <th className="misa-min-w-160">Tên đối tượng</th>
                        </tr>
                    </thead>
                    <tbody>
                        {selectedRow?.lines && selectedRow.lines.length > 0 ? (
                            selectedRow.lines.map((line: any, index: number) => (
                                <tr key={`det-row-${index}`}>
                                    <td className="misa-text-center misa-color-muted">{index + 1}</td>
                                    <td>{line.description ?? selectedRow.reason ?? '—'}</td>
                                    <td className="misa-fw-600">{line.debit_account ?? '—'}</td>
                                    <td className="misa-fw-600">{line.credit_account ?? '—'}</td>
                                    <td className="misa-text-right misa-fw-600">
                                        {line.amount == null ? '—' : `${new Intl.NumberFormat('vi-VN').format(line.amount)} ₫`}
                                    </td>
                                    <td>
                                        {line.line_contact_id ?? selectedRow.contact_code ?? '—'}
                                    </td>
                                    <td>{line.line_contact_name ?? selectedRow.contact_name ?? '—'}</td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={7} className="misa-text-center misa-color-muted">
                                    Chưa có dòng hạch toán được máy chủ cung cấp.
                                </td>
                            </tr>
                        )}
                        <tr className="summary-row">
                            <td className="misa-text-center"></td>
                            <td className="misa-fw-800">Tổng cộng</td>
                            <td></td>
                            <td></td>
                            <td className="misa-text-right misa-fw-900 misa-stat-value-blue">
                                {formatCashAmount(selectedRow?.total_amount)}
                            </td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </DataTableSurface>

            {/* MISA Warning Delete Confirmation Modal */}
            <Modal
                title={
                    <div className="misa-modal-warning-header">
                        <ExclamationCircleOutlined className="misa-fs-22" />
                        <span className="misa-fw-700 misa-fs-16">Cảnh báo</span>
                    </div>
                }
                open={isDeleteModalOpen}
                onCancel={() => setIsDeleteModalOpen(false)}
                footer={[
                    <Button key="no" onClick={() => setIsDeleteModalOpen(false)} className="misa-btn-secondary">
                        Không
                    </Button>,
                    <Button
                        key="yes"
                        type="primary"
                        danger
                        loading={deleteMutation.isPending}
                        onClick={() => {
                            if (deleteVoucher) {
                                deleteMutation.mutate({ id: deleteVoucher.id, type: deleteVoucher.type });
                            }
                        }}
                        className="misa-border-radius-4"
                    >
                        Có
                    </Button>
                ]}
                width={440}
            >
                <div className="misa-modal-warning-body">
                    Bạn có chắc chắn muốn xóa chứng từ <strong className="misa-color-dark">{deleteVoucher?.voucher_number}</strong> không?
                </div>
            </Modal>

            {/* Extension Modals for "Thu tiền" & "Chi tiền" Dropdowns */}
            <CollectByInvoiceModal
                open={isCollectByInvoiceOpen}
                onClose={() => setIsCollectByInvoiceOpen(false)}
            />

            <CollectMultiCustomerModal
                open={isCollectMultiCustomerOpen}
                onClose={() => setIsCollectMultiCustomerOpen(false)}
            />

            <PayByInvoiceModal
                open={isPayByInvoiceOpen}
                onClose={() => setIsPayByInvoiceOpen(false)}
            />


             <ExcelImportModal
                open={isExcelImportOpen}
                onClose={() => setIsExcelImportOpen(false)}
                voucherType={excelImportType}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
                    queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
                }}
            />
            </div>
        </PageShell>
    );
});

export default CashTransactions;
