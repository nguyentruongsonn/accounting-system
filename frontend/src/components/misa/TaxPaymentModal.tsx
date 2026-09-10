import React, { useState, useMemo } from 'react';
import { Alert, InputNumber, Button, Table, DatePicker, Select, Tag } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import { BankOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';

export interface TaxItem {
    key: string;
    tax_name: string;
    debit_account: string;
    budget_sub_item: string; // Tiểu mục NSNN
    payable_amount: number;
    paid_amount: number;
    remaining_amount: number;
    pay_amount: number;
}

interface TaxPaymentModalProps {
    open: boolean;
    onClose: () => void;
    onSuccess?: (taxes: TaxItem[], total: number) => void;
}

export const TaxPaymentModal: React.FC<TaxPaymentModalProps> = ({
    open,
    onClose
}) => {
    const [taxType, setTaxType] = useState('all');
    const [taxes, setTaxes] = useState<TaxItem[]>([]);

    const [selectedKeys, setSelectedKeys] = useState<string[]>([]);

    const handleAmountChange = (key: string, val: number) => {
        const safeVal = Number(val) || 0;
        setTaxes(prev => prev.map(t => t.key === key ? { ...t, pay_amount: safeVal } : t));
        if (safeVal > 0) {
            setSelectedKeys(prev => prev.includes(key) ? prev : [...prev, key]);
        }
    };

    const handleRowSelectionChange = (newSelectedKeys: React.Key[]) => {
        setSelectedKeys(newSelectedKeys.map(String));
    };

    const selectedTaxes = useMemo(() => {
        return (Array.isArray(taxes) ? taxes : []).filter(t => selectedKeys.includes(t.key) && (t.pay_amount || 0) > 0);
    }, [taxes, selectedKeys]);

    const totalPay = useMemo(() => {
        return (Array.isArray(selectedTaxes) ? selectedTaxes : []).reduce(
            (sum, item) => sum + (Number(item?.pay_amount) || 0), 
            0
        );
    }, [selectedTaxes]);

    const handleConfirm = () => {
        if (!Array.isArray(selectedTaxes) || selectedTaxes.length === 0 || totalPay <= 0) {
            message.warning('Vui lòng chọn ít nhất một loại thuế có số tiền nộp > 0!');
            return;
        }

        // The tax source, authority and payment endpoint are not published.
        // Never turn locally selected rows into a cash voucher or claim that
        // tax payment succeeded. Keep the boundary explicit until a server
        // contract is supplied.
        message.info('Nộp thuế chưa khả dụng: chưa có nguồn nghĩa vụ thuế và API chứng từ máy chủ.');
    };

    const columns: ColumnsType<TaxItem> = [
        {
            title: 'Tên sắc thuế / Nghĩa vụ nộp',
            dataIndex: 'tax_name',
            key: 'tax_name',
            render: (text) => <span className="misa-fw-600">{text}</span>
        },
        {
            title: 'TK Nợ',
            dataIndex: 'debit_account',
            key: 'debit_account',
            width: 90,
            align: 'center',
            render: (text) => <Tag color="blue">{text}</Tag>
        },
        {
            title: 'Tiểu mục',
            dataIndex: 'budget_sub_item',
            key: 'budget_sub_item',
            width: 90,
            align: 'center',
            render: (text) => <span className="misa-fw-700">{text}</span>
        },
        {
            title: 'Số phải nộp',
            dataIndex: 'payable_amount',
            key: 'payable_amount',
            align: 'right',
            width: 140,
            render: (val) => `${new Intl.NumberFormat('vi-VN').format(Number(val) || 0)} ₫`
        },
        {
            title: 'Số còn nợ',
            dataIndex: 'remaining_amount',
            key: 'remaining_amount',
            align: 'right',
            width: 140,
            render: (val) => <span className="misa-fw-700 misa-color-red">{new Intl.NumberFormat('vi-VN').format(Number(val) || 0)} ₫</span>
        },
        {
            title: 'Số nộp lần này',
            dataIndex: 'pay_amount',
            key: 'pay_amount',
            align: 'right',
            width: 170,
            render: (val, record) => (
                <InputNumber
                    className="misa-w-full text-right font-semibold"
                    min={0}
                    max={record.remaining_amount}
                    value={val}
                    formatter={v => (v !== undefined && v !== null && v !== '') ? `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : ''}
                    parser={(v) => (v ? Number(String(v).replace(/\$\s?|(,*)/g, '')) : 0) as any}
                    onChange={(n) => handleAmountChange(record.key, Number(n) || 0)}
                />
            )
        }
    ];

    return (
        <Modal
            title={
                <div className="misa-flex-between misa-pr-24">
                    <span className="misa-modal-title">Nộp thuế vào Ngân sách Nhà nước</span>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={1050}
            footer={
                <div className="misa-flex-between">
                    <div>
                        <span className="apple-muted-text">Tổng số tiền nộp thuế: </span>
                        <strong className="misa-fs-16 misa-color-red">
                            {new Intl.NumberFormat('vi-VN').format(totalPay)} ₫
                        </strong>
                    </div>
                    <div className="misa-flex misa-gap-8">
                        <Button onClick={onClose}>Hủy bỏ</Button>
                        <Button type="primary" danger className="misa-btn-danger" onClick={handleConfirm} disabled={selectedTaxes.length === 0}>
                            <BankOutlined /> Nộp thuế ({(selectedTaxes || []).length})
                        </Button>
                    </div>
                </div>
            }
            centered
            className="misa-custom-modal"
        >
            <Alert
                className="apple-section-gap"
                type="warning"
                showIcon
                message="Nộp thuế chưa khả dụng"
                description="Chưa có API cung cấp nghĩa vụ thuế, cơ quan thụ hưởng và chứng từ nguồn; không thể lập phiếu chi từ dữ liệu mẫu."
            />
            <div className="apple-section-gap misa-bg-light misa-p-12 misa-rounded-6">
                <div className="misa-form-grid">
                    <div className="misa-col-4">
                        <div className="misa-field-label">Loại thuế</div>
                        <Select
                            className="misa-w-full"
                            value={taxType}
                            onChange={setTaxType}
                            options={[
                                { value: 'all', label: 'Tất cả các sắc thuế' },
                                { value: 'vat', label: 'Thuế GTGT' },
                                { value: 'cit', label: 'Thuế TNDN' },
                                { value: 'pit', label: 'Thuế TNCN' },
                                { value: 'license', label: 'Lệ phí môn bài' }
                            ]}
                        />
                    </div>
                    <div className="misa-col-4">
                        <div className="misa-field-label">Cơ quan thuế / Kho bạc</div>
                        <Select
                            className="misa-w-full"
                            disabled
                            placeholder="Chưa có cơ quan thuế/kho bạc từ máy chủ"
                            options={[]}
                        />
                    </div>
                    <div className="misa-col-4">
                        <div className="misa-field-label">Ngày nộp</div>
                        <DatePicker className="misa-w-full" defaultValue={dayjs()} format="DD/MM/YYYY" />
                    </div>
                </div>
            </div>

            <Table
                columns={columns}
                dataSource={taxes}
                rowKey="key"
                size="small"
                pagination={false}
                bordered
                rowSelection={{
                    selectedRowKeys: selectedKeys,
                    onChange: (keys) => handleRowSelectionChange(keys)
                }}
            />
        </Modal>
    );
};
