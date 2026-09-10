import React, { useState, useEffect, useCallback } from 'react';
import { Alert, Button, ConfigProvider, Form, Input, InputNumber, Select, DatePicker, Switch, Tag, Dropdown } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import {
    PlusOutlined,
    DeleteOutlined,
    DownOutlined,
    SettingOutlined,
    ReloadOutlined,
    QrcodeOutlined,
    CalculatorOutlined,
    CloseOutlined,
    LinkOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import {
    MisaMasterCard,
    MisaGridActionFooter,
    MisaTableSummaryBar,
    MisaTotalCard,
    MisaUploadBox,
    QuickAddContactModal,
    QuickAddEmployeeModal,
    ReferenceVoucherModal,
    MultiColumnContactSelect,
    AccountSelect,
    useVoucherShortcuts,
    useVoucherTotals,
    QuickAddReasonModal,
    BankVoucherPrintModal
} from '../../components/misa';
import { BANK_ACCOUNTS_ENDPOINT, readBankAccountsResponse } from './bankAccountData';

interface BankReceiptLine {
    key?: string;
    description?: string;
    debit_account?: string;
    credit_account?: string;
    amount?: number;
    contact_id?: string;
    contact_name?: string;
}

function parseCollectionResponse(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

export const bankReceiptVoucherTypes = [
    { value: '1. Thu tiền khách hàng (không theo hóa đơn)', label: '1. Thu tiền khách hàng (không theo hóa đơn)', reason: 'Thu tiền gửi của khách hàng' },
    { value: '2. Thu hoàn ứng nhân viên', label: '2. Thu hoàn ứng nhân viên', reason: 'Thu hoàn ứng tiền gửi của nhân viên' },
    { value: '3. Thu hoàn thuế GTGT', label: '3. Thu hoàn thuế GTGT', reason: 'Thu hoàn thuế GTGT vào TK ngân hàng' },
    { value: '4. Thu lãi đầu tư, tiền gửi', label: '4. Thu lãi đầu tư, tiền gửi', reason: 'Thu lãi tiền gửi ngân hàng' },
    { value: '5. Thu hồi nợ cho vay', label: '5. Thu hồi nợ cho vay', reason: 'Thu hồi nợ cho vay qua ngân hàng' },
    { value: '6. Rút tiền gửi về nhập quỹ', label: '6. Rút tiền gửi về nhập quỹ', reason: 'Rút tiền gửi ngân hàng về nhập quỹ tiền mặt' },
    { value: '7. Thu khác', label: '7. Thu khác', reason: 'Thu tiền gửi khác' },
];

export const BankReceipts: React.FC = () => {
    const [customBankReceiptVoucherTypes, setCustomBankReceiptVoucherTypes] = useState<any[]>(bankReceiptVoucherTypes);
    const [isReasonModalVisible, setIsReasonModalVisible] = useState(false);
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [isCustomerModalVisible, setIsCustomerModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [isRefModalVisible, setIsRefModalVisible] = useState(false);
    const [referencedVouchers, setReferencedVouchers] = useState<any[]>([]);
    const [showAccounts, setShowAccounts] = useState(true);
    const [isPrintModalVisible, setIsPrintModalVisible] = useState(false);
    const [printVoucherData, setPrintVoucherData] = useState<any>(null);

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const lines: BankReceiptLine[] = Form.useWatch('lines', form) || [];
    const totals = useVoucherTotals(lines);
    const voucherNumber = Form.useWatch('voucher_number', form) || '—';

    const { data: bankAccounts = [], isError: bankAccountsError, isLoading: bankAccountsLoading } = useQuery({
        queryKey: ['bank-accounts'],
        queryFn: async () => {
            const { data } = await api.get(BANK_ACCOUNTS_ENDPOINT);
            return readBankAccountsResponse(data);
        },
    });

    const { data: customers = [] } = useQuery({
        queryKey: ['customers'],
        queryFn: async () => {
            const { data } = await api.get('/master/customers');
            return parseCollectionResponse(data, 'customer catalogue');
        },
    });

    const { data: employees = [] } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parseCollectionResponse(data, 'employee catalogue');
        },
    });

    const { data: chartOfAccounts = [] } = useQuery({
        queryKey: ['chart-of-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return parseCollectionResponse(data, 'chart-of-accounts catalogue');
        },
    });

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const safeLines = (values.lines || []).map((l: any) => ({
                description: l.description,
                debit_account: l.debit_account,
                credit_account: l.credit_account,
                amount: l.amount || 0,
                line_contact_id: l.contact_id,
                line_contact_name: l.contact_name,
                invoice_id: l.invoice_id,
                bank_account_id: l.bank_account_id,
            }));

            return api.post('/bank/receipts', {
                bank_account_id: values.bank_account_id,
                contact_type: 'customer',
                contact_id: values.contact_id,
                contact_name: customers?.find((c: any) => c.id === values.contact_id)?.name || values.contact_name,
                reason: values.reason,
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                posting_date: values.posting_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                lines: safeLines
            });
        },
        onSuccess: (response: any) => {
            const persistedReceipt = response?.data?.data ?? response?.data;
            if (!persistedReceipt || persistedReceipt.id === undefined || persistedReceipt.id === null) {
                message.error('Máy chủ không trả về phiếu thu tiền gửi đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Lưu chứng từ Thu tiền gửi thành công!');
            setIsModalVisible(false);
            queryClient.invalidateQueries({ queryKey: ['bank-receipts'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra!');
        }
    });

    const handleSaveForm = (andNew = false, andPrint = false) => {
        form.validateFields().then(values => {
            if (bankAccountsError || bankAccounts.length === 0) {
                message.error('Chưa có tài khoản ngân hàng hợp lệ của đơn vị. Không thể lập chứng từ thu tiền gửi.');
                return;
            }
            if (!values.contact_id && !values.contact_name) {
                message.error('Vui lòng chọn hoặc nhập đối tượng nộp tiền!');
                return;
            }
            mutation.mutate(values);
            if (andPrint) {
                const selectedAcc = bankAccounts.find((b: any) => b.id === values.bank_account_id);
                setPrintVoucherData({
                    voucher_number: values.voucher_number,
                    voucher_date: values.voucher_date,
                    posting_date: values.posting_date,
                    payer_name: values.payer_name || values.contact_name,
                    payer_address: values.payer_address,
                    payer_bank_account: values.payer_bank_account,
                    bank_account_number: selectedAcc?.account_number,
                    bank_name: selectedAcc?.bank_name,
                    description: values.reason,
                    total_amount: totals.grandTotal || totals.subTotal || 0,
                    lines: values.lines
                });
                setIsPrintModalVisible(true);
            }
            if (andNew) handleOpenModal();
        }).catch(() => {
            message.error('Vui lòng kiểm tra lại các trường thông tin bắt buộc (màu đỏ)!');
        });
    };

    const handleAddLine = () => {
        const currentLines = form.getFieldValue('lines') || [];
        form.setFieldsValue({
            lines: [
                ...currentLines,
                {
                    key: `${Date.now()}`,
                    description: form.getFieldValue('reason') || 'Thu tiền gửi của ',
                    amount: 0,
                    contact_id: form.getFieldValue('contact_id') || undefined,
                    contact_name: form.getFieldValue('contact_name') || undefined
                }
            ]
        });
    };

    useVoucherShortcuts({
        onSave: () => handleSaveForm(false, false),
        onSaveAndNew: () => handleSaveForm(true, false),
        onPrint: () => handleSaveForm(false, true),
        onAddLine: handleAddLine,
        onClose: () => {
            if (!isCustomerModalVisible && !isEmployeeModalVisible && !isRefModalVisible) {
                setIsModalVisible(false);
            }
        },
        enabled: isModalVisible
    });

    const handleOpenModal = useCallback(async () => {
        form.resetFields();
        let nextVoucherNumber = '';
        try {
            const { data } = await api.get('/bank/receipts/next-code');
            nextVoucherNumber = data?.voucher_number || data?.data?.code || data?.data?.next_code || data?.code || '';
        } catch {
            // The server may deny code preview; leave the field blank and let validation stop submission.
        }
        form.setFieldsValue({
            voucher_type: '7. Thu khác',
            voucher_number: nextVoucherNumber,
            bank_account_id: undefined,
            bank_name: undefined,
            voucher_date: dayjs(),
            posting_date: dayjs(),
            reason: 'Thu tiền gửi của ',
            lines: [{ description: 'Thu tiền gửi của ' }]
        });
        setIsModalVisible(true);
    }, [form, bankAccounts]);

    useEffect(() => {
        const handleOpen = () => handleOpenModal();
        window.addEventListener('open-bank-receipt', handleOpen);
        return () => window.removeEventListener('open-bank-receipt', handleOpen);
    }, [handleOpenModal]);

    return (
        <>
            <Modal
                title={
                    <div className="misa-modal-custom-header">
                        <div className="misa-modal-header-left">
                            <div
                                className="misa-refresh-btn"
                                onClick={() => {
                                    void api.get('/bank/receipts/next-code').then(({ data }) => {
                                        const nextCode = data?.voucher_number || data?.data?.code || data?.data?.next_code || data?.code || '';
                                        if (nextCode) form.setFieldValue('voucher_number', nextCode);
                                    }).catch(() => message.warning('Không lấy được số chứng từ từ máy chủ.'));
                                }}
                            >
                                <ReloadOutlined />
                            </div>
                            <span className="misa-modal-header-title">
                                Thu tiền gửi {voucherNumber}
                            </span>
                            <div className="misa-input-group">
                                <Form.Item name="voucher_type" noStyle initialValue="7. Thu khác">
                                    <Select
                                        variant="borderless"
                                        className="misa-input-w260 misa-text-semibold"
                                        popupMatchSelectWidth={false}
                                        options={customBankReceiptVoucherTypes.map(t => ({ value: t.value, label: t.value }))}
                                        onChange={(val) => {
                                            const matched = customBankReceiptVoucherTypes.find(t => t.value === val);
                                            if (matched) {
                                                form.setFieldsValue({
                                                    reason: matched.reason + (form.getFieldValue('contact_name') || '')
                                                });
                                                const curLines = form.getFieldValue('lines') || [];
                                                if (curLines.length > 0) {
                                                    form.setFieldsValue({
                                                        lines: curLines.map((l: any) => ({
                                                            ...l,
                                                            description: matched.reason + (form.getFieldValue('contact_name') || '')
                                                        }))
                                                    });
                                                }
                                            }
                                        }}
                                    />
                                </Form.Item>
                                <button
                                    type="button"
                                    className="misa-plus-btn"
                                    title="Thêm tài khoản ngầm định"
                                    onClick={() => setIsReasonModalVisible(true)}
                                >
                                    <PlusOutlined />
                                </button>
                            </div>
                        </div>
                        <div className="misa-modal-header-right">
                            <button type="button" className="misa-icon-btn" title="Máy tính"><CalculatorOutlined /></button>
                            <button type="button" className="misa-icon-btn" title="Thiết lập"><SettingOutlined /></button>
                            <button
                                type="button"
                                className="misa-modal-close-btn"
                                title="Đóng (Esc)"
                                onClick={() => setIsModalVisible(false)}
                            >
                                <CloseOutlined />
                            </button>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                footer={
                    <ConfigProvider componentSize="small">
                        <div className="misa-modal-footer-container">
                            <div className="misa-flex-center misa-gap-20">
                                <div className="misa-flex-center misa-gap-8">
                                    <Switch
                                        size="small"
                                        checked={showAccounts}
                                        onChange={setShowAccounts}
                                    />
                                    <span className="misa-text-semibold">Hiển thị tài khoản</span>
                                </div>
                            </div>

                            <div className="misa-flex-center misa-gap-8">
                                <Button
                                    onClick={() => setIsModalVisible(false)}
                                    className="misa-btn-footer-secondary"
                                >
                                    Hủy
                                </Button>
                                <Button
                                    onClick={() => handleSaveForm(false, false)}
                                    loading={mutation.isPending}
                                    disabled={bankAccountsError || bankAccounts.length === 0}
                                    className="misa-btn-footer-save"
                                >
                                    Cất
                                </Button>
                                <Dropdown
                                    trigger={['click']}
                                    menu={{
                                        items: [
                                            { key: 'add-new', label: 'Cất và Thêm mới' },
                                            { key: 'close', label: 'Cất và Đóng' }
                                        ],
                                        onClick: ({ key }: { key: string }) => {
                                            if (key === 'add-new') handleSaveForm(true, false);
                                            else handleSaveForm(false, false);
                                        }
                                    }}
                                >
                                    <Button
                                        type="primary"
                                        onClick={() => handleSaveForm(false, true)}
                                        loading={mutation.isPending}
                                        disabled={bankAccountsError || bankAccounts.length === 0}
                                        className="misa-btn-footer-primary"
                                    >
                                        <span>Cất và In</span>
                                        <DownOutlined />
                                    </Button>
                                </Dropdown>
                            </div>
                        </div>
                    </ConfigProvider>
                }
                closable={false}
                centered
                className="misa-voucher-modal"
            >
                <Form form={form} layout="vertical" onFinish={() => handleSaveForm(false, false)} size="small" className="misa-form-flex-col">
                    {(bankAccountsError || bankAccounts.length === 0) && (
                        <Alert
                            type={bankAccountsError ? 'error' : 'warning'}
                            showIcon
                            message={bankAccountsError ? 'Không tải được tài khoản ngân hàng' : 'Đơn vị chưa có tài khoản ngân hàng'}
                            description="Chứng từ thu tiền gửi chỉ được lập khi máy chủ cung cấp tài khoản thuộc đơn vị hiện tại. Hãy tải lại hoặc khai báo tài khoản trước khi lưu."
                        />
                    )}
                    {/* Master Card */}
                    <MisaMasterCard>
                        <MisaMasterCard.Left>
                            <MisaMasterCard.FormGrid>
                                {/* Nộp vào TK */}
                                <div className="misa-col-5">
                                    <div className="misa-field-label">Nộp vào TK</div>
                                    <div className="misa-input-group">
                                        <Form.Item name="bank_account_id" noStyle rules={[{ required: true, message: 'Vui lòng chọn tài khoản ngân hàng' }]}>
                                            <Select
                                                loading={bankAccountsLoading}
                                                disabled={bankAccountsError || bankAccounts.length === 0}
                                                variant="borderless"
                                                className="misa-w-full"
                                                options={bankAccounts?.map((b: any) => ({ value: b.id, label: `${b.account_number} - ${b.bank_name}` }))}
                                                onChange={(val) => {
                                                    const b = bankAccounts?.find((x: any) => x.id === val);
                                                    if (b) form.setFieldsValue({ bank_name: b.bank_name });
                                                }}
                                            />
                                        </Form.Item>
                                    </div>
                                </div>
                                <div className="misa-col-7">
                                    <div className="misa-field-label">Ngân hàng</div>
                                    <Form.Item name="bank_name" noStyle>
                                        <Input className="misa-input" placeholder="Tên ngân hàng" disabled />
                                    </Form.Item>
                                </div>

                                {/* Mã đối tượng & Tên đối tượng */}
                                <div className="misa-col-5">
                                    <div className="misa-field-label">Mã đối tượng</div>
                                    <Form.Item name="contact_id" noStyle>
                                        <MultiColumnContactSelect
                                            placeholder="Chọn khách hàng / đối tượng..."
                                            options={customers?.map((c: any) => ({
                                                id: c.id,
                                                code: c.code,
                                                name: c.name,
                                                tax_code: c.tax_code,
                                                address: c.address,
                                                phone: c.phone,
                                                type: 'customer'
                                            }))}
                                            value={form.getFieldValue('contact_id')}
                                            onChange={(val, item) => {
                                                form.setFieldsValue({
                                                    contact_id: val,
                                                    contact_name: item?.name || '',
                                                    payer_name: item?.contact_person || item?.name || '',
                                                    payer_address: item?.address || '',
                                                    reason: `Thu tiền gửi của ${item?.name || ''}`
                                                });
                                                const curLines = form.getFieldValue('lines') || [];
                                                form.setFieldsValue({
                                                    lines: curLines.map((l: any) => ({
                                                        ...l,
                                                        description: `Thu tiền gửi của ${item?.name || ''}`,
                                                        contact_id: item?.code || '',
                                                        contact_name: item?.name || ''
                                                    }))
                                                });
                                            }}
                                            onQuickAdd={() => setIsCustomerModalVisible(true)}
                                        />
                                    </Form.Item>
                                </div>
                                <div className="misa-col-7">
                                    <div className="misa-field-label">Tên đối tượng</div>
                                    <Form.Item name="contact_name" noStyle>
                                        <Input className="misa-input" placeholder="Tên khách hàng / người nộp" />
                                    </Form.Item>
                                </div>

                                {/* Người nộp */}
                                <div className="misa-col-5">
                                    <div className="misa-field-label">Người nộp</div>
                                    <Form.Item name="payer_name" noStyle>
                                        <Input className="misa-input" placeholder="Họ và tên người nộp" />
                                    </Form.Item>
                                </div>

                                {/* Địa chỉ */}
                                <div className="misa-col-7">
                                    <div className="misa-field-label">Địa chỉ</div>
                                    <Form.Item name="payer_address" noStyle>
                                        <Input className="misa-input" placeholder="Địa chỉ chi tiết" />
                                    </Form.Item>
                                </div>

                                {/* Lý do thu */}
                                <div className="misa-col-5">
                                    <div className="misa-field-label">Lý do thu</div>
                                    <Form.Item name="reason" noStyle>
                                        <Input
                                            className="misa-input"
                                            suffix={<QrcodeOutlined className="misa-header-icon-qrcode" />}
                                            placeholder="Nội dung thu tiền gửi"
                                            onChange={(e) => {
                                                const val = e.target.value;
                                                const curLines = form.getFieldValue('lines') || [];
                                                form.setFieldsValue({
                                                    lines: curLines.map((l: any) => ({ ...l, description: val }))
                                                });
                                            }}
                                        />
                                    </Form.Item>
                                </div>

                                {/* Nhân viên thu */}
                                <div className="misa-col-5">
                                    <div className="misa-field-label">Nhân viên thu</div>
                                    <div className="misa-input-group">
                                        <Form.Item name="employee_id" noStyle>
                                            <Select
                                                allowClear
                                                variant="borderless"
                                                placeholder="Chọn nhân viên"
                                                className="misa-w-full"
                                                options={employees?.map((e: any) => ({
                                                    value: e.id || e.code,
                                                    label: `${e.code} - ${e.name}`
                                                }))}
                                            />
                                        </Form.Item>
                                        <button
                                            type="button"
                                            className="misa-plus-btn"
                                            title="Thêm nhanh"
                                            onClick={() => setIsEmployeeModalVisible(true)}
                                        >
                                            <PlusOutlined />
                                        </button>
                                    </div>
                                </div>

                                {/* Kèm theo */}
                                <div className="misa-col-2">
                                    <div className="misa-field-label">Kèm theo</div>
                                    <div className="misa-flex-center misa-gap-6">
                                        <Form.Item name="attached_docs" noStyle>
                                            <Input className="misa-input misa-input-w55" placeholder="SL" />
                                        </Form.Item>
                                        <span className="misa-unit-label">chứng từ gốc</span>
                                    </div>
                                </div>
                            </MisaMasterCard.FormGrid>

                            {/* Tham chiếu link */}
                            <div className="misa-ref-chip-container">
                                <span className="misa-ref-label">Tham chiếu:</span>
                                {referencedVouchers.map((v, idx) => (
                                    <Tag
                                        key={`ref-${v.voucher_number ?? idx}`}
                                        color="blue"
                                        closable
                                        onClose={() => setReferencedVouchers(referencedVouchers.filter((_, i) => i !== idx))}
                                    >
                                        <LinkOutlined />
                                        <span>{v.voucher_number} ({new Intl.NumberFormat('vi-VN').format(v.total_amount || 0)} ₫)</span>
                                    </Tag>
                                ))}
                                <button
                                    type="button"
                                    className="misa-ref-btn"
                                    onClick={() => setIsRefModalVisible(true)}
                                >
                                    ...
                                </button>
                            </div>
                        </MisaMasterCard.Left>

                        <MisaMasterCard.Right>
                            <MisaMasterCard.MetaRow label="Ngày hạch toán" required>
                                <Form.Item name="posting_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Ngày chứng từ" required>
                                <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Số chứng từ" required>
                                <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                    <Input className="misa-input misa-input-w160 misa-text-semibold" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaTotalCard
                                label="TỔNG TIỀN"
                                value={totals.grandTotal}
                            />
                        </MisaMasterCard.Right>
                    </MisaMasterCard>

                    {/* Middle Section - Accounting Grid */}
                    <div className="misa-detail-card-section">
                        <div className="misa-grid-tab-bar" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <div className="misa-grid-tabs">
                                <button
                                    type="button"
                                    className="misa-grid-tab-btn active"
                                >
                                    Hạch toán
                                </button>
                            </div>
                        </div>

                        <div className="misa-form-flex-col">
                            <Form.List name="lines">
                                    {(fields, { add, remove }) => (
                                        <div className="misa-detail-flex-col">
                                            <div className="misa-table-container">
                                                <table className="misa-voucher-table misa-min-w-1000">
                                                    <thead>
                                                        <tr>
                                                            <th className="misa-text-center">#</th>
                                                            <th>Diễn giải</th>
                                                            <th>TK Nợ</th>
                                                            <th>TK Có</th>
                                                            <th className="misa-text-right">Số tiền</th>
                                                            <th>Đối tượng</th>
                                                            <th>Tên đối tượng</th>
                                                            <th className="misa-text-center"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {fields.map((field, index) => (
                                                            <tr key={field.key}>
                                                                <td className="misa-text-center apple-muted-text misa-text-semibold">{index + 1}</td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'description']} noStyle>
                                                                        <input className="misa-table-input" placeholder="Diễn giải..." />
                                                                    </Form.Item>
                                                                </td>
                                                                <td style={{ width: 125, minWidth: 125 }}>
                                                                    <Form.Item name={[field.name, 'debit_account']} noStyle>
                                                                        <AccountSelect accounts={chartOfAccounts} />
                                                                    </Form.Item>
                                                                </td>
                                                                <td style={{ width: 125, minWidth: 125 }}>
                                                                    <Form.Item name={[field.name, 'credit_account']} noStyle>
                                                                        <AccountSelect accounts={chartOfAccounts} />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right" style={{ width: 140, minWidth: 140 }}>
                                                                    <Form.Item name={[field.name, 'amount']} noStyle initialValue={0}>
                                                                        <InputNumber
                                                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                            className="misa-w-full misa-text-right misa-text-bold"
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'contact_id']} noStyle>
                                                                        <Select
                                                                            showSearch
                                                                            className="misa-w-full"
                                                                            allowClear
                                                                            placeholder="Mã ĐT"
                                                                            options={customers?.map((c: any) => ({
                                                                                value: c.code || c.id,
                                                                                label: `${c.code} - ${c.name}`
                                                                            }))}
                                                                            onChange={(val) => {
                                                                                const found = customers?.find((c: any) => c.code === val || c.id === val);
                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                curLines[field.name] = {
                                                                                    ...curLines[field.name],
                                                                                    contact_id: val,
                                                                                    contact_name: found ? found.name : ''
                                                                                };
                                                                                form.setFieldsValue({ lines: curLines });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'contact_name']} noStyle>
                                                                        <Input className="misa-table-input" placeholder="Tên đối tượng..." readOnly />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-center">
                                                                    <button
                                                                        type="button"
                                                                        className="misa-btn-row-delete"
                                                                        onClick={() => remove(field.name)}
                                                                        title="Xóa dòng"
                                                                    >
                                                                        <DeleteOutlined />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>

                                            {/* Action Grid Footer Buttons */}
                                            <MisaGridActionFooter
                                                onAddLine={() => {
                                                    add({
                                                        description: form.getFieldValue('reason') || 'Thu tiền gửi của ',
                                                        amount: 0,
                                                        contact_id: form.getFieldValue('contact_id') || '',
                                                        contact_name: form.getFieldValue('contact_name') || ''
                                                    });
                                                }}
                                                onDeleteAll={() => {
                                                    form.setFieldValue('lines', []);
                                                    message.success('Đã xóa toàn bộ dòng hạch toán');
                                                }}
                                                lineCount={fields.length}
                                            />

                                            {/* Summary Bar */}
                                            <MisaTableSummaryBar
                                                items={[
                                                    { label: 'Tổng số tiền', value: totals.grandTotal, format: 'currency', highlight: true }
                                                ]}
                                            />
                                        </div>
                                    )}
                                </Form.List>
                            </div>
                    </div>

                    {/* Drag and Drop Attachment Box */}
                    <div className="misa-upload-box-wrapper">
                        <MisaUploadBox />
                    </div>

                </Form>
            </Modal>

            {/* Quick Add Customer / Contact Modal */}
            <QuickAddContactModal
                open={isCustomerModalVisible}
                contactType="customer"
                onCancel={() => setIsCustomerModalVisible(false)}
                onSuccess={(newContact) => {
                    form.setFieldsValue({
                        contact_id: newContact.id,
                        contact_name: newContact.name,
                        payer_name: newContact.contact_person || newContact.name,
                        payer_address: newContact.address || '',
                        reason: `Thu tiền gửi của ${newContact.name}`
                    });
                }}
            />

            {/* Quick Add Employee Modal */}
            <QuickAddEmployeeModal
                open={isEmployeeModalVisible}
                onCancel={() => setIsEmployeeModalVisible(false)}
                onSuccess={(newEmployee) => {
                    form.setFieldsValue({
                        employee_id: newEmployee.id
                    });
                }}
            />

            {/* Reference Voucher Selection Modal */}
            <ReferenceVoucherModal
                open={isRefModalVisible}
                onCancel={() => setIsRefModalVisible(false)}
                onSelect={(selected) => {
                    const newRefs = [...referencedVouchers, ...selected];
                    setReferencedVouchers(newRefs);
                    if (selected.length > 0) {
                        const first = selected[0];
                        if (first.contact_name) {
                            form.setFieldsValue({
                                contact_id: first.contact_id || form.getFieldValue('contact_id'),
                                contact_name: first.contact_name || form.getFieldValue('contact_name'),
                                payer_name: first.contact_name || form.getFieldValue('payer_name'),
                                description: `Thu tiền gửi theo ${selected.map(s => s.voucher_number).join(', ')}`
                            });
                        }
                        const newLines = selected.map((v, idx) => ({
                            key: `${Date.now()}_${idx}`,
                            description: `Thu tiền gửi theo ${v.voucher_type} ${v.voucher_number}`,
                            amount: Number(v.total_amount || 0),
                            contact_id: v.contact_id || v.contact_code || '',
                            contact_name: v.contact_name || '',
                            line_contact_id: v.contact_id || v.contact_code || '',
                            line_contact_name: v.contact_name || '',
                        }));
                        form.setFieldsValue({ lines: newLines });
                        message.success(`Đã nạp ${selected.length} chứng từ tham chiếu vào bảng hạch toán!`);
                    }
                }}
            />

            {/* Quick Add Reason Modal */}
            <QuickAddReasonModal
                open={isReasonModalVisible}
                onCancel={() => setIsReasonModalVisible(false)}
                category="bank_receipt"
                onSuccess={(newReason: any) => {
                    setCustomBankReceiptVoucherTypes(prev => [...prev, newReason]);
                    form.setFieldValue('voucher_type', newReason.value);
                }}
            />

            {/* Bank Voucher Print Preview Modal */}
            <BankVoucherPrintModal
                open={isPrintModalVisible}
                onCancel={() => setIsPrintModalVisible(false)}
                voucherType="receipt"
                data={printVoucherData}
                initialTemplate="bao_co"
            />
        </>
    );
};

export default BankReceipts;
