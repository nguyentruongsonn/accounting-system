import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Alert, Table, Button, ConfigProvider, Form, Input, InputNumber, Select, DatePicker, Switch, Dropdown, Space, Tag } from 'antd';
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
    UploadOutlined,
    SearchOutlined,
    ExportOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { formatDate } from '../../utils/dateUtils';
import { readMoneyToVietnameseWords } from '../../utils/numberToWords';
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
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import { getVoucherActionDecision, runVoucherAction, type VoucherAction } from '../../types/voucherActions';
import { getApiErrorMessage } from '../../utils/apiErrorMessage';
import { cashVoucherStatusLabel, cashVoucherStatusTone, isVoidedCashVoucher } from './cashVoucherStatus';

interface VoucherTypeOption {
    value: string;
    label: string;
    debit?: string;
    credit?: string;
    reason?: string;
    operation?: string;
    voucher_type?: string;
}

interface ReceiptLine {
    key?: string;
    description?: string;
    debit_account?: string;
    credit_account?: string;
    amount?: number | null;
    operation?: string;
    line_contact_id?: string;
    line_contact_name?: string;
    loan_contract?: string;
}

interface ReceiptRecord {
    id: number;
    voucher_number: string;
    voucher_date: string;
    posting_date: string;
    reason: string;
    total_amount: number | null;
    contact_name: string;
    is_posted: boolean;
    status?: string;
    voucher_type?: string;
    lines?: ReceiptLine[];
}

function parseCashReceiptCollection(value: unknown, resource: string): any[] {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

function readCashVoucherAmount(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string' && value.trim() !== '' && Number.isFinite(Number(value))) return Number(value);
    return null;
}

function sumCashVoucherAmounts(rows: any[], loading: boolean, error: boolean): number | null {
    if (loading || error) return null;
    if (rows.length === 0) return 0;
    const amounts = rows.map((row) => readCashVoucherAmount(row?.total_amount ?? row?.amount));
    if (amounts.some((amount) => amount === null)) return null;
    return (amounts as number[]).reduce((sum, amount) => sum + amount, 0);
}

function sumPostedCashVoucherAmounts(rows: any[], loading: boolean, error: boolean): number | null {
    return sumCashVoucherAmounts(
        rows.filter((row) => row?.is_posted === true && !isVoidedCashVoucher(row)),
        loading,
        error,
    );
}

function formatCashVoucherAmount(value: unknown): string {
    const amount = readCashVoucherAmount(value);
    return amount === null ? '—' : `${new Intl.NumberFormat('vi-VN').format(amount)} ₫`;
}

const voucherTypes: VoucherTypeOption[] = [
    { value: '1. Thu tiền khách hàng (không theo hóa đơn)', label: '1. Thu tiền khách hàng (không theo hóa đơn)', reason: 'Thu tiền khách hàng', operation: 'Thu tiền khách hàng' },
    { value: '2. Thu hoàn ứng nhân viên', label: '2. Thu hoàn ứng nhân viên', reason: 'Thu hoàn ứng sau khi quyết toán tạm ứng', operation: 'Thu hoàn ứng' },
    { value: '4. Thu hồi các khoản cho vay', label: '4. Thu hồi các khoản cho vay', reason: 'Thu hồi các khoản cho vay', operation: 'Thu hồi cho vay' },
    { value: '5. Thu khác', label: '5. Thu khác', reason: 'Thu tiền của ', operation: 'Thu khác' },
];

const isDepositTransferVoucherType = (value: unknown): boolean => {
    const normalized = String(value ?? '').toLowerCase();
    return normalized.includes('rút tiền gửi')
        || normalized.includes('rút tiền từ ngân hàng')
        || normalized.includes('gửi tiền vào ngân hàng');
};

const isLegacyDepositReceiptRecord = (record: Partial<ReceiptRecord> | null | undefined): boolean => (
    isDepositTransferVoucherType(record?.voucher_type)
    || isDepositTransferVoucherType(record?.reason)
    || String(record?.lines?.[0]?.credit_account ?? '').startsWith('112')
);

interface CashReceiptsProps {
    modalOnly?: boolean;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}

export const CashReceipts: React.FC<CashReceiptsProps> = React.memo(({ modalOnly = false, open: controlledOpen, onOpenChange }) => {

    const [searchParams, setSearchParams] = useSearchParams();
    const [editingReceiptId, setEditingReceiptId] = useState<number | null>(null);
    const [modalMode, setModalMode] = useState<'create' | 'edit' | 'view'>('create');
    const [activeReceiptIsPosted, setActiveReceiptIsPosted] = useState(false);
    const [customVoucherTypes, setCustomVoucherTypes] = useState<any[]>(voucherTypes);
    const [isReasonModalVisible, setIsReasonModalVisible] = useState(false);
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [isCustomerModalVisible, setIsCustomerModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [isRefModalVisible, setIsRefModalVisible] = useState(false);
    const [isPrintModalVisible, setIsPrintModalVisible] = useState(false);
    const [referencedVouchers, setReferencedVouchers] = useState<any[]>([]);

    // Extension Modals for "Thu tiền" & "Chi tiền" Dropdown Menus
    const [isCollectByInvoiceOpen, setIsCollectByInvoiceOpen] = useState(false);
    const [isCollectMultiCustomerOpen, setIsCollectMultiCustomerOpen] = useState(false);
    const [isPayByInvoiceOpen, setIsPayByInvoiceOpen] = useState(false);
    const [isExcelImportOpen, setIsExcelImportOpen] = useState(false);
    const [excelImportType, setExcelImportType] = useState<'receipt' | 'payment'>('receipt');

    const [datePreset, setDatePreset] = useState('Đầu năm tới hiện tại');
    const [dateRange, setDateRange] = useState<any>([dayjs().startOf('year'), dayjs()]);
    const [showAccounts, setShowAccounts] = useState(true);
    const [pageSize, setPageSize] = useState(20);
    const [currentPage, setCurrentPage] = useState(1);
    const [searchText, setSearchText] = useState('');
    const [listFilterType, setListFilterType] = useState('all');
    const [listPageSize, setListPageSize] = useState(20);

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const lines: ReceiptLine[] = Form.useWatch('lines', form) || [];
    const totals = useVoucherTotals(lines);
    const voucherNumber = Form.useWatch('voucher_number', form);
    const [voucherType, setVoucherType] = useState('1. Thu tiền khách hàng (không theo hóa đơn)');

    const { data: receipts = [], isLoading, isError: isReceiptsError, refetch: refetchReceipts } = useQuery({
        queryKey: ['cash-receipts'],
        queryFn: async () => {
            const { data } = await api.get('/cash/receipts');
            return parseCashReceiptCollection(data, 'cash receipts');
        },
        enabled: !modalOnly,
    });

    const { data: payments = [], isLoading: isPaymentsLoading, isError: isPaymentsError, refetch: refetchPayments } = useQuery({
        queryKey: ['cash-payments'],
        queryFn: async () => {
            const { data } = await api.get('/cash/payments');
            return parseCashReceiptCollection(data, 'cash payments');
        },
        enabled: !modalOnly,
    });

    useEffect(() => {
        const refresh = () => {
            void queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
            void queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
        };
        window.addEventListener('cash-receipts-invalidated', refresh);
        return () => window.removeEventListener('cash-receipts-invalidated', refresh);
    }, [queryClient]);

    const listedReceiptTotal = useMemo(() => sumCashVoucherAmounts(Array.isArray(receipts) ? receipts : [], isLoading, isReceiptsError), [receipts, isLoading, isReceiptsError]);
    const totalReceipts = useMemo(() => sumPostedCashVoucherAmounts(Array.isArray(receipts) ? receipts : [], isLoading, isReceiptsError), [receipts, isLoading, isReceiptsError]);
    const totalPayments = useMemo(() => sumPostedCashVoucherAmounts(Array.isArray(payments) ? payments : [], isPaymentsLoading, isPaymentsError), [payments, isPaymentsLoading, isPaymentsError]);
    const netBalance = totalReceipts === null || totalPayments === null ? null : totalReceipts - totalPayments;
    const currentTime = useMemo(() => dayjs().format('DD/MM/YYYY HH:mm:ss'), []);

    const filteredReceipts = useMemo(() => {
        let result = Array.isArray(receipts) ? receipts : [];
        if (searchText.trim()) {
            const q = searchText.toLowerCase();
            result = result.filter((r: any) =>
                String(r?.voucher_number || '').toLowerCase().includes(q) ||
                String(r?.contact_name || '').toLowerCase().includes(q) ||
                String(r?.reason || '').toLowerCase().includes(q)
            );
        }
        return result;
    }, [receipts, searchText]);

    const paymentMenu: MenuProps['items'] = useMemo(() => [
        {
            key: 'pay-std',
            label: 'Phiếu chi',
            onClick: () => window.dispatchEvent(new Event('open-cash-payment'))
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
    ], []);

    const { data: voucherSettingsData = [] } = useQuery({
        queryKey: ['voucher-type-settings', 'thu_tien_mat'],
        queryFn: async () => {
            const { data } = await api.get('/master/voucher-type-settings?voucher_type=thu_tien_mat');
            return parseCashReceiptCollection(data, 'cash voucher settings');
        },
        staleTime: 10 * 60 * 1000,
    });

    const activeVoucherTypes = useMemo(() => {
        const base = [...voucherTypes];
        voucherSettingsData.forEach((s: any) => {
            const rawName = s.name;
            if (!rawName || isDepositTransferVoucherType(rawName) || isDepositTransferVoucherType(s.voucher_type)) return;

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

        // Merge customVoucherTypes additions
        customVoucherTypes.forEach((c: any) => {
            const rawVal = c.value || c.name || c.label;
            if (!rawVal || isDepositTransferVoucherType(rawVal) || isDepositTransferVoucherType(c.voucher_type)) return;

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
        return base;
    }, [voucherSettingsData, customVoucherTypes]);

    const { data: chartOfAccounts = [] } = useQuery({
        queryKey: ['chart-of-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return parseCashReceiptCollection(data, 'chart-of-accounts catalogue');
        },
        staleTime: 15 * 60 * 1000,
    });

    const { data: customers = [] } = useQuery({
        queryKey: ['customers'],
        queryFn: async () => {
            const { data } = await api.get('/master/customers');
            return parseCashReceiptCollection(data, 'customer catalogue');
        },
        staleTime: 10 * 60 * 1000,
    });

    const { data: employees = [] } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parseCashReceiptCollection(data, 'employee catalogue');
        },
        staleTime: 10 * 60 * 1000,
    });

    // Deposit/bank workflows are outside the internal cash-only pilot. Legacy
    // records remain viewable, but no bank catalogue is loaded or offered.
    const bankAccounts: any[] = [];

    const mutation = useMutation({
        mutationFn: async (values: any) => {
            const isThuHoanUng = String(values?.voucher_type || voucherType || '').includes('2. Thu hoàn ứng');
            const empObj = employees?.find((e: any) => e.id === values.contact_id || e.code === values.contact_id);
            const custObj = customers?.find((c: any) => c.id === values.contact_id);

            const payload = {
                voucher_type: values.voucher_type || voucherType,
                contact_type: isThuHoanUng ? 'employee' : 'customer',
                contact_id: values.contact_id,
                contact_name: isThuHoanUng ? (empObj?.name || values.contact_name || values.payer_name) : (custObj?.name || values.contact_name || values.payer_name),
                payer_name: values.payer_name,
                payer_address: values.payer_address,
                employee_id: values.employee_id || (isThuHoanUng ? values.contact_id : undefined),
                employee_name: employees?.find((e: any) => e.id === values.employee_id || (isThuHoanUng && (e.id === values.contact_id || e.code === values.contact_id)))?.name,
                reason: values.reason,
                referenced_vouchers: referencedVouchers,
                attached_docs: values.attached_docs,
                currency: values.currency || 'VND',
                exchange_rate: values.exchange_rate || 1,
                voucher_number: values.voucher_number,
                voucher_date: dayjs.isDayjs(values.voucher_date) ? values.voucher_date.format('YYYY-MM-DD') : (values.voucher_date ? formatDate(values.voucher_date) : dayjs().format('YYYY-MM-DD')),
                posting_date: dayjs.isDayjs(values.posting_date) ? values.posting_date.format('YYYY-MM-DD') : (values.posting_date ? formatDate(values.posting_date) : dayjs().format('YYYY-MM-DD')),
                lines: (values.lines || []).filter(Boolean).map((line: any) => ({
                    description: line.description || values.reason,
                    debit_account: line.debit_account,
                    credit_account: line.credit_account,
                    amount: readCashVoucherAmount(line.amount),
                    operation: line.operation || (isThuHoanUng ? 'Thu hoàn ứng' : 'Thu tiền khách hàng'),
                    line_contact_id: isThuHoanUng ? null : line.line_contact_id,
                    line_contact_name: isThuHoanUng ? null : line.line_contact_name,
                    loan_contract: line.loan_contract,
                    cost_object: line.cost_object
                }))
            };
            const res = editingReceiptId
                ? await api.put(`/cash/receipts/${editingReceiptId}`, payload)
                : await api.post('/cash/receipts', payload);
            return { res, andNew: values.andNew, andPrint: values.andPrint };
        },
        onSuccess: (data: any) => {
            const persistedReceipt = data?.res?.data?.data ?? data?.res?.data;
            if (!persistedReceipt || !Number.isInteger(Number(persistedReceipt.id))) {
                message.error('Máy chủ không trả về phiếu thu đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success(editingReceiptId ? 'Cập nhật Phiếu thu thành công!' : 'Tạo Phiếu thu thành công!');
            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });

            if (data?.andPrint) {
                setIsPrintModalVisible(true);
            }

            if (data?.andNew) {
                setEditingReceiptId(null);
                setModalMode('create');
                form.resetFields();
                form.setFieldsValue({
                    posting_date: dayjs(),
                    voucher_date: dayjs(),
                    reason: 'Thu tiền khách hàng',
                    lines: [
                        { key: '1', description: 'Thu tiền khách hàng', amount: undefined }
                    ]
                });
                api.get('/cash/receipts/next-code').then(res => {
                    if (res.data?.code) form.setFieldValue('voucher_number', res.data.code);
                }).catch((error) => {
                    message.warning(getApiErrorMessage(error, 'Không thể cấp số phiếu thu tự động; hãy nhập số chứng từ hoặc thử lại.'));
                });
            } else if (!data?.andPrint) {
                setIsModalVisible(false);
            }
        },
        onError: (err: any) => {
            message.error(getApiErrorMessage(err, 'Có lỗi xảy ra khi lưu phiếu thu!'));
        }
    });

    const handleSaveForm = (andNew = false, andPrint = false) => {
        form.validateFields().then(values => {
            if (!values.contact_id && !values.contact_name && !values.payer_name) {
                message.error('Vui lòng chọn hoặc nhập đối tượng nộp tiền!');
                return;
            }
            const currentLines = (values.lines || []).filter(Boolean);
            const requestedVoucherType = String(values.voucher_type || voucherType || '');
            const usesDepositFlow = isDepositTransferVoucherType(requestedVoucherType)
                || currentLines.some((line: any) => isDepositTransferVoucherType(line?.operation));
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
        const currentLines = form.getFieldValue('lines') || [];
        const defaultOp = voucherType.includes('2. Thu hoàn ứng') ? 'Thu hoàn ứng' :
            voucherType.includes('3. Rút tiền gửi') ? 'Rút tiền gửi về nhập quỹ' :
            voucherType.includes('4. Thu hồi') ? 'Thu hồi các khoản cho vay' :
            voucherType.includes('5. Thu khác') || voucherType.includes('Thu khác') ? 'Thu khác' : 'Thu tiền khách hàng';
        form.setFieldsValue({
            lines: [
                ...currentLines,
                {
                    key: `${Date.now()}`,
                    description: form.getFieldValue('reason') || 'Thu tiền của ',
                    amount: undefined,
                    operation: defaultOp,
                    loan_contract: '',
                    line_contact_id: form.getFieldValue('contact_name') || '',
                    line_contact_name: form.getFieldValue('contact_name') || ''
                }
            ]
        });
    };

    const receiptActionContext = {
        status: activeReceiptIsPosted ? 'posted' : 'draft',
        isPosted: activeReceiptIsPosted,
        mode: modalMode,
        hasIdentity: Boolean(editingReceiptId),
    };
    const runGuardedReceiptAction = (action: VoucherAction, execute: () => void) => {
        runVoucherAction(action, receiptActionContext, execute, (reason) => message.warning(reason));
    };

    useVoucherShortcuts({
        onSave: () => runGuardedReceiptAction('save', () => handleSaveForm(false, false)),
        onSaveAndNew: () => runGuardedReceiptAction('save-and-new', () => handleSaveForm(true, false)),
        onPrint: () => {
            if (modalMode === 'view') {
                setIsPrintModalVisible(true);
            } else {
                handleSaveForm(false, true);
            }
        },
        onAddLine: () => runGuardedReceiptAction('add-line', handleAddLine),
        onPost: () => runGuardedReceiptAction('post', () => postMutation.mutate(editingReceiptId!)),
        onUnpost: () => runGuardedReceiptAction('unpost', () => unpostMutation.mutate(editingReceiptId!)),
        onEdit: () => runGuardedReceiptAction('edit', () => {
            const cur = receipts.find((r: ReceiptRecord) => r.id === editingReceiptId);
            if (cur) handleEditReceipt(cur);
        }),
        onDelete: () => runGuardedReceiptAction('delete', () => {
            Modal.confirm({
                title: 'Xóa phiếu thu',
                content: 'Bạn có chắc chắn muốn xóa phiếu thu này không?',
                okText: 'Xóa',
                okType: 'danger',
                cancelText: 'Hủy',
                onOk: () => deleteMutation.mutate(editingReceiptId!)
            });
        }),
        onClose: () => {
            if (!isCustomerModalVisible && !isEmployeeModalVisible && !isRefModalVisible && !isPrintModalVisible) {
                setIsModalVisible(false);
            }
        },
        enabled: isModalVisible
    });

    const postMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/cash/receipts/${id}/post`);
        },
        onSuccess: (response: any) => {
            if (typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã ghi sổ phiếu thu.');
                return;
            }
            message.success('Ghi sổ thành công');
            setActiveReceiptIsPosted(true);
            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
        },
        onError: (err: unknown) => {
            message.error(getApiErrorMessage(err, 'Không thể ghi sổ phiếu thu.'));
        },
    });

    const handleVoucherTypeChange = (type: string) => {
        if (isDepositTransferVoucherType(type)) {
            message.info('Nghiệp vụ tiền gửi không thuộc phạm vi tiền mặt nội bộ; route ngân hàng chỉ giữ tương thích.');
            return;
        }
        setVoucherType(type);
        const typeStr = String(type || '');
        const found = activeVoucherTypes.find(t =>
            t.value === type ||
            t.label === type ||
            t.value.replace(/^\d+\.\s*/, '') === typeStr.replace(/^\d+\.\s*/, '')
        );
        let defaultReason = found?.reason || (type ? `Thu tiền - ${typeStr.replace(/^\d+\.\s*/, '')}` : 'Thu tiền');

        const isThuKhach = typeStr.includes('1. Thu tiền khách hàng');
        const isThuHoanUng = typeStr.includes('2. Thu hoàn ứng');
        const isRutTG = isDepositTransferVoucherType(typeStr);
        const isThuHoiVay = typeStr.includes('Thu hồi các khoản') || typeStr.includes('cho vay');
        const isThuKhac = typeStr.includes('Thu khác');

        const curContactName = form.getFieldValue('contact_name');
        const curContactId = form.getFieldValue('contact_id');

        if (isThuKhach) {
            defaultReason = curContactName ? `Thu tiền của ${curContactName}` : 'Thu tiền khách hàng';
        } else if (isThuHoanUng) {
            const emp = employees?.find((e: any) => String(e.id) === String(curContactId) || e.code === curContactId);
            defaultReason = emp ? `Thu hoàn ứng sau khi quyết toán tạm ứng cho ${emp.name}` : (curContactName ? `Thu hoàn ứng của ${curContactName}` : 'Thu hoàn ứng');
        } else if (isRutTG) {
            defaultReason = curContactName ? `Rút tiền gửi của ${curContactName} về nhập quỹ` : 'Rút tiền gửi về nhập quỹ';
        } else if (isThuHoiVay) {
            defaultReason = curContactName ? `Thu hồi các khoản cho ${curContactName} vay` : 'Thu hồi các khoản cho vay';
        } else if (isThuKhac) {
            defaultReason = curContactName ? `Thu tiền của ${curContactName}` : 'Thu khác';
        } else if (found?.value || found?.label) {
            const cleanName = (found.reason || found.label || found.value).replace(/^\d+\.\s*/, '');
            defaultReason = curContactName ? `${cleanName} của ${curContactName}` : cleanName;
        }

        const operationName = isThuKhach ? 'Thu tiền khách hàng' :
            isThuHoanUng ? 'Thu hoàn ứng' :
            isRutTG ? 'Rút tiền gửi về nhập quỹ' :
            isThuHoiVay ? 'Thu hồi các khoản cho vay' : (found?.operation || 'Thu khác');

        const currentLines = form.getFieldValue('lines') || [];
        const updatedLines = currentLines.length > 0
            ? currentLines.map((l: any, idx: number) => ({
                ...l,
                key: l.key || String(idx + 1),
                description: defaultReason,
                debit_account: l.debit_account,
                credit_account: l.credit_account,
                operation: operationName,
                line_contact_id: l.line_contact_id || curContactId || '',
                line_contact_name: l.line_contact_name || curContactName || ''
            }))
            : [{
                key: '1',
                description: defaultReason,
                amount: undefined,
                operation: operationName,
                line_contact_id: curContactId || '',
                line_contact_name: curContactName || ''
            }];

        form.setFieldsValue({
            voucher_type: type,
            reason: defaultReason,
            lines: updatedLines
        });
    };

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/cash/receipts/${id}/unpost`);
        },
        onSuccess: (response: any) => {
            if (typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã bỏ ghi sổ phiếu thu.');
                return;
            }
            message.success('Bỏ ghi sổ thành công!');
            setActiveReceiptIsPosted(false);
            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
        },
        onError: (err: unknown) => {
            message.error(getApiErrorMessage(err, 'Không thể bỏ ghi sổ phiếu thu!'));
        }
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/cash/receipts/${id}/duplicate`);
        },
        onSuccess: (res: any) => {
            const newDoc = res?.data?.data ?? res?.data;
            if (!newDoc || !Number.isInteger(Number(newDoc.id))) {
                message.error('Máy chủ không trả về phiếu thu nhân bản; không thể xác nhận thao tác thành công.');
                return;
            }
            message.success('Nhân bản chứng từ thành công!');
            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
            handleEditReceipt(newDoc);
        },
        onError: (err: unknown) => {
            message.error(getApiErrorMessage(err, 'Không thể nhân bản phiếu thu!'));
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.delete(`/cash/receipts/${id}`);
        },
        onSuccess: (response: any) => {
            if (typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã xóa phiếu thu.');
                return;
            }
            message.success('Xóa phiếu thu thành công');
            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
        },
        onError: (err: unknown) => {
            message.error(getApiErrorMessage(err, 'Không thể xóa phiếu thu!'));
        }
    });

    const handleViewReceipt = async (record: any, targetMode: 'view' | 'edit' = 'view') => {
        setEditingReceiptId(record.id);
        setModalMode(isLegacyDepositReceiptRecord(record) ? 'view' : targetMode);
        let receipt: any;
        try {
            const { data } = await api.get(`/cash/receipts/${record.id}`);
            receipt = data?.data || data;
            if (!receipt || typeof receipt !== 'object' || !Number.isInteger(Number(receipt.id))) {
                throw new Error('Máy chủ không trả về chi tiết phiếu thu hợp lệ.');
            }
        } catch (e) {
            setEditingReceiptId(null);
            message.error(getApiErrorMessage(e, 'Không thể tải chi tiết phiếu thu. Hãy thử lại.'));
            return;
        }

        setActiveReceiptIsPosted(Boolean(receipt.is_posted ?? record.is_posted));

        // Detect voucher_type smartly if missing or resolve to numbered format
        const rawType = receipt.voucher_type;
        const matched = activeVoucherTypes.find(t =>
            t.value === rawType ||
            t.label === rawType ||
            t.value.replace(/^\d+\.\s*/, '') === String(rawType).replace(/^\d+\.\s*/, '')
        );
        const resolvedType = matched?.value || rawType || (() => {
            const creditAcc = receipt.lines?.[0]?.credit_account || '';
            if (creditAcc.startsWith('141')) return '2. Thu hoàn ứng nhân viên';
            if (creditAcc.startsWith('112')) return '3. Rút tiền gửi về nhập quỹ';
            if (creditAcc.startsWith('128')) return '4. Thu hồi các khoản cho vay';
            if (creditAcc.startsWith('711') || creditAcc.startsWith('138')) return '5. Thu khác';
            return '1. Thu tiền khách hàng (không theo hóa đơn)';
        })();

        if (isDepositTransferVoucherType(resolvedType)) {
            setModalMode('view');
        }

        setVoucherType(resolvedType);
        if (resolvedType && !activeVoucherTypes.some(t => t.value === resolvedType)) {
            setCustomVoucherTypes(prev => [...prev, { value: resolvedType, label: resolvedType }]);
        }

        setReferencedVouchers(receipt.references || receipt.referenced_vouchers || []);

        const foundCust = customers?.find((c: any) => c.id === receipt.contact_id || String(c.id) === String(receipt.contact_id) || c.code === receipt.contact_id);
        const resolvedContactCode = foundCust?.code || receipt.contact_code || receipt.contact_id;
        const resolvedContactName = foundCust?.name || receipt.contact_name || receipt.payer_name;
        const resolvedEmpId = employees?.find((e: any) => String(e.id) === String(receipt.employee_id) || e.code === receipt.employee_id)?.id
            ?? (receipt.employee_id ? (Number(receipt.employee_id) || receipt.employee_id) : undefined);

        form.setFieldsValue({
            voucher_type: resolvedType,
            voucher_number: receipt.voucher_number,
            voucher_date: dayjs(receipt.voucher_date || new Date()),
            posting_date: dayjs(receipt.posting_date || receipt.voucher_date || new Date()),
            currency: receipt.currency || 'VND',
            exchange_rate: receipt.exchange_rate || 1,
            contact_id: resolvedContactCode,
            contact_name: resolvedContactName,
            payer_name: receipt.payer_name || resolvedContactName,
            payer_address: receipt.payer_address || foundCust?.address,
            employee_id: resolvedEmpId,
            reason: receipt.reason,
            attached_docs: receipt.attached_docs,
            lines: (receipt.lines && receipt.lines.length > 0) ? receipt.lines.map((l: any, idx: number) => {
                const lineCust = customers?.find((c: any) => c.id === l.line_contact_id || String(c.id) === String(l.line_contact_id) || c.code === l.line_contact_id);
                return {
                    key: String(l.id || l.key || idx),
                    description: l.description || receipt.reason,
                    debit_account: l.debit_account,
                    credit_account: l.credit_account,
                    amount: readCashVoucherAmount(l.amount),
                    operation: l.operation || (resolvedType.includes('2. Thu hoàn ứng') ? 'Thu hoàn ứng' : 'Thu tiền khách hàng'),
                    loan_contract: l.loan_contract,
                    line_contact_id: lineCust?.code || l.line_contact_id || resolvedContactCode,
                    line_contact_name: lineCust?.name || l.line_contact_name || resolvedContactName
                };
            }) : []
        });
        setIsModalVisible(true);
    };

    const handleEditReceipt = async (record: ReceiptRecord) => {
        if (isLegacyDepositReceiptRecord(record)) {
            message.info('Phiếu thu tiền gửi cũ chỉ được xem; nghiệp vụ này đã ẩn khỏi phạm vi tiền mặt nội bộ.');
            await handleViewReceipt(record, 'view');
            return;
        }
        const decision = getVoucherActionDecision('edit', {
            isPosted: record.is_posted,
            status: record.is_posted ? 'posted' : 'draft',
            hasIdentity: Boolean(record.id),
        });
        if (!decision.allowed) {
            message.warning(decision.reason);
            return;
        }

        setEditingReceiptId(record.id);
        setModalMode('edit');
        await handleViewReceipt(record, 'edit');
    };

    const handleOpenModal = useCallback((presetType?: string, prefillData?: any) => {
        if (isDepositTransferVoucherType(presetType)) {
            message.info('Nghiệp vụ tiền gửi không thuộc phạm vi tiền mặt nội bộ; không mở mẫu chứng từ.');
            return;
        }
        setEditingReceiptId(null);
        setModalMode('create');
        setActiveReceiptIsPosted(false);
        const vType = presetType || '1. Thu tiền khách hàng (không theo hóa đơn)';
        const found = (activeVoucherTypes || []).find(t =>
            t.value === vType ||
            t.label === vType ||
            t.value?.replace(/^\d+\.\s*/, '') === String(vType).replace(/^\d+\.\s*/, '')
        );
        const resolvedType = found?.value || vType;
        setVoucherType(resolvedType);
        setReferencedVouchers(prefillData?.referenced_vouchers || []);
        form.resetFields();
        const defaultReason = found?.reason || 'Thu tiền khách hàng';
        const defaultOp = found?.operation || 'Thu tiền khách hàng';

        form.setFieldsValue({
            voucher_type: resolvedType,
            voucher_date: dayjs(),
            posting_date: dayjs(),
            currency: 'VND',
            exchange_rate: 1,
            reason: prefillData?.reason || defaultReason,
            contact_id: prefillData?.contact_id || undefined,
            contact_name: prefillData?.contact_name || '',
            payer_name: prefillData?.payer_name || '',
            payer_address: prefillData?.payer_address || '',
            lines: prefillData?.lines || [{
                key: '1',
                description: prefillData?.reason || defaultReason,
                amount: readCashVoucherAmount(prefillData?.amount),
                operation: defaultOp,
                line_contact_id: prefillData?.contact_code || '',
                line_contact_name: prefillData?.contact_name || ''
            }]
        });

        api.get('/cash/receipts/next-code').then(res => {
            if (res.data?.code) {
                form.setFieldValue('voucher_number', res.data.code);
            }
        }).catch((error) => {
            message.warning(getApiErrorMessage(error, 'Không thể cấp số phiếu thu tự động; hãy nhập số chứng từ hoặc thử lại.'));
        });

        setIsModalVisible(true);
    }, [form, activeVoucherTypes]);

    const receiptMenu: MenuProps['items'] = useMemo(() => [
        {
            key: 'rec-std',
            label: 'Phiếu thu',
            onClick: () => handleOpenModal()
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
    ], [handleOpenModal]);

    const handlersRef = useRef({ handleOpenModal, handleEditReceipt, handleViewReceipt });
    handlersRef.current = { handleOpenModal, handleEditReceipt, handleViewReceipt };

    useEffect(() => {
        const handleOpen = (e: any) => {
            if (e?.detail?.record) {
                if (e.detail.mode === 'edit') {
                    handlersRef.current.handleEditReceipt(e.detail.record);
                } else {
                    handlersRef.current.handleViewReceipt(e.detail.record, 'view');
                }
            } else {
                handlersRef.current.handleOpenModal(e?.detail?.presetType, e?.detail?.prefillData);
            }
        };
        window.addEventListener('open-cash-receipt', handleOpen);
        return () => window.removeEventListener('open-cash-receipt', handleOpen);
    }, []);

    useEffect(() => {
        if (controlledOpen === true) {
            handleOpenModal();
        } else if (controlledOpen === false) {
            setIsModalVisible(false);
        }
    }, [controlledOpen, handleOpenModal]);

    useEffect(() => {
        const action = searchParams.get('action');

        if (action === 'create' && !isModalVisible) {
            handleOpenModal();
        } else if (action === 'collect-multi' && !isCollectMultiCustomerOpen) {
            setIsCollectMultiCustomerOpen(true);
        } else {
            return;
        }

        const nextSearchParams = new URLSearchParams(searchParams);
        nextSearchParams.delete('action');
        setSearchParams(nextSearchParams, { replace: true });
    }, [
        searchParams,
        setSearchParams,
        isModalVisible,
        isCollectMultiCustomerOpen,
        handleOpenModal,
    ]);

    useEffect(() => {
        if (modalOnly) return;
        const rawSourceId = searchParams.get('source_id');
        if (rawSourceId === null || isLoading || isReceiptsError) return;

        const sourceId = Number(rawSourceId);
        const clearSourceQuery = () => {
            const nextSearchParams = new URLSearchParams(searchParams);
            nextSearchParams.delete('source_id');
            setSearchParams(nextSearchParams, { replace: true });
        };
        if (!Number.isSafeInteger(sourceId) || sourceId <= 0) {
            message.error('Liên kết phiếu thu không hợp lệ.');
            clearSourceQuery();
            return;
        }

        const listed = receipts.find((receipt: any) => Number(receipt.id) === sourceId);
        if (listed) {
            clearSourceQuery();
            void handleViewReceipt(listed, 'view');
            return;
        }

        let active = true;
        api.get('/cash/receipts/' + sourceId)
            .then(({ data }) => {
                if (!active) return;
                const record = data?.data ?? data;
                if (!record || Number(record.id) !== sourceId) throw new Error('Máy chủ không trả về phiếu thu được yêu cầu.');
                clearSourceQuery();
                void handleViewReceipt(record, 'view');
            })
            .catch((error) => {
                if (!active) return;
                clearSourceQuery();
                message.error(getApiErrorMessage(error, 'Không tìm thấy phiếu thu trong doanh nghiệp hiện tại.'));
            });
        return () => { active = false; };
    }, [handleViewReceipt, isLoading, isReceiptsError, modalOnly, receipts, searchParams, setSearchParams]);

    const columns: ColumnsType<ReceiptRecord> = [
        {
            title: 'Ngày hạch toán',
            dataIndex: 'posting_date',
            key: 'posting_date',
            width: 110,
            render: (val: any) => formatDate(val)
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 120,
            render: (t: string, record: ReceiptRecord) => (
                <span className="misa-code-link" onClick={() => handleViewReceipt(record)}>
                    {t}
                </span>
            )
        },
        {
            title: 'Diễn giải',
            dataIndex: 'reason',
            key: 'reason',
            minWidth: 180,
        },
        {
            title: 'Số tiền',
            dataIndex: 'total_amount',
            key: 'total_amount',
            width: 140,
            align: 'right',
            render: (val: number) => <span className="misa-text-semibold">{formatCashVoucherAmount(val)}</span>
        },
        {
            title: 'Đối tượng',
            dataIndex: 'contact_name',
            key: 'contact_name',
            width: 180
        },
        {
            title: 'Lý do thu/chi',
            dataIndex: 'voucher_type',
            key: 'voucher_type',
            width: 160,
            render: (val: string, record: any) => val || record.reason || 'Thu tiền mặt'
        },
        {
            title: 'Loại chứng từ',
            key: 'voucher_doc_type',
            width: 120,
            render: () => <span className="misa-badge-type misa-badge-receipt">Thu tiền mặt</span>
        },
        {
            title: 'Hạch toán gộp nhiều hóa đơn',
            key: 'batch_invoice',
            width: 140,
            align: 'center',
            render: () => <span className="misa-color-muted">Không</span>
        },
        {
            title: 'Trạng thái',
            dataIndex: 'is_posted',
            key: 'is_posted',
            width: 105,
            align: 'center',
            render: (_posted: boolean, record: ReceiptRecord) => (
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
            render: (_: any, record: ReceiptRecord) => {
                const legacyDeposit = isLegacyDepositReceiptRecord(record);
                const isVoided = isVoidedCashVoucher(record);
                const viewOnlyItems: MenuProps['items'] = [
                    {
                        key: 'view',
                        label: 'Xem chi tiết',
                        onClick: () => handleViewReceipt(record, 'view'),
                    },
                    {
                        key: 'print',
                        label: 'In chứng từ',
                        icon: <PrinterOutlined className="misa-icon-success" />,
                        onClick: async () => {
                            await handleViewReceipt(record, 'view');
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
                            await handleViewReceipt(record, 'view');
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
                                title: 'Xác nhận xóa phiếu thu',
                                content: `Bạn có chắc chắn muốn xóa phiếu thu ${record.voucher_number}?`,
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
                    onClick={() => legacyDeposit || record.is_posted || isVoided ? handleViewReceipt(record) : handleEditReceipt(record)}
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
                                        const res = await api.get('/cash/receipts/next-code');
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
                                Phiếu thu {voucherNumber} {modalMode === 'view' ? '(Xem chi tiết)' : (modalMode === 'edit' ? '(Chỉnh sửa)' : '')}
                            </span>
                            <div className="misa-input-group">
                                <Select
                                    value={voucherType}
                                    disabled={modalMode === 'view'}
                                    variant="borderless"
                                    className="misa-input-w260 misa-text-semibold"
                                    popupMatchSelectWidth={false}
                                    onChange={handleVoucherTypeChange}
                                    options={activeVoucherTypes.map(t => ({ value: t.value, label: t.label }))}
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
                                        {editingReceiptId && (receipts.find((r: ReceiptRecord) => r.id === editingReceiptId)?.is_posted) ? (
                                            <Button
                                                onClick={() => unpostMutation.mutate(editingReceiptId)}
                                                className="misa-btn-footer-cancel"
                                            >
                                                Bỏ ghi (Ctrl+B)
                                            </Button>
                                        ) : (
                                            editingReceiptId && (
                                                <Button
                                                    onClick={() => postMutation.mutate(editingReceiptId)}
                                                    className="misa-btn-footer-cancel"
                                                >
                                                    Ghi sổ (F9)
                                                </Button>
                                            )
                                        )}
                                        <Button
                                            onClick={() => {
                                                const cur = receipts.find((r: ReceiptRecord) => r.id === editingReceiptId);
                                                if (cur?.is_posted) {
                                                    Modal.confirm({
                                                        title: 'Chứng từ đã ghi sổ',
                                                        content: `Chứng từ ${cur.voucher_number} đã được ghi sổ. Bạn có muốn BỎ GHI SỔ để chỉnh sửa không?`,
                                                        okText: 'Bỏ ghi và Sửa',
                                                        cancelText: 'Hủy bỏ',
                                                        onOk: async () => {
                                                            await unpostMutation.mutateAsync(cur.id);
                                                            setModalMode('edit');
                                                            message.info('Đã chuyển sang chế độ chỉnh sửa');
                                                        }
                                                    });
                                                } else {
                                                    setModalMode('edit');
                                                    message.info('Đã chuyển sang chế độ chỉnh sửa');
                                                }
                                            }}
                                            type="primary"
                                            className="misa-btn-footer-edit"
                                        >
                                            Sửa (Ctrl+E)
                                        </Button>
                                        <Button
                                            onClick={() => {
                                                if (editingReceiptId) duplicateMutation.mutate(editingReceiptId);
                                            }}
                                            className="misa-btn-footer-cancel"
                                        >
                                            Nhân bản
                                        </Button>
                                        <Button
                                            onClick={() => setIsPrintModalVisible(true)}
                                            className="misa-btn-footer-cancel"
                                        >
                                            In (Ctrl+P)
                                        </Button>
                                    </>
                                ) : (
                                    <>
                                        <Button
                                            onClick={() => {
                                                if (modalMode === 'edit' && editingReceiptId) {
                                                    setModalMode('view');
                                                    const orig = receipts.find((r: ReceiptRecord) => r.id === editingReceiptId);
                                                    if (orig) handleViewReceipt(orig);
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
                                        <Space.Compact className="misa-btn-footer-save-add">
                                            <Button
                                                type="primary"
                                                onClick={() => handleSaveForm(true, false)}
                                                loading={mutation.isPending}
                                            >
                                                Cất và Thêm
                                            </Button>
                                            <Dropdown
                                                menu={{
                                                    items: [
                                                        {
                                                            key: 'save_and_add',
                                                            label: 'Cất và Thêm',
                                                            onClick: () => handleSaveForm(true, false),
                                                        },
                                                        {
                                                            key: 'save_and_close',
                                                            label: 'Cất và Đóng',
                                                            onClick: () => handleSaveForm(false, false),
                                                        },
                                                        {
                                                            key: 'save_and_print',
                                                            label: 'Cất và In',
                                                            onClick: () => handleSaveForm(false, true),
                                                        }
                                                    ]
                                                }}
                                                trigger={['click']}
                                                placement="topRight"
                                            >
                                                <Button
                                                    type="primary"
                                                    aria-label="Tùy chọn cất chứng từ"
                                                    loading={mutation.isPending}
                                                >
                                                    <DownOutlined />
                                                </Button>
                                            </Dropdown>
                                        </Space.Compact>
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
                        {/* Master Card */}
                        <MisaMasterCard>
                        <MisaMasterCard.Left>
                            <MisaMasterCard.FormGrid>
                                {/* Row 1: Target Entity / Bank / Employee */}
                                {voucherType.includes('2. Thu hoàn ứng') ? (
                                    <>
                                        <div className="misa-col-5">
                                            <div className="misa-field-label">Mã nhân viên</div>
                                            <Form.Item name="contact_id" noStyle>
                                                <MultiColumnContactSelect
                                                    placeholder="Chọn nhân viên hoàn ứng..."
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
                                                        const reasonText = item ? `Thu hoàn ứng sau khi quyết toán tạm ứng cho ${item.name}` : 'Thu hoàn ứng sau khi quyết toán tạm ứng cho ';
                                                        form.setFieldsValue({
                                                            contact_id: val,
                                                            contact_name: item?.name || '',
                                                            payer_name: item?.name || '',
                                                            payer_address: item?.department || item?.address || '',
                                                            employee_id: item?.id || val,
                                                            reason: reasonText
                                                        });
                                                        const curLines = form.getFieldValue('lines') || [];
                                                        form.setFieldsValue({
                                                            lines: curLines.map((l: any) => ({
                                                                ...l,
                                                                description: reasonText,
                                                                line_contact_id: item?.code || '',
                                                                line_contact_name: item?.name || ''
                                                            }))
                                                        });
                                                    }}
                                                    onQuickAdd={modalMode === 'view' ? undefined : () => setIsEmployeeModalVisible(true)}
                                                />
                                            </Form.Item>
                                        </div>
                                        <div className="misa-col-7">
                                            <div className="misa-field-label">Tên nhân viên</div>
                                            <Form.Item name="contact_name" noStyle>
                                                <Input className="misa-input" placeholder="Tên nhân viên" />
                                            </Form.Item>
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <div className="misa-col-5">
                                            <div className="misa-field-label">Mã đối tượng</div>
                                            <Form.Item name="contact_id" noStyle>
                                                <MultiColumnContactSelect
                                                    placeholder="Chọn đối tượng..."
                                                    disabled={modalMode === 'view'}
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
                                                        const curType = voucherType || '5. Thu khác';
                                                        let reasonText = 'Thu tiền';
                                                        if (curType.includes('3. Rút tiền')) {
                                                            reasonText = item ? `Rút tiền gửi của ${item.name} về nhập quỹ` : 'Rút tiền gửi về nhập quỹ';
                                                        } else if (curType.includes('4. Thu hồi') || curType.includes('cho vay')) {
                                                            reasonText = item ? `Thu hồi các khoản cho ${item.name} vay` : 'Thu hồi các khoản cho vay';
                                                        } else if (curType.includes('1. Thu tiền')) {
                                                            reasonText = item ? `Thu tiền của ${item.name}` : 'Thu tiền khách hàng';
                                                        } else if (curType.includes('5. Thu khác')) {
                                                            reasonText = item ? `Thu tiền của ${item.name}` : 'Thu khác';
                                                        } else {
                                                            const cleanName = curType.replace(/^\d+\.\s*/, '');
                                                            reasonText = item ? `${cleanName} của ${item.name}` : cleanName;
                                                        }
                                                        form.setFieldsValue({
                                                            contact_id: val,
                                                            contact_name: item?.name || '',
                                                            payer_name: item?.contact_person || item?.name || '',
                                                            payer_address: item?.address || '',
                                                            reason: reasonText
                                                        });
                                                        const curLines = form.getFieldValue('lines') || [];
                                                        form.setFieldsValue({
                                                            lines: curLines.map((l: any) => ({
                                                                ...l,
                                                                description: reasonText,
                                                                line_contact_id: item?.code || '',
                                                                line_contact_name: item?.name || ''
                                                            }))
                                                        });
                                                    }}
                                                    onQuickAdd={modalMode === 'view' ? undefined : () => setIsCustomerModalVisible(true)}
                                                />
                                            </Form.Item>
                                        </div>
                                        <div className="misa-col-7">
                                            <div className="misa-field-label">Tên đối tượng</div>
                                            <Form.Item name="contact_name" noStyle>
                                                <Input className="misa-input" placeholder="Tên đối tượng" />
                                            </Form.Item>
                                        </div>
                                    </>
                                )}

                                {/* Row 2: Người nộp & Địa chỉ */}
                                <div className="misa-col-5">
                                    <div className="misa-field-label">Người nộp</div>
                                    <Form.Item name="payer_name" noStyle>
                                        <Input className="misa-input" placeholder="Họ và tên người nộp tiền" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-7">
                                    <div className="misa-field-label">Địa chỉ</div>
                                    <Form.Item name="payer_address" noStyle>
                                        <Input className="misa-input" placeholder="Địa chỉ chi tiết" />
                                    </Form.Item>
                                </div>

                                {/* Row 3: Nhân viên / Lý do thu / Kèm theo */}
                                {voucherType.includes('2. Thu hoàn ứng') ? (
                                    <>
                                        <div className="misa-col-10">
                                            <div className="misa-field-label">Lý do nộp</div>
                                            <Form.Item name="reason" noStyle>
                                                <Input
                                                    className="misa-input"
                                                    suffix={<QrcodeOutlined className="misa-header-icon-qrcode" />}
                                                    placeholder="Lý do nộp tiền"
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
                                        <div className="misa-col-2">
                                            <div className="misa-field-label">Kèm theo</div>
                                            <div className="misa-flex-center misa-gap-6">
                                                <Form.Item name="attached_docs" noStyle>
                                                    <Input className="misa-input misa-input-w55" placeholder="Số lượng" />
                                                </Form.Item>
                                                <span className="misa-unit-label">chứng từ gốc</span>
                                            </div>
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <div className="misa-col-5">
                                            <div className="misa-field-label">Nhân viên</div>
                                            <div className="misa-input-group">
                                                <Form.Item name="employee_id" noStyle>
                                                    <Select
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
                                                    title="Thêm nhân viên"
                                                    disabled={modalMode === 'view'}
                                                    onClick={modalMode === 'view' ? undefined : () => setIsEmployeeModalVisible(true)}
                                                >
                                                    <PlusOutlined />
                                                </button>
                                            </div>
                                        </div>

                                        <div className="misa-col-5">
                                            <div className="misa-field-label">Lý do nộp</div>
                                            <Form.Item name="reason" noStyle>
                                                <Input
                                                    className="misa-input"
                                                    suffix={<QrcodeOutlined className="misa-header-icon-qrcode" />}
                                                    placeholder="Lý do nộp tiền"
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

                                        <div className="misa-col-2">
                                            <div className="misa-field-label">Kèm theo</div>
                                            <div className="misa-flex-center misa-gap-6">
                                                <Form.Item name="attached_docs" noStyle>
                                                    <Input className="misa-input misa-input-w55" placeholder="Số lượng" />
                                                </Form.Item>
                                                <span className="misa-unit-label">chứng từ gốc</span>
                                            </div>
                                        </div>
                                    </>
                                )}
                            </MisaMasterCard.FormGrid>

                            {/* Tham chiếu link */}
                            <div className="misa-ref-chip-container">
                                <span className="misa-ref-label">Tham chiếu:</span>
                                {referencedVouchers.map((v, idx) => (
                                    <Tag
                                        key={`ref-${v.voucher_number ?? idx}`}
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

                            <MisaMasterCard.MetaRow label="Ngày phiếu thu" required>
                                <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Số phiếu thu" required>
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
                                        const isThuHoanUng = vtStr.includes('2. Thu hoàn ứng');
                                        const isThuKhachHang = vtStr.includes('1. Thu tiền khách hàng');
                                        const isRutTienGui = vtStr.includes('3. Rút tiền gửi');
                                        const isLoanRelated = vtStr.includes('Thu hồi các khoản') || vtStr.includes('cho vay') || (Array.isArray(lines) ? lines : []).some((l: any) => String(l?.operation || '').includes('vay') || l?.loan_contract);
                                        const showLineContact = !isThuKhachHang && !isThuHoanUng && !isRutTienGui;
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
                                                                {isRutTienGui && <th className="misa-col-contact-code">TK ngân hàng</th>}
                                                                {isRutTienGui && <th>Tên ngân hàng</th>}
                                                                {showLineContact && <th className="misa-col-contact-code">Đối tượng</th>}
                                                                {showLineContact && <th>Tên đối tượng</th>}
                                                                {isLoanRelated && <th className="misa-col-contract">Khế ước cho vay</th>}
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
                                                                            <Form.Item
                                                                                name={[field.name, 'credit_account']}
                                                                                noStyle
                                                                                rules={[{ required: true, message: 'Chọn TK Có' }]}
                                                                            >
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
                                                                            <Form.Item name={[field.name, 'operation']} noStyle initialValue={isThuHoanUng ? 'Thu hoàn ứng' : (isRutTienGui ? 'Rút tiền gửi về nhập quỹ' : (isLoanRelated ? 'Thu hồi các khoản cho vay' : 'Thu tiền khách hàng'))}>
                                                                                <Select
                                                                                    showSearch
                                                                                    variant="borderless"
                                                                                    className="misa-w-full"
                                                                                    placeholder="Chọn nghiệp vụ..."
                                                                                    popupMatchSelectWidth={false}
                                                                                    options={[
                                                                                        { value: 'Thu tiền khách hàng', label: 'Thu tiền khách hàng' },
                                                                                        { value: 'Thu hoàn ứng', label: 'Thu hoàn ứng' },
                                                                                        { value: 'Thu hồi các khoản cho vay', label: 'Thu hồi các khoản cho vay' },
                                                                                        { value: 'Thu khác', label: 'Thu khác' }
                                                                                    ]}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        {isRutTienGui && (
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
                                                                        {isRutTienGui && (
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'line_contact_name']} noStyle>
                                                                                    <Input variant="borderless" className="misa-table-input" placeholder="Tên ngân hàng" />
                                                                                </Form.Item>
                                                                            </td>
                                                                        )}
                                                                        {showLineContact && (
                                                                            <td className="misa-col-contact-code">
                                                                                <Form.Item name={[field.name, 'line_contact_id']} noStyle>
                                                                                    <Select
                                                                                        showSearch
                                                                                        variant="borderless"
                                                                                        className="misa-w-full"
                                                                                        allowClear
                                                                                        placeholder="Mã ĐT"
                                                                                        options={customers?.map((c: any) => ({
                                                                                            value: c.code || c.id,
                                                                                            label: `${c.code} - ${c.name}`
                                                                                        }))}
                                                                                        onChange={(val) => {
                                                                                            const cust = customers?.find((c: any) => (c.code === val || c.id === val));
                                                                                            const curLines = form.getFieldValue('lines') || [];
                                                                                            if (curLines && curLines[field.name]) {
                                                                                                curLines[field.name].line_contact_name = cust?.name || '';
                                                                                                form.setFieldsValue({ lines: [...curLines] });
                                                                                            }
                                                                                        }}
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                        )}
                                                                        {showLineContact && (
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'line_contact_name']} noStyle>
                                                                                    <Input variant="borderless" className="misa-table-input" placeholder="Tên đối tượng" />
                                                                                </Form.Item>
                                                                            </td>
                                                                        )}
                                                                        {isLoanRelated && (
                                                                            <td className="misa-col-contract">
                                                                                <Form.Item name={[field.name, 'loan_contract']} noStyle>
                                                                                    <Input variant="borderless" className="misa-table-input" placeholder="Số khế ước..." />
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
                                                                <strong>{new Intl.NumberFormat('vi-VN').format(totals?.grandTotal || 0)} ₫</strong>
                                                            </td>
                                                            <td></td>
                                                            {isRutTienGui && <td></td>}
                                                            {isRutTienGui && <td></td>}
                                                            {showLineContact && <td></td>}
                                                            {showLineContact && <td></td>}
                                                            {isLoanRelated && <td></td>}
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
                                                                     const vt = String(voucherType || '');
                                                                     const found = activeVoucherTypes.find(t =>
                                                                         t.value === voucherType ||
                                                                         t.label === voucherType ||
                                                                         t.value.replace(/^\d+\.\s*/, '') === String(voucherType).replace(/^\d+\.\s*/, '')
                                                                     );
                                                                     const opName = found?.operation || (
                                                                        vt.includes('1. Thu tiền khách hàng') ? 'Thu tiền khách hàng' :
                                                                        vt.includes('2. Thu hoàn ứng') ? 'Thu hoàn ứng' :
                                                                        vt.includes('3. Rút tiền gửi') ? 'Rút tiền gửi về nhập quỹ' :
                                                                        vt.includes('Thu hồi các khoản') ? 'Thu hồi các khoản cho vay' : 'Thu khác'
                                                                    );
                                                                    add({
                                                                        description: form.getFieldValue('reason') || 'Thu tiền',
                                                                         amount: undefined,
                                                                        operation: opName,
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
                                                                Thêm dòng (F7)
                                                            </Button>
                                                            <Button
                                                                size="small"
                                                                icon={<DeleteOutlined />}
                                                                onClick={() => form.setFieldsValue({ lines: [] })}
                                                                className="misa-btn-footer-default"
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
                        reason: `Thu tiền của ${newContact.name}`
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
                    const newRefs = [...(Array.isArray(referencedVouchers) ? referencedVouchers : []), ...(Array.isArray(selected) ? selected : [])];
                    setReferencedVouchers(newRefs);
                    if (Array.isArray(selected) && selected.length > 0) {
                        const first = selected[0];
                        form.setFieldsValue({
                            reason: `Thu tiền theo ${selected.map(s => s?.voucher_number || '').join(', ')}`,
                            ...(first.contact_id || first.contact_code ? { contact_id: first.contact_id || first.contact_code } : {}),
                            ...(first.contact_name ? { contact_name: first.contact_name, payer_name: first.contact_name } : {})
                        });
                        // Auto-populate accounting lines matching referenced vouchers
                        const newLines = selected.map((v, idx) => ({
                            key: `${Date.now()}_${idx}`,
                            description: `Thu tiền theo ${v.voucher_type || ''} ${v.voucher_number || ''}`,
                            debit_account: v.debit_account,
                            credit_account: v.credit_account,
                            amount: readCashVoucherAmount(v.total_amount ?? v.amount),
                            operation: 'Thu tiền khách hàng',
                            line_contact_id: v.contact_id || '',
                            line_contact_name: v.contact_name || '',
                            invoice_id: v.real_id || v.id
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
                category="cash_receipt"
                onSuccess={(newReason: any) => {
                    setCustomVoucherTypes(prev => [...prev, newReason]);
                    form.setFieldValue('voucher_type', newReason.value);
                    handleVoucherTypeChange(newReason.value);
                }}
            />

            {/* MISA Standard Print Modal */}
            <VoucherPrintModal
                open={isPrintModalVisible}
                onCancel={() => setIsPrintModalVisible(false)}
                type="receipt"
                voucher={{
                    voucher_number: form.getFieldValue('voucher_number') || voucherNumber,
                    voucher_date: form.getFieldValue('voucher_date')?.format?.('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                    posting_date: form.getFieldValue('posting_date')?.format?.('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                    contact_name: form.getFieldValue('contact_name') || form.getFieldValue('payer_name') || '',
                    payer_name: form.getFieldValue('payer_name') || form.getFieldValue('contact_name') || '',
                    payer_address: form.getFieldValue('payer_address') || '',
                    reason: form.getFieldValue('reason') || '',
                    attached_docs: form.getFieldValue('attached_docs') || '',
                    total_amount: totals?.grandTotal || 0,
                    amount_in_words: readMoneyToVietnameseWords(totals?.grandTotal || 0),
                    currency: form.getFieldValue('currency') || 'VND',
                    lines: (form.getFieldValue('lines') || lines || []).filter(Boolean).map((l: any) => ({
                        description: l.description || form.getFieldValue('reason'),
                        debit_account: l.debit_account,
                        credit_account: l.credit_account,
                        amount: readCashVoucherAmount(l.amount)
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
        <PageShell title={<PageHeader eyebrow="Tiền mặt" title="Phiếu thu" description="Lập và quản lý các chứng từ thu tiền mặt." />}>
            {/* 3 Summary Cards */}
            <div className="misa-stat-grid-3">
                <div className="misa-stat-card">
                    <div className="misa-stat-label">Tổng thu đầu năm đến hiện tại</div>
                    <div className="misa-stat-value">
                        {formatCashVoucherAmount(totalReceipts)}
                    </div>
                    <div className="misa-stat-time">
                        Cập nhật: {currentTime}
                    </div>
                </div>

                <div className="misa-stat-card">
                    <div className="misa-stat-label">Tổng chi đầu năm đến hiện tại</div>
                    <div className="misa-stat-value">
                        {formatCashVoucherAmount(totalPayments)}
                    </div>
                    <div className="misa-stat-time">
                        Cập nhật: {currentTime}
                    </div>
                </div>

                <div className="misa-stat-card">
                    <div className="misa-stat-label">Tồn quỹ đến ngày hiện tại</div>
                    <div className="misa-stat-value text-blue-600">
                        {formatCashVoucherAmount(netBalance)}
                    </div>
                    <div className="misa-stat-time">
                        Cập nhật: {currentTime}
                    </div>
                </div>
            </div>

            {/* Filter Toolbar */}
            <PageToolbar
                filters={(
                <div className="ui-page-toolbar__filter-group">
                    <Select
                        value={listFilterType}
                        onChange={setListFilterType}
                        className="misa-w-130"
                        options={[
                            { value: 'all', label: 'Tất cả' },
                            { value: 'receipt', label: 'Thu tiền' },
                            { value: 'payment', label: 'Chi tiền' }
                        ]}
                    />

                    <Select
                        value={datePreset}
                        onChange={setDatePreset}
                        className="misa-w-160"
                        options={[
                            { value: 'Đầu năm tới hiện tại', label: 'Đầu năm tới hiện tại' },
                            { value: 'Hôm nay', label: 'Hôm nay' },
                            { value: 'Tuần này', label: 'Tuần này' },
                            { value: 'Tháng này', label: 'Tháng này' },
                            { value: 'Quý này', label: 'Quý này' },
                            { value: 'Cả năm', label: 'Cả năm' },
                            { value: 'Tùy chọn', label: 'Tùy chọn' }
                        ]}
                    />

                    {datePreset === 'Tùy chọn' && (
                        <DatePicker.RangePicker
                            value={dateRange}
                            onChange={(dates: any) => setDateRange(dates)}
                            format="DD/MM/YYYY"
                        />
                    )}
                </div>
                )}
                actions={(
                <div className="ui-page-toolbar__action-group">
                    <Input
                        placeholder="Tìm theo số CT, đối tượng, lý do..."
                        prefix={<SearchOutlined className="misa-color-muted" />}
                        className="misa-w-240"
                        value={searchText}
                        onChange={e => setSearchText(e.target.value)}
                        allowClear
                    />

                    <Button
                        icon={<ReloadOutlined />}
                        onClick={() => {
                            queryClient.invalidateQueries({ queryKey: ['cash-receipts'] });
                            queryClient.invalidateQueries({ queryKey: ['cash-payments'] });
                            message.success('Đã làm mới dữ liệu!');
                        }}
                        title="Nạp lại dữ liệu"
                        className="misa-btn-tool"
                    />

                    <Button
                        icon={<ExportOutlined />}
                        title="Xuất khẩu Excel"
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
                            onClick={() => handleOpenModal()}
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
                            onClick={() => window.dispatchEvent(new Event('open-cash-payment'))}
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
                {isReceiptsError && (
                    <Alert
                        className="m-3"
                        type="error"
                        showIcon
                        message="Không thể tải danh sách phiếu thu"
                        description="Máy chủ không trả về danh sách phiếu thu hợp lệ; không thay thế bằng dữ liệu rỗng."
                        action={<Button size="small" onClick={() => void refetchReceipts()}>Thử lại danh sách phiếu thu</Button>}
                    />
                )}
                {isPaymentsError && (
                    <Alert
                        className="m-3"
                        type="warning"
                        showIcon
                        message="Không thể tải danh sách phiếu chi"
                        description="Tổng thu–chi và tồn quỹ chưa thể xác minh cho đến khi tải lại được danh sách phiếu chi."
                        action={<Button size="small" onClick={() => void refetchPayments()}>Thử lại danh sách phiếu chi</Button>}
                    />
                )}
                <Table
                    columns={columns}
                    dataSource={filteredReceipts}
                    rowKey="id"
                    loading={isLoading}
                    size="small"
                    bordered
                    rowSelection={{ type: 'checkbox' }}
                    className="misa-voucher-table"
                    pagination={{
                        pageSize: listPageSize,
                        showSizeChanger: true,
                        pageSizeOptions: ['20', '50', '100'],
                        onShowSizeChange: (_, size) => setListPageSize(size),
                        showTotal: (total, range) => (
                            <div style={{ display: 'flex', gap: 16 }}>
                                <span>Tổng số: <strong>{total}</strong> chứng từ ({range[0]}-{range[1]})</span>
                                <span>Tổng tiền: <strong>{formatCashVoucherAmount(listedReceiptTotal)}</strong></span>
                            </div>
                        )
                    }}
                    scroll={{ x: 'max-content', y: 'calc(100vh - 360px)' }}
                    locale={{ emptyText: isReceiptsError ? 'Không thể xác định dữ liệu phiếu thu' : (
                        <div className="misa-empty-box">
                            <InboxOutlined className="misa-empty-icon" />
                            Không có dữ liệu
                        </div>
                    ) }}
                />
            </DataTableSurface>

            {modalsContent}
        </PageShell>
    );
});

export default CashReceipts;

