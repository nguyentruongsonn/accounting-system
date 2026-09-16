import React, { useState, useMemo } from 'react';
import { Table, Input, Select, Button, Space } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { SearchOutlined, ThunderboltOutlined, CheckCircleOutlined, SyncOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';

interface ImportFromMeInvoiceModalProps {
    open: boolean;
    onCancel: () => void;
    onSelectInvoiceToCreate?: (invoice: any) => void;
}

interface EInvoiceRecord {
    id: number;
    invoice_symbol: string;
    invoice_number: string;
    invoice_date: string;
    seller_tax_code: string;
    seller_name: string;
    subtotal: number;
    tax_amount: number;
    total_amount: number;
    status: 'valid' | 'synced' | 'created';
    voucher_ref?: string;
    items?: any[];
}

export const ImportFromMeInvoiceModal: React.FC<ImportFromMeInvoiceModalProps> = ({
    open,
    onCancel
}) => {
    const [period, setPeriod] = useState('Tháng này');
    const [statusFilter, setStatusFilter] = useState('all');
    const [filterKeyword, setFilterKeyword] = useState('');
    const [selectedRowKeys, setSelectedRowKeys] = useState<React.Key[]>([]);
    const [isSyncing] = useState(false);

    const [eInvoices] = useState<EInvoiceRecord[]>([]);

    const handleSyncFromTax = () => {
        message.info('Backend chưa công bố tích hợp meInvoice/Tổng cục Thuế; không đồng bộ dữ liệu cục bộ.');
    };

    const filteredInvoices = useMemo(() => {
        return eInvoices.filter(inv => {
            if (statusFilter !== 'all' && inv.status !== statusFilter) return false;
            if (filterKeyword) {
                const kw = filterKeyword.toLowerCase();
                return (
                    inv.invoice_number.toLowerCase().includes(kw) ||
                    inv.seller_name.toLowerCase().includes(kw) ||
                    inv.seller_tax_code.toLowerCase().includes(kw)
                );
            }
            return true;
        });
    }, [eInvoices, statusFilter, filterKeyword]);

    const handleCreateVoucher = () => {
        message.info('Backend chưa công bố API tải hóa đơn điện tử vào chứng từ mua hàng.');
    };

    const columns = [
        {
            title: 'Ký hiệu HĐ',
            dataIndex: 'invoice_symbol',
            key: 'invoice_symbol',
            width: 105,
            render: (val: string) => <span className="misa-text-semibold">{val}</span>
        },
        {
            title: 'Số hóa đơn',
            dataIndex: 'invoice_number',
            key: 'invoice_number',
            width: 110,
            render: (val: string) => <span className="misa-text-blue-bold">{val}</span>
        },
        {
            title: 'Ngày HĐ',
            dataIndex: 'invoice_date',
            key: 'invoice_date',
            width: 100,
            align: 'center' as const,
            render: (d: string) => dayjs(d).format('DD/MM/YYYY')
        },
        {
            title: 'Mã số thuế',
            dataIndex: 'seller_tax_code',
            key: 'seller_tax_code',
            width: 120
        },
        {
            title: 'Người bán (Nhà cung cấp)',
            dataIndex: 'seller_name',
            key: 'seller_name',
            minWidth: 200,
            render: (name: string) => <span className="misa-text-dark-bold">{name}</span>
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
            title: 'Tiền thuế',
            dataIndex: 'tax_amount',
            key: 'tax_amount',
            width: 120,
            align: 'right' as const,
            render: (v: number) => <span>{new Intl.NumberFormat('vi-VN').format(v)} ₫</span>
        },
        {
            title: 'Tổng thanh toán',
            dataIndex: 'total_amount',
            key: 'total_amount',
            width: 140,
            align: 'right' as const,
            render: (v: number) => <span className="misa-text-dark-bold">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span>
        },
        {
            title: 'Trạng thái',
            dataIndex: 'status',
            key: 'status',
            width: 110,
            align: 'center' as const,
            render: () => (
                <span className="misa-text-green-bold misa-flex-center-gap-6 text-xs">
                    <CheckCircleOutlined /> Hợp lệ
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 140,
            align: 'center' as const,
            render: () => (
                <span 
                    onClick={handleCreateVoucher}
                    className="misa-text-blue-bold misa-cursor-pointer"
                >
                    Lập chứng từ
                </span>
            )
        }
    ];

    return (
        <Modal
            title={
                <div className="misa-modal-title">
                    <ThunderboltOutlined className="misa-text-blue" />
                    <span>Lập chứng từ mua hàng từ hóa đơn đầu vào (meInvoice Bot)</span>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={1180}
            className="misa-modal-top-20"
            footer={
                <div className="misa-modal-footer">
                    <span className="apple-muted-text">
                        Đã chọn: <strong className="misa-text-green-bold">{selectedRowKeys.length}</strong> hóa đơn
                    </span>
                    <Space>
                        <Button onClick={onCancel} className="misa-btn-secondary">Đóng</Button>
                        <Button 
                            type="primary"
                            disabled={selectedRowKeys.length === 0}
                            onClick={() => {
                                const first = eInvoices.find(i => selectedRowKeys.includes(i.id));
                                if (first) handleCreateVoucher();
                            }}
                            className="misa-btn-primary"
                        >
                            Lập chứng từ mua hàng
                        </Button>
                    </Space>
                </div>
            }
        >
            {/* Filter Bar */}
            <div className="misa-filter-box">
                <div className="misa-flex-center-gap-12 flex-wrap">
                    <div className="misa-flex-center-gap-6">
                        <span className="misa-font-12-muted">Kỳ:</span>
                        <Select 
                            value={period} 
                            onChange={setPeriod} 
                            className="misa-input misa-w-130"
                            options={[
                                { value: 'Tháng này', label: 'Tháng này' },
                                { value: 'Quý này', label: 'Quý này' },
                                { value: 'Năm nay', label: 'Năm nay' },
                            ]}
                        />
                    </div>

                    <div className="misa-flex-center-gap-6">
                        <span className="misa-font-12-muted">Trạng thái:</span>
                        <Select 
                            value={statusFilter} 
                            onChange={setStatusFilter} 
                            className="misa-input misa-w-140"
                            options={[
                                { value: 'all', label: 'Tất cả trạng thái' },
                                { value: 'valid', label: 'Hợp lệ' },
                                { value: 'created', label: 'Đã lập chứng từ' },
                            ]}
                        />
                    </div>

                    <Input 
                        placeholder="Tìm số HĐ, MST, tên người bán..." 
                        prefix={<SearchOutlined className="apple-muted-text" />}
                        value={filterKeyword}
                        onChange={e => setFilterKeyword(e.target.value)}
                        allowClear
                        className="misa-input misa-w-260"
                    />
                </div>

                <Button 
                    icon={<SyncOutlined spin={isSyncing} />}
                    loading={isSyncing}
                    onClick={handleSyncFromTax}
                    disabled
                    className="misa-btn-blue-outline"
                >
                    Đồng bộ từ TCT
                </Button>
            </div>

            {/* Invoices List Table */}
            <div className="misa-table-wrapper">
                <Table 
                    rowKey="id"
                    columns={columns}
                    dataSource={filteredInvoices}
                    size="small"
                    pagination={{ pageSize: 8 }}
                    rowSelection={{
                        selectedRowKeys,
                        onChange: setSelectedRowKeys
                    }}
                    scroll={{ y: 280 }}
                    locale={{ emptyText: 'Backend chưa công bố dữ liệu hóa đơn điện tử từ meInvoice.' }}
                />
            </div>
        </Modal>
    );
};

export default ImportFromMeInvoiceModal;
