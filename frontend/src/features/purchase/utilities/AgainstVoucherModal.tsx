import React, { useState } from 'react';
import { Button, Select, DatePicker, Table, InputNumber, Space, Tag } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { 
    SwapOutlined, 
    ReloadOutlined
} from '@ant-design/icons';
import dayjs from 'dayjs';
import ModalFrame from '../../../components/layout/ModalFrame';

interface PaymentVoucherItem {
    id: string;
    doc_date: string;
    doc_number: string;
    description: string;
    total_amount: number;
    unoffset_amount: number;
    offset_amount: number;
    doc_type: string;
}

interface DebtVoucherItem {
    id: string;
    doc_date: string;
    doc_number: string;
    invoice_number: string;
    due_date: string;
    description: string;
    total_amount: number;
    remaining_amount: number;
    offset_amount: number;
    doc_type: string;
}

interface Props {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
}

export const AgainstVoucherModal: React.FC<Props> = ({ open, onCancel }) => {
    const [supplierCode, setSupplierCode] = useState('');
    // The offset endpoint is not available yet. Keep the account field empty
    // until the server supplies a tenant-scoped catalogue; never infer 331/3388.
    const [account, setAccount] = useState<string | undefined>(undefined);
    const [offsetDate, setOffsetDate] = useState<any>(dayjs());
    const [currency, setCurrency] = useState('VND');

    const [paymentVouchers, setPaymentVouchers] = useState<PaymentVoucherItem[]>([]);

    const [debtVouchers, setDebtVouchers] = useState<DebtVoucherItem[]>([]);

    const [selectedPaymentKeys, setSelectedPaymentKeys] = useState<React.Key[]>([]);
    const [selectedDebtKeys, setSelectedDebtKeys] = useState<React.Key[]>([]);

    const totalOffsetPayment = paymentVouchers
        .filter(p => selectedPaymentKeys.includes(p.id))
        .reduce((sum, p) => sum + (p.offset_amount || 0), 0);

    const totalOffsetDebt = debtVouchers
        .filter(d => selectedDebtKeys.includes(d.id))
        .reduce((sum, d) => sum + (d.offset_amount || 0), 0);

    const handleAutoOffset = () => {
        message.info('Backend chưa công bố dữ liệu đối trừ công nợ; không tự động gán số tiền cục bộ.');
    };

    const handleExecute = () => {
        message.info('Backend chưa công bố API thực hiện đối trừ công nợ; không tạo dữ liệu cục bộ.');
    };

    return (
        <Modal
            title={
                <div className="misa-modal-title-between">
                    <div className="misa-flex-center-gap-10">
                        <SwapOutlined className="misa-text-primary-bold text-xl" />
                        <span className="misa-font-18-bold">
                            Đối trừ chứng từ công nợ Nhà cung cấp
                        </span>
                    </div>
                    <div className="misa-flex-center-gap-8">
                        <span className="apple-muted-text">Tổng số tiền đối trừ:</span>
                        <span className="misa-validation-val-green">
                            {new Intl.NumberFormat('vi-VN').format(totalOffsetPayment)} ₫
                        </span>
                    </div>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={1100}
            footer={
                <div className="misa-modal-footer">
                    <Button onClick={onCancel} className="misa-btn-cancel">Hủy</Button>
                    <Space>
                        <Button icon={<ReloadOutlined />} onClick={handleAutoOffset} disabled>
                            Đối trừ tự động
                        </Button>
                        <Button 
                            type="primary" 
                            className="misa-btn-modal-action"
                            onClick={handleExecute}
                            disabled
                        >
                            Thực hiện đối trừ
                        </Button>
                    </Space>
                </div>
            }
        >
            <ModalFrame>
            <div className="misa-flex-col-gap-14 pt-1">
                {/* Header Filter Box */}
                <div className="misa-filter-box misa-filter-grid-4col">
                    <div>
                        <div className="misa-label-bold">Nhà cung cấp *:</div>
                        <Select 
                            className="misa-w-full"
                            value={supplierCode}
                            onChange={setSupplierCode}
                            placeholder="Chưa có nhà cung cấp từ máy chủ"
                            notFoundContent="Chưa có nhà cung cấp từ máy chủ"
                            options={[]}
                        />
                    </div>
                    <div>
                        <div className="misa-label-bold">Tài khoản phải trả *:</div>
                        <Select 
                            className="misa-w-full"
                            value={account}
                            onChange={setAccount}
                            disabled
                            placeholder="Chưa có tài khoản từ máy chủ"
                            notFoundContent="Chưa có tài khoản từ máy chủ"
                            options={[]}
                        />
                    </div>
                    <div>
                        <div className="misa-label-bold">Ngày đối trừ *:</div>
                        <DatePicker className="misa-w-full" format="DD/MM/YYYY" value={offsetDate} onChange={setOffsetDate} />
                    </div>
                    <div>
                        <div className="misa-label-bold">Loại tiền:</div>
                        <Select className="misa-w-full" value={currency} onChange={setCurrency} options={[{ value: 'VND', label: 'VND - Đồng Việt Nam' }, { value: 'USD', label: 'USD - Đô la Mỹ' }]} />
                    </div>
                </div>

                {/* Table 1: Chứng từ thanh toán */}
                <div>
                    <div className="misa-font-13-bold-dark mb-1 misa-flex-between-center">
                        <span>1. Chứng từ thanh toán (Phiếu chi, UNC, Trả lại hàng mua)</span>
                        <Tag color="blue">Tổng thanh toán: {new Intl.NumberFormat('vi-VN').format(totalOffsetPayment)} ₫</Tag>
                    </div>
                    <div className="misa-table-wrapper">
                        <Table
                            locale={{ emptyText: 'Backend chưa công bố dữ liệu chứng từ thanh toán.' }}
                            rowSelection={{
                                selectedRowKeys: selectedPaymentKeys,
                                onChange: setSelectedPaymentKeys
                            }}
                            columns={[
                                { title: 'Ngày CT', dataIndex: 'doc_date', key: 'doc_date', width: 100, align: 'center' as const },
                                { title: 'Số CT', dataIndex: 'doc_number', key: 'doc_number', width: 110, render: t => <span className="misa-text-blue-bold">{t}</span> },
                                { title: 'Diễn giải', dataIndex: 'description', key: 'description' },
                                { title: 'Số tiền', dataIndex: 'total_amount', key: 'total_amount', width: 130, align: 'right' as const, render: v => <span>{new Intl.NumberFormat('vi-VN').format(v)} ₫</span> },
                                { title: 'Chưa đối trừ', dataIndex: 'unoffset_amount', key: 'unoffset_amount', width: 130, align: 'right' as const, render: v => <span className="misa-text-blue-bold">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span> },
                                { 
                                    title: 'Số tiền đối trừ', 
                                    dataIndex: 'offset_amount', 
                                    key: 'offset_amount', 
                                    width: 140, 
                                    align: 'right' as const,
                                    render: (v, _, idx) => (
                                        <InputNumber 
                                            className="misa-table-input misa-w-full misa-text-primary-bold" 
                                            value={v}
                                            formatter={val => `${val}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            onChange={val => {
                                                const updated = [...paymentVouchers];
                                                updated[idx].offset_amount = Number(val) || 0;
                                                setPaymentVouchers(updated);
                                            }}
                                        />
                                    )
                                },
                            ]}
                            dataSource={paymentVouchers}
                            rowKey="id"
                            pagination={false}
                            size="small"
                        />
                    </div>
                </div>

                {/* Table 2: Chứng từ công nợ */}
                <div>
                    <div className="misa-font-13-bold-dark mb-1 misa-flex-between-center">
                        <span>2. Chứng từ công nợ (Hóa đơn, Chứng từ mua hàng chưa thanh toán)</span>
                        <Tag color="green">Tổng công nợ: {new Intl.NumberFormat('vi-VN').format(totalOffsetDebt)} ₫</Tag>
                    </div>
                    <div className="misa-table-wrapper">
                        <Table
                            locale={{ emptyText: 'Backend chưa công bố dữ liệu công nợ.' }}
                            rowSelection={{
                                selectedRowKeys: selectedDebtKeys,
                                onChange: setSelectedDebtKeys
                            }}
                            columns={[
                                { title: 'Ngày CT', dataIndex: 'doc_date', key: 'doc_date', width: 100, align: 'center' as const },
                                { title: 'Số CT', dataIndex: 'doc_number', key: 'doc_number', width: 110, render: t => <span className="misa-text-blue-bold">{t}</span> },
                                { title: 'Số hóa đơn', dataIndex: 'invoice_number', key: 'invoice_number', width: 110 },
                                { title: 'Hạn thanh toán', dataIndex: 'due_date', key: 'due_date', width: 110, align: 'center' as const },
                                { title: 'Diễn giải', dataIndex: 'description', key: 'description' },
                                { title: 'Số còn nợ', dataIndex: 'remaining_amount', key: 'remaining_amount', width: 130, align: 'right' as const, render: v => <span className="misa-text-red">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span> },
                                { 
                                    title: 'Số tiền đối trừ', 
                                    dataIndex: 'offset_amount', 
                                    key: 'offset_amount', 
                                    width: 140, 
                                    align: 'right' as const,
                                    render: (v, _, idx) => (
                                        <InputNumber 
                                            className="misa-table-input misa-w-full misa-text-primary-bold" 
                                            value={v}
                                            formatter={val => `${val}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            onChange={val => {
                                                const updated = [...debtVouchers];
                                                updated[idx].offset_amount = Number(val) || 0;
                                                setDebtVouchers(updated);
                                            }}
                                        />
                                    )
                                },
                            ]}
                            dataSource={debtVouchers}
                            rowKey="id"
                            pagination={false}
                            size="small"
                        />
                    </div>
                </div>
            </div>
            </ModalFrame>
        </Modal>
    );
};

export default AgainstVoucherModal;
