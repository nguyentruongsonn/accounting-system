import React, { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Input, InputNumber, DatePicker, Select, Space, Table } from 'antd';
import { toast as message } from '../feedback/toast';
import type { ColumnsType } from 'antd/es/table';
import { DollarOutlined, FilterOutlined, SearchOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import Modal from '../layout/AppModal';
import ModalFrame from '../layout/ModalFrame';

export interface InvoiceItem {
    id: number | string;
    voucher_number: string;
    voucher_date: string;
    due_date?: string;
    total_amount: number | null;
    paid_amount: number | null;
    remaining_amount: number | null;
    collect_amount: number | null;
    discount_amount: number | null;
    description?: string;
    currency?: string;
}

const sourceMoney = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const amount = Number(value);
    return Number.isFinite(amount) ? amount : null;
};

const asArray = <T,>(payload: unknown, label: string): T[] => {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: unknown[] }).data as T[];
    }
    throw new Error(`Phản hồi ${label} không hợp lệ`);
};

const formatMoney = (value: number | null | undefined): string => (
    value === null || value === undefined ? '—' : `${new Intl.NumberFormat('vi-VN').format(value)} ₫`
);

const asNumber = (value: number | string | null | undefined): number => {
    const parsed = Number(value ?? 0);
    return Number.isFinite(parsed) ? parsed : 0;
};

interface AccountOption { code: string; name: string; is_active?: boolean; }

export interface CollectByInvoiceModalProps {
    open: boolean;
    onClose: () => void;
    initialInvoice?: { id?: number; customer_id?: number } | null;
    onSuccess?: (data: {
        customer_id: number | string;
        customer_name: string;
        customer_address?: string;
        total_collect: number;
        invoices: InvoiceItem[];
    }) => void;
}

const normaliseRows = (rows: any[]): InvoiceItem[] => rows
    .filter((invoice) => invoice?.id !== undefined && invoice?.id !== null)
    .map((invoice) => ({
        id: invoice.id,
        voucher_number: invoice.voucher_number || invoice.invoice_number || '—',
        voucher_date: invoice.voucher_date || invoice.invoice_date || invoice.accounting_date || '',
        due_date: invoice.due_date,
        total_amount: sourceMoney(invoice.total_amount),
        paid_amount: sourceMoney(invoice.paid_amount),
        remaining_amount: sourceMoney(invoice.remaining_amount),
        collect_amount: 0,
        discount_amount: 0,
        description: invoice.description || '',
        currency: invoice.currency || 'VND',
    }));

export const CollectByInvoiceModal: React.FC<CollectByInvoiceModalProps> = ({ open, onClose, onSuccess, initialInvoice }) => {
    const [selectedCustomerId, setSelectedCustomerId] = useState<number | null>(null);
    const [paymentDate, setPaymentDate] = useState(dayjs());
    const [debitAccount, setDebitAccount] = useState<string>();
    const [creditAccount, setCreditAccount] = useState<string>();
    const [filterText, setFilterText] = useState('');
    const [autoAllocateAmount, setAutoAllocateAmount] = useState<number | null>(null);
    const [selectedRowKeys, setSelectedRowKeys] = useState<React.Key[]>([]);
    const [tableData, setTableData] = useState<InvoiceItem[]>([]);
    const [hasLoaded, setHasLoaded] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const customersQuery = useQuery({
        queryKey: ['customers'],
        queryFn: async () => asArray<any>((await api.get('/master/customers')).data, 'khách hàng'),
        enabled: open,
    });
    const accountsQuery = useQuery({
        queryKey: ['cash-collection-accounts'],
        queryFn: async () => asArray<AccountOption>((await api.get('/master/accounts', { params: { include_inactive: false } })).data, 'tài khoản'),
        enabled: open,
    });
    const outstandingQuery = useQuery({
        queryKey: ['sales-invoice-outstanding', selectedCustomerId, paymentDate.format('YYYY-MM-DD')],
        queryFn: async () => asArray<any>((await api.get('/sales/invoices/outstanding', {
            params: { ...(selectedCustomerId ? { customer_id: selectedCustomerId } : {}), as_of_date: paymentDate.format('YYYY-MM-DD') },
        })).data, 'hóa đơn còn phải thu'),
        enabled: false,
    });

    useEffect(() => {
        if (!open) return;
        setSelectedCustomerId(initialInvoice?.customer_id ?? null);
        setPaymentDate(dayjs());
        setDebitAccount(undefined);
        setCreditAccount(undefined);
        setFilterText('');
        setAutoAllocateAmount(null);
        setSelectedRowKeys([]);
        setTableData([]);
        setHasLoaded(false);
        setIsSubmitting(false);
    }, [open, initialInvoice?.id, initialInvoice?.customer_id]);

    const customers = customersQuery.data ?? [];
    const accountOptions = (accountsQuery.data ?? [])
        .filter((account) => account.is_active !== false)
        .map((account) => ({ value: account.code, label: `${account.code} - ${account.name}` }));
    const selectedCustomer = customers.find((customer: any) => Number(customer?.id) === selectedCustomerId);

    const retryLookups = () => {
        void Promise.all([customersQuery.refetch(), accountsQuery.refetch()]);
    };

    const handleCustomerChange = (value: number | null) => {
        setSelectedCustomerId(value);
        setTableData([]);
        setSelectedRowKeys([]);
        setHasLoaded(false);
        setAutoAllocateAmount(null);
    };

    const handleLoadInvoices = async () => {
        if (!selectedCustomerId) {
            message.warning('Vui lòng chọn khách hàng cần thu tiền.');
            return;
        }
        try {
            const result = await outstandingQuery.refetch();
            if (!result.data) throw new Error('Máy chủ không trả về danh sách hóa đơn còn phải thu.');
            const rows = normaliseRows(result.data);
            setTableData(rows);
            setSelectedRowKeys(initialInvoice?.id && rows.some((row) => Number(row.id) === Number(initialInvoice.id)) ? [initialInvoice.id] : []);
            setHasLoaded(true);
        } catch (error: any) {
            message.error(error?.response?.data?.error || error?.response?.data?.message || error?.message || 'Không tải được danh sách hóa đơn còn phải thu.');
        }
    };

    const updateInvoice = (id: InvoiceItem['id'], amount: number | null) => {
        const value = Math.max(0, Number(amount) || 0);
        setTableData((current) => current.map((invoice) => invoice.id === id ? { ...invoice, collect_amount: value } : invoice));
        setSelectedRowKeys((keys) => value > 0
            ? (keys.includes(id) ? keys : [...keys, id])
            : keys.filter((key) => String(key) !== String(id)));
    };

    const handleAutoAllocate = (value: number | null) => {
        const amount = Math.max(0, Number(value) || 0);
        setAutoAllocateAmount(amount || null);
        let remaining = amount;
        const keys: React.Key[] = [];
        setTableData((current) => current.map((invoice) => {
            const allocation = Math.min(remaining, asNumber(invoice.remaining_amount));
            remaining -= allocation;
            if (allocation > 0) keys.push(invoice.id);
            return { ...invoice, collect_amount: allocation };
        }));
        setSelectedRowKeys(keys);
    };

    const selectedInvoices = useMemo(() => tableData.filter((invoice) => (
        selectedRowKeys.some((key) => String(key) === String(invoice.id)) && asNumber(invoice.collect_amount) > 0
    )), [tableData, selectedRowKeys]);
    const totalCollect = selectedInvoices.reduce((total, invoice) => total + asNumber(invoice.collect_amount), 0);
    const filteredInvoices = useMemo(() => {
        const keyword = filterText.trim().toLowerCase();
        if (!keyword) return tableData;
        return tableData.filter((invoice) => [invoice.voucher_number, invoice.description]
            .some((value) => String(value || '').toLowerCase().includes(keyword)));
    }, [filterText, tableData]);

    const handleConfirm = async () => {
        if (!selectedCustomerId || selectedInvoices.length === 0 || totalCollect <= 0) {
            message.warning('Vui lòng chọn ít nhất một hóa đơn có số thu lớn hơn 0.');
            return;
        }
        if (!debitAccount || !creditAccount) {
            message.warning('Chọn đầy đủ tài khoản Nợ và Có từ hệ thống tài khoản.');
            return;
        }
        if (selectedInvoices.some((invoice) => !Number.isInteger(asNumber(invoice.collect_amount)))) {
            message.warning('Thu tiền mặt chỉ nhận số tiền nguyên VND.');
            return;
        }

        setIsSubmitting(true);
        const completed: InvoiceItem[] = [];
        try {
            for (const invoice of selectedInvoices) {
                const codeResponse = await api.get('/cash/receipts/next-code');
                const voucherNumber = codeResponse.data?.code || codeResponse.data?.data?.code || codeResponse.data?.next_code;
                if (!voucherNumber) throw new Error('Máy chủ không trả về số phiếu thu tiếp theo.');
                const response = await api.post(`/sales/invoices/${invoice.id}/collect`, {
                    voucher_number: voucherNumber,
                    voucher_date: paymentDate.format('YYYY-MM-DD'),
                    posting_date: paymentDate.format('YYYY-MM-DD'),
                    amount_raw: String(asNumber(invoice.collect_amount)),
                    amount_scale: 0,
                    debit_account: debitAccount,
                    credit_account: creditAccount,
                    currency: invoice.currency || 'VND',
                    payer_name: selectedCustomer?.name,
                    description: `Thu tiền hóa đơn ${invoice.voucher_number}`,
                });
                if (!response.data?.data?.receipt?.id || !response.data?.data?.allocation?.id) {
                    throw new Error('Máy chủ không xác nhận phiếu thu và phân bổ công nợ đã lưu.');
                }
                completed.push(invoice);
            }
            const payload = {
                customer_id: selectedCustomerId,
                customer_name: selectedCustomer?.name || '',
                customer_address: selectedCustomer?.address || '',
                total_collect: totalCollect,
                invoices: completed,
            };
            onSuccess?.(payload);
            window.dispatchEvent(new Event('cash-receipts-invalidated'));
            window.dispatchEvent(new Event('sales-invoices-invalidated'));
            message.success(`Đã thu tiền ${completed.length} hóa đơn và cập nhật công nợ.`);
            onClose();
        } catch (error: any) {
            if (completed.length > 0) {
                const completedIds = new Set(completed.map((invoice) => String(invoice.id)));
                setTableData((current) => current.map((invoice) => completedIds.has(String(invoice.id)) ? { ...invoice, collect_amount: 0 } : invoice));
                setSelectedRowKeys((keys) => keys.filter((key) => !completedIds.has(String(key))));
                window.dispatchEvent(new Event('cash-receipts-invalidated'));
                window.dispatchEvent(new Event('sales-invoices-invalidated'));
            }
            message.error(error?.response?.data?.error || error?.response?.data?.message || `Không hoàn tất thu tiền${completed.length ? `; đã lưu ${completed.length} hóa đơn trước đó` : ''}.`);
        } finally {
            setIsSubmitting(false);
        }
    };

    const columns: ColumnsType<InvoiceItem> = [
        { title: 'Ngày HĐ', dataIndex: 'voucher_date', key: 'voucher_date', width: 100, render: (date) => date ? dayjs(date).format('DD/MM/YYYY') : '—' },
        { title: 'Số hóa đơn', dataIndex: 'voucher_number', key: 'voucher_number', width: 120, render: (value) => <span className="misa-text-blue-bold">{value}</span> },
        { title: 'Hạn TT', dataIndex: 'due_date', key: 'due_date', width: 100, render: (date) => date ? dayjs(date).format('DD/MM/YYYY') : '—' },
        { title: 'Diễn giải', dataIndex: 'description', key: 'description', width: 190, ellipsis: true },
        { title: 'Tổng tiền HĐ', dataIndex: 'total_amount', key: 'total_amount', width: 130, align: 'right', render: formatMoney },
        { title: 'Đã thu', dataIndex: 'paid_amount', key: 'paid_amount', width: 110, align: 'right', render: formatMoney },
        { title: 'Còn phải thu', dataIndex: 'remaining_amount', key: 'remaining_amount', width: 135, align: 'right', render: (value) => <span className="misa-text-dark-bold">{formatMoney(value)}</span> },
        { title: 'Số thu lần này', dataIndex: 'collect_amount', key: 'collect_amount', width: 150, align: 'right', render: (value, record) => <InputNumber value={value ?? 0} min={0} max={record.remaining_amount ?? undefined} precision={0} formatter={(next) => `${next ?? ''}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} parser={(next: any) => next?.replace(/\$\s?|(,*)/g, '') || ''} onChange={(next) => updateInvoice(record.id, next)} className="misa-input misa-w-full misa-text-semibold" /> },
    ];

    return (
        <Modal
            title={<div className="misa-modal-title"><DollarOutlined className="misa-text-primary-bold" /><span>Thu tiền khách hàng theo hóa đơn</span></div>}
            open={open}
            onCancel={onClose}
            width={1180}
            className="misa-modal-top-20"
            footer={<div className="misa-modal-footer"><div className="misa-font-13-muted">Đã chọn: <strong className="misa-text-green-bold">{selectedInvoices.length}</strong> hóa đơn | Tổng số thu: <strong className="misa-text-blue-bold">{formatMoney(totalCollect)}</strong></div><Space><Button onClick={onClose} className="misa-btn-secondary">Hủy (Esc)</Button><Button type="primary" onClick={handleConfirm} loading={isSubmitting} className="misa-btn-primary">Thu tiền</Button></Space></div>}
            centered
        >
            <ModalFrame className="misa-collect-by-invoice-modal__frame">
                {(customersQuery.isError || accountsQuery.isError) && <Alert type="error" showIcon message="Không thể tải dữ liệu thu tiền theo hóa đơn" description="Danh mục khách hàng hoặc tài khoản chưa tải được. Dữ liệu form được giữ nguyên; hãy thử lại." action={<Button size="small" onClick={retryLookups}>Thử lại</Button>} className="misa-mb-12" />}
                <div className="misa-filter-box">
                    <div className="misa-flex-center-gap-12 flex-wrap">
                        <div className="misa-flex-center-gap-6"><span className="misa-font-12-muted">Khách hàng:</span><Select value={selectedCustomerId} onChange={handleCustomerChange} allowClear showSearch optionFilterProp="label" className="misa-input misa-w-280" placeholder="Chọn khách hàng cần thu nợ..." options={customers.map((customer: any) => ({ value: customer.id, label: `${customer.code ? `${customer.code} - ` : ''}${customer.name}` }))} getPopupContainer={() => document.body} /></div>
                        <div className="misa-flex-center-gap-6"><span className="misa-font-12-muted">Ngày thu:</span><DatePicker value={paymentDate} onChange={(date) => setPaymentDate(date || dayjs())} format="DD/MM/YYYY" className="misa-input misa-w-130" /></div>
                        <div className="misa-flex-center-gap-6"><span className="misa-font-12-muted">TK Nợ:</span><Select value={debitAccount} onChange={setDebitAccount} showSearch optionFilterProp="label" className="misa-input misa-w-190" placeholder="Chọn tài khoản" options={accountOptions} getPopupContainer={() => document.body} /></div>
                        <div className="misa-flex-center-gap-6"><span className="misa-font-12-muted">TK Có:</span><Select value={creditAccount} onChange={setCreditAccount} showSearch optionFilterProp="label" className="misa-input misa-w-190" placeholder="Chọn tài khoản" options={accountOptions} getPopupContainer={() => document.body} /></div>
                        <Button type="primary" icon={<FilterOutlined />} className="misa-btn-modal-action" onClick={handleLoadInvoices} loading={outstandingQuery.isFetching}>Lấy dữ liệu</Button>
                    </div>
                    <div className="misa-kpi-summary-box"><div className="misa-font-11-muted">Tổng số thu</div><div className="misa-validation-val-dark">{formatMoney(totalCollect)}</div></div>
                </div>
                <div className="misa-quick-bar"><Input placeholder="Tìm theo số hóa đơn, diễn giải..." prefix={<SearchOutlined className="apple-muted-text" />} value={filterText} onChange={(event) => setFilterText(event.target.value)} allowClear className="misa-input misa-w-320" /><div className="misa-flex-center-gap-8"><span className="misa-font-12-blue misa-text-bold">Tự động phân bổ số tiền:</span><InputNumber placeholder="Nhập số tiền muốn thu..." value={autoAllocateAmount} onChange={handleAutoAllocate} precision={0} formatter={(value) => `${value ?? ''}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} parser={(value: any) => value?.replace(/\$\s?|(,*)/g, '') || ''} className="misa-input misa-w-200" /></div></div>
                <div className="misa-table-wrapper"><Table rowKey="id" columns={columns} dataSource={filteredInvoices} loading={outstandingQuery.isFetching} size="small" pagination={false} bordered scroll={{ x: 1120, y: 320 }} locale={{ emptyText: hasLoaded ? 'Không có hóa đơn còn phải thu trong phạm vi đã chọn.' : 'Chọn khách hàng rồi bấm Lấy dữ liệu.' }} rowSelection={{ selectedRowKeys, onChange: (keys) => { setSelectedRowKeys(keys); setTableData((current) => current.map((invoice) => keys.some((key) => String(key) === String(invoice.id)) && asNumber(invoice.collect_amount) === 0 ? { ...invoice, collect_amount: asNumber(invoice.remaining_amount) } : invoice)); } }} /></div>
            </ModalFrame>
        </Modal>
    );
};

export default CollectByInvoiceModal;
