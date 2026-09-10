import React, { useEffect, useState } from 'react';
import { Table, Button, Form, Input, InputNumber, DatePicker, Switch, Radio, Checkbox, Dropdown, Alert, Space } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import type { MenuProps } from 'antd';
import {
    PlusOutlined,
    DeleteOutlined,
    InboxOutlined,
    DownOutlined,
    SettingOutlined,
    ReloadOutlined,
    PrinterOutlined,
    SearchOutlined,
    CopyOutlined,
    EditOutlined,
    CheckCircleOutlined,
    CloseCircleOutlined,
    CloseOutlined,
    DollarOutlined,
    FileProtectOutlined,
    HistoryOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { formatDate } from '../../utils/dateUtils';
import { AdaptiveSelect as Select } from '../../components/layout/AdaptiveSelect';
import {
    MisaMasterCard,
    MisaTableSummaryBar,
    MisaTotalCard,
    MisaGridActionFooter,
    QuickAddContactModal,
    QuickAddEmployeeModal,
    QuickAddItemModal,
    QuickAddPaymentTermModal,
    AccountSelect,
    MultiColumnContactSelect,
    ReferenceVoucherModal,
    VoucherPrintModal,
    CollectByInvoiceModal,
    useVoucherShortcuts,
    useVoucherTotals
} from '../../components/misa';
import SalesInvoiceDimensionAssignments from './SalesInvoiceDimensionAssignments';
import SalesInvoiceApprovalWorkflow from './SalesInvoiceApprovalWorkflow';
import { CustomerDebtModal } from './components/CustomerDebtModal';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { useSourceRecordDeepLink } from '../reports/useSourceRecordDeepLink';
import PageHeader from '../../components/layout/PageHeader';
import ModalFrame from '../../components/layout/ModalFrame';

interface SalesInvoiceLine {
    key?: string;
    item_id?: number;
    item_code?: string;
    item_name?: string;
    description?: string;
    debit_account?: string;
    credit_account?: string;
    inventory_account?: string;
    cogs_debit_account?: string;
    cogs_credit_account?: string;
     unit?: string;
     warehouse_name?: string;
     quantity?: number;
    unit_price?: number;
    amount?: number;
    discount_rate?: number;
    discount_amount?: number;
    tax_rate?: number;
    tax_amount?: number;
    tax_account?: string;
    cogs_unit_price?: number;
    cogs_amount?: number;
}

interface SalesInvoiceRecord {
    id: number;
    invoice_number: string;
    invoice_date: string;
    accounting_date: string;
    customer_id?: number;
    customer_name: string;
    customer_address?: string;
    receiver_name?: string;
    tax_code?: string;
    employee_id?: number;
    description: string;
    total_amount: number;
    sub_total?: number;
    tax_amount?: number;
    is_posted: boolean;
    status?: string;
    einvoice_status?: string;
    lines?: SalesInvoiceLine[];
}

function parseSalesInvoiceCollection(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

export const SalesInvoices: React.FC = () => {
    const [searchParams, setSearchParams] = useSearchParams();
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [isViewMode, setIsViewMode] = useState(false);
    const [editingInvoiceId, setEditingInvoiceId] = useState<number | null>(null);
    const [isCustomerModalVisible, setIsCustomerModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [datePreset, setDatePreset] = useState('Tháng này');
    const [voucherType, setVoucherType] = useState('1. Bán hàng hóa trong nước');
    const [voucherTab, setVoucherTab] = useState<'debt_voucher' | 'delivery_voucher' | 'invoice'>('debt_voucher');
    const [paymentMethod, setPaymentMethod] = useState<'unpaid' | 'cash' | 'bank'>('unpaid');
    const [paymentSubMethod, setPaymentSubMethod] = useState<'cash' | 'bank'>('cash');
    const [discountMode, setDiscountMode] = useState<'none' | 'line' | 'total'>('none');
    const [isPaymentTermsExpanded, setIsPaymentTermsExpanded] = useState<boolean>(true);
    const [showAccounts, setShowAccounts] = useState<boolean>(true);
    const [isCustomerDebtModalOpen, setIsCustomerDebtModalOpen] = useState<boolean>(false);
    const [isIncludeDelivery, setIsIncludeDelivery] = useState(true);
    const [isIncludeInvoice, setIsIncludeInvoice] = useState(true);
    const [isRefModalVisible, setIsRefModalVisible] = useState(false);
    const [referencedVouchers, setReferencedVouchers] = useState<any[]>([]);
    const [activeGridTab, setActiveGridTab] = useState<'accounting' | 'tax' | 'cogs' | 'reference' | 'stats'>('accounting');
    const [dimensionInvoice, setDimensionInvoice] = useState<SalesInvoiceRecord | null>(null);
    const [isDimensionAssignmentsOpen, setIsDimensionAssignmentsOpen] = useState(false);
    const [approvalInvoice, setApprovalInvoice] = useState<SalesInvoiceRecord | null>(null);
    const [isApprovalWorkflowOpen, setIsApprovalWorkflowOpen] = useState(false);
    const [isCollectByInvoiceOpen, setIsCollectByInvoiceOpen] = useState(false);
    const [collectionInvoice, setCollectionInvoice] = useState<SalesInvoiceRecord | null>(null);

    // Print Modal State
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printType, setPrintType] = useState<'01-BH' | '02-VT'>('01-BH');
    const [printData, setPrintData] = useState<any>(null);

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const formLines: SalesInvoiceLine[] = Form.useWatch('lines', form) || [];
    const totals = useVoucherTotals(formLines);
    const voucherNumber = Form.useWatch('invoice_number', form) || '—';

    const { data: invoices = [], isLoading, isError: isInvoicesError, refetch: refetchInvoices } = useQuery({
        queryKey: ['sales-invoices'],
        queryFn: async () => {
            const { data } = await api.get('/sales/invoices');
            return parseSalesInvoiceCollection(data, 'sales invoices');
        },
    });

    useEffect(() => {
        const refresh = () => { void queryClient.invalidateQueries({ queryKey: ['sales-invoices'] }); };
        window.addEventListener('sales-invoices-invalidated', refresh);
        return () => window.removeEventListener('sales-invoices-invalidated', refresh);
    }, [queryClient]);

    const { data: customers = [] } = useQuery({
        queryKey: ['customers'],
        queryFn: async () => {
            const { data } = await api.get('/master/customers');
            return parseSalesInvoiceCollection(data, 'customer catalogue');
        },
    });

    const { data: employees = [] } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parseSalesInvoiceCollection(data, 'employee catalogue');
        },
    });

    const { data: items = [] } = useQuery({
        queryKey: ['inventory-items'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parseSalesInvoiceCollection(data, 'inventory item catalogue');
        },
    });

    const { data: accounts = [] } = useQuery({
        queryKey: ['accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return parseSalesInvoiceCollection(data, 'accounts');
        },
    });

    const { data: warehouses = [] } = useQuery({
        queryKey: ['warehouses'],
        queryFn: async () => {
            try {
                const { data } = await api.get('/inventory/warehouses');
                return parseSalesInvoiceCollection(data, 'warehouses');
            } catch {
                return [];
            }
        },
    });

    const [isItemModalVisible, setIsItemModalVisible] = useState(false);
    const [isPaymentTermModalVisible, setIsPaymentTermModalVisible] = useState(false);
    const [activeRowIndex, setActiveRowIndex] = useState<number | null>(null);

    const { data: paymentTerms = [] } = useQuery({
        queryKey: ['payment-terms'],
        queryFn: async () => {
            try {
                const { data } = await api.get('/master/payment-terms');
                return Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
            } catch {
                return [];
            }
        },
    });

    const handlePaymentTermChange = (termValue: string) => {
        const term = Array.isArray(paymentTerms) ? paymentTerms.find((t: any) => t.code === termValue || t.name === termValue) : undefined;
        const days = term ? Number(term.due_days) : (termValue?.includes('15') ? 15 : termValue?.includes('30') ? 30 : termValue?.includes('45') ? 45 : termValue?.includes('60') ? 60 : 0);
        form.setFieldsValue({
            payment_terms: term ? (term.code || term.name) : termValue,
            due_days: days,
            due_date: dayjs().add(days, 'day')
        });
    };

    const handleToggleDelivery = (checked: boolean) => {
        setIsIncludeDelivery(checked);
        if (!checked) {
            if (voucherTab === 'delivery_voucher') {
                setVoucherTab('debt_voucher');
            }
            if (activeGridTab === 'cogs') {
                setActiveGridTab('accounting');
            }
        }
    };

    const handleToggleInvoice = (checked: boolean) => {
        setIsIncludeInvoice(checked);
        if (!checked) {
            if (voucherTab === 'invoice') {
                setVoucherTab('debt_voucher');
            }
        }
    };

    const primaryTabTitle = paymentMethod === 'unpaid'
        ? 'Chứng từ ghi nợ'
        : (paymentSubMethod === 'cash' ? 'Phiếu thu' : 'Thu tiền gửi');

    const invoiceNumberLabel = paymentMethod === 'unpaid'
        ? 'Số chứng từ'
        : (paymentSubMethod === 'cash' ? 'Số phiếu thu' : 'Số chứng từ');

    const paymentTermOptions = [
        { value: 'Thanh toán ngay', label: 'Thanh toán ngay' },
        { value: 'Thanh toán trong 15 ngày', label: '15 ngày' },
        { value: 'Thanh toán trong 30 ngày', label: '30 ngày' },
        { value: 'Thanh toán trong 45 ngày', label: '45 ngày' },
        { value: 'Thanh toán trong 60 ngày', label: '60 ngày' },
        ...(Array.isArray(paymentTerms) ? paymentTerms.map((t: any) => ({
            value: t.code || t.name,
            label: t.name ? `${t.code} - ${t.name}` : (t.code || String(t.id))
        })) : [])
    ];

    const mutation = useMutation({
        mutationFn: async (payloadWithFlags: any) => {
            const { andNew, andPrint, ...values } = payloadWithFlags;
            const payload = {
                customer_id: values.customer_id,
                customer_name: customers?.find((s: any) => s.id === values.customer_id)?.name || values.customer_name,
                customer_address: values.customer_address,
                receiver_name: values.receiver_name,
                tax_code: values.tax_code,
                employee_id: values.employee_id,
                attached_docs: values.attached_docs,
                currency: 'VND',
                exchange_rate: 1,
                invoice_number: values.invoice_number,
                invoice_symbol: values.invoice_symbol,
                invoice_code: values.invoice_code,
                delivery_voucher_number: values.delivery_voucher_number,
                invoice_date: values.invoice_date?.format('YYYY-MM-DD'),
                accounting_date: values.accounting_date?.format('YYYY-MM-DD'),
                due_date: values.due_date?.format('YYYY-MM-DD'),
                description: values.description,
                is_export_slip: isIncludeDelivery,
                is_include_delivery: isIncludeDelivery,
                is_include_invoice: isIncludeInvoice,
                payment_method: paymentMethod,
                lines: values.lines?.map((line: any) => ({
                    item_id: line.item_id,
                    description: line.description || values.description,
                    debit_account: line.debit_account,
                    credit_account: line.credit_account,
                    inventory_account: line.inventory_account,
                    cogs_debit_account: line.cogs_debit_account,
                    cogs_credit_account: line.cogs_credit_account,
                    unit: line.unit,
                    quantity: line.quantity,
                    unit_price: line.unit_price,
                    amount: line.amount,
                    discount_rate: line.discount_rate,
                    discount_amount: line.discount_amount,
                    tax_rate: line.tax_rate,
                    tax_amount: line.tax_amount,
                    tax_account: line.tax_account,
                    cogs_unit_price: line.cogs_unit_price,
                    cogs_amount: line.cogs_amount,
                })) || []
            };

            const res = editingInvoiceId
                ? await api.put(`/sales/invoices/${editingInvoiceId}`, payload)
                : await api.post('/sales/invoices', payload);
            return { res, andNew, andPrint };
        },
        onSuccess: (data: any) => {
            const persistedInvoice = data?.res?.data?.data ?? data?.res?.data;
            if (!persistedInvoice || persistedInvoice.id === undefined || persistedInvoice.id === null) {
                message.error('Máy chủ không trả về chứng từ bán hàng đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success(editingInvoiceId ? 'Cập nhật Chứng từ bán hàng thành công!' : 'Tạo Chứng từ bán hàng thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-invoices'] });
            if (data?.andNew) {
                handleOpenCreateModal();
            } else if (data?.andPrint) {
                setIsModalVisible(false);
                handleOpenPrintRecord(persistedInvoice, '01-BH');
            } else {
                setIsModalVisible(false);
            }
        },
        onError: (err: any) => {
            const validationErrors = err.response?.data?.errors;
            const firstValidationMessage = validationErrors && typeof validationErrors === 'object'
                ? Object.values(validationErrors).flat().find((value) => typeof value === 'string')
                : undefined;
            message.error(
                firstValidationMessage
                || err.response?.data?.message
                || err.response?.data?.error
                || 'Có lỗi xảy ra!',
            );
        }
    });

    const postMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/sales/invoices/${id}/post`);
        },
        onSuccess: (response: any) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || (evidence.id === undefined || evidence.id === null)) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã ghi sổ chứng từ bán hàng.');
                return;
            }
            message.success('Ghi sổ chứng từ bán hàng thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-invoices'] });
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.error || err?.response?.data?.message || 'Lỗi khi ghi sổ!');
        }
    });

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/sales/invoices/${id}/unpost`);
        },
        onSuccess: (response: any) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || (evidence.id === undefined || evidence.id === null)) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã bỏ ghi sổ chứng từ bán hàng.');
                return;
            }
            message.success('Bỏ ghi sổ chứng từ bán hàng thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-invoices'] });
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.error || err?.response?.data?.message || 'Lỗi khi bỏ ghi sổ!');
        }
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/sales/invoices/${id}/duplicate`);
        },
        onSuccess: (response: any) => {
            const duplicated = response?.data?.data ?? response?.data;
            if (!duplicated || duplicated.id === undefined || duplicated.id === null) {
                message.error('Máy chủ không trả về chứng từ bán hàng nhân bản đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Nhân bản chứng từ bán hàng thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-invoices'] });
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.error || err?.response?.data?.message || 'Lỗi khi nhân bản chứng từ!');
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.delete(`/sales/invoices/${id}`);
        },
        onSuccess: (response: any) => {
            if (typeof response?.data?.message !== 'string' && response?.data?.success !== true) {
                message.error('Máy chủ không xác nhận đã xóa chứng từ bán hàng.');
                return;
            }
            message.success('Đã xóa chứng từ bán hàng thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-invoices'] });
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.error || err?.response?.data?.message || 'Lỗi khi xóa chứng từ!');
        }
    });

    const handleOpenCreateModal = async () => {
        form.resetFields();
        setEditingInvoiceId(null);
        setIsViewMode(false);
        let nextInvoiceNumber = '';
        try {
            const { data } = await api.get('/sales/invoices/next-code');
            nextInvoiceNumber = data?.invoice_number || data?.voucher_number || data?.data?.code || data?.code || data?.data || '';
        } catch {
            // Leave the identity blank when the server cannot allocate it;
            // validation must stop an unidentifiable source document.
        }

        form.setFieldsValue({
            invoice_number: nextInvoiceNumber,
            delivery_voucher_number: undefined,
            invoice_symbol: undefined,
            invoice_code: undefined,
            accounting_date: dayjs(),
            invoice_date: dayjs(),
            due_date: dayjs().add(30, 'day'),
            due_days: 30,
            payment_terms: 'Thanh toán trong 30 ngày',
            description: 'Bán hàng',
            voucher_type: '1. Bán hàng hóa trong nước',
            lines: [
                {
                    key: '1',
                    item_id: undefined,
                    description: '',
                    unit: undefined,
                    quantity: undefined,
                    unit_price: undefined,
                    amount: undefined,
                    debit_account: undefined,
                    credit_account: undefined,
                    inventory_account: undefined,
                    discount_rate: 0,
                    discount_amount: 0,
                    tax_rate: undefined,
                    tax_amount: undefined,
                    tax_account: undefined,
                    cogs_debit_account: undefined,
                    cogs_credit_account: undefined,
                    cogs_unit_price: undefined,
                    cogs_amount: undefined,
                }
            ]
        });
        setPaymentMethod('unpaid');
        setPaymentSubMethod('cash');
        setVoucherType('1. Bán hàng hóa trong nước');
        setVoucherTab('debt_voucher');
        setDiscountMode('none');
        setIsIncludeDelivery(true);
        setIsIncludeInvoice(true);
        setActiveGridTab('accounting');
        setIsModalVisible(true);
    };

    useEffect(() => {
        if (searchParams.get('action') === 'create' && !isModalVisible) {
            void handleOpenCreateModal();
            const nextSearchParams = new URLSearchParams(searchParams);
            nextSearchParams.delete('action');
            setSearchParams(nextSearchParams, { replace: true });
        }
    }, [searchParams, setSearchParams, isModalVisible, handleOpenCreateModal]);

    const handleOpenViewRecord = (record: any) => {
        setEditingInvoiceId(record.id);
        setIsViewMode(true);
        populateFormFromRecord(record);
        setIsModalVisible(true);
    };

    useSourceRecordDeepLink({
        records: invoices,
        isLoading,
        isError: isInvoicesError,
        onOpen: handleOpenViewRecord,
        onMissing: () => message.error('Không tìm thấy chứng từ bán hàng nguồn.'),
    });

    const handleOpenEditRecord = (record: any) => {
        if (record.is_posted) {
            Modal.confirm({
                title: 'Xác nhận bỏ ghi sổ để sửa',
                content: `Chứng từ ${record.invoice_number} đã ghi sổ. Bạn có muốn BỎ GHI SỔ để chỉnh sửa không?`,
                okText: 'Bỏ ghi và Sửa',
                okType: 'primary',
                cancelText: 'Hủy',
                onOk: async () => {
                    await unpostMutation.mutateAsync(record.id);
                    setEditingInvoiceId(record.id);
                    setIsViewMode(false);
                    populateFormFromRecord({ ...record, is_posted: false });
                    setIsModalVisible(true);
                }
            });
        } else {
            setEditingInvoiceId(record.id);
            setIsViewMode(false);
            populateFormFromRecord(record);
            setIsModalVisible(true);
        }
    };

    const populateFormFromRecord = (record: any) => {
        form.resetFields();
        form.setFieldsValue({
            customer_id: record.customer_id,
            customer_name: record.customer_name || record.customer?.name,
            customer_address: record.customer_address || record.customer?.address,
            receiver_name: record.receiver_name || record.customer_name,
            tax_code: record.tax_code || record.customer?.tax_code,
            employee_id: record.employee_id,
            invoice_number: record.invoice_number,
            delivery_voucher_number: record.delivery_voucher_number,
            invoice_symbol: record.invoice_symbol,
            invoice_code: record.invoice_code,
            accounting_date: record.accounting_date ? dayjs(record.accounting_date) : dayjs(),
            invoice_date: record.invoice_date ? dayjs(record.invoice_date) : dayjs(),
            due_date: record.due_date ? dayjs(record.due_date) : undefined,
            description: record.description,
            voucher_type: record.voucher_type || '1. Bán hàng hóa, dịch vụ trong nước',
            lines: record.lines && record.lines.length > 0 ? record.lines.map((l: any, idx: number) => ({
                key: `${idx + 1}`,
                item_id: l.item_id,
                description: l.description,
                unit: l.unit,
                quantity: l.quantity,
                unit_price: l.unit_price,
                amount: l.amount,
                debit_account: l.debit_account,
                credit_account: l.credit_account,
                inventory_account: l.inventory_account,
                discount_rate: l.discount_rate,
                discount_amount: l.discount_amount,
                tax_rate: l.tax_rate,
                tax_amount: l.tax_amount,
                tax_account: l.tax_account,
                cogs_debit_account: l.cogs_debit_account,
                cogs_credit_account: l.cogs_credit_account,
                cogs_unit_price: l.cogs_unit_price,
                cogs_amount: l.cogs_amount
            })) : []
        });
        setPaymentMethod(record.payment_method || 'unpaid');
        setIsIncludeDelivery(record.is_export_slip !== false);
        setIsIncludeInvoice(record.is_include_invoice !== false);
    };

    const handleSwitchToEdit = () => {
        const isPosted = invoices?.find((i: any) => i.id === editingInvoiceId)?.is_posted;
        if (isPosted && editingInvoiceId) {
            Modal.confirm({
                title: 'Xác nhận bỏ ghi sổ',
                content: `Chứng từ ${form.getFieldValue('invoice_number')} đã ghi sổ. Bạn có muốn BỎ GHI SỔ để chỉnh sửa không?`,
                okText: 'Bỏ ghi và Sửa',
                okType: 'primary',
                cancelText: 'Hủy',
                onOk: async () => {
                    await unpostMutation.mutateAsync(editingInvoiceId);
                    setIsViewMode(false);
                }
            });
        } else {
            setIsViewMode(false);
        }
    };

    const handleOpenPrintRecord = (record: any, type: '01-BH' | '02-VT') => {
        const lines = Array.isArray(record.lines) ? record.lines : [];
        if (lines.length === 0) {
            message.warning('Không thể in chứng từ vì máy chủ chưa cung cấp dòng chi tiết đã lưu.');
            return;
        }

        setPrintType(type);
        setPrintData({
            voucher_number: record.invoice_number || record.invoice_code,
            invoice_number: record.invoice_code || record.invoice_number,
            invoice_symbol: record.invoice_symbol,
            voucher_date: record.invoice_date || record.accounting_date,
            customer_name: record.customer_name || record.customer?.name,
            contact_name: record.customer_name || record.customer?.name,
            address: record.customer_address || record.customer?.address,
            customer_address: record.customer_address || record.customer?.address,
            tax_code: record.tax_code || record.customer?.tax_code,
            description: record.description,
            sub_total: Number(record.sub_total || record.total_amount || 0),
            tax_amount: Number(record.tax_amount || 0),
            total_amount: Number(record.total_amount || 0),
            warehouse_name: record.warehouse_name || record.warehouse?.name,
            lines: lines
        });
        setIsPrintModalOpen(true);
    };

    const handleOpenDimensionAssignments = (record: SalesInvoiceRecord) => {
        setDimensionInvoice(record);
        setIsDimensionAssignmentsOpen(true);
    };

    const handleOpenApprovalWorkflow = (record: SalesInvoiceRecord) => {
        setApprovalInvoice(record);
        setIsApprovalWorkflowOpen(true);
    };

    const handleCreateReceipt = (record: SalesInvoiceRecord) => {
        setCollectionInvoice(record);
        setIsCollectByInvoiceOpen(true);
    };

    const handleDeleteRecord = (record: any) => {
        Modal.confirm({
            title: 'Xác nhận xóa hóa đơn bán hàng',
            content: `Bạn có chắc chắn muốn xóa hóa đơn ${record.invoice_number}? Thao tác này không thể hoàn tác.`,
            okText: 'Xóa',
            okType: 'danger',
            cancelText: 'Hủy',
            onOk: () => deleteMutation.mutate(record.id)
        });
    };

    const handleSaveForm = (andNew = false, andPrint = false) => {
        if (paymentMethod === 'bank') {
            message.info('Thanh toán tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ; chứng từ cũ chỉ được xem.');
            return;
        }
        form.validateFields().then(() => {
            // The accounting/tax/COGS grids are conditionally rendered tabs.
            // Read the complete preserved form store so fields entered on a
            // non-active tab are not silently omitted from the API payload.
            const values = form.getFieldsValue(true);
            mutation.mutate({ ...values, andNew, andPrint });
        }).catch(() => {
            message.error('Vui lòng kiểm tra lại các trường thông tin bắt buộc (màu đỏ)!');
        });
    };

    const handleAddLine = () => {
        const curLines = form.getFieldValue('lines') || [];

        form.setFieldsValue({
            lines: [
                ...curLines,
                {
                    key: `${Date.now()}`,
                }
            ]
        });
    };

    // Voucher keyboard shortcuts
    useVoucherShortcuts({
        onSave: () => !isViewMode && handleSaveForm(false, false),
        onSaveAndNew: () => !isViewMode && handleSaveForm(true, false),
        onPrint: () => !isViewMode && handleSaveForm(false, true),
        onAddLine: handleAddLine,
        onClose: () => {
            if (!isCustomerModalVisible && !isEmployeeModalVisible) {
                setIsModalVisible(false);
            }
        },
        enabled: isModalVisible
    });

    const handleItemChange = (index: number, itemId: number, explicitItem?: any) => {
        const item = explicitItem || items?.find((i: any) => i.id === itemId);
        if (item) {
            const curLines = form.getFieldValue('lines') || [];
            const rawPrice = item.sale_price ?? item.purchase_price;
            const price = rawPrice === undefined || rawPrice === null ? undefined : Number(rawPrice);
            const rawCost = item.cost_price;
            const costPrice = rawCost === undefined || rawCost === null ? undefined : Number(rawCost);
            const quantity = curLines[index]?.quantity == null ? 1 : Number(curLines[index].quantity);
            const taxRate = curLines[index]?.tax_rate ?? item.tax_rate ?? 10;
            const amount = price !== undefined && Number.isFinite(price) && quantity !== undefined && Number.isFinite(quantity)
                ? price * quantity
                : undefined;
            const taxAmount = amount !== undefined && taxRate !== undefined && taxRate > 0 ? (amount * taxRate / 100) : undefined;

            curLines[index] = {
                ...curLines[index],
                item_id: item.id,
                description: `Bán ${item.name}`,
                unit: item.unit || 'Cái',
                quantity,
                unit_price: price,
                amount,
                debit_account: curLines[index]?.debit_account || '131',
                credit_account: curLines[index]?.credit_account || item.revenue_account || '5111',
                tax_account: curLines[index]?.tax_account || '33311',
                inventory_account: curLines[index]?.inventory_account || item.inventory_account || '1561',
                cogs_debit_account: curLines[index]?.cogs_debit_account || item.cogs_account || '632',
                cogs_unit_price: costPrice,
                cogs_amount: costPrice !== undefined && quantity !== undefined ? costPrice * quantity : undefined,
                tax_rate: taxRate,
                tax_amount: taxAmount
            };
            form.setFieldsValue({ lines: [...curLines] });
        }
    };

    const columns: ColumnsType<SalesInvoiceRecord> = [
        {
            title: 'Ngày hạch toán',
            dataIndex: 'accounting_date',
            key: 'accounting_date',
            width: 110,
            align: 'center',
            render: (val: any) => formatDate(val)
        },
        {
            title: 'Ngày chứng từ',
            dataIndex: 'invoice_date',
            key: 'invoice_date',
            width: 110,
            align: 'center',
            render: (val: any) => formatDate(val)
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'invoice_number',
            key: 'invoice_number',
            width: 125,
            render: (text: string) => <span className="misa-table-link-bold">{text}</span>
        },
        {
            title: 'Khách hàng',
            dataIndex: 'customer_name',
            key: 'customer_name',
            width: 240,
            render: (name: string, r: any) => (
                <div className="misa-cell-title-box">
                    <div className="misa-cell-main-title-dark">{name || r.customer?.name || '—'}</div>
                    <div className="misa-cell-sub-title">MST: {r.tax_code || r.customer?.tax_code || '—'}</div>
                </div>
            )
        },
        {
            title: 'Diễn giải',
            dataIndex: 'description',
            key: 'description'
        },
        {
            title: 'Tổng tiền thanh toán',
            dataIndex: 'total_amount',
            key: 'total_amount',
            align: 'right',
            width: 160,
            render: (amount: number, record: any) => {
                const total = amount || record.lines?.reduce((s: number, l: any) => s + (Number(l.amount) || 0) + (Number(l.tax_amount) || 0), 0) || 0;
                return <span className="misa-table-amount-bold">{new Intl.NumberFormat('vi-VN').format(total)} ₫</span>;
            }
        },
        {
            title: 'Trạng thái ghi sổ',
            dataIndex: 'is_posted',
            key: 'is_posted',
            align: 'center',
            width: 120,
            render: (posted: boolean) => (
                <span className={`misa-apple-pill ${posted ? 'misa-apple-pill-green' : 'misa-apple-pill-orange'}`}>
                    <span className="misa-apple-pill-dot" />
                    {posted ? 'Đã ghi sổ' : 'Bản nháp'}
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'action',
            align: 'center',
            width: 140,
            render: (_: any, record: any) => {
                const menuItems: MenuProps['items'] = [
                    record.is_posted ? {
                        key: 'unpost',
                        label: 'Bỏ ghi sổ',
                        icon: <CloseCircleOutlined className="misa-icon-warning" />,
                        onClick: () => unpostMutation.mutate(record.id)
                    } : {
                        key: 'post',
                        label: 'Ghi sổ',
                        icon: <CheckCircleOutlined className="misa-icon-success" />,
                        onClick: () => postMutation.mutate(record.id)
                    },
                    {
                        key: 'edit',
                        label: record.is_posted ? 'Sửa (Bỏ ghi)' : 'Sửa',
                        icon: <EditOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenEditRecord(record)
                    },
                    {
                        key: 'dimensions',
                        label: 'Chiều hạch toán',
                        icon: <SettingOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenDimensionAssignments(record)
                    },
                    {
                        key: 'approval',
                        label: 'Gửi/Xem phê duyệt',
                        icon: <FileProtectOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenApprovalWorkflow(record)
                    },
                    {
                        key: 'duplicate',
                        label: 'Nhân bản',
                        icon: <CopyOutlined className="misa-icon-primary" />,
                        onClick: () => duplicateMutation.mutate(record.id)
                    },
                    {
                        key: 'receipt_cash',
                        label: 'Thu tiền theo hóa đơn',
                        icon: <DollarOutlined className="misa-icon-blue" />,
                        onClick: () => handleCreateReceipt(record)
                    },
                    {
                        key: 'print_bh',
                        label: 'In Hóa đơn bán hàng (01-BH)',
                        icon: <PrinterOutlined className="misa-icon-success" />,
                        onClick: () => handleOpenPrintRecord(record, '01-BH')
                    },
                    {
                        key: 'print_vt',
                        label: 'In Phiếu xuất kho (02-VT)',
                        icon: <PrinterOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenPrintRecord(record, '02-VT')
                    },
                    { type: 'divider' },
                    {
                        key: 'delete',
                        label: 'Xóa',
                        danger: true,
                        disabled: !!record.is_posted,
                        icon: <DeleteOutlined />,
                        onClick: () => handleDeleteRecord(record)
                    }
                ];

                return (
                    <div className="misa-inline-flex-center misa-gap-4">
                        <Button
                            type="link"
                            size="small"
                            className="misa-text-primary misa-text-semibold misa-px-4"
                            onClick={() => record.is_posted ? handleOpenViewRecord(record) : handleOpenEditRecord(record)}
                        >
                            {record.is_posted ? 'Xem' : 'Sửa'}
                        </Button>
                        <Dropdown
                            trigger={['click']}
                            menu={{ items: menuItems }}
                        >
                            <Button type="link" size="small" className="misa-btn-action-more">
                                <DownOutlined className="misa-icon-xs" />
                            </Button>
                        </Dropdown>
                    </div>
                );
            }
        }
    ];

    return (
            <PageShell
                title={(
                    <PageHeader
                        eyebrow="BÁN HÀNG"
                        title="Chứng từ bán hàng"
                        description="Tra cứu và lập chứng từ bán hàng theo dữ liệu được máy chủ cung cấp."
                    />
                )}
                toolbar={(
                    <PageToolbar
                    filters={(
                        <div className="misa-toolbar-left">
                    <div className="misa-search-box">
                        <Input
                            placeholder="Tìm kiếm chứng từ, khách hàng..."
                            prefix={<SearchOutlined className="misa-header-qrcode" />}
                            className="misa-input"
                            allowClear
                        />
                    </div>
                    <Select
                        value={datePreset}
                        onChange={setDatePreset}
                        className="misa-date-select"
                        options={[
                            { value: 'Hôm nay', label: 'Hôm nay' },
                            { value: 'Tuần này', label: 'Tuần này' },
                            { value: 'Tháng này', label: 'Tháng này' },
                            { value: 'Quý này', label: 'Quý này' },
                            { value: 'Năm nay', label: 'Năm nay' },
                        ]}
                    />
                        </div>
                    )}
                    actions={(
                        <div className="misa-toolbar-right">
                    <button
                        type="button"
                        className="misa-btn-tool"
                        title="Làm mới"
                        onClick={() => queryClient.invalidateQueries({ queryKey: ['sales-invoices'] })}
                    >
                        <ReloadOutlined />
                    </button>
                    <button
                        type="button"
                        className="misa-btn-tool"
                        title="In"
                        onClick={() => window.print()}
                    >
                        <PrinterOutlined />
                    </button>
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        className="misa-btn-primary"
                        onClick={handleOpenCreateModal}
                    >
                        Thêm chứng từ bán hàng
                    </Button>
                        </div>
                    )}
                    />
                )}
            >

            {/* List Table */}
            <DataTableSurface className="misa-voucher-surface">
                {isInvoicesError ? <Alert
                    type="error"
                    showIcon
                    title="Không thể tải danh sách chứng từ bán hàng"
                    description="Dữ liệu chưa được xác minh từ máy chủ; không hiển thị danh sách rỗng thay thế."
                    action={<Button onClick={() => void refetchInvoices()}>Thử lại danh sách chứng từ bán hàng</Button>}
                /> : <Table
                    columns={columns}
                    dataSource={invoices}
                    rowKey="id"
                    loading={isLoading}
                    size="small"
                    bordered
                    className="misa-voucher-table"
                    pagination={{ pageSize: 20 }}
                    locale={{ emptyText: (
                        <div className="misa-empty-box">
                            <InboxOutlined className="misa-empty-icon" />
                            Chưa có chứng từ bán hàng nào. Nhấn "+ Thêm chứng từ bán hàng" để bắt đầu.
                        </div>
                    )}}
                />}
            </DataTableSurface>

            {/* Modal Chứng từ bán hàng */}
            <Modal
                title={
                    <div className="misa-modal-header-wrapper">
                        <div className="misa-flex-center misa-gap-12">
                            <HistoryOutlined
                                style={{ fontSize: 18, color: '#4b5563', cursor: 'pointer' }}
                                onClick={() => {
                                    void api.get('/sales/invoices/next-code').then(({ data }) => {
                                        const nextCode = data?.invoice_number || data?.voucher_number || data?.data?.code || data?.code || data?.data || '';
                                        if (nextCode) {
                                            form.setFieldValue('invoice_number', nextCode);
                                            message.success('Đã lấy số chứng từ từ máy chủ.');
                                        } else {
                                            message.warning('Máy chủ chưa cung cấp số chứng từ.');
                                        }
                                    }).catch(() => message.warning('Không lấy được số chứng từ từ máy chủ.'));
                                }}
                                title="Tự động sinh lại số chứng từ mới"
                            />
                            <span className="misa-voucher-header-title">
                                Chứng từ bán hàng {voucherNumber || ''}
                            </span>
                            {isViewMode && (
                                <span className="misa-apple-pill misa-apple-pill-blue">
                                    <span className="misa-apple-pill-dot" />
                                    Chế độ xem
                                </span>
                            )}
                            {isIncludeInvoice ? (
                                <span className="misa-apple-pill misa-apple-pill-blue">
                                    <span className="misa-apple-pill-dot" />
                                    Đã lập hóa đơn
                                </span>
                            ) : (
                                <span className="misa-apple-pill misa-apple-pill-gray">
                                    Chưa lập hóa đơn
                                </span>
                            )}
                        </div>

                        <div className="misa-voucher-header-right">
                            <button type="button" className="misa-icon-btn" title="Thiết lập">
                                <SettingOutlined />
                            </button>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                width="100vw"
                style={{ top: 0, margin: 0, paddingBottom: 0, maxWidth: '100vw' }}
                className="misa-voucher-modal misa-voucher-modal-container"
                closeIcon={<CloseOutlined className="misa-btn-tool-sm" />}
                styles={{
                    header: { padding: '10px 18px', borderBottom: '1px solid #e5e7eb' },
                    body: { padding: '12px 16px', maxHeight: 'calc(100vh - 190px)', overflowY: 'auto', overflowX: 'hidden', display: 'flex', flexDirection: 'column', gap: 10, background: '#eaedf2' }
                }}
                footer={
                    <div className="misa-modal-footer">
                        <div className="misa-footer-left">
                            <Switch size="small" checked={showAccounts} onChange={setShowAccounts} />
                            <span className="misa-footer-switch-label">Hiển thị tài khoản</span>
                        </div>
                        <div className="misa-footer-right">
                            {isViewMode ? (
                                <Space size={8}>
                                    <Button
                                        onClick={() => setIsModalVisible(false)}
                                        className="misa-btn-secondary"
                                    >
                                        Đóng (Esc)
                                    </Button>
                                    <Button
                                        type="primary"
                                        icon={<EditOutlined />}
                                        onClick={handleSwitchToEdit}
                                        className="misa-btn-primary misa-text-bold"
                                    >
                                        Sửa (Ctrl+E)
                                    </Button>
                                </Space>
                            ) : (
                                <Space size={8}>
                                    <Button
                                        onClick={() => setIsModalVisible(false)}
                                        className="misa-btn-secondary"
                                    >
                                        Hủy (Esc)
                                    </Button>
                                    <Button
                                        onClick={() => handleSaveForm(true, false)}
                                        className="misa-btn-secondary misa-text-bold"
                                    >
                                        Cất và Thêm
                                    </Button>
                                    <Button
                                        type="primary"
                                        onClick={() => handleSaveForm(false, false)}
                                        className="misa-btn-primary misa-text-bold"
                                    >
                                        Cất
                                    </Button>
                                    <Button
                                        type="primary"
                                        onClick={() => handleSaveForm(false, true)}
                                        className="misa-btn-primary misa-text-bold"
                                    >
                                        Cất và In
                                    </Button>
                                </Space>
                            )}
                        </div>
                    </div>
                }
                closable={true}
            >
                <ModalFrame bodyStyle={{ width: '100%', maxWidth: '100%', minWidth: 0, overflowX: 'hidden' }}>
                <Form form={form} layout="vertical" size="small" disabled={isViewMode} className="misa-form-flex-col">
                    {/* Top Config Bar matching MISA AMIS */}
                    <div className="misa-config-bar">
                        <div className="misa-config-bar-left">
                            {/* Voucher Type */}
                            <div className="misa-flex-center misa-gap-6">
                                <Select
                                    value={voucherType}
                                    onChange={setVoucherType}
                                    className="misa-input misa-select-w275"
                                    disabled={isViewMode}
                                    popupMatchSelectWidth={false}
                                    options={[
                                        { value: '1. Bán hàng hóa trong nước', label: '1. Bán hàng hóa trong nước' },
                                        { value: '2. Bán hàng xuất khẩu', label: '2. Bán hàng xuất khẩu' },
                                        { value: '3. Bán hàng đại lý bán đúng giá', label: '3. Bán hàng đại lý bán đúng giá' },
                                        { value: '4. Bán hàng ủy thác xuất khẩu', label: '4. Bán hàng ủy thác xuất khẩu' },
                                    ]}
                                />
                            </div>

                            {/* Source reference */}
                            <div className="misa-flex-center misa-gap-6">
                                <Input
                                    placeholder="Nhập số phiếu xuất..."
                                    prefix={<SearchOutlined className="apple-muted-text" />}
                                    className="misa-input misa-input-w230"
                                    disabled={isViewMode}
                                />
                            </div>

                            {/* Payment Method */}
                            <div className="misa-flex-center misa-gap-12">
                                <Radio.Group value={paymentMethod} onChange={e => setPaymentMethod(e.target.value)} disabled={isViewMode}>
                                    <Radio value="unpaid"><span className="misa-text-semibold">Chưa thu tiền</span></Radio>
                                    <Radio value="cash"><span className="misa-text-semibold">Thu tiền ngay</span></Radio>
                                </Radio.Group>
                                {paymentMethod !== 'unpaid' && (
                                    <Select
                                        value={paymentSubMethod}
                                        onChange={setPaymentSubMethod}
                                        disabled={isViewMode}
                                        className="misa-input misa-select-w155"
                                        options={[
                                            { value: 'cash', label: 'Tiền mặt' },
                                            { value: 'bank', label: 'Chuyển khoản' }
                                        ]}
                                    />
                                )}
                            </div>

                            {/* Checkboxes: Kiêm phiếu xuất, Lập kèm hóa đơn */}
                            <div className="misa-flex-center misa-gap-16">
                                <Checkbox checked={isIncludeDelivery} onChange={e => handleToggleDelivery(e.target.checked)} disabled={isViewMode}>
                                    <span className="misa-text-semibold">Kiêm phiếu xuất</span>
                                </Checkbox>
                                <Checkbox checked={isIncludeInvoice} onChange={e => handleToggleInvoice(e.target.checked)} disabled={isViewMode}>
                                    <span className="misa-text-semibold">Lập kèm hóa đơn</span>
                                </Checkbox>
                            </div>
                        </div>

                        <div className="misa-config-bar-right">
                            <Checkbox checked={showAccounts} onChange={e => setShowAccounts(e.target.checked)}>
                                <span className="apple-muted-text">Hiển thị tài khoản</span>
                            </Checkbox>
                        </div>
                    </div>

                    {/* Master Form Section */}
                    <MisaMasterCard className="misa-mb-12">
                        {/* Sub-tabs Chứng từ liên quan */}
                        <div className="misa-master-tabs">
                            <button
                                type="button"
                                className={`misa-master-tab-btn ${voucherTab === 'debt_voucher' ? 'active' : ''}`}
                                onClick={() => setVoucherTab('debt_voucher')}
                            >
                                {primaryTabTitle}
                            </button>
                            {isIncludeDelivery && (
                                <button
                                    type="button"
                                    className={`misa-master-tab-btn ${voucherTab === 'delivery_voucher' ? 'active' : ''}`}
                                    onClick={() => setVoucherTab('delivery_voucher')}
                                >
                                    Phiếu xuất
                                </button>
                            )}
                            {isIncludeInvoice && (
                                <button
                                    type="button"
                                    className={`misa-master-tab-btn ${voucherTab === 'invoice' ? 'active' : ''}`}
                                    onClick={() => setVoucherTab('invoice')}
                                >
                                    Hóa đơn
                                </button>
                            )}
                        </div>

                        <div className="misa-master-layout">
                            <div className="misa-master-left">
                                <div className="misa-form-grid">
                                {voucherTab === 'debt_voucher' && (
                                    <>
                                        {/* Row 1: Mã KH ($) + Tên KH + MST */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label required">Mã khách hàng</div>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                                <Form.Item name="customer_id" noStyle rules={[{ required: true, message: 'Chọn khách hàng' }]}>
                                                    <MultiColumnContactSelect
                                                        placeholder="Chọn khách hàng..."
                                                        disabled={isViewMode}
                                                        options={customers?.map((c: any) => ({
                                                            id: c.id,
                                                            code: c.code,
                                                            name: c.name,
                                                            tax_code: c.tax_code,
                                                            address: c.address,
                                                            phone: c.phone,
                                                            type: 'customer'
                                                        }))}
                                                        value={form.getFieldValue('customer_id')}
                                                        onChange={(val, item) => {
                                                            form.setFieldsValue({
                                                                customer_id: val,
                                                                customer_name: item?.name || '',
                                                                customer_address: item?.address || '',
                                                                tax_code: item?.tax_code || '',
                                                                receiver_name: item?.name || ''
                                                            });
                                                        }}
                                                        onQuickAdd={() => setIsCustomerModalVisible(true)}
                                                    />
                                                </Form.Item>
                                                <Button
                                                    icon={<DollarOutlined />}
                                                    style={{ color: '#1677ff', borderColor: '#1677ff', padding: '0 6px', height: 32 }}
                                                    title="Tra cứu công nợ khách hàng"
                                                    onClick={() => setIsCustomerDebtModalOpen(true)}
                                                />
                                            </div>
                                        </div>

                                        <div className="misa-col-5">
                                            <div className="misa-field-label">Tên khách hàng</div>
                                            <Form.Item name="customer_name" noStyle>
                                                <Input className="misa-input" placeholder="Tên khách hàng" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-3">
                                            <div className="misa-field-label">Mã số thuế</div>
                                            <Form.Item name="tax_code" noStyle>
                                                <Input className="misa-input" placeholder="Mã số thuế" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 2: Người liên hệ + Địa chỉ */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Người liên hệ</div>
                                            <Form.Item name="receiver_name" noStyle>
                                                <Input className="misa-input" placeholder="Người liên hệ" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-8">
                                            <div className="misa-field-label">Địa chỉ</div>
                                            <Form.Item name="customer_address" noStyle>
                                                <Input className="misa-input" placeholder="Địa chỉ" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 3: Nhân viên bán hàng + Diễn giải */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Nhân viên bán hàng</div>
                                            <Form.Item name="employee_id" noStyle>
                                                <Select
                                                    placeholder="Chọn nhân viên..."
                                                    className="misa-input misa-w-full"
                                                    style={{ width: '100%' }}
                                                    disabled={isViewMode}
                                                    options={employees?.map((e: any) => ({ value: e.id, label: `${e.code} - ${e.name}` }))}
                                                    dropdownRender={(menu) => (
                                                        <>
                                                            {menu}
                                                            <div className="misa-p-4 misa-border-t">
                                                                <Button
                                                                    type="link"
                                                                    size="small"
                                                                    icon={<PlusOutlined />}
                                                                    onClick={() => setIsEmployeeModalVisible(true)}
                                                                    className="misa-text-primary"
                                                                >
                                                                    Thêm nhân viên nhanh
                                                                </Button>
                                                            </div>
                                                        </>
                                                    )}
                                                />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-8">
                                            <div className="misa-field-label">Diễn giải</div>
                                            <Form.Item name="description" noStyle>
                                                <Input className="misa-input" placeholder="Bán hàng" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 4: Tham chiếu */}
                                        <div className="misa-col-12" style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '2px 0' }}>
                                            <span style={{ fontSize: 13, color: '#4b5563' }}>Tham chiếu</span>
                                            <Button
                                                size="small"
                                                onClick={() => setIsRefModalVisible(true)}
                                                disabled={isViewMode}
                                                style={{ padding: '0 8px', height: 22, fontSize: 12, borderRadius: 3 }}
                                            >
                                                ... {referencedVouchers.length > 0 && `(${referencedVouchers.length})`}
                                            </Button>
                                        </div>

                                        {/* Row 5: Collapsible Điều khoản thanh toán */}
                                        <div className="misa-col-12" style={{ marginTop: 4 }}>
                                            <div
                                                style={{ display: 'flex', alignItems: 'center', gap: 6, cursor: 'pointer', marginBottom: 4 }}
                                                onClick={() => setIsPaymentTermsExpanded(!isPaymentTermsExpanded)}
                                            >
                                                <span style={{ fontSize: 10, color: '#4b5563' }}>{isPaymentTermsExpanded ? '▾' : '▸'}</span>
                                                <span style={{ fontSize: 12, fontWeight: 600, color: '#374151' }}>Điều khoản thanh toán</span>
                                            </div>
                                            {isPaymentTermsExpanded && (
                                                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(12, 1fr)', gap: 8, padding: '6px 10px', background: '#f9fafb', borderRadius: 4, border: '1px solid #f3f4f6' }}>
                                                    <div style={{ gridColumn: 'span 4' }}>
                                                        <div className="misa-field-label">Điều khoản thanh toán</div>
                                                        {!isViewMode ? (
                                                            <div className="misa-input-group misa-w-full" style={{ width: '100%' }}>
                                                                <Form.Item name="payment_terms" noStyle>
                                                                    <Select
                                                                        placeholder="Chọn điều khoản..."
                                                                        variant="borderless"
                                                                        className="misa-w-full"
                                                                        style={{ width: '100%' }}
                                                                        onChange={handlePaymentTermChange}
                                                                        options={paymentTermOptions}
                                                                    />
                                                                </Form.Item>
                                                                <button
                                                                    type="button"
                                                                    className="misa-plus-btn"
                                                                    title="Thêm nhanh (F9)"
                                                                    onClick={() => setIsPaymentTermModalVisible(true)}
                                                                >
                                                                    <PlusOutlined />
                                                                </button>
                                                            </div>
                                                        ) : (
                                                            <Form.Item name="payment_terms" noStyle>
                                                                <Select
                                                                    placeholder="Chọn điều khoản..."
                                                                    disabled={true}
                                                                    className="misa-input misa-w-full"
                                                                    onChange={handlePaymentTermChange}
                                                                    options={paymentTermOptions}
                                                                />
                                                            </Form.Item>
                                                        )}
                                                    </div>

                                                    <div style={{ gridColumn: 'span 4' }}>
                                                        <div className="misa-field-label">Số ngày được nợ</div>
                                                        <Form.Item name="due_days" noStyle>
                                                            <InputNumber
                                                                min={0}
                                                                className="misa-input misa-w-full"
                                                                disabled={isViewMode}
                                                                onChange={(val) => {
                                                                    if (val != null) {
                                                                        form.setFieldValue('due_date', dayjs().add(Number(val), 'day'));
                                                                    }
                                                                }}
                                                            />
                                                        </Form.Item>
                                                    </div>

                                                    <div style={{ gridColumn: 'span 4' }}>
                                                        <div className="misa-field-label">Hạn thanh toán</div>
                                                        <Form.Item name="due_date" noStyle>
                                                            <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" disabled={isViewMode} />
                                                        </Form.Item>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </>
                                )}

                                {voucherTab === 'delivery_voucher' && (
                                    <>
                                        {/* Row 1: Mã KH ($) + Tên KH */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label required">Mã khách hàng</div>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                                <Form.Item name="customer_id" noStyle rules={[{ required: true, message: 'Chọn khách hàng' }]}>
                                                    <MultiColumnContactSelect
                                                        placeholder="Chọn khách hàng..."
                                                        disabled={isViewMode}
                                                        options={customers?.map((c: any) => ({
                                                            id: c.id,
                                                            code: c.code,
                                                            name: c.name,
                                                            tax_code: c.tax_code,
                                                            address: c.address,
                                                            phone: c.phone,
                                                            type: 'customer'
                                                        }))}
                                                        value={form.getFieldValue('customer_id')}
                                                        onChange={(val, item) => {
                                                            form.setFieldsValue({
                                                                customer_id: val,
                                                                customer_name: item?.name || '',
                                                                customer_address: item?.address || '',
                                                                tax_code: item?.tax_code || '',
                                                                receiver_name: item?.name || ''
                                                            });
                                                        }}
                                                        onQuickAdd={() => setIsCustomerModalVisible(true)}
                                                    />
                                                </Form.Item>
                                                <Button
                                                    icon={<DollarOutlined />}
                                                    style={{ color: '#1677ff', borderColor: '#1677ff', padding: '0 6px', height: 32 }}
                                                    title="Tra cứu công nợ khách hàng"
                                                    onClick={() => setIsCustomerDebtModalOpen(true)}
                                                />
                                            </div>
                                        </div>

                                        <div className="misa-col-8">
                                            <div className="misa-field-label">Tên khách hàng</div>
                                            <Form.Item name="customer_name" noStyle>
                                                <Input className="misa-input" placeholder="Tên khách hàng" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 2: Người nhận hàng + Địa chỉ giao */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Người nhận hàng</div>
                                            <Form.Item name="receiver_name" noStyle>
                                                <Input className="misa-input" placeholder="Người nhận hàng" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-8">
                                            <div className="misa-field-label">Địa chỉ giao hàng</div>
                                            <Form.Item name="customer_address" noStyle>
                                                <Input className="misa-input" placeholder="Địa chỉ giao hàng" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 3: Nhân viên bán hàng + Lý do xuất + Kèm theo */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Nhân viên bán hàng</div>
                                            <Form.Item name="employee_id" noStyle>
                                                <Select
                                                    placeholder="Chọn nhân viên..."
                                                    className="misa-input misa-w-full"
                                                    style={{ width: '100%' }}
                                                    disabled={isViewMode}
                                                    options={employees?.map((e: any) => ({ value: e.id, label: `${e.code} - ${e.name}` }))}
                                                />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-6">
                                            <div className="misa-field-label">Lý do xuất</div>
                                            <Form.Item name="description" noStyle>
                                                <Input className="misa-input" placeholder="Xuất kho bán hàng" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-2">
                                            <div className="misa-field-label">Kèm theo</div>
                                            <Form.Item name="attached_docs" noStyle>
                                                <Input className="misa-input text-right" placeholder="0 CT gốc" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 4: Tham chiếu */}
                                        <div className="misa-col-12" style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '2px 0' }}>
                                            <span style={{ fontSize: 13, color: '#4b5563' }}>Tham chiếu</span>
                                            <Button
                                                size="small"
                                                onClick={() => setIsRefModalVisible(true)}
                                                disabled={isViewMode}
                                                style={{ padding: '0 8px', height: 22, fontSize: 12, borderRadius: 3 }}
                                            >
                                                ... {referencedVouchers.length > 0 && `(${referencedVouchers.length})`}
                                            </Button>
                                        </div>
                                    </>
                                )}

                                {voucherTab === 'invoice' && (
                                    <>
                                        {/* Row 1: Mã KH ($) + Tên KH + MST */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label required">Mã khách hàng</div>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                                <Form.Item name="customer_id" noStyle rules={[{ required: true, message: 'Chọn khách hàng' }]}>
                                                    <MultiColumnContactSelect
                                                        placeholder="Chọn khách hàng..."
                                                        disabled={isViewMode}
                                                        options={customers?.map((c: any) => ({
                                                            id: c.id,
                                                            code: c.code,
                                                            name: c.name,
                                                            tax_code: c.tax_code,
                                                            address: c.address,
                                                            phone: c.phone,
                                                            type: 'customer'
                                                        }))}
                                                        value={form.getFieldValue('customer_id')}
                                                        onChange={(val, item) => {
                                                            form.setFieldsValue({
                                                                customer_id: val,
                                                                customer_name: item?.name || '',
                                                                customer_address: item?.address || '',
                                                                tax_code: item?.tax_code || '',
                                                                receiver_name: item?.name || ''
                                                            });
                                                        }}
                                                        onQuickAdd={() => setIsCustomerModalVisible(true)}
                                                    />
                                                </Form.Item>
                                                <Button
                                                    icon={<DollarOutlined />}
                                                    style={{ color: '#1677ff', borderColor: '#1677ff', padding: '0 6px', height: 32 }}
                                                    title="Tra cứu công nợ khách hàng"
                                                    onClick={() => setIsCustomerDebtModalOpen(true)}
                                                />
                                            </div>
                                        </div>

                                        <div className="misa-col-5">
                                            <div className="misa-field-label">Tên khách hàng</div>
                                            <Form.Item name="customer_name" noStyle>
                                                <Input className="misa-input" placeholder="Tên khách hàng" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-3">
                                            <div className="misa-field-label">Mã số thuế</div>
                                            <Form.Item name="tax_code" noStyle>
                                                <Input className="misa-input" placeholder="Mã số thuế" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 2: Mã QHNS + Số CCCD + Số hộ chiếu */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Mã QHNS</div>
                                            <Form.Item name="budget_relation_code" noStyle>
                                                <Input className="misa-input" placeholder="Mã quan hệ NS" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Số CCCD/CMND</div>
                                            <Form.Item name="id_card_number" noStyle>
                                                <Input className="misa-input" placeholder="Số CCCD" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Số hộ chiếu</div>
                                            <Form.Item name="passport_number" noStyle>
                                                <Input className="misa-input" placeholder="Số hộ chiếu" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 3: Địa chỉ + Người mua hàng */}
                                        <div className="misa-col-8">
                                            <div className="misa-field-label">Địa chỉ</div>
                                            <Form.Item name="customer_address" noStyle>
                                                <Input className="misa-input" placeholder="Địa chỉ" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Người mua hàng</div>
                                            <Form.Item name="receiver_name" noStyle>
                                                <Input className="misa-input" placeholder="Người mua hàng" disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 4: Hình thức thanh toán + Tài khoản ngân hàng */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Hình thức thanh toán</div>
                                            <Form.Item name="payment_method_display" noStyle initialValue="TM/CK">
                                                <Select
                                                    className="misa-input misa-w-full"
                                                    disabled={isViewMode}
                                                    options={[
                                                        { value: 'TM/CK', label: 'TM/CK' },
                                                        { value: 'TM', label: 'Tiền mặt (TM)' },
                                                        { value: 'CK', label: 'Chuyển khoản (CK)' },
                                                    ]}
                                                />
                                            </Form.Item>
                                        </div>

                                        <div className="misa-col-8">
                                            <div className="misa-field-label">Tài khoản ngân hàng</div>
                                            <Form.Item name="bank_account" noStyle>
                                                <Input className="misa-input" placeholder="Số tài khoản ngân hàng..." disabled={isViewMode} />
                                            </Form.Item>
                                        </div>

                                        {/* Row 5: Tham chiếu */}
                                        <div className="misa-col-12" style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '2px 0' }}>
                                            <span style={{ fontSize: 13, color: '#4b5563' }}>Tham chiếu</span>
                                            <Button
                                                size="small"
                                                onClick={() => setIsRefModalVisible(true)}
                                                disabled={isViewMode}
                                                style={{ padding: '0 8px', height: 22, fontSize: 12, borderRadius: 3 }}
                                            >
                                                ... {referencedVouchers.length > 0 && `(${referencedVouchers.length})`}
                                            </Button>
                                        </div>
                                    </>
                                )}
                                </div>
                            </div>

                            <div className="misa-master-right">
                                <div className="misa-flex-col misa-gap-6">
                                    {voucherTab === 'debt_voucher' && (
                                        <>
                                            <div className="misa-meta-row">
                                                <span className="misa-field-label required">Ngày hạch toán</span>
                                                <Form.Item name="accounting_date" noStyle rules={[{ required: true, message: 'Nhập ngày hạch toán' }]}>
                                                    <DatePicker
                                                        className="misa-input misa-w-full"
                                                        format="DD/MM/YYYY HH:mm:ss"
                                                        showTime={{ format: 'HH:mm:ss' }}
                                                        disabled={isViewMode}
                                                    />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-meta-row">
                                                <span className="misa-field-label required">Ngày chứng từ</span>
                                                <Form.Item name="invoice_date" noStyle rules={[{ required: true, message: 'Nhập ngày chứng từ' }]}>
                                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" disabled={isViewMode} />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-meta-row">
                                                <span className="misa-field-label required">{invoiceNumberLabel}</span>
                                                <Form.Item name="invoice_number" noStyle rules={[{ required: true, message: `Nhập ${invoiceNumberLabel.toLowerCase()}` }]}>
                                                    <Input className="misa-input font-semibold misa-text-blue" disabled={isViewMode} />
                                                </Form.Item>
                                            </div>
                                        </>
                                    )}

                                    {voucherTab === 'delivery_voucher' && (
                                        <>
                                            <div className="misa-meta-row">
                                                <span className="misa-field-label required">Ngày hạch toán</span>
                                                <Form.Item name="accounting_date" noStyle rules={[{ required: true, message: 'Nhập ngày hạch toán' }]}>
                                                    <DatePicker
                                                        className="misa-input misa-w-full"
                                                        format="DD/MM/YYYY HH:mm:ss"
                                                        showTime={{ format: 'HH:mm:ss' }}
                                                        disabled={isViewMode}
                                                    />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-meta-row">
                                                <span className="misa-field-label required">Ngày chứng từ</span>
                                                <Form.Item name="invoice_date" noStyle rules={[{ required: true, message: 'Nhập ngày chứng từ' }]}>
                                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" disabled={isViewMode} />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-meta-row">
                                                <span className="misa-field-label required">Số phiếu xuất</span>
                                                <Form.Item name="delivery_voucher_number" noStyle rules={[{ required: true, message: 'Nhập số phiếu xuất' }]}>
                                                    <Input className="misa-input font-semibold misa-text-blue" placeholder="PX00001" disabled={isViewMode} />
                                                </Form.Item>
                                            </div>
                                        </>
                                    )}

                                    {voucherTab === 'invoice' && (
                                        <>
                                            <div className="misa-meta-row">
                                                <span className="misa-field-label">Mẫu số HĐ</span>
                                                <Form.Item name="invoice_form_number" noStyle initialValue="1C26TLL">
                                                    <Input className="misa-input" placeholder="1C26TLL" disabled={isViewMode} />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-meta-row">
                                                <span className="misa-field-label">Ký hiệu HĐ</span>
                                                <Form.Item name="invoice_symbol" noStyle initialValue="C26TLL">
                                                    <Input className="misa-input" placeholder="C26TLL" disabled={isViewMode} />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-meta-row">
                                                <span className="misa-field-label">Số hóa đơn</span>
                                                <Form.Item name="invoice_code" noStyle>
                                                    <Input className="misa-input font-semibold misa-text-blue" placeholder="00000001" disabled={isViewMode} />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-meta-row">
                                                <span className="misa-field-label">Ngày hóa đơn</span>
                                                <Form.Item name="invoice_date" noStyle>
                                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" disabled={isViewMode} />
                                                </Form.Item>
                                            </div>
                                        </>
                                    )}
                                </div>

                                {/* MisaTotalCard Component */}
                                <MisaTotalCard
                                    label="TỔNG TIỀN THANH TOÁN"
                                    value={totals.grandTotal}
                                />
                            </div>
                        </div>
                    </MisaMasterCard>

                    {/* Detail Grid Section */}
                    <div className="misa-grid-section">
                        <div className="misa-grid-tab-bar" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: '1px solid #e5e7eb', paddingBottom: 4, marginBottom: 8 }}>
                            <div style={{ display: 'flex', gap: 4 }}>
                                <button
                                    type="button"
                                    className={`misa-grid-tab-btn ${activeGridTab === 'accounting' ? 'active' : ''}`}
                                    onClick={() => setActiveGridTab('accounting')}
                                    style={{
                                        background: 'none',
                                        border: 'none',
                                        padding: '6px 16px',
                                        fontSize: 13,
                                        fontWeight: activeGridTab === 'accounting' ? 600 : 400,
                                        color: activeGridTab === 'accounting' ? '#1677ff' : '#4b5563',
                                        borderBottom: activeGridTab === 'accounting' ? '2px solid #1677ff' : '2px solid transparent',
                                        cursor: 'pointer'
                                    }}
                                >
                                    Hàng tiền
                                </button>
                                {isIncludeDelivery && (
                                    <button
                                        type="button"
                                        className={`misa-grid-tab-btn ${activeGridTab === 'cogs' ? 'active' : ''}`}
                                        onClick={() => setActiveGridTab('cogs')}
                                        style={{
                                            background: 'none',
                                            border: 'none',
                                            padding: '6px 16px',
                                            fontSize: 13,
                                            fontWeight: activeGridTab === 'cogs' ? 600 : 400,
                                            color: activeGridTab === 'cogs' ? '#1677ff' : '#4b5563',
                                            borderBottom: activeGridTab === 'cogs' ? '2px solid #1677ff' : '2px solid transparent',
                                            cursor: 'pointer'
                                        }}
                                    >
                                        Giá vốn
                                    </button>
                                )}
                            </div>

                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <span style={{ fontSize: 13, color: '#4b5563' }}>Chiết khấu:</span>
                                <Select
                                    value={discountMode}
                                    onChange={setDiscountMode}
                                    disabled={isViewMode}
                                    style={{ width: 230 }}
                                    options={[
                                        { value: 'none', label: 'Không chiết khấu' },
                                        { value: 'line', label: 'Chiết khấu theo từng dòng mặt hàng' },
                                        { value: 'total', label: 'Chiết khấu trên tổng hóa đơn' },
                                    ]}
                                />
                            </div>
                        </div>

                        {/* Grid Content: Tab Hàng tiền */}
                        {activeGridTab === 'accounting' && (
                        <div className="misa-table-container">
                            <Form.List name="lines">
                                {(fields, { remove }) => (
                                    <div className="misa-grid-wrapper">
                                        <div className="misa-grid-scroll-box">
                                            <table className="misa-grid-table" style={{ minWidth: showAccounts ? 1400 : 1000 }}>
                                                <thead>
                                                    <tr>
                                                        <th style={{ width: 40 }}>#</th>
                                                        <th style={{ width: 180 }}>Mã hàng</th>
                                                        <th style={{ width: 220 }}>Tên hàng</th>
                                                        <th style={{ width: 75 }} className="text-center">ĐVT</th>
                                                        <th style={{ width: 85 }} className="text-right">Số lượng</th>
                                                        <th style={{ width: 110 }} className="text-right">Đơn giá</th>
                                                        <th style={{ width: 125 }} className="text-right">Thành tiền</th>
                                                        <th style={{ width: 140 }}>Nhóm ngành nghề</th>
                                                        {showAccounts && (
                                                            <>
                                                                <th style={{ width: 95 }}>TK Nợ</th>
                                                                <th style={{ width: 95 }}>TK Có</th>
                                                            </>
                                                        )}
                                                        {discountMode === 'line' && (
                                                            <>
                                                                <th style={{ width: 75 }} className="text-right">% CK</th>
                                                                <th style={{ width: 105 }} className="text-right">Tiền CK</th>
                                                            </>
                                                        )}
                                                        {showAccounts && (
                                                            <>
                                                                <th style={{ width: 80 }} className="text-center">% VAT</th>
                                                                <th style={{ width: 110 }} className="text-right">Tiền VAT</th>
                                                            </>
                                                        )}
                                                        {!isViewMode && <th style={{ width: 45 }}></th>}
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {fields.map((field, index) => {
                                                        const line = formLines[index] || {};
                                                        const qty = line.quantity == null ? null : Number(line.quantity);
                                                        const price = line.unit_price == null ? null : Number(line.unit_price);
                                                        const amt = qty == null || price == null ? null : qty * price;

                                                        return (
                                                            <tr key={field.key}>
                                                                <td className="text-center">{index + 1}</td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'item_id']} noStyle>
                                                                        <Select
                                                                            showSearch
                                                                            placeholder="Chọn mã hàng..."
                                                                            className="misa-grid-select misa-w-full"
                                                                            disabled={isViewMode}
                                                                            value={formLines[index]?.item_id}
                                                                            optionLabelProp="label"
                                                                            popupMatchSelectWidth={false}
                                                                            popupClassName="misa-multicolumn-item-popup"
                                                                            dropdownStyle={{ minWidth: 680, width: 680 }}
                                                                            filterOption={(input, option: any) => {
                                                                                const code = String(option?.itemCode || option?.label || '').toLowerCase();
                                                                                const name = String(option?.itemName || '').toLowerCase();
                                                                                const q = input.toLowerCase();
                                                                                return code.includes(q) || name.includes(q);
                                                                            }}
                                                                            options={items?.map((it: any) => ({
                                                                                value: it.id,
                                                                                label: it.code,
                                                                                itemCode: it.code,
                                                                                itemName: it.name,
                                                                                itemStock: it.stock_quantity,
                                                                                itemPrice: it.sale_price ?? it.cost_price,
                                                                            }))}
                                                                            optionRender={option => (
                                                                                <div className="misa-cell-dropdown-grid-4col">
                                                                                    <span className="misa-text-semibold">{option.data.itemCode}</span>
                                                                                    <span className="misa-text-truncate">{option.data.itemName}</span>
                                                                                    <span className="misa-text-right misa-text-blue">{option.data.itemStock ?? '—'}</span>
                                                                                    <span className="misa-text-right">
                                                                                        {option.data.itemPrice === undefined
                                                                                            ? '—'
                                                                                            : new Intl.NumberFormat('vi-VN').format(option.data.itemPrice)}
                                                                                    </span>
                                                                                </div>
                                                                            )}
                                                                            dropdownRender={menu => (
                                                                                <div>
                                                                                    <div className="misa-grid-dropdown-header misa-cell-dropdown-grid-4col">
                                                                                        <span>Mã hàng</span>
                                                                                        <span>Tên hàng</span>
                                                                                        <span className="misa-text-right">Số lượng tồn</span>
                                                                                        <span className="misa-text-right">Đơn giá bán</span>
                                                                                    </div>
                                                                                    {menu}
                                                                                    {!isViewMode && (
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
                                                                                    )}
                                                                                </div>
                                                                            )}
                                                                            onChange={(val: any) => handleItemChange(index, val)}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'description']} noStyle>
                                                                        <Input className="misa-grid-input" placeholder="Tên mặt hàng" disabled={isViewMode} />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'unit']} noStyle>
                                                                        <Input className="misa-grid-input text-center" placeholder="Đơn vị" disabled={isViewMode} />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'quantity']} noStyle>
                                                                        <InputNumber
                                                                            className="misa-grid-input text-right"
                                                                            min={1}
                                                                            disabled={isViewMode}
                                                                            onChange={(val: any) => {
                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                const p = curLines[index]?.unit_price == null ? undefined : Number(curLines[index].unit_price);
                                                                                const q = val == null ? undefined : Number(val);
                                                                                const taxR = curLines[index]?.tax_rate;
                                                                                const costP = curLines[index]?.cogs_unit_price == null ? undefined : Number(curLines[index].cogs_unit_price);
                                                                                curLines[index] = {
                                                                                    ...curLines[index],
                                                                                    quantity: q,
                                                                                    amount: p !== undefined && q !== undefined ? p * q : undefined,
                                                                                    tax_amount: p !== undefined && q !== undefined && taxR !== undefined ? (p * q) * taxR / 100 : undefined,
                                                                                    cogs_amount: costP !== undefined && q !== undefined ? costP * q : undefined
                                                                                };
                                                                                form.setFieldsValue({ lines: [...curLines] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'unit_price']} noStyle>
                                                                        <InputNumber
                                                                            className="misa-grid-input text-right"
                                                                            min={0}
                                                                            disabled={isViewMode}
                                                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                            parser={(v) => (v ? Number(v.replace(/\$\s?|(,*)/g, '')) : 0) as any}
                                                                            onChange={(val: any) => {
                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                const q = curLines[index]?.quantity == null ? undefined : Number(curLines[index].quantity);
                                                                                const p = val == null ? undefined : Number(val);
                                                                                const taxR = curLines[index]?.tax_rate;
                                                                                const costP = curLines[index]?.cogs_unit_price == null ? undefined : Number(curLines[index].cogs_unit_price);
                                                                                curLines[index] = {
                                                                                    ...curLines[index],
                                                                                    unit_price: p,
                                                                                    amount: p !== undefined && q !== undefined ? p * q : undefined,
                                                                                    tax_amount: p !== undefined && q !== undefined && taxR !== undefined ? (p * q) * taxR / 100 : undefined,
                                                                                    cogs_unit_price: costP,
                                                                                    cogs_amount: costP !== undefined && q !== undefined ? costP * q : undefined
                                                                                };
                                                                                form.setFieldsValue({ lines: [...curLines] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="text-right font-semibold">
                                                                    {amt == null ? '—' : new Intl.NumberFormat('vi-VN').format(amt)}
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'business_group']} noStyle initialValue="Bán lẻ hàng hóa">
                                                                        <Select
                                                                            className="misa-grid-select misa-w-full"
                                                                            disabled={isViewMode}
                                                                            options={[
                                                                                { value: 'Bán lẻ hàng hóa', label: 'Bán lẻ hàng hóa' },
                                                                                { value: 'Bán buôn hàng hóa', label: 'Bán buôn hàng hóa' },
                                                                                { value: 'Dịch vụ', label: 'Dịch vụ' },
                                                                                { value: 'Sản xuất', label: 'Sản xuất' },
                                                                                { value: 'Xây dựng', label: 'Xây dựng' },
                                                                            ]}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                {showAccounts && (
                                                                    <>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'debit_account']} noStyle>
                                                                                <AccountSelect
                                                                                    accounts={accounts}
                                                                                    disabled={isViewMode}
                                                                                    placeholder="TK Nợ"
                                                                                    value={formLines[index]?.debit_account}
                                                                                    onChange={(val: string) => {
                                                                                        const curLines = form.getFieldValue('lines') || [];
                                                                                        curLines[index] = { ...curLines[index], debit_account: val };
                                                                                        form.setFieldsValue({ lines: [...curLines] });
                                                                                    }}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'credit_account']} noStyle>
                                                                                <AccountSelect
                                                                                    accounts={accounts}
                                                                                    disabled={isViewMode}
                                                                                    placeholder="TK Có"
                                                                                    value={formLines[index]?.credit_account}
                                                                                    onChange={(val: string) => {
                                                                                        const curLines = form.getFieldValue('lines') || [];
                                                                                        curLines[index] = { ...curLines[index], credit_account: val };
                                                                                        form.setFieldsValue({ lines: [...curLines] });
                                                                                    }}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                    </>
                                                                )}
                                                                {discountMode === 'line' && (
                                                                    <>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'discount_rate']} noStyle>
                                                                                <InputNumber
                                                                                    className="misa-grid-input text-right"
                                                                                    min={0}
                                                                                    max={100}
                                                                                    disabled={isViewMode}
                                                                                    onChange={(rate) => {
                                                                                        const curLines = form.getFieldValue('lines') || [];
                                                                                        const lineAmt = curLines[index]?.amount || 0;
                                                                                        const discAmt = rate != null ? Math.round(lineAmt * Number(rate) / 100) : 0;
                                                                                        curLines[index] = { ...curLines[index], discount_rate: rate, discount_amount: discAmt };
                                                                                        form.setFieldsValue({ lines: [...curLines] });
                                                                                    }}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'discount_amount']} noStyle>
                                                                                <InputNumber className="misa-grid-input text-right" min={0} disabled={isViewMode} />
                                                                            </Form.Item>
                                                                        </td>
                                                                    </>
                                                                )}
                                                                {showAccounts && (
                                                                    <>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'tax_rate']} noStyle>
                                                                                <Select
                                                                                    className="misa-grid-select"
                                                                                    disabled={isViewMode}
                                                                                    options={[
                                                                                        { value: 0, label: '0%' },
                                                                                        { value: 5, label: '5%' },
                                                                                        { value: 8, label: '8%' },
                                                                                        { value: 10, label: '10%' },
                                                                                        { value: -1, label: 'KCT' }
                                                                                    ]}
                                                                                    onChange={(val: any) => {
                                                                                        const curLines = form.getFieldValue('lines') || [];
                                                                                        const p = curLines[index]?.unit_price == null ? undefined : Number(curLines[index].unit_price);
                                                                                        const q = curLines[index]?.quantity == null ? undefined : Number(curLines[index].quantity);
                                                                                        const tAmt = p !== undefined && q !== undefined && val > 0 ? Math.round(p * q * val / 100) : undefined;
                                                                                        curLines[index] = { ...curLines[index], tax_rate: val, tax_amount: tAmt };
                                                                                        form.setFieldsValue({ lines: [...curLines] });
                                                                                    }}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'tax_amount']} noStyle>
                                                                                <InputNumber className="misa-grid-input text-right" min={0} disabled={isViewMode} />
                                                                            </Form.Item>
                                                                        </td>
                                                                    </>
                                                                )}
                                                                {!isViewMode && (
                                                                    <td className="text-center">
                                                                        <button
                                                                            type="button"
                                                                            className="misa-grid-btn-del"
                                                                            onClick={() => remove(field.name)}
                                                                        >
                                                                            <DeleteOutlined />
                                                                        </button>
                                                                    </td>
                                                                )}
                                                            </tr>
                                                        );
                                                    })}

                                                    {/* Summary Footer Row inside table */}
                                                    <tr style={{ background: '#f8fafc', fontWeight: 600, borderTop: '1px solid #e2e8f0' }}>
                                                        <td colSpan={4} className="text-center" style={{ fontWeight: 600, color: '#334155' }}>
                                                            Tổng cộng
                                                        </td>
                                                        <td className="text-right" style={{ fontWeight: 600, color: '#1e293b' }}>
                                                            {totals.totalQuantity ? new Intl.NumberFormat('vi-VN').format(totals.totalQuantity) : '0'}
                                                        </td>
                                                        <td></td>
                                                        <td className="text-right" style={{ fontWeight: 600, color: '#1e293b' }}>
                                                            {new Intl.NumberFormat('vi-VN').format(totals.subTotal)}
                                                        </td>
                                                        <td></td>
                                                        {showAccounts && (
                                                            <>
                                                                <td></td>
                                                                <td></td>
                                                            </>
                                                        )}
                                                        {discountMode === 'line' && (
                                                            <>
                                                                <td></td>
                                                                <td className="text-right" style={{ fontWeight: 600, color: '#1e293b' }}>
                                                                    {new Intl.NumberFormat('vi-VN').format(totals.totalDiscount)}
                                                                </td>
                                                            </>
                                                        )}
                                                        {showAccounts && (
                                                            <>
                                                                <td></td>
                                                                <td className="text-right" style={{ fontWeight: 600, color: '#1e293b' }}>
                                                                    {new Intl.NumberFormat('vi-VN').format(totals.totalTax)}
                                                                </td>
                                                            </>
                                                        )}
                                                        {!isViewMode && <td></td>}
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        {/* MisaGridActionFooter placed BELOW table */}
                                        <MisaGridActionFooter
                                            onAddLine={handleAddLine}
                                            onAddNote={() => {
                                                const curLines = form.getFieldValue('lines') || [];
                                                form.setFieldValue('lines', [...curLines, { description: 'Ghi chú bán hàng' }]);
                                            }}
                                            onDeleteAll={() => {
                                                form.setFieldValue('lines', []);
                                                message.success('Đã xóa toàn bộ dòng');
                                            }}
                                            lineCount={fields.length}
                                            disabled={isViewMode}
                                        />

                                        {/* Table Summary Bar */}
                                        <MisaTableSummaryBar
                                            leftContent={<span className="apple-muted-text">Tổng số: <strong className="misa-text-heading">{fields.length}</strong> dòng</span>}
                                            items={[
                                                { label: 'Số lượng', value: totals.totalQuantity, format: 'number' },
                                                { label: 'Tổng tiền hàng', value: totals.subTotal, format: 'currency' },
                                                ...(totals.totalDiscount > 0 ? [{ label: 'Tổng chiết khấu', value: totals.totalDiscount, format: 'currency' as const }] : []),
                                                ...(totals.totalTax > 0 ? [{ label: 'Tổng tiền thuế', value: totals.totalTax, format: 'currency' as const }] : []),
                                                { label: 'Tổng tiền thanh toán', value: totals.grandTotal, format: 'currency', highlight: true }
                                            ]}
                                        />
                                    </div>
                                )}
                            </Form.List>
                        </div>
                        )}

                        {/* Grid Content: Tab Giá vốn */}
                        {activeGridTab === 'cogs' && isIncludeDelivery && (
                        <div className="misa-table-container">
                            <Form.List name="lines">
                                {(fields) => (
                                    <div className="misa-grid-wrapper">
                                        <div className="misa-grid-scroll-box">
                                            <table className="misa-grid-table" style={{ minWidth: 1100 }}>
                                                <thead>
                                                    <tr>
                                                        <th style={{ width: 40 }}>#</th>
                                                        <th style={{ width: 180 }}>Mã hàng</th>
                                                        <th style={{ width: 220 }}>Tên hàng</th>
                                                        <th style={{ width: 140 }}>Kho xuất</th>
                                                        <th style={{ width: 100 }}>TK Kho</th>
                                                        <th style={{ width: 100 }}>TK Giá vốn</th>
                                                        <th style={{ width: 75 }} className="text-center">ĐVT</th>
                                                        <th style={{ width: 85 }} className="text-right">Số lượng</th>
                                                        <th style={{ width: 110 }} className="text-right">Đơn giá vốn</th>
                                                        <th style={{ width: 125 }} className="text-right">Tiền giá vốn</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {fields.map((field, index) => {
                                                        const line = formLines[index] || {};
                                                        const qty = line.quantity == null ? null : Number(line.quantity);
                                                        const cPrice = line.cogs_unit_price == null ? null : Number(line.cogs_unit_price);
                                                        const cAmt = line.cogs_amount == null ? null : Number(line.cogs_amount);

                                                        return (
                                                            <tr key={field.key}>
                                                                <td className="text-center">{index + 1}</td>
                                                                <td>{line.item_code || '—'}</td>
                                                                <td>{line.description || '—'}</td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'warehouse_id']} noStyle>
                                                                        <Select
                                                                            className="misa-grid-select misa-w-full"
                                                                            disabled={isViewMode}
                                                                            placeholder="Chọn kho..."
                                                                            options={warehouses?.map((w: any) => ({ value: w.id, label: w.name || w.code }))}
                                                                            onChange={(val: any) => {
                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                const wh = warehouses?.find((w: any) => w.id === val);
                                                                                curLines[index] = { ...curLines[index], warehouse_id: val, warehouse_name: wh?.name };
                                                                                form.setFieldsValue({ lines: [...curLines] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'inventory_account']} noStyle>
                                                                        <AccountSelect
                                                                            accounts={accounts}
                                                                            disabled={isViewMode}
                                                                            placeholder="TK Kho"
                                                                            value={formLines[index]?.inventory_account}
                                                                            onChange={(val: string) => {
                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                curLines[index] = { ...curLines[index], inventory_account: val };
                                                                                form.setFieldsValue({ lines: [...curLines] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'cogs_debit_account']} noStyle>
                                                                        <AccountSelect
                                                                            accounts={accounts}
                                                                            disabled={isViewMode}
                                                                            placeholder="TK Giá vốn"
                                                                            value={formLines[index]?.cogs_debit_account}
                                                                            onChange={(val: string) => {
                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                curLines[index] = { ...curLines[index], cogs_debit_account: val };
                                                                                form.setFieldsValue({ lines: [...curLines] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="text-center">{line.unit || '—'}</td>
                                                                <td className="text-right">{qty == null ? '—' : qty}</td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'cogs_unit_price']} noStyle initialValue={cPrice}>
                                                                        <InputNumber
                                                                            className="misa-grid-input text-right"
                                                                            disabled={isViewMode}
                                                                            onChange={(val) => {
                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                const q = curLines[index]?.quantity == null ? undefined : Number(curLines[index].quantity);
                                                                                const cp = val == null ? undefined : Number(val);
                                                                                curLines[index] = {
                                                                                    ...curLines[index],
                                                                                    cogs_unit_price: cp,
                                                                                    cogs_amount: cp !== undefined && q !== undefined ? cp * q : undefined
                                                                                };
                                                                                form.setFieldsValue({ lines: [...curLines] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="text-right font-semibold">
                                                                    {cAmt == null ? '—' : new Intl.NumberFormat('vi-VN').format(cAmt)}
                                                                </td>
                                                            </tr>
                                                        );
                                                    })}

                                                    {/* Summary row for cogs */}
                                                    <tr style={{ background: '#f8fafc', fontWeight: 600, borderTop: '1px solid #e2e8f0' }}>
                                                        <td colSpan={7} className="text-center" style={{ fontWeight: 600, color: '#334155' }}>
                                                            Tổng cộng
                                                        </td>
                                                        <td className="text-right" style={{ fontWeight: 600, color: '#1e293b' }}>
                                                            {totals.totalQuantity ? new Intl.NumberFormat('vi-VN').format(totals.totalQuantity) : '0'}
                                                        </td>
                                                        <td></td>
                                                        <td className="text-right" style={{ fontWeight: 600, color: '#1e293b' }}>
                                                            {new Intl.NumberFormat('vi-VN').format(
                                                                formLines.reduce((s: number, l: any) => s + (Number(l.cogs_amount) || 0), 0)
                                                            )}
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                )}
                            </Form.List>
                        </div>
                        )}
                    </div>

                </Form>
                </ModalFrame>
            </Modal>

            {/* Quick Add Sub-Modals */}
            <QuickAddContactModal
                open={isCustomerModalVisible}
                onCancel={() => setIsCustomerModalVisible(false)}
                contactType="customer"
                onSuccess={(newCust) => {
                    queryClient.invalidateQueries({ queryKey: ['customers'] });
                    form.setFieldsValue({
                        customer_id: newCust.id,
                        customer_name: newCust.name,
                        customer_address: newCust.address,
                        tax_code: newCust.tax_code,
                        receiver_name: newCust.name
                    });
                }}
            />

            <QuickAddEmployeeModal
                open={isEmployeeModalVisible}
                onCancel={() => setIsEmployeeModalVisible(false)}
                onSuccess={(newEmp) => {
                    queryClient.invalidateQueries({ queryKey: ['employees'] });
                    form.setFieldsValue({ employee_id: newEmp.id });
                }}
            />

            <QuickAddPaymentTermModal
                open={isPaymentTermModalVisible}
                onCancel={() => setIsPaymentTermModalVisible(false)}
                onSuccess={(newTerm: any) => {
                    void queryClient.invalidateQueries({ queryKey: ['payment-terms'] });
                    handlePaymentTermChange(newTerm.code || newTerm.name);
                }}
            />

            <QuickAddItemModal
                open={isItemModalVisible}
                onCancel={() => {
                    setIsItemModalVisible(false);
                    setActiveRowIndex(null);
                }}
                onSuccess={(newItem: any) => {
                    setIsItemModalVisible(false);
                    queryClient.invalidateQueries({ queryKey: ['inventory-items'] });
                    if (activeRowIndex !== null && newItem?.id) {
                        handleItemChange(activeRowIndex, newItem.id, newItem);
                    }
                    setActiveRowIndex(null);
                    message.success(`Đã thêm nhanh vật tư hàng hóa: ${newItem.name || newItem.code}`);
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
                                customer_id: first.contact_id || form.getFieldValue('customer_id'),
                                customer_name: first.contact_name || form.getFieldValue('customer_name'),
                                receiver_name: first.contact_name || form.getFieldValue('receiver_name'),
                                description: `Bán hàng theo ${selected.map(s => s.voucher_number).join(', ')}`
                            });
                        }
                        message.success(`Đã nạp ${selected.length} chứng từ tham chiếu!`);
                    }
                }}
            />

            {/* MISA Standard Print Modal (Mẫu 01-BH / Mẫu 02-VT) */}
            <VoucherPrintModal
                open={isPrintModalOpen}
                type={printType}
                data={printData}
                onCancel={() => setIsPrintModalOpen(false)}
            />

            <SalesInvoiceDimensionAssignments
                invoice={dimensionInvoice}
                open={isDimensionAssignmentsOpen}
                onClose={() => setIsDimensionAssignmentsOpen(false)}
            />
            <SalesInvoiceApprovalWorkflow
                invoice={approvalInvoice}
                open={isApprovalWorkflowOpen}
                onClose={() => setIsApprovalWorkflowOpen(false)}
            />
            <CollectByInvoiceModal
                open={isCollectByInvoiceOpen}
                initialInvoice={collectionInvoice}
                onClose={() => { setIsCollectByInvoiceOpen(false); setCollectionInvoice(null); }}
                onSuccess={() => { void queryClient.invalidateQueries({ queryKey: ['sales-invoices'] }); }}
            />
                        <CustomerDebtModal
                open={isCustomerDebtModalOpen}
                customer={customers.find((c: any) => c.id === form.getFieldValue('customer_id'))}
                invoices={invoices}
                onClose={() => setIsCustomerDebtModalOpen(false)}
            />
</PageShell>
    );
};

export default SalesInvoices;
