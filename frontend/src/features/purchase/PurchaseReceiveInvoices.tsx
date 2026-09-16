import React, { useMemo, useState } from 'react';
import { Table, Input, Select, InputNumber, Button, Tag } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { PlusOutlined, ReloadOutlined, ExportOutlined, SearchOutlined } from '@ant-design/icons';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

interface ReceiveInvoiceRecord {
    id: number;
    supplier_id: number;
    invoice_template: string | null;
    invoice_series: string;
    invoice_number: string;
    invoice_date: string;
    supplier_name: string | null;
    supplier_tax_code: string | null;
    subtotal: string;
    vat_rate: string;
    vat_amount: string;
    total_amount: string;
    status: 'mapped' | 'unmapped';
    voucher_ref: string | null;
    description?: string | null;
}

interface SupplierOption {
    id: number;
    name: string;
    tax_code?: string | null;
}

interface PurchaseVoucherOption {
    id: number;
    supplier_id: number;
    invoice_number?: string | null;
    voucher_number?: string | null;
    invoice_date?: string | null;
    total_amount?: string | number | null;
}

interface PurchaseSourceOption {
    id: number;
    supplier_id?: number | null;
    order_number?: string | null;
    contract_number?: string | null;
    voucher_number?: string | null;
    invoice_number?: string | null;
    invoice_date?: string | null;
    order_date?: string | null;
    signed_date?: string | null;
    voucher_date?: string | null;
}

type ReferenceType = 'purchase_invoice' | 'purchase_order' | 'purchase_contract' | 'inventory_receipt';

const referenceField: Record<ReferenceType, string> = {
    purchase_invoice: 'purchase_invoice_id',
    purchase_order: 'purchase_order_id',
    purchase_contract: 'purchase_contract_id',
    inventory_receipt: 'inventory_receipt_id',
};

function parseCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    const envelope = payload && typeof payload === 'object' ? payload as { data?: unknown } : null;
    if (Array.isArray(envelope?.data)) return envelope.data as T[];
    const nested = envelope?.data && typeof envelope.data === 'object' ? envelope.data as { data?: unknown } : null;
    if (Array.isArray(nested?.data)) return nested.data as T[];
    throw new Error(`Invalid ${resource} response.`);
}

function formatMoney(value: string | number): string {
    const amount = Number(value);
    return `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 2 }).format(Number.isFinite(amount) ? amount : 0)} ₫`;
}

const today = () => new Date().toISOString().slice(0, 10);

export const PurchaseReceiveInvoices: React.FC = () => {
    const queryClient = useQueryClient();
    const [searchText, setSearchText] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [invoiceNo, setInvoiceNo] = useState('');
    const [invoiceSeries, setInvoiceSeries] = useState('');
    const [invoiceTemplate, setInvoiceTemplate] = useState('');
    const [invoiceDate, setInvoiceDate] = useState(today());
    const [subtotal, setSubtotal] = useState<number | null>(null);
    const [vatRate, setVatRate] = useState(0);
    const [supplierId, setSupplierId] = useState<number | null>(null);
    const [description, setDescription] = useState('');
    const [linkTarget, setLinkTarget] = useState<ReceiveInvoiceRecord | null>(null);
    const [referenceType, setReferenceType] = useState<ReferenceType>('purchase_invoice');
    const [referenceId, setReferenceId] = useState<number | null>(null);

    const invoicesQuery = useQuery({
        queryKey: ['purchase-received-invoices'],
        queryFn: async () => parseCollection<ReceiveInvoiceRecord>(
            (await api.get('/purchase/received-invoices')).data,
            'received purchase invoice list',
        ),
    });

    const suppliersQuery = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => parseCollection<SupplierOption>(
            (await api.get('/master/suppliers')).data,
            'supplier catalogue',
        ),
    });

    const purchaseVouchersQuery = useQuery({
        queryKey: ['purchase-invoices-for-received-link', linkTarget?.supplier_id],
        enabled: linkTarget !== null,
        queryFn: async () => parseCollection<PurchaseVoucherOption>(
            (await api.get('/purchase/invoices')).data,
            'purchase voucher catalogue',
        ),
    });

    const purchaseOrdersQuery = useQuery({
        queryKey: ['purchase-orders-for-received-link', linkTarget?.supplier_id],
        enabled: linkTarget !== null,
        queryFn: async () => parseCollection<PurchaseSourceOption>((await api.get('/purchase/orders')).data, 'purchase order catalogue'),
    });

    const purchaseContractsQuery = useQuery({
        queryKey: ['purchase-contracts-for-received-link', linkTarget?.supplier_id],
        enabled: linkTarget !== null,
        queryFn: async () => parseCollection<PurchaseSourceOption>((await api.get('/purchase/contracts')).data, 'purchase contract catalogue'),
    });

    const inventoryReceiptsQuery = useQuery({
        queryKey: ['inventory-receipts-for-received-link'],
        enabled: linkTarget !== null,
        queryFn: async () => parseCollection<PurchaseSourceOption>((await api.get('/inventory/receipts')).data, 'inventory receipt catalogue'),
    });

    const saveMutation = useMutation({
        mutationFn: async () => {
            if (!supplierId || !invoiceSeries.trim() || !invoiceNo.trim() || !invoiceDate || subtotal == null) {
                throw new Error('Vui lòng nhập đủ nhà cung cấp, ký hiệu, số hóa đơn, ngày và tiền chưa thuế.');
            }
            const response = await api.post('/purchase/received-invoices', {
                supplier_id: supplierId,
                invoice_template: invoiceTemplate.trim() || null,
                invoice_series: invoiceSeries.trim(),
                invoice_number: invoiceNo.trim(),
                invoice_date: invoiceDate,
                subtotal: subtotal.toFixed(2),
                vat_rate: vatRate.toFixed(2),
                description: description.trim() || null,
            });
            return response.data;
        },
        onSuccess: () => {
            message.success('Đã nhận hóa đơn mua vào.');
            queryClient.invalidateQueries({ queryKey: ['purchase-received-invoices'] });
            setIsModalOpen(false);
            setInvoiceNo('');
            setInvoiceSeries('');
            setInvoiceTemplate('');
            setInvoiceDate(today());
            setSubtotal(null);
            setVatRate(0);
            setSupplierId(null);
            setDescription('');
        },
        onError: (error: any) => message.error(error?.response?.data?.message || error?.message || 'Không thể lưu hóa đơn mua vào.'),
    });

    const linkMutation = useMutation({
        mutationFn: async () => {
            if (!linkTarget || !referenceId) throw new Error('Hãy chọn nguồn tham chiếu để ghép.');
            const response = await api.post(`/purchase/received-invoices/${linkTarget.id}/link`, { [referenceField[referenceType]]: referenceId });
            return response.data;
        },
        onSuccess: () => {
            message.success('Đã ghép hóa đơn với chứng từ mua.');
            queryClient.invalidateQueries({ queryKey: ['purchase-received-invoices'] });
            setLinkTarget(null);
            setReferenceType('purchase_invoice');
            setReferenceId(null);
        },
        onError: (error: any) => message.error(error?.response?.data?.message || error?.message || 'Không thể ghép hóa đơn với chứng từ mua.'),
    });

    const invoices = useMemo(() => {
        const rows = invoicesQuery.data ?? [];
        const query = searchText.trim().toLowerCase();
        if (!query) return rows;
        return rows.filter((row) => [row.invoice_series, row.invoice_number, row.supplier_name, row.supplier_tax_code]
            .some((value) => String(value ?? '').toLowerCase().includes(query)));
    }, [invoicesQuery.data, searchText]);

    const totalSum = invoices.reduce((acc, invoice) => acc + (Number(invoice.total_amount) || 0), 0);

    const handleExport = () => {
        const header = ['Ký hiệu HĐ', 'Số hóa đơn', 'Ngày hóa đơn', 'Nhà cung cấp', 'Tiền chưa thuế', 'Tiền thuế', 'Tổng tiền', 'Trạng thái'];
        const rows = invoices.map((invoice) => [invoice.invoice_series, invoice.invoice_number, invoice.invoice_date, invoice.supplier_name ?? '', invoice.subtotal, invoice.vat_amount, invoice.total_amount, invoice.status === 'mapped' ? `Đã khớp: ${invoice.voucher_ref ?? ''}` : 'Chưa ghép chứng từ']);
        const csv = [header, ...rows].map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(',')).join('\n');
        const url = URL.createObjectURL(new Blob([`\ufeff${csv}`], { type: 'text/csv;charset=utf-8;' }));
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'hoa-don-mua-vao.csv';
        anchor.click();
        URL.revokeObjectURL(url);
    };

    const columns = [
        { title: 'Ký hiệu HĐ', dataIndex: 'invoice_series', key: 'invoice_series', width: 110 },
        { title: 'Số hóa đơn', dataIndex: 'invoice_number', key: 'invoice_number', width: 120, render: (value: string) => <span className="misa-table-link-bold">{value}</span> },
        { title: 'Ngày hóa đơn', dataIndex: 'invoice_date', key: 'invoice_date', width: 110, align: 'center' as const },
        { title: 'Nhà cung cấp', dataIndex: 'supplier_name', key: 'supplier_name', width: 250, render: (value: string | null, record: ReceiveInvoiceRecord) => <div className="misa-cell-title-box"><div className="misa-cell-main-title">{value || '—'}</div><div className="misa-cell-sub-title">MST: {record.supplier_tax_code || '—'}</div></div> },
        { title: 'Tiền chưa thuế', dataIndex: 'subtotal', key: 'subtotal', width: 130, align: 'right' as const, render: formatMoney },
        { title: 'Tiền thuế GTGT', dataIndex: 'vat_amount', key: 'vat_amount', width: 120, align: 'right' as const, render: formatMoney },
        { title: 'Tổng tiền thanh toán', dataIndex: 'total_amount', key: 'total_amount', width: 140, align: 'right' as const, render: (value: string) => <span className="misa-table-amount-dark">{formatMoney(value)}</span> },
        { title: 'Khớp chứng từ mua', dataIndex: 'status', key: 'status', width: 220, align: 'center' as const, render: (status: ReceiveInvoiceRecord['status'], record: ReceiveInvoiceRecord) => status === 'mapped' ? <Tag color="green">Đã khớp: {record.voucher_ref}</Tag> : <span className="misa-flex-center misa-gap-8"><Tag>Chưa ghép chứng từ</Tag><Button type="link" size="small" onClick={() => { setLinkTarget(record); setReferenceType('purchase_invoice'); setReferenceId(null); }}>Ghép chứng từ</Button></span> },
    ];

    const calculatedTotal = subtotal == null ? null : subtotal * (1 + vatRate / 100);

    return (
        <PageShell
            className="apple-ledger-page"
            title={<PageHeader eyebrow="MUA HÀNG" title="Hóa đơn mua vào" description="Tiếp nhận, tra cứu và ghép hóa đơn đầu vào với chứng từ mua hàng." />}
            toolbar={<PageToolbar filters={<div className="ui-page-toolbar__filter-group flex items-center gap-2"><Input placeholder="Tìm kiếm số hóa đơn, nhà cung cấp..." className="misa-w-280" allowClear prefix={<SearchOutlined className="misa-color-muted" />} value={searchText} onChange={(event) => setSearchText(event.target.value)} /></div>} actions={<div className="ui-page-toolbar__action-group flex items-center gap-2"><Button icon={<ReloadOutlined />} className="misa-btn-tool" title="Tải lại" onClick={() => void runManualDataLoad(() => invoicesQuery.refetch(), { success: 'Tải lại danh sách nhận hóa đơn thành công.', failure: 'Không thể tải lại danh sách nhận hóa đơn.' })} loading={invoicesQuery.isFetching} /><Button icon={<ExportOutlined />} className="misa-btn-tool" title="Xuất file CSV" onClick={handleExport} /><Button type="primary" className="misa-btn-primary" icon={<PlusOutlined />} onClick={() => setIsModalOpen(true)}>Nhận hóa đơn</Button></div>} />}
        >
            <DataTableSurface className="purchase-receive-invoices-table-surface">
                <Table
                    className="misa-voucher-table"
                    columns={columns}
                    dataSource={invoices}
                    rowKey="id"
                    pagination={false}
                    size="small"
                    loading={invoicesQuery.isLoading}
                    locale={{ emptyText: invoicesQuery.isError ? 'Không thể tải dữ liệu hóa đơn mua vào.' : 'Chưa có hóa đơn mua vào.' }}
                    summary={() => <Table.Summary fixed><Table.Summary.Row className="misa-table-summary-row"><Table.Summary.Cell index={0} colSpan={6}><span>Tổng ({invoices.length} hóa đơn)</span></Table.Summary.Cell><Table.Summary.Cell index={1} align="right"><span className="misa-table-summary-total">{formatMoney(totalSum)}</span></Table.Summary.Cell><Table.Summary.Cell index={2} /></Table.Summary.Row></Table.Summary>}
                />
            </DataTableSurface>

            <Modal title="Nhận hóa đơn mua vào" open={isModalOpen} onCancel={() => setIsModalOpen(false)} onOk={() => saveMutation.mutate()} confirmLoading={saveMutation.isPending} width={700}>
                <div className="misa-flex-col misa-gap-12 misa-top-8">
                    <div className="misa-form-grid">
                        <div className="misa-col-4"><div className="misa-field-label">Mẫu số:</div><Input aria-label="Mẫu số hóa đơn" className="misa-input" value={invoiceTemplate} onChange={(event) => setInvoiceTemplate(event.target.value)} placeholder="Ví dụ: 1C26TAA" /></div>
                        <div className="misa-col-4"><div className="misa-field-label required">Ký hiệu hóa đơn:</div><Input aria-label="Ký hiệu hóa đơn" className="misa-input" value={invoiceSeries} onChange={(event) => setInvoiceSeries(event.target.value)} /></div>
                        <div className="misa-col-4"><div className="misa-field-label required">Số hóa đơn:</div><Input aria-label="Số hóa đơn" className="misa-input" value={invoiceNo} onChange={(event) => setInvoiceNo(event.target.value)} /></div>
                    </div>
                    <div className="misa-form-grid">
                        <div className="misa-col-6"><div className="misa-field-label required">Nhà cung cấp:</div><Select aria-label="Nhà cung cấp" className="misa-w-full" showSearch optionFilterProp="label" placeholder="Chọn nhà cung cấp" value={supplierId ?? undefined} onChange={setSupplierId} loading={suppliersQuery.isLoading} options={(suppliersQuery.data ?? []).map((supplier) => ({ value: supplier.id, label: `${supplier.name}${supplier.tax_code ? ` — MST: ${supplier.tax_code}` : ''}` }))} /></div>
                        <div className="misa-col-6"><div className="misa-field-label required">Ngày hóa đơn:</div><Input aria-label="Ngày hóa đơn" type="date" className="misa-input" value={invoiceDate} onChange={(event) => setInvoiceDate(event.target.value)} /></div>
                    </div>
                    <div className="misa-form-grid">
                        <div className="misa-col-4"><div className="misa-field-label required">Tiền chưa thuế:</div><InputNumber aria-label="Tiền chưa thuế" className="misa-input misa-w-full" min={0} value={subtotal} onChange={(value) => setSubtotal(value == null ? null : Number(value))} /></div>
                        <div className="misa-col-4"><div className="misa-field-label">Thuế suất %:</div><Select aria-label="Thuế suất" className="misa-w-full" value={vatRate} onChange={setVatRate} options={[{ value: 0, label: '0%' }, { value: 5, label: '5%' }, { value: 8, label: '8%' }, { value: 10, label: '10%' }]} /></div>
                        <div className="misa-col-4"><div className="misa-field-label">Tổng tiền:</div><div className="misa-table-summary-primary misa-mt-12">{calculatedTotal == null ? '—' : formatMoney(calculatedTotal)}</div></div>
                    </div>
                    <div><div className="misa-field-label">Diễn giải:</div><Input.TextArea aria-label="Diễn giải" rows={3} value={description} onChange={(event) => setDescription(event.target.value)} placeholder="Ghi chú đối chiếu hoặc tham chiếu hóa đơn" /></div>
                </div>
            </Modal>

            <Modal
                title={`Ghép hóa đơn ${linkTarget?.invoice_number ?? ''} với chứng từ mua`}
                open={linkTarget !== null}
                onCancel={() => { if (!linkMutation.isPending) { setLinkTarget(null); setReferenceType('purchase_invoice'); setReferenceId(null); } }}
                onOk={() => linkMutation.mutate()}
                confirmLoading={linkMutation.isPending}
                okText="Ghép chứng từ"
                cancelText="Hủy"
                width={620}
            >
                <div className="misa-field-label required">Nguồn tham chiếu:</div>
                <Select
                    aria-label="Loại nguồn tham chiếu"
                    className="misa-w-full misa-mb-12"
                    value={referenceType}
                    onChange={(value: ReferenceType) => { setReferenceType(value); setReferenceId(null); }}
                    options={[
                        { value: 'purchase_invoice', label: 'Chứng từ mua' },
                        { value: 'purchase_order', label: 'Đơn mua hàng' },
                        { value: 'purchase_contract', label: 'Hợp đồng mua' },
                        { value: 'inventory_receipt', label: 'Phiếu nhập kho' },
                    ]}
                />
                <Select
                    aria-label="Nguồn tham chiếu để ghép"
                    className="misa-w-full"
                    showSearch
                    optionFilterProp="label"
                    placeholder="Chọn chứng từ tham chiếu"
                    value={referenceId ?? undefined}
                    onChange={setReferenceId}
                    loading={purchaseVouchersQuery.isLoading || purchaseOrdersQuery.isLoading || purchaseContractsQuery.isLoading || inventoryReceiptsQuery.isLoading}
                    options={(
                        referenceType === 'purchase_invoice' ? (purchaseVouchersQuery.data ?? [])
                            .filter((voucher) => voucher.supplier_id === linkTarget?.supplier_id)
                            .map((voucher) => ({ value: voucher.id, label: `${voucher.voucher_number || voucher.invoice_number || `Chứng từ #${voucher.id}`} — ${voucher.invoice_date || 'chưa có ngày'}` }))
                            : referenceType === 'purchase_order' ? (purchaseOrdersQuery.data ?? [])
                                .filter((order) => order.supplier_id === linkTarget?.supplier_id)
                                .map((order) => ({ value: order.id, label: `${order.order_number || `Đơn mua #${order.id}`} — ${order.order_date || 'chưa có ngày'}` }))
                                : referenceType === 'purchase_contract' ? (purchaseContractsQuery.data ?? [])
                                    .filter((contract) => contract.supplier_id === linkTarget?.supplier_id)
                                    .map((contract) => ({ value: contract.id, label: `${contract.contract_number || `Hợp đồng #${contract.id}`} — ${contract.signed_date || 'chưa có ngày'}` }))
                                    : (inventoryReceiptsQuery.data ?? []).map((receipt) => ({ value: receipt.id, label: `${receipt.voucher_number || `Phiếu nhập #${receipt.id}`} — ${receipt.voucher_date || 'chưa có ngày'}` }))
                    )}
                />
                {(purchaseVouchersQuery.isError || purchaseOrdersQuery.isError || purchaseContractsQuery.isError || inventoryReceiptsQuery.isError) && <div className="misa-mt-8 text-red-600 text-sm">Không tải được danh sách nguồn tham chiếu.</div>}
            </Modal>
        </PageShell>
    );
};

export default PurchaseReceiveInvoices;
