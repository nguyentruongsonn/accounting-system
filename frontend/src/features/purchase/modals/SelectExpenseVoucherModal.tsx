import React from 'react';
import { Button, Table } from 'antd';
import Modal from '../../../components/layout/AppModal';
import { CloseOutlined, QuestionCircleOutlined } from '@ant-design/icons';

export interface SelectExpenseVoucherModalProps {
    open: boolean;
    onCancel: () => void;
    onSelect: (selectedVouchers: any[]) => void;
    supplierId?: number;
}

interface ExpenseVoucherRow {
    id: number;
    voucher_no: string;
    voucher_date: string;
    supplier_name: string;
    total_expense: number;
    allocated_amount: number;
}

/**
 * Expense-voucher selection is intentionally fail-closed. There is currently
 * no backend query contract for this source, so the modal must not expose
 * invented vouchers or pass them to a purchase document as if they existed.
 */
export const SelectExpenseVoucherModal: React.FC<SelectExpenseVoucherModalProps> = ({
    open,
    onCancel
}) => {
    const columns = [
        { title: 'Ngày chứng từ', dataIndex: 'voucher_date', key: 'voucher_date' },
        { title: 'Số chứng từ', dataIndex: 'voucher_no', key: 'voucher_no' },
        { title: 'Nhà cung cấp', dataIndex: 'supplier_name', key: 'supplier_name' },
        {
            title: 'Tổng chi phí',
            dataIndex: 'total_expense',
            key: 'total_expense',
            align: 'right' as const,
            render: (value: number) => `${new Intl.NumberFormat('vi-VN').format(value || 0)} ₫`
        },
        {
            title: 'Đã phân bổ',
            dataIndex: 'allocated_amount',
            key: 'allocated_amount',
            align: 'right' as const,
            render: (value: number) => `${new Intl.NumberFormat('vi-VN').format(value || 0)} ₫`
        }
    ];

    return (
        <Modal
            open={open}
            onCancel={onCancel}
            width={960}
            className="misa-modal-top-30"
            closeIcon={<CloseOutlined className="text-sm" />}
            title={
                <div className="misa-modal-title-between-sm">
                    <div className="misa-flex-center-gap-8">
                        <span className="misa-font-16-bold">Chọn chứng từ chi phí</span>
                        <QuestionCircleOutlined className="apple-muted-text text-sm" />
                    </div>
                </div>
            }
            footer={
                <div className="misa-modal-footer-end">
                    <Button onClick={onCancel} className="misa-btn-secondary">Hủy</Button>
                    <Button type="primary" disabled className="misa-btn-modal-action">
                        Đồng ý
                    </Button>
                </div>
            }
        >
            <Table<ExpenseVoucherRow>
                rowKey="id"
                size="small"
                dataSource={[]}
                columns={columns}
                pagination={false}
                locale={{ emptyText: 'Backend chưa công bố dữ liệu chứng từ chi phí.' }}
            />
        </Modal>
    );
};

export default SelectExpenseVoucherModal;
