import React, { useState } from 'react';
import { Radio, Button, Table } from 'antd';
import Modal from '../../../components/layout/AppModal';
import { CloseOutlined, CalculatorOutlined } from '@ant-design/icons';
import ModalFrame from '../../../components/layout/ModalFrame';

export interface AllocateExpenseModalProps {
    open: boolean;
    onCancel: () => void;
    totalExpenseToAllocate: number;
    items: any[];
    onAllocate: (allocatedItems: any[]) => void;
}

export const AllocateExpenseModal: React.FC<AllocateExpenseModalProps> = ({
    open,
    onCancel,
    totalExpenseToAllocate,
    items,
    onAllocate
}) => {
    const [method, setMethod] = useState<'quantity' | 'value'>('value');

    const totalQty = items.reduce((sum, it) => sum + (Number(it.quantity) || 0), 0);
    const totalVal = items.reduce((sum, it) => sum + (Number(it.amount) || 0), 0);

    const calculatedItems = items.map((it, idx) => {
        let ratio = 0;
        if (method === 'quantity' && totalQty > 0) {
            ratio = (Number(it.quantity) || 0) / totalQty;
        } else if (method === 'value' && totalVal > 0) {
            ratio = (Number(it.amount) || 0) / totalVal;
        }

        const allocated = Math.round(totalExpenseToAllocate * ratio);
        return {
            ...it,
            index: idx + 1,
            allocation_ratio: (ratio * 100).toFixed(2),
            allocated_expense: allocated
        };
    });

    const totalAllocated = calculatedItems.reduce((sum, it) => sum + it.allocated_expense, 0);

    const columns = [
        {
            title: '#',
            dataIndex: 'index',
            width: 45,
            align: 'center' as const
        },
        {
            title: 'Mã hàng',
            dataIndex: 'item_code',
            width: 120,
            render: (text: string) => <span className="misa-text-semibold">{text}</span>
        },
        {
            title: 'Tên hàng',
            dataIndex: 'item_name',
            width: 220,
            ellipsis: true
        },
        {
            title: 'ĐVT',
            dataIndex: 'unit',
            width: 60,
            align: 'center' as const
        },
        {
            title: 'Số lượng',
            dataIndex: 'quantity',
            width: 90,
            align: 'right' as const,
            render: (val: number) => <span>{new Intl.NumberFormat('vi-VN').format(val || 0)}</span>
        },
        {
            title: 'Giá trị hàng',
            dataIndex: 'amount',
            width: 130,
            align: 'right' as const,
            render: (val: number) => <span className="misa-text-semibold">{new Intl.NumberFormat('vi-VN').format(val || 0)} ₫</span>
        },
        {
            title: 'Tỷ lệ PB (%)',
            dataIndex: 'allocation_ratio',
            width: 100,
            align: 'right' as const,
            render: (val: string) => <span>{val}%</span>
        },
        {
            title: 'Số tiền phân bổ',
            dataIndex: 'allocated_expense',
            width: 140,
            align: 'right' as const,
            render: (val: number) => <span className="misa-text-primary-bold">{new Intl.NumberFormat('vi-VN').format(val || 0)} ₫</span>
        }
    ];

    const handleConfirm = () => {
        onAllocate(calculatedItems);
        onCancel();
    };

    return (
        <Modal
            open={open}
            onCancel={onCancel}
            width={880}
            className="misa-modal-top-40"
            closeIcon={<CloseOutlined className="text-sm" />}
            title={
                <div className="misa-flex-center-gap-8">
                    <CalculatorOutlined className="misa-text-primary-bold text-lg" />
                    <span className="misa-font-16-bold">Phân bổ chi phí mua hàng</span>
                </div>
            }
            footer={
                <div className="misa-modal-footer">
                    <div className="misa-font-12-muted">
                        Tổng chi phí phân bổ: <strong className="misa-text-primary-bold text-base">{new Intl.NumberFormat('vi-VN').format(totalAllocated)} ₫</strong>
                    </div>
                    <div className="misa-flex-center-gap-10">
                        <Button onClick={onCancel} className="misa-btn-secondary">
                            Hủy
                        </Button>
                        <Button 
                            type="primary" 
                            onClick={handleConfirm} 
                            className="misa-btn-primary"
                        >
                            Phân bổ
                        </Button>
                    </div>
                </div>
            }
        >
            <ModalFrame>
            <div className="misa-flex-col-gap-12">
                {/* Method selector & Total box */}
                <div className="misa-filter-box">
                    <div className="misa-flex-center-gap-12">
                        <span className="misa-font-13-bold-dark">Phương thức phân bổ:</span>
                        <Radio.Group value={method} onChange={e => setMethod(e.target.value)}>
                            <Radio value="value"><span className="misa-text-semibold">Theo giá trị</span></Radio>
                            <Radio value="quantity"><span className="misa-text-semibold">Theo số lượng</span></Radio>
                        </Radio.Group>
                    </div>

                    <div className="misa-font-13-muted">
                        Tổng CP cần phân bổ: <strong className="misa-text-blue-bold text-base">{new Intl.NumberFormat('vi-VN').format(totalExpenseToAllocate)} ₫</strong>
                    </div>
                </div>

                {/* Allocation Table */}
                <div className="misa-table-wrapper">
                    <Table 
                        rowKey="item_code"
                        size="small"
                        dataSource={calculatedItems}
                        columns={columns}
                        pagination={false}
                        scroll={{ y: 260 }}
                        bordered
                        summary={() => (
                            <Table.Summary.Row className="misa-summary-row-bold">
                                <Table.Summary.Cell index={0} colSpan={4} align="center">Tổng cộng</Table.Summary.Cell>
                                <Table.Summary.Cell index={1} align="right">{new Intl.NumberFormat('vi-VN').format(totalQty)}</Table.Summary.Cell>
                                <Table.Summary.Cell index={2} align="right">{new Intl.NumberFormat('vi-VN').format(totalVal)} ₫</Table.Summary.Cell>
                                <Table.Summary.Cell index={4} align="right">
                                    <span className="misa-text-primary-bold">{new Intl.NumberFormat('vi-VN').format(totalAllocated)} ₫</span>
                                </Table.Summary.Cell>
                            </Table.Summary.Row>
                        )}
                    />
                </div>
            </div>
            </ModalFrame>
        </Modal>
    );
};

export default AllocateExpenseModal;
