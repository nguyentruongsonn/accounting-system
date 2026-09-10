import React, { useState, useEffect } from 'react';
import { Alert, Form, Input, InputNumber, DatePicker, Radio, Checkbox, Table, Button, Space, Popconfirm } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { 
    PlusOutlined, 
    DeleteOutlined, 
    CalculatorOutlined,
    SaveOutlined,
    SearchOutlined,
    FileTextOutlined,
    LinkOutlined
} from '@ant-design/icons';
import dayjs from 'dayjs';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import { 
    QuickAddContactModal, 
    QuickAddEmployeeModal,
    QuickAddItemModal,
    QuickAddPaymentTermModal,
    ReferenceVoucherModal,
    MisaMasterCard,
    MisaGridActionFooter,
    MisaTableSummaryBar,
    MisaTotalCard
} from '../../../components/misa';
import { useVoucherShortcuts } from '../../../hooks/useVoucherShortcuts';
import { useVoucherTotals } from '../../../hooks/useVoucherTotals';
import { SelectExpenseVoucherModal } from './SelectExpenseVoucherModal';
import { AllocateExpenseModal } from './AllocateExpenseModal';
import ModalFrame from '../../../components/layout/ModalFrame';
import { AdaptiveSelect as Select } from '../../../components/layout/AdaptiveSelect';

export interface PurchaseServiceModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
    editRecord?: any;
}

interface ServiceLine {
    key: string;
    item_id?: number;
    service_code: string;
    service_name: string;
    debit_account: string;
    credit_account: string;
    unit: string;
    quantity: number;
    unit_price: number;
    amount: number;
    discount_rate?: number;
    discount_amount?: number;
    tax_rate: number;
    tax_amount: number;
    tax_account: string;
    invoice_template?: string;
    invoice_series?: string;
    invoice_number?: string;
    invoice_date?: any;
    tax_code?: string;
    supplier_tax_name?: string;
    supplier_tax_address?: string;
    vat_group?: string;
    cost_item_code?: string;
    cost_object_code?: string;
    department_code?: string;
}

const VAT_GROUPS = [
    { value: '1', label: '1 - HHDV dùng riêng cho SXKD chịu thuế GTGT đủ ĐK khấu trừ' },
    { value: '2', label: '2 - HHDV dùng riêng cho SXKD không chịu thuế GTGT' },
    { value: '3', label: '3 - HHDV dùng chung cho SXKD chịu thuế và không chịu thuế' },
    { value: '4', label: '4 - HHDV dùng cho dự án đầu tư đủ ĐK khấu trừ' },
    { value: '5', label: '5 - HHDV không phải tổng hợp trên tờ khai 01/GTGT' }
];

const COST_ITEMS = [
    { code: 'CP_VANCHUYEN', name: 'Chi phí vận chuyển, bốc xếp' },
    { code: 'CP_LUUKHO', name: 'Chi phí lưu kho, bến bãi' },
    { code: 'CP_BAOHIEM', name: 'Chi phí bảo hiểm hàng hóa' },
    { code: 'CP_HAIPHAN', name: 'Phí hải quan, kiểm định' },
    { code: 'CP_DIEN_NUOC', name: 'Chi phí điện, nước, viễn thông' },
    { code: 'CP_QUANGCAO', name: 'Chi phí tiếp thị, quảng cáo' },
    { code: 'CP_KHAC', name: 'Chi phí dịch vụ mua ngoài khác' }
];

function parsePurchaseServiceCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: T[] }).data;
    }
    throw new Error(`Invalid ${resource} response.`);
}

export const PurchaseServiceModal: React.FC<PurchaseServiceModalProps> = ({
    open,
    onCancel,
    onSuccess,
    editRecord
}) => {
    const [form] = Form.useForm();
    const voucherNumber = Form.useWatch('voucher_number', form);
    const queryClient = useQueryClient();

    const [paymentStatus, setPaymentStatus] = useState<'unpaid' | 'paid'>('unpaid');
    const [paymentMethod, setPaymentMethod] = useState<'cash' | 'bank' | 'cheque_transfer' | 'cheque_cash'>('cash');
    const [invoiceHandling, setInvoiceHandling] = useState<'with_invoice' | 'without_invoice_now' | 'no_invoice'>('with_invoice');
    const [isPurchaseExpense, setIsPurchaseExpense] = useState(false);
    const [currency, setCurrency] = useState<'VND' | 'USD' | 'EUR'>('VND');
    const [exchangeRate, setExchangeRate] = useState<number>(1);

    const [masterActiveTab, setMasterActiveTab] = useState<string>('service_voucher');
    const [gridActiveTab, setGridActiveTab] = useState<string>('accounting');

    const [targetPurchaseVouchers, setTargetPurchaseVouchers] = useState<any[]>([]);
    const [referenceVouchers, setReferenceVouchers] = useState<any[]>([]);

    const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [isItemModalVisible, setIsItemModalVisible] = useState(false);
    const [isPaymentTermModalVisible, setIsPaymentTermModalVisible] = useState(false);
    const [isSelectExpenseModalOpen, setIsSelectExpenseModalOpen] = useState(false);
    const [isAllocateExpenseModalOpen, setIsAllocateExpenseModalOpen] = useState(false);
    const [isReferenceModalOpen, setIsReferenceModalOpen] = useState(false);

    const {
        data: suppliers = [],
        isError: isSuppliersError,
        refetch: refetchSuppliers,
    } = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parsePurchaseServiceCollection<any>(data, 'suppliers');
        },
        enabled: open
    });

    const {
        data: employees = [],
        isError: isEmployeesError,
        refetch: refetchEmployees,
    } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parsePurchaseServiceCollection<any>(data, 'employees');
        },
        enabled: open
    });

    const {
        data: items = [],
        isError: isItemsError,
        refetch: refetchItems,
    } = useQuery({
        queryKey: ['items-services'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parsePurchaseServiceCollection<any>(data, 'inventory items');
        },
        enabled: open
    });

    const {
        data: accounts = [],
        isError: isAccountsError,
        refetch: refetchAccounts,
    } = useQuery({
        queryKey: ['accounts', 'purchase-service'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            const rows = parsePurchaseServiceCollection<any>(data, 'accounts');
            return rows.filter((account: any) => account && account.is_parent !== true && account.is_active !== false);
        },
        enabled: open
    });

    const supplierList = suppliers;
    const employeeList = employees;
    const itemList = items;
    const accountList = accounts;

    // A new service voucher starts empty. Voucher numbering and accounting
    // mappings are server/tenant policy, so the browser must not synthesize
    // sample values before the user or server supplies them.
    const [lines, setLines] = useState<ServiceLine[]>([]);

    const { subTotal, totalTax, grandTotal } = useVoucherTotals(lines, {
        includeTax: invoiceHandling !== 'no_invoice'
    });

    useEffect(() => {
        if (open) {
            setGridActiveTab('accounting');
            setMasterActiveTab('service_voucher');

            if (editRecord) {
                form.setFieldsValue({
                    ...editRecord,
                    voucher_date: editRecord.invoice_date ? dayjs(editRecord.invoice_date) : dayjs(),
                    accounting_date: editRecord.accounting_date ? dayjs(editRecord.accounting_date) : dayjs(),
                    due_date: editRecord.due_date ? dayjs(editRecord.due_date) : dayjs().add(30, 'day'),
                });
                setPaymentStatus(editRecord.payment_method === 'unpaid' ? 'unpaid' : 'paid');
                setPaymentMethod(editRecord.payment_method || 'cash');
                setIsPurchaseExpense(Boolean(editRecord.is_purchase_expense));
                if (editRecord.lines && editRecord.lines.length > 0) {
                    setLines(editRecord.lines.map((l: any, idx: number) => ({
                        key: `${idx + 1}`,
                        service_code: l.service_code || l.item_code || '',
                        service_name: l.description || l.service_name || '',
                        debit_account: l.debit_account,
                        credit_account: l.credit_account,
                        unit: l.unit,
                        quantity: l.quantity,
                        unit_price: l.unit_price,
                        amount: l.amount,
                        discount_rate: l.discount_rate,
                        discount_amount: l.discount_amount,
                        tax_rate: l.tax_rate,
                        tax_amount: l.tax_amount,
                        tax_account: l.tax_account,
                        invoice_template: l.invoice_template,
                        invoice_series: l.invoice_series,
                        invoice_number: l.invoice_number,
                        invoice_date: l.invoice_date ? dayjs(l.invoice_date) : undefined,
                        vat_group: l.vat_group,
                        cost_item_code: l.cost_item_code
                    })));
                }
            } else {
                form.resetFields();
                form.setFieldsValue({
                    voucher_number: undefined,
                    payment_slip_number: undefined,
                    accounting_date: dayjs(),
                    voucher_date: dayjs(),
                    currency: 'VND',
                    exchange_rate: 1,
                    invoice_number: undefined
                });
                setPaymentStatus('unpaid');
                setPaymentMethod('cash');
                setIsPurchaseExpense(false);
                setInvoiceHandling('with_invoice');
                setCurrency('VND');
                setExchangeRate(1);
                setLines([]);
            }
        }
    }, [open, editRecord, form]);

    const handlePaymentStatusChange = (newStatus: 'unpaid' | 'paid') => {
        setPaymentStatus(newStatus);

        if (newStatus === 'paid') {
            setMasterActiveTab('payment_slip');
        } else {
            setMasterActiveTab('service_voucher');
        }
    };

    const handlePaymentMethodChange = (newMethod: 'cash' | 'bank' | 'cheque_transfer' | 'cheque_cash') => {
        setPaymentMethod(newMethod);
        setMasterActiveTab('payment_slip');
    };

    const handlePurchaseExpenseToggle = (checked: boolean) => {
        setIsPurchaseExpense(checked);
        if (checked) {
            setLines(prev => prev.map(l => ({
                ...l,
                debit_account: l.debit_account === '6427' || l.debit_account === '6417' ? '1562' : l.debit_account
            })));
        } else {
            setLines(prev => prev.map(l => ({
                ...l,
                debit_account: l.debit_account === '1562' ? '6427' : l.debit_account
            })));
        }
    };

    const handleSupplierChange = (supplierId: number) => {
        const supplier = supplierList.find((s: any) => s.id === supplierId);
        if (supplier) {
            form.setFieldsValue({
                supplier_id: supplier.id,
                supplier_name: supplier.name,
                supplier_address: supplier.address,
                tax_code: supplier.tax_code,
                deliverer_name: supplier.contact_name || supplier.name,
                receiver_name: supplier.contact_name || supplier.name,
                recipient_bank_account: supplier.bank_accounts?.[0]?.account_number || '',
                recipient_bank_name: supplier.bank_accounts?.[0]?.bank_name || '',
                description: `Mua dịch vụ của ${supplier.name}`,
                spend_reason: `Chi tiền mua dịch vụ của ${supplier.name}`,
                bank_payment_content: `Thanh toán tiền mua dịch vụ cho ${supplier.name}`
            });
            setLines(prev => prev.map(l => ({
                ...l,
                tax_code: supplier.tax_code || l.tax_code,
                supplier_tax_name: supplier.name || l.supplier_tax_name,
                supplier_tax_address: supplier.address || l.supplier_tax_address
            })));
        }
    };

    const handleServiceSelect = (key: string, itemId: number) => {
        const item = itemList.find((i: any) => i.id === itemId);
        if (item) {
            updateLine(key, 'service_code', item.code);
            updateLine(key, 'service_name', item.name);
            updateLine(key, 'unit', item.unit);
            updateLine(key, 'unit_price', item.cost_price ?? item.purchase_price ?? item.unit_price);
            if (item.debit_account) updateLine(key, 'debit_account', item.debit_account);
            if (item.tax_rate !== undefined) updateLine(key, 'tax_rate', item.tax_rate);
        }
    };

    const updateLine = (key: string, field: keyof ServiceLine, value: any) => {
        setLines(prev => prev.map(l => {
            if (l.key !== key) return l;
            const updated = { ...l, [field]: value };
            if (field === 'quantity' || field === 'unit_price' || field === 'discount_rate') {
                const q = field === 'quantity' ? Number(value) || 0 : l.quantity;
                const p = field === 'unit_price' ? Number(value) || 0 : l.unit_price;
                const dr = field === 'discount_rate' ? Number(value) || 0 : (l.discount_rate || 0);
                const rawAmount = q * p;
                const discAmount = (rawAmount * dr) / 100;
                updated.amount = rawAmount - discAmount;
                updated.discount_amount = discAmount;
                updated.tax_amount = (updated.amount * (updated.tax_rate || 0)) / 100;
            }
            if (field === 'tax_rate') {
                updated.tax_amount = (updated.amount * (Number(value) || 0)) / 100;
            }
            return updated;
        }));
    };

    const addLine = () => {
        const newKey = `${Date.now()}`;
        const supName = form.getFieldValue('supplier_name');
        const taxCode = form.getFieldValue('tax_code');
        const supAddr = form.getFieldValue('supplier_address');

        setLines(prev => [
            ...prev,
            {
                key: newKey,
                service_code: '',
                service_name: '',
                debit_account: '',
                credit_account: '',
                unit: '',
                quantity: 0,
                unit_price: 0,
                amount: 0,
                discount_rate: 0,
                discount_amount: 0,
                tax_rate: 0,
                tax_amount: 0,
                tax_account: '',
                invoice_template: undefined,
                invoice_series: undefined,
                invoice_number: '',
                invoice_date: undefined,
                tax_code: taxCode,
                supplier_tax_name: supName,
                supplier_tax_address: supAddr,
                vat_group: undefined,
                cost_item_code: undefined
            }
        ]);
    };

    const removeLine = (key: string) => {
        if (lines.length <= 1) {
            message.warning('Chứng từ phải có ít nhất 1 dòng chi tiết!');
            return;
        }
        setLines(prev => prev.filter(l => l.key !== key));
    };

    const deleteAllLines = () => {
        setLines([]);
    };

    const handleSave = async (andNew = false) => {
        try {
            const values = await form.validateFields();

            if (paymentMethod === 'bank') {
                message.info('Thanh toán tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ; chứng từ cũ chỉ được xem.');
                return;
            }
            
            const invalidLine = lines.find(l => !l.service_name && !l.service_code);
            if (invalidLine) {
                message.error('Vui lòng nhập tên hoặc mã dịch vụ cho tất cả các dòng!');
                return;
            }

            const payload = {
                voucher_number: values.voucher_number,
                payment_slip_number: values.payment_slip_number,
                voucher_type: '5. Mua dịch vụ',
                supplier_id: values.supplier_id,
                supplier_name: values.supplier_name,
                tax_code: values.tax_code,
                supplier_address: values.supplier_address,
                employee_id: values.employee_id,
                accounting_date: values.accounting_date?.format('YYYY-MM-DD'),
                invoice_date: values.voucher_date?.format('YYYY-MM-DD'),
                due_date: values.due_date?.format('YYYY-MM-DD'),
                payment_term_code: values.payment_term_code,
                description: values.description,
                payment_status: paymentStatus,
                payment_method: paymentStatus === 'unpaid' ? 'unpaid' : paymentMethod,
                is_purchase_expense: isPurchaseExpense,
                invoice_option: invoiceHandling === 'with_invoice' ? 'Nhận kèm hóa đơn' : (invoiceHandling === 'without_invoice_now' ? 'Không kèm hóa đơn' : 'Không có hóa đơn'),
                currency: currency,
                exchange_rate: exchangeRate,
                sub_total: subTotal,
                tax_amount: totalTax,
                purchase_expense: isPurchaseExpense ? subTotal : 0,
                total_amount: grandTotal,
                lines: lines.map(l => ({
                    service_code: l.service_code,
                    description: l.service_name || values.description,
                    unit: l.unit,
                    quantity: l.quantity,
                    unit_price: l.unit_price,
                    amount: l.amount,
                    discount_rate: l.discount_rate,
                    discount_amount: l.discount_amount,
                    debit_account: l.debit_account,
                    credit_account: l.credit_account,
                    tax_rate: l.tax_rate,
                    tax_amount: l.tax_amount,
                    tax_account: l.tax_account,
                    invoice_template: l.invoice_template,
                    invoice_series: l.invoice_series,
                    invoice_number: l.invoice_number,
                    invoice_date: l.invoice_date ? dayjs(l.invoice_date).format('YYYY-MM-DD') : undefined,
                    vat_group: l.vat_group,
                    cost_item_code: l.cost_item_code,
                    cost_object_code: l.cost_object_code
                }))
            };

            const response = await api.post('/purchase/invoices', payload);
            const persistedInvoice = response?.data?.data ?? response?.data;
            if (!persistedInvoice || persistedInvoice.id === undefined || persistedInvoice.id === null) {
                throw new Error('Máy chủ không trả về chứng từ mua dịch vụ đã lưu; không thể báo thành công.');
            }
            message.success(`Đã lưu Chứng từ mua dịch vụ ${values.voucher_number} thành công!`);
            queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] });

            if (onSuccess) onSuccess();

            if (andNew) {
                form.resetFields();
                form.setFieldsValue({
                    voucher_number: undefined,
                    payment_slip_number: undefined,
                    accounting_date: dayjs(),
                    voucher_date: dayjs(),
                    currency: 'VND',
                    exchange_rate: 1
                });
                deleteAllLines();
            } else {
                onCancel();
            }
        } catch (err: any) {
            if (err?.errorFields) return;
            message.error(err?.response?.data?.message || 'Có lỗi khi lưu chứng từ!');
        }
    };

    useVoucherShortcuts({
        onSave: () => handleSave(false),
        onSaveAndNew: () => handleSave(true),
        onClose: onCancel,
        onAddLine: addLine,
        enabled: open
    });

    const accountingColumns = [
        {
            title: '#',
            key: 'index',
            width: 40,
            align: 'center' as const,
            render: (_: any, __: any, index: number) => (
                <span className="apple-muted-text">{index + 1}</span>
            )
        },
        {
            title: 'Mã dịch vụ',
            dataIndex: 'service_code',
            key: 'service_code',
            width: 140,
            render: (val: string, r: ServiceLine) => (
                <Select
                    showSearch
                    value={val || undefined}
                    placeholder="Chọn mã DV"
                    className="misa-input misa-w-full"
                    optionLabelProp="value"
                    popupMatchSelectWidth={false}
                    dropdownStyle={{ minWidth: 680, width: 680 }}
                    onChange={(v) => {
                        const itm = itemList.find((i: any) => i.code === v);
                        if (itm) handleServiceSelect(r.key, itm.id);
                        else updateLine(r.key, 'service_code', v);
                    }}
                    options={itemList.map((item: any) => ({
                        value: item.code,
                        label: item.code,
                        data: item
                    }))}
                    optionRender={(option: any) => (
                        <div className="misa-cell-dropdown-grid-4col">
                            <span className="misa-text-bold">{option?.data?.code}</span>
                            <span className="misa-text-truncate">{option?.data?.name}</span>
                            <span className="misa-text-center apple-muted-text">{option?.data?.unit ?? '—'}</span>
                            <span className="misa-text-right misa-text-green misa-text-bold">
                                {new Intl.NumberFormat('vi-VN').format(Number(option?.data?.cost_price || option?.data?.purchase_price || option?.data?.unit_price) || 0)} ₫
                            </span>
                        </div>
                    )}
                    dropdownRender={menu => (
                        <div>
                            <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-4col">
                                <span>Mã dịch vụ</span>
                                <span>Tên dịch vụ</span>
                                <span className="misa-text-center">ĐVT</span>
                                <span className="misa-text-right">Đơn giá mua</span>
                            </div>
                            {menu}
                            <div className="misa-grid-dropdown-footer">
                                <Button 
                                    type="link" 
                                    size="small" 
                                    icon={<PlusOutlined />} 
                                    onClick={() => setIsItemModalVisible(true)}
                                    className="misa-action-link-success"
                                >
                                    Thêm mới dịch vụ
                                </Button>
                            </div>
                        </div>
                    )}
                />
            )
        },
        {
            title: 'Tên dịch vụ / Diễn giải',
            dataIndex: 'service_name',
            key: 'service_name',
            minWidth: 200,
            render: (val: string, r: ServiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'service_name', e.target.value)} 
                    placeholder="Tên dịch vụ hoặc nội dung chi phí"
                    className="misa-input"
                />
            )
        },
        {
            title: 'TK Chi phí',
            dataIndex: 'debit_account',
            key: 'debit_account',
            width: 110,
            render: (val: string, r: ServiceLine) => (
                <Select
                    showSearch
                    value={val}
                    onChange={v => updateLine(r.key, 'debit_account', v)}
                    className="misa-input misa-w-full"
                    optionLabelProp="value"
                    popupMatchSelectWidth={false}
                    dropdownStyle={{ minWidth: 380, width: 380 }}
                    options={accountList.map((a: any) => ({
                        value: a.code,
                        label: a.code,
                        data: a
                    }))}
                    notFoundContent="Chưa có tài khoản từ máy chủ"
                    optionRender={(option: any) => (
                        <div className="misa-cell-dropdown-grid-2col">
                            <span className="misa-text-bold">{option?.data?.code}</span>
                            <span className="apple-muted-text">{option?.data?.name}</span>
                        </div>
                    )}
                    dropdownRender={menu => (
                        <div>
                            <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-2col">
                                <span>Số TK</span>
                                <span>Tên tài khoản</span>
                            </div>
                            {menu}
                        </div>
                    )}
                />
            )
        },
        {
            title: 'TK Công nợ/Tiền',
            dataIndex: 'credit_account',
            key: 'credit_account',
            width: 115,
            render: (val: string, r: ServiceLine) => (
                <Select
                    showSearch
                    value={val}
                    onChange={v => updateLine(r.key, 'credit_account', v)}
                    className="misa-input misa-w-full"
                    optionLabelProp="value"
                    popupMatchSelectWidth={false}
                    dropdownStyle={{ minWidth: 380, width: 380 }}
                    options={accountList.map((a: any) => ({
                        value: a.code,
                        label: a.code,
                        data: a
                    }))}
                    notFoundContent="Chưa có tài khoản từ máy chủ"
                    optionRender={(option: any) => (
                        <div className="misa-cell-dropdown-grid-2col">
                            <span className="misa-text-bold">{option?.data?.code}</span>
                            <span className="apple-muted-text">{option?.data?.name}</span>
                        </div>
                    )}
                    dropdownRender={menu => (
                        <div>
                            <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-2col">
                                <span>Số TK</span>
                                <span>Tên tài khoản</span>
                            </div>
                            {menu}
                        </div>
                    )}
                />
            )
        },
        {
            title: 'ĐVT',
            dataIndex: 'unit',
            key: 'unit',
            width: 70,
            render: (val: string, r: ServiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'unit', e.target.value)} 
                    className="misa-input misa-text-center"
                />
            )
        },
        {
            title: 'Số lượng',
            dataIndex: 'quantity',
            key: 'quantity',
            width: 80,
            align: 'right' as const,
            render: (val: number, r: ServiceLine) => (
                <InputNumber 
                    value={val} 
                    onChange={v => updateLine(r.key, 'quantity', v)} 
                    min={0}
                    className="misa-input misa-w-full"
                />
            )
        },
        {
            title: 'Đơn giá',
            dataIndex: 'unit_price',
            key: 'unit_price',
            width: 120,
            align: 'right' as const,
            render: (val: number, r: ServiceLine) => (
                <InputNumber 
                    value={val} 
                    onChange={v => updateLine(r.key, 'unit_price', v)} 
                    min={0}
                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                    parser={(v: any) => v.replace(/\$\s?|(,*)/g, '')}
                    className="misa-input misa-w-full"
                />
            )
        },
        {
            title: 'Thành tiền',
            dataIndex: 'amount',
            key: 'amount',
            width: 130,
            align: 'right' as const,
            render: (val: number) => (
                <span className="misa-table-amount-bold">
                    {new Intl.NumberFormat('vi-VN').format(val)} ₫
                </span>
            )
        },
        {
            title: 'Khoản mục CP',
            dataIndex: 'cost_item_code',
            key: 'cost_item_code',
            width: 150,
            render: (val: string, r: ServiceLine) => (
                <Select
                    value={val || undefined}
                    onChange={v => updateLine(r.key, 'cost_item_code', v)}
                    placeholder="Khoản mục CP"
                    className="misa-input misa-w-full"
                    allowClear
                    options={COST_ITEMS.map(c => ({ value: c.code, label: `${c.code} - ${c.name}` }))}
                />
            )
        },
        ...(isPurchaseExpense ? [{
            title: 'Là CP mua hàng',
            key: 'is_expense_badge',
            width: 110,
            align: 'center' as const,
            render: (_: any, r: ServiceLine) => (
                <span className="misa-badge-account">
                    TK {r.debit_account}
                </span>
            )
        }] : []),
        {
            title: '',
            key: 'action',
            width: 35,
            align: 'center' as const,
            render: (_: any, r: ServiceLine) => (
                <Popconfirm title="Xóa dòng này?" onConfirm={() => removeLine(r.key)} okText="Xóa" cancelText="Hủy">
                    <button type="button" className="misa-btn-plain-danger">
                        <DeleteOutlined />
                    </button>
                </Popconfirm>
            )
        }
    ];

    const taxColumns = [
        {
            title: '#',
            key: 'index',
            width: 40,
            align: 'center' as const,
            render: (_: any, __: any, index: number) => index + 1
        },
        {
            title: 'Tên dịch vụ',
            dataIndex: 'service_name',
            key: 'service_name',
            minWidth: 180,
            render: (val: string) => <span className="misa-text-semibold">{val || '-'}</span>
        },
        {
            title: '% Thuế GTGT',
            dataIndex: 'tax_rate',
            key: 'tax_rate',
            width: 100,
            render: (val: number, r: ServiceLine) => (
                <Select 
                    value={val} 
                    onChange={v => updateLine(r.key, 'tax_rate', v)}
                    className="misa-input misa-w-full"
                    options={[
                        { value: 0, label: '0%' },
                        { value: 5, label: '5%' },
                        { value: 8, label: '8%' },
                        { value: 10, label: '10%' }
                    ]}
                />
            )
        },
        {
            title: 'Tiền thuế GTGT',
            dataIndex: 'tax_amount',
            key: 'tax_amount',
            width: 125,
            align: 'right' as const,
            render: (val: number) => (
                <span className="misa-table-amount-bold">
                    {new Intl.NumberFormat('vi-VN').format(val)} ₫
                </span>
            )
        },
        {
            title: 'TK Thuế',
            dataIndex: 'tax_account',
            key: 'tax_account',
            width: 95,
            render: (val: string, r: ServiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'tax_account', e.target.value)} 
                    className="misa-input misa-text-center"
                />
            )
        },
        {
            title: 'Mẫu số HĐ',
            dataIndex: 'invoice_template',
            key: 'invoice_template',
            width: 90,
            render: (val: string, r: ServiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'invoice_template', e.target.value)} 
                    placeholder="1/001"
                    className="misa-input"
                />
            )
        },
        {
            title: 'Ký hiệu HĐ',
            dataIndex: 'invoice_series',
            key: 'invoice_series',
            width: 95,
            render: (val: string, r: ServiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'invoice_series', e.target.value)} 
                    placeholder="1C26TAA"
                    className="misa-input"
                />
            )
        },
        {
            title: 'Số hóa đơn',
            dataIndex: 'invoice_number',
            key: 'invoice_number',
            width: 110,
            render: (val: string, r: ServiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'invoice_number', e.target.value)} 
                    placeholder="Số HĐ"
                    className="misa-input"
                />
            )
        },
        {
            title: 'Ngày hóa đơn',
            dataIndex: 'invoice_date',
            key: 'invoice_date',
            width: 120,
            render: (val: any, r: ServiceLine) => (
                <DatePicker 
                    value={val ? dayjs(val) : null} 
                    onChange={d => updateLine(r.key, 'invoice_date', d)}
                    format="DD/MM/YYYY"
                    className="misa-input misa-w-full"
                />
            )
        },
        {
            title: 'Nhóm HHDV',
            dataIndex: 'vat_group',
            key: 'vat_group',
            width: 160,
            render: (val: string, r: ServiceLine) => (
                <Select
                    value={val || undefined}
                    onChange={v => updateLine(r.key, 'vat_group', v)}
                    className="misa-input misa-w-full"
                    options={VAT_GROUPS}
                />
            )
        }
    ];

    return (
        <>
            <Modal
                title={
                    <div className="misa-modal-header-wrapper">
                        <div className="misa-flex-center misa-gap-12">
                            <span className="misa-voucher-header-title">
                                Chứng từ mua dịch vụ
                            </span>
                            <span className="misa-status-tag misa-status-tag-draft">
                                {voucherNumber || 'MDV'}
                            </span>
                        </div>
                    </div>
                }
                open={open}
                onCancel={onCancel}
                width="98vw"
                className="misa-voucher-modal misa-top-8"
                footer={
                    <div className="misa-modal-footer">
                        <Space>
                            <Button onClick={onCancel} className="misa-btn-secondary">
                                Hủy (Esc)
                            </Button>
                        </Space>

                        <Space size={10}>
                            <Button 
                                onClick={() => handleSave(true)}
                                className="misa-btn-footer-save"
                            >
                                Lưu và thêm mới
                            </Button>
                            <Button 
                                type="primary"
                                onClick={() => handleSave(false)}
                                icon={<SaveOutlined />}
                                className="misa-btn-footer-primary"
                            >
                                Lưu
                            </Button>
                        </Space>
                    </div>
                }
            >
                <ModalFrame>
                {(isSuppliersError || isEmployeesError || isItemsError || isAccountsError) && (
                    <div className="misa-modal-data-errors misa-flex-col misa-gap-8 misa-bottom-8">
                        {isSuppliersError && (
                            <Alert
                                type="error"
                                showIcon
                                title="Không thể tải danh mục nhà cung cấp"
                                action={<Button size="small" onClick={() => void refetchSuppliers()}>Thử lại danh mục nhà cung cấp</Button>}
                            />
                        )}
                        {isEmployeesError && (
                            <Alert
                                type="error"
                                showIcon
                                title="Không thể tải danh mục nhân viên"
                                action={<Button size="small" onClick={() => void refetchEmployees()}>Thử lại danh mục nhân viên</Button>}
                            />
                        )}
                        {isItemsError && (
                            <Alert
                                type="error"
                                showIcon
                                title="Không thể tải danh mục dịch vụ"
                                action={<Button size="small" onClick={() => void refetchItems()}>Thử lại danh mục dịch vụ</Button>}
                            />
                        )}
                        {isAccountsError && (
                            <Alert
                                type="error"
                                showIcon
                                title="Không thể tải danh mục tài khoản"
                                action={<Button size="small" onClick={() => void refetchAccounts()}>Thử lại danh mục tài khoản</Button>}
                            />
                        )}
                    </div>
                )}
                <Form form={form} layout="vertical">
                    {/* Top Configuration Bar */}
                    <div className="misa-config-bar">
                        <div className="misa-config-bar-left">
                            <Radio.Group value={paymentStatus} onChange={e => handlePaymentStatusChange(e.target.value)}>
                                <Radio value="unpaid"><span className="misa-text-bold">Chưa thanh toán</span></Radio>
                                <Radio value="paid"><span className="misa-text-bold">Thanh toán ngay</span></Radio>
                            </Radio.Group>

                            {paymentStatus === 'paid' && (
                                <div className="misa-flex-center misa-gap-12 misa-border-l misa-pl-12">
                                    <Radio.Group value={paymentMethod} onChange={e => handlePaymentMethodChange(e.target.value)}>
                                        <Radio value="cash"><span>Tiền mặt</span></Radio>
                                        <Radio value="cheque_transfer"><span>Séc CK</span></Radio>
                                        <Radio value="cheque_cash"><span>Séc TM</span></Radio>
                                    </Radio.Group>
                                </div>
                            )}
                        </div>

                        <div className="misa-config-bar-right">
                            <Select 
                                value={invoiceHandling} 
                                onChange={setInvoiceHandling}
                                className="misa-input misa-input-w170"
                                options={[
                                    { value: 'with_invoice', label: 'Nhận kèm hóa đơn' },
                                    { value: 'without_invoice_now', label: 'Không kèm hóa đơn' },
                                    { value: 'no_invoice', label: 'Không có hóa đơn' },
                                ]}
                            />
                            
                            <div className="misa-unit-badge">
                                <Checkbox checked={isPurchaseExpense} onChange={e => handlePurchaseExpenseToggle(e.target.checked)}>
                                    <span className={isPurchaseExpense ? 'misa-text-primary-bold' : 'misa-text-bold'}>
                                        Là chi phí mua hàng
                                    </span>
                                </Checkbox>
                            </div>

                            <Select 
                                value={currency} 
                                onChange={setCurrency}
                                className="misa-input misa-input-w75"
                                options={[
                                    { value: 'VND', label: 'VND' },
                                    { value: 'USD', label: 'USD' },
                                    { value: 'EUR', label: 'EUR' }
                                ]}
                            />
                            {currency !== 'VND' && (
                                <InputNumber 
                                    value={exchangeRate} 
                                    onChange={(v) => setExchangeRate(Number(v) || 1)}
                                    className="misa-input misa-input-w95"
                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                    placeholder="Tỷ giá"
                                />
                            )}
                        </div>
                    </div>

                    {/* Master Card using MisaMasterCard Shared Component */}
                    <MisaMasterCard className="mb-2">
                        <MisaMasterCard.Left>
                            {paymentStatus === 'paid' && (
                                <MisaMasterCard.Tabs
                                    activeKey={masterActiveTab}
                                    onChange={setMasterActiveTab}
                                    tabs={[
                                        { key: 'service_voucher', label: '1. Chứng từ mua dịch vụ' },
                                        { key: 'payment_slip', label: `2. ${paymentMethod === 'cash' ? 'Phiếu chi' : (paymentMethod === 'bank' ? 'Ủy nhiệm chi' : 'Séc')}` }
                                    ]}
                                />
                            )}

                            {masterActiveTab === 'service_voucher' && (
                                <MisaMasterCard.FormGrid>
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Mã nhà cung cấp <span className="misa-text-red">*</span></div>
                                        <div className="misa-input-group">
                                            <Form.Item name="supplier_id" noStyle rules={[{ required: true, message: 'Chọn nhà cung cấp' }]}>
                                                <Select 
                                                    showSearch 
                                                    variant="borderless" 
                                                    className="misa-w-full"
                                                    placeholder="Chọn nhà cung cấp..."
                                                    onChange={handleSupplierChange}
                                                    popupMatchSelectWidth={false}
                                                    dropdownStyle={{ minWidth: 950, width: 950 }}
                                                    optionLabelProp="label"
                                                    options={supplierList.map((s: any) => ({
                                                        value: s.id,
                                                        label: `${s.code} - ${s.name}`,
                                                        data: s
                                                    }))}
                                                    optionRender={option => (
                                                        <div className="misa-cell-dropdown-grid-5col">
                                                            <span className="misa-text-bold">{option.data.code}</span>
                                                            <span className="misa-text-truncate">{option.data.name}</span>
                                                            <span className="misa-text-truncate apple-muted-text">{option.data.address}</span>
                                                            <span className="apple-muted-text">{option.data.tax_code}</span>
                                                            <span className="apple-muted-text">{option.data.phone}</span>
                                                        </div>
                                                    )}
                                                    dropdownRender={menu => (
                                                        <div>
                                                            <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-5col">
                                                                <span>Mã NCC</span>
                                                                <span>Tên NCC</span>
                                                                <span>Địa chỉ</span>
                                                                <span>Mã số thuế</span>
                                                                <span>Điện thoại</span>
                                                            </div>
                                                            {menu}
                                                            <div className="misa-grid-dropdown-footer">
                                                                <Button 
                                                                    type="link" 
                                                                    size="small" 
                                                                    icon={<PlusOutlined />} 
                                                                    onClick={() => setIsSupplierModalVisible(true)}
                                                                    className="misa-action-link-success"
                                                                >
                                                                    Thêm mới nhà cung cấp
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    )}
                                                />
                                            </Form.Item>
                                            <button type="button" className="misa-plus-btn" onClick={() => setIsSupplierModalVisible(true)} title="Thêm nhanh NCC">
                                                <PlusOutlined className="misa-btn-plus-icon-sm" />
                                            </button>
                                        </div>
                                    </div>

                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Tên nhà cung cấp</div>
                                        <Form.Item name="supplier_name" noStyle>
                                            <Input className="misa-input" placeholder="Tên nhà cung cấp" />
                                        </Form.Item>
                                    </div>

                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Người liên hệ</div>
                                        <Form.Item name="contact_name" noStyle>
                                            <Input className="misa-input" placeholder="Người liên hệ" />
                                        </Form.Item>
                                    </div>

                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Địa chỉ</div>
                                        <Form.Item name="supplier_address" noStyle>
                                            <Input className="misa-input" placeholder="Địa chỉ nhà cung cấp" />
                                        </Form.Item>
                                    </div>

                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Mã số thuế</div>
                                        <Form.Item name="tax_code" noStyle>
                                            <Input className="misa-input" placeholder="Mã số thuế" />
                                        </Form.Item>
                                    </div>

                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Nhân viên mua hàng</div>
                                        <div className="misa-input-group">
                                            <Form.Item name="employee_id" noStyle>
                                                <Select 
                                                    showSearch 
                                                    variant="borderless" 
                                                    className="misa-w-full"
                                                    allowClear
                                                    placeholder="Chọn nhân viên"
                                                    options={employeeList.map((e: any) => ({ value: e.id, label: `${e.code} - ${e.name}` }))}
                                                />
                                            </Form.Item>
                                            <button type="button" className="misa-plus-btn" onClick={() => setIsEmployeeModalVisible(true)} title="Thêm nhân viên">
                                                <PlusOutlined className="misa-btn-plus-icon-sm" />
                                            </button>
                                        </div>
                                    </div>

                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Diễn giải</div>
                                        <Form.Item name="description" noStyle>
                                            <Input className="misa-input" placeholder="Nội dung diễn giải nghiệp vụ mua dịch vụ..." />
                                        </Form.Item>
                                    </div>

                                    <div className="misa-col-2">
                                        <div className="misa-field-label">Kèm theo</div>
                                        <div className="misa-flex-center misa-gap-4">
                                        <Form.Item name="attached_docs" noStyle>
                                                <Input className="misa-input misa-input-w45-center" />
                                            </Form.Item>
                                            <span className="apple-muted-text misa-nowrap">CT gốc</span>
                                        </div>
                                    </div>

                                    <div className="misa-col-2">
                                        <div className="misa-field-label">Tham chiếu</div>
                                        <div className="misa-flex-center misa-gap-6">
                                            <button 
                                                type="button" 
                                                className="misa-btn-tool-ref misa-btn-tool" 
                                                onClick={() => setIsReferenceModalOpen(true)}
                                                title="Chọn chứng từ tham chiếu"
                                            >
                                                ...
                                            </button>
                                            {referenceVouchers.length > 0 && (
                                                <span className="misa-btn-ref-count">
                                                    ({referenceVouchers.length} CT)
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </MisaMasterCard.FormGrid>
                            )}

                            {masterActiveTab === 'payment_slip' && paymentStatus === 'paid' && (
                                <MisaMasterCard.FormGrid>
                                    {paymentMethod === 'cash' && (
                                        <>
                                            <div className="misa-col-6">
                                                <div className="misa-field-label">Người nhận tiền</div>
                                                <Form.Item name="receiver_name" noStyle>
                                                    <Input className="misa-input" placeholder="Họ và tên người nhận tiền" />
                                                </Form.Item>
                                            </div>
                                            <div className="misa-col-6">
                                                <div className="misa-field-label">Địa chỉ người nhận</div>
                                                <Form.Item name="receiver_address" noStyle>
                                                    <Input className="misa-input" placeholder="Địa chỉ người nhận tiền" />
                                                </Form.Item>
                                            </div>
                                            <div className="misa-col-12">
                                                <div className="misa-field-label">Lý do chi</div>
                                                <Form.Item name="spend_reason" noStyle>
                                                    <Input className="misa-input" placeholder="Lý do chi tiền mua dịch vụ..." />
                                                </Form.Item>
                                            </div>
                                        </>
                                    )}

                                </MisaMasterCard.FormGrid>
                            )}
                        </MisaMasterCard.Left>

                        <MisaMasterCard.Right>
                            <MisaMasterCard.MetaRow label="Ngày hạch toán">
                                <Form.Item name="accounting_date" noStyle>
                                    <DatePicker format="DD/MM/YYYY" className="misa-input misa-w-full" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Ngày chứng từ">
                                <Form.Item name="voucher_date" noStyle>
                                    <DatePicker format="DD/MM/YYYY" className="misa-input misa-w-full" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Số chứng từ" required>
                                <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                    <Input className="misa-input misa-table-link-bold" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            {paymentStatus === 'paid' && (
                                <MisaMasterCard.MetaRow label={paymentMethod === 'cash' ? 'Số phiếu chi:' : (paymentMethod === 'bank' ? 'Số UNC:' : 'Số Séc:')}>
                                    <Form.Item name="payment_slip_number" noStyle>
                                        <Input className="misa-input misa-text-green misa-text-bold" />
                                    </Form.Item>
                                </MisaMasterCard.MetaRow>
                            )}

                            {paymentStatus === 'unpaid' && (
                                <>
                                    <MisaMasterCard.MetaRow label="Hạn thanh toán">
                                        <Form.Item name="due_date" noStyle>
                                            <DatePicker format="DD/MM/YYYY" className="misa-input misa-w-full" />
                                        </Form.Item>
                                    </MisaMasterCard.MetaRow>
                                    <MisaMasterCard.MetaRow label="Điều khoản TT">
                                        <Form.Item name="payment_term_code" noStyle>
                                            <Select
                                                placeholder="Chọn ĐKTT"
                                                className="misa-input"
                                                allowClear
                                                options={[
                                                    { value: 'ĐKTT30', label: '30 ngày' },
                                                    { value: 'ĐKTT60', label: '60 ngày' },
                                                    { value: 'ĐKTT90', label: '90 ngày' }
                                                ]}
                                            />
                                        </Form.Item>
                                    </MisaMasterCard.MetaRow>
                                </>
                            )}

                            <MisaTotalCard label="Tổng tiền thanh toán" value={grandTotal} />
                        </MisaMasterCard.Right>
                    </MisaMasterCard>

                    {/* Detail Grid Section */}
                    <div className="misa-detail-card">
                        <div className="misa-grid-tab-bar">
                            <div className="misa-grid-tabs">
                                <button
                                    type="button"
                                    onClick={() => setGridActiveTab('accounting')}
                                    className={`misa-grid-tab-btn ${gridActiveTab === 'accounting' ? 'active' : ''}`}
                                >
                                    1. Hạch toán
                                </button>
                                
                                {invoiceHandling !== 'no_invoice' && (
                                    <button
                                        type="button"
                                        onClick={() => setGridActiveTab('tax')}
                                        className={`misa-grid-tab-btn ${gridActiveTab === 'tax' ? 'active' : ''}`}
                                    >
                                        2. Thuế ({lines.filter(l => l.tax_rate > 0).length})
                                    </button>
                                )}

                                {isPurchaseExpense && (
                                    <button
                                        type="button"
                                        onClick={() => setGridActiveTab('expense_allocation')}
                                        className={`misa-grid-tab-btn ${gridActiveTab === 'expense_allocation' ? 'active' : ''}`}
                                    >
                                        3. Phân bổ chi phí mua hàng ({targetPurchaseVouchers.length})
                                    </button>
                                )}

                                <button
                                    type="button"
                                    onClick={() => setGridActiveTab('reference')}
                                    className={`misa-grid-tab-btn ${gridActiveTab === 'reference' ? 'active' : ''}`}
                                >
                                    {isPurchaseExpense ? '4.' : (invoiceHandling !== 'no_invoice' ? '3.' : '2.')} Tham chiếu ({referenceVouchers.length})
                                </button>
                            </div>
                        </div>

                        {gridActiveTab === 'accounting' && (
                            <Table 
                                columns={accountingColumns}
                                dataSource={lines}
                                pagination={false}
                                size="small"
                                scroll={{ y: 220, x: 1100 }}
                                className="misa-voucher-table"
                            />
                        )}

                        {gridActiveTab === 'tax' && (
                            <Table 
                                columns={taxColumns}
                                dataSource={lines}
                                pagination={false}
                                size="small"
                                scroll={{ y: 220, x: 1100 }}
                                className="misa-voucher-table"
                            />
                        )}

                        {gridActiveTab === 'expense_allocation' && (
                            <div className="misa-page-layout-gray">
                                <div className="misa-flex-between misa-mb-12">
                                    <div>
                                        <span className="misa-font-14 misa-text-bold">
                                            Danh sách chứng từ mua hàng nhập kho được phân bổ chi phí này
                                        </span>
                                        <div className="misa-font-12 apple-muted-text">
                                            Tổng số tiền chi phí phân bổ: <strong className="misa-text-blue">{new Intl.NumberFormat('vi-VN').format(subTotal)} ₫</strong>
                                        </div>
                                    </div>
                                    <Space>
                                        <Button 
                                            icon={<SearchOutlined />}
                                            onClick={() => setIsSelectExpenseModalOpen(true)}
                                            className="misa-btn-tool-sm misa-text-bold"
                                        >
                                            Chọn chứng từ mua hàng
                                        </Button>
                                        <Button 
                                            type="primary"
                                            icon={<CalculatorOutlined />}
                                            onClick={() => setIsAllocateExpenseModalOpen(true)}
                                            className="misa-btn-blue-sm"
                                        >
                                            Phân bổ chi phí
                                        </Button>
                                    </Space>
                                </div>

                                {targetPurchaseVouchers.length === 0 ? (
                                    <div className="misa-empty-table-cell misa-table-card">
                                        <CalculatorOutlined className="misa-empty-icon" />
                                        <div>Chưa chọn chứng từ mua hàng nào để phân bổ. Bấm <strong>"Chọn chứng từ mua hàng"</strong> để bắt đầu.</div>
                                    </div>
                                ) : (
                                    <Table 
                                        dataSource={targetPurchaseVouchers}
                                        columns={[
                                            { title: 'Số chứng từ', dataIndex: 'voucher_number', key: 'voucher_number' },
                                            { title: 'Ngày chứng từ', dataIndex: 'voucher_date', key: 'voucher_date' },
                                            { title: 'Nhà cung cấp', dataIndex: 'supplier_name', key: 'supplier_name' },
                                            { title: 'Tổng tiền hàng', dataIndex: 'sub_total', key: 'sub_total', render: (v: number) => `${new Intl.NumberFormat('vi-VN').format(v)} ₫` },
                                            { title: 'Số tiền phân bổ', dataIndex: 'allocated_amount', key: 'allocated_amount', render: (v: number) => <strong className="misa-text-blue">{new Intl.NumberFormat('vi-VN').format(v)} ₫</strong> }
                                        ]}
                                        pagination={false}
                                        size="small"
                                    />
                                )}
                            </div>
                        )}

                        {gridActiveTab === 'reference' && (
                            <div className="misa-page-layout-gray">
                                <div className="misa-flex-between misa-mb-12">
                                    <span className="misa-font-14 misa-text-bold">
                                        Danh sách chứng từ tham chiếu
                                    </span>
                                    <Button 
                                        icon={<LinkOutlined />}
                                        onClick={() => setIsReferenceModalOpen(true)}
                                        className="misa-btn-tool-sm misa-text-bold"
                                    >
                                        Chọn chứng từ tham chiếu
                                    </Button>
                                </div>

                                {referenceVouchers.length === 0 ? (
                                    <div className="misa-empty-table-cell misa-table-card">
                                        <FileTextOutlined className="misa-empty-icon" />
                                        <div>Chưa có chứng từ tham chiếu nào. Bấm <strong>"Chọn chứng từ tham chiếu"</strong> để liên kết.</div>
                                    </div>
                                ) : (
                                    <Table 
                                        dataSource={referenceVouchers}
                                        columns={[
                                            { title: 'Loại chứng từ', dataIndex: 'voucher_type', key: 'voucher_type' },
                                            { title: 'Số chứng từ', dataIndex: 'voucher_number', key: 'voucher_number' },
                                            { title: 'Ngày chứng từ', dataIndex: 'voucher_date', key: 'voucher_date' },
                                            { title: 'Diễn giải', dataIndex: 'description', key: 'description' },
                                            { title: 'Số tiền', dataIndex: 'total_amount', key: 'total_amount', render: (v: number) => `${new Intl.NumberFormat('vi-VN').format(v)} ₫` }
                                        ]}
                                        pagination={false}
                                        size="small"
                                    />
                                )}
                            </div>
                        )}

                        {/* Action Buttons strictly placed BELOW table as per standard */}
                        <MisaGridActionFooter 
                            onAddLine={addLine}
                            onDeleteAll={deleteAllLines}
                            lineCount={lines.length}
                        />

                        {/* Summary Bar at bottom of table */}
                        <MisaTableSummaryBar 
                            items={[
                                { label: 'Tiền dịch vụ', value: subTotal, format: 'currency' },
                                ...(invoiceHandling !== 'no_invoice' ? [{ label: 'Tiền thuế GTGT', value: totalTax, format: 'currency' as const }] : []),
                                { label: 'Tổng thanh toán', value: grandTotal, format: 'currency', highlight: true }
                            ]}
                        />
                    </div>
                </Form>
                </ModalFrame>
            </Modal>

            {/* Quick Add Sub Modals */}
            <QuickAddContactModal 
                open={isSupplierModalVisible}
                onCancel={() => setIsSupplierModalVisible(false)}
                onSuccess={(newSup) => {
                    if (newSup) {
                        form.setFieldsValue({
                            supplier_id: newSup.id,
                            supplier_name: newSup.name,
                            supplier_address: newSup.address,
                            tax_code: newSup.tax_code
                        });
                    }
                    queryClient.invalidateQueries({ queryKey: ['suppliers'] });
                    setIsSupplierModalVisible(false);
                }}
            />

            <QuickAddEmployeeModal 
                open={isEmployeeModalVisible}
                onCancel={() => setIsEmployeeModalVisible(false)}
                onSuccess={(newEmp) => {
                    if (newEmp) {
                        form.setFieldsValue({ employee_id: newEmp.id });
                    }
                    queryClient.invalidateQueries({ queryKey: ['employees'] });
                    setIsEmployeeModalVisible(false);
                }}
            />

            <QuickAddItemModal
                open={isItemModalVisible}
                onCancel={() => setIsItemModalVisible(false)}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['items-services'] });
                    setIsItemModalVisible(false);
                }}
            />

            <QuickAddPaymentTermModal
                open={isPaymentTermModalVisible}
                onCancel={() => setIsPaymentTermModalVisible(false)}
                onSuccess={(term) => {
                    if (term) {
                        form.setFieldsValue({ payment_term_code: term.code });
                    }
                    setIsPaymentTermModalVisible(false);
                }}
            />

            <ReferenceVoucherModal 
                open={isReferenceModalOpen}
                onCancel={() => setIsReferenceModalOpen(false)}
                onSelect={(vouchers) => {
                    setReferenceVouchers(vouchers);
                    setIsReferenceModalOpen(false);
                }}
            />

            <SelectExpenseVoucherModal
                open={isSelectExpenseModalOpen}
                onCancel={() => setIsSelectExpenseModalOpen(false)}
                onSelect={(selected) => {
                    setTargetPurchaseVouchers(selected);
                    setIsSelectExpenseModalOpen(false);
                }}
            />

            <AllocateExpenseModal
                open={isAllocateExpenseModalOpen}
                onCancel={() => setIsAllocateExpenseModalOpen(false)}
                totalExpenseToAllocate={subTotal}
                items={lines}
                onAllocate={(_allocatedItems: any[]) => {
                    message.info('Đã tính thử phân bổ cục bộ; chưa lưu vì backend chưa cung cấp API ghi nhận phân bổ chi phí.');
                    setIsAllocateExpenseModalOpen(false);
                }}
            />
        </>
    );
};

export default PurchaseServiceModal;
