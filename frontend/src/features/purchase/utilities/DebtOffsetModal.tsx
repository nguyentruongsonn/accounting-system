import React, { useState } from 'react';
import { Button, Select, DatePicker, Table, InputNumber, Space, Tag } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { 
    BranchesOutlined, 
    ReloadOutlined
} from '@ant-design/icons';
import dayjs from 'dayjs';
import ModalFrame from '../../../components/layout/ModalFrame';

interface DebtItem {
    id: string;
    doc_date: string;
    doc_number: string;
    invoice_number: string;
    description: string;
    total_amount: number;
    remaining_amount: number;
    offset_amount: number;
}

interface Props {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
}

export const DebtOffsetModal: React.FC<Props> = ({ open, onCancel }) => {
    const [offsetDate, setOffsetDate] = useState<any>(dayjs());

    const [receivables, setReceivables] = useState<DebtItem[]>([]);

    const [payables, setPayables] = useState<DebtItem[]>([]);

    const [selectedRecKeys, setSelectedRecKeys] = useState<React.Key[]>([]);
    const [selectedPayKeys, setSelectedPayKeys] = useState<React.Key[]>([]);

    const totalOffsetRec = receivables
        .filter(r => selectedRecKeys.includes(r.id))
        .reduce((sum, r) => sum + (r.offset_amount || 0), 0);

    const totalOffsetPay = payables
        .filter(p => selectedPayKeys.includes(p.id))
        .reduce((sum, p) => sum + (p.offset_amount || 0), 0);

    const handleExecute = () => {
        message.info('Backend chưa công bố API bù trừ công nợ; không tạo dữ liệu cục bộ.');
    };

    return (
        <Modal
            title={
                <div className="misa-modal-title-between">
                    <div className="misa-flex-center-gap-10">
                        <BranchesOutlined className="misa-text-blue text-xl" />
                        <span className="misa-font-18-bold">
                            Bù trừ công nợ phải thu và phải trả cùng đối tượng
                        </span>
                    </div>
                    <div className="misa-flex-center-gap-8">
                        <span className="apple-muted-text">Số tiền cấn trừ:</span>
                        <span className="misa-validation-val-green misa-text-blue">
                            {new Intl.NumberFormat('vi-VN').format(totalOffsetRec)} ₫
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
                        <Button 
                            type="primary" 
                            className="misa-btn-blue"
                            onClick={handleExecute}
                            disabled
                        >
                            Thực hiện bù trừ
                        </Button>
                    </Space>
                </div>
            }
        >
            <ModalFrame>
            <div className="misa-flex-col-gap-14 pt-1">
                <div className="misa-filter-box misa-filter-grid-3col">
                    <div>
                        <div className="misa-label-bold">Đối tượng (Vừa là KH vừa là NCC) *:</div>
                        <Select 
                            className="misa-w-full"
                            value={undefined}
                            disabled
                            placeholder="Chưa có dữ liệu đối tượng từ backend"
                            options={[]}
                        />
                    </div>
                    <div>
                        <div className="misa-label-bold">Ngày bù trừ *:</div>
                        <DatePicker className="misa-w-full" format="DD/MM/YYYY" value={offsetDate} onChange={setOffsetDate} />
                    </div>
                    <div className="misa-flex-end-center">
                        <Button icon={<ReloadOutlined />} disabled>
                            Lấy dữ liệu
                        </Button>
                    </div>
                </div>

                {/* Table 1: Phải thu */}
                <div>
                    <div className="misa-font-13-bold-dark mb-1 misa-flex-between-center">
                        <span>1. Chứng từ phải thu khách hàng</span>
                        <Tag color="blue">Tổng bù trừ: {new Intl.NumberFormat('vi-VN').format(totalOffsetRec)} ₫</Tag>
                    </div>
                    <div className="misa-table-wrapper">
                        <Table 
                            locale={{ emptyText: 'Backend chưa công bố dữ liệu phải thu.' }}
                            rowSelection={{
                                selectedRowKeys: selectedRecKeys,
                                onChange: setSelectedRecKeys
                            }}
                            columns={[
                                { title: 'Ngày CT', dataIndex: 'doc_date', key: 'doc_date', width: 100, align: 'center' as const },
                                { title: 'Số CT / HĐ', dataIndex: 'doc_number', key: 'doc_number', width: 120, render: (t, r) => <span className="misa-text-blue-bold">{t} ({r.invoice_number})</span> },
                                { title: 'Diễn giải', dataIndex: 'description', key: 'description' },
                                { title: 'Số chưa thu', dataIndex: 'remaining_amount', key: 'remaining_amount', width: 130, align: 'right' as const, render: v => <span>{new Intl.NumberFormat('vi-VN').format(v)} ₫</span> },
                                { 
                                    title: 'Số tiền bù trừ', 
                                    dataIndex: 'offset_amount', 
                                    key: 'offset_amount', 
                                    width: 140, 
                                    align: 'right' as const,
                                    render: (v, _, idx) => (
                                        <InputNumber 
                                            className="misa-table-input misa-w-full misa-text-blue-bold" 
                                            value={v}
                                            formatter={val => `${val}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            onChange={val => {
                                                const updated = [...receivables];
                                                updated[idx].offset_amount = Number(val) || 0;
                                                setReceivables(updated);
                                            }}
                                        />
                                    )
                                }
                            ]}
                            dataSource={receivables}
                            rowKey="id"
                            pagination={false}
                            size="small"
                        />
                    </div>
                </div>

                {/* Table 2: Phải trả */}
                <div>
                    <div className="misa-font-13-bold-dark mb-1 misa-flex-between-center">
                        <span>2. Chứng từ phải trả nhà cung cấp</span>
                        <Tag color="green">Tổng bù trừ: {new Intl.NumberFormat('vi-VN').format(totalOffsetPay)} ₫</Tag>
                    </div>
                    <div className="misa-table-wrapper">
                        <Table 
                            locale={{ emptyText: 'Backend chưa công bố dữ liệu phải trả.' }}
                            rowSelection={{
                                selectedRowKeys: selectedPayKeys,
                                onChange: setSelectedPayKeys
                            }}
                            columns={[
                                { title: 'Ngày CT', dataIndex: 'doc_date', key: 'doc_date', width: 100, align: 'center' as const },
                                { title: 'Số CT / HĐ', dataIndex: 'doc_number', key: 'doc_number', width: 120, render: (t, r) => <span className="misa-text-blue-bold">{t} ({r.invoice_number})</span> },
                                { title: 'Diễn giải', dataIndex: 'description', key: 'description' },
                                { title: 'Số còn nợ', dataIndex: 'remaining_amount', key: 'remaining_amount', width: 130, align: 'right' as const, render: v => <span className="misa-text-red">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span> },
                                { 
                                    title: 'Số tiền bù trừ', 
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
                                                const updated = [...payables];
                                                updated[idx].offset_amount = Number(val) || 0;
                                                setPayables(updated);
                                            }}
                                        />
                                    )
                                }
                            ]}
                            dataSource={payables}
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

export default DebtOffsetModal;
