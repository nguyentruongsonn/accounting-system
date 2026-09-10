import React, { useState, useMemo, useCallback } from 'react';
import { Alert, Table, Button, Input, Select, Dropdown, Form, InputNumber, DatePicker } from 'antd';
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
    SearchOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import isBetween from 'dayjs/plugin/isBetween';
import quarterOfYear from 'dayjs/plugin/quarterOfYear';
import api from '../../api/axios';
import { exportBankTransactionsToExcel } from './exportToExcel';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import DataTableSurface from '../../components/layout/DataTableSurface';

dayjs.extend(isBetween);
dayjs.extend(quarterOfYear);

function parseBankTransactionsResponse(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

function readBankAmount(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value);
    return null;
}

function sumBankAmounts(rows: any[], loading: boolean): number | null {
    if (loading) return null;
    if (rows.length === 0) return 0;
    const amounts = rows.map((row) => readBankAmount(row?.total_amount));
    if (amounts.some((amount) => amount === null)) return null;
    return (amounts as number[]).reduce((sum, amount) => sum + amount, 0);
}

function formatBankAmount(value: unknown): string {
    const amount = readBankAmount(value);
    return amount === null ? '—' : `${new Intl.NumberFormat('vi-VN').format(amount)} ₫`;
}

function bankPostingStatusLabel(value: unknown): string {
    if (value === true) return 'Đã ghi sổ';
    if (value === false) return 'Bản nháp';
    return '—';
}

interface BankTransactionsProps {
    active?: boolean;
}

export const BankTransactions: React.FC<BankTransactionsProps> = React.memo(() => {
    const queryClient = useQueryClient();
    const [selectedRow, setSelectedRow] = useState<any>(null);
    const [statsVisible] = useState(true);
    const [selectedType, setSelectedType] = useState<'all' | 'receipt' | 'payment'>('all');
    const [period, setPeriod] = useState<string>('year');
    const [filterBankAcc, setFilterBankAcc] = useState<string>('all');
    const [searchText, setSearchText] = useState('');
    const [filterAccount, setFilterAccount] = useState('');
    const [isAdvancedFilterOpen, setIsAdvancedFilterOpen] = useState(false);

    // Modals
    const [viewVoucher, setViewVoucher] = useState<any>(null);
    const [isViewModalOpen, setIsViewModalOpen] = useState(false);
    const [editVoucher, setEditVoucher] = useState<any>(null);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [deleteVoucher, setDeleteVoucher] = useState<any>(null);
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);

    const [editForm] = Form.useForm();

    // Fetch Receipts (Báo Có)
    const { data: receipts = [], isLoading: isLoadingReceipts, isError: isReceiptsError, refetch: refetchReceipts } = useQuery({
        queryKey: ['bank-receipts'],
        queryFn: async () => {
            const { data } = await api.get('/bank/receipts');
            const arr = parseBankTransactionsResponse(data, 'bank receipts');
            return arr.map((r: any) => ({
                ...r,
                type: 'receipt',
                type_label: 'Báo Có',
                reason_type: 'Báo Có',
                // These values must come from the API/resource.  A missing
                // contact or bank account is an evidence gap, not permission
                // to display a sample counterparty/account.
                contact_name: r.contact_name ?? r.payer_name ?? null,
                contact_code: r.contact_code ?? null,
                reason: r.reason ?? r.description ?? null,
                bank_account_number: r.bank_account_number ?? r.bank_account?.account_number ?? null,
                bank_name: r.bank_name ?? r.bank_account?.bank_name ?? null,
                total_amount: r.total_amount ?? r.amount ?? null
            }));
        },
        staleTime: 10 * 60 * 1000,
        gcTime: 30 * 60 * 1000,
    });

    // Fetch Payments (Ủy nhiệm chi)
    const { data: payments = [], isLoading: isLoadingPayments, isError: isPaymentsError, refetch: refetchPayments } = useQuery({
        queryKey: ['bank-payments'],
        queryFn: async () => {
            const { data } = await api.get('/bank/payments');
            const arr = parseBankTransactionsResponse(data, 'bank payments');
            return arr.map((p: any) => ({
                ...p,
                type: 'payment',
                type_label: 'Ủy nhiệm chi',
                reason_type: 'Báo Nợ',
                contact_name: p.contact_name ?? p.receiver_name ?? null,
                contact_code: p.contact_code ?? null,
                reason: p.reason ?? p.description ?? null,
                bank_account_number: p.bank_account_number ?? p.bank_account?.account_number ?? null,
                bank_name: p.bank_name ?? p.bank_account?.bank_name ?? null,
                total_amount: p.total_amount ?? p.amount ?? null
            }));
        },
        staleTime: 10 * 60 * 1000,
        gcTime: 30 * 60 * 1000,
    });

    // Toggle Post/Void Mutation
    const togglePostMutation = useMutation({
        mutationFn: async ({ id, type, isPosted }: { id: any; type: string; isPosted: boolean }) => {
            const endpoint = type === 'receipt' ? `/bank/receipts/${id}` : `/bank/payments/${id}`;
            const action = isPosted ? '/unpost' : '/post';
            return api.post(`${endpoint}${action}`);
        },
        onSuccess: (response: any, variables) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || (evidence.id === undefined || evidence.id === null)) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận trạng thái ghi sổ/bỏ ghi sổ chứng từ tiền gửi.');
                return;
            }
            message.success(variables.isPosted ? 'Đã bỏ ghi sổ chứng từ tiền gửi!' : 'Đã ghi sổ chứng từ tiền gửi thành công!');
            queryClient.invalidateQueries({ queryKey: ['bank-receipts'] });
            queryClient.invalidateQueries({ queryKey: ['bank-payments'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Thao tác không thành công!');
        }
    });

    // Delete Mutation
    const deleteMutation = useMutation({
        mutationFn: async ({ id, type }: { id: any; type: string }) => {
            const endpoint = type === 'receipt' ? `/bank/receipts/${id}` : `/bank/payments/${id}`;
            return api.delete(endpoint);
        },
        onSuccess: (response: any) => {
            if (typeof response?.data?.message !== 'string' && response?.data?.success !== true) {
                message.error('Máy chủ không xác nhận đã xóa chứng từ tiền gửi.');
                return;
            }
            message.success('Đã xóa chứng từ tiền gửi thành công!');
            setIsDeleteModalOpen(false);
            setDeleteVoucher(null);
            queryClient.invalidateQueries({ queryKey: ['bank-receipts'] });
            queryClient.invalidateQueries({ queryKey: ['bank-payments'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi xóa chứng từ!');
        }
    });

    // Duplication must be persisted by the server before the UI reports
    // success or opens an edit form for the new voucher.
    const duplicateMutation = useMutation({
        mutationFn: async ({ id, type }: { id: any; type: string }) => {
            const endpoint = type === 'receipt' ? `/bank/receipts/${id}` : `/bank/payments/${id}`;
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
            queryClient.invalidateQueries({ queryKey: ['bank-receipts'] });
            queryClient.invalidateQueries({ queryKey: ['bank-payments'] });
            window.dispatchEvent(new CustomEvent(type === 'receipt' ? 'open-bank-receipt' : 'open-bank-payment', { detail: { record, mode: 'edit' } }));
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Không thể nhân bản chứng từ.');
        }
    });

    // Update Mutation
    const updateMutation = useMutation({
        mutationFn: async ({ id, type, values }: { id: any; type: string; values: any }) => {
            const endpoint = type === 'receipt' ? `/bank/receipts/${id}` : `/bank/payments/${id}`;
            return api.put(endpoint, {
                ...values,
                voucher_date: values.voucher_date?.format('YYYY-MM-DD'),
                posting_date: values.posting_date?.format('YYYY-MM-DD'),
            });
        },
        onSuccess: (response: any) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || (evidence.id === undefined || evidence.id === null)) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không trả về chứng từ tiền gửi đã cập nhật; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Cập nhật chứng từ tiền gửi thành công!');
            setIsEditModalOpen(false);
            setEditVoucher(null);
            queryClient.invalidateQueries({ queryKey: ['bank-receipts'] });
            queryClient.invalidateQueries({ queryKey: ['bank-payments'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Cập nhật thất bại!');
        }
    });

    // Filter choices are derived from tenant-scoped API evidence.  Do not
    // ship sample bank account numbers as if they belonged to this company.
    const bankAccountOptions = useMemo(() => {
        const seen = new Set<string>();
        const options = [...receipts, ...payments].flatMap((item: any) => {
            const number = item.bank_account_number ?? item.bank_account?.account_number;
            if (number == null || String(number).trim() === '') return [];
            const value = String(number);
            if (seen.has(value)) return [];
            seen.add(value);
            const bankName = item.bank_name ?? item.bank_account?.bank_name;
            return [{ value, label: bankName ? `${bankName} - ${value}` : value }];
        });
        return [{ value: 'all', label: 'Tất cả tài khoản NH' }, ...options];
    }, [receipts, payments]);

    // Filter Logic
    const allTransactions = useMemo(() => {
        let combined: any[] = [];
        if (selectedType === 'all' || selectedType === 'receipt') {
            combined = [...combined, ...receipts];
        }
        if (selectedType === 'all' || selectedType === 'payment') {
            combined = [...combined, ...payments];
        }

        const now = dayjs();

        return combined.filter(item => {
            // Period Filter
            if (period !== 'all' && item.posting_date) {
                const pDate = dayjs(item.posting_date);
                if (pDate.isValid()) {
                    if (period === 'today' && !pDate.isSame(now, 'day')) return false;
                    if (period === 'this_week' && !pDate.isSame(now, 'week')) return false;
                    if (period === 'month' && !pDate.isSame(now, 'month')) return false;
                    if (period === 'quarter' && !pDate.isSame(now, 'quarter')) return false;
                    if (period === 'year' && !pDate.isSame(now, 'year')) return false;
                }
            }

            // Bank Account Filter
            if (filterBankAcc !== 'all') {
                const acc = item.bank_account_number || item.bank_account || '';
                if (!acc.includes(filterBankAcc)) return false;
            }

            // Search Text Filter
            if (searchText.trim()) {
                const s = searchText.toLowerCase();
                const matchVoucher = item.voucher_number?.toLowerCase().includes(s);
                const matchContact = item.contact_name?.toLowerCase().includes(s);
                const matchReason = item.reason?.toLowerCase().includes(s);
                const matchBank = item.bank_name?.toLowerCase().includes(s);
                if (!matchVoucher && !matchContact && !matchReason && !matchBank) return false;
            }

            // Advanced Account Filter
            if (filterAccount.trim()) {
                const accQuery = filterAccount.trim();
                const lines = item.lines || [];
                const matchLine = lines.some((l: any) =>
                    l.debit_account?.includes(accQuery) || l.credit_account?.includes(accQuery)
                );
                if (!matchLine && !item.debit_account?.includes(accQuery) && !item.credit_account?.includes(accQuery)) {
                    return false;
                }
            }

            return true;
        }).sort((a, b) => dayjs(b.posting_date).valueOf() - dayjs(a.posting_date).valueOf());
    }, [receipts, payments, selectedType, period, filterBankAcc, searchText, filterAccount]);

    // Financial KPI Totals
    const totalReceipts = useMemo(() => sumBankAmounts(receipts, isLoadingReceipts), [receipts, isLoadingReceipts]);

    const totalPayments = useMemo(() => sumBankAmounts(payments, isLoadingPayments), [payments, isLoadingPayments]);

    const netBalance = totalReceipts === null || totalPayments === null ? null : totalReceipts - totalPayments;
    const currentTime = useMemo(() => dayjs().format('DD/MM/YYYY HH:mm'), []);

    const totalFilteredAmount = useMemo(() => sumBankAmounts(allTransactions, isLoadingReceipts || isLoadingPayments), [allTransactions, isLoadingReceipts, isLoadingPayments]);

    // Format date helper
    const formatDate = (dateStr: any) => {
        if (!dateStr) return '-';
        const d = dayjs(dateStr);
        return d.isValid() ? d.format('DD/MM/YYYY') : dateStr;
    };

    // Handlers
    const handleViewVoucherDetails = useCallback((record: any) => {
        setViewVoucher(record);
        setIsViewModalOpen(true);
    }, []);

    const handleEditVoucher = useCallback((record: any) => {
        setEditVoucher(record);
        editForm.setFieldsValue({
            voucher_number: record.voucher_number,
            voucher_date: dayjs(record.voucher_date),
            posting_date: dayjs(record.posting_date),
            contact_name: record.contact_name,
            reason: record.reason,
            bank_account_number: record.bank_account_number,
            bank_name: record.bank_name,
            total_amount: record.total_amount,
            // Never manufacture accounting lines when the API does not return
            // source evidence. The server must provide the lines explicitly.
            lines: Array.isArray(record.lines) ? record.lines : []
        });
        setIsEditModalOpen(true);
    }, [editForm]);

    const handleSaveEdit = () => {
        editForm.validateFields().then(values => {
            if (editVoucher) {
                updateMutation.mutate({
                    id: editVoucher.id,
                    type: editVoucher.type,
                    values: values
                });
            }
        });
    };

    const handleDeleteClick = useCallback((record: any) => {
        setDeleteVoucher(record);
        setIsDeleteModalOpen(true);
    }, []);

    const handleExportExcel = () => {
        const periodLabels: Record<string, string> = {
            today: 'Hôm nay',
            this_week: 'Tuần này',
            month: 'Tháng này',
            quarter: 'Quý này',
            year: 'Cả năm',
            all: 'Tất cả'
        };
        exportBankTransactionsToExcel(allTransactions, periodLabels[period] || 'Năm 2026');
        message.success('Đã xuất khẩu danh sách chứng từ tiền gửi thành công!');
    };

    // Subaction Menus for Receipt (Báo Có)
    const receiptMenu = [
        { key: 'tx-rcpt-1', label: '1. Báo Có thu tiền gửi', onClick: () => window.dispatchEvent(new Event('open-bank-receipt')) },
        { key: 'tx-rcpt-2', label: '2. Thu tiền khách hàng qua ngân hàng', onClick: () => window.dispatchEvent(new Event('open-bank-receipt')) },
        { key: 'tx-rcpt-3', label: '3. Nộp tiền mặt vào tài khoản NH', onClick: () => window.dispatchEvent(new Event('open-bank-receipt')) },
        { key: 'tx-rcpt-4', label: '4. Thu hoàn ứng nhân viên qua TK', onClick: () => window.dispatchEvent(new Event('open-bank-receipt')) },
        { key: 'tx-rcpt-5', label: '5. Thu lãi tiền gửi / hoàn vốn', onClick: () => window.dispatchEvent(new Event('open-bank-receipt')) },
        { key: 'tx-rcpt-6', label: '6. Nhập từ Excel', onClick: () => message.info('Tính năng nhập danh sách Báo Có từ Excel') },
    ];

    // Subaction Menus for Payment (UNC)
    const paymentMenu = [
        { key: 'tx-pmt-1', label: '1. Ủy nhiệm chi trả tiền NCC', onClick: () => window.dispatchEvent(new Event('open-bank-payment')) },
        { key: 'tx-pmt-2', label: '2. Ủy nhiệm chi tạm ứng nhân viên', onClick: () => window.dispatchEvent(new Event('open-bank-payment')) },
        { key: 'tx-pmt-3', label: '3. Nộp thuế vào NSNN qua ngân hàng', onClick: () => window.dispatchEvent(new Event('open-bank-payment')) },
        { key: 'tx-pmt-4', label: '4. Chi trả nợ vay / gốc vay ngân hàng', onClick: () => window.dispatchEvent(new Event('open-bank-payment')) },
        { key: 'tx-pmt-5', label: '5. Báo Nợ / Trích nợ tự động', onClick: () => window.dispatchEvent(new Event('open-bank-payment')) },
        { key: 'tx-pmt-6', label: '6. Nhập từ Excel', onClick: () => message.info('Tính năng nhập danh sách Ủy nhiệm chi từ Excel') },
    ];

    // Memoized Columns
    const columns = useMemo(() => [
        {
            title: 'Ngày hạch toán',
            dataIndex: 'posting_date',
            key: 'posting_date',
            width: 120,
            render: (val: any) => formatDate(val)
        },
        {
            title: 'Ngày chứng từ',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 120,
            render: (val: any) => formatDate(val)
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 130,
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
            title: 'Loại chứng từ',
            dataIndex: 'type_label',
            key: 'type_label',
            width: 120,
            render: (label: string, r: any) => (
                <span className={`misa-badge-type misa-badge-${r.type}`}>
                    {label}
                </span>
            )
        },
        {
            title: 'Tài khoản NH',
            dataIndex: 'bank_account_number',
            key: 'bank_account_number',
            width: 150,
            render: (acc: string, r: any) => (
                <div>
                    <div className="misa-fw-600">{acc}</div>
                    <div className="misa-fs-11 misa-color-muted">{r.bank_name}</div>
                </div>
            )
        },
        { title: 'Đối tượng', dataIndex: 'contact_name', key: 'contact_name', minWidth: 160 },
        { title: 'Lý do / Diễn giải', dataIndex: 'reason', key: 'reason', minWidth: 200 },
        {
            title: 'Số tiền (VND)',
            dataIndex: 'total_amount',
            key: 'total_amount',
            width: 140,
            align: 'right' as const,
            render: (amt: number | null) => <span className="misa-text-bold">{formatBankAmount(amt)}</span>
        },
        {
            title: 'Trạng thái',
            dataIndex: 'is_posted',
            key: 'is_posted',
            width: 110,
            align: 'center' as const,
            render: (isPosted: boolean) => (
                <span className={`misa-apple-pill ${isPosted ? 'misa-apple-pill-green' : 'misa-apple-pill-orange'}`}>
                    <span className="misa-apple-pill-dot" />
                    {bankPostingStatusLabel(isPosted)}
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
                const actionItems = [
                    {
                        key: 'view',
                        icon: <EyeOutlined />,
                        label: 'Xem chi tiết',
                        onClick: () => handleViewVoucherDetails(record)
                    },
                    {
                        key: 'edit',
                        icon: <EditOutlined />,
                        label: 'Sửa chứng từ',
                        onClick: () => handleEditVoucher(record)
                    },
                    ...(typeof isPosted === 'boolean' ? [{
                        key: 'toggle-post',
                        icon: isPosted ? <CloseOutlined /> : <CheckCircleOutlined />,
                        label: isPosted ? 'Bỏ ghi sổ' : 'Ghi sổ',
                        onClick: () => togglePostMutation.mutate({ id: record.id, type: record.type, isPosted })
                    }] : [{
                        key: 'toggle-post-unverified',
                        icon: <ExclamationCircleOutlined />,
                        label: 'Chưa xác minh trạng thái',
                        disabled: true
                    }]),
                    {
                        key: 'duplicate',
                        icon: <CopyOutlined />,
                        label: 'Nhân bản',
                        onClick: () => duplicateMutation.mutate({ id: record.id, type: record.type })
                    },
                    {
                        key: 'delete',
                        icon: <DeleteOutlined />,
                        danger: true,
                        label: 'Xóa chứng từ',
                        onClick: () => handleDeleteClick(record)
                    }
                ];

                return (
                    <div className="misa-flex-center misa-gap-4">
                        <Button
                            type="link"
                            size="small"
                            className="misa-btn-link-action"
                            onClick={() => isPosted === false ? handleEditVoucher(record) : handleViewVoucherDetails(record)}
                        >
                            {isPosted === false ? 'Sửa' : 'Xem'}
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
    ], [handleViewVoucherDetails, handleEditVoucher, togglePostMutation, handleDeleteClick]);

    return (
        <PageShell title={<PageHeader eyebrow="Ngân hàng" title="Giao dịch tiền gửi ngân hàng" description="Theo dõi báo Có, báo Nợ và số dư theo dữ liệu máy chủ." />}>
            {/* Top 3 Stat Cards Banner */}
            {statsVisible && (
                <div className="misa-stat-grid-3">
                    {/* Card 1: Tổng thu TGNH */}
                    <div className="misa-stat-card">
                        <div className="misa-stat-label">Tổng thu TGNH đầu năm đến nay</div>
                        <div className="misa-stat-value misa-stat-value-green">
                            {formatBankAmount(totalReceipts)}
                        </div>
                        <div className="misa-stat-time">
                            Cập nhật: {currentTime}
                        </div>
                    </div>

                    {/* Card 2: Tổng chi TGNH */}
                    <div className="misa-stat-card">
                        <div className="misa-stat-label">Tổng chi TGNH đầu năm đến nay</div>
                        <div className="misa-stat-value misa-stat-value-orange">
                            {formatBankAmount(totalPayments)}
                        </div>
                        <div className="misa-stat-time">
                            Cập nhật: {currentTime}
                        </div>
                    </div>

                    {/* Card 3: Số dư TGNH */}
                    <div className="misa-stat-card">
                        <div className="misa-stat-label">Số dư tiền gửi ngân hàng</div>
                        <div className="misa-stat-value misa-color-blue">
                            {formatBankAmount(netBalance)}
                        </div>
                        <div className="misa-stat-time">
                            Cập nhật: {currentTime}
                        </div>
                    </div>
                </div>
            )}

            {/* MISA Toolbar Header & Filters */}
            <div className="misa-toolbar-header">
                <div className="misa-flex-center misa-gap-8">
                    <Select
                        value={selectedType}
                        onChange={setSelectedType}
                        className="misa-w-130"
                        options={[
                            { value: 'all', label: 'Tất cả' },
                            { value: 'receipt', label: 'Báo Có (Thu)' },
                            { value: 'payment', label: 'Ủy nhiệm chi' }
                        ]}
                    />

                    <Select
                        value={period}
                        onChange={setPeriod}
                        className="misa-w-140"
                        options={[
                            { value: 'today', label: 'Hôm nay' },
                            { value: 'this_week', label: 'Tuần này' },
                            { value: 'month', label: 'Tháng này' },
                            { value: 'quarter', label: 'Quý này' },
                            { value: 'year', label: 'Cả năm' }
                        ]}
                    />

                    <Select
                        value={filterBankAcc}
                        onChange={setFilterBankAcc}
                        className="misa-w-180"
                        options={bankAccountOptions}
                    />

                    <Button
                        icon={<FilterOutlined />}
                        onClick={() => setIsAdvancedFilterOpen(!isAdvancedFilterOpen)}
                        className={isAdvancedFilterOpen ? 'misa-btn-tool-active' : 'misa-btn-tool'}
                        title="Lọc nâng cao"
                    />
                </div>

                <div className="misa-flex-center misa-gap-8">
                    <Input
                        placeholder="Tìm theo số CT, ngân hàng, đối tượng..."
                        prefix={<SearchOutlined className="misa-color-muted" />}
                        className="misa-w-240"
                        value={searchText}
                        onChange={e => setSearchText(e.target.value)}
                        allowClear
                    />

                    <Button
                        icon={<ReloadOutlined />}
                        onClick={() => {
                            queryClient.invalidateQueries({ queryKey: ['bank-receipts'] });
                            queryClient.invalidateQueries({ queryKey: ['bank-payments'] });
                            message.success('Đã làm mới danh sách dữ liệu tiền gửi!');
                        }}
                        title="Nạp lại dữ liệu"
                        className="misa-btn-tool"
                    />

                    <Button
                        icon={<PrinterOutlined />}
                        onClick={() => window.print()}
                        title="In danh sách"
                        className="misa-btn-tool"
                    />

                    <Button
                        icon={<ExportOutlined />}
                        onClick={handleExportExcel}
                        title="Xuất khẩu Excel"
                        className="misa-btn-tool"
                    />

                    <Dropdown menu={{ items: receiptMenu }} placement="bottomRight" trigger={['click']}>
                        <Button
                            type="primary"
                            className="misa-btn-primary"
                            onClick={() => window.dispatchEvent(new Event('open-bank-receipt'))}
                        >
                            <span>Thu tiền (Báo Có)</span>
                            <DownOutlined className="misa-fs-10" />
                        </Button>
                    </Dropdown>

                    <Dropdown menu={{ items: paymentMenu }} placement="bottomRight" trigger={['click']}>
                        <Button
                            type="primary"
                            className="misa-btn-primary"
                            onClick={() => window.dispatchEvent(new Event('open-bank-payment'))}
                        >
                            <span>Chi tiền (UNC)</span>
                            <DownOutlined className="misa-fs-10" />
                        </Button>
                    </Dropdown>
                </div>
            </div>

            {/* Advanced Filter Sub-Bar */}
            {isAdvancedFilterOpen && (
                <div className="misa-filter-subbar">
                    <span className="misa-filter-label">Lọc theo tài khoản:</span>
                    <Input
                        placeholder="VD: 1121, 131, 331..."
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
            <DataTableSurface className="bank-transactions-table-surface">
                {(isReceiptsError || isPaymentsError) && (
                    <Alert
                        className="mb-3"
                        type="error"
                        showIcon
                        message="Không thể tải chứng từ tiền gửi"
                        description="Không hiển thị số liệu tổng hợp như thể danh sách bị rỗng. Hãy thử tải lại nguồn bị lỗi."
                        action={<Button size="small" onClick={() => {
                            if (isReceiptsError) void refetchReceipts();
                            if (isPaymentsError) void refetchPayments();
                        }}>Thử lại chứng từ tiền gửi</Button>}
                    />
                )}
                <div className="misa-table-card">
                <Table
                    loading={isLoadingReceipts || isLoadingPayments}
                    columns={columns}
                    dataSource={allTransactions}
                    rowKey={(r) => String(r.id || r.voucher_number)}
                    pagination={false}
                    size="small"
                    summary={() => (
                        <Table.Summary fixed>
                            <Table.Summary.Row className="misa-table-summary-row">
                                <Table.Summary.Cell index={0} colSpan={7}>
                                    <div className="misa-flex-between">
                                        <span>Tổng ({allTransactions.length} chứng từ)</span>
                                    </div>
                                </Table.Summary.Cell>
                                <Table.Summary.Cell index={1} align="right">
                                    <span className="misa-stat-value-green misa-fs-13 misa-fw-800">
                                        {formatBankAmount(totalFilteredAmount)}
                                    </span>
                                </Table.Summary.Cell>
                                <Table.Summary.Cell index={2} colSpan={2}></Table.Summary.Cell>
                            </Table.Summary.Row>
                        </Table.Summary>
                    )}
                    onRow={(record) => ({
                        onClick: () => setSelectedRow(record),
                        onDoubleClick: () => handleViewVoucherDetails(record),
                        className: selectedRow?.id === record.id ? 'misa-table-row-selected misa-cursor-pointer' : 'misa-cursor-pointer'
                    })}
                    scroll={{ x: 'max-content', y: 'calc(100vh - 350px)' }}
                />

                <div className="misa-table-footer-nav">
                    <div>Tổng số: <strong>{allTransactions.length}</strong> chứng từ</div>
                    <div className="misa-flex-center misa-gap-12">
                        <span>Số dòng/trang: <strong>20</strong></span>
                        <span>1 - {allTransactions.length}</span>
                    </div>
                </div>
            </div></DataTableSurface>

            {/* Detail Table Header */}
            <div className="misa-detail-header-text">
                Chi tiết: <span className="misa-color-blue">{selectedRow?.voucher_number ?? '—'}</span> - {selectedRow?.reason ?? '—'}
            </div>

            {/* Detail Table */}
            <div className="misa-table-card-flex">
                <table className="misa-voucher-table">
                    <thead>
                        <tr>
                            <th className="misa-w-40 misa-text-center">#</th>
                            <th className="misa-min-w-220">Diễn giải</th>
                            <th className="misa-w-100">TK Nợ</th>
                            <th className="misa-w-100">TK Có</th>
                            <th className="misa-w-140 misa-text-right">Số tiền</th>
                            <th className="misa-w-130">Mã đối tượng</th>
                            <th className="misa-min-w-160">Tên đối tượng</th>
                            <th className="misa-w-160">Tài khoản ngân hàng</th>
                        </tr>
                    </thead>
                    <tbody>
                        {selectedRow?.lines && selectedRow.lines.length > 0 ? (
                            selectedRow.lines.map((line: any, index: number) => (
                                <tr key={index}>
                                    <td className="misa-text-center misa-color-muted">{index + 1}</td>
                                    <td>{line.description ?? selectedRow.reason ?? '—'}</td>
                                    <td className="misa-fw-600">{line.debit_account ?? '—'}</td>
                                    <td className="misa-fw-600">{line.credit_account ?? '—'}</td>
                                    <td className="misa-text-right misa-fw-600">
                                        {line.amount == null ? '—' : `${new Intl.NumberFormat('vi-VN').format(line.amount)} ₫`}
                                    </td>
                                    <td>{line.line_contact_id ?? selectedRow.contact_code ?? '—'}</td>
                                    <td>{line.line_contact_name ?? selectedRow.contact_name ?? '—'}</td>
                                    <td>{selectedRow.bank_account_number ?? '—'}</td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td colSpan={8} className="misa-text-center misa-color-muted">
                                    Chưa có dòng hạch toán được máy chủ cung cấp.
                                </td>
                            </tr>
                        )}
                        <tr className="summary-row">
                            <td className="misa-text-center"></td>
                            <td className="misa-fw-800">Tổng cộng</td>
                            <td></td>
                            <td></td>
                            <td className="misa-text-right misa-fw-900 misa-color-green">
                                {formatBankAmount(selectedRow?.total_amount)}
                            </td>
                            <td></td>
                            <td></td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {/* MISA Warning Delete Confirmation Modal */}
            <Modal
                title={
                    <div className="misa-confirm-title">
                        <ExclamationCircleOutlined className="misa-confirm-icon-warning" />
                        <span>Xác nhận xóa chứng từ</span>
                    </div>
                }
                open={isDeleteModalOpen}
                onOk={() => deleteVoucher && deleteMutation.mutate({ id: deleteVoucher.id, type: deleteVoucher.type })}
                onCancel={() => { setIsDeleteModalOpen(false); setDeleteVoucher(null); }}
                okText="Xóa chứng từ"
                cancelText="Hủy bỏ"
                okButtonProps={{ danger: true, loading: deleteMutation.isPending }}
            >
                <p>
                    Bạn có chắc chắn muốn xóa chứng từ <strong>{deleteVoucher?.voucher_number}</strong> ({deleteVoucher?.reason_type}) không?
                </p>
                <p className="misa-fs-12 misa-color-muted">
                    Hành động này sẽ xóa hoàn toàn chứng từ khỏi hệ thống sổ sách và không thể phục hồi.
                </p>
            </Modal>

            {/* Modal Sửa Chứng Từ */}
            <Modal
                title={
                    <div className="misa-modal-clean-header">
                        <EditOutlined className="misa-color-primary" />
                        <span>Sửa chứng từ {editVoucher?.voucher_number} ({editVoucher?.type_label})</span>
                    </div>
                }
                open={isEditModalOpen}
                onOk={handleSaveEdit}
                onCancel={() => { setIsEditModalOpen(false); setEditVoucher(null); }}
                width={850}
                okText="Lưu thay đổi"
                cancelText="Hủy bỏ"
                confirmLoading={updateMutation.isPending}
            >
                <Form form={editForm} layout="vertical" className="misa-pt-12">
                    <div className="misa-grid-3col">
                        <Form.Item name="voucher_number" label="Số chứng từ" rules={[{ required: true }]}>
                            <Input disabled />
                        </Form.Item>
                        <Form.Item name="voucher_date" label="Ngày chứng từ" rules={[{ required: true }]}>
                            <DatePicker format="DD/MM/YYYY" className="misa-w-full" />
                        </Form.Item>
                        <Form.Item name="posting_date" label="Ngày hạch toán" rules={[{ required: true }]}>
                            <DatePicker format="DD/MM/YYYY" className="misa-w-full" />
                        </Form.Item>
                    </div>

                    <div className="misa-grid-2col">
                        <Form.Item name="contact_name" label="Đối tượng nộp / nhận" rules={[{ required: true }]}>
                            <Input />
                        </Form.Item>
                        <Form.Item name="total_amount" label="Tổng số tiền (VND)" rules={[{ required: true }]}>
                            <InputNumber
                                className="misa-w-full"
                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                parser={(v: any) => v?.replace(/\$\s?|(,*)/g, '')}
                            />
                        </Form.Item>
                    </div>

                    <Form.Item name="reason" label="Lý do / Diễn giải" rules={[{ required: true }]}>
                        <Input />
                    </Form.Item>
                </Form>
            </Modal>

            {/* Modal Xem Chi Tiết Chứng Từ */}
            <Modal
                title={
                    <div className="misa-modal-clean-header">
                        <EyeOutlined className="misa-color-blue" />
                        <span>Chi tiết chứng từ {viewVoucher?.voucher_number}</span>
                    </div>
                }
                open={isViewModalOpen}
                onCancel={() => { setIsViewModalOpen(false); setViewVoucher(null); }}
                footer={[
                    <Button key="print" icon={<PrinterOutlined />} onClick={() => window.print()} className="misa-btn-secondary">
                        In chứng từ
                    </Button>,
                    <Button key="close" type="primary" onClick={() => { setIsViewModalOpen(false); setViewVoucher(null); }} className="misa-btn-primary">
                        Đóng
                    </Button>
                ]}
                width={850}
            >
                {viewVoucher && (
                    <div className="misa-flex-col-gap-16">
                        <div className="misa-grid-2col misa-bg-light misa-p-12-16 misa-border-radius-8">
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Số chứng từ:</div>
                                <div className="misa-fw-700 misa-fs-15 misa-color-blue">{viewVoucher.voucher_number}</div>
                            </div>
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Loại chứng từ:</div>
                                <div className="misa-fw-600">{viewVoucher.type_label}</div>
                            </div>
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Đối tượng:</div>
                                <div className="misa-fw-600">{viewVoucher.contact_name || '-'}</div>
                            </div>
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Tổng số tiền:</div>
                                <div className="misa-fw-700 misa-fs-15 misa-color-green">
                                     {formatBankAmount(viewVoucher.total_amount)}
                                </div>
                            </div>
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Ngày hạch toán / chứng từ:</div>
                                <div className="misa-fw-600">{formatDate(viewVoucher.posting_date)} / {formatDate(viewVoucher.voucher_date)}</div>
                            </div>
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Tài khoản ngân hàng:</div>
                                <div className="misa-fw-600">{viewVoucher.bank_account_number} ({viewVoucher.bank_name})</div>
                            </div>
                            <div className="misa-grid-span-2">
                                <div className="misa-fs-12 misa-color-muted">Lý do / Diễn giải:</div>
                                <div className="misa-fw-600">{viewVoucher.reason || '-'}</div>
                            </div>
                        </div>

                        <div className="misa-table-card">
                            <div className="misa-grid-dropdown-header">
                                Các dòng định khoản hạch toán
                            </div>
                            <table className="misa-voucher-table">
                                <thead>
                                    <tr>
                                        <th className="misa-w-40 misa-text-center">#</th>
                                        <th>Diễn giải</th>
                                        <th className="misa-w-100">TK Nợ</th>
                                        <th className="misa-w-100">TK Có</th>
                                        <th className="misa-w-160 misa-text-right">Số tiền</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {viewVoucher.lines && viewVoucher.lines.length > 0 ? (
                                        viewVoucher.lines.map((l: any, idx: number) => (
                                            <tr key={idx}>
                                                <td className="misa-text-center">{idx + 1}</td>
                                                <td>{l.description || viewVoucher.reason}</td>
                                                <td className="misa-fw-700">{l.debit_account ?? '—'}</td>
                                                <td className="misa-fw-700">{l.credit_account ?? '—'}</td>
                                                <td className="misa-text-right misa-fw-700">
                                                    {l.amount == null ? '—' : `${new Intl.NumberFormat('vi-VN').format(l.amount)} ₫`}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={5} className="misa-text-center misa-color-muted">
                                                Chưa có dòng hạch toán được máy chủ cung cấp.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </Modal>
        </PageShell>
    );
});

export default BankTransactions;
