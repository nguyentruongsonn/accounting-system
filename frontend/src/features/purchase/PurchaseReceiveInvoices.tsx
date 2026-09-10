import React, { useState } from 'react';
import { Table, Input, Select, InputNumber, Button } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { 
    PlusOutlined, 
    ReloadOutlined, 
    ExportOutlined, 
    SearchOutlined
} from '@ant-design/icons';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

interface ReceiveInvoiceRecord {
    id: number;
    invoice_template: string;
    invoice_series: string;
    invoice_number: string;
    invoice_date: string;
    supplier_name: string;
    supplier_tax_code: string;
    subtotal: number;
    vat_amount: number;
    total_amount: number;
    status: 'mapped' | 'unmapped';
    voucher_ref: string;
}

export const PurchaseReceiveInvoices: React.FC = () => {
    const [searchText, setSearchText] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [invoiceNo, setInvoiceNo] = useState('');
    const [invoiceSeries, setInvoiceSeries] = useState('');
    const [subtotal, setSubtotal] = useState<number | null>(null);
    const [vatRate, setVatRate] = useState<number | null>(null);
    const [supplierName, setSupplierName] = useState('');

    const [invoices] = useState<ReceiveInvoiceRecord[]>([]);

    const handleSave = () => {
        message.info('Backend chưa công bố workflow tiếp nhận hóa đơn mua vào; không tạo dữ liệu cục bộ.');
    };

    const columns = [
        { title: 'Ký hiệu HĐ', dataIndex: 'invoice_series', key: 'invoice_series', width: 110 },
        { title: 'Số hóa đơn', dataIndex: 'invoice_number', key: 'invoice_number', width: 120, render: (t: string) => <span className="misa-table-link-bold">{t}</span> },
        { title: 'Ngày hóa đơn', dataIndex: 'invoice_date', key: 'invoice_date', width: 110, align: 'center' as const },
        { 
            title: 'Nhà cung cấp', 
            dataIndex: 'supplier_name', 
            key: 'supplier_name', 
            width: 250,
            render: (t: string, r: ReceiveInvoiceRecord) => (
                <div className="misa-cell-title-box">
                    <div className="misa-cell-main-title">{t}</div>
                    <div className="misa-cell-sub-title">MST: {r.supplier_tax_code}</div>
                </div>
            )
        },
        { 
            title: 'Tiền chưa thuế', 
            dataIndex: 'subtotal', 
            key: 'subtotal', 
            width: 130, 
            align: 'right' as const,
            render: (v: number) => <span>{new Intl.NumberFormat('vi-VN').format(v)} ₫</span>
        },
        { 
            title: 'Tiền thuế GTGT', 
            dataIndex: 'vat_amount', 
            key: 'vat_amount', 
            width: 120, 
            align: 'right' as const,
            render: (v: number) => <span>{new Intl.NumberFormat('vi-VN').format(v)} ₫</span>
        },
        { 
            title: 'Tổng tiền thanh toán', 
            dataIndex: 'total_amount', 
            key: 'total_amount', 
            width: 140, 
            align: 'right' as const,
            render: (v: number) => <span className="misa-table-amount-dark">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span>
        },
        { 
            title: 'Khớp chứng từ mua', 
            dataIndex: 'status', 
            key: 'status', 
            width: 150, 
            align: 'center' as const,
            render: (s: string, r: ReceiveInvoiceRecord) => s === 'mapped' ? <span>Đã khớp: {r.voucher_ref}</span> : <span>Chưa ghép chứng từ</span>
        },
    ];

    return (
        <PageShell
            className="apple-ledger-page"
            title={<PageHeader
                eyebrow="MUA HÀNG"
                title="Hóa đơn mua vào"
                description="Quản lý và đối chiếu hóa đơn đầu vào từ nhà cung cấp."
            />}
            toolbar={(
                <PageToolbar
                    filters={(
                        <div className="ui-page-toolbar__filter-group flex items-center gap-2">
                            <Input 
                                placeholder="Tìm kiếm số hóa đơn, nhà cung cấp..." 
                                className="misa-w-280"
                                allowClear
                                prefix={<SearchOutlined className="misa-color-muted" />}
                                value={searchText}
                                onChange={e => setSearchText(e.target.value)}
                            />
                        </div>
                    )}
                    actions={(
                        <div className="ui-page-toolbar__action-group flex items-center gap-2">
                            <Button
                                icon={<ReloadOutlined />}
                                className="misa-btn-tool"
                                disabled
                                title="Tải lại"
                            />
                            <Button
                                icon={<ExportOutlined />}
                                className="misa-btn-tool"
                                disabled
                                title="Xuất file"
                            />
                            <Button 
                                type="primary"
                                className="misa-btn-primary"
                                icon={<PlusOutlined />}
                                onClick={() => setIsModalOpen(true)}
                                disabled
                            >
                                Nhận hóa đơn
                            </Button>
                        </div>
                    )}
                />
            )}
        >
            <DataTableSurface className="purchase-receive-invoices-table-surface">
                <Table 
                    className="misa-voucher-table"
                    columns={columns}
                    dataSource={invoices}
                    rowKey="id"
                    pagination={false}
                    size="small"
                    locale={{ emptyText: 'Backend chưa công bố dữ liệu hóa đơn mua vào.' }}
                    summary={() => {
                        const totalSum = invoices.reduce((acc, i) => acc + i.total_amount, 0);
                        return (
                            <Table.Summary fixed>
                                <Table.Summary.Row className="misa-table-summary-row">
                                    <Table.Summary.Cell index={0} colSpan={6}>
                                        <span>Tổng ({invoices.length} hóa đơn)</span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={1} align="right">
                                        <span className="misa-table-summary-total">
                                            {new Intl.NumberFormat('vi-VN').format(totalSum)} ₫
                                        </span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={2} colSpan={1}></Table.Summary.Cell>
                                </Table.Summary.Row>
                            </Table.Summary>
                        );
                    }}
                />
            </DataTableSurface>

            <Modal
                title={`Nhận hóa đơn GTGT: ${invoiceNo}`}
                open={isModalOpen}
                onCancel={() => setIsModalOpen(false)}
                onOk={handleSave}
                width={650}
            >
                <div className="misa-flex-col misa-gap-12 misa-top-8">
                    <div className="misa-form-grid">
                        <div className="misa-col-6">
                            <div className="misa-field-label required">Ký hiệu hóa đơn:</div>
                            <Input className="misa-input" value={invoiceSeries} onChange={e => setInvoiceSeries(e.target.value)} />
                        </div>
                        <div className="misa-col-6">
                            <div className="misa-field-label required">Số hóa đơn:</div>
                            <Input className="misa-input" value={invoiceNo} onChange={e => setInvoiceNo(e.target.value)} />
                        </div>
                    </div>
                    <div>
                        <div className="misa-field-label required">Nhà cung cấp:</div>
                        <Input className="misa-input" value={supplierName} onChange={e => setSupplierName(e.target.value)} />
                    </div>
                    <div className="misa-form-grid">
                        <div className="misa-col-4">
                            <div className="misa-field-label required">Tiền chưa thuế:</div>
                            <InputNumber 
                                className="misa-input misa-w-full" 
                                value={subtotal}
                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                onChange={v => setSubtotal(v == null ? null : Number(v))}
                            />
                        </div>
                        <div className="misa-col-4">
                            <div className="misa-field-label">Thuế suất %:</div>
                            <Select className="misa-w-full" value={vatRate} onChange={setVatRate} options={[{ value: 0, label: '0%' }, { value: 5, label: '5%' }, { value: 8, label: '8%' }, { value: 10, label: '10%' }]} />
                        </div>
                        <div className="misa-col-4">
                            <div className="misa-field-label">Tổng tiền:</div>
                            <div className="misa-table-summary-primary misa-mt-12">
                                {subtotal == null || vatRate == null
                                    ? '—'
                                    : `${new Intl.NumberFormat('vi-VN').format(subtotal * (1 + vatRate / 100))} ₫`}
                            </div>
                        </div>
                    </div>
                </div>
            </Modal>
        </PageShell>
    );
};

export default PurchaseReceiveInvoices;
