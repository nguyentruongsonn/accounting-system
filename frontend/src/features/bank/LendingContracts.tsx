import React, { useState } from 'react';
import { Table, Button, Input, Select, InputNumber, DatePicker, Space, Tag, Radio, Tabs, Alert } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { 
    PlusOutlined, 
    ReloadOutlined, 
    ExportOutlined, 
    PrinterOutlined, 
    FileTextOutlined
} from '@ant-design/icons';
import dayjs from 'dayjs';


interface LendingContractRecord {
    id: number;
    contract_number: string;
    sign_date: string;
    disbursement_date: string;
    term_months: number;
    borrower_code: string;
    borrower_name: string;
    credit_limit: number;
    loan_amount: number;
    disbursed_amount: number;
    principal_recovered: number;
    current_balance: number;
    interest_receivable: number;
    interest_collected: number;
    interest_rate: number;
    purpose: string;
    status: 'active' | 'settled' | 'overdue';
    next_principal_date: string;
    next_interest_date: string;
}

export const LendingContracts: React.FC = () => {
    const [searchText, setSearchText] = useState('');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isViewModalOpen, setIsViewModalOpen] = useState(false);
    const [selectedRecord, setSelectedRecord] = useState<LendingContractRecord | null>(null);

    // Form States
    const [contractNo] = useState('');
    const [borrowerCode, setBorrowerCode] = useState('');
    const [, setBorrowerName] = useState('');
    const [loanAmount, setLoanAmount] = useState<number | null>(null);
    const [termMonths, setTermMonths] = useState<number | null>(null);
    const [disbursementDate, setDisbursementDate] = useState<any>(dayjs());
    const [dueDate, setDueDate] = useState<any>(dayjs().add(12, 'month'));
    const [interestRate, setInterestRate] = useState<number | null>(null);
    const [rateType, setRateType] = useState('reducing');
    const [dayBasis, setDayBasis] = useState('365');
    const [adjustMethod, setAdjustMethod] = useState('adjustable');
    const [principalRecovery, setPrincipalRecovery] = useState('monthly');
    const [interestRecovery, setInterestRecovery] = useState('monthly');
    const [receiveBankAcc, setReceiveBankAcc] = useState('');
    const [purpose, setPurpose] = useState('');

    const [contracts] = useState<LendingContractRecord[]>([]);

    const handleOpenCreateModal = () => {
        message.info('Backend hiện chỉ công bố khế ước đi vay; workflow cho vay chưa khả dụng.');
    };

    const handleSaveContract = () => {
        message.info('Backend hiện chỉ công bố khế ước đi vay; không tạo dữ liệu cho vay cục bộ.');
    };

    const columns = [
        {
            title: 'Số khế ước cho vay',
            dataIndex: 'contract_number',
            key: 'contract_number',
            width: 150,
            render: (t: string, r: LendingContractRecord) => (
                <span 
                    className="misa-btn-link-action-bold"
                    onClick={() => {
                        setSelectedRecord(r);
                        setIsViewModalOpen(true);
                    }}
                >
                    {t}
                </span>
            )
        },
        { title: 'Ngày giải ngân', dataIndex: 'disbursement_date', key: 'disbursement_date', width: 110, align: 'center' as const },
        { title: 'Thời hạn (tháng)', dataIndex: 'term_months', key: 'term_months', width: 120, align: 'center' as const },
        { 
            title: 'Đối tượng vay', 
            dataIndex: 'borrower_name', 
            key: 'borrower_name', 
            minWidth: 200,
            render: (name: string, r: LendingContractRecord) => (
                <div>
                    <div className="misa-text-bold">{name}</div>
                    <div className="misa-fs-11 misa-color-muted">{r.borrower_code}</div>
                </div>
            )
        },
        { 
            title: 'Giá trị cho vay', 
            dataIndex: 'loan_amount', 
            key: 'loan_amount', 
            width: 140, 
            align: 'right' as const,
            render: (v: number) => <span className="misa-text-bold">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span>
        },
        { 
            title: 'Nợ gốc đã thu', 
            dataIndex: 'principal_recovered', 
            key: 'principal_recovered', 
            width: 130, 
            align: 'right' as const,
            render: (v: number) => <span className="misa-stat-value-green">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span>
        },
        { 
            title: 'Dư nợ hiện tại', 
            dataIndex: 'current_balance', 
            key: 'current_balance', 
            width: 140, 
            align: 'right' as const,
            render: (v: number) => <span className="misa-stat-value-blue misa-fw-800">{new Intl.NumberFormat('vi-VN').format(v)} ₫</span>
        },
        { 
            title: 'Lãi suất (%/năm)', 
            dataIndex: 'interest_rate', 
            key: 'interest_rate', 
            width: 130, 
            align: 'center' as const,
            render: (v: number) => <Tag color="blue">{v}%</Tag>
        },
        { 
            title: 'Trạng thái', 
            dataIndex: 'status', 
            key: 'status', 
            width: 110, 
            align: 'center' as const,
            render: (s: string) => <Tag color={s === 'active' ? 'green' : (s === 'settled' ? 'default' : 'red')}>{s === 'active' ? 'Đang cho vay' : (s === 'settled' ? 'Đã tất toán' : 'Quá hạn')}</Tag>
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 80,
            align: 'center' as const,
            render: (_: any, r: LendingContractRecord) => (
                <Button
                    type="link"
                    size="small"
                    className="misa-btn-link-action"
                    onClick={() => {
                        setSelectedRecord(r);
                        setIsViewModalOpen(true);
                    }}
                >
                    Xem
                </Button>
            )
        }
    ];

    return (
        <div className="misa-page-layout">
            {/* Toolbar */}
            <div className="misa-toolbar-header">
                <div className="misa-toolbar-group">
                    <Input 
                        placeholder="Tìm kiếm số khế ước, đối tượng vay..." 
                        className="misa-input misa-w-280 misa-border-radius-4" 
                        size="middle"
                        allowClear
                        value={searchText}
                        onChange={e => setSearchText(e.target.value)}
                    />
                </div>

                <div className="misa-toolbar-group-right-gap8">
                    <button 
                        type="button" 
                        className="misa-btn-tool" 
                        title="Làm mới" 
                        disabled
                    >
                        <ReloadOutlined />
                    </button>
                    <button 
                        type="button" 
                        className="misa-btn-tool" 
                        title="Xuất khẩu" 
                        disabled
                    >
                        <ExportOutlined />
                    </button>
                    <Button 
                        type="primary" 
                        icon={<PlusOutlined />}
                        className="misa-btn-primary"
                        onClick={handleOpenCreateModal}
                        disabled
                    >
                        Thêm khế ước cho vay
                    </Button>
                </div>
            </div>

            <Alert
                className="apple-section-gap"
                type="warning"
                showIcon
                message="Workflow cho vay chưa khả dụng"
                description="Backend hiện chỉ công bố khế ước đi vay. Giao diện không dùng endpoint đó cho dữ liệu cho vay và không hiển thị số liệu mẫu."
            />

            {/* Table */}
            <div className="misa-table-card">
                <Table 
                    className="misa-voucher-table"
                    columns={columns}
                    dataSource={contracts}
                    rowKey="id"
                    pagination={false}
                    size="small"
                    locale={{ emptyText: 'Workflow cho vay chưa được backend công bố.' }}
                    summary={() => {
                        const totalLoan = contracts.reduce((acc, c) => acc + c.loan_amount, 0);
                        const totalBalance = contracts.reduce((acc, c) => acc + c.current_balance, 0);
                        return (
                            <Table.Summary fixed>
                                <Table.Summary.Row className="misa-report-header-debit misa-fw-700">
                                    <Table.Summary.Cell index={0} colSpan={4}>
                                        <span>Tổng ({contracts.length} khế ước)</span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={1} align="right">
                                        <span className="misa-text-bold">
                                             {new Intl.NumberFormat('vi-VN').format(totalLoan)} ₫
                                        </span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={2} />
                                    <Table.Summary.Cell index={3} align="right">
                                        <span className="misa-fw-800 misa-color-blue">
                                            {new Intl.NumberFormat('vi-VN').format(totalBalance)} ₫
                                        </span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={4} colSpan={3}></Table.Summary.Cell>
                                </Table.Summary.Row>
                            </Table.Summary>
                        );
                    }}
                />
            </div>

            {/* MISA Modal Thêm Khế Ước Cho Vay */}
            <Modal
                title={
                    <div className="misa-modal-detail-header-20">
                        <div className="misa-flex-center misa-gap-10">
                            <FileTextOutlined className="misa-color-primary misa-fs-20" />
                            <span className="misa-fs-17 misa-fw-700 misa-color-dark">
                                Khế ước cho vay: {contractNo}
                            </span>
                        </div>
                        <div className="misa-flex-center misa-gap-8">
                            <span className="misa-fs-12 misa-color-muted">Giá trị khoản vay:</span>
                            <span className="misa-fs-18 misa-fw-800 misa-color-primary">
                                {loanAmount == null ? '—' : `${new Intl.NumberFormat('vi-VN').format(loanAmount)} ₫`}
                            </span>
                        </div>
                    </div>
                }
                open={isModalOpen}
                onCancel={() => setIsModalOpen(false)}
                width={960}
                footer={
                    <div className="misa-modal-btn-footer-between">
                        <Button onClick={() => setIsModalOpen(false)} className="misa-btn-footer-cancel">Hủy</Button>
                        <Space>
                            <Button icon={<PrinterOutlined />} onClick={() => message.info('Workflow cho vay chưa khả dụng; không thể cất hoặc in chứng từ.')} className="misa-btn-secondary" disabled>Cất và In</Button>
                            <Button 
                                type="primary" 
                                className="misa-btn-primary"
                                onClick={handleSaveContract}
                                disabled
                            >
                                Cất (Ctrl+S)
                            </Button>
                        </Space>
                    </div>
                }
            >
                <div className="misa-flex-col-gap-12-pt6">
                    {/* Header Info Grid */}
                    <div className="misa-grid-3col misa-bg-light misa-p-12 misa-border-radius-6 misa-border-e2e">
                        <div>
                            <div className="misa-field-label misa-mb-4">Số khế ước cho vay *:</div>
                            <Input className="misa-input" value={contractNo} disabled />
                        </div>

                        <div>
                            <div className="misa-field-label misa-mb-4">Đối tượng vay *:</div>
                            <Select 
                                className="misa-w-full"
                                value={borrowerCode}
                                onChange={(val) => {
                                    setBorrowerCode(val);
                                    setBorrowerName('');
                                }}
                                options={[]}
                                notFoundContent="Chưa có đối tượng vay từ máy chủ"
                            />
                        </div>

                        <div>
                            <div className="misa-field-label misa-mb-4">TK hạch toán nợ gốc *:</div>
                            <Select 
                                className="misa-w-full"
                                options={[]}
                                notFoundContent="Chưa có tài khoản từ máy chủ"
                            />
                        </div>

                        <div className="misa-col-span-2">
                            <div className="misa-field-label misa-mb-4">Mục đích cho vay:</div>
                            <Input className="misa-input" value={purpose} onChange={e => setPurpose(e.target.value)} />
                        </div>

                        <div>
                            <div className="misa-field-label misa-mb-4">TK hạch toán lãi vay *:</div>
                            <Select 
                                className="misa-w-full"
                                options={[]}
                                notFoundContent="Chưa có tài khoản từ máy chủ"
                            />
                        </div>
                    </div>

                    {/* 5 Tabs Detail */}
                    <Tabs 
                        defaultActiveKey="1"
                        items={[
                            {
                                key: '1',
                                label: '1. Thông tin giải ngân',
                                children: (
                                    <div className="misa-grid-3col misa-pt-8">
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Giá trị khoản vay *:</div>
                                            <InputNumber 
                                                className="misa-input misa-w-full misa-fw-700 misa-color-primary" 
                                                value={loanAmount} 
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                parser={v => Number(v?.replace(/\$\s?|(,*)/g, '') || 0)}
                                                onChange={v => setLoanAmount(Number(v) || 0)}
                                            />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Thời hạn vay (tháng) *:</div>
                                            <InputNumber className="misa-input misa-w-full" value={termMonths} onChange={v => setTermMonths(Number(v) || 12)} />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Phương thức giải ngân *:</div>
                                            <Select className="misa-w-full" defaultValue="transfer" options={[{ value: 'transfer', label: 'Chuyển khoản' }, { value: 'cash', label: 'Tiền mặt' }]} />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Ngày giải ngân *:</div>
                                            <DatePicker className="misa-w-full" format="DD/MM/YYYY" value={disbursementDate} onChange={setDisbursementDate} />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Ngày đáo hạn *:</div>
                                            <DatePicker className="misa-w-full" format="DD/MM/YYYY" value={dueDate} onChange={setDueDate} />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">TK ngân hàng bên vay:</div>
                                            <Input className="misa-input" placeholder="Nhập số tài khoản thụ hưởng" />
                                        </div>
                                    </div>
                                )
                            },
                            {
                                key: '2',
                                label: '2. Lãi suất',
                                children: (
                                    <div className="misa-flex-col-gap-12 misa-pt-8">
                                        <div className="misa-grid-3col">
                                            <div>
                                                <div className="misa-field-label misa-mb-4">Loại lãi suất:</div>
                                                <Radio.Group value={rateType} onChange={e => setRateType(e.target.value)}>
                                                    <Radio value="reducing">Dư nợ giảm dần</Radio>
                                                    <Radio value="fixed">Dư nợ gốc</Radio>
                                                </Radio.Group>
                                            </div>
                                            <div>
                                                <div className="misa-field-label misa-mb-4">Cơ sở tính lãi ngày:</div>
                                                <Radio.Group value={dayBasis} onChange={e => setDayBasis(e.target.value)}>
                                                    <Radio value="365">Năm / 365</Radio>
                                                    <Radio value="360">Năm / 360</Radio>
                                                </Radio.Group>
                                            </div>
                                            <div>
                                                <div className="misa-field-label misa-mb-4">Phương thức điều chỉnh:</div>
                                                <Radio.Group value={adjustMethod} onChange={e => setAdjustMethod(e.target.value)}>
                                                    <Radio value="adjustable">Có điều chỉnh</Radio>
                                                    <Radio value="fixed">Cố định</Radio>
                                                </Radio.Group>
                                            </div>
                                        </div>

                                        <div className="misa-table-card">
                                            <table className="misa-voucher-table misa-w-full">
                                                <thead>
                                                    <tr>
                                                        <th className="misa-w-40 misa-text-center">#</th>
                                                        <th>Lãi suất (%/năm)</th>
                                                        <th>Lãi suất quá hạn (%/năm)</th>
                                                        <th>Hiệu lực từ ngày</th>
                                                        <th>Ghi chú</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td className="misa-text-center">1</td>
                                                        <td>
                                                            <InputNumber 
                                                                className="misa-table-input misa-w-full" 
                                                                value={interestRate} 
                                                                onChange={v => setInterestRate(Number(v) || 8.5)} 
                                                            />
                                                        </td>
                                                        <td>12.75%</td>
                                                        <td>{disbursementDate?.format('DD/MM/YYYY')}</td>
                                                        <td>Lãi suất trong hạn</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                )
                            },
                            {
                                key: '3',
                                label: '3. Hình thức thu nợ',
                                children: (
                                    <div className="misa-grid-3col misa-pt-8">
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Kỳ hạn thu gốc *:</div>
                                            <Select 
                                                className="misa-w-full" 
                                                value={principalRecovery} 
                                                onChange={setPrincipalRecovery}
                                                options={[
                                                    { value: 'monthly', label: 'Hàng tháng' },
                                                    { value: 'quarterly', label: 'Hàng quý' },
                                                    { value: 'end_of_term', label: 'Cuối kỳ' }
                                                ]}
                                            />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Kỳ hạn thu lãi *:</div>
                                            <Select 
                                                className="misa-w-full" 
                                                value={interestRecovery} 
                                                onChange={setInterestRecovery}
                                                options={[
                                                    { value: 'monthly', label: 'Hàng tháng' },
                                                    { value: 'quarterly', label: 'Hàng quý' },
                                                    { value: 'end_of_term', label: 'Cuối kỳ' }
                                                ]}
                                            />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Chuyển vào tài khoản công ty:</div>
                                            <Select 
                                                className="misa-w-full" 
                                                value={receiveBankAcc} 
                                                onChange={setReceiveBankAcc}
                                                options={[
                                                    { value: '0011001234567', label: '0011001234567 - Vietcombank CN Ba Đình' },
                                                    { value: '1903009876543', label: '1903009876543 - Techcombank CN Hoàn Kiếm' }
                                                ]}
                                            />
                                        </div>
                                    </div>
                                )
                            },
                            {
                                key: '4',
                                label: '4. Thống kê khác',
                                children: (
                                    <div className="misa-grid-3col misa-pt-8">
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Khoản mục chi phí:</div>
                                            <Input className="misa-input" placeholder="KMCP" />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Hợp đồng bán / Dự án:</div>
                                            <Input className="misa-input" placeholder="Mã dự án" />
                                        </div>
                                        <div>
                                            <div className="misa-field-label misa-mb-4">Đơn vị / Phòng ban:</div>
                                            <Input className="misa-input" placeholder="Phòng Kinh doanh" />
                                        </div>
                                    </div>
                                )
                            },
                            {
                                key: '5',
                                label: '5. Đính kèm',
                                children: (
                                    <div className="misa-attachment-dropzone">
                                        <div className="misa-fs-13 misa-color-muted">Kéo thả tài liệu, hợp đồng cho vay đính kèm vào đây (Tối đa 5MB)</div>
                                        <Button size="small" className="misa-mt-8">Chọn tệp từ máy tính</Button>
                                    </div>
                                )
                            }
                        ]}
                    />
                </div>
            </Modal>

            {/* View Modal */}
            <Modal
                title={`Chi tiết Khế ước cho vay: ${selectedRecord?.contract_number}`}
                open={isViewModalOpen}
                onCancel={() => setIsViewModalOpen(false)}
                footer={[<Button key="close" type="primary" onClick={() => setIsViewModalOpen(false)}>Đóng</Button>]}
                width={800}
            >
                {selectedRecord && (
                    <div className="misa-flex-col-gap-12-pt10">
                        <div className="misa-grid-2col-card">
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Đối tượng vay:</div>
                                <div className="misa-fw-700">{selectedRecord.borrower_name} ({selectedRecord.borrower_code})</div>
                            </div>
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Giá trị khoản vay:</div>
                                <div className="misa-fw-800 misa-fs-16 misa-color-primary">
                                    {new Intl.NumberFormat('vi-VN').format(selectedRecord.loan_amount)} ₫
                                </div>
                            </div>
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Ngày giải ngân:</div>
                                <div className="misa-fw-600">{selectedRecord.disbursement_date} (Thời hạn: {selectedRecord.term_months} tháng)</div>
                            </div>
                            <div>
                                <div className="misa-fs-12 misa-color-muted">Dư nợ cho vay hiện tại:</div>
                                <div className="misa-fw-800 misa-color-blue">
                                    {new Intl.NumberFormat('vi-VN').format(selectedRecord.current_balance)} ₫
                                </div>
                            </div>
                            <div className="misa-col-span-2">
                                <div className="misa-fs-12 misa-color-muted">Mục đích cho vay:</div>
                                <div className="misa-fw-600">{selectedRecord.purpose}</div>
                            </div>
                        </div>
                    </div>
                )}
            </Modal>
        </div>
    );
};

export default LendingContracts;
