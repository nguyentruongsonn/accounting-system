import React, { useState, useMemo } from 'react';
import { Alert, InputNumber, Button, Table, DatePicker, Select, Tag } from 'antd';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import { SafetyCertificateOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import ModalFrame from '../layout/ModalFrame';

export interface InsuranceItem {
    key: string;
    ins_name: string;
    rate_text: string;
    debit_account: string;
    payable_amount: number;
    paid_amount: number;
    remaining_amount: number;
    pay_amount: number;
}

interface InsurancePaymentModalProps {
    open: boolean;
    onClose: () => void;
    onSuccess?: (ins: InsuranceItem[], total: number) => void;
}

export const InsurancePaymentModal: React.FC<InsurancePaymentModalProps> = ({
    open,
    onClose
}) => {
    const [period, setPeriod] = useState<string>(`Tháng ${dayjs().format('MM/YYYY')}`);
    const [insuranceList, setInsuranceList] = useState<InsuranceItem[]>([]);

    const [selectedKeys, setSelectedKeys] = useState<string[]>([]);

    const handleAmountChange = (key: string, val: number) => {
        const safeVal = Number(val) || 0;
        setInsuranceList(prev => prev.map(item => item.key === key ? { ...item, pay_amount: safeVal } : item));
        if (safeVal > 0) {
            setSelectedKeys(prev => prev.includes(key) ? prev : [...prev, key]);
        }
    };

    const handleRowSelectionChange = (newSelectedKeys: React.Key[]) => {
        setSelectedKeys(newSelectedKeys.map(String));
    };

    const selectedItems = useMemo(() => {
        return (Array.isArray(insuranceList) ? insuranceList : []).filter(item => selectedKeys.includes(item.key) && (item.pay_amount || 0) > 0);
    }, [insuranceList, selectedKeys]);

    const totalPay = useMemo(() => {
        return (Array.isArray(selectedItems) ? selectedItems : []).reduce(
            (sum, item) => sum + (Number(item?.pay_amount) || 0), 
            0
        );
    }, [selectedItems]);

    const handleConfirm = () => {
        if (!Array.isArray(selectedItems) || selectedItems.length === 0 || totalPay <= 0) {
            message.warning('Vui lòng chọn ít nhất một khoản bảo hiểm có số nộp > 0!');
            return;
        }

        // The payroll/insurance source and payment endpoint are not
        // published. Do not synthesize a cash voucher or an accounting
        // mapping from locally selected rows.
        message.info('Nộp bảo hiểm chưa khả dụng: chưa có bảng kê và API chứng từ máy chủ.');
    };

    const columns: ColumnsType<InsuranceItem> = [
        {
            title: 'Khoản trích theo lương',
            dataIndex: 'ins_name',
            key: 'ins_name',
            render: (text) => <span className="misa-fw-600">{text}</span>
        },
        {
            title: 'Tỷ lệ trích',
            dataIndex: 'rate_text',
            key: 'rate_text',
            width: 170,
            render: (text) => <span className="apple-muted-text">{text}</span>
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
            title: 'Số phải nộp',
            dataIndex: 'payable_amount',
            key: 'payable_amount',
            align: 'right',
            width: 130,
            render: (val) => `${new Intl.NumberFormat('vi-VN').format(Number(val) || 0)} ₫`
        },
        {
            title: 'Số còn nợ',
            dataIndex: 'remaining_amount',
            key: 'remaining_amount',
            align: 'right',
            width: 130,
            render: (val) => <span className="misa-fw-700 misa-color-red">{new Intl.NumberFormat('vi-VN').format(Number(val) || 0)} ₫</span>
        },
        {
            title: 'Số nộp lần này',
            dataIndex: 'pay_amount',
            key: 'pay_amount',
            align: 'right',
            width: 160,
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
                    <span className="misa-modal-title">Nộp bảo hiểm & Kinh phí công đoàn</span>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={1050}
            footer={
                <div className="misa-flex-between">
                    <div>
                        <span className="apple-muted-text">Tổng số tiền nộp bảo hiểm: </span>
                        <strong className="misa-fs-16 misa-color-red">
                            {new Intl.NumberFormat('vi-VN').format(totalPay)} ₫
                        </strong>
                    </div>
                    <div className="misa-flex misa-gap-8">
                        <Button onClick={onClose}>Hủy bỏ</Button>
                        <Button type="primary" danger className="misa-btn-danger" onClick={handleConfirm} disabled={selectedItems.length === 0}>
                            <SafetyCertificateOutlined /> Nộp bảo hiểm ({(selectedItems || []).length})
                        </Button>
                    </div>
                </div>
            }
            centered
            className="misa-custom-modal"
        >
            <ModalFrame className="misa-insurance-payment-modal__frame">
            <Alert
                className="apple-section-gap"
                type="warning"
                showIcon
                message="Nộp bảo hiểm chưa khả dụng"
                description="Chưa có API cung cấp bảng kê bảo hiểm, cơ quan thụ hưởng và chứng từ nguồn; không thể lập phiếu chi từ dữ liệu mẫu."
            />
            <div className="apple-section-gap misa-bg-light misa-p-12 misa-rounded-6">
                <div className="misa-form-grid">
                    <div className="misa-col-4">
                        <div className="misa-field-label">Kỳ nộp bảo hiểm</div>
                        <Select
                            className="misa-w-full"
                            value={period}
                            onChange={setPeriod}
                            options={[
                                { value: `Tháng ${dayjs().format('MM/YYYY')}`, label: `Tháng ${dayjs().format('MM/YYYY')}` },
                                { value: `Tháng ${dayjs().subtract(1, 'month').format('MM/YYYY')}`, label: `Tháng ${dayjs().subtract(1, 'month').format('MM/YYYY')}` },
                                { value: `Tháng ${dayjs().subtract(2, 'month').format('MM/YYYY')}`, label: `Tháng ${dayjs().subtract(2, 'month').format('MM/YYYY')}` }
                            ]}
                        />
                    </div>
                    <div className="misa-col-4">
                        <div className="misa-field-label">Cơ quan BHXH quản lý</div>
                        <Select
                            className="misa-w-full"
                            disabled
                            placeholder="Chưa có cơ quan BHXH từ máy chủ"
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
                dataSource={insuranceList}
                rowKey="key"
                size="small"
                pagination={false}
                bordered
                rowSelection={{
                    selectedRowKeys: selectedKeys,
                    onChange: (keys) => handleRowSelectionChange(keys)
                }}
            />
            </ModalFrame>
        </Modal>
    );
};
