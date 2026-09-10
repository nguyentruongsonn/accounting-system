import React, { useEffect, useMemo, useState } from 'react';
import { Button, DatePicker, Input, InputNumber, Select, Space, Table } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { DollarOutlined, FilterOutlined, SearchOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useQuery } from '@tanstack/react-query';
import api from '../../../api/axios';

interface PayVendorByInvoiceModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
    initialInvoice?: { id?: number; supplier_id?: number } | null;
}

interface UnpaidInvoice {
    id: number;
    voucher_number: string;
    voucher_date: string;
    invoice_number: string;
    invoice_date: string;
    description: string;
    due_date: string;
    currency?: string;
    total_amount: number | string;
    remaining_debt: number | string;
    pay_amount: number;
    discount_rate: number;
    discount_amount: number;
}

interface AccountOption {
    code: string;
    name: string;
    is_active?: boolean;
}

const asArray = <T,>(payload: unknown, label: string): T[] => {
    const body = payload as { data?: unknown } | null;
    const records = Array.isArray(payload) ? payload : body?.data;
    if (!Array.isArray(records)) throw new Error(`Invalid ${label} response`);
    return records as T[];
};

const asNumber = (value: number | string | null | undefined): number => {
    const parsed = Number(value ?? 0);
    return Number.isFinite(parsed) ? parsed : 0;
};

const formatMoney = (value: number | string): string => new Intl.NumberFormat('vi-VN').format(asNumber(value));

const normaliseRows = (rows: UnpaidInvoice[]): UnpaidInvoice[] => rows.map((invoice) => ({
    ...invoice,
    voucher_number: invoice.voucher_number || invoice.invoice_number || '—',
    invoice_number: invoice.invoice_number || invoice.voucher_number || '—',
    description: invoice.description || 'Thanh toán hóa đơn mua hàng',
    total_amount: invoice.total_amount ?? 0,
    remaining_debt: invoice.remaining_debt ?? 0,
    pay_amount: 0,
    discount_rate: 0,
    discount_amount: 0,
}));

export const PayVendorByInvoiceModal: React.FC<PayVendorByInvoiceModalProps> = ({ open, onCancel, onSuccess, initialInvoice }) => {
    const [selectedSupplierId, setSelectedSupplierId] = useState<number | null>(null);
    const [paymentDate, setPaymentDate] = useState<dayjs.Dayjs>(dayjs());
    const [filterText, setFilterText] = useState('');
    const [autoAllocateAmount, setAutoAllocateAmount] = useState<number | null>(null);
    const [debitAccount, setDebitAccount] = useState<string>();
    const [creditAccount, setCreditAccount] = useState<string>();
    const [selectedRowKeys, setSelectedRowKeys] = useState<React.Key[]>([]);
    const [invoices, setInvoices] = useState<UnpaidInvoice[]>([]);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [hasLoaded, setHasLoaded] = useState(false);

    const suppliersQuery = useQuery({
        queryKey: ['purchase-payment-suppliers'],
        queryFn: async () => asArray<any>((await api.get('/master/suppliers')).data, 'supplier catalogue'),
        enabled: open,
    });
    const accountsQuery = useQuery({
        queryKey: ['purchase-payment-accounts'],
        queryFn: async () => asArray<AccountOption>((await api.get('/master/accounts', { params: { include_inactive: 0 } })).data, 'account catalogue'),
        enabled: open,
    });
    const outstandingQuery = useQuery({
        queryKey: ['purchase-invoice-outstanding', selectedSupplierId, paymentDate.format('YYYY-MM-DD')],
        queryFn: async () => asArray<UnpaidInvoice>((await api.get('/purchase/invoices/outstanding', {
            params: { ...(selectedSupplierId ? { supplier_id: selectedSupplierId } : {}), as_of_date: paymentDate.format('YYYY-MM-DD') },
        })).data, 'outstanding purchase-invoice'),
        enabled: false,
    });

    useEffect(() => {
        if (!open) return;
        setSelectedSupplierId(initialInvoice?.supplier_id ?? null);
        setSelectedRowKeys(initialInvoice?.id ? [initialInvoice.id] : []);
        setInvoices([]);
        setHasLoaded(false);
        setAutoAllocateAmount(null);
        setDebitAccount(undefined);
        setCreditAccount(undefined);
        setPaymentDate(dayjs());
    }, [open, initialInvoice?.id, initialInvoice?.supplier_id]);

    const supplierList = suppliersQuery.data ?? [];
    const accountList = (accountsQuery.data ?? []).filter((account) => account.is_active !== false);

    const handleSupplierChange = (value: number | null) => {
        setSelectedSupplierId(value);
        setInvoices([]);
        setSelectedRowKeys([]);
        setHasLoaded(false);
    };

    const handleLoadInvoices = async () => {
        try {
            const result = await outstandingQuery.refetch();
            if (!result.data) throw new Error('Máy chủ không trả về danh sách hóa đơn còn nợ.');
            const rows = normaliseRows(result.data);
            setInvoices(rows);
            const initialKey = initialInvoice?.id && rows.some((row) => row.id === initialInvoice.id) ? [initialInvoice.id] : [];
            setSelectedRowKeys(initialKey);
            setHasLoaded(true);
        } catch (error: any) {
            message.error(error?.response?.data?.error || error?.message || 'Không tải được danh sách hóa đơn còn phải trả.');
        }
    };

    const handleAutoAllocate = (amount: number | null) => {
        setAutoAllocateAmount(amount);
        if (!amount || amount <= 0) {
            setSelectedRowKeys([]);
            setInvoices((current) => current.map((invoice) => ({ ...invoice, pay_amount: 0, discount_amount: 0 })));
            return;
        }
        let remaining = amount;
        const keys: React.Key[] = [];
        setInvoices((current) => current.map((invoice) => {
            if (remaining <= 0) return { ...invoice, pay_amount: 0, discount_amount: 0 };
            const payable = Math.min(remaining, asNumber(invoice.remaining_debt));
            remaining -= payable;
            keys.push(invoice.id);
            return { ...invoice, pay_amount: payable, discount_amount: 0 };
        }));
        setSelectedRowKeys(keys);
    };

    const updateInvoice = (id: number, field: 'pay_amount' | 'discount_rate', value: number | null) => {
        setInvoices((current) => current.map((invoice) => {
            if (invoice.id !== id) return invoice;
            const nextValue = Number(value) || 0;
            return {
                ...invoice,
                [field]: nextValue,
                discount_amount: field === 'discount_rate'
                    ? asNumber(invoice.pay_amount) * nextValue / 100
                    : asNumber(invoice.discount_rate) * nextValue / 100,
            };
        }));
        if (field === 'pay_amount' && (Number(value) || 0) > 0 && !selectedRowKeys.includes(id)) setSelectedRowKeys((keys) => [...keys, id]);
    };

    const selectedInvoices = useMemo(() => invoices.filter((invoice) => selectedRowKeys.includes(invoice.id) && asNumber(invoice.pay_amount) > 0), [invoices, selectedRowKeys]);
    const totalSelectedPayment = selectedInvoices.reduce((total, invoice) => total + asNumber(invoice.pay_amount), 0);
    const totalSelectedDiscount = selectedInvoices.reduce((total, invoice) => total + asNumber(invoice.discount_amount), 0);
    const filteredInvoices = useMemo(() => {
        const keyword = filterText.trim().toLowerCase();
        if (!keyword) return invoices;
        return invoices.filter((invoice) => [invoice.voucher_number, invoice.invoice_number, invoice.description].some((value) => String(value || '').toLowerCase().includes(keyword)));
    }, [filterText, invoices]);

    const catalogueUnavailable = suppliersQuery.isError || accountsQuery.isError;
    const catalogueErrorKey = suppliersQuery.isError ? 'suppliers' : accountsQuery.isError ? 'accounts' : '';
    useEffect(() => {
        if (!open || !catalogueErrorKey) return;
        const queryError = suppliersQuery.error || accountsQuery.error;
        const status = (queryError as { response?: { status?: number } } | undefined)?.response?.status;
        const detail = status === 403
            ? 'Bạn không có quyền xem danh mục thanh toán.'
            : status === 422
                ? 'Danh mục thanh toán không đúng định dạng máy chủ yêu cầu.'
                : 'Không tải được danh mục thanh toán. Kiểm tra quyền truy cập rồi thử lại.';
        message.error(detail);
    }, [accountsQuery.error, catalogueErrorKey, open, suppliersQuery.error]);

    const retryCatalogue = () => {
        void suppliersQuery.refetch();
        void accountsQuery.refetch();
    };

    const handleConfirmPayment = async () => {
        if (selectedInvoices.length === 0) return message.warning('Chọn ít nhất một hóa đơn và nhập số tiền thanh toán.');
        if (!debitAccount || !creditAccount) return message.warning('Chọn đầy đủ tài khoản Nợ và Có từ hệ thống tài khoản.');
        if (totalSelectedDiscount > 0) return message.warning('Chiết khấu phải lập chứng từ giảm giá riêng trước khi thanh toán.');
        if (selectedInvoices.some((invoice) => !Number.isInteger(asNumber(invoice.pay_amount)))) return message.warning('Thanh toán tiền mặt chỉ nhận số tiền nguyên VND.');

        setIsSubmitting(true);
        let completed = 0;
        try {
            for (const invoice of selectedInvoices) {
                const nextCodeResponse = await api.get('/cash/payments/next-code');
                const nextCode = nextCodeResponse.data?.code || nextCodeResponse.data?.data?.code || nextCodeResponse.data?.next_code;
                if (!nextCode) throw new Error('Máy chủ không trả về số phiếu chi tiếp theo.');
                const response = await api.post(`/purchase/invoices/${invoice.id}/pay`, {
                    voucher_number: nextCode,
                    voucher_date: paymentDate.format('YYYY-MM-DD'),
                    posting_date: paymentDate.format('YYYY-MM-DD'),
                    amount_raw: String(asNumber(invoice.pay_amount)),
                    amount_scale: 0,
                    debit_account: debitAccount,
                    credit_account: creditAccount,
                    currency: invoice.currency || 'VND',
                    description: `Thanh toán hóa đơn ${invoice.invoice_number || invoice.voucher_number}`,
                });
                if (!response.data?.data?.payment?.id || !response.data?.data?.allocation?.id) throw new Error('Máy chủ không xác nhận phiếu chi và phân bổ công nợ đã lưu.');
                completed += 1;
            }
            message.success(`Đã thanh toán ${completed} hóa đơn và cập nhật công nợ.`);
            onSuccess?.();
            onCancel();
        } catch (error: any) {
            if (completed > 0) {
                const completedIds = new Set(selectedInvoices.slice(0, completed).map((invoice) => String(invoice.id)));
                setInvoices((current) => current.map((invoice) => completedIds.has(String(invoice.id)) ? { ...invoice, pay_amount: 0, discount_amount: 0 } : invoice));
                setSelectedRowKeys((keys) => keys.filter((key) => !completedIds.has(String(key))));
            }
            message.error(error?.response?.data?.error || error?.response?.data?.message || `Không hoàn tất thanh toán${completed ? `; đã lưu ${completed} hóa đơn trước đó` : ''}.`);
            onSuccess?.();
        } finally {
            setIsSubmitting(false);
        }
    };

    const accountOptions = accountList.map((account) => ({ value: account.code, label: `${account.code} - ${account.name}` }));
    const columns = [
        { title: 'Ngày CT', dataIndex: 'voucher_date', key: 'voucher_date', width: 100, align: 'center' as const, render: (date: string) => date ? dayjs(date).format('DD/MM/YYYY') : '—' },
        { title: 'Số chứng từ', dataIndex: 'voucher_number', key: 'voucher_number', width: 110, render: (value: string) => <span className="misa-text-blue-bold">{value}</span> },
        { title: 'Số hóa đơn', dataIndex: 'invoice_number', key: 'invoice_number', width: 105, render: (value: string) => <span className="misa-text-semibold">{value}</span> },
        { title: 'Ngày HĐ', dataIndex: 'invoice_date', key: 'invoice_date', width: 100, align: 'center' as const, render: (date: string) => date ? dayjs(date).format('DD/MM/YYYY') : '—' },
        { title: 'Diễn giải', dataIndex: 'description', key: 'description', width: 180, ellipsis: true },
        { title: 'Hạn TT', dataIndex: 'due_date', key: 'due_date', width: 100, align: 'center' as const, render: (date: string) => date ? dayjs(date).format('DD/MM/YYYY') : '—' },
        { title: 'Số còn nợ', dataIndex: 'remaining_debt', key: 'remaining_debt', width: 135, align: 'right' as const, render: (value: number | string) => <span className="misa-text-dark-bold">{formatMoney(value)} ₫</span> },
        { title: 'Số trả lần này', dataIndex: 'pay_amount', key: 'pay_amount', width: 145, align: 'right' as const, render: (value: number, row: UnpaidInvoice) => <InputNumber value={value} onChange={(next) => updateInvoice(row.id, 'pay_amount', next)} max={asNumber(row.remaining_debt)} min={0} precision={0} formatter={(next) => `${next ?? ''}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} parser={(next: any) => next?.replace(/\$\s?|(,*)/g, '') || ''} className="misa-input misa-w-full misa-text-semibold" /> },
        { title: '% CK', dataIndex: 'discount_rate', key: 'discount_rate', width: 75, render: (value: number, row: UnpaidInvoice) => <InputNumber value={value} onChange={(next) => updateInvoice(row.id, 'discount_rate', next)} min={0} max={100} precision={2} className="misa-input misa-w-full" /> },
        { title: 'Tiền chiết khấu', dataIndex: 'discount_amount', key: 'discount_amount', width: 120, align: 'right' as const, render: (value: number) => <span className="misa-text-blue-bold">{formatMoney(value)} ₫</span> },
    ];

    return (
        <Modal
            title={<div className="misa-modal-title misa-pay-vendor-modal__title"><DollarOutlined className="misa-text-primary-bold" /><span>Trả tiền theo hóa đơn</span></div>}
            open={open}
            onCancel={onCancel}
            width={1180}
            className="misa-modal-top-20 misa-pay-vendor-modal"
            footer={(
                <div className="misa-modal-footer misa-pay-vendor-modal__footer">
                    <div className="misa-pay-vendor-modal__footer-summary">
                        Đã chọn: <strong className="misa-text-green-bold">{selectedInvoices.length}</strong> hóa đơn
                        <span className="misa-pay-vendor-modal__footer-divider">|</span>
                        Tổng tiền trả: <strong className="misa-text-blue-bold">{formatMoney(totalSelectedPayment)} ₫</strong>
                    </div>
                    <Space className="misa-pay-vendor-modal__footer-actions">
                        <Button onClick={onCancel} className="misa-btn-secondary">Hủy (Esc)</Button>
                        <Button type="primary" onClick={handleConfirmPayment} className="misa-btn-primary" loading={isSubmitting} disabled={catalogueUnavailable}>Trả tiền</Button>
                    </Space>
                </div>
            )}
        >
            {catalogueUnavailable && (
                <div className="misa-modal-inline-actions misa-pay-vendor-modal__catalogue-status">
                    <span className="misa-font-12-muted">Danh mục chưa sẵn sàng; thao tác trả tiền đang tạm khóa.</span>
                    <Button size="small" className="misa-btn-secondary" onClick={retryCatalogue}>Thử lại danh mục</Button>
                </div>
            )}

            <section className="misa-pay-vendor-modal__filters" aria-label="Bộ lọc thanh toán">
                <div className="misa-pay-vendor-modal__filter-grid">
                    <label className="misa-pay-vendor-modal__field">
                        <span>Nhà cung cấp</span>
                        <Select value={selectedSupplierId} onChange={handleSupplierChange} allowClear showSearch optionFilterProp="label" className="misa-pay-vendor-modal__control" placeholder="Tất cả nhà cung cấp" options={supplierList.map((supplier: any) => ({ value: supplier.id, label: `${supplier.code ? `${supplier.code} - ` : ''}${supplier.name}` }))} getPopupContainer={() => document.body} />
                    </label>
                    <label className="misa-pay-vendor-modal__field misa-pay-vendor-modal__field-date">
                        <span>Ngày trả tiền</span>
                        <DatePicker value={paymentDate} onChange={(date) => setPaymentDate(date || dayjs())} format="DD/MM/YYYY" className="misa-pay-vendor-modal__control" getPopupContainer={() => document.body} />
                    </label>
                    <label className="misa-pay-vendor-modal__field">
                        <span>TK Nợ</span>
                        <Select value={debitAccount} onChange={setDebitAccount} showSearch optionFilterProp="label" className="misa-pay-vendor-modal__control" placeholder="Chọn tài khoản" options={accountOptions} getPopupContainer={() => document.body} />
                    </label>
                    <label className="misa-pay-vendor-modal__field">
                        <span>TK Có</span>
                        <Select value={creditAccount} onChange={setCreditAccount} showSearch optionFilterProp="label" className="misa-pay-vendor-modal__control" placeholder="Chọn tài khoản" options={accountOptions} getPopupContainer={() => document.body} />
                    </label>
                    <div className="misa-pay-vendor-modal__filter-action">
                        <Button type="primary" icon={<FilterOutlined />} className="misa-btn-modal-action" onClick={handleLoadInvoices} loading={outstandingQuery.isFetching}>Lấy dữ liệu</Button>
                    </div>
                </div>
                <div className="misa-pay-vendor-modal__summary" aria-live="polite">
                    <span>Tổng tiền trả</span>
                    <strong>{formatMoney(totalSelectedPayment)} ₫</strong>
                </div>
            </section>

            <div className="misa-pay-vendor-modal__toolbar">
                <Input placeholder="Tìm theo số chứng từ, hóa đơn, diễn giải..." prefix={<SearchOutlined className="apple-muted-text" />} value={filterText} onChange={(event) => setFilterText(event.target.value)} allowClear className="misa-pay-vendor-modal__search" />
                <label className="misa-pay-vendor-modal__auto-allocate">
                    <span>Tự động phân bổ số tiền</span>
                    <InputNumber placeholder="Nhập số tiền muốn trả..." value={autoAllocateAmount} onChange={handleAutoAllocate} precision={0} formatter={(value) => `${value ?? ''}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} parser={(value: any) => value?.replace(/\$\s?|(,*)/g, '') || ''} className="misa-pay-vendor-modal__control misa-pay-vendor-modal__auto-input" />
                </label>
            </div>

            <div className="misa-pay-vendor-modal__table-shell">
                <Table
                    rowKey="id"
                    columns={columns}
                    dataSource={filteredInvoices}
                    loading={outstandingQuery.isFetching}
                    size="small"
                    pagination={false}
                    locale={{ emptyText: hasLoaded ? 'Không có hóa đơn còn phải trả trong phạm vi đã chọn.' : 'Chọn bộ lọc rồi bấm Lấy dữ liệu.' }}
                    rowSelection={{ selectedRowKeys, onChange: (keys) => { setSelectedRowKeys(keys); setInvoices((current) => current.map((invoice) => keys.includes(invoice.id) && invoice.pay_amount === 0 ? { ...invoice, pay_amount: asNumber(invoice.remaining_debt) } : invoice)); } }}
                    scroll={{ x: 1160, y: 300 }}
                />
            </div>
        </Modal>
    );
};

export default PayVendorByInvoiceModal;
