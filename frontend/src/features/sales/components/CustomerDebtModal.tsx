import React from 'react';
import { Table, Button, Tag } from 'antd';
import Modal from '../../../components/layout/AppModal';
import { DollarOutlined, WarningOutlined, CheckCircleOutlined, InfoCircleOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';

interface CustomerDebtModalProps {
    open: boolean;
    customer?: any;
    invoices?: any[];
    onClose: () => void;
}

export const CustomerDebtModal: React.FC<CustomerDebtModalProps> = ({
    open,
    customer,
    invoices = [],
    onClose
}) => {
    const customerInvoices = invoices.filter((inv: any) =>
        inv.customer_id === customer?.id || (customer?.name && inv.customer_name === customer.name)
    );

    const totalDebt = customerInvoices.reduce((sum: number, inv: any) => sum + (Number(inv.total_amount) || 0), 0);
    const debtLimit = customer?.debt_limit || 50000000;
    const overdueDebt = 0;

    const formatCurrency = (val: number) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val);

    const columns: ColumnsType<any> = [
        {
            title: '#',
            width: 45,
            align: 'center',
            render: (_, __, idx) => idx + 1
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'invoice_number',
            key: 'invoice_number',
            width: 130,
            render: (val, r) => (
                <span className="font-semibold text-blue-600">{val || r.invoice_code || '—'}</span>
            )
        },
        {
            title: 'Ngày chứng từ',
            dataIndex: 'invoice_date',
            key: 'invoice_date',
            width: 110,
            render: (val) => val ? String(val).slice(0, 10) : '—'
        },
        {
            title: 'Hạn thanh toán',
            dataIndex: 'due_date',
            key: 'due_date',
            width: 110,
            render: (val) => val ? String(val).slice(0, 10) : '—'
        },
        {
            title: 'Số tiền hóa đơn',
            dataIndex: 'total_amount',
            key: 'total_amount',
            width: 130,
            align: 'right',
            render: (val) => formatCurrency(Number(val) || 0)
        },
        {
            title: 'Đã thanh toán',
            dataIndex: 'paid_amount',
            key: 'paid_amount',
            width: 120,
            align: 'right',
            render: (val) => formatCurrency(Number(val) || 0)
        },
        {
            title: 'Còn phải thu',
            key: 'remaining',
            width: 130,
            align: 'right',
            render: (_, r) => {
                const total = Number(r.total_amount) || 0;
                const paid = Number(r.paid_amount) || 0;
                return <span className="font-semibold text-red-600">{formatCurrency(total - paid)}</span>;
            }
        },
        {
            title: 'Trạng thái',
            dataIndex: 'status',
            key: 'status',
            width: 110,
            align: 'center',
            render: (val) => {
                if (val === 'paid') return <Tag color="success">Đã thanh toán</Tag>;
                if (val === 'partial') return <Tag color="warning">Thanh toán 1 phần</Tag>;
                return <Tag color="error">Chưa thanh toán</Tag>;
            }
        }
    ];

    return (
        <Modal
            open={open}
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <DollarOutlined style={{ color: '#1677ff', fontSize: 18 }} />
                    <span style={{ fontWeight: 600, fontSize: 16 }}>Tình hình công nợ khách hàng</span>
                </div>
            }
            onCancel={onClose}
            width={960}
            footer={[
                <Button key="close" onClick={onClose} type="primary">
                    Đóng
                </Button>
            ]}
        >
            <div style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 6, padding: '12px 16px', marginBottom: 16 }}>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(12, 1fr)', gap: 10, fontSize: 13 }}>
                    <div style={{ gridColumn: 'span 4' }}>
                        <span style={{ color: '#64748b' }}>Mã khách hàng: </span>
                        <span style={{ fontWeight: 600, color: '#1e293b' }}>{customer?.code || '—'}</span>
                    </div>
                    <div style={{ gridColumn: 'span 8' }}>
                        <span style={{ color: '#64748b' }}>Tên khách hàng: </span>
                        <span style={{ fontWeight: 600, color: '#1e293b' }}>{customer?.name || '—'}</span>
                    </div>
                    <div style={{ gridColumn: 'span 4' }}>
                        <span style={{ color: '#64748b' }}>Mã số thuế: </span>
                        <span style={{ color: '#1e293b' }}>{customer?.tax_code || '—'}</span>
                    </div>
                    <div style={{ gridColumn: 'span 8' }}>
                        <span style={{ color: '#64748b' }}>Địa chỉ: </span>
                        <span style={{ color: '#1e293b' }}>{customer?.address || '—'}</span>
                    </div>
                </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 16 }}>
                <div style={{ background: '#fff1f2', border: '1px solid #fecdd3', borderRadius: 6, padding: '10px 14px' }}>
                    <div style={{ fontSize: 12, color: '#9f1239', display: 'flex', alignItems: 'center', gap: 6 }}>
                        <WarningOutlined style={{ color: '#e11d48' }} />
                        <span>Nợ hiện tại</span>
                    </div>
                    <div style={{ fontSize: 18, fontWeight: 700, color: '#e11d48', marginTop: 4 }}>
                        {formatCurrency(totalDebt)}
                    </div>
                </div>

                <div style={{ background: '#ecfdf5', border: '1px solid #a7f3d0', borderRadius: 6, padding: '10px 14px' }}>
                    <div style={{ fontSize: 12, color: '#065f46', display: 'flex', alignItems: 'center', gap: 6 }}>
                        <CheckCircleOutlined style={{ color: '#059669' }} />
                        <span>Hạn mức nợ</span>
                    </div>
                    <div style={{ fontSize: 18, fontWeight: 700, color: '#059669', marginTop: 4 }}>
                        {formatCurrency(debtLimit)}
                    </div>
                </div>

                <div style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 6, padding: '10px 14px' }}>
                    <div style={{ fontSize: 12, color: '#92400e', display: 'flex', alignItems: 'center', gap: 6 }}>
                        <InfoCircleOutlined style={{ color: '#d97706' }} />
                        <span>Nợ quá hạn</span>
                    </div>
                    <div style={{ fontSize: 18, fontWeight: 700, color: '#d97706', marginTop: 4 }}>
                        {formatCurrency(overdueDebt)}
                    </div>
                </div>
            </div>

            <div style={{ border: '1px solid #e2e8f0', borderRadius: 6, overflow: 'hidden' }}>
                <Table
                    columns={columns}
                    dataSource={customerInvoices}
                    rowKey="id"
                    pagination={{ pageSize: 5, size: 'small' }}
                    size="small"
                    locale={{ emptyText: 'Khách hàng này hiện không có nợ tồn đọng' }}
                />
            </div>
        </Modal>
    );
};

export default CustomerDebtModal;
