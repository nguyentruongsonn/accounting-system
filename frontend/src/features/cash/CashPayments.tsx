import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Table, Button, ConfigProvider, Form, Input, InputNumber, Select, DatePicker, Switch, Dropdown, Tag, Alert } from 'antd';
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
    QrcodeOutlined,
    CloseOutlined,
    LinkOutlined,
    CopyOutlined,
    CheckCircleOutlined,
    CloseCircleOutlined,
    PrinterOutlined,
    PaperClipOutlined,
    UploadOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { formatDate } from '../../utils/dateUtils';
import { 
    MisaMasterCard,
    MisaTotalCard,
    QuickAddContactModal, 
    QuickAddEmployeeModal, 
    ReferenceVoucherModal, 
    MultiColumnContactSelect,
    useVoucherShortcuts,
    useVoucherTotals,
    QuickAddReasonModal,
    AccountSelect,
    VoucherPrintModal,
    CollectByInvoiceModal,
    CollectMultiCustomerModal,
    PayByInvoiceModal,
    ExcelImportModal
} from '../../components/misa';
import { readMoneyToVietnameseWords } from '../../utils/numberToWords';
import { getApiErrorMessage } from '../../utils/apiErrorMessage';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import { cashVoucherStatusLabel, cashVoucherStatusTone, isVoidedCashVoucher } from './cashVoucherStatus';
import { isLegacyOutOfScopeCashPaymentRecord, isOutOfScopeCashPaymentType } from './cashVoucherScope';

interface VoucherTypeOption {
    value: string;
    label: string;
    debit?: string;
    credit?: string;
    reason?: string;
    operation?: string;
    voucher_type?: string;
}

interface PaymentLine {
    key?: string;
    description?: string;
    debit_account?: string;
    credit_account?: string;
    amount?: number | null;
    operation?: string;
    loan_contract?: string;
    line_contact_id?: string;
    line_contact_name?: string;
    invoice_id?: number | null;
}

interface PaymentRecord {
    id: number;
    voucher_number: string;
    voucher_date: string;
    posting_date: string;
    contact_name: string;
    contact_id?: string;
    contact_code?: string;
    receiver_name?: string;
    receiver_address?: string;
    reason: string;
    currency?: string;
    exchange_rate?: number;
    total_amount: number | null;
    is_posted: boolean;
    status?: string;
    journal_entry_id?: number;
    lines?: PaymentLine[];
    referenced_vouchers?: any[];
    references?: any[];
    attached_docs?: string;
    voucher_type?: string;
}

const paymentVoucherTypes: VoucherTypeOption[] = [
    { value: '1. Trả tiền cho nhà cung cấp (không theo hóa đơn)', label: '1. Trả tiền cho nhà cung cấp (không theo hóa đơn)', reason: 'Trả tiền cho nhà cung cấp ', operation: 'Trả tiền NCC' },
    { value: '1. Trả tiền nhà cung cấp (không theo hóa đơn)', label: '1. Trả tiền nhà cung cấp (không theo hóa đơn)', reason: 'Trả tiền nhà cung cấp ', operation: 'Trả tiền NCC' },
    { value: '2. Tạm ứng cho nhân viên', label: '2. Tạm ứng cho nhân viên', reason: 'Tạm ứng cho nhân viên ', operation: 'Tạm ứng nhân viên' },
    { value: '3. Chi mua ngoài có hóa đơn', label: '3. Chi mua ngoài có hóa đơn', reason: 'Chi mua ngoài ', operation: 'Chi mua ngoài' },
    { value: '4. Trả lương tạm ứng cho nhân viên', label: '4. Trả lương tạm ứng cho nhân viên', reason: 'Trả lương tạm ứng cho nhân viên ', operation: 'Trả lương' },
    { value: '5. Trả lương cho nhân viên', label: '5. Trả lương cho nhân viên', reason: 'Trả lương cho nhân viên ', operation: 'Trả lương nhân viên' },
    { value: '7. Chi cho vay', label: '7. Chi cho vay', reason: 'Chi cho vay ', operation: 'Chi cho vay' },
    { value: '8. Chi khác', label: '8. Chi khác', reason: 'Chi tiền cho ', operation: 'Chi khác' },
    { value: 'Nộp bảo hiểm', label: 'Nộp bảo hiểm & KPCĐ', reason: 'Nộp bảo hiểm', operation: 'Nộp bảo hiểm' },
];

const isDepositVoucherType = (value: unknown): boolean => {
    const normalized = String(value ?? '').toLowerCase();
    return normalized.includes('gửi tiền vào ngân hàng') || normalized.includes('gửi tiền vào nh');
};

const isLegacyDepositPaymentRecord = (record: Partial<PaymentRecord> | null | undefined): boolean => (
    isDepositVoucherType(record?.voucher_type)
    || String(record?.reason ?? '').toLowerCase().includes('gửi tiền vào ngân hàng')
    || String(record?.lines?.[0]?.debit_account ?? '').startsWith('112')
);

function parseCashPaymentCollection(value: unknown): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error('Invalid cash payments response');
}

function parseCashPaymentCatalogue(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

function formatCashPaymentAmount(value: unknown): string {
    if (value === null || value === undefined || value === '') return '—';
    const amount = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(amount)
        ? new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(amount)
        : '—';
}

function readCashPaymentAmount(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value);
    return null;
}

interface CashPaymentsProps {
    modalOnly?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}

export const CashPayments: React.FC<CashPaymentsProps> = React.memo(({ modalOnly = false, open: controlledOpen, onOpenChange }) => {

    const [searchParams, setSearchParams] = useSearchParams();
    const [editingPaymentId, setEditingPaymentId] = useState<number | null>(null);
    const [modalMode, setModalMode] = useState<'create' | 'edit' | 'view'>('create');
    const [customPaymentVoucherTypes, setCustomPaymentVoucherTypes] = useState<any[]>(paymentVoucherTypes);
    const [isReasonModalVisible, setIsReasonModalVisible] = useState(false);
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [isPrintModalVisible, setIsPrintModalVisible] = useState(false);
    const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [isRefModalVisible, setIsRefModalVisible] = useState(false);
    const [referencedVouchers, setReferencedVouchers] = useState<any[]>([]);

    // Extension Modals for "Chi tiền" & "Thu tiền" Dropdown Menus
    const [isCollectByInvoiceOpen, setIsCollectByInvoiceOpen] = useState(false);
    const [isCollectMultiCustomerOpen, setIsCollectMultiCustomerOpen] = useState(false);
    const [isPayByInvoiceOpen, setIsPayByInvoiceOpen] = useState(false);
    const [isExcelImportOpen, setIsExcelImportOpen] = useState(false);
    const [excelImportType, setExcelImportType] = useState<'receipt' | 'payment'>('payment');

    const [datePreset, setDatePreset] = useState('Tháng này');
    const [dateRange, setDateRange] = useState<any>([dayjs().startOf('month'), dayjs().endOf('month')]);
    const [showAccounts, setShowAccounts] = useState(true);
    const [pageSize, setPageSize] = useState(20);
    const [currentPage, setCurrentPage] = useState(1);
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const lines: PaymentLine[] = Form.useWatch('lines', form) || [];
    const totals = useVoucherTotals(lines);
    const [voucherType, setVoucherType] = useState('8. Chi khác');
    const voucherNumber = Form.useWatch('voucher_number', form);

    const { data: payments = [], isLoading, isError: isPaymentsError, refetch: refetchPayments } = useQuery({
        queryKey: ['cash-payments'],
        queryFn: async () => {
            const { data } = await api.get('/cash/payments');
            return parseCashPaymentCollection(data);
        },
        enabled: !modalOnly,
    });

    const { data: voucherSettingsData = [] } = useQuery({
        queryKey: ['voucher-type-settings', 'chi_tien_mat'],
        queryFn: async () => {
            const { data } = await api.get('/master/voucher-type-settings?voucher_type=chi_tien_mat');
            return parseCashPaymentCatalogue(data, 'cash voucher settings');
        },
        staleTime: 10 * 60 * 1000,
    });

    const activePaymentVoucherTypes = useMemo(() => {
        const base = [...paymentVoucherTypes];
        voucherSettingsData.forEach((s: any) => {
            const rawName = s.name;
            if (!rawName) return;

            const exists = base.some(b => 
                b.value === rawName || 
                b.label === rawName ||
                b.value.replace(/^\d+\.\s*/, '') === rawName.replace(/^\d+\.\s*/, '')
            );

            if (!exists) {
                const nextIdx = base.length + 1;
                const formattedName = /^\d+\.\s*/.test(rawName) ? rawName : `${nextIdx}. ${rawName}`;
                base.push({
                    value: formattedName,
                    label: formattedName,
                    debit: s.debit_account,
                    credit: s.credit_account,
                    reason: rawName,
                    operation: rawName,
                    voucher_type: s.voucher_type,
                });
            }
        });

        customPaymentVoucherTypes.forEach((c: any) => {
            const rawVal = c.value || c.name || c.label;
            if (!rawVal) return;

            const exists = base.some(b => 
                b.value === rawVal || 
                b.label === rawVal ||
                b.value.replace(/^\d+\.\s*/, '') === String(rawVal).replace(/^\d+\.\s*/, '')
            );

            if (!exists) {
                const nextIdx = base.length + 1;
                const formattedName = /^\d+\.\s*/.test(rawVal) ? rawVal : `${nextIdx}. ${rawVal}`;
                base.push({
                    ...c,
                    value: formattedName,
                    label: formattedName,
                });
            }
        });
        // Tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ hiện tại. Keep legacy
        // records readable, but never offer a deposit voucher as a new
        // cash-payment choice, even when a server setting still advertises it.
        return base.filter((item) => !isDepositVoucherType(item.value) && !isDepositVoucherType(item.label));
    }, [voucherSettingsData, customPaymentVoucherTypes]);

    // Payroll and insurance remain available for viewing legacy vouchers, but
    // cannot be selected when creating a new internal cash payment.
    const selectablePaymentVoucherTypes = useMemo(() => {
        const scoped = activePaymentVoucherTypes.filter((item) => !isOutOfScopeCashPaymentType(item.value));
        if (modalMode !== 'view') return scoped;

        const current = activePaymentVoucherTypes.find((item) => item.value === voucherType || item.label === voucherType);
        if (current && !scoped.some((item) => item.value === current.value)) {
            return [...scoped, current];
        }
        return scoped;
    }, [activePaymentVoucherTypes, modalMode, voucherType]);

    const { data: chartOfAccounts = [] } = useQuery({
        queryKey: ['chart-of-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return parseCashPaymentCatalogue(data, 'chart-of-accounts catalogue');
        },
        staleTime: 15 * 60 * 1000,
    });

    // Deposit/bank workflows are outside the internal cash-only pilot. Legacy
    // records remain viewable, but no bank catalogue is loaded or offered.
    const bankAccounts: any[] = [];

    const { data: suppliers = [] } = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parseCashPaymentCatalogue(data, 'supplier catalogue');
        },
        staleTime: 10 * 60 * 1000,
    });

    const { data: employees = [] } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parseCashPaymentCatalogue(data, 'employee catalogue');
        },
        staleTime: 10 * 60 * 1000,
    });

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const payload = {
                voucher_type: values.voucher_type || voucherType,
                contact_type: 'supplier',
                contact_id: values.contact_id,
                contact_name: suppliers?.find((c: any) => c.id === values.contact_id)?.name || values.contact_name || values.receiver_name,
                receiver_name: values.receiver_name,
                receiver_address: values.receiver_address,
                employee_id: values.employee_id,
                employee_name: employees?.find((e: any) => e.id === values.employee_id)?.name,
                reason: values.reason,
                referenced_vouchers: referencedVouchers,
                attached_docs: values.attached_docs,
                currency: values.currency || 'VND',
                exchange_rate: values.exchange_rate || 1,
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                posting_date: values.posting_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                lines: (values.lines || []).filter(Boolean).map((line: any) => ({
                    description: line.description || values.reason,
                    debit_account: line.debit_account,
                    credit_account: line.credit_account,
                    amount: readCashPaymentAmount(line.amount),
                    operation: line.operation,
                    line_contact_id: line.line_contact_id,
                    line_contact_name: line.line_contact_name
                }))
            };
            const res = editingPaymentId 
                ? await api.put(`/cash/payments/${editingPaymentId}`, payload)
                : await api.post('/cash/payments', payload);
            return { res, andNew: values.andNew, andPrint: values.andPrint };
        },
        onSuccess: (data: any) => {
            const persistedPayment = data?.res?.data?.data ?? data?.res?.data;
            if (!persistedPayment || !Number.isInteger(Number(persistedPayment.id))) {
                message.error('Máy chủ không trả về phiếu chi đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success(editingPaymentId ? 'Cập nhật Phiếu chi thành công!' : 'Tạo Phiếu chi thành công!');
            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });

            if (data?.andPrint) {
                setIsPrintModalVisible(true);
            }

            if (data?.andNew) {
                setEditingPaymentId(null);
                setModalMode('create');
                form.resetFields();
                api.get('/cash/payments/next-code').then(res => {
                    const code = res.data?.code;
                    form.setFieldsValue({
                        ...(code ? { voucher_number: code } : {}),
                        posting_date: dayjs(),
                        voucher_date: dayjs(),
                        reason: 'Chi tiền nhà cung cấp',
                        lines: [
                            { key: '1', description: 'Chi tiền nhà cung cấp', amount: undefined }
                        ]
                    });
                }).catch((error) => {
                    message.warning(getApiErrorMessage(error, 'Không thể cấp số phiếu chi tự động; hãy nhập số chứng từ hoặc thử lại.'));
                });
            } else if (!data?.andPrint) {
                setIsModalVisible(false);
            }
        },
        onError: (err: any) => {
            message.error(getApiErrorMessage(err, 'Có lỗi xảy ra khi lưu phiếu chi!'));
        }
    });

    const handleSaveForm = (andNew = false, andPrint = false) => {
        form.validateFields().then(values => {
            if (!values.contact_id && !values.contact_name && !values.receiver_name) {
                message.error('Vui lòng chọn hoặc nhập đối tượng nhận tiền!');
                return;
            }
            const currentLines = (values.lines || []).filter(Boolean);
            const requestedVoucherType = String(values.voucher_type || voucherType || '');
            const usesDepositFlow = isDepositVoucherType(requestedVoucherType)
                || currentLines.some((line: any) => isDepositVoucherType(line?.operation));
            if (usesDepositFlow) {
                message.info('Nghiệp vụ tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ; chứng từ cũ chỉ được xem.');
                return;
            }
            if (currentLines.length === 0) {
                message.error('Vui lòng nhập ít nhất 1 dòng định khoản hạch toán!');
                return;
            }
            for (let i = 0; i < currentLines.length; i++) {
                if (!currentLines[i]?.amount || currentLines[i]?.amount <= 0) {
                    message.error(`Dòng ${i + 1}: Số tiền phải lớn hơn 0!`);
                    return;
                }
                if (!currentLines[i]?.debit_account || !currentLines[i]?.credit_account) {
                    message.error(`Dòng ${i + 1}: Vui lòng chọn đầy đủ Tài khoản Nợ và Tài khoản Có!`);
                    return;
                }
            }
            mutation.mutate({ ...values, lines: currentLines, andNew, andPrint });
        }).catch(() => {
            message.error('Vui lòng kiểm tra lại các trường thông tin bắt buộc (màu đỏ)!');
        });
    };

    const handleAddLine = () => {
        const found = paymentVoucherTypes.find(t => t.value === voucherType);
        const currentLines = form.getFieldValue('lines') || [];
        form.setFieldsValue({
            lines: [
                ...currentLines,
                {
                    key: `${Date.now()}`,
                    description: form.getFieldValue('reason') || 'Chi tiền cho ',
                    debit_account: found?.debit || '',
                    amount: undefined,
                    operation: found?.operation || 'Chi khác',
                    line_contact_id: form.getFieldValue('contact_name') || '',
                    line_contact_name: form.getFieldValue('contact_name') || ''
                }
            ]
        });
    };

    const postMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/cash/payments/${id}/post`);
        },
        onSuccess: (response: any) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || (evidence.id === undefined || evidence.id === null)) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã ghi sổ phiếu chi.');
                return;
            }
            message.success('Ghi sổ thành công');
            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
        },
        onError: (err: unknown) => {
            message.error(getApiErrorMessage(err, 'Không thể ghi sổ phiếu chi.'));
        },
    });

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/cash/payments/${id}/unpost`);
        },
        onSuccess: (response: any) => {
            const evidence = response?.data?.data ?? response?.data;
            if ((!evidence || (evidence.id === undefined || evidence.id === null)) && typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã bỏ ghi sổ phiếu chi.');
                return;
            }
            message.success('Bỏ ghi sổ thành công!');
            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
        },
        onError: (err: unknown) => {
            message.error(getApiErrorMessage(err, 'Không thể bỏ ghi sổ phiếu chi!'));
        }
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/cash/payments/${id}/duplicate`);
        },
        onSuccess: (res: any) => {
            const newDoc = res?.data?.data ?? res?.data;
            if (!newDoc || !Number.isInteger(Number(newDoc.id))) {
                message.error('Máy chủ không trả về phiếu chi nhân bản đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Nhân bản chứng từ thành công!');
            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
            handleEditPayment(newDoc);
        },
        onError: (err: unknown) => {
            message.error(getApiErrorMessage(err, 'Không thể nhân bản phiếu chi!'));
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.delete(`/cash/payments/${id}`);
        },
        onSuccess: (response: any) => {
            if (typeof response?.data?.message !== 'string' && response?.data?.success !== true) {
                message.error('Máy chủ không xác nhận đã xóa phiếu chi.');
                return;
            }
            message.success('Xóa phiếu chi thành công');
            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
        },
        onError: (err: unknown) => {
            message.error(getApiErrorMessage(err, 'Không thể xóa phiếu chi!'));
        }
    });

    // MISA standard voucher shortcuts
    useVoucherShortcuts({
        onSave: () => {
            if (modalMode !== 'view') handleSaveForm(false, false);
        },
        onSaveAndNew: () => {
            if (modalMode !== 'view') handleSaveForm(true, false);
        },
        onPrint: () => {
            setIsPrintModalVisible(true);
        },
        onAddLine: () => {
            if (modalMode !== 'view') handleAddLine();
        },
        onDeleteLine: () => {
            if (modalMode !== 'view') {
                const cur = form.getFieldValue('lines') || [];
                if (cur.length > 1) {
                    form.setFieldValue('lines', cur.slice(0, -1));
                }
            }
        },
        onPost: () => {
            if (editingPaymentId) postMutation.mutate(editingPaymentId);
        },
        onUnpost: () => {
            if (editingPaymentId) unpostMutation.mutate(editingPaymentId);
        },
        onEdit: () => {
            if (modalMode === 'view' && editingPaymentId) {
                const cur = payments.find((p: PaymentRecord) => p.id === editingPaymentId);
                if (cur) handleEditPayment(cur);
            }
        },
        onDelete: () => {
            if (editingPaymentId) {
                Modal.confirm({
                    title: 'Xác nhận xóa',
                    content: 'Bạn có chắc chắn muốn xóa phiếu chi này không?',
                    okText: 'Xóa',
                    okType: 'danger',
                    cancelText: 'Hủy',
                    onOk: () => {
                        deleteMutation.mutate(editingPaymentId);
                        setIsModalVisible(false);
                    }
                });
            }
        },
        onClose: () => {
            if (!isSupplierModalVisible && !isEmployeeModalVisible && !isRefModalVisible && !isPrintModalVisible) {
                setIsModalVisible(false);
            }
        },
        enabled: isModalVisible
    });

    const handleVoucherTypeChange = (type: string) => {
        setVoucherType(type);
        const norm = (s: string) => String(s || '').replace(/^\d+\.\s*/, '').replace(/\s+/g, ' ').replace(/cho\s+/, '').trim().toLowerCase();
        const typeNorm = norm(type);
        const found = activePaymentVoucherTypes.find(t => 
            t.value === type || 
            t.label === type || 
            t.value.replace(/^\d+\.\s*/, '') === String(type).replace(/^\d+\.\s*/, '') ||
            norm(t.value || '') === typeNorm
        );
        const cleanName = String(found?.reason || found?.label || found?.value || type || '').replace(/^\d+\.\s*/, '');
        const defaultReason = found?.reason || (type ? `Chi tiền - ${cleanName}` : 'Chi tiền');
        const defaultOp = found?.operation || cleanName || 'Chi khác';
        form.setFieldsValue({
            voucher_type: type,
            reason: defaultReason,
            lines: [{
                key: '1',
                description: defaultReason,
                amount: undefined,
                operation: defaultOp,
                line_contact_id: form.getFieldValue('contact_id') || '',
                line_contact_name: form.getFieldValue('contact_name') || ''
            }]
        });
    };

    const handleViewPayment = async (record: any, targetMode: 'view' | 'edit' = 'view') => {
        setEditingPaymentId(record.id);
        // Deposit/bank vouchers are outside the internal cash-only pilot. Keep
        // legacy records readable, but never open them in an editable mode.
        const legacyDepositRecord = isLegacyDepositPaymentRecord(record);
        const legacyOutOfScopeRecord = isLegacyOutOfScopeCashPaymentRecord(record);
        setModalMode(legacyDepositRecord || legacyOutOfScopeRecord ? 'view' : targetMode);
        let payment: any;
        try {
            const { data } = await api.get(`/cash/payments/${record.id}`);
            payment = data?.data || data;
            if (!payment || typeof payment !== 'object' || !Number.isInteger(Number(payment.id))) {
                throw new Error('Máy chủ không trả về chi tiết phiếu chi hợp lệ.');
            }
        } catch (e) {
            setEditingPaymentId(null);
            message.error(getApiErrorMessage(e, 'Không thể tải chi tiết phiếu chi. Hãy thử lại.'));
            return;
        }
            
        // Detect voucher_type smartly if missing or resolve to numbered format
        const rawType = payment.voucher_type;
        const matched = activePaymentVoucherTypes.find(t => 
            t.value === rawType || 
            t.label === rawType || 
            t.value.replace(/^\d+\.\s*/, '') === String(rawType).replace(/^\d+\.\s*/, '')
        );
        const resolvedType = matched?.value || rawType || (() => {
            const debitAcc = payment.lines?.[0]?.debit_account || '';
            if (debitAcc.startsWith('3341')) return '4. Trả lương tạm ứng cho nhân viên';
            if (debitAcc.startsWith('334')) return '5. Trả lương nhân viên';
            if (debitAcc.startsWith('112')) return '6. Gửi tiền vào ngân hàng';
            if (debitAcc.startsWith('128')) return '7. Chi cho vay';
            if (debitAcc.startsWith('333')) return '8. Chi khác';
            if (debitAcc.startsWith('331')) return '1. Trả tiền nhà cung cấp (không theo hóa đơn)';
            return '8. Chi khác';
        })();

        if (isDepositVoucherType(resolvedType)) {
            setModalMode('view');
        }
        if (isOutOfScopeCashPaymentType(resolvedType)) {
            setModalMode('view');
        }

        // Populate form
        setVoucherType(resolvedType);
        if (resolvedType && !activePaymentVoucherTypes.some(t => t.value === resolvedType)) {
            setCustomPaymentVoucherTypes(prev => [...prev, { value: resolvedType, label: resolvedType }]);
        }

        setReferencedVouchers(payment.references || payment.referenced_vouchers || []);
        const resolvedEmpId = employees?.find((e: any) => String(e.id) === String(payment.employee_id) || e.code === payment.employee_id)?.id 
            ?? (payment.employee_id ? (Number(payment.employee_id) || payment.employee_id) : undefined);
        
        form.setFieldsValue({
            voucher_type: resolvedType,
            voucher_number: payment.voucher_number,
            voucher_date: dayjs(payment.voucher_date || new Date()),
            posting_date: dayjs(payment.posting_date || payment.voucher_date || new Date()),
            currency: payment.currency || 'VND',
            exchange_rate: payment.exchange_rate || 1,
            contact_id: payment.contact_id || payment.contact_code,
            contact_name: payment.contact_name,
            receiver_name: payment.receiver_name || payment.contact_name,
            receiver_address: payment.receiver_address,
            employee_id: resolvedEmpId,
            reason: payment.reason,
            attached_docs: payment.attached_docs,
            lines: (payment.lines && payment.lines.length > 0) ? payment.lines.map((l: any, idx: number) => ({
                key: String(l.id || l.key || idx),
                description: l.description || payment.reason,
                debit_account: l.debit_account,
                credit_account: l.credit_account,
                amount: readCashPaymentAmount(l.amount),
                operation: l.operation,
                loan_contract: l.loan_contract,
                line_contact_id: l.line_contact_id || l.contact_code || payment.contact_id,
                line_contact_name: l.line_contact_name || payment.contact_name
            })) : []
        });
        setIsModalVisible(true);
    };

    const handleEditPayment = async (record: PaymentRecord) => {
        if (isLegacyDepositPaymentRecord(record)) {
            message.info('Chứng từ tiền gửi ngân hàng chỉ được xem trong phạm vi nội bộ hiện tại.');
            await handleViewPayment(record, 'view');
            return;
        }
        if (isLegacyOutOfScopeCashPaymentRecord(record)) {
            message.info('Chứng từ lương/bảo hiểm chỉ được xem trong phạm vi nội bộ hiện tại.');
            await handleViewPayment(record, 'view');
            return;
        }
        if (record.is_posted) {
            Modal.confirm({
                title: 'Chứng từ đã ghi sổ',
                content: `Chứng từ ${record.voucher_number} đã được ghi sổ vào Sổ cái. Bạn có muốn BỎ GHI SỔ để chỉnh sửa không?`,
                okText: 'Bỏ ghi và Sửa',
                cancelText: 'Hủy bỏ',
                onOk: async () => {
                    try {
                        await unpostMutation.mutateAsync(record.id);
                        await handleViewPayment(record, 'edit');
                    } catch {
                        // Error handled in mutation
                    }
                }
            });
        } else {
            await handleViewPayment(record, 'edit');
        }
    };

    const handleOpenModal = useCallback((presetType?: string, prefillData?: any) => {
        if (isDepositVoucherType(presetType)) {
            message.info('Chức năng tiền gửi ngân hàng nằm ngoài phạm vi nội bộ hiện tại.');
            return;
        }
        if (isOutOfScopeCashPaymentType(presetType)) {
            message.info('Nghiệp vụ lương/bảo hiểm nằm ngoài phạm vi nội bộ hiện tại.');
            return;
        }
        setEditingPaymentId(null);
        setModalMode('create');
        setReferencedVouchers(prefillData?.referenced_vouchers || []);
        form.resetFields();
        const vType = presetType || '8. Chi khác';
        const norm = (s: string) => String(s || '').replace(/^\d+\.\s*/, '').replace(/\s+/g, ' ').replace(/cho\s+/, '').trim().toLowerCase();
        const vTypeNorm = norm(vType);
        const found = (activePaymentVoucherTypes || []).find(t => 
            t.value === vType || 
            t.label === vType || 
            t.value?.replace(/^\d+\.\s*/, '') === String(vType).replace(/^\d+\.\s*/, '') ||
            norm(t.value || '') === vTypeNorm ||
            (vType.toLowerCase().includes('bảo hiểm') && (t.value.toLowerCase().includes('bảo hiểm') || String(t.label || '').toLowerCase().includes('bảo hiểm')))
        );
        const resolvedType = found?.value || vType;
        setVoucherType(resolvedType);
        const defaultReason = found?.reason || prefillData?.reason || 'Chi khác';
        const defaultOp = found?.operation || (
            vType.toLowerCase().includes('bảo hiểm') ? 'Nộp bảo hiểm' :
            vType.toLowerCase().includes('nhà cung cấp') ? 'Trả tiền NCC' : 'Chi khác'
        );

        form.setFieldsValue({
            voucher_type: resolvedType,
            voucher_date: dayjs(),
            posting_date: dayjs(),
            currency: 'VND',
            exchange_rate: 1,
            reason: prefillData?.reason || defaultReason,
            contact_id: prefillData?.contact_id || undefined,
            contact_name: prefillData?.contact_name || '',
            receiver_name: prefillData?.receiver_name || '',
            receiver_address: prefillData?.receiver_address || '',
            lines: prefillData?.lines || [{
                key: '1',
                description: prefillData?.reason || defaultReason,
                amount: readCashPaymentAmount(prefillData?.amount),
                operation: defaultOp,
                line_contact_id: prefillData?.contact_code || '',
                line_contact_name: prefillData?.contact_name || ''
            }]
        });

        api.get('/cash/payments/next-code').then(res => {
            if (res.data?.code) {
                form.setFieldValue('voucher_number', res.data.code);
            }
        }).catch((error) => {
            message.warning(getApiErrorMessage(error, 'Không thể cấp số phiếu chi tự động; hãy nhập số chứng từ hoặc thử lại.'));
        });

        setIsModalVisible(true);
    }, [form, activePaymentVoucherTypes]);

    const paymentMenu: MenuProps['items'] = useMemo(() => [
        { 
            key: 'pay-std', 
            label: 'Phiếu chi', 
            onClick: () => handleOpenModal() 
        },
        { 
            key: 'pay-invoice', 
            label: 'Trả tiền theo hóa đơn', 
            onClick: () => setIsPayByInvoiceOpen(true) 
        },
        { 
            type: 'divider' 
        },
        { 
            key: 'pay-excel', 
            label: 'Nhập từ excel', 
            onClick: () => {
                setExcelImportType('payment');
                setIsExcelImportOpen(true);
            } 
        },
    ], [handleOpenModal]);

    const receiptMenu: MenuProps['items'] = useMemo(() => [
        { 
            key: 'rec-std', 
            label: 'Phiếu thu', 
            onClick: () => window.dispatchEvent(new Event('open-cash-receipt')) 
        },
        { 
            key: 'rec-invoice', 
            label: 'Thu tiền theo hóa đơn', 
            onClick: () => setIsCollectByInvoiceOpen(true) 
        },
        { 
            key: 'rec-multi-invoice', 
            label: 'Thu tiền theo hóa đơn nhiều khách hàng', 
            onClick: () => setIsCollectMultiCustomerOpen(true) 
        },
        { 
            type: 'divider' 
        },
        { 
            key: 'rec-excel', 
            label: 'Nhập từ excel', 
            onClick: () => {
                setExcelImportType('receipt');
                setIsExcelImportOpen(true);
            } 
        },
    ], []);

    const handlersRef = useRef({ handleOpenModal, handleEditPayment, handleViewPayment });
    handlersRef.current = { handleOpenModal, handleEditPayment, handleViewPayment };

    useEffect(() => {
        const handleOpen = (e: any) => {
            if (e?.detail?.record) {
                if (e.detail.mode === 'edit') {
                    handlersRef.current.handleEditPayment(e.detail.record);
                } else {
                    handlersRef.current.handleViewPayment(e.detail.record, 'view');
                }
            } else {
                handlersRef.current.handleOpenModal(e?.detail?.presetType, e?.detail?.prefillData);
            }
        };
        window.addEventListener('open-cash-payment', handleOpen);
        return () => window.removeEventListener('open-cash-payment', handleOpen);
    }, []);

    useEffect(() => {
        if (controlledOpen === true) {
            handleOpenModal();
        } else if (controlledOpen === false) {
            setIsModalVisible(false);
        }
    }, [controlledOpen, handleOpenModal]);

    useEffect(() => {
        if (searchParams.get('action') === 'create' && !isModalVisible) {
            handleOpenModal();
            const nextSearchParams = new URLSearchParams(searchParams);
            nextSearchParams.delete('action');
            setSearchParams(nextSearchParams, { replace: true });
        }
    }, [searchParams, setSearchParams, isModalVisible, handleOpenModal]);

    useEffect(() => {
        if (modalOnly) return;
        const rawSourceId = searchParams.get('source_id');
        if (rawSourceId === null || isLoading || isPaymentsError) return;

        const sourceId = Number(rawSourceId);
        const clearSourceQuery = () => {
            const nextSearchParams = new URLSearchParams(searchParams);
            nextSearchParams.delete('source_id');
            setSearchParams(nextSearchParams, { replace: true });
        };
        if (!Number.isSafeInteger(sourceId) || sourceId <= 0) {
            message.error('Liên kết phiếu chi không hợp lệ.');
            clearSourceQuery();
            return;
        }

        const listed = payments.find((payment: any) => Number(payment.id) === sourceId);
        if (listed) {
            clearSourceQuery();
            void handleViewPayment(listed, 'view');
            return;
        }

        let active = true;
        api.get('/cash/payments/' + sourceId)
            .then(({ data }) => {
                if (!active) return;
                const record = data?.data ?? data;
                if (!record || Number(record.id) !== sourceId) throw new Error('Máy chủ không trả về phiếu chi được yêu cầu.');
                clearSourceQuery();
                void handleViewPayment(record, 'view');
            })
            .catch((error) => {
                if (!active) return;
                clearSourceQuery();
                message.error(getApiErrorMessage(error, 'Không tìm thấy phiếu chi trong doanh nghiệp hiện tại.'));
            });
        return () => { active = false; };
    }, [handleViewPayment, isLoading, isPaymentsError, modalOnly, payments, searchParams, setSearchParams]);

    const columns: ColumnsType<PaymentRecord> = [
        { 
            title: 'Ngày hạch toán', 
            dataIndex: 'posting_date', 
            key: 'posting_date', 
            width: 120, 
            render: (val: any) => formatDate(val) 
        },
        { 
            title: 'Ngày chứng từ', 
            dataIndex: 'voucher_date', 
            key: 'voucher_date', 
            width: 120, 
            render: (val: any) => formatDate(val) 
        },
        { 
            title: 'Số chứng từ', 
            dataIndex: 'voucher_number', 
            key: 'voucher_number', 
            width: 130, 
            render: (t: string, record: PaymentRecord) => (
                <span className="misa-code-link" onClick={() => handleViewPayment(record)}>
                    {t}
                </span>
            )
        },
        { 
            title: 'Diễn giải', 
            dataIndex: 'reason', 
            key: 'reason' 
        },
        { 
            title: 'Số tiền', 
            dataIndex: 'total_amount', 
            key: 'total_amount',
            width: 140,
            align: 'right',
            render: (val: number) => <span className="misa-text-semibold">{formatCashPaymentAmount(val)}</span>
        },
        { 
            title: 'Đối tượng', 
            dataIndex: 'contact_name', 
            key: 'contact_name', 
            width: 220 
        },
        { 
            title: 'Trạng thái', 
            dataIndex: 'is_posted', 
            key: 'is_posted',
            width: 110,
            align: 'center',
            render: (_posted: boolean, record: PaymentRecord) => (
                <span className={`misa-apple-pill ${cashVoucherStatusTone(record)}`}>
                    <span className="misa-apple-pill-dot" />
                    {cashVoucherStatusLabel(record)}
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 130,
            align: 'center',
            render: (_: any, record: PaymentRecord) => {
                const legacyDeposit = isLegacyDepositPaymentRecord(record);
                const isVoided = isVoidedCashVoucher(record);
                const viewOnlyItems: MenuProps['items'] = [
                    {
                        key: 'view',
                        label: 'Xem chi tiết',
                        onClick: () => handleViewPayment(record, 'view'),
                    },
                    {
                        key: 'print',
                        label: 'In chứng từ',
                        icon: <PrinterOutlined className="misa-icon-success" />,
                        onClick: async () => {
                            await handleViewPayment(record, 'view');
                            setIsPrintModalVisible(true);
                        },
                    },
                ];
                const mutationItems: MenuProps['items'] = [
                    record.is_posted ? {
                        key: 'unpost',
                        label: 'Bỏ ghi',
                        icon: <CloseCircleOutlined className="misa-icon-warning" />,
                        onClick: () => unpostMutation.mutate(record.id)
                    } : {
                        key: 'post',
                        label: 'Ghi sổ',
                        icon: <CheckCircleOutlined className="misa-icon-success" />,
                        onClick: () => postMutation.mutate(record.id)
                    },
                    {
                        key: 'duplicate',
                        label: 'Nhân bản',
                        icon: <CopyOutlined className="misa-icon-primary" />,
                        onClick: () => duplicateMutation.mutate(record.id)
                    },
                    {
                        key: 'print',
                        label: 'In chứng từ',
                        icon: <PrinterOutlined className="misa-icon-success" />,
                        onClick: async () => {
                            await handleViewPayment(record, 'view');
                            setIsPrintModalVisible(true);
                        }
                    },
                    { type: 'divider' },
                    {
                        key: 'delete',
                        label: 'Xóa',
                        danger: true,
                        disabled: !!record.is_posted || isVoided,
                        icon: <DeleteOutlined />,
                        onClick: () => {
                            Modal.confirm({
                                title: 'Xác nhận xóa phiếu chi',
                                content: `Bạn có chắc chắn muốn xóa phiếu chi ${record.voucher_number}?`,
                                okText: 'Xóa',
                                okType: 'danger',
                                cancelText: 'Hủy',
                                onOk: () => deleteMutation.mutate(record.id)
                            });
                        }
                    }
                ];
                return (
                <div className="misa-inline-flex-center misa-gap-4">
                    <Button 
                        type="link" 
                        size="small" 
                        className="misa-text-primary misa-text-semibold misa-px-4" 
                        onClick={() => legacyDeposit || record.is_posted || isVoided ? handleViewPayment(record) : handleEditPayment(record)}
                    >
                        {legacyDeposit || record.is_posted || isVoided ? 'Xem' : 'Sửa'}
                    </Button>
                    <Dropdown
                        trigger={['click']}
                        menu={{ items: legacyDeposit || isVoided ? viewOnlyItems : mutationItems }}
                    >
                        <Button type="link" size="small" className="misa-btn-action-more">
                            <DownOutlined className="misa-icon-xs" />
                        </Button>
                    </Dropdown>
                </div>
                );
            },
        },
    ];

    const modalsContent = (
        <>
            {/* MISA Style Modal */}
            <Modal
                title={
                    <div className="misa-voucher-custom-header">
                        <div className="misa-voucher-header-left">
                            <div 
                                className={`misa-voucher-reload-btn ${modalMode === 'view' ? 'disabled' : ''}`}
                                onClick={modalMode === 'view' ? undefined : async () => {
                                    try {
                                        const res = await api.get('/cash/payments/next-code');
                                         const newVoucher = res.data?.code;
                                         if (!newVoucher) {
                                             message.error('Máy chủ không trả về số chứng từ mới');
                                             return;
                                         }
                                        form.setFieldValue('voucher_number', newVoucher);
                                        message.info(`Đã lấy số chứng từ mới: ${newVoucher}`);
                                    } catch {
                                        message.error('Không thể lấy số chứng từ mới');
                                    }
                                }}
                                title={modalMode === 'view' ? 'Chế độ xem không thể đổi số chứng từ' : 'Tự động lấy số chứng từ mới'}
                            >
                                <ReloadOutlined />
                            </div>
                            <span className="misa-voucher-title">
                                Phiếu chi {voucherNumber} {modalMode === 'view' ? '(Xem chi tiết)' : (modalMode === 'edit' ? '(Chỉnh sửa)' : '')}
                            </span>
                            <div className="misa-input-group">
                                <Select 
                                    value={voucherType}
                                    disabled={modalMode === 'view'}
                                    variant="borderless" 
                                    className="misa-input-w230 misa-text-semibold"
                                    popupMatchSelectWidth={false}
                                    onChange={handleVoucherTypeChange}
                                    options={selectablePaymentVoucherTypes.map(t => ({ value: t.value, label: t.value }))}
                                />
                                <button 
                                    type="button" 
                                    className={`misa-plus-btn ${modalMode === 'view' ? 'disabled' : ''}`}
                                    title="Thêm tài khoản ngầm định"
                                    disabled={modalMode === 'view'}
                                    onClick={modalMode === 'view' ? undefined : () => setIsReasonModalVisible(true)}
                                >
                                    <PlusOutlined />
                                </button>
                            </div>
                        </div>
                        <div className="misa-voucher-header-right">
                            <button type="button" className="misa-voucher-header-icon-btn" title="Thiết lập">
                                <SettingOutlined />
                            </button>
                            <button 
                                type="button" 
                                className="misa-voucher-header-icon-btn" 
                                title="Đóng (Esc)"
                                onClick={() => {
                                    setIsModalVisible(false);
                                    onOpenChange?.(false);
                                }}
                            >
                                <CloseOutlined />
                            </button>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => {
                    setIsModalVisible(false);
                    onOpenChange?.(false);
                }}
                className="misa-voucher-modal"
                footer={
                    <ConfigProvider componentSize="small" componentDisabled={modalMode === 'view'}>
                        <div className="misa-voucher-fixed-footer">
                            <div className="misa-footer-left">
                                <Switch
                                    size="small"
                                    checked={showAccounts}
                                    onChange={setShowAccounts}
                                    disabled={modalMode === 'view'}
                                    className="misa-switch-green"
                                />
                                <span className="misa-footer-switch-label">Hiển thị tài khoản</span>
                            </div>

                            <div className="misa-footer-right">
                                {modalMode === 'view' ? (
                                    <>
                                        <Button
                                            onClick={() => setIsModalVisible(false)}
                                            className="misa-btn-footer-cancel"
                                        >
                                            Đóng
                                        </Button>
                                        {editingPaymentId && (payments.find((p: PaymentRecord) => p.id === editingPaymentId)?.is_posted) ? (
                                            <Button
                                                onClick={() => unpostMutation.mutate(editingPaymentId)}
                                                className="misa-btn-footer-cancel"
                                            >
                                                Bỏ ghi
                                            </Button>
                                        ) : (
                                            editingPaymentId && (
                                                <Button
                                                    onClick={() => postMutation.mutate(editingPaymentId)}
                                                    className="misa-btn-footer-cancel"
                                                >
                                                    Ghi sổ
                                                </Button>
                                            )
                                        )}
                                        <Button
                                            onClick={() => {
                                                const cur = payments.find((p: PaymentRecord) => p.id === editingPaymentId);
                                                if (cur) handleEditPayment(cur);
                                                else setModalMode('edit');
                                            }}
                                            type="primary"
                                            className="misa-btn-footer-edit"
                                        >
                                            Sửa
                                        </Button>
                                        <Button
                                            onClick={() => {
                                                if (editingPaymentId) duplicateMutation.mutate(editingPaymentId);
                                            }}
                                            className="misa-btn-footer-cancel"
                                        >
                                            Nhân bản
                                        </Button>
                                        <Button
                                            onClick={() => setIsPrintModalVisible(true)}
                                            className="misa-btn-footer-cancel"
                                        >
                                            In
                                        </Button>
                                    </>
                                ) : (
                                    <>
                                        <Button
                                            onClick={() => {
                                                if (modalMode === 'edit' && editingPaymentId) {
                                                    setModalMode('view');
                                                    const orig = payments.find((p: PaymentRecord) => p.id === editingPaymentId);
                                                    if (orig) handleViewPayment(orig);
                                                } else {
                                                    setIsModalVisible(false);
                                                    onOpenChange?.(false);
                                                }
                                            }}
                                            className="misa-btn-footer-cancel"
                                        >
                                            Hủy
                                        </Button>
                                        <Button
                                            onClick={() => handleSaveForm(false, false)}
                                            loading={mutation.isPending}
                                            className="misa-btn-footer-save"
                                        >
                                            Cất
                                        </Button>
                                        <Button
                                            type="primary"
                                            onClick={() => handleSaveForm(true, false)}
                                            loading={mutation.isPending}
                                            className="misa-btn-footer-save-add"
                                        >
                                            Cất và Thêm
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </ConfigProvider>
                }
                closable={false}
                centered
            >
                <ModalFrame>
                <Form form={form} preserve={true} layout="vertical" onFinish={mutation.mutate} size="small" className="misa-voucher-form-container" disabled={modalMode === 'view'}>
                    <div className="misa-voucher-scroll-body">
                        {/* Top Master Card */}
                        <MisaMasterCard>
                        <MisaMasterCard.Left>
                            <MisaMasterCard.FormGrid>
                                {/* Mã đối tượng / Mã nhân viên */}
                                <div className="misa-col-5">
                                    <div className="misa-field-label">
                                        {String(voucherType || '').includes('4. Trả lương') ? 'Mã nhân viên' : 'Mã đối tượng'}
                                    </div>
                                    <Form.Item name="contact_id" noStyle>
                                        {String(voucherType || '').includes('Trả lương') ? (
                                            <MultiColumnContactSelect 
                                                placeholder="Chọn nhân viên..."
                                                entityType="employee"
                                                disabled={modalMode === 'view'}
                                                options={employees?.map((emp: any) => ({
                                                    id: emp.id,
                                                    code: emp.code,
                                                    name: emp.name,
                                                    department: emp.department || emp.address,
                                                    phone: emp.phone,
                                                    type: 'employee'
                                                }))}
                                                value={form.getFieldValue('contact_id')}
                                                onChange={(val, item) => {
                                                    form.setFieldsValue({
                                                        contact_id: val,
                                                        contact_name: item?.name || '',
                                                        receiver_name: item?.name || '',
                                                        receiver_address: item?.department || item?.address || '',
                                                        reason: `Trả lương cho nhân viên ${item?.name || ''}`
                                                    });
                                                    const curLines = form.getFieldValue('lines') || [];
                                                    form.setFieldsValue({
                                                        lines: curLines.map((l: any) => ({
                                                            ...l,
                                                            description: `Trả lương cho nhân viên ${item?.name || ''}`,
                                                            line_contact_id: item?.code || '',
                                                            line_contact_name: item?.name || ''
                                                        }))
                                                    });
                                                }}
                                                onQuickAdd={modalMode === 'view' ? undefined : () => setIsEmployeeModalVisible(true)}
                                            />
                                        ) : String(voucherType || '').includes('6. Gửi tiền vào ngân hàng') ? (
                                            <MultiColumnContactSelect 
                                                placeholder="Chọn tài khoản ngân hàng nhận..."
                                                entityType="bank"
                                                disabled={modalMode === 'view'}
                                                options={bankAccounts?.map((b: any) => ({
                                                    id: b.id,
                                                    code: b.account_number,
                                                    name: b.bank_name,
                                                    branch: b.branch,
                                                    address: b.branch,
                                                    type: 'bank'
                                                }))}
                                                value={form.getFieldValue('contact_id')}
                                                onChange={(val, item) => {
                                                    form.setFieldsValue({
                                                        contact_id: val,
                                                        contact_name: item?.name || '',
                                                        receiver_name: item?.name || 'Ngân hàng',
                                                        receiver_address: item?.branch || '',
                                                        reason: `Gửi tiền vào tài khoản ${item?.name || ''}`
                                                    });
                                                    const curLines = form.getFieldValue('lines') || [];
                                                    form.setFieldsValue({
                                                        lines: curLines.map((l: any) => ({
                                                            ...l,
                                                            description: `Gửi tiền vào tài khoản ${item?.name || ''}`,
                                                             operation: 'Gửi tiền vào ngân hàng',
                                                            line_contact_id: item?.code || val,
                                                            line_contact_name: item?.name || ''
                                                        }))
                                                    });
                                                }}
                                                onQuickAdd={undefined}
                                            />
                                        ) : (
                                            <MultiColumnContactSelect 
                                                placeholder="Chọn nhà cung cấp / đối tượng..."
                                                entityType="supplier"
                                                disabled={modalMode === 'view'}
                                                options={suppliers?.map((s: any) => ({
                                                    id: s.id,
                                                    code: s.code,
                                                    name: s.name,
                                                    tax_code: s.tax_code,
                                                    address: s.address,
                                                    phone: s.phone,
                                                    type: 'supplier'
                                                }))}
                                                value={form.getFieldValue('contact_id')}
                                                onChange={(val, item) => {
                                                    form.setFieldsValue({
                                                        contact_id: val,
                                                        contact_name: item?.name || '',
                                                        receiver_name: item?.contact_person || item?.name || '',
                                                        receiver_address: item?.address || '',
                                                        reason: `Chi tiền cho ${item?.name || ''}`
                                                    });
                                                    const curLines = form.getFieldValue('lines') || [];
                                                    form.setFieldsValue({
                                                        lines: curLines.map((l: any) => ({
                                                            ...l,
                                                            description: `Chi tiền cho ${item?.name || ''}`,
                                                            line_contact_id: item?.code || '',
                                                            line_contact_name: item?.name || ''
                                                        }))
                                                    });
                                                }}
                                                onQuickAdd={modalMode === 'view' ? undefined : () => setIsSupplierModalVisible(true)}
                                            />
                                        )}
                                    </Form.Item>
                                </div>

                                {/* Tên đối tượng / Tên nhân viên */}
                                <div className="misa-col-7">
                                    <div className="misa-field-label">
                                        {voucherType === '4. Trả lương tạm ứng cho nhân viên' ? 'Tên nhân viên' : 'Tên đối tượng'}
                                    </div>
                                    <Form.Item name="contact_name" noStyle>
                                        <Input className="misa-input" placeholder={voucherType === '4. Trả lương tạm ứng cho nhân viên' ? 'Tên nhân viên' : 'Tên đối tượng'} />
                                    </Form.Item>
                                </div>

                                {/* Người nhận */}
                                <div className="misa-col-5">
                                    <div className="misa-field-label">Người nhận</div>
                                    <Form.Item name="receiver_name" noStyle>
                                        <Input className="misa-input" placeholder="Họ và tên người nhận tiền" />
                                    </Form.Item>
                                </div>

                                {/* Địa chỉ */}
                                <div className="misa-col-7">
                                    <div className="misa-field-label">Địa chỉ</div>
                                    <Form.Item name="receiver_address" noStyle>
                                        <Input className="misa-input" placeholder="Địa chỉ chi tiết" />
                                    </Form.Item>
                                </div>

                                {/* Dynamic rows */}
                                {(voucherType === '4. Trả lương tạm ứng cho nhân viên' || voucherType === '6. Gửi tiền vào ngân hàng') ? (
                                    <div className="misa-col-10">
                                        <div className="misa-field-label">Lý do chi</div>
                                        <Form.Item name="reason" noStyle>
                                            <Input 
                                                className="misa-input" 
                                                suffix={<QrcodeOutlined className="misa-header-icon-qrcode" />} 
                                                placeholder="Lý do chi tiền"
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
                                ) : (
                                    <>
                                        <div className="misa-col-5">
                                            <div className="misa-field-label">Nhân viên</div>
                                            <div className="misa-input-group">
                                                <Form.Item name="employee_id" noStyle>
                                                    <Select 
                                                        showSearch
                                                        allowClear
                                                        variant="borderless" 
                                                        placeholder="Chọn nhân viên"
                                                        className="misa-w-full"
                                                        options={employees?.map((emp: any) => ({
                                                            value: emp.id,
                                                            label: `${emp.code} - ${emp.name}`
                                                        }))}
                                                    />
                                                </Form.Item>
                                                <button 
                                                    type="button" 
                                                    className={`misa-plus-btn ${modalMode === 'view' ? 'misa-btn-disabled' : ''}`}
                                                    title="Thêm nhanh"
                                                    disabled={modalMode === 'view'}
                                                    onClick={modalMode === 'view' ? undefined : () => setIsEmployeeModalVisible(true)}
                                                >
                                                    <PlusOutlined />
                                                </button>
                                            </div>
                                        </div>
                                        <div className="misa-col-5">
                                            <div className="misa-field-label">Lý do chi</div>
                                            <Form.Item name="reason" noStyle>
                                                <Input 
                                                    className="misa-input" 
                                                    suffix={<QrcodeOutlined className="misa-header-icon-qrcode" />} 
                                                    placeholder="Lý do chi tiền"
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
                                    </>
                                )}

                                {/* Kèm theo chứng từ */}
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
                                        key={`ref-pmt-${v.voucher_number ?? idx}`} 
                                        color="blue" 
                                        closable={modalMode !== 'view'} 
                                        onClose={() => setReferencedVouchers(referencedVouchers.filter((_, i) => i !== idx))}
                                    >
                                        <LinkOutlined />
                                        <span>{v.voucher_number} ({new Intl.NumberFormat('vi-VN').format(v.total_amount || 0)} ₫)</span>
                                    </Tag>
                                ))}
                                {modalMode !== 'view' && (
                                    <button 
                                        type="button" 
                                        className="misa-ref-btn"
                                        onClick={() => setIsRefModalVisible(true)}
                                    >
                                        ...
                                    </button>
                                )}
                            </div>
                        </MisaMasterCard.Left>

                        <MisaMasterCard.Right>
                            <MisaMasterCard.MetaRow label="Ngày hạch toán" required>
                                <Form.Item name="posting_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Ngày phiếu chi" required>
                                <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Số phiếu chi" required>
                                <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                    <Input className="misa-input misa-input-w160 misa-text-semibold" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaTotalCard 
                                label="TỔNG TIỀN" 
                                value={totals?.grandTotal || 0} 
                            />
                        </MisaMasterCard.Right>
                    </MisaMasterCard>

                    {/* Middle Section - Detail Accounting Grid */}
                    <div className="misa-detail-card-section">
                        <div className="misa-grid-tab-bar misa-flex-between">
                            <div className="misa-grid-tabs">
                                <button 
                                    type="button" 
                                    className="misa-grid-tab-btn active"
                                >
                                    Hạch toán
                                </button>
                            </div>
                        </div>

                        <div>
                            <Form.List name="lines">
                                    {(fields, { add, remove }) => {
                                        const totalPages = Math.max(1, Math.ceil(fields.length / pageSize));
                                        const vtStr = String(voucherType || '');
                                        const isTraNCC = vtStr.includes('1. Trả tiền cho nhà cung cấp') || vtStr.includes('Trả tiền nhà cung cấp');
                                        const isTamUng = vtStr.includes('2. Tạm ứng cho nhân viên');
                                        const isTraLuong = vtStr.includes('3. Trả lương cho nhân viên') || vtStr.includes('4. Trả lương') || vtStr.includes('Trả lương');
                                        const isGuiTienNH = vtStr.includes('6. Gửi tiền vào ngân hàng');
                                        const showPaymentLineContact = !isTraNCC && !isTamUng && !isTraLuong && !isGuiTienNH;
                                        const safePage = Math.min(Math.max(1, currentPage), totalPages);
                                        const startIndex = (safePage - 1) * pageSize;
                                        const visibleFields = fields.slice(startIndex, startIndex + pageSize);

                                        return (
                                            <div>
                                                <div className="misa-table-container">
                                                    <table className="misa-voucher-table">
                                                        <thead>
                                                            <tr>
                                                                <th className="misa-col-seq">#</th>
                                                                <th>Diễn giải</th>
                                                                <th className="misa-col-account">TK Nợ</th>
                                                                <th className="misa-col-account">TK Có</th>
                                                                <th className="misa-col-amount">Số tiền</th>
                                                                <th className="misa-col-operation">Nghiệp vụ</th>
                                                                {isGuiTienNH && <th className="misa-col-contact-code">TK ngân hàng</th>}
                                                                {isGuiTienNH && <th>Tên ngân hàng</th>}
                                                                {showPaymentLineContact && (
                                                                    <th className="misa-col-contact-code">
                                                                        {vtStr.includes('4. Trả lương') ? 'Mã nhân viên' : 'Đối tượng'}
                                                                    </th>
                                                                )}
                                                                {showPaymentLineContact && (
                                                                    <th>
                                                                        {vtStr.includes('4. Trả lương') ? 'Tên nhân viên' : 'Tên đối tượng'}
                                                                    </th>
                                                                )}
                                                                {modalMode !== 'view' && <th className="misa-col-action"></th>}
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {visibleFields.map((field, idx) => {
                                                                const rowNum = startIndex + idx + 1;
                                                                return (
                                                                    <tr key={field.key}>
                                                                        <td className="misa-col-seq">
                                                                            {rowNum}
                                                                        </td>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'description']} noStyle>
                                                                                <Input className="misa-table-input" variant="borderless" placeholder="Diễn giải nghiệp vụ..." />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td className="misa-col-account">
                                                                             <Form.Item name={[field.name, 'debit_account']} noStyle rules={[{ required: true, message: 'Chọn TK Nợ' }]}>
                                                                                <AccountSelect accounts={chartOfAccounts} disabled={modalMode === 'view'} />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td className="misa-col-account">
                                                                             <Form.Item name={[field.name, 'credit_account']} noStyle rules={[{ required: true, message: 'Chọn TK Có' }]}>
                                                                                <AccountSelect accounts={chartOfAccounts} disabled={modalMode === 'view'} />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td className="misa-col-amount">
                                                                            <Form.Item name={[field.name, 'amount']} noStyle initialValue={0}>
                                                                                <InputNumber 
                                                                                    variant="borderless"
                                                                                    formatter={v => (v !== undefined && v !== null && v !== '') ? `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : ''}
                                                                                    parser={(v) => (v ? Number(String(v).replace(/\$\s?|(,*)/g, '')) : 0) as any}
                                                                                    className="misa-w-full misa-text-right misa-text-bold"
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td className="misa-col-operation">
                                                                            <Form.Item name={[field.name, 'operation']} noStyle initialValue={isGuiTienNH ? 'Gửi tiền vào NH' : 'Chi khác'}>
                                                                                <Select 
                                                                                    showSearch 
                                                                                    variant="borderless"
                                                                                    className="misa-w-full"
                                                                                    placeholder="Chọn nghiệp vụ..."
                                                                                    popupMatchSelectWidth={false}
                                                                                    options={[
                                                                                        { value: 'Chi khác', label: 'Chi khác' },
                                                                                        { value: 'Trả lương tạm ứng', label: 'Trả lương tạm ứng' },
                                                                                        { value: 'Trả lương nhân viên', label: 'Trả lương nhân viên' },
                                                                                        { value: 'Chi cho vay', label: 'Chi cho vay' },
                                                                                    ]}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        {isGuiTienNH && (
                                                                            <td className="misa-col-contact-code">
                                                                                <Form.Item name={[field.name, 'line_contact_id']} noStyle>
                                                                                    <MultiColumnContactSelect 
                                                                                        options={bankAccounts?.map((b: any) => ({
                                                                                            id: b.id,
                                                                                            code: b.account_number,
                                                                                            name: b.bank_name,
                                                                                            branch: b.branch,
                                                                                            address: b.branch,
                                                                                            type: 'bank'
                                                                                        })) || []}
                                                                                        placeholder="Chọn TK ngân hàng"
                                                                                        entityType="bank"
                                                                                        disabled={modalMode === 'view'}
                                                                                        onChange={(_, rec) => {
                                                                                            const curLines = form.getFieldValue('lines') || [];
                                                                                            if (curLines && curLines[field.name]) {
                                                                                                curLines[field.name].line_contact_name = rec?.name || '';
                                                                                                form.setFieldsValue({ lines: [...curLines] });
                                                                                            }
                                                                                        }}
                                                                                        onQuickAdd={undefined}
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                        )}
                                                                        {isGuiTienNH && (
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'line_contact_name']} noStyle>
                                                                                    <Input variant="borderless" className="misa-table-input" placeholder="Tên ngân hàng" />
                                                                                </Form.Item>
                                                                            </td>
                                                                        )}
                                                                        {showPaymentLineContact && (
                                                                            <td className="misa-col-contact-code">
                                                                                <Form.Item name={[field.name, 'line_contact_id']} noStyle>
                                                                                    {voucherType === '4. Trả lương tạm ứng cho nhân viên' ? (
                                                                                        <Select 
                                                                                            showSearch 
                                                                                            variant="borderless"
                                                                                            className="misa-w-full"
                                                                                            allowClear
                                                                                            placeholder="Mã NV"
                                                                                            options={employees?.map((emp: any) => ({
                                                                                                value: emp.code || emp.id,
                                                                                                label: `${emp.code} - ${emp.name}`
                                                                                            }))}
                                                                                            onChange={(val) => {
                                                                                                const emp = employees?.find((e: any) => (e.code === val || e.id === val));
                                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                                if (curLines && curLines[field.name]) {
                                                                                                    curLines[field.name].line_contact_name = emp?.name || '';
                                                                                                    form.setFieldsValue({ lines: [...curLines] });
                                                                                                }
                                                                                            }}
                                                                                        />
                                                                                    ) : (
                                                                                        <Select 
                                                                                            showSearch 
                                                                                            variant="borderless"
                                                                                            className="misa-w-full"
                                                                                            allowClear
                                                                                            placeholder="Mã ĐT"
                                                                                            options={suppliers?.map((s: any) => ({
                                                                                                value: s.code || s.id,
                                                                                                label: `${s.code} - ${s.name}`
                                                                                            }))}
                                                                                            onChange={(val) => {
                                                                                                const supp = suppliers?.find((c: any) => (c.code === val || c.id === val));
                                                                                                const curLines = form.getFieldValue('lines') || [];
                                                                                                if (curLines && curLines[field.name]) {
                                                                                                    curLines[field.name].line_contact_name = supp?.name || '';
                                                                                                    form.setFieldsValue({ lines: [...curLines] });
                                                                                                }
                                                                                            }}
                                                                                        />
                                                                                    )}
                                                                                </Form.Item>
                                                                            </td>
                                                                        )}
                                                                        {showPaymentLineContact && (
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'line_contact_name']} noStyle>
                                                                                    <Input variant="borderless" className="misa-table-input" placeholder="Tên đối tượng" />
                                                                                </Form.Item>
                                                                            </td>
                                                                        )}
                                                                        {modalMode !== 'view' && (
                                                                            <td className="misa-col-action">
                                                                                <button 
                                                                                    type="button" 
                                                                                    title="Xóa dòng"
                                                                                    className="misa-btn-row-delete"
                                                                                    onClick={() => {
                                                                                        remove(field.name);
                                                                                        const nextLength = Math.max(0, fields.length - 1);
                                                                                        const nextTotalPages = Math.max(1, Math.ceil(nextLength / pageSize));
                                                                                        if (currentPage > nextTotalPages) {
                                                                                            setCurrentPage(nextTotalPages);
                                                                                        }
                                                                                    }}
                                                                                >
                                                                                    <DeleteOutlined />
                                                                                </button>
                                                                            </td>
                                                                        )}
                                                                    </tr>
                                                                );
                                                            })}
                                                        </tbody>
                                                        <tfoot>
                                                            <tr>
                                                                <td colSpan={2}>
                                                                    Tổng số: <strong>{fields.length}</strong>
                                                                </td>
                                                                <td></td>
                                                                <td></td>
                                                                <td className="misa-col-amount">
                                                                    <strong>{new Intl.NumberFormat('vi-VN').format(totals?.grandTotal || 0)}</strong>
                                                                </td>
                                                                <td></td>
                                                                {isGuiTienNH && <td></td>}
                                                                {isGuiTienNH && <td></td>}
                                                                {showPaymentLineContact && <td></td>}
                                                                {showPaymentLineContact && <td></td>}
                                                                {modalMode !== 'view' && <td></td>}
                                                            </tr>
                                                        </tfoot>
                                                    </table>
                                                </div>

                                                {/* Action Bar Below Grid & Pagination Controls */}
                                                <div className="misa-grid-action-row">
                                                    <div className="misa-grid-action-left">
                                                        {modalMode !== 'view' && (
                                                            <>
                                                                <Button 
                                                                    size="small" 
                                                                    icon={<PlusOutlined />} 
                                                                    onClick={() => {
                                                                         const found = activePaymentVoucherTypes.find((t: any) => 
                                                                            t.value === voucherType || 
                                                                            t.label === voucherType || 
                                                                            t.value.replace(/^\d+\.\s*/, '') === String(voucherType).replace(/^\d+\.\s*/, '')
                                                                        );
                                                                        add({ 
                                                                            description: form.getFieldValue('reason') || 'Chi tiền', 
                                                                             amount: undefined,
                                                                            operation: found?.operation || 'Chi khác',
                                                                            line_contact_id: form.getFieldValue('contact_id') || '',
                                                                            line_contact_name: form.getFieldValue('contact_name') || ''
                                                                        });
                                                                        const nextLength = fields.length + 1;
                                                                        const nextTotalPages = Math.max(1, Math.ceil(nextLength / pageSize));
                                                                        if (nextTotalPages > currentPage) {
                                                                            setCurrentPage(nextTotalPages);
                                                                        }
                                                                    }}
                                                                    className="misa-btn-footer-default"
                                                                >
                                                                    + Thêm dòng
                                                                </Button>
                                                                <Button 
                                                                    size="small" 
                                                                    danger
                                                                    icon={<DeleteOutlined />} 
                                                                    onClick={() => {
                                                                        form.setFieldValue('lines', []);
                                                                        setCurrentPage(1);
                                                                        message.success('Đã xóa toàn bộ dòng hạch toán');
                                                                    }}
                                                                >
                                                                    Xóa hết dòng
                                                                </Button>
                                                                <Button 
                                                                    size="small" 
                                                                    icon={<SettingOutlined />} 
                                                                    className="misa-btn-footer-default"
                                                                >
                                                                    Thêm ghi chú
                                                                </Button>
                                                            </>
                                                        )}
                                                    </div>

                                                    <div className="misa-grid-action-right">
                                                        <span>Số dòng/trang</span>
                                                        <Select 
                                                            value={pageSize} 
                                                            onChange={val => { setPageSize(val); setCurrentPage(1); }} 
                                                            size="small" 
                                                            className="misa-input-w70"
                                                            options={[{ value: 20, label: '20' }, { value: 50, label: '50' }, { value: 100, label: '100' }]} 
                                                        />
                                                        <span>{fields.length === 0 ? '0 - 0' : `${startIndex + 1} - ${Math.min(startIndex + pageSize, fields.length)}`} trên {fields.length}</span>
                                                        <div className="misa-pagination-pages">
                                                            <Button size="small" disabled={safePage <= 1} onClick={() => setCurrentPage(1)} className="misa-pagination-nav-btn">|&lt;</Button>
                                                            <Button size="small" disabled={safePage <= 1} onClick={() => setCurrentPage(p => Math.max(1, p - 1))} className="misa-pagination-nav-btn">&lt;</Button>
                                                            <Button size="small" disabled={safePage >= totalPages} onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))} className="misa-pagination-nav-btn">&gt;</Button>
                                                            <Button size="small" disabled={safePage >= totalPages} onClick={() => setCurrentPage(totalPages)} className="misa-pagination-nav-btn">&gt;|</Button>
                                                        </div>
                                                    </div>
                                                </div>

                                                {/* Drag & Drop Attachment Box */}
                                                {modalMode !== 'view' && (
                                                    <div className="misa-attachment-container">
                                                        <div className="misa-attachment-label">
                                                            <PaperClipOutlined className="misa-primary-color" />
                                                            <span>Đính kèm</span>
                                                            <span className="misa-attachment-limit">Dung lượng tối đa 5MB</span>
                                                        </div>
                                                        <div className="misa-attachment-dropzone">
                                                            <UploadOutlined className="misa-attachment-icon" />
                                                            <span className="misa-attachment-link">Chọn tệp</span> hoặc kéo và thả tệp vào đây
                                                        </div>
                                                    </div>
                                                )}
                                            </div>
                                        );
                                    }}
                                </Form.List>
                        </div>
                    </div>
                </div>

                </Form>
                </ModalFrame>
            </Modal>
            
            {/* Quick Add Supplier / Contact Modal */}
            <QuickAddContactModal 
                open={isSupplierModalVisible}
                contactType="supplier"
                onCancel={() => setIsSupplierModalVisible(false)}
                onSuccess={(newContact) => {
                    form.setFieldsValue({
                        contact_id: newContact.id,
                        contact_name: newContact.name,
                        receiver_name: newContact.contact_person || newContact.name,
                        receiver_address: newContact.address || '',
                        reason: `Chi tiền cho ${newContact.name}`
                    });
                }}
            />

            {/* Quick Add Employee Modal */}
            <QuickAddEmployeeModal 
                open={isEmployeeModalVisible}
                onCancel={() => setIsEmployeeModalVisible(false)}
                onSuccess={(newEmployee) => {
                    if (voucherType === '4. Trả lương tạm ứng cho nhân viên') {
                        form.setFieldsValue({
                            contact_id: newEmployee.id,
                            contact_name: newEmployee.name,
                            receiver_name: newEmployee.name,
                            receiver_address: newEmployee.address || newEmployee.department,
                            reason: `Trả lương tạm ứng cho nhân viên ${newEmployee.name}`
                        });
                    } else {
                        form.setFieldsValue({
                            employee_id: newEmployee.id
                        });
                    }
                }}
            />

            {/* Reference Voucher Selection Modal */}
            <ReferenceVoucherModal 
                open={isRefModalVisible}
                onCancel={() => setIsRefModalVisible(false)}
                onSelect={(selected) => {
                    const newRefs = [...(Array.isArray(referencedVouchers) ? referencedVouchers : []), ...(Array.isArray(selected) ? selected : [])];
                    setReferencedVouchers(newRefs);
                    if (Array.isArray(selected) && selected.length > 0) {
                        const first = selected[0];
                        form.setFieldsValue({
                            reason: `Chi tiền theo ${selected.map(s => s?.voucher_number || '').join(', ')}`,
                            ...(first.contact_id || first.contact_code ? { contact_id: first.contact_id || first.contact_code } : {}),
                            ...(first.contact_name ? { contact_name: first.contact_name, receiver_name: first.contact_name } : {})
                        });
                        // Auto-populate accounting lines matching referenced vouchers
                        const newLines = selected.map((v, idx) => {
                            return {
                                key: `${Date.now()}_${idx}`,
                                description: `Chi tiền theo ${v.voucher_type || ''} ${v.voucher_number || ''}`,
                                debit_account: v.debit_account,
                                credit_account: v.credit_account,
                                amount: readCashPaymentAmount(v.total_amount ?? v.amount),
                                operation: v.operation,
                                line_contact_id: v.contact_id || v.contact_code || '',
                                line_contact_name: v.contact_name || '',
                                invoice_id: v.real_id || v.id
                            };
                        });
                        form.setFieldsValue({ lines: newLines });
                        message.success(`Đã nạp ${selected.length} chứng từ tham chiếu vào bảng hạch toán!`);
                    }
                }}
            />

            {/* Quick Add Reason Modal */}
            <QuickAddReasonModal
                open={isReasonModalVisible}
                onCancel={() => setIsReasonModalVisible(false)}
                category="cash_payment"
                onSuccess={(newReason: any) => {
                    setCustomPaymentVoucherTypes(prev => [...prev, newReason]);
                    form.setFieldValue('voucher_type', newReason.value);
                    handleVoucherTypeChange(newReason.value);
                }}
            />

            {/* MISA Standard Payment Voucher Print Preview Modal (Mẫu 02-TT) */}
            <VoucherPrintModal
                open={isPrintModalVisible}
                onCancel={() => setIsPrintModalVisible(false)}
                type="payment"
                voucher={{
                     voucher_number: form.getFieldValue('voucher_number'),
                    voucher_date: form.getFieldValue('voucher_date')?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                    posting_date: form.getFieldValue('posting_date')?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                    contact_name: form.getFieldValue('contact_name') || form.getFieldValue('receiver_name') || '',
                    receiver_name: form.getFieldValue('receiver_name') || form.getFieldValue('contact_name') || '',
                    receiver_address: form.getFieldValue('receiver_address') || '',
                    reason: form.getFieldValue('reason') || '',
                    attached_docs: form.getFieldValue('attached_docs') || '',
                    total_amount: totals?.grandTotal || 0,
                    amount_in_words: readMoneyToVietnameseWords(totals?.grandTotal || 0),
                    lines: (form.getFieldValue('lines') || []).filter(Boolean).map((l: any) => ({
                        description: l.description || form.getFieldValue('reason'),
                         debit_account: l.debit_account,
                         credit_account: l.credit_account,
                        amount: readCashPaymentAmount(l.amount)
                    }))
                }}
            />

            {/* Extension Modals for Dropdown Menus */}
            <CollectByInvoiceModal 
                open={isCollectByInvoiceOpen}
                onClose={() => setIsCollectByInvoiceOpen(false)}
            />

            <CollectMultiCustomerModal 
                open={isCollectMultiCustomerOpen}
                onClose={() => setIsCollectMultiCustomerOpen(false)}
            />

            <PayByInvoiceModal 
                open={isPayByInvoiceOpen}
                onClose={() => setIsPayByInvoiceOpen(false)}
            />


             <ExcelImportModal 
                open={isExcelImportOpen}
                onClose={() => setIsExcelImportOpen(false)}
                voucherType={excelImportType}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
                    queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
                }}
            />
        </>
    );

    if (modalOnly) {
        return modalsContent;
    }

    return (
        <PageShell title={<PageHeader eyebrow="Tiền mặt" title="Phiếu chi" description="Lập và quản lý các chứng từ chi tiền mặt." />}>
            {/* Header Toolbar */}
            <PageToolbar
                filters={(
                <div className="ui-page-toolbar__filter-group">
                    <div className="misa-search-box">
                        <Input.Search placeholder="Nhập từ khóa tìm kiếm..." allowClear />
                    </div>
                    <div className="misa-flex-center misa-gap-8">
                        <Select value={datePreset} onChange={setDatePreset} className="misa-date-select">
                            <Select.Option value="Hôm nay">Hôm nay</Select.Option>
                            <Select.Option value="Tuần này">Tuần này</Select.Option>
                            <Select.Option value="Tháng này">Tháng này</Select.Option>
                            <Select.Option value="Quý này">Quý này</Select.Option>
                            <Select.Option value="Năm này">Năm này</Select.Option>
                            <Select.Option value="Tùy chọn">Tùy chọn</Select.Option>
                        </Select>
                        {datePreset === 'Tùy chọn' && (
                            <DatePicker.RangePicker 
                                value={dateRange}
                                onChange={(dates: any) => setDateRange(dates)}
                                format="DD/MM/YYYY"
                            />
                        )}
                    </div>
                </div>
                )}
                actions={(
                <div className="ui-page-toolbar__action-group">
                    <Button 
                        icon={<ReloadOutlined />} 
                        onClick={() => queryClient.invalidateQueries({ queryKey: ['cash-payments'] })}
                        className="misa-btn-tool"
                    />

                    <Dropdown 
                        menu={{ items: receiptMenu }} 
                        placement="bottomRight" 
                        trigger={['hover']}
                    >
                        <Button 
                            type="primary" 
                            className="misa-btn-primary"
                            onClick={() => window.dispatchEvent(new Event('open-cash-receipt'))}
                        >
                            <span>Thu tiền</span>
                            <DownOutlined className="misa-fs-10" />
                        </Button>
                    </Dropdown>

                    <Dropdown 
                        menu={{ items: paymentMenu }} 
                        placement="bottomRight" 
                        trigger={['hover']}
                    >
                        <Button 
                            type="primary" 
                            className="misa-btn-primary"
                            onClick={() => handleOpenModal()}
                        >
                            <span>Chi tiền</span>
                            <DownOutlined className="misa-fs-10" />
                        </Button>
                    </Dropdown>
                </div>
                )}
            />

            {/* List Table */}
            <DataTableSurface>
            {isPaymentsError ? <Alert
                type="error"
                showIcon
                title="Không thể tải danh sách phiếu chi"
                description="Dữ liệu chưa được xác minh từ máy chủ; không hiển thị danh sách rỗng thay thế."
                action={<Button onClick={() => void refetchPayments()}>Thử lại danh sách phiếu chi</Button>}
            /> : <Table 
                    columns={columns} 
                    dataSource={payments || []} 
                    rowKey="id" 
                    loading={isLoading} 
                    size="small"
                    bordered
                    rowSelection={{ type: 'checkbox' }}
                    className="misa-voucher-table"
                    scroll={{ x: 'max-content', y: 'calc(100vh - 280px)' }}
                    locale={{ emptyText: (
                        <div className="misa-empty-box">
                            <InboxOutlined className="misa-empty-icon" />
                            Không có dữ liệu
                        </div>
                    ) }}
                />}
            </DataTableSurface>

            {modalsContent}
        </PageShell>
    );
});

export default CashPayments;

