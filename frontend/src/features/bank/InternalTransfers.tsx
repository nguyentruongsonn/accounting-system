import React, { useState } from 'react';
import { Table, Button, Input, Select, InputNumber, Space, Tag, Popconfirm, Alert } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { 
    PlusOutlined, 
    ReloadOutlined, 
    ExportOutlined, 
    PrinterOutlined, 
    SwapOutlined
} from '@ant-design/icons';
import dayjs from 'dayjs';

interface InternalTransferRecord {
    id: number;
    voucher_number: string;
    posting_date: string;
    voucher_date: string;
    from_account: string;
    from_bank_name: string;
    to_account: string;
    to_bank_name: string;
    reason: string;
    amount: number;
    fee_amount: number;
    creator: string;
    is_posted: boolean;
}

export const InternalTransfers: React.FC = () => {
    const [searchText, setSearchText] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isViewModalOpen, setIsViewModalOpen] = useState(false);
    const [selectedRecord, setSelectedRecord] = useState<InternalTransferRecord | null>(null);

    // Form fields
    // No local voucher may be invented while the server workflow is unavailable.
    // The server must allocate the number when an approved endpoint exists.
    const [voucherNo, setVoucherNo] = useState('');
    const [postingDate] = useState<any>(dayjs());
    const [voucherDate] = useState<any>(dayjs());
    const [fromAcc, setFromAcc] = useState('');
    const [fromBankName, setFromBankName] = useState('');
    const [toAcc, setToAcc] = useState('');
    const [toBankName, setToBankName] = useState('');
    const [reason, setReason] = useState('');
    const [transferAmount, setTransferAmount] = useState<number | null>(null);
    const [transferFee, setTransferFee] = useState<number | null>(null);

    const [transfers] = useState<InternalTransferRecord[]>([]);

    // There is currently no internal-transfer endpoint or bank-account query
    // contract. Do not fabricate tenant bank accounts in the UI.
    const bankOptions: Array<{ account: string; bank: string }> = [];

    const handleCreateTransfer = () => {
        message.info('Backend chưa công bố endpoint chuyển tiền nội bộ; không tạo dữ liệu cục bộ.');
    };

    const handleTogglePost = (_record: InternalTransferRecord) => {
        message.warning('Chưa thể ghi sổ/bỏ ghi sổ: backend chưa công bố workflow chuyển tiền nội bộ.');
    };

    const handleDelete = (_id: number) => {
        message.warning('Chưa thể xóa: backend chưa công bố endpoint chuyển tiền nội bộ.');
    };

    const totalAmountSum = transfers.reduce((sum, t) => sum + t.amount, 0);
    const totalFeeSum = transfers.reduce((sum, t) => sum + t.fee_amount, 0);

    const filteredTransfers = transfers.filter(t => 
        t.voucher_number.toLowerCase().includes(searchText.toLowerCase()) ||
        t.reason.toLowerCase().includes(searchText.toLowerCase()) ||
        t.from_bank_name.toLowerCase().includes(searchText.toLowerCase()) ||
        t.to_bank_name.toLowerCase().includes(searchText.toLowerCase())
    );

    const columns = [
        {
            title: 'Trạng thái',
            dataIndex: 'is_posted',
            key: 'is_posted',
            width: 100,
            align: 'center' as const,
            render: (posted: boolean) => (
                <Tag color={posted ? 'success' : 'default'} className="misa-status-tag">
                    {posted === true ? 'Đã ghi sổ' : posted === false ? 'Bản nháp' : '—'}
                </Tag>
            )
        },
        {
            title: 'Ngày HT',
            dataIndex: 'posting_date',
            key: 'posting_date',
            width: 100,
            align: 'center' as const,
        },
        {
            title: 'Ngày CT',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 100,
            align: 'center' as const,
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 120,
            render: (val: string, r: InternalTransferRecord) => (
                <a 
                    className="misa-code-link"
                    onClick={() => { setSelectedRecord(r); setIsViewModalOpen(true); }}
                >
                    {val}
                </a>
            )
        },
        {
            title: 'Nội dung diễn giải',
            dataIndex: 'reason',
            key: 'reason',
            minWidth: 260,
        },
        {
            title: 'Tài khoản nguồn (Chi)',
            key: 'from_acc',
            width: 210,
            render: (_: any, r: InternalTransferRecord) => (
                <div>
                    <div className="misa-text-semibold">{r.from_account}</div>
                    <div className="apple-muted-text">{r.from_bank_name}</div>
                </div>
            )
        },
        {
            title: 'Tài khoản đích (Thu)',
            key: 'to_acc',
            width: 210,
            render: (_: any, r: InternalTransferRecord) => (
                <div>
                    <div className="misa-text-semibold">{r.to_account}</div>
                    <div className="apple-muted-text">{r.to_bank_name}</div>
                </div>
            )
        },
        {
            title: 'Số tiền chuyển (VND)',
            dataIndex: 'amount',
            key: 'amount',
            width: 150,
            align: 'right' as const,
            render: (v: number) => (
                <span className="misa-amount">
                    {new Intl.NumberFormat('vi-VN').format(v)}
                </span>
            )
        },
        {
            title: 'Phí chuyển khoản',
            dataIndex: 'fee_amount',
            key: 'fee_amount',
            width: 120,
            align: 'right' as const,
            render: (v: number) => (
                <span className="apple-muted-text">
                    {new Intl.NumberFormat('vi-VN').format(v)}
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'actions',
            width: 140,
            align: 'center' as const,
            render: (_: any, r: InternalTransferRecord) => (
                <Space size="small">
                    <Button 
                        type="link" 
                        size="small"
                        onClick={() => handleTogglePost(r)}
                        className="misa-action-link"
                    >
                        {r.is_posted ? 'Bỏ ghi' : 'Ghi sổ'}
                    </Button>
                    <Popconfirm
                        title="Bạn có chắc chắn muốn xóa chứng từ này?"
                        onConfirm={() => handleDelete(r.id)}
                        okText="Xóa"
                        cancelText="Hủy"
                    >
                        <Button type="link" danger size="small" className="misa-action-link-danger">
                            Xóa
                        </Button>
                    </Popconfirm>
                </Space>
            )
        }
    ];

    return (
        <div className="misa-page-layout-p16">
            {/* Header Toolbar */}
            <div className="apple-section-gap">
                <div>
                    <div className="misa-font-18-bold">
                        Chuyển tiền nội bộ (Ngân hàng / Quỹ)
                    </div>
                    <div className="misa-font-13-muted">
                        Quản lý hạch toán chuyển đổi vốn giữa các tài khoản ngân hàng và tiền mặt
                    </div>
                </div>

                <Space>
                    <Button className="misa-btn-tool" icon={<PrinterOutlined />} disabled>In danh sách</Button>
                    <Button className="misa-btn-tool" icon={<ExportOutlined />} disabled>Xuất khẩu Excel</Button>
                    <Button 
                        type="primary" 
                        icon={<PlusOutlined />} 
                        onClick={() => setIsModalOpen(true)}
                        className="misa-btn-primary"
                        disabled
                    >
                        Thêm chuyển tiền nội bộ
                    </Button>
                </Space>
            </div>

            {/* Filter Bar */}
            <div className="misa-filter-subbar" >
                <Input.Search
                    placeholder="Tìm theo số CT, tài khoản, nội dung..."
                    allowClear
                    value={searchText}
                    onChange={e => setSearchText(e.target.value)}
                    className="misa-search-box"
                />
                <div className="misa-flex-center misa-gap-8">
                    <span className="misa-filter-label">Tài khoản chi:</span>
                    <Select
                        defaultValue="all"
                        className="misa-input-w200"
                        options={[
                            { value: 'all', label: 'Tất cả tài khoản' },
                            ...bankOptions.map(b => ({ value: b.account, label: `${b.account} - ${b.bank}` }))
                        ]}
                    />
                </div>
                <Button className="misa-btn-tool" icon={<ReloadOutlined />} onClick={() => setSearchText('')}>Nạp lại</Button>
            </div>

            <Alert
                className="apple-section-gap"
                type="warning"
                showIcon
                message="Chuyển tiền nội bộ chưa khả dụng"
                description="Backend chưa công bố endpoint tạo, ghi sổ, bỏ ghi sổ hoặc truy vấn chuyển tiền nội bộ. Giao diện không hiển thị dữ liệu mẫu và không tự hạch toán."
            />

            {/* Table */}
            <div className="misa-table-card-flex">
                <Table
                    columns={columns}
                    dataSource={filteredTransfers}
                    rowKey="id"
                    pagination={{ pageSize: 10 }}
                    size="small"
                    className="misa-voucher-table"
                    locale={{ emptyText: 'Backend chưa công bố dữ liệu chuyển tiền nội bộ.' }}
                    summary={() => (
                        <Table.Summary fixed>
                            <Table.Summary.Row className="misa-table-summary-row">
                                <Table.Summary.Cell index={0} colSpan={7} className="misa-table-summary-total">
                                    Tổng cộng:
                                </Table.Summary.Cell>
                                <Table.Summary.Cell index={1} className="misa-text-right misa-table-summary-primary">
                                    {new Intl.NumberFormat('vi-VN').format(totalAmountSum)} ₫
                                </Table.Summary.Cell>
                                <Table.Summary.Cell index={2} className="misa-text-right misa-text-bold">
                                    {new Intl.NumberFormat('vi-VN').format(totalFeeSum)} ₫
                                </Table.Summary.Cell>
                                <Table.Summary.Cell index={3} />
                            </Table.Summary.Row>
                        </Table.Summary>
                    )}
                />
            </div>

            {/* Create Transfer Modal */}
            <Modal
                title={
                    <div className="misa-modal-title">
                        <SwapOutlined className="misa-color-blue" />
                        <span>Chứng từ Chuyển tiền nội bộ</span>
                    </div>
                }
                open={isModalOpen}
                onCancel={() => setIsModalOpen(false)}
                width={850}
                onOk={handleCreateTransfer}
                okText="Cất & Ghi sổ"
                cancelText="Hủy bỏ"
                className="misa-voucher-modal"
            >
                <div className="misa-flex-col-gap-14-pt10">
                    <div className="misa-grid-3col">
                        <div>
                            <div className="misa-label-bold">Số chứng từ <span className="misa-text-red">*</span></div>
                            <Input value={voucherNo} onChange={e => setVoucherNo(e.target.value)} className="misa-input" />
                        </div>
                        <div>
                            <div className="misa-label-bold">Ngày hạch toán</div>
                            <Input value={dayjs(postingDate).format('DD/MM/YYYY')} disabled className="misa-input" />
                        </div>
                        <div>
                            <div className="misa-label-bold">Ngày chứng từ</div>
                            <Input value={dayjs(voucherDate).format('DD/MM/YYYY')} disabled className="misa-input" />
                        </div>
                    </div>

                    <div className="misa-grid-2col-card">
                        <div>
                            <div className="misa-label-bold">Tài khoản trích tiền (nguồn)</div>
                            <Select 
                                value={fromAcc}
                                onChange={(acc) => {
                                    setFromAcc(acc);
                                    const b = bankOptions.find(o => o.account === acc);
                                    if (b) setFromBankName(b.bank);
                                }}
                                className="misa-w-full"
                                options={bankOptions.map(b => ({ value: b.account, label: `${b.account} - ${b.bank}` }))}
                            />
                            <div className="apple-muted-text misa-mt-4">
                                Ngân hàng: {fromBankName}
                            </div>
                        </div>

                        <div>
                            <div className="misa-label-bold">Tài khoản nhận tiền (đích)</div>
                            <Select 
                                value={toAcc}
                                onChange={(acc) => {
                                    setToAcc(acc);
                                    const b = bankOptions.find(o => o.account === acc);
                                    if (b) setToBankName(b.bank);
                                }}
                                className="misa-w-full"
                                options={bankOptions.map(b => ({ value: b.account, label: `${b.account} - ${b.bank}` }))}
                            />
                            <div className="apple-muted-text misa-mt-4">
                                Ngân hàng: {toBankName}
                            </div>
                        </div>
                    </div>

                    <div>
                        <div className="misa-label-bold">Nội dung chuyển tiền</div>
                        <Input.TextArea 
                            rows={2}
                            value={reason}
                            onChange={e => setReason(e.target.value)}
                            placeholder="Nhập lý do điều chuyển vốn nội bộ..."
                            className="misa-input"
                        />
                    </div>

                    <div className="misa-grid-2col">
                        <div>
                            <div className="misa-label-bold">Số tiền chuyển (VND) <span className="misa-text-red">*</span></div>
                            <InputNumber
                                className="misa-w-full"
                                value={transferAmount}
                                onChange={(v) => setTransferAmount(Number(v) || 0)}
                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                parser={(v: any) => v.replace(/\$\s?|(,*)/g, '')}
                                size="large"
                            />
                        </div>
                        <div>
                            <div className="misa-label-bold">Phí chuyển khoản (nếu có)</div>
                            <InputNumber
                                className="misa-w-full"
                                value={transferFee}
                                onChange={(v) => setTransferFee(Number(v) || 0)}
                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                parser={(v: any) => v.replace(/\$\s?|(,*)/g, '')}
                                size="large"
                            />
                        </div>
                    </div>
                </div>
            </Modal>

            {/* View Modal */}
            <Modal
                title={`Chi tiết chứng từ ${selectedRecord?.voucher_number || ''}`}
                open={isViewModalOpen}
                onCancel={() => setIsViewModalOpen(false)}
                footer={<Button className="misa-btn-secondary" onClick={() => setIsViewModalOpen(false)}>Đóng</Button>}
                width={650}
                className="misa-voucher-modal"
            >
                {selectedRecord && (
                    <div className="misa-flex-col-gap-12-pt6">
                        <div className="misa-modal-info-box-2col-p14">
                            <div><strong>Số chứng từ:</strong> {selectedRecord.voucher_number}</div>
                            <div><strong>Ngày chứng từ:</strong> {selectedRecord.voucher_date}</div>
                            <div><strong>Từ tài khoản:</strong> {selectedRecord.from_account} ({selectedRecord.from_bank_name})</div>
                            <div><strong>Đến tài khoản:</strong> {selectedRecord.to_account} ({selectedRecord.to_bank_name})</div>
                            <div><strong>Số tiền:</strong> <span className="misa-table-summary-primary">{new Intl.NumberFormat('vi-VN').format(selectedRecord.amount)} ₫</span></div>
                            <div><strong>Phí ngân hàng:</strong> {new Intl.NumberFormat('vi-VN').format(selectedRecord.fee_amount)} ₫</div>
                            <div className="misa-col-span-2"><strong>Nội dung:</strong> {selectedRecord.reason}</div>
                            <div><strong>Người lập:</strong> {selectedRecord.creator}</div>
                            <div><strong>Trạng thái:</strong> {selectedRecord.is_posted === true ? 'Đã ghi sổ' : selectedRecord.is_posted === false ? 'Bản nháp' : '—'}</div>
                        </div>
                    </div>
                )}
            </Modal>
        </div>
    );
};

export default InternalTransfers;
