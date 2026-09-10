import React, { useState, useMemo } from 'react';
import { Alert, InputNumber, Button, Table, DatePicker, Select } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import { DollarOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import api from '../../api/axios';
import { formatDate } from '../../utils/dateUtils';

export interface PaySupplierInvoiceItem {
    id: number | string;
    voucher_number: string;
    voucher_date: string;
    due_date?: string;
    total_amount: number | null;
    paid_amount: number | null;
    remaining_amount: number | null;
    pay_amount: number | null;
    discount_amount: number | null;
    description?: string;
}

const sourceMoney = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const amount = Number(value);
    return Number.isFinite(amount) ? amount : null;
};

const formatMoney = (value: number | null | undefined): string => (
    value === null || value === undefined
        ? '—'
        : `${new Intl.NumberFormat('vi-VN').format(value)} ₫`
);

const parseListPayload = (payload: unknown, label: string): unknown[] => {
    if (Array.isArray(payload)) return payload;
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: unknown[] }).data;
    }
    throw new Error(`Phản hồi ${label} không hợp lệ`);
};

interface PayByInvoiceModalProps {
    open: boolean;
    onClose: () => void;
    onSuccess?: (data: {
        supplier_id: number | string;
        supplier_name: string;
        supplier_address?: string;
        total_pay: number;
        invoices: PaySupplierInvoiceItem[];
    }) => void;
}

export const PayByInvoiceModal: React.FC<PayByInvoiceModalProps> = ({
    open,
    onClose,
    onSuccess
}) => {
    const [selectedSupplierId, setSelectedSupplierId] = useState<any>(null);
    const [selectedRowKeys, setSelectedRowKeys] = useState<React.Key[]>([]);
    const [tableData, setTableData] = useState<PaySupplierInvoiceItem[]>([]);

    const suppliersQuery = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parseListPayload(data, 'nhà cung cấp');
        },
        enabled: open
    });
    const { data: suppliers = [], isError: suppliersLoadError } = suppliersQuery;

    const purchaseInvoicesQuery = useQuery({
        queryKey: ['purchase-invoices-payable', selectedSupplierId],
        queryFn: async () => {
            if (!selectedSupplierId) return [];
            const { data } = await api.get(`/purchase/invoices?supplier_id=${selectedSupplierId}&status=Unpaid`);
            const list = parseListPayload(data, 'hóa đơn mua hàng');
            return list.filter((inv: any) => inv?.id !== undefined && inv?.id !== null).map((inv: any) => ({
                id: inv.id,
                voucher_number: inv.invoice_number || inv.voucher_number || '',
                voucher_date: inv.invoice_date || inv.accounting_date || inv.created_at || '',
                due_date: inv.due_date,
                total_amount: sourceMoney(inv.total_amount),
                paid_amount: sourceMoney(inv.paid_amount),
                remaining_amount: sourceMoney(inv.total_amount) === null
                    ? null
                    : Math.max(0, (sourceMoney(inv.total_amount) as number) - (sourceMoney(inv.paid_amount) ?? 0)),
                pay_amount: sourceMoney(inv.total_amount) === null
                    ? null
                    : Math.max(0, (sourceMoney(inv.total_amount) as number) - (sourceMoney(inv.paid_amount) ?? 0)),
                discount_amount: sourceMoney(inv.discount_amount),
                description: inv.description || ''
            }));
        },
        enabled: open && !!selectedSupplierId
    });
    const { data: purchaseInvoices, isLoading, isSuccess: invoicesLoaded, isError: invoicesLoadError } = purchaseInvoicesQuery;

    const retryLookups = () => {
        void Promise.all([
            suppliersQuery.refetch(),
            selectedSupplierId ? purchaseInvoicesQuery.refetch() : Promise.resolve()
        ]);
    };

    React.useEffect(() => {
        if (!open || !selectedSupplierId) {
            setSelectedRowKeys([]);
            setTableData([]);
            return;
        }
        if (!invoicesLoaded) return;
        if (purchaseInvoices && purchaseInvoices.length > 0) {
            setTableData(purchaseInvoices);
            setSelectedRowKeys(purchaseInvoices.map((i: any) => i.id));
        } else {
            setTableData([]);
            setSelectedRowKeys([]);
        }
    }, [open, purchaseInvoices, selectedSupplierId, invoicesLoaded]);

    const handleQuickAllocate = (total: number) => {
        let rem = Number(total) || 0;
        const updated = tableData.map(item => {
            if (rem <= 0 || item.remaining_amount === null) {
                return { ...item, pay_amount: item.remaining_amount === null ? null : 0 };
            }
            const alloc = Math.min(rem, item.remaining_amount || 0);
            rem -= alloc;
            return { ...item, pay_amount: alloc };
        });
        setTableData(updated);
        setSelectedRowKeys(updated.filter(u => (u.pay_amount || 0) > 0).map(u => u.id));
    };

    const handleAmountChange = (id: number | string, val: number) => {
        const safeVal = Number(val);
        const normalizedValue = Number.isFinite(safeVal) ? safeVal : 0;
        const updated = tableData.map(item => item.id === id ? { ...item, pay_amount: normalizedValue } : item);
        setTableData(updated);
        if (safeVal > 0) {
            setSelectedRowKeys(prev => prev.includes(id) ? prev : [...prev, id]);
        }
    };

    const selectedInvoices = useMemo(() => {
        const keySet = new Set((selectedRowKeys || []).map(String));
        return (tableData || []).filter(item => keySet.has(String(item.id)) && item.pay_amount !== null && Number.isFinite(item.pay_amount) && item.pay_amount > 0 && item.remaining_amount !== null);
    }, [tableData, selectedRowKeys]);

    const totalPay = useMemo(() => {
        return (Array.isArray(selectedInvoices) ? selectedInvoices : []).reduce(
            (sum, item) => sum + (Number(item?.pay_amount) || 0), 
            0
        );
    }, [selectedInvoices]);

    const handleConfirm = () => {
        if (!selectedSupplierId) {
            message.warning('Vui lòng chọn nhà cung cấp cần trả tiền!');
            return;
        }
        if (!Array.isArray(selectedInvoices) || selectedInvoices.length === 0 || totalPay <= 0) {
            message.warning('Vui lòng chọn ít nhất một hóa đơn có số trả > 0!');
            return;
        }

        const supplier = (suppliers as any[]).find((s: any) => s?.id === selectedSupplierId || s?.code === selectedSupplierId);

        const payload = {
            supplier_id: supplier?.id || selectedSupplierId,
            supplier_name: supplier?.name || '',
            supplier_address: supplier?.address || '',
            total_pay: totalPay,
            invoices: selectedInvoices
        };

        if (onSuccess) {
            onSuccess(payload);
        } else {
            const reasonText = `Trả tiền theo hóa đơn NCC: ${selectedInvoices.map(i => i.voucher_number || '').join(', ')}`;
            window.dispatchEvent(new CustomEvent('open-cash-payment', {
                detail: {
                    presetType: '1. Trả tiền cho nhà cung cấp (không theo hóa đơn)',
                    prefillData: {
                        contact_id: supplier?.code || supplier?.id,
                        contact_name: supplier?.name,
                        receiver_name: supplier?.contact_person || supplier?.name,
                        receiver_address: supplier?.address,
                        reason: reasonText,
                        referenced_vouchers: selectedInvoices.map(i => ({
                            id: i.id,
                            voucher_number: i.voucher_number,
                            voucher_type: 'Hóa đơn mua hàng',
                            total_amount: Number(i.pay_amount) || 0
                        })),
                        lines: selectedInvoices.map((inv, idx) => ({
                            key: String(idx + 1),
                            description: `Trả tiền ${inv.voucher_number || ''} - ${inv.description || ''}`,
                            amount: Number(inv.pay_amount) || 0,
                            operation: 'Trả tiền nhà cung cấp',
                            line_contact_id: supplier?.code || '',
                            line_contact_name: supplier?.name || ''
                        }))
                    }
                }
            }));
        }

        onClose();
    };

    const columns: ColumnsType<PaySupplierInvoiceItem> = [
        {
            title: 'Số hóa đơn',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 120,
            render: (text) => <span className="misa-fw-700 misa-color-blue">{text}</span>
        },
        {
            title: 'Ngày HĐ',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 100,
            render: (d) => formatDate(d)
        },
        {
            title: 'Hạn TT',
            dataIndex: 'due_date',
            key: 'due_date',
            width: 100,
            render: (d) => formatDate(d)
        },
        {
            title: 'Diễn giải',
            dataIndex: 'description',
            key: 'description'
        },
        {
            title: 'Tổng tiền HĐ',
            dataIndex: 'total_amount',
            key: 'total_amount',
            align: 'right',
            width: 130,
            render: (val) => formatMoney(val)
        },
        {
            title: 'Đã trả',
            dataIndex: 'paid_amount',
            key: 'paid_amount',
            align: 'right',
            width: 110,
            render: (val) => `${new Intl.NumberFormat('vi-VN').format(Number(val) || 0)} ₫`
        },
        {
            title: 'Còn phải trả',
            dataIndex: 'remaining_amount',
            key: 'remaining_amount',
            align: 'right',
            width: 130,
            render: (val) => <span className="misa-fw-700 misa-color-red">{formatMoney(val)}</span>
        },
        {
            title: 'Số trả lần này',
            dataIndex: 'pay_amount',
            key: 'pay_amount',
            align: 'right',
            width: 160,
            render: (val, record) => (
                <InputNumber
                    className="misa-w-full text-right font-semibold"
                    min={0}
                    max={record.remaining_amount ?? undefined}
                    value={val ?? undefined}
                    formatter={v => (v !== undefined && v !== null && v !== '') ? `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : ''}
                    parser={(v) => (v ? Number(String(v).replace(/\$\s?|(,*)/g, '')) : 0) as any}
                    onChange={(n) => handleAmountChange(record.id, Number(n) || 0)}
                />
            )
        }
    ];

    return (
        <Modal
            title={
                <div className="misa-flex-between misa-pr-24">
                    <span className="misa-modal-title">Trả tiền nhà cung cấp theo hóa đơn</span>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={1100}
            footer={
                <div className="misa-flex-between">
                    <div>
                        <span className="apple-muted-text">Tổng số tiền trả: </span>
                        <strong className="misa-fs-16 misa-color-red">
                            {formatMoney(totalPay)}
                        </strong>
                    </div>
                    <div className="misa-flex misa-gap-8">
                        <Button onClick={onClose}>Hủy bỏ</Button>
                        <Button type="primary" danger className="misa-btn-danger" onClick={handleConfirm}>
                            <DollarOutlined /> Trả tiền
                        </Button>
                    </div>
                </div>
            }
            centered
            className="misa-custom-modal"
        >
            {(suppliersLoadError || invoicesLoadError) && (
                <Alert
                    type="error"
                    showIcon
                    message="Không thể tải dữ liệu trả tiền theo hóa đơn"
                    description="Danh sách nhà cung cấp hoặc hóa đơn chưa tải được. Dữ liệu cũ được giữ lại; hãy thử lại."
                    action={<Button size="small" onClick={retryLookups}>Thử lại</Button>}
                    className="misa-mb-12"
                />
            )}
            <div className="apple-section-gap misa-bg-light misa-p-12 misa-rounded-6">
                <div className="misa-form-grid">
                    <div className="misa-col-6">
                        <div className="misa-field-label required">Nhà cung cấp</div>
                        <Select
                            showSearch
                            placeholder="Chọn nhà cung cấp cần thanh toán..."
                            className="misa-w-full"
                            value={selectedSupplierId}
                            onChange={(val) => setSelectedSupplierId(val)}
                            filterOption={(input, option: any) =>
                                (option?.label || '').toLowerCase().includes(input.toLowerCase())
                            }
                            options={(suppliers || []).map((s: any) => ({
                                value: s.id,
                                label: `${s.code} - ${s.name}`
                            }))}
                            getPopupContainer={() => document.body}
                        />
                    </div>
                    <div className="misa-col-3">
                        <div className="misa-field-label">Đến ngày</div>
                        <DatePicker className="misa-w-full" defaultValue={dayjs()} format="DD/MM/YYYY" />
                    </div>
                    <div className="misa-col-3">
                        <div className="misa-field-label">Nhập nhanh số trả</div>
                        <InputNumber
                            placeholder="Nhập số tiền..."
                            className="misa-w-full"
                            formatter={v => (v !== undefined && v !== null && v !== '') ? `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : ''}
                            parser={(v) => (v ? Number(String(v).replace(/\$\s?|(,*)/g, '')) : 0) as any}
                            onChange={(val) => handleQuickAllocate(Number(val) || 0)}
                        />
                    </div>
                </div>
            </div>

            <Table
                columns={columns}
                dataSource={tableData}
                rowKey="id"
                loading={isLoading}
                size="small"
                pagination={false}
                bordered
                rowSelection={{
                    selectedRowKeys,
                    onChange: (keys) => setSelectedRowKeys(keys)
                }}
            />
        </Modal>
    );
};
