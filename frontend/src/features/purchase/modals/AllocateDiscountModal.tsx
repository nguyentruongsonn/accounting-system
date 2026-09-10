import React, { useState, useEffect } from 'react';
import { Radio, InputNumber, Button, Table, Space } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { CalculatorOutlined } from '@ant-design/icons';
import ModalFrame from '../../../components/layout/ModalFrame';

interface AllocateDiscountModalProps {
    open: boolean;
    onCancel: () => void;
    items: any[];
    onAllocate: (allocatedItems: any[]) => void;
}

export const AllocateDiscountModal: React.FC<AllocateDiscountModalProps> = ({
    open,
    onCancel,
    items,
    onAllocate
}) => {
    const [totalDiscount, setTotalDiscount] = useState<number>(0);
    const [method, setMethod] = useState<'value' | 'quantity'>('value');
    const [lines, setLines] = useState<any[]>([]);

    useEffect(() => {
        if (open) {
            const currentTotalDiscount = items.reduce((acc, it) => acc + (Number(it.discount_amount) || 0), 0);
            setTotalDiscount(currentTotalDiscount);
            setMethod('value');
            
            // Initialize allocation lines
            const initialLines = items.map((it, idx) => ({
                key: it.id ?? idx,
                index: idx,
                item_code: it.item_code ?? null,
                item_name: it.item_name ?? null,
                quantity: it.quantity == null ? null : Number(it.quantity),
                amount: it.amount == null ? null : Number(it.amount),
                alloc_rate: 0,
                allocated_discount: Number(it.discount_amount) || 0
            }));
            setLines(initialLines);
        }
    }, [open, items]);

    const handleCalculateAllocation = () => {
        if (totalDiscount <= 0) {
            message.warning('Vui lòng nhập số tiền chiết khấu lớn hơn 0');
            return;
        }

        const totalQty = lines.reduce((acc, l) => acc + (Number(l.quantity) || 0), 0);
        const totalVal = lines.reduce((acc, l) => acc + (Number(l.amount) || 0), 0);

        let runningDiscount = 0;
        const updated = lines.map((l, idx) => {
            let rate = 0;
            let allocated = 0;

            if (method === 'quantity' && totalQty > 0) {
                rate = (Number(l.quantity) / totalQty);
            } else if (method === 'value' && totalVal > 0) {
                rate = (Number(l.amount) / totalVal);
            }

            if (idx === lines.length - 1) {
                // Ensure exact rounding match
                allocated = totalDiscount - runningDiscount;
            } else {
                allocated = Math.round(totalDiscount * rate);
                runningDiscount += allocated;
            }

            return {
                ...l,
                alloc_rate: (rate * 100).toFixed(4),
                allocated_discount: allocated
            };
        });

        setLines(updated);
        message.success('Đã tính toán phân bổ chiết khấu');
    };

    const handleConfirm = () => {
        onAllocate(lines);
        onCancel();
    };

    const columns = [
        {
            title: '#',
            dataIndex: 'index',
            width: 45,
            align: 'center' as const,
            render: (val: number) => val + 1
        },
        {
            title: 'Mã hàng',
            dataIndex: 'item_code',
            width: 140,
            render: (text: string | null) => <span className="misa-text-semibold">{text ?? '—'}</span>
        },
        {
            title: 'Tên hàng',
            dataIndex: 'item_name',
            ellipsis: true
        },
        {
            title: 'Số lượng',
            dataIndex: 'quantity',
            width: 90,
            align: 'right' as const,
            render: (val: number | null) => val == null ? '—' : new Intl.NumberFormat('vi-VN').format(val)
        },
        {
            title: 'Thành tiền',
            dataIndex: 'amount',
            width: 140,
            align: 'right' as const,
            render: (val: number | null) => <span className="misa-text-semibold">{val == null ? '—' : `${new Intl.NumberFormat('vi-VN').format(val)} ₫`}</span>
        },
        {
            title: 'Tỷ lệ phân bổ (%)',
            dataIndex: 'alloc_rate',
            width: 130,
            align: 'right' as const,
            render: (val: string) => `${val || 0}%`
        },
        {
            title: 'Tiền chiết khấu',
            dataIndex: 'allocated_discount',
            width: 150,
            align: 'right' as const,
            render: (val: number) => (
                <span className="misa-text-red misa-text-bold">
                    {new Intl.NumberFormat('vi-VN').format(val)} ₫
                </span>
            )
        }
    ];

    const totalAllocated = lines.reduce((acc, l) => acc + (Number(l.allocated_discount) || 0), 0);

    return (
        <Modal
            title={
                <div className="misa-flex-center-gap-8">
                    <CalculatorOutlined className="misa-text-blue text-lg" />
                    <span className="misa-font-16-bold">
                        Phân bổ chiết khấu theo tổng giá trị hóa đơn
                    </span>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={880}
            footer={
                <div className="misa-modal-footer">
                    <span className="apple-muted-text">
                        Tổng chiết khấu phân bổ: <strong className="misa-text-red">{new Intl.NumberFormat('vi-VN').format(totalAllocated)} ₫</strong>
                    </span>
                    <Space size={10}>
                        <Button onClick={onCancel} className="misa-btn-secondary">Hủy</Button>
                        <Button 
                            type="primary" 
                            onClick={handleConfirm}
                            className="misa-btn-primary"
                        >
                            Thực hiện
                        </Button>
                    </Space>
                </div>
            }
        >
            <ModalFrame>
            <div className="misa-flex-col-gap-14 py-1">
                <div className="misa-filter-box">
                    <div className="misa-flex-center-gap-10">
                        <span className="misa-font-12-muted misa-text-semibold">Tổng tiền chiết khấu:</span>
                        <InputNumber 
                            value={totalDiscount}
                            onChange={v => setTotalDiscount(Number(v) || 0)}
                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                            className="misa-input misa-w-170 misa-text-red misa-text-bold"
                            size="middle"
                        />
                    </div>

                    <div className="misa-flex-center-gap-10">
                        <span className="misa-font-12-muted misa-text-semibold">Phương pháp:</span>
                        <Radio.Group value={method} onChange={e => setMethod(e.target.value)}>
                            <Radio value="value">Theo giá trị (Thành tiền)</Radio>
                            <Radio value="quantity">Theo số lượng</Radio>
                        </Radio.Group>
                        <Button 
                            type="primary" 
                            onClick={handleCalculateAllocation}
                            className="misa-btn-blue-sm"
                            size="small"
                        >
                            Phân bổ
                        </Button>
                    </div>
                </div>

                <div className="misa-table-wrapper">
                    <Table 
                        columns={columns}
                        dataSource={lines}
                        pagination={false}
                        size="small"
                        bordered
                        scroll={{ y: 260 }}
                    />
                </div>
            </div>
            </ModalFrame>
        </Modal>
    );
};

export default AllocateDiscountModal;
