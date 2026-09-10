import React, { useState } from 'react';
import { Table, Button, Form, Input, InputNumber, Select, DatePicker } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { 
    PlusOutlined, 
    UploadOutlined, 
    ReloadOutlined,
    ExportOutlined,
    QrcodeOutlined,
    InboxOutlined
} from '@ant-design/icons';
import dayjs from 'dayjs';
import { QuickAddContactModal } from '../../components/misa';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';

interface BorrowingContract {
    id: number;
    contract_number: string;
    credit_contract?: string;
    lender_name: string;
    purpose: string;
    amount: number;
    disbursement_date: string;
    maturity_date: string;
    term_months: number;
    paid_principal: number;
    remaining_principal: number;
    status: string;
}

function parseBorrowingCollection(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

const BorrowingContracts: React.FC = () => {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isLenderModalOpen, setIsLenderModalOpen] = useState(false);
    const [activeTab, setActiveTab] = useState('1');
    const [searchText, setSearchText] = useState('');
    const queryClient = useQueryClient();

    const { data: accountPayload = [] } = useQuery({
        queryKey: ['borrowing-contract-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return parseBorrowingCollection(data, 'chart-of-accounts catalogue');
        },
    });
    const accountOptions = accountPayload
        .filter((account: any) => account && (account.code || account.account_code))
        .map((account: any) => ({
            value: account.code || account.account_code,
            label: `${account.code || account.account_code} - ${account.name || account.account_name || ''}`,
        }));

    const { data: contracts = [] } = useQuery<BorrowingContract[]>({
        queryKey: ['borrowing-contracts'],
        queryFn: async () => {
            const { data } = await api.get('/borrowing-contracts');
            return parseBorrowingCollection(data, 'borrowing contracts');
        },
    });

    const [form] = Form.useForm();

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            return api.post('/borrowing-contracts', {
                contract_number: values.contract_number,
                credit_contract: values.credit_contract,
                lender_name: values.lender_name,
                purpose: values.purpose,
                debit_account: values.debit_account || undefined,
                interest_account: values.interest_account || undefined,
                amount: values.amount || 0,
                term: values.term || 12,
                term_unit: values.term_unit || 'Tháng',
                disbursement_date: values.disbursement_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                maturity_date: values.maturity_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                disbursement_method: values.disbursement_method || 'Chuyển khoản vào tài khoản DN',
                recipient_account: values.recipient_account,
                recipient_bank: values.recipient_bank,
                interest_rate: values.interest_rate || 8.5,
                interest_period: values.interest_period || 'Hàng tháng',
                overdue_interest_rate: values.overdue_interest_rate || 12.75,
                status: 'active'
            });
        },
        onSuccess: (response) => {
            if (response?.data?.id === undefined || response?.data?.id === null) {
                message.error('Máy chủ không trả về khế ước đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Thêm khế ước vay thành công!');
            setIsModalOpen(false);
            form.resetFields();
            queryClient.invalidateQueries({ queryKey: ['borrowing-contracts'] });
        },
        onError: () => {
            message.error('Lỗi khi lưu khế ước vay!');
        }
    });

    const handleSave = () => {
        form.validateFields().then(values => {
            mutation.mutate(values);
        });
    };

    const columns = [
        {
            title: 'Số khế ước',
            dataIndex: 'contract_number',
            key: 'contract_number',
            width: 140,
            render: (text: string) => <span className="misa-table-link-bold">{text}</span>
        },
        {
            title: 'Số hợp đồng tín dụng',
            dataIndex: 'credit_contract',
            key: 'credit_contract',
            width: 160,
        },
        {
            title: 'Bên cho vay',
            dataIndex: 'lender_name',
            key: 'lender_name',
            minWidth: 200,
        },
        {
            title: 'Mục đích vay',
            dataIndex: 'purpose',
            key: 'purpose',
            minWidth: 200,
        },
        {
            title: 'Giá trị khoản vay',
            dataIndex: 'amount',
            key: 'amount',
            width: 140,
            align: 'right' as const,
            render: (val: number) => (
                <span className="misa-table-amount-800">
                    {new Intl.NumberFormat('vi-VN').format(val || 0)}
                </span>
            )
        },
        {
            title: 'Ngày giải ngân',
            dataIndex: 'disbursement_date',
            key: 'disbursement_date',
            width: 120,
            align: 'center' as const,
            render: (date: string) => date ? dayjs(date).format('DD/MM/YYYY') : ''
        },
        {
            title: 'Ngày đáo hạn',
            dataIndex: 'maturity_date',
            key: 'maturity_date',
            width: 120,
            align: 'center' as const,
            render: (date: string) => date ? dayjs(date).format('DD/MM/YYYY') : ''
        },
        {
            title: 'Đã trả gốc',
            dataIndex: 'paid_principal',
            key: 'paid_principal',
            width: 130,
            align: 'right' as const,
            render: (val: number) => (
                <span className="misa-table-amount-dark">
                    {new Intl.NumberFormat('vi-VN').format(val || 0)}
                </span>
            )
        },
        {
            title: 'Còn lại',
            dataIndex: 'remaining_principal',
            key: 'remaining_principal',
            width: 130,
            align: 'right' as const,
            render: (val: number) => (
                <span className="misa-table-summary-primary">
                    {new Intl.NumberFormat('vi-VN').format(val || 0)}
                </span>
            )
        },
        {
            title: 'Tình trạng',
            dataIndex: 'status',
            key: 'status',
            width: 120,
            align: 'center' as const,
            render: (status: string) => (
                <span className={status === 'active' ? 'misa-status-tag-green-bold' : 'misa-badge-draft'}>
                    {status === 'active' ? 'Đang vay' : 'Đã tất toán'}
                </span>
            )
        }
    ];

    const filteredData = contracts.filter(c => 
        c.contract_number?.toLowerCase().includes(searchText.toLowerCase()) ||
        c.lender_name?.toLowerCase().includes(searchText.toLowerCase()) ||
        c.purpose?.toLowerCase().includes(searchText.toLowerCase())
    );

    return (
        <div className="misa-page-layout-p16">
            {/* Top Toolbar */}
            <div className="apple-section-gap">
                <div>
                    <div className="misa-font-18-bold">Khế ước vay</div>
                    <div className="misa-font-13-muted">Theo dõi danh sách các khế ước vay vốn ngân hàng, tổ chức tín dụng</div>
                </div>

                <div className="misa-flex-center misa-gap-8">
                    <Button className="misa-btn-tool" icon={<UploadOutlined />} disabled title="Chưa có API nhập khế ước từ Excel">Nhập từ Excel (chưa khả dụng)</Button>
                    <Button className="misa-btn-tool" icon={<ExportOutlined />} disabled title="Chưa có API xuất khế ước có delivery/audit evidence">Xuất khẩu (chưa khả dụng)</Button>
                    <Button 
                        type="primary" 
                        icon={<PlusOutlined />} 
                        onClick={() => setIsModalOpen(true)}
                        className="misa-btn-primary"
                    >
                        Thêm khế ước vay
                    </Button>
                </div>
            </div>

            {/* Filter Bar */}
            <div className="misa-filter-subbar" >
                <Input.Search
                    placeholder="Tìm theo số khế ước, bên cho vay, mục đích..."
                    allowClear
                    value={searchText}
                    onChange={e => setSearchText(e.target.value)}
                    className="misa-search-box"
                />
                <Button className="misa-btn-tool" icon={<ReloadOutlined />} onClick={() => queryClient.invalidateQueries({ queryKey: ['borrowing-contracts'] })}>
                    Nạp lại
                </Button>
            </div>

            {/* Table */}
            <div className="misa-table-card-flex">
                <Table 
                    columns={columns} 
                    dataSource={filteredData} 
                    rowKey="id"
                    pagination={{ pageSize: 15 }}
                    size="small"
                    className="misa-voucher-table"
                />
            </div>

            {/* Modal Khế ước vay 1:1 theo screenshot MISA */}
            <Modal
                title={
                    <div className="misa-contract-custom-header">
                        <div className="misa-flex-center misa-gap-8">
                            <span className="misa-font-16-bold">Khế ước vay</span>
                            <QrcodeOutlined className="misa-header-qrcode" />
                        </div>
                    </div>
                }
                open={isModalOpen}
                onCancel={() => setIsModalOpen(false)}
                width={1000}
                className="misa-voucher-modal"
                footer={
                    <div className="misa-modal-btn-footer-between">
                        <Button onClick={() => setIsModalOpen(false)} className="misa-btn-footer-cancel">
                            Hủy
                        </Button>
                        <div className="misa-flex-center misa-gap-8">
                            <Button onClick={handleSave} className="misa-btn-footer-save">
                                Cất
                            </Button>
                            <Button type="primary" onClick={handleSave} className="misa-btn-footer-save-add">
                                Cất và Thêm
                            </Button>
                        </div>
                    </div>
                }
            >
                <Form form={form} layout="vertical" className="misa-modal-scroll-body">
                    {/* Header Info Grid */}
                    <div className="misa-grid-12col-gap-16">
                        {/* Cột Trái 8/12 */}
                        <div className="misa-col-span-8 misa-flex-col-gap-6">
                            <div className="misa-grid-2col-gap-16">
                                <div>
                                    <div className="misa-field-label">Số khế ước <span className="misa-text-red">*</span></div>
                                    <Form.Item name="contract_number" noStyle rules={[{ required: true, message: 'Nhập số khế ước' }]}>
                                        <Input className="misa-input" placeholder="Ví dụ: KUV01" />
                                    </Form.Item>
                                </div>
                                <div>
                                    <div className="misa-field-label">Hợp đồng tín dụng</div>
                                    <Form.Item name="credit_contract" noStyle>
                                        <Select 
                                            className="misa-input misa-w-full" 
                                            placeholder="Chọn HĐ tín dụng"
                                            options={[]}
                                            notFoundContent="Chưa có hợp đồng tín dụng từ máy chủ"
                                        />
                                    </Form.Item>
                                </div>
                            </div>

                            <div>
                                <div className="misa-field-label">Bên cho vay <span className="misa-text-red">*</span></div>
                                <div className="misa-flex-center misa-gap-6">
                                    <Form.Item name="lender_name" noStyle rules={[{ required: true, message: 'Chọn bên cho vay' }]}>
                                        <Select 
                                            className="misa-input misa-flex-1"
                                            showSearch
                                            placeholder="Chọn nhà cung cấp / ngân hàng"
                                            options={[]}
                                            notFoundContent="Chưa có bên cho vay từ máy chủ"
                                        />
                                    </Form.Item>
                                    <button 
                                        type="button" 
                                        className="misa-btn-plus-sq"
                                        onClick={() => setIsLenderModalOpen(true)}
                                    >
                                        <PlusOutlined className="misa-color-primary" />
                                    </button>
                                </div>
                            </div>

                            <div>
                                <div className="misa-field-label">Mục đích vay <span className="misa-text-red">*</span></div>
                                <Form.Item name="purpose" noStyle rules={[{ required: true, message: 'Nhập mục đích vay' }]}>
                                    <Input className="misa-input" placeholder="Mục đích sử dụng vốn vay..." />
                                </Form.Item>
                            </div>
                        </div>

                        {/* Cột Phải 4/12: Định khoản */}
                        <div className="misa-col-span-4 misa-flex-col-gap-6 misa-border-l misa-pl-24">
                            <div>
                                <div className="misa-field-label">Tài khoản vay</div>
                                <Form.Item name="debit_account" noStyle rules={[{ required: true, message: 'Chọn tài khoản vay từ danh mục tài khoản của đơn vị' }]}>
                                    <Select 
                                        className="misa-input misa-w-full"
                                        options={accountOptions}
                                        notFoundContent="Chưa có tài khoản từ máy chủ"
                                    />
                                </Form.Item>
                            </div>

                            <div>
                                <div className="misa-field-label">Tài khoản chi phí lãi</div>
                                <Form.Item name="interest_account" noStyle rules={[{ required: true, message: 'Chọn tài khoản chi phí lãi từ danh mục tài khoản của đơn vị' }]}>
                                    <Select 
                                        className="misa-input misa-w-full"
                                        options={accountOptions}
                                        notFoundContent="Chưa có tài khoản từ máy chủ"
                                    />
                                </Form.Item>
                            </div>
                        </div>
                    </div>

                    {/* Sub-tabs Header */}
                    <div className="misa-flex-center misa-gap-6 misa-border-b" >
                        {[
                            { key: '1', label: 'Thông tin giải ngân' },
                            { key: '2', label: 'Lãi suất' },
                            { key: '3', label: 'Hình thức trả nợ' },
                            { key: '4', label: 'Thống kê khác' },
                            { key: '5', label: 'Đính kèm' }
                        ].map(tab => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setActiveTab(tab.key)}
                                className={`misa-tab-btn-header ${activeTab === tab.key ? 'active' : 'inactive'}`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    {/* Tab 1: Thông tin giải ngân */}
                    {activeTab === '1' && (
                        <div className="misa-flex-col-gap-12-pt6">
                            <div className="misa-w-280">
                                <div className="misa-field-label">Giá trị khoản vay <span className="misa-text-red">*</span></div>
                                <Form.Item name="amount" noStyle initialValue={0}>
                                    <InputNumber 
                                        className="misa-input misa-w-full misa-text-right misa-text-bold"
                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                    />
                                </Form.Item>
                            </div>

                            <div>
                                <div className="misa-field-label">Thời hạn vay <span className="misa-text-red">*</span></div>
                                <div className="misa-flex-center misa-gap-10 misa-w-280">
                                    <Form.Item name="term" noStyle initialValue={12}>
                                        <InputNumber className="misa-input misa-w-140" />
                                    </Form.Item>
                                    <Form.Item name="term_unit" noStyle initialValue="Tháng">
                                        <Select className="misa-input misa-w-130">
                                            <Select.Option value="Tháng">Tháng</Select.Option>
                                            <Select.Option value="Năm">Năm</Select.Option>
                                            <Select.Option value="Ngày">Ngày</Select.Option>
                                        </Select>
                                    </Form.Item>
                                </div>
                            </div>

                            <div className="misa-flex-center misa-gap-20">
                                <div className="misa-w-140">
                                    <div className="misa-field-label">Ngày giải ngân <span className="misa-text-red">*</span></div>
                                    <Form.Item name="disbursement_date" noStyle initialValue={dayjs()}>
                                        <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                    </Form.Item>
                                </div>
                                <div className="misa-w-140">
                                    <div className="misa-field-label">Ngày đáo hạn <span className="misa-text-red">*</span></div>
                                    <Form.Item name="maturity_date" noStyle initialValue={dayjs().add(12, 'month')}>
                                        <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                    </Form.Item>
                                </div>
                            </div>

                            <div className="misa-grid-12col-gap-16">
                                <div className="misa-col-span-3">
                                    <div className="misa-field-label">Phương thức giải ngân <span className="misa-text-red">*</span></div>
                                    <Form.Item name="disbursement_method" noStyle initialValue="Chuyển khoản vào tài khoản DN">
                                        <Select className="misa-input misa-w-full">
                                            <Select.Option value="Chuyển khoản vào tài khoản DN">Chuyển khoản vào tài khoản DN</Select.Option>
                                            <Select.Option value="Tiền mặt">Tiền mặt</Select.Option>
                                        </Select>
                                    </Form.Item>
                                </div>
                                <div className="misa-col-span-3">
                                    <div className="misa-field-label">TK thụ hưởng</div>
                                    <Form.Item name="recipient_account" noStyle>
                                        <Input className="misa-input" placeholder="Số TK ngân hàng" />
                                    </Form.Item>
                                </div>
                                <div className="misa-col-span-6">
                                    <div className="misa-field-label">Tên ngân hàng</div>
                                    <Form.Item name="recipient_bank" noStyle>
                                        <Input className="misa-input" placeholder="Tên ngân hàng thụ hưởng" />
                                    </Form.Item>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Tab 2: Lãi suất */}
                    {activeTab === '2' && (
                        <div className="misa-grid-3col-pt8">
                            <div>
                                <div className="misa-field-label">Lãi suất (% / năm)</div>
                                <Form.Item name="interest_rate" noStyle initialValue={8.5}>
                                    <InputNumber className="misa-input misa-w-full" />
                                </Form.Item>
                            </div>
                            <div>
                                <div className="misa-field-label">Kỳ tính lãi</div>
                                <Form.Item name="interest_period" noStyle initialValue="Hàng tháng">
                                    <Select className="misa-input misa-w-full">
                                        <Select.Option value="Hàng tháng">Hàng tháng</Select.Option>
                                        <Select.Option value="Hàng quý">Hàng quý</Select.Option>
                                        <Select.Option value="Cuối kỳ">Cuối kỳ</Select.Option>
                                    </Select>
                                </Form.Item>
                            </div>
                            <div>
                                <div className="misa-field-label">Lãi suất quá hạn (% / năm)</div>
                                <Form.Item name="overdue_interest_rate" noStyle initialValue={12.75}>
                                    <InputNumber className="misa-input misa-w-full" />
                                </Form.Item>
                            </div>
                        </div>
                    )}

                    {/* Tab 5: Đính kèm */}
                    {activeTab === '5' && (
                        <div className="misa-upload-box-dashed">
                            <InboxOutlined className="misa-stat-icon-bank-blue misa-upload-icon" />
                            <div className="misa-upload-title">Kéo và thả tệp đính kèm khế ước vào đây (PDF, Word, Ảnh)</div>
                            <div className="misa-upload-hint-sm">Dung lượng tối đa 10MB</div>
                        </div>
                    )}
                </Form>
            </Modal>

            {/* Quick Add Lender Modal */}
            <QuickAddContactModal 
                open={isLenderModalOpen}
                onCancel={() => setIsLenderModalOpen(false)}
                contactType="supplier"
                onSuccess={(newContact) => {
                    form.setFieldsValue({ lender_name: newContact.name });
                }}
            />
        </div>
    );
};

export default BorrowingContracts;
