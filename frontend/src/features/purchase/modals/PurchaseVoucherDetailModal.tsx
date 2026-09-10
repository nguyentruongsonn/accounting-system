import React, { useState, useEffect, useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Alert, Form, Input, InputNumber, DatePicker, Radio, Checkbox, Button, Space } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import { 
    PlusOutlined,
    UploadOutlined, 
    DeleteOutlined, 
    SearchOutlined, 
    CloseOutlined,
    CalculatorOutlined,
    FileTextOutlined,
    EditOutlined
} from '@ant-design/icons';
import dayjs from 'dayjs';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import { 
    MisaMasterCard,
    MisaGridActionFooter,
    MisaTableSummaryBar,
    MisaTotalCard,
    MisaVoucherSummaryCard,
    useVoucherShortcuts,
    useVoucherTotals,
    type VoucherSummaryItem,
    QuickAddContactModal, 
    QuickAddEmployeeModal, 
    QuickAddItemModal, 
    QuickAddPaymentTermModal,
    ReferenceVoucherModal 
} from '../../../components/misa';
import { SelectExpenseVoucherModal } from './SelectExpenseVoucherModal';
import { AllocateExpenseModal } from './AllocateExpenseModal';
import { AllocateDiscountModal } from './AllocateDiscountModal';
import ModalFrame from '../../../components/layout/ModalFrame';
import { AdaptiveSelect as Select } from '../../../components/layout/AdaptiveSelect';
import { getApiErrorMessage } from '../../../utils/apiErrorMessage';

export interface PurchaseVoucherDetailModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
    initialVoucherType?: string;
    editRecord?: any;
    readOnly?: boolean;
    fromPoNumber?: string;
}

// VAT Groups
const VAT_GROUPS = [
    { value: '1', label: '1 - HHDV dùng riêng cho SXKD chịu thuế GTGT đủ ĐK khấu trừ' },
    { value: '2', label: '2 - HHDV dùng riêng cho SXKD không chịu thuế GTGT' },
    { value: '3', label: '3 - HHDV dùng chung cho SXKD chịu thuế và không chịu thuế' },
    { value: '4', label: '4 - HHDV dùng cho dự án đầu tư đủ ĐK khấu trừ' },
    { value: '5', label: '5 - HHDV không phải tổng hợp trên tờ khai 01/GTGT' },
];

function parsePurchaseVoucherCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: T[] }).data;
    }
    throw new Error(`Invalid ${resource} response.`);
}

export const PurchaseVoucherDetailModal: React.FC<PurchaseVoucherDetailModalProps> = ({
    open,
    onCancel,
    onSuccess,
    initialVoucherType = '1. Mua hàng trong nước nhập kho',
    editRecord,
    readOnly = false,
    fromPoNumber
}) => {
    const [form] = Form.useForm();
    // useWatch hooks the instance without reading it during render. This is
    // important because the modal body is portalled and may mount after the
    // parent render when the modal is initially closed.
    const voucherNumber = Form.useWatch('voucher_number', form);
    const supplierId = Form.useWatch('supplier_id', form);
    const queryClient = useQueryClient();
    const [searchParams] = useSearchParams();

    // Configuration states
    const [isViewMode, setIsViewMode] = useState(!!readOnly);
    const [voucherType, setVoucherType] = useState(initialVoucherType);
    const [paymentStatus, setPaymentStatus] = useState<'unpaid' | 'paid'>('unpaid');
    const [paymentMethod, setPaymentMethod] = useState<'cash' | 'bank' | 'cheque_transfer' | 'cheque_cash'>('cash');
    const [invoiceHandling, setInvoiceHandling] = useState<'with_invoice' | 'without_invoice_now' | 'no_invoice'>('with_invoice');
    const [discountMode, setDiscountMode] = useState<'none' | 'item' | 'percent_invoice' | 'amount_invoice'>('none');
    const [invoiceDiscountPercent, setInvoiceDiscountPercent] = useState<number>(0);
    const [showAccounts, setShowAccounts] = useState(true);

    // Active sub-tabs in Master and Grid
    const [masterActiveTab, setMasterActiveTab] = useState<string>('inward');
    const [gridActiveTab, setGridActiveTab] = useState<string>('accounting');

    // Expense Tab Vouchers state
    const [expenseVouchers, setExpenseVouchers] = useState<any[]>([]);
    const [isSelectExpenseModalOpen, setIsSelectExpenseModalOpen] = useState(false);
    const [isAllocateExpenseModalOpen, setIsAllocateExpenseModalOpen] = useState(false);
    const [isAllocateDiscountModalOpen, setIsAllocateDiscountModalOpen] = useState(false);
    const [sourceLoadError, setSourceLoadError] = useState('');
    const [sourceRetryToken, setSourceRetryToken] = useState(0);

    // Quick-add modals & Reference modal
    const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [isItemModalVisible, setIsItemModalVisible] = useState(false);
    const [activeRowIndex, setActiveRowIndex] = useState<number>(0);
    const [isPaymentTermModalVisible, setIsPaymentTermModalVisible] = useState(false);
    const [isReferenceModalOpen, setIsReferenceModalOpen] = useState(false);
    const [referenceVouchers, setReferenceVouchers] = useState<any[]>([]);

    // Derived flags based on Voucher Type
    const isDomesticInward = voucherType.startsWith('1');
    const isDomesticDirect = voucherType.startsWith('2');
    const isImportInward = voucherType.startsWith('3');
    const isImportDirect = voucherType.startsWith('4');

    const isStockInward = isDomesticInward || isImportInward;
    const isDirectExpense = isDomesticDirect || isImportDirect;
    const isImport = isImportInward || isImportDirect;

    // Queries
    const {
        data: suppliers = [],
        isError: isSuppliersError,
        refetch: refetchSuppliers,
    } = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parsePurchaseVoucherCollection<any>(data, 'suppliers');
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
            return parsePurchaseVoucherCollection<any>(data, 'employees');
        },
        enabled: open
    });

    const {
        data: items = [],
        isError: isItemsError,
        refetch: refetchItems,
    } = useQuery({
        queryKey: ['inventory-items'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parsePurchaseVoucherCollection<any>(data, 'inventory items');
        },
        enabled: open
    });

    const {
        data: accounts = [],
        isError: isAccountsError,
        refetch: refetchAccounts,
    } = useQuery({
        queryKey: ['accounts', 'purchase-voucher-detail'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            const rows = parsePurchaseVoucherCollection<any>(data, 'accounts');
            return rows.filter((account: any) => account && account.is_parent !== true && account.is_active !== false);
        },
        enabled: open
    });

    const {
        data: warehouses = [],
        isError: isWarehousesError,
        refetch: refetchWarehouses,
    } = useQuery({
        queryKey: ['warehouses', 'purchase-voucher-detail'],
        queryFn: async () => {
            const { data } = await api.get('/master/warehouses');
            const rows = parsePurchaseVoucherCollection<any>(data, 'warehouses');
            return rows.filter((warehouse: any) => warehouse && warehouse.is_active !== false);
        },
        enabled: open
    });

    const supplierList = suppliers;
    const employeeList = employees;
    const itemList = items;
    const accountList = accounts;
    const warehouseList = warehouses;

    // Reset & initialize form
    useEffect(() => {
        if (open) {
            setSourceLoadError('');
            setIsViewMode(!!readOnly);
            setVoucherType(initialVoucherType);
            setPaymentStatus('unpaid');
            setPaymentMethod('cash');
            setInvoiceHandling('with_invoice');
            setDiscountMode('none');
            setInvoiceDiscountPercent(0);
            setShowAccounts(true);
            setGridActiveTab('accounting');
            setReferenceVouchers([]);
            setExpenseVouchers([]);

            if (initialVoucherType.startsWith('1') || initialVoucherType.startsWith('3')) {
                setMasterActiveTab('inward');
            } else {
                setMasterActiveTab('debt_voucher');
            }

            form.resetFields();

            const targetPo = fromPoNumber || searchParams.get('from_po');

            if (editRecord) {
                form.setFieldsValue({
                    voucher_number: editRecord.invoice_number || editRecord.voucher_number || '',
                    inward_voucher_number: editRecord.inward_voucher_number,
                    supplier_id: editRecord.supplier_id,
                    supplier_name: editRecord.supplier_name || editRecord.seller_name,
                    supplier_address: editRecord.supplier_address || editRecord.address,
                    tax_code: editRecord.tax_code || editRecord.seller_tax_code,
                    accounting_date: editRecord.accounting_date ? dayjs(editRecord.accounting_date) : dayjs(),
                    voucher_date: editRecord.invoice_date ? dayjs(editRecord.invoice_date) : dayjs(),
                    invoice_number: editRecord.invoice_code || editRecord.invoice_number,
                    invoice_symbol: editRecord.invoice_symbol,
                    invoice_form: editRecord.invoice_form,
                    invoice_date: editRecord.invoice_date ? dayjs(editRecord.invoice_date) : dayjs(),
                    invoice_address: editRecord.invoice_address,
                    due_days: editRecord.due_days,
                    due_date: editRecord.due_date ? dayjs(editRecord.due_date) : undefined,
                    payment_term_code: editRecord.payment_term_code,
                    description: editRecord.description,
                    einvoice_lookup_code: editRecord.einvoice_lookup_code,
                    einvoice_lookup_url: editRecord.einvoice_lookup_url,
                    lines: (Array.isArray(editRecord.items)
                        ? editRecord.items
                        : (Array.isArray(editRecord.lines) ? editRecord.lines : [])
                    ).map((it: any) => ({
                        item_id: it.item_id,
                        item_code: it.item_code,
                        item_name: it.item_name || it.description,
                        warehouse: it.warehouse || it.warehouse_code,
                        debit_account: it.debit_account,
                        credit_account: it.credit_account,
                        unit: it.unit,
                        quantity: it.quantity,
                        unit_price: it.unit_price,
                        amount: it.amount,
                        discount_rate: it.discount_rate,
                        discount_amount: it.discount_amount,
                        tax_rate: it.tax_rate,
                        tax_amount: it.tax_amount,
                        tax_account: it.tax_account,
                        vat_group: it.vat_group,
                        import_tax_rate: it.import_tax_rate,
                        import_tax_amount: it.import_tax_amount,
                        import_tax_account: it.import_tax_account,
                        contra_vat_account: it.contra_vat_account,
                        purchase_expense: it.purchase_expense,
                        stock_value: it.stock_value
                    })) || []
                });
            } else if (targetPo) {
                // Pre-populate from Purchase Order
                const today = dayjs();
                form.setFieldsValue({
                    accounting_date: today,
                    voucher_date: today,
                    invoice_date: today,
                    due_date: today.add(30, 'day'),
                    description: `Mua hàng theo đơn mua hàng ${targetPo}`,
                    lines: []
                });

                api.get('/purchase/invoices/next-code').then(res => {
                    const code = res.data?.data?.code || res.data?.code;
                    if (code) {
                        form.setFieldsValue({ voucher_number: code });
                    }
                }).catch(err => {
                    console.warn('Next code unavailable:', err);
                });

                api.get(`/purchase/orders?search=${targetPo}`).then(res => {
                    const list = parsePurchaseVoucherCollection<any>(res.data, 'purchase orders');
                    const po = list.find((o: any) => o.order_number === targetPo || String(o.id) === targetPo);
                    if (!po) {
                        message.warning(`Không tìm thấy đơn mua hàng ${targetPo}; không tự nạp dữ liệu khác.`);
                        return;
                    }
                    const poLines = Array.isArray(po.lines) ? po.lines.map((l: any) => ({
                        item_id: l.item_id,
                        item_code: l.item_code || l.item?.code,
                        item_name: l.item_name || l.item?.name || l.description,
                        warehouse: l.warehouse || l.warehouse_code,
                        debit_account: l.debit_account,
                        credit_account: l.credit_account,
                        unit: l.unit || l.item?.unit,
                        quantity: l.quantity,
                        unit_price: l.unit_price,
                        amount: l.amount,
                        discount_rate: l.discount_rate,
                        discount_amount: l.discount_amount,
                        tax_rate: l.vat_rate ?? l.tax_rate,
                        tax_amount: l.vat_amount ?? l.tax_amount,
                        tax_account: l.tax_account,
                        vat_group: l.vat_group,
                        purchase_expense: l.purchase_expense,
                        stock_value: l.stock_value
                    })) : [];
                    form.setFieldsValue({
                        supplier_id: po.supplier_id,
                        supplier_name: po.supplier_name || po.supplier?.name,
                        supplier_address: po.supplier_address || po.supplier?.address,
                        tax_code: po.tax_code || po.supplier?.tax_code,
                        description: po.order_number ? `Mua hàng theo đơn mua hàng ${po.order_number}` : undefined,
                        payment_term_code: po.payment_term,
                        due_days: po.due_days,
                        lines: poLines
                    });
                    message.info(poLines.length > 0
                        ? `Đã nạp ${poLines.length} dòng có dữ liệu từ đơn mua hàng ${po.order_number || targetPo}.`
                        : 'Đơn mua hàng không cung cấp dòng hàng; vui lòng nhập chứng từ theo dữ liệu thực tế.');
                }).catch((error) => {
                    setSourceLoadError(getApiErrorMessage(error, 'Không thể tải dữ liệu đơn mua hàng để khởi tạo chứng từ.'));
                });
            } else {
                const today = dayjs();
                form.setFieldsValue({
                    accounting_date: today,
                    voucher_date: today,
                    invoice_date: today,
                    due_date: today.add(30, 'day'),
                    voucher_number: '',
                    inward_voucher_number: undefined,
                    description: undefined,
                    lines: []
                });

                api.get('/purchase/invoices/next-code').then(res => {
                    const code = res.data?.data?.code || res.data?.code;
                    if (code) {
                        form.setFieldsValue({ voucher_number: code });
                    }
                }).catch(err => {
                    console.warn('Next code unavailable:', err);
                });
            }
        }
    // `itemList` is a derived empty array while the catalogue request is
    // unresolved, so including it here creates a new dependency on every
    // render and can repeatedly reset the form (React max-update-depth).
    // Catalogue options update independently; initialization should only run
    // when the modal/source record changes.
    }, [open, initialVoucherType, editRecord, readOnly, fromPoNumber, searchParams, form, sourceRetryToken]);

    // Handle voucher type switch
    const handleVoucherTypeChange = (newType: string) => {
        setVoucherType(newType);
        const isDir = newType.startsWith('2') || newType.startsWith('4');

        if (isDir) {
            setMasterActiveTab('debt_voucher');
        } else {
            setMasterActiveTab('inward');
        }

        const curLines = form.getFieldValue('lines') || [];
        form.setFieldsValue({
            lines: curLines.map((l: any) => ({ ...l }))
        });
    };

    // Handle Payment Status switch
    const handlePaymentStatusChange = (newStatus: 'unpaid' | 'paid') => {
        setPaymentStatus(newStatus);
        const curLines = form.getFieldValue('lines') || [];
        form.setFieldsValue({
            lines: curLines.map((l: any) => ({ ...l }))
        });

        if (newStatus === 'paid') {
            if (paymentMethod === 'cash') setMasterActiveTab('cash_receipt');
        } else {
            setMasterActiveTab(isStockInward ? 'inward' : 'debt_voucher');
        }
    };

    // Handle Payment Method switch
    const handlePaymentMethodChange = (newMethod: 'cash' | 'bank' | 'cheque_transfer' | 'cheque_cash') => {
        setPaymentMethod(newMethod);
        const curLines = form.getFieldValue('lines') || [];
        form.setFieldsValue({
            lines: curLines.map((l: any) => ({ ...l }))
        });

        if (newMethod === 'cash') setMasterActiveTab('cash_receipt');
    };

    // Handle Payment Term change -> Auto calculate Due Days & Due Date
    const handlePaymentTermChange = (termCode: string) => {
        let days = 30;
        if (termCode === 'ĐKTT45') days = 45;
        if (termCode === 'ĐKTT60') days = 60;
        if (termCode === 'COD') days = 0;

        const voucherDate = form.getFieldValue('voucher_date') || dayjs();
        form.setFieldsValue({
            payment_term_code: termCode,
            due_days: days,
            due_date: dayjs(voucherDate).add(days, 'day')
        });
    };

    // Handle Due Days change -> Auto calculate Due Date
    const handleDueDaysChange = (days: number | null) => {
        const d = Number(days) || 0;
        const voucherDate = form.getFieldValue('voucher_date') || dayjs();
        form.setFieldsValue({
            due_days: d,
            due_date: dayjs(voucherDate).add(d, 'day')
        });
    };

    // Auto fill supplier
    const handleSupplierChange = (supplierId: number) => {
        const supplier = supplierList.find((s: any) => s.id === supplierId);
        if (supplier) {
            form.setFieldsValue({
                supplier_id: supplier.id,
                supplier_code: supplier.code,
                supplier_name: supplier.name,
                supplier_address: supplier.address,
                tax_code: supplier.tax_code,
                deliverer_name: supplier.contact_name || supplier.name,
                receiver_name: supplier.contact_name || supplier.name,
                description: `Mua hàng của ${supplier.name}`
            });
        }
    };

    // Auto fill item on line
    const handleItemChange = (index: number, itemId: number) => {
        const item = itemList.find((i: any) => i.id === itemId);
        if (item) {
            const curLines = form.getFieldValue('lines') || [];
            const price = item.cost_price;
            const qty = curLines[index]?.quantity;
            const amt = price !== undefined && qty !== undefined ? Number(price) * Number(qty) : undefined;
            const discRate = curLines[index]?.discount_rate ?? (discountMode === 'percent_invoice' ? invoiceDiscountPercent : undefined);
            const discAmt = amt !== undefined && discRate !== undefined ? amt * (Number(discRate) / 100) : undefined;
            const netAmt = amt !== undefined && discAmt !== undefined ? amt - discAmt : undefined;
            const taxRate = curLines[index]?.tax_rate;
            const taxAmt = netAmt !== undefined && taxRate !== undefined && Number(taxRate) > 0
                ? Math.round(netAmt * Number(taxRate) / 100)
                : curLines[index]?.tax_amount;
            const impRate = curLines[index]?.import_tax_rate;
            const impAmt = isImport && netAmt !== undefined && impRate !== undefined
                ? Math.round(netAmt * Number(impRate) / 100)
                : curLines[index]?.import_tax_amount;

            curLines[index] = {
                ...curLines[index],
                item_id: item.id,
                item_code: item.code,
                item_name: item.name,
                unit: item.unit,
                unit_price: price,
                amount: amt,
                discount_rate: discRate,
                discount_amount: discAmt,
                tax_amount: taxAmt,
                import_tax_amount: impAmt,
                stock_value: netAmt !== undefined ? netAmt + (Number(curLines[index]?.purchase_expense) || 0) : undefined
            };
            form.setFieldsValue({ lines: [...curLines] });
        }
    };

    // Handle % Discount for entire invoice
    const handleInvoiceDiscountPercentChange = (val: number | null) => {
        const pct = Number(val) || 0;
        setInvoiceDiscountPercent(pct);
        const curLines = form.getFieldValue('lines') || [];
        const updated = curLines.map((l: any) => {
            const amt = Number(l.amount) || 0;
            const discAmt = amt * (pct / 100);
            const netAmt = amt - discAmt;
            const taxRate = l.tax_rate;
            const taxAmt = taxRate !== undefined && Number(taxRate) > 0 ? Math.round(netAmt * Number(taxRate) / 100) : l.tax_amount;
            return {
                ...l,
                discount_rate: pct,
                discount_amount: discAmt,
                tax_amount: taxAmt,
                stock_value: netAmt + (Number(l.purchase_expense) || 0)
            };
        });
        form.setFieldsValue({ lines: updated });
    };

    // Handle Discount Distributed from AllocateDiscountModal
    const handleDiscountAllocated = (allocatedItems: any[]) => {
        const curLines = form.getFieldValue('lines') || [];
        const updated = curLines.map((l: any, idx: number) => {
            const discAmt = allocatedItems[idx]?.allocated_discount || 0;
            const amt = Number(l.amount) || 0;
            const discRate = amt > 0 ? (discAmt / amt) * 100 : 0;
            const netAmt = amt - discAmt;
            const taxRate = l.tax_rate;
            const taxAmt = taxRate !== undefined && Number(taxRate) > 0 ? Math.round(netAmt * Number(taxRate) / 100) : l.tax_amount;
            return {
                ...l,
                discount_rate: discRate.toFixed(2),
                discount_amount: discAmt,
                tax_amount: taxAmt,
                stock_value: netAmt + (Number(l.purchase_expense) || 0)
            };
        });
        form.setFieldsValue({ lines: updated });
        message.success('Đã phân bổ chiết khấu thành công vào các dòng hàng');
    };

    // Allocate Expenses across lines
    const handleExpenseAllocated = (allocatedItems: any[]) => {
        const curLines = form.getFieldValue('lines') || [];
        const updated = curLines.map((l: any, idx: number) => {
            const exp = allocatedItems[idx]?.allocated_expense || 0;
            const amt = (Number(l.amount) || 0) - (Number(l.discount_amount) || 0);
            return {
                ...l,
                purchase_expense: exp,
                stock_value: amt + exp
            };
        });
        form.setFieldsValue({ lines: updated });
        message.success('Đã phân bổ chi phí thành công vào giá trị nhập kho');
    };

    // Calculate totals using useVoucherTotals hook
    const formLines = Form.useWatch('lines', form) || [];
    const totals = useVoucherTotals(formLines, {
        includeTax: !isImport && invoiceHandling === 'with_invoice',
        includeImportTax: isImport,
        includePurchaseExpense: isStockInward
    });

    const totalStockValue = (totals.subTotal - totals.totalDiscount) + totals.totalPurchaseExpense;

    // Total expenses from expense vouchers
    const totalSelectedExpense = expenseVouchers.reduce((acc: number, curr: any) => acc + (Number(curr.this_allocation) || 0), 0);

    // Add new line handler
    const handleAddLine = useCallback(() => {
        const cur = form.getFieldValue('lines') || [];

        form.setFieldsValue({
            lines: [
                ...cur,
                { 
                    item_id: undefined,
                    item_code: undefined,
                    item_name: '',
                    warehouse: undefined,
                    debit_account: undefined,
                    credit_account: undefined,
                    unit: '',
                    quantity: undefined,
                    unit_price: undefined,
                    amount: undefined,
                    discount_rate: undefined,
                    discount_amount: undefined,
                    tax_rate: undefined,
                    tax_amount: undefined,
                    tax_account: undefined,
                    vat_group: undefined,
                    import_tax_rate: undefined,
                    import_tax_amount: undefined,
                    purchase_expense: undefined,
                    stock_value: undefined
                }
            ]
        });
    }, [form]);

    // Add note handler
    const handleAddNote = useCallback(() => {
        const cur = form.getFieldValue('lines') || [];
        form.setFieldsValue({
            lines: [
                ...cur,
                { 
                    item_id: null,
                    item_code: '',
                    item_name: 'Ghi chú thêm...', 
                    quantity: 0, 
                    unit_price: 0, 
                    amount: 0, 
                    tax_amount: 0,
                    stock_value: 0
                }
            ]
        });
    }, [form]);

    // Delete all lines handler
    const handleDeleteAll = useCallback(() => {
        form.setFieldValue('lines', []);
        message.success('Đã xóa toàn bộ dòng');
    }, [form]);

    // Handle Save
    const handleSave = useCallback(async (closeAfterSave = true) => {
        try {
            if (paymentMethod === 'bank') {
                message.info('Thanh toán tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ; chứng từ cũ chỉ được xem.');
                return;
            }
            const values = await form.validateFields();

            const payload = {
                supplier_id: values.supplier_id,
                supplier_name: values.supplier_name,
                deliverer_name: values.deliverer_name,
                tax_code: values.tax_code,
                employee_id: values.employee_id,
                attached_docs: values.attached_docs === undefined ? undefined : String(values.attached_docs),
                currency: values.currency,
                exchange_rate: values.exchange_rate,
                invoice_number: values.voucher_number,
                voucher_type: voucherType,
                invoice_date: values.voucher_date?.format('YYYY-MM-DD'),
                accounting_date: values.accounting_date?.format('YYYY-MM-DD'),
                due_date: values.due_date?.format('YYYY-MM-DD'),
                purchase_expense: totals.totalPurchaseExpense,
                total_stock_value: totalStockValue,
                description: values.description,
                payment_method: paymentStatus === 'unpaid' ? 'unpaid' : paymentMethod,
                is_include_invoice: invoiceHandling === 'with_invoice',
                lines: values.lines?.map((l: any) => ({
                    item_id: l.item_id,
                    description: l.item_name || values.description,
                    warehouse: l.warehouse,
                    warehouse_code: l.warehouse,
                    warehouse_id: warehouseList.find((warehouse: any) =>
                        (warehouse.code || warehouse.warehouse_code) === l.warehouse
                    )?.id,
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
                    import_tax_rate: l.import_tax_rate,
                    import_tax_amount: l.import_tax_amount,
                    purchase_expense: l.purchase_expense,
                    stock_value: l.stock_value,
                    invoice_symbol: values.invoice_symbol,
                    invoice_number: values.invoice_number || values.voucher_number,
                    invoice_date: values.invoice_date ? values.invoice_date.format('YYYY-MM-DD') : undefined
                })) || []
            };

            const response = editRecord?.id
                ? await api.put(`/purchase/invoices/${editRecord.id}`, payload)
                : await api.post('/purchase/invoices', payload);
            const persistedInvoice = response?.data?.data ?? response?.data;
            if (!persistedInvoice || persistedInvoice.id === undefined || persistedInvoice.id === null) {
                throw new Error('Máy chủ không trả về chứng từ mua hàng đã lưu; không thể báo thành công.');
            }
            message.success(`Lưu chứng từ ${values.voucher_number} thành công!`);
            queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] });
            if (onSuccess) onSuccess();

            if (closeAfterSave) {
                onCancel();
            } else {
                form.setFieldsValue({
                    voucher_number: '',
                    inward_voucher_number: undefined,
                    lines: []
                });
            }
        } catch (err: any) {
            if (err?.errorFields && err.errorFields.length > 0) {
                const fieldNameMap: Record<string, string> = {
                    supplier_id: 'Nhà cung cấp',
                    voucher_number: 'Số chứng từ / Số phiếu nhập',
                    voucher_date: 'Ngày chứng từ',
                    accounting_date: 'Ngày hạch toán',
                    due_date: 'Hạn thanh toán',
                    lines: 'Chi tiết hàng tiền'
                };

                const failedLabels = err.errorFields.map((f: any) => {
                    const fieldKey = Array.isArray(f.name) ? f.name[0] : f.name;
                    return fieldNameMap[fieldKey] || fieldKey;
                });

                message.error(
                    `Vui lòng nhập đầy đủ thông tin tại: ${failedLabels.join(', ')}`,
                    { duration: 5 },
                );

                // Auto focus first failed field
                if (err.errorFields[0]?.name) {
                    form.scrollToField(err.errorFields[0].name);
                }
            } else if (err?.response?.data?.errors) {
                const backendErrors = err.response.data.errors;
                const formFieldsToSet: any[] = [];
                const errorMessages: string[] = [];

                Object.keys(backendErrors).forEach((key) => {
                    const msgs = backendErrors[key];
                    const msgText = Array.isArray(msgs) ? msgs.join(', ') : msgs;
                    errorMessages.push(msgText);

                    if (key === 'invoice_number') {
                        formFieldsToSet.push({ name: 'voucher_number', errors: [msgText] });
                        formFieldsToSet.push({ name: 'invoice_number', errors: [msgText] });
                    } else if (key === 'supplier_id') {
                        formFieldsToSet.push({ name: 'supplier_id', errors: [msgText] });
                    } else if (key === 'attached_docs') {
                        formFieldsToSet.push({ name: 'attached_docs', errors: [msgText] });
                    } else if (key === 'accounting_date') {
                        formFieldsToSet.push({ name: 'accounting_date', errors: [msgText] });
                    } else if (key === 'due_date') {
                        formFieldsToSet.push({ name: 'due_date', errors: [msgText] });
                    } else if (key.startsWith('lines.')) {
                        const parts = key.split('.');
                        const lineIdx = Number(parts[1]);
                        const fieldName = parts[2];
                        formFieldsToSet.push({ name: ['lines', lineIdx, fieldName], errors: [msgText] });
                    } else {
                        formFieldsToSet.push({ name: key, errors: [msgText] });
                    }
                });

                form.setFields(formFieldsToSet);
                message.error(
                    `Lỗi dữ liệu: ${errorMessages.join('; ')}`,
                    { duration: 6 },
                );
            } else {
                const msg = err?.response?.data?.error || err?.response?.data?.message || err?.message || 'Có lỗi xảy ra khi lưu chứng từ!';
                message.error(msg, { duration: 5 });
            }
        }
    }, [form, paymentStatus, paymentMethod, isDirectExpense, isImport, voucherType, totals.totalPurchaseExpense, totalStockValue, invoiceHandling, onSuccess, onCancel, queryClient, warehouseList, editRecord]);

    // Switch from View to Edit mode (handling unpost confirmation if posted)
    const handleSwitchToEdit = () => {
        if (editRecord?.is_posted) {
            Modal.confirm({
                title: 'Xác nhận bỏ ghi sổ',
                content: `Chứng từ ${editRecord.invoice_number || editRecord.voucher_number || ''} đã được ghi sổ. Bạn có muốn BỎ GHI SỔ để chỉnh sửa không?`,
                okText: 'Bỏ ghi và Sửa',
                okType: 'primary',
                cancelText: 'Hủy',
                onOk: async () => {
                    try {
                        const response = await api.post(`/purchase/invoices/${editRecord.id}/unpost`);
                        if (typeof response?.data?.message !== 'string') {
                            throw new Error('Máy chủ không xác nhận đã bỏ ghi sổ chứng từ.');
                        }
                        message.success('Đã bỏ ghi sổ chứng từ!');
                        queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] });
                        setIsViewMode(false);
                    } catch (e: any) {
                        message.error(e?.response?.data?.message || 'Không thể bỏ ghi sổ!');
                    }
                }
            });
        } else {
            setIsViewMode(false);
        }
    };

    // Keyboard shortcuts hook integration (F7, Ctrl+S, Ctrl+Shift+S, Esc)
    useVoucherShortcuts({
        onSave: () => !isViewMode && handleSave(true),
        onSaveAndNew: () => !isViewMode && handleSave(false),
        onAddLine: handleAddLine,
        onClose: onCancel,
        enabled: open
    });

    // Master Subtabs definition
    const masterTabs = [
        ...(paymentStatus === 'paid' && paymentMethod === 'cash' ? [{ key: 'cash_receipt', label: 'Phiếu chi' }] : []),
        ...(isStockInward ? [{ key: 'inward', label: 'Phiếu nhập' }] : []),
        ...(isDirectExpense ? [{ key: 'debt_voucher', label: 'Chứng từ ghi nợ' }] : []),
        ...(!isImport && invoiceHandling === 'with_invoice' ? [{ key: 'invoice', label: 'Hóa đơn' }] : []),
        ...(isImport ? [{ key: 'customs', label: 'Tờ khai hải quan' }] : [])
    ];

    // Summary Card items breakdown (7 financial indicators)
    const summaryCardItems: VoucherSummaryItem[] = [
        { label: 'Tổng tiền hàng', value: totals.subTotal },
        ...(discountMode !== 'none' ? [{ label: 'Tiền chiết khấu', value: totals.totalDiscount }] : []),
        { label: 'Thuế GTGT', value: totals.totalTax, hideIfZero: !(!isImport && invoiceHandling === 'with_invoice') },
        ...(isImport ? [{ label: 'Thuế nhập khẩu', value: totals.totalImportTax }] : []),
        { label: 'Tổng tiền thanh toán', value: totals.grandTotal, isTotal: true },
        { label: 'Chi phí mua hàng', value: totals.totalPurchaseExpense, hideIfZero: !isStockInward },
        { label: isStockInward ? 'Giá trị nhập kho' : 'Tổng giá trị', value: totalStockValue, isTotal: true }
    ];

    return (
        <Modal
            open={open}
            onCancel={onCancel}
            width="100vw"
            style={{ top: 0, margin: 0, paddingBottom: 0, maxWidth: '100vw' }}
            className="misa-voucher-modal misa-voucher-modal-container"
            closeIcon={<CloseOutlined className="misa-btn-tool-sm" />}
            styles={{
                header: { padding: '10px 18px', borderBottom: '1px solid #e5e7eb' },
                body: { padding: '12px 16px', maxHeight: 'calc(100vh - 190px)', overflowY: 'auto', overflowX: 'hidden', display: 'flex', flexDirection: 'column', gap: 10, background: '#eaedf2' }
            }}
            title={
                <div className="misa-modal-header-wrapper">
                    <div className="misa-flex-center misa-gap-12">
                        <span className="misa-voucher-header-title">
                            {voucherType} {voucherNumber || ''}
                        </span>
                        {isViewMode && (
                            <span className="misa-apple-pill misa-apple-pill-blue">
                                <span className="misa-apple-pill-dot" />
                                Chế độ xem
                            </span>
                        )}
                    </div>
                </div>
            }
            footer={
                <div className="misa-modal-footer">
                    <Button onClick={onCancel} className="misa-btn-secondary">
                        {isViewMode ? 'Đóng (Esc)' : 'Hủy (Esc)'}
                    </Button>
                    {isViewMode ? (
                        <Button 
                            type="primary" 
                            icon={<EditOutlined />}
                            onClick={handleSwitchToEdit}
                            className="misa-btn-primary misa-text-bold"
                        >
                            Sửa (Ctrl+E)
                        </Button>
                    ) : (
                        <Space size={8}>
                            <Button
                                onClick={() => handleSave(false)}
                                className="misa-btn-secondary misa-text-bold"
                            >
                                Cất và Thêm
                            </Button>
                            <Button
                                type="primary"
                                onClick={() => handleSave(true)}
                                className="misa-btn-primary misa-text-bold"
                            >
                                Cất
                            </Button>
                        </Space>
                    )}
                </div>
            }
        >
            <ModalFrame bodyStyle={{ width: '100%', maxWidth: '100%', minWidth: 0, overflowX: 'hidden' }}>
            {sourceLoadError && (fromPoNumber || searchParams.get('from_po')) && (
                <Alert
                    type="error"
                    showIcon
                    title="Không thể tải dữ liệu đơn mua hàng"
                    description={sourceLoadError}
                    action={<Button size="small" onClick={() => setSourceRetryToken(token => token + 1)}>Thử lại đơn mua hàng</Button>}
                    className="misa-mb-8"
                />
            )}
            {(isSuppliersError || isEmployeesError || isItemsError || isAccountsError || isWarehousesError) && (
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
                            title="Không thể tải danh mục hàng hóa"
                            action={<Button size="small" onClick={() => void refetchItems()}>Thử lại danh mục hàng hóa</Button>}
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
                    {isWarehousesError && (
                        <Alert
                            type="error"
                            showIcon
                            title="Không thể tải danh mục kho"
                            action={<Button size="small" onClick={() => void refetchWarehouses()}>Thử lại danh mục kho</Button>}
                        />
                    )}
                </div>
            )}
            <Form form={form} layout="vertical" disabled={isViewMode} style={{ width: '100%', maxWidth: '100%', minWidth: 0 }}>
                {/* 1. TOP SETTINGS BAR */}
                <div className="misa-config-bar">
                    <div className="misa-config-bar-left">
                        {/* Voucher Type Dropdown */}
                        <div className="misa-flex-center misa-gap-6">
                            <Select 
                                value={voucherType}
                                onChange={handleVoucherTypeChange}
                                className="misa-input misa-select-w275"
                                options={[
                                    { value: '1. Mua hàng trong nước nhập kho', label: '1. Mua hàng trong nước nhập kho' },
                                    { value: '2. Mua hàng trong nước không qua kho', label: '2. Mua hàng trong nước không qua kho' },
                                    { value: '3. Mua hàng nhập khẩu nhập kho', label: '3. Mua hàng nhập khẩu nhập kho' },
                                    { value: '4. Mua hàng nhập khẩu không qua kho', label: '4. Mua hàng nhập khẩu không qua kho' },
                                ]}
                            />
                        </div>

                        {/* Source Reference Input */}
                        <div className="misa-flex-center misa-gap-6">
                            <Input 
                                placeholder="Nhập số hợp đồng/đơn mua hàng..."
                                prefix={<SearchOutlined className="apple-muted-text" />}
                                className="misa-input misa-input-w230"
                            />
                        </div>

                        {/* Payment Radio & Method Dropdown */}
                        <div className="misa-flex-center misa-gap-12">
                            <Radio.Group value={paymentStatus} onChange={e => handlePaymentStatusChange(e.target.value)}>
                                <Radio value="unpaid"><span className="misa-text-semibold">Chưa thanh toán</span></Radio>
                                <Radio value="paid"><span className="misa-text-semibold">Thanh toán ngay</span></Radio>
                            </Radio.Group>

                            {paymentStatus === 'paid' && (
                                <Select 
                                    value={paymentMethod}
                                    onChange={handlePaymentMethodChange}
                                    className="misa-input misa-select-w155"
                                    options={[
                                        { value: 'cash', label: 'Tiền mặt' },
                                        { value: 'cheque_transfer', label: 'Séc chuyển khoản' },
                                        { value: 'cheque_cash', label: 'Séc tiền mặt' },
                                    ]}
                                />
                            )}
                        </div>

                        {/* Invoice Handling Dropdown (for Domestic) */}
                        {!isImport && (
                            <div className="misa-flex-center misa-gap-6">
                                <Select 
                                    value={invoiceHandling}
                                    onChange={setInvoiceHandling}
                                    className="misa-input misa-select-w170"
                                    options={[
                                        { value: 'with_invoice', label: 'Nhận kèm hóa đơn' },
                                        { value: 'without_invoice_now', label: 'Không kèm hóa đơn' },
                                        { value: 'no_invoice', label: 'Không có hóa đơn' },
                                    ]}
                                />
                            </div>
                        )}
                    </div>

                    <div className="misa-config-bar-right">
                        <Checkbox checked={showAccounts} onChange={e => setShowAccounts(e.target.checked)}>
                            <span className="apple-muted-text">Hiển thị tài khoản</span>
                        </Checkbox>
                    </div>
                </div>

                {/* 2. MASTER SECTION (MisaMasterCard + MisaTotalCard) */}
                <MisaMasterCard className="misa-mb-12">
                    {/* Master Sub-tabs Bar */}
                    <div className="misa-master-tabs">
                        {masterTabs.map(tab => (
                            <button 
                                key={tab.key}
                                type="button"
                                className={`misa-master-tab-btn ${masterActiveTab === tab.key ? 'active' : ''}`}
                                onClick={() => setMasterActiveTab(tab.key)}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    {/* Master Form Fields */}
                    <div className="misa-master-layout">
                        {/* LEFT AREA: Fields based on Active Master Tab */}
                        <div className="misa-master-left">
                            {/* Tab 1: Phiếu nhập (Loại 1 & 3) */}
                            {masterActiveTab === 'inward' && (
                                <div className="misa-form-grid">
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Mã nhà cung cấp <span className="misa-text-danger">*</span></div>
                                        <div className="misa-input-group">
                                            <Form.Item name="supplier_id" noStyle rules={[{ required: true, message: 'Chọn nhà cung cấp' }]}>
                                                <Select 
                                                    showSearch 
                                                    variant="borderless" 
                                                    className="misa-w-full"
                                                    placeholder="Chọn nhà cung cấp..."
                                                    onChange={handleSupplierChange}
                                                    popupMatchSelectWidth={false}
                                                    popupClassName="misa-multicolumn-supplier-popup"
                                                    dropdownStyle={{ minWidth: 950, width: 950 }}
                                                    optionLabelProp="label"
                                                    options={supplierList.map((s: any) => ({ 
                                                        value: s.id, 
                                                        label: `${s.code} - ${s.name}`,
                                                        code: s.code,
                                                        name: s.name,
                                                        address: s.address || 'Hà Nội',
                                                        tax_code: s.tax_code || '0108889999',
                                                        phone: s.phone || '0243.888.999'
                                                    }))}
                                                    optionRender={(option) => (
                                                        <div className="misa-cell-dropdown-grid-5col">
                                                            <span className="misa-text-semibold">{option.data.code}</span>
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
                                                                    className="misa-btn-tool-sm misa-text-blue misa-text-semibold"
                                                                >
                                                                    Thêm mới nhà cung cấp
                                                                </Button>
                                                            </div>
                                                        </div>
                                                    )}
                                                />
                                            </Form.Item>
                                            <button type="button" className="misa-plus-btn" onClick={() => setIsSupplierModalVisible(true)}>
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
                                        <div className="misa-field-label">Người giao hàng</div>
                                        <Form.Item name="deliverer_name" noStyle>
                                            <Input className="misa-input" placeholder="Họ và tên người giao hàng" />
                                        </Form.Item>
                                    </div>

                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Địa chỉ</div>
                                        <Form.Item name="supplier_address" noStyle>
                                            <Input className="misa-input" placeholder="Địa chỉ nhà cung cấp" />
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
                                            <button type="button" className="misa-plus-btn" onClick={() => setIsEmployeeModalVisible(true)}>
                                                <PlusOutlined className="misa-btn-plus-icon-sm" />
                                            </button>
                                        </div>
                                    </div>

                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Diễn giải</div>
                                        <Form.Item name="description" noStyle>
                                            <Input 
                                                className="misa-input" 
                                                placeholder="Nội dung diễn giải..."
                                                onChange={e => {
                                                    const val = e.target.value;
                                                    const cur = form.getFieldValue('lines') || [];
                                                    form.setFieldsValue({ lines: cur.map((l: any) => ({ ...l, item_name: val })) });
                                                }}
                                            />
                                        </Form.Item>
                                    </div>

                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Kèm theo</div>
                                        <div className="misa-flex-center misa-gap-4">
                                            <Form.Item name="attached_docs" noStyle>
                                                <Input className="misa-input" style={{ width: 90 }} placeholder="Số lượng" />
                                            </Form.Item>
                                            <span className="apple-muted-text misa-nowrap">Chứng từ gốc</span>
                                        </div>
                                    </div>

                                    {/* 3-Dot Reference Button */}
                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Tham chiếu</div>
                                        <div className="misa-flex-center misa-gap-6">
                                            <button 
                                                type="button" 
                                                className="misa-btn-tool misa-btn-tool-ref" 
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

                                    {paymentStatus === 'unpaid' && (
                                        <>
                                            <div className="misa-col-5">
                                                <div className="misa-field-label">Điều khoản thanh toán</div>
                                                <div className="misa-input-group">
                                                    <Form.Item name="payment_term_code" noStyle>
                                                        <Select 
                                                            className="misa-w-full"
                                                            variant="borderless"
                                                            placeholder="Chọn điều khoản..."
                                                            onChange={handlePaymentTermChange}
                                                            options={[
                                                                { value: 'ĐKTT30', label: 'Net 30' },
                                                                { value: 'ĐKTT45', label: 'Net 45' },
                                                                { value: 'ĐKTT60', label: 'Net 60' },
                                                                { value: 'COD', label: 'COD' },
                                                            ]}
                                                        />
                                                    </Form.Item>
                                                    <button 
                                                        type="button" 
                                                        className="misa-plus-btn"
                                                        onClick={() => setIsPaymentTermModalVisible(true)}
                                                        title="Thêm điều khoản thanh toán"
                                                    >
                                                        <PlusOutlined className="misa-btn-plus-icon-sm" />
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="misa-col-3">
                                                <div className="misa-field-label">Số ngày được nợ</div>
                                                <Form.Item name="due_days" noStyle>
                                                    <InputNumber 
                                                        className="misa-input misa-w-full" 
                                                        min={0}
                                                        onChange={handleDueDaysChange}
                                                    />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-col-4">
                                                <div className="misa-field-label">Hạn thanh toán</div>
                                                <Form.Item name="due_date" noStyle>
                                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                                </Form.Item>
                                            </div>
                                        </>
                                    )}
                                </div>
                            )}

                            {/* Tab 2: Chứng từ ghi nợ (Loại 2 & 4) */}
                            {masterActiveTab === 'debt_voucher' && (
                                <div className="misa-form-grid">
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Mã nhà cung cấp <span className="misa-text-danger">*</span></div>
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
                                                    options={supplierList.map((s: any) => ({ value: s.id, label: `${s.code} - ${s.name}` }))}
                                                />
                                            </Form.Item>
                                            <button type="button" className="misa-plus-btn" onClick={() => setIsSupplierModalVisible(true)}>
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

                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Địa chỉ</div>
                                        <Form.Item name="supplier_address" noStyle>
                                            <Input className="misa-input" placeholder="Địa chỉ nhà cung cấp" />
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
                                            <button type="button" className="misa-plus-btn" onClick={() => setIsEmployeeModalVisible(true)}>
                                                <PlusOutlined className="misa-btn-plus-icon-sm" />
                                            </button>
                                        </div>
                                    </div>

                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Diễn giải</div>
                                        <Form.Item name="description" noStyle>
                                            <Input className="misa-input" placeholder="Nội dung ghi nợ..." />
                                        </Form.Item>
                                    </div>

                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Kèm theo</div>
                                        <div className="misa-flex-center misa-gap-4">
                                            <Form.Item name="attached_docs" noStyle>
                                                <Input className="misa-input" style={{ width: 90 }} placeholder="Số lượng" />
                                            </Form.Item>
                                            <span className="apple-muted-text misa-nowrap">Chứng từ gốc</span>
                                        </div>
                                    </div>

                                    {/* 3-Dot Reference Button */}
                                    <div className="misa-col-12">
                                        <div className="misa-field-label">Tham chiếu</div>
                                        <div className="misa-flex-center misa-gap-6">
                                            <button 
                                                type="button" 
                                                className="misa-btn-tool misa-btn-tool-ref" 
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

                                    {paymentStatus === 'unpaid' && (
                                        <>
                                            <div className="misa-col-5">
                                                <div className="misa-field-label">Điều khoản thanh toán</div>
                                                <div className="misa-input-group">
                                                    <Form.Item name="payment_term_code" noStyle>
                                                        <Select 
                                                            className="misa-w-full"
                                                            variant="borderless"
                                                            placeholder="Chọn điều khoản..."
                                                            onChange={handlePaymentTermChange}
                                                            options={[
                                                                { value: 'ĐKTT30', label: 'Net 30' },
                                                                { value: 'ĐKTT45', label: 'Net 45' },
                                                                { value: 'ĐKTT60', label: 'Net 60' },
                                                                { value: 'COD', label: 'COD' },
                                                            ]}
                                                        />
                                                    </Form.Item>
                                                    <button 
                                                        type="button" 
                                                        className="misa-plus-btn"
                                                        onClick={() => setIsPaymentTermModalVisible(true)}
                                                        title="Thêm điều khoản thanh toán"
                                                    >
                                                        <PlusOutlined className="misa-btn-plus-icon-sm" />
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="misa-col-3">
                                                <div className="misa-field-label">Số ngày được nợ</div>
                                                <Form.Item name="due_days" noStyle>
                                                    <InputNumber 
                                                        className="misa-input misa-w-full" 
                                                        min={0}
                                                        onChange={handleDueDaysChange}
                                                    />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-col-4">
                                                <div className="misa-field-label">Hạn thanh toán</div>
                                                <Form.Item name="due_date" noStyle>
                                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                                </Form.Item>
                                            </div>
                                        </>
                                    )}
                                </div>
                            )}

                            {/* Tab 3: Hóa đơn */}
                            {masterActiveTab === 'invoice' && (
                                <div className="misa-form-grid">
                                    <div className="misa-col-3">
                                        <div className="misa-field-label">Mẫu số hóa đơn</div>
                                        <Form.Item name="invoice_form" noStyle>
                                            <Input className="misa-input" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-3">
                                        <div className="misa-field-label">Ký hiệu hóa đơn</div>
                                        <Form.Item name="invoice_symbol" noStyle>
                                            <Input className="misa-input" placeholder="1C26TBB" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-3">
                                        <div className="misa-field-label">Số hóa đơn</div>
                                        <Form.Item name="invoice_number" noStyle>
                                            <Input className="misa-input misa-text-semibold misa-text-blue" placeholder="00012845" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-3">
                                        <div className="misa-field-label">Ngày hóa đơn</div>
                                        <Form.Item name="invoice_date" noStyle>
                                            <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Mã số thuế</div>
                                        <Form.Item name="tax_code" noStyle>
                                            <Input className="misa-input" placeholder="Mã số thuế" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Địa chỉ trên hóa đơn</div>
                                        <Form.Item name="invoice_address" noStyle>
                                            <Input className="misa-input" placeholder="Địa chỉ xuất hóa đơn..." />
                                        </Form.Item>
                                    </div>
                                </div>
                            )}

                            {/* Tab 4: Phiếu chi */}
                            {masterActiveTab === 'cash_receipt' && (
                                <div className="misa-form-grid">
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Mã nhà cung cấp <span className="misa-text-danger">*</span></div>
                                        <Form.Item name="supplier_id" noStyle rules={[{ required: true }]}>
                                            <Select 
                                                showSearch 
                                                className="misa-input"
                                                placeholder="Chọn nhà cung cấp"
                                                onChange={handleSupplierChange}
                                                popupMatchSelectWidth={false}
                                                dropdownStyle={{ minWidth: 950, width: 950 }}
                                                options={supplierList.map((s: any) => ({ value: s.id, label: `${s.code} - ${s.name}` }))}
                                            />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Người nhận tiền</div>
                                        <Form.Item name="receiver_name" noStyle>
                                            <Input className="misa-input" placeholder="Họ và tên người nhận tiền" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Địa chỉ</div>
                                        <Form.Item name="supplier_address" noStyle>
                                            <Input className="misa-input" placeholder="Địa chỉ..." />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Kèm theo</div>
                                        <div className="misa-flex-center misa-gap-4">
                                            <Form.Item name="attached_docs" noStyle>
                                                <Input className="misa-input misa-input-w45-center" />
                                            </Form.Item>
                                            <span className="apple-muted-text">chứng từ gốc</span>
                                        </div>
                                    </div>
                                    <div className="misa-col-12">
                                        <div className="misa-field-label">Lý do chi</div>
                                            <Form.Item name="spend_reason" noStyle>
                                            <Input className="misa-input" />
                                        </Form.Item>
                                    </div>
                                </div>
                            )}

                            {/* Tab 6: Tờ khai hải quan */}
                            {masterActiveTab === 'customs' && (
                                <div className="misa-form-grid">
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Số tờ khai HQ <span className="misa-text-danger">*</span></div>
                                        <Form.Item name="customs_declaration_no" noStyle>
                                            <Input className="misa-input misa-text-semibold" placeholder="104588992200" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Ngày đăng ký tờ khai</div>
                                        <Form.Item name="customs_date" noStyle>
                                            <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                        </Form.Item>
                                    </div>
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Tỷ giá hải quan (USD/VND)</div>
                                            <Form.Item name="exchange_rate" noStyle>
                                            <InputNumber className="misa-input misa-w-full" formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
                                        </Form.Item>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* RIGHT AREA: Metadata + MisaTotalCard */}
                        <div className="misa-master-right">
                            <div className="misa-flex-col misa-gap-6">
                                <div className="misa-meta-row">
                                    <span className="misa-field-label required">Ngày hạch toán</span>
                                    <Form.Item name="accounting_date" noStyle rules={[{ required: true }]}>
                                        <DatePicker className="misa-input misa-col-w-175" format="DD/MM/YYYY" />
                                    </Form.Item>
                                </div>

                                <div className="misa-meta-row">
                                    <span className="misa-field-label required">Ngày chứng từ</span>
                                    <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                        <DatePicker 
                                            className="misa-input misa-col-w-175" 
                                            format="DD/MM/YYYY"
                                            onChange={d => {
                                                const days = form.getFieldValue('due_days') || 0;
                                                if (d) form.setFieldValue('due_date', d.add(days, 'day'));
                                            }}
                                        />
                                    </Form.Item>
                                </div>

                                <div className="misa-meta-row">
                                    <span className="misa-field-label required">
                                        {isStockInward ? 'Số phiếu nhập' : 'Số chứng từ'}
                                    </span>
                                    <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                        <Input className="misa-input misa-col-w-175 misa-text-semibold misa-text-blue" />
                                    </Form.Item>
                                </div>

                            </div>

                            {/* MisaTotalCard Component */}
                            <MisaTotalCard 
                                label="TỔNG TIỀN THANH TOÁN"
                                value={totals.grandTotal}
                            />
                        </div>
                    </div>
                </MisaMasterCard>

                {/* 3. DETAIL GRID SECTION (GRID TABS + TOOLBAR + MULTI-COLUMN TABLE + FOOTER) */}
                <div className="misa-grid-container">
                    {/* Grid Header Toolbar */}
                    <div className="misa-grid-tab-bar">
                        <div className="misa-grid-tabs">
                            <button 
                                type="button"
                                className={`misa-grid-tab-btn ${gridActiveTab === 'accounting' ? 'active' : ''}`}
                                onClick={() => setGridActiveTab('accounting')}
                            >
                                1. Hàng tiền
                            </button>

                            <button 
                                type="button"
                                className={`misa-grid-tab-btn ${gridActiveTab === 'expenses' ? 'active' : ''}`}
                                onClick={() => setGridActiveTab('expenses')}
                            >
                                {isImportInward ? '4. Phí hàng về kho' : '2. Chi phí mua hàng'}
                            </button>

                            {isImport && (
                                <button 
                                    type="button"
                                    className={`misa-grid-tab-btn ${gridActiveTab === 'tax' ? 'active' : ''}`}
                                    onClick={() => setGridActiveTab('tax')}
                                >
                                    2. Thuế nhập khẩu & GTGT
                                </button>
                            )}

                            {isImport && (
                                <button 
                                    type="button"
                                    className={`misa-grid-tab-btn ${gridActiveTab === 'pre_customs' ? 'active' : ''}`}
                                    onClick={() => setGridActiveTab('pre_customs')}
                                >
                                    3. Phí trước hải quan
                                </button>
                            )}
                        </div>

                        <div className="misa-flex-center misa-gap-12">
                            {/* Actions on Expense Tab */}
                            {gridActiveTab === 'expenses' && (
                                <div className="misa-flex-center misa-gap-8">
                                    <Button 
                                        size="small" 
                                        icon={<FileTextOutlined className="misa-text-blue" />} 
                                        onClick={() => setIsSelectExpenseModalOpen(true)}
                                        className="misa-btn-tool-sm"
                                    >
                                        Chọn chứng từ CP
                                    </Button>
                                    <Button 
                                        size="small" 
                                        type="primary"
                                        icon={<CalculatorOutlined />}
                                        onClick={() => setIsAllocateExpenseModalOpen(true)}
                                        disabled={totalSelectedExpense === 0 && expenseVouchers.length === 0}
                                        className="misa-btn-accent-blue"
                                    >
                                        Phân bổ chi phí ({new Intl.NumberFormat('vi-VN').format(totalSelectedExpense || 0)} ₫)
                                    </Button>
                                </div>
                            )}

                            {/* Discount Mode on Accounting Tab (4 Options matching MISA AMIS) */}
                            {gridActiveTab === 'accounting' && (
                                <div className="misa-flex-center misa-gap-8">
                                    <span className="apple-muted-text misa-text-semibold">Chiết khấu:</span>
                                    <Select 
                                        value={discountMode}
                                        onChange={(val) => {
                                            setDiscountMode(val);
                                            if (val === 'none') {
                                                const cur = form.getFieldValue('lines') || [];
                                                form.setFieldsValue({ lines: cur.map((l: any) => ({ ...l, discount_rate: 0, discount_amount: 0 })) });
                                            }
                                        }}
                                        size="small"
                                        className="misa-input misa-select-w190"
                                        options={[
                                            { value: 'none', label: 'Không chiết khấu' },
                                            { value: 'item', label: 'Theo từng mặt hàng' },
                                            { value: 'percent_invoice', label: 'Theo % hóa đơn' },
                                            { value: 'amount_invoice', label: 'Theo số tiền trên tổng HĐ' },
                                        ]}
                                    />

                                    {/* % Discount for entire invoice input */}
                                    {discountMode === 'percent_invoice' && (
                                        <div className="misa-flex-center misa-gap-4">
                                            <InputNumber 
                                                size="small"
                                                className="misa-input-w70"
                                                min={0}
                                                max={100}
                                                placeholder="%"
                                                value={invoiceDiscountPercent}
                                                onChange={handleInvoiceDiscountPercentChange}
                                            />
                                            <span className="apple-muted-text">%</span>
                                        </div>
                                    )}

                                    {/* Allocate Discount Button for Total Invoice Amount Mode */}
                                    {discountMode === 'amount_invoice' && (
                                        <Button 
                                            size="small" 
                                            type="primary"
                                            icon={<CalculatorOutlined />}
                                            onClick={() => setIsAllocateDiscountModalOpen(true)}
                                            className="misa-btn-accent-blue"
                                        >
                                            Phân bổ chiết khấu
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Grid Content Table with Horizontal Scroll */}
                    <div className="misa-voucher-content-scroll">
                        {gridActiveTab === 'accounting' && (
                            <Form.List name="lines">
                                {(fields, { remove }) => (
                                    <div className={isDirectExpense ? 'misa-voucher-grid-min-w-direct' : 'misa-voucher-grid-min-w-stock'}>
                                        <table className="misa-grid-table misa-w-full">
                                            <thead>
                                                <tr>
                                                    <th className="misa-col-w-45 misa-text-center">#</th>
                                                    <th className="misa-col-w-160">Mã hàng</th>
                                                    <th className="misa-col-min-w-280">Tên hàng</th>
                                                    {isStockInward && <th className="misa-col-w-130">Kho</th>}
                                                    {showAccounts && (
                                                        <th className="misa-col-w-110">
                                                            {isDirectExpense ? 'TK Chi phí' : 'TK Kho'}
                                                        </th>
                                                    )}
                                                    {showAccounts && <th className="misa-col-w-110">TK Công nợ</th>}
                                                    <th className="misa-col-w-85">ĐVT</th>
                                                    <th className="misa-col-w-100 misa-text-right">Số lượng</th>
                                                    <th className="misa-col-w-140 misa-text-right">Đơn giá</th>
                                                    <th className="misa-col-w-160 misa-text-right">Thành tiền</th>
                                                    {discountMode !== 'none' && <th className="misa-col-w-90 misa-text-right">% CK</th>}
                                                    {discountMode !== 'none' && <th className="misa-col-w-140 misa-text-right">Tiền CK</th>}
                                                    {!isImport && invoiceHandling === 'with_invoice' && <th className="misa-col-w-100">% Thuế</th>}
                                                    {!isImport && invoiceHandling === 'with_invoice' && <th className="misa-col-w-150 misa-text-right">Tiền thuế</th>}
                                                    {!isImport && invoiceHandling === 'with_invoice' && showAccounts && <th className="misa-col-w-110">TK Thuế</th>}
                                                    <th className="misa-col-w-140">Nhóm HHDV</th>
                                                    {isStockInward && <th className="misa-col-w-150 misa-text-right">Chi phí mua</th>}
                                                    {isStockInward && <th className="misa-col-w-160 misa-text-right">Giá trị nhập</th>}
                                                    <th className="misa-col-w-45 misa-text-center"></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {fields.map((field, index) => (
                                                    <tr key={field.key}>
                                                        <td className="misa-text-center apple-muted-text">{index + 1}</td>

                                                        {/* Mã hàng */}
                                                        <td>
                                                            <Form.Item name={[field.name, 'item_id']} noStyle>
                                                                <Select 
                                                                    showSearch 
                                                                    variant="borderless" 
                                                                    className="misa-w-full"
                                                                    placeholder="Chọn mã..."
                                                                    onChange={(val: any) => handleItemChange(index, val)}
                                                                    popupMatchSelectWidth={false}
                                                                    popupClassName="misa-multicolumn-item-popup"
                                                                    dropdownStyle={{ minWidth: 680, width: 680 }}
                                                                    optionLabelProp="label"
                                                                    filterOption={(input, option) => {
                                                                        const code = String(option?.itemCode || option?.label || '').toLowerCase();
                                                                        const name = String(option?.itemName || '').toLowerCase();
                                                                        const q = input.toLowerCase();
                                                                        return code.includes(q) || name.includes(q);
                                                                    }}
                                                                    options={itemList.map((it: any) => ({ 
                                                                        value: it.id, 
                                                                        label: it.code,
                                                                        itemCode: it.code,
                                                                        itemName: it.name,
                                                                        itemStock: it.stock_quantity ?? it.stock ?? it.on_hand ?? it.minimum_stock,
                                                                        itemPrice: it.cost_price ?? it.purchase_price
                                                                    }))}
                                                                    optionRender={(option) => (
                                                                        <div className="misa-cell-dropdown-grid-4col">
                                                                            <span className="misa-text-semibold">{option.data.itemCode}</span>
                                                                            <span className="misa-text-truncate">{option.data.itemName}</span>
                                                                            <span className="misa-text-right misa-text-blue">{option.data.itemStock ?? '—'}</span>
                                                                            <span className="misa-text-right">{option.data.itemPrice === undefined || option.data.itemPrice === null ? '—' : new Intl.NumberFormat('vi-VN').format(option.data.itemPrice)}</span>
                                                                        </div>
                                                                    )}
                                                                    dropdownRender={menu => (
                                                                        <div>
                                                                            <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-4col">
                                                                                <span>Mã hàng</span>
                                                                                <span>Tên hàng</span>
                                                                                <span className="misa-text-right">Số lượng tồn</span>
                                                                                <span className="misa-text-right">Đơn giá</span>
                                                                            </div>
                                                                            {menu}
                                                                            <div className="misa-grid-dropdown-footer">
                                                                                <Button 
                                                                                    type="link" 
                                                                                    size="small" 
                                                                                    icon={<PlusOutlined />} 
                                                                                    onClick={() => {
                                                                                        setActiveRowIndex(index);
                                                                                        setIsItemModalVisible(true);
                                                                                    }}
                                                                                    className="misa-btn-tool-sm misa-text-blue misa-text-semibold"
                                                                                >
                                                                                    Thêm mới
                                                                                </Button>
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                />
                                                            </Form.Item>
                                                        </td>
                                                        <td>
                                                            <Form.Item name={[field.name, 'item_name']} noStyle>
                                                                <input className="misa-table-input" placeholder="Tên hàng / Diễn giải..." />
                                                            </Form.Item>
                                                        </td>

                                                        {/* Kho */}
                                                        {isStockInward && (
                                                            <td>
                                                                <Form.Item name={[field.name, 'warehouse']} noStyle>
                                                                    <Select 
                                                                        variant="borderless"
                                                                        className="misa-w-full"
                                                                        popupMatchSelectWidth={false}
                                                                        popupClassName="misa-multicolumn-account-popup"
                                                                        dropdownStyle={{ minWidth: 380, width: 380 }}
                                                                        optionLabelProp="label"
                                                                        options={warehouseList.map((w: any) => ({
                                                                            value: w.code || w.warehouse_code,
                                                                            label: w.code || w.warehouse_code,
                                                                            warehouseCode: w.code || w.warehouse_code,
                                                                            warehouseName: w.name || w.warehouse_name
                                                                        }))}
                                                                        notFoundContent="Chưa có kho từ máy chủ"
                                                                        optionRender={(option) => (
                                                                            <div className="misa-cell-dropdown-grid-2col">
                                                                                <span className="misa-text-semibold">{option.data.warehouseCode}</span>
                                                                                <span>{option.data.warehouseName}</span>
                                                                            </div>
                                                                        )}
                                                                        dropdownRender={menu => (
                                                                            <div>
                                                                                <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-2col">
                                                                                    <span>Mã kho</span>
                                                                                    <span>Tên kho</span>
                                                                                </div>
                                                                                {menu}
                                                                            </div>
                                                                        )}
                                                                    />
                                                                </Form.Item>
                                                            </td>
                                                        )}

                                                        {/* TK Kho / TK Chi phí */}
                                                        {showAccounts && (
                                                            <td>
                                                                <Form.Item name={[field.name, 'debit_account']} noStyle>
                                                                    <Select 
                                                                        variant="borderless"
                                                                        className="misa-w-full misa-text-semibold"
                                                                        popupMatchSelectWidth={false}
                                                                        popupClassName="misa-multicolumn-account-popup"
                                                                        dropdownStyle={{ minWidth: 380, width: 380 }}
                                                                        optionLabelProp="label"
                                                                        options={accountList.map((a: any) => ({
                                                                            value: a.code,
                                                                            label: a.code,
                                                                            accountCode: a.code,
                                                                            accountName: a.name
                                                                        }))}
                                                                        notFoundContent="Chưa có tài khoản từ máy chủ"
                                                                        optionRender={(option) => (
                                                                            <div className="misa-cell-dropdown-grid-2col">
                                                                                <span className="misa-text-semibold">{option.data.accountCode}</span>
                                                                                <span>{option.data.accountName}</span>
                                                                            </div>
                                                                        )}
                                                                        dropdownRender={menu => (
                                                                            <div>
                                                                                <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-2col">
                                                                                    <span>Số tài khoản</span>
                                                                                    <span>Tên tài khoản</span>
                                                                                </div>
                                                                                {menu}
                                                                            </div>
                                                                        )}
                                                                    />
                                                                </Form.Item>
                                                            </td>
                                                        )}

                                                        {/* TK Công nợ */}
                                                        {showAccounts && (
                                                            <td>
                                                                <Form.Item name={[field.name, 'credit_account']} noStyle>
                                                                    <Select 
                                                                        variant="borderless"
                                                                        className="misa-w-full misa-text-semibold"
                                                                        popupMatchSelectWidth={false}
                                                                        popupClassName="misa-multicolumn-account-popup"
                                                                        dropdownStyle={{ minWidth: 380, width: 380 }}
                                                                        optionLabelProp="label"
                                                                        options={accountList.map((a: any) => ({
                                                                            value: a.code,
                                                                            label: a.code,
                                                                            accountCode: a.code,
                                                                            accountName: a.name
                                                                        }))}
                                                                        notFoundContent="Chưa có tài khoản từ máy chủ"
                                                                        optionRender={(option) => (
                                                                            <div className="misa-cell-dropdown-grid-2col">
                                                                                <span className="misa-text-semibold">{option.data.accountCode}</span>
                                                                                <span>{option.data.accountName}</span>
                                                                            </div>
                                                                        )}
                                                                        dropdownRender={menu => (
                                                                            <div>
                                                                                <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-2col">
                                                                                    <span>Số tài khoản</span>
                                                                                    <span>Tên tài khoản</span>
                                                                                </div>
                                                                                {menu}
                                                                            </div>
                                                                        )}
                                                                    />
                                                                </Form.Item>
                                                            </td>
                                                        )}

                                                        {/* ĐVT */}
                                                        <td>
                                                            <Form.Item name={[field.name, 'unit']} noStyle>
                                                                <input className="misa-table-input" />
                                                            </Form.Item>
                                                        </td>

                                                        {/* Số lượng */}
                                                        <td>
                                                            <Form.Item name={[field.name, 'quantity']} noStyle>
                                                                <InputNumber 
                                                                    variant="borderless" 
                                                                    className="misa-w-full misa-text-right"
                                                                    onChange={val => {
                                                                        const cur = form.getFieldValue('lines') || [];
                                                                        const q = Number(val) || 0;
                                                                        const p = Number(cur[index]?.unit_price) || 0;
                                                                        const amt = q * p;
                                                                        const discAmt = amt * ((Number(cur[index]?.discount_rate) || 0) / 100);
                                                                        const netAmt = amt - discAmt;
                                                                        cur[index].quantity = q;
                                                                        cur[index].amount = amt;
                                                                        cur[index].discount_amount = discAmt;
                                                                        cur[index].tax_amount = Math.round(netAmt * ((cur[index]?.tax_rate ?? 10) / 100));
                                                                        if (isImport) {
                                                                            cur[index].import_tax_amount = Math.round(netAmt * ((cur[index]?.import_tax_rate ?? 5) / 100));
                                                                        }
                                                                        cur[index].stock_value = netAmt + (Number(cur[index]?.purchase_expense) || 0);
                                                                        form.setFieldsValue({ lines: [...cur] });
                                                                    }}
                                                                />
                                                            </Form.Item>
                                                        </td>

                                                        {/* Đơn giá */}
                                                        <td>
                                                            <Form.Item name={[field.name, 'unit_price']} noStyle>
                                                                <InputNumber 
                                                                    variant="borderless" 
                                                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                    className="misa-w-full misa-text-right"
                                                                    onChange={val => {
                                                                        const cur = form.getFieldValue('lines') || [];
                                                                        const p = Number(val) || 0;
                                                                        const q = Number(cur[index]?.quantity) || 0;
                                                                        const amt = q * p;
                                                                        const discAmt = amt * ((Number(cur[index]?.discount_rate) || 0) / 100);
                                                                        const netAmt = amt - discAmt;
                                                                        cur[index].unit_price = p;
                                                                        cur[index].amount = amt;
                                                                        cur[index].discount_amount = discAmt;
                                                                        cur[index].tax_amount = Math.round(netAmt * ((cur[index]?.tax_rate ?? 10) / 100));
                                                                        if (isImport) {
                                                                            cur[index].import_tax_amount = Math.round(netAmt * ((cur[index]?.import_tax_rate ?? 5) / 100));
                                                                        }
                                                                        cur[index].stock_value = netAmt + (Number(cur[index]?.purchase_expense) || 0);
                                                                        form.setFieldsValue({ lines: [...cur] });
                                                                    }}
                                                                />
                                                            </Form.Item>
                                                        </td>

                                                        {/* Thành tiền */}
                                                        <td>
                                                            <Form.Item name={[field.name, 'amount']} noStyle>
                                                                <InputNumber 
                                                                    variant="borderless"
                                                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                    className="misa-w-full misa-text-right misa-text-semibold"
                                                                    onChange={val => {
                                                                        const cur = form.getFieldValue('lines') || [];
                                                                        const amt = Number(val) || 0;
                                                                        const q = Number(cur[index]?.quantity) || 1;
                                                                        if (q > 0) cur[index].unit_price = Math.round(amt / q);
                                                                        const discAmt = amt * ((Number(cur[index]?.discount_rate) || 0) / 100);
                                                                        const netAmt = amt - discAmt;
                                                                        cur[index].amount = amt;
                                                                        cur[index].discount_amount = discAmt;
                                                                        cur[index].tax_amount = Math.round(netAmt * ((cur[index]?.tax_rate ?? 10) / 100));
                                                                        if (isImport) {
                                                                            cur[index].import_tax_amount = Math.round(netAmt * ((cur[index]?.import_tax_rate ?? 5) / 100));
                                                                        }
                                                                        cur[index].stock_value = netAmt + (Number(cur[index]?.purchase_expense) || 0);
                                                                        form.setFieldsValue({ lines: [...cur] });
                                                                    }}
                                                                />
                                                            </Form.Item>
                                                        </td>

                                                        {/* Chiết khấu */}
                                                        {discountMode !== 'none' && (
                                                            <>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'discount_rate']} noStyle>
                                                                        <InputNumber 
                                                                            variant="borderless" 
                                                                            className="misa-w-full misa-text-right"
                                                                            onChange={(val: any) => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                const r = Number(val) || 0;
                                                                                const amt = Number(cur[index]?.amount) || 0;
                                                                                const discAmt = amt * (r / 100);
                                                                                const netAmt = amt - discAmt;
                                                                                cur[index].discount_rate = r;
                                                                                cur[index].discount_amount = discAmt;
                                                                                cur[index].tax_amount = Math.round(netAmt * ((cur[index]?.tax_rate ?? 10) / 100));
                                                                                cur[index].stock_value = netAmt + (Number(cur[index]?.purchase_expense) || 0);
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'discount_amount']} noStyle>
                                                                        <InputNumber 
                                                                            variant="borderless" 
                                                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                            className="misa-w-full misa-text-right misa-text-semibold misa-text-danger" 
                                                                            onChange={(val: any) => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                const discAmt = Number(val) || 0;
                                                                                const amt = Number(cur[index]?.amount) || 0;
                                                                                const r = amt > 0 ? (discAmt / amt) * 100 : 0;
                                                                                const netAmt = amt - discAmt;
                                                                                cur[index].discount_rate = r.toFixed(2);
                                                                                cur[index].discount_amount = discAmt;
                                                                                cur[index].tax_amount = Math.round(netAmt * ((cur[index]?.tax_rate ?? 10) / 100));
                                                                                cur[index].stock_value = netAmt + (Number(cur[index]?.purchase_expense) || 0);
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                            </>
                                                        )}

                                                        {/* Thuế suất & Tiền thuế GTGT */}
                                                        {!isImport && invoiceHandling === 'with_invoice' && (
                                                            <>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'tax_rate']} noStyle>
                                                                        <Select 
                                                                            variant="borderless" 
                                                                            className="misa-w-full"
                                                                            options={[
                                                                                { value: 0, label: '0%' },
                                                                                { value: 5, label: '5%' },
                                                                                { value: 8, label: '8%' },
                                                                                { value: 10, label: '10%' },
                                                                                { value: -1, label: 'KCT' },
                                                                            ]}
                                                                            onChange={(val: any) => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                const amt = (Number(cur[index]?.amount) || 0) - (Number(cur[index]?.discount_amount) || 0);
                                                                                cur[index].tax_rate = val;
                                                                                cur[index].tax_amount = val > 0 ? Math.round(amt * val / 100) : 0;
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'tax_amount']} noStyle>
                                                                        <InputNumber 
                                                                            variant="borderless"
                                                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                            className="misa-w-full misa-text-right misa-text-semibold"
                                                                            onChange={val => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                cur[index].tax_amount = Number(val) || 0;
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                {showAccounts && (
                                                                    <td>
                                                                        <Form.Item name={[field.name, 'tax_account']} noStyle>
                                                                            <Select 
                                                                                variant="borderless"
                                                                                className="misa-w-full misa-text-semibold"
                                                                                popupMatchSelectWidth={false}
                                                                                popupClassName="misa-multicolumn-account-popup"
                                                                                dropdownStyle={{ minWidth: 380, width: 380 }}
                                                                                optionLabelProp="label"
                                                                                options={accountList.map((a: any) => ({
                                                                                    value: a.code,
                                                                                    label: a.code,
                                                                                    accountCode: a.code,
                                                                                    accountName: a.name
                                                                                }))}
                                                                                notFoundContent="Chưa có tài khoản từ máy chủ"
                                                                                optionRender={(option) => (
                                                                                    <div className="misa-cell-dropdown-grid-2col">
                                                                                        <span className="misa-text-semibold">{option.data.accountCode}</span>
                                                                                        <span>{option.data.accountName}</span>
                                                                                    </div>
                                                                                )}
                                                                                dropdownRender={menu => (
                                                                                    <div>
                                                                                        <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-2col">
                                                                                            <span>Số tài khoản</span>
                                                                                            <span>Tên tài khoản</span>
                                                                                        </div>
                                                                                        {menu}
                                                                                    </div>
                                                                                )}
                                                                            />
                                                                        </Form.Item>
                                                                    </td>
                                                                )}
                                                            </>
                                                        )}

                                                        {/* Nhóm HHDV Mua Vào */}
                                                        <td>
                                                            <Form.Item name={[field.name, 'vat_group']} noStyle>
                                                                <Select 
                                                                    variant="borderless"
                                                                    className="misa-w-full"
                                                                    options={VAT_GROUPS}
                                                                />
                                                            </Form.Item>
                                                        </td>

                                                        {/* Chi phí mua hàng */}
                                                        {isStockInward && (
                                                            <td>
                                                                <Form.Item name={[field.name, 'purchase_expense']} noStyle>
                                                                    <InputNumber 
                                                                        variant="borderless"
                                                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                        className="misa-w-full misa-text-right misa-text-semibold misa-text-blue"
                                                                        onChange={val => {
                                                                            const cur = form.getFieldValue('lines') || [];
                                                                            const exp = Number(val) || 0;
                                                                            const netAmt = (Number(cur[index]?.amount) || 0) - (Number(cur[index]?.discount_amount) || 0);
                                                                            cur[index].purchase_expense = exp;
                                                                            cur[index].stock_value = netAmt + exp;
                                                                            form.setFieldsValue({ lines: [...cur] });
                                                                        }}
                                                                    />
                                                                </Form.Item>
                                                            </td>
                                                        )}

                                                        {/* Giá trị nhập kho */}
                                                        {isStockInward && (
                                                            <td className="misa-text-right misa-text-bold misa-text-blue">
                                                                {new Intl.NumberFormat('vi-VN').format(formLines[index]?.stock_value || ((formLines[index]?.amount || 0) - (formLines[index]?.discount_amount || 0) + (formLines[index]?.purchase_expense || 0)))}
                                                            </td>
                                                        )}

                                                        <td className="misa-text-center">
                                                            <button 
                                                                type="button" 
                                                                title="Xóa dòng"
                                                                className="misa-row-del-btn"
                                                                onClick={() => remove(index)}
                                                            >
                                                                <DeleteOutlined />
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}

                                                {/* In-table Summary Row */}
                                                <tr className="summary-row">
                                                    <td className="misa-text-center"></td>
                                                    <td>Tổng cộng</td>
                                                    <td></td>
                                                    {isStockInward && <td></td>}
                                                    {showAccounts && <td></td>}
                                                    {showAccounts && <td></td>}
                                                    <td></td>
                                                    <td className="misa-text-right misa-text-bold">
                                                        {new Intl.NumberFormat('vi-VN').format(totals.totalQuantity)}
                                                    </td>
                                                    <td></td>
                                                    <td className="misa-text-right misa-text-bold">
                                                        {new Intl.NumberFormat('vi-VN').format(totals.subTotal)}
                                                    </td>
                                                    {discountMode !== 'none' && <td></td>}
                                                    {discountMode !== 'none' && (
                                                        <td className="misa-text-right misa-text-bold misa-text-danger">
                                                            {new Intl.NumberFormat('vi-VN').format(totals.totalDiscount)}
                                                        </td>
                                                    )}
                                                    {!isImport && invoiceHandling === 'with_invoice' && <td></td>}
                                                    {!isImport && invoiceHandling === 'with_invoice' && (
                                                        <td className="misa-text-right misa-text-bold">
                                                            {new Intl.NumberFormat('vi-VN').format(totals.totalTax)}
                                                        </td>
                                                    )}
                                                    {!isImport && invoiceHandling === 'with_invoice' && showAccounts && <td></td>}
                                                    <td></td>
                                                    {isStockInward && (
                                                        <td className="misa-text-right misa-text-bold misa-text-blue">
                                                            {new Intl.NumberFormat('vi-VN').format(totals.totalPurchaseExpense)}
                                                        </td>
                                                    )}
                                                    {isStockInward && (
                                                        <td className="misa-text-right misa-text-bold misa-text-blue">
                                                            {new Intl.NumberFormat('vi-VN').format(totalStockValue)}
                                                        </td>
                                                    )}
                                                    <td></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </Form.List>
                        )}

                        {/* Tab 2: Chi phí mua hàng */}
                        {gridActiveTab === 'expenses' && (
                            <div className="misa-voucher-grid-min-w-expense">
                                <table className="misa-grid-table misa-w-full">
                                    <thead>
                                        <tr>
                                            <th className="misa-col-w-36 misa-text-center">#</th>
                                            <th className="misa-col-w-110 misa-text-center">Ngày hạch toán</th>
                                            <th className="misa-col-w-110 misa-text-center">Ngày chứng từ</th>
                                            <th className="misa-col-w-130">Số chứng từ</th>
                                            <th className="misa-col-min-w-260">Nhà cung cấp</th>
                                            <th className="misa-col-min-w-260">Diễn giải</th>
                                            <th className="misa-col-w-140 misa-text-right">Tổng chi phí</th>
                                            <th className="misa-col-w-140 misa-text-right">Lũy kế đã phân bổ</th>
                                            <th className="misa-col-w-160 misa-text-right">Số phân bổ lần này</th>
                                            <th className="misa-col-w-36 misa-text-center"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {expenseVouchers.length === 0 ? (
                                            <tr>
                                                <td colSpan={10} className="misa-empty-table-cell">
                                                    <div>Chưa có chứng từ chi phí nào được chọn.</div>
                                                    <Button 
                                                        type="link" 
                                                        icon={<FileTextOutlined />} 
                                                        onClick={() => setIsSelectExpenseModalOpen(true)}
                                                        className="misa-text-blue misa-text-semibold"
                                                    >
                                                        Bấm vào đây để Chọn chứng từ chi phí
                                                    </Button>
                                                </td>
                                            </tr>
                                        ) : (
                                            expenseVouchers.map((ev, idx) => (
                                                <tr key={ev.id}>
                                                    <td className="misa-text-center apple-muted-text">{idx + 1}</td>
                                                    <td className="misa-text-center">{ev.accounting_date}</td>
                                                    <td className="misa-text-center">{ev.voucher_date}</td>
                                                    <td><span className="misa-text-semibold misa-text-blue">{ev.voucher_no}</span></td>
                                                    <td>{ev.supplier_name}</td>
                                                    <td>{ev.description}</td>
                                                    <td className="misa-text-right misa-text-semibold">{new Intl.NumberFormat('vi-VN').format(ev.total_expense)} ₫</td>
                                                    <td className="misa-text-right">{new Intl.NumberFormat('vi-VN').format(ev.allocated_amount)} ₫</td>
                                                    <td className="misa-text-right">
                                                        <InputNumber 
                                                            variant="borderless" 
                                                            value={ev.this_allocation}
                                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                            className="misa-w-full misa-text-right misa-text-bold misa-text-blue"
                                                            onChange={val => {
                                                                const updated = [...expenseVouchers];
                                                                updated[idx].this_allocation = Number(val) || 0;
                                                                setExpenseVouchers(updated);
                                                            }}
                                                        />
                                                    </td>
                                                    <td className="misa-text-center">
                                                        <button 
                                                            type="button" 
                                                            className="misa-row-del-btn"
                                                            onClick={() => setExpenseVouchers(expenseVouchers.filter((_, i) => i !== idx))}
                                                        >
                                                            <DeleteOutlined />
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                        {expenseVouchers.length > 0 && (
                                            <tr className="summary-row">
                                                <td className="misa-text-center"></td>
                                                <td>Cộng</td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td></td>
                                                <td className="misa-text-right misa-text-bold">
                                                    {new Intl.NumberFormat('vi-VN').format(expenseVouchers.reduce((s, it) => s + (it.total_expense || 0), 0))} ₫
                                                </td>
                                                <td className="misa-text-right misa-text-bold">
                                                    {new Intl.NumberFormat('vi-VN').format(expenseVouchers.reduce((s, it) => s + (it.allocated_amount || 0), 0))} ₫
                                                </td>
                                                <td className="misa-text-right misa-text-bold misa-text-blue">
                                                    {new Intl.NumberFormat('vi-VN').format(totalSelectedExpense)} ₫
                                                </td>
                                                <td></td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* Tab 3: Thuế */}
                        {gridActiveTab === 'tax' && (
                            <div className="misa-voucher-grid-min-w-tax">
                                <table className="misa-grid-table misa-w-full">
                                    <thead>
                                        <tr>
                                            <th className="misa-col-w-36 misa-text-center">#</th>
                                            <th className="misa-col-w-160">Mã hàng</th>
                                            <th className="misa-col-min-w-200">Tên hàng</th>
                                            <th className="misa-col-w-90 misa-text-right">% Thuế NK</th>
                                            <th className="misa-col-w-130 misa-text-right">Tiền thuế NK</th>
                                            {showAccounts && <th className="misa-col-w-100">TK Thuế NK</th>}
                                            <th className="misa-col-w-85">% Thuế GTGT</th>
                                            <th className="misa-col-w-130 misa-text-right">Tiền thuế GTGT</th>
                                            {showAccounts && <th className="misa-col-w-100">TK Thuế GTGT</th>}
                                            {showAccounts && <th className="misa-col-w-100">TKĐƯ GTGT</th>}
                                            <th className="misa-col-w-120">Nhóm HHDV</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {formLines.map((l: any, idx: number) => (
                                            <tr key={idx}>
                                                <td className="misa-text-center apple-muted-text">{idx + 1}</td>
                                                <td><span className="misa-text-semibold">{l.item_code || '-'}</span></td>
                                                <td>{l.item_name || '-'}</td>
                                                <td className="misa-text-right">{l.import_tax_rate ?? '—'}{l.import_tax_rate === undefined ? '' : '%'}</td>
                                                <td className="misa-text-right misa-text-semibold">{l.import_tax_amount === undefined ? '—' : `${new Intl.NumberFormat('vi-VN').format(l.import_tax_amount)} ₫`}</td>
                                                {showAccounts && <td>{l.import_tax_account ?? '—'}</td>}
                                                <td>{l.tax_rate ?? '—'}{l.tax_rate === undefined ? '' : '%'}</td>
                                                <td className="misa-text-right misa-text-semibold">{l.tax_amount === undefined ? '—' : `${new Intl.NumberFormat('vi-VN').format(l.tax_amount)} ₫`}</td>
                                                {showAccounts && <td>{l.tax_account ?? '—'}</td>}
                                                {showAccounts && <td>{l.contra_vat_account ?? '—'}</td>}
                                                <td>{l.vat_group ? `Nhóm ${l.vat_group}` : '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* Tab 4: Phí trước hải quan */}
                        {gridActiveTab === 'pre_customs' && (
                            <div className="misa-voucher-grid-min-w-customs">
                                <table className="misa-grid-table misa-w-full">
                                    <thead>
                                        <tr>
                                            <th className="misa-col-w-36 misa-text-center">#</th>
                                            <th className="misa-col-w-160">Mã hàng</th>
                                            <th className="misa-col-min-w-260">Tên hàng</th>
                                            <th className="misa-col-w-200 misa-text-right">Phí trước HQ (Ngoại tệ USD)</th>
                                            <th className="misa-col-w-200 misa-text-right">Phí trước HQ (Quy đổi VND)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {formLines.map((l: any, idx: number) => (
                                            <tr key={idx}>
                                                <td className="misa-text-center apple-muted-text">{idx + 1}</td>
                                                <td><span className="misa-text-semibold">{l.item_code || '-'}</span></td>
                                                <td>{l.item_name || '-'}</td>
                                                <td className="misa-text-right"><input className="misa-table-input misa-text-right" defaultValue="0.00" /></td>
                                                <td className="misa-text-right"><input className="misa-table-input misa-text-right" defaultValue="0" /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    {/* MisaGridActionFooter placed BELOW table */}
                    <MisaGridActionFooter 
                        onAddLine={handleAddLine}
                        onAddNote={handleAddNote}
                        onDeleteAll={handleDeleteAll}
                        lineCount={formLines.length}
                    />

                    {/* MisaTableSummaryBar placed below the table */}
                    <MisaTableSummaryBar 
                        leftContent={<span className="apple-muted-text">Tổng số: <strong className="misa-text-heading">{totals.lineCount}</strong> dòng</span>}
                        items={[
                            { label: 'Số lượng', value: totals.totalQuantity, format: 'number' },
                            { label: 'Tiền hàng', value: totals.subTotal, format: 'currency' },
                            ...(discountMode !== 'none' ? [{ label: 'Tiền CK', value: totals.totalDiscount, format: 'currency' as const }] : []),
                            ...(!isImport && invoiceHandling === 'with_invoice' ? [{ label: 'Tiền thuế GTGT', value: totals.totalTax, format: 'currency' as const }] : []),
                            ...(isStockInward ? [{ label: 'CP mua hàng', value: totals.totalPurchaseExpense, format: 'currency' as const }] : []),
                            ...(isStockInward ? [{ label: 'Giá trị nhập kho', value: totalStockValue, format: 'currency' as const, highlight: true }] : [])
                        ]}
                    />
                </div>

                {/* 4. FOOTER: E-INVOICE LOOKUP + 7-ROW MisaVoucherSummaryCard */}
                <div className="misa-footer-layout">
                    {/* Left: E-Invoice lookup fields */}
                    <div className="misa-footer-left">
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '12px' }}>
                            <div>
                                <div className="misa-field-label">Mã tra cứu HĐĐT</div>
                                <Form.Item name="einvoice_lookup_code" noStyle>
                                    <Input className="misa-input misa-w-full" placeholder="" />
                                </Form.Item>
                            </div>
                            <div>
                                <div className="misa-field-label">Đường dẫn tra cứu HĐĐT</div>
                                <Form.Item name="einvoice_lookup_url" noStyle>
                                    <Input className="misa-input misa-w-full" placeholder="" />
                                </Form.Item>
                            </div>
                        </div>
                    </div>

                    {/* Middle: Attachment box */}
                    <div className="misa-footer-attachment">
                        <div 
                            className="misa-upload-box-dashed"
                            style={{ 
                                padding: '16px 20px', 
                                textAlign: 'center', 
                                border: '1px dashed #cbd5e1', 
                                borderRadius: '6px', 
                                background: '#fafafa',
                                cursor: 'pointer',
                                transition: 'all 0.2s ease'
                            }}
                            onClick={() => {
                                const input = document.getElementById('purchase-voucher-file-input');
                                if (input) input.click();
                            }}
                            onDragOver={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                            }}
                            onDrop={(e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                const files = e.dataTransfer.files;
                                if (files && files.length > 0) {
                                    message.success(`Đã chọn ${files.length} tệp đính kèm`);
                                }
                            }}
                        >
                            <input 
                                id="purchase-voucher-file-input"
                                type="file" 
                                multiple 
                                style={{ display: 'none' }} 
                                onChange={(e) => {
                                    const files = e.target.files;
                                    if (files && files.length > 0) {
                                        message.success(`Đã chọn ${files.length} tệp đính kèm`);
                                    }
                                }}
                            />
                            <div style={{ marginBottom: 6 }}>
                                <UploadOutlined style={{ fontSize: 20, color: '#94a3b8' }} />
                            </div>
                            <div style={{ fontSize: 13, color: '#475569' }}>
                                <span style={{ color: '#1677ff', fontWeight: 600 }}>Chọn tệp</span> hoặc kéo và thả tệp vào đây
                            </div>
                            <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 4 }}>
                                (tối đa 5MB)
                            </div>
                        </div>
                    </div>

                    {/* Right: Comprehensive 7-Row MisaVoucherSummaryCard */}
                    <div className="misa-footer-right">
                        <MisaVoucherSummaryCard items={summaryCardItems} />
                    </div>
                </div>
            </Form>
            </ModalFrame>

            {/* Quick Add Supplier Modal */}
            <QuickAddContactModal 
                open={isSupplierModalVisible}
                onCancel={() => setIsSupplierModalVisible(false)}
                contactType="supplier"
                onSuccess={(newSupplier) => {
                    queryClient.invalidateQueries({ queryKey: ['suppliers'] });
                    form.setFieldsValue({
                        supplier_id: newSupplier.id,
                        supplier_code: newSupplier.code,
                        supplier_name: newSupplier.name,
                        supplier_address: newSupplier.address,
                        tax_code: newSupplier.tax_code,
                    });
                }}
            />

            {/* Quick Add Employee Modal */}
            <QuickAddEmployeeModal 
                open={isEmployeeModalVisible}
                onCancel={() => setIsEmployeeModalVisible(false)}
                onSuccess={(newEmp) => {
                    queryClient.invalidateQueries({ queryKey: ['employees'] });
                    form.setFieldsValue({
                        employee_id: newEmp.id,
                    });
                }}
            />

            {/* Quick Add Payment Term Modal */}
            <QuickAddPaymentTermModal 
                open={isPaymentTermModalVisible}
                onCancel={() => setIsPaymentTermModalVisible(false)}
                onSuccess={(newTerm) => {
                    queryClient.invalidateQueries({ queryKey: ['payment-terms'] });
                    handlePaymentTermChange(newTerm.code);
                }}
            />

            {/* Quick Add Item Modal */}
            <QuickAddItemModal 
                open={isItemModalVisible}
                onCancel={() => setIsItemModalVisible(false)}
                onSuccess={(newItem) => {
                    queryClient.invalidateQueries({ queryKey: ['inventory-items'] });
                    const cur = form.getFieldValue('lines') || [];
                    const targetIdx = (activeRowIndex >= 0 && activeRowIndex < cur.length) ? activeRowIndex : cur.length - 1;
                    if (targetIdx >= 0) {
                        handleItemChange(targetIdx, newItem.id);
                    }
                }}
            />

            {/* Reference Voucher Selection Modal */}
            <ReferenceVoucherModal 
                open={isReferenceModalOpen}
                onCancel={() => setIsReferenceModalOpen(false)}
                onSelect={(selected) => {
                    setReferenceVouchers(selected);
                    message.success(`Đã chọn ${selected.length} chứng từ tham chiếu`);
                    if (selected.length > 0) {
                        const first = selected[0];
                        if (first.supplier_id) handleSupplierChange(first.supplier_id);
                        if (first.description) form.setFieldValue('description', `Kế thừa từ ${first.voucher_no}: ${first.description}`);
                    }
                }}
            />

            {/* Select Purchase Expense Voucher Modal */}
            <SelectExpenseVoucherModal 
                open={isSelectExpenseModalOpen}
                onCancel={() => setIsSelectExpenseModalOpen(false)}
                supplierId={supplierId}
                onSelect={(selected) => {
                    setExpenseVouchers(selected);
                    message.success(`Đã chọn ${selected.length} chứng từ chi phí`);
                }}
            />

            {/* Allocate Purchase Expense Modal */}
            <AllocateExpenseModal 
                open={isAllocateExpenseModalOpen}
                onCancel={() => setIsAllocateExpenseModalOpen(false)}
                totalExpenseToAllocate={totalSelectedExpense}
                items={formLines}
                onAllocate={handleExpenseAllocated}
            />

            {/* Allocate Invoice Discount Modal */}
            <AllocateDiscountModal 
                open={isAllocateDiscountModalOpen}
                onCancel={() => setIsAllocateDiscountModalOpen(false)}
                items={formLines}
                onAllocate={handleDiscountAllocated}
            />
        </Modal>
    );
};

export default PurchaseVoucherDetailModal;
