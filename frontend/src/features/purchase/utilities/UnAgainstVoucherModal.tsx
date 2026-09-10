import React, { useState } from 'react';
import { Button, Select, Table, Space, Popconfirm } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { 
    RollbackOutlined, 
    ReloadOutlined
} from '@ant-design/icons';

interface OffsetHistoryRecord {
    id: string;
    payment_type: string;
    payment_date: string;
    payment_number: string;
    payment_offset: number;
    debt_type: string;
    debt_date: string;
    debt_number: string;
    invoice_number: string;
    debt_offset: number;
    created_at: string;
}

interface Props {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
}

export const UnAgainstVoucherModal: React.FC<Props> = ({ open, onCancel }) => {
    const [supplierCode, setSupplierCode] = useState('');
    const [selectedKeys, setSelectedKeys] = useState<React.Key[]>([]);

    const [offsetHistory] = useState<OffsetHistoryRecord[]>([]);

    const handleUnOffset = () => {
        message.info('Backend chưa công bố API hủy đối trừ công nợ; không thay đổi dữ liệu cục bộ.');
    };

    return (
        <Modal
            title={
                <div className="misa-modal-title">
                    <RollbackOutlined className="misa-text-red text-xl" />
                    <span className="misa-font-18-bold">
                        Bỏ đối trừ chứng từ công nợ Nhà cung cấp
                    </span>
                </div>
            }
            open={open}
            onCancel={onCancel}
            width={1000}
            footer={
                <div className="misa-modal-footer">
                    <Button onClick={onCancel} className="misa-btn-cancel">Đóng</Button>
                    <Space>
                        <Popconfirm
                            title="Bạn có chắc chắn muốn bỏ đối trừ các chứng từ đã chọn?"
                            onConfirm={handleUnOffset}
                        >
                            <Button 
                                danger 
                                type="primary"
                                disabled={selectedKeys.length === 0}
                            >
                                Bỏ đối trừ ({selectedKeys.length})
                            </Button>
                        </Popconfirm>
                    </Space>
                </div>
            }
        >
            <div className="misa-flex-col-gap-14 pt-1">
                <div className="misa-filter-box misa-filter-grid-3col">
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
                            placeholder="Chưa có tài khoản từ máy chủ"
                            notFoundContent="Chưa có tài khoản từ máy chủ"
                            options={[]}
                            disabled
                        />
                    </div>
                    <div className="misa-flex-end-center">
                        <Button icon={<ReloadOutlined />} disabled>
                            Lấy dữ liệu
                        </Button>
                    </div>
                </div>

                <div className="misa-table-wrapper">
                    <Table 
                        locale={{ emptyText: 'Backend chưa công bố lịch sử đối trừ.' }}
                        rowSelection={{
                            selectedRowKeys: selectedKeys,
                            onChange: setSelectedKeys
                        }}
                        columns={[
                            {
                                title: 'Chứng từ thanh toán',
                                children: [
                                    { title: 'Loại CT', dataIndex: 'payment_type', key: 'payment_type', width: 110 },
                                    { title: 'Ngày CT', dataIndex: 'payment_date', key: 'payment_date', width: 95, align: 'center' as const },
                                    { title: 'Số CT', dataIndex: 'payment_number', key: 'payment_number', width: 105, render: t => <span className="misa-text-blue-bold">{t}</span> },
                                    { title: 'Số tiền đối trừ', dataIndex: 'payment_offset', key: 'payment_offset', width: 130, align: 'right' as const, render: v => <span>{new Intl.NumberFormat('vi-VN').format(v)} ₫</span> },
                                ]
                            },
                            {
                                title: 'Chứng từ công nợ',
                                children: [
                                    { title: 'Loại CT', dataIndex: 'debt_type', key: 'debt_type', width: 130 },
                                    { title: 'Ngày CT', dataIndex: 'debt_date', key: 'debt_date', width: 95, align: 'center' as const },
                                    { title: 'Số CT / HĐ', dataIndex: 'debt_number', key: 'debt_number', width: 110, render: (t, r) => <span>{t} ({r.invoice_number})</span> },
                                    { title: 'Số tiền đối trừ', dataIndex: 'debt_offset', key: 'debt_offset', width: 130, align: 'right' as const, render: v => <span className="misa-text-primary-bold">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span> },
                                ]
                            }
                        ]}
                        dataSource={offsetHistory}
                        rowKey="id"
                        pagination={false}
                        size="small"
                    />
                </div>
            </div>
        </Modal>
    );
};

export default UnAgainstVoucherModal;
