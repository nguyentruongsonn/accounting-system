import React, { useState, useEffect, useCallback } from 'react';
import { Alert, Button, Form } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import api from '../../../api/axios';
import {
    MisaVoucherModal,
    MisaGridToolbar,
    MisaTableSummaryBar,
    ReferenceVoucherModal,
    QuickAddContactModal,
    QuickAddEmployeeModal,
    QuickAddItemModal,
    useVoucherShortcuts,
    useVoucherTotals,
    useVoucherLines,
    useAutoVoucherNumber
} from '../../../components/misa';
import { SalesReturnGrid } from '../components/SalesReturnGrid';
import { SalesReturnMasterCard } from '../components/SalesReturnMasterCard';
import type {
    SalesReturnRecord,
    SalesReturnLine,
    SalesPaymentMethod,
    CustomerOption,
    EmployeeOption,
    InventoryItemOption
} from '../types';

export interface SalesReturnModalProps {
    open: boolean;
    onClose: () => void;
    recordId?: number | null;
    initialRecord?: SalesReturnRecord | null;
    isViewMode?: boolean;
    onSuccess?: () => void;
}

const parseListPayload = <T,>(payload: unknown, label: string): T[] => {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: T[] }).data;
    }
    throw new Error(`Phản hồi ${label} không hợp lệ`);
};

export const SalesReturnModal: React.FC<SalesReturnModalProps> = ({
    open,
    onClose,
    recordId,
    initialRecord,
    isViewMode = false,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const [activeTab, setActiveTab] = useState<'accounting' | 'tax' | 'cogs' | 'statistic'>('accounting');
    const [paymentMethod, setPaymentMethod] = useState<SalesPaymentMethod>('reduce_receivable');
    const [isInward, setIsInward] = useState<boolean>(true);
    const [isRefModalOpen, setIsRefModalOpen] = useState<boolean>(false);
    const [isAddCustomerOpen, setIsAddCustomerOpen] = useState<boolean>(false);
    const [isAddEmployeeOpen, setIsAddEmployeeOpen] = useState<boolean>(false);
    const [isAddItemOpen, setIsAddItemOpen] = useState<boolean>(false);
    const [activeRowIndex, setActiveRowIndex] = useState<number | null>(null);

    const { voucherNumber, refreshVoucherNumber } = useAutoVoucherNumber({
        prefix: 'TLHB',
        endpoint: '/sales/returns/next-code',
        enabled: open && !recordId
    });

    const customersQuery = useQuery<CustomerOption[]>({
        queryKey: ['customers'],
        queryFn: async () => {
            const { data } = await api.get('/master/customers');
            return parseListPayload<CustomerOption>(data, 'khách hàng');
        },
        enabled: open
    });
    const { data: customers = [], isError: customersLoadError } = customersQuery;

    const employeesQuery = useQuery<EmployeeOption[]>({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parseListPayload<EmployeeOption>(data, 'nhân viên');
        },
        enabled: open
    });
    const { data: employees = [], isError: employeesLoadError } = employeesQuery;

    const itemsQuery = useQuery<InventoryItemOption[]>({
        queryKey: ['inventory-items'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parseListPayload<InventoryItemOption>(data, 'hàng hóa');
        },
        enabled: open
    });
    const { data: items = [], isError: itemsLoadError } = itemsQuery;

    const lookupError = customersLoadError || employeesLoadError || itemsLoadError;
    const retryLookups = () => {
        void Promise.all([customersQuery.refetch(), employeesQuery.refetch(), itemsQuery.refetch()]);
    };

    const defaultLineFactory = useCallback((): SalesReturnLine => {
        return {
            key: `line-${Date.now()}`,
            description: form.getFieldValue('description') || undefined
        };
    }, [form]);

    const {
        lines,
        addLine,
        updateLine,
        removeLine,
        removeAllLines,
        resetLines
    } = useVoucherLines<SalesReturnLine>({
        initialLines: [],
        defaultLineFactory
    });

    const totals = useVoucherTotals(lines);

    const syncFormAndLines = useCallback((record: Partial<SalesReturnRecord>) => {
        const method = record.payment_method || 'reduce_receivable';
        setPaymentMethod(method);
        setIsInward(record.is_inward !== false);

        form.setFieldsValue({
            customer_id: record.customer_id,
            customer_name: record.customer_name || record.customer?.name,
            customer_address: record.customer_address || record.customer?.address,
            tax_code: record.tax_code || record.customer?.tax_code,
            receiver_name: record.receiver_name || record.customer?.contact_name,
            reason: record.reason || record.description || 'Hàng bán trả lại',
            description: record.description || record.reason || 'Hàng bán trả lại',
            employee_id: record.employee_id,
            voucher_number: record.voucher_number || voucherNumber,
            accounting_date: record.accounting_date ? dayjs(record.accounting_date) : dayjs(),
            voucher_date: record.voucher_date ? dayjs(record.voucher_date) : dayjs(),
            reference_invoice_id: record.reference_invoice_id
        });

        if (record.lines && record.lines.length > 0) {
            resetLines(record.lines);
        } else {
            resetLines([defaultLineFactory()]);
        }
    }, [form, voucherNumber, defaultLineFactory, resetLines]);

    useEffect(() => {
        if (!open) return;
        if (recordId && initialRecord) {
            syncFormAndLines(initialRecord);
        } else if (open && !recordId) {
            form.resetFields();
            form.setFieldsValue({
                voucher_number: voucherNumber,
                accounting_date: dayjs(),
                voucher_date: dayjs(),
                reason: 'Hàng bán trả lại do lỗi chất lượng',
                description: 'Hàng bán trả lại do lỗi chất lượng'
            });
            setPaymentMethod('reduce_receivable');
            setIsInward(true);
            resetLines([defaultLineFactory()]);
        }
    }, [open, recordId, initialRecord, voucherNumber, defaultLineFactory, resetLines, syncFormAndLines, form]);

    const handlePaymentMethodChange = (method: SalesPaymentMethod) => {
        setPaymentMethod(method);
        lines.forEach((_, idx) => {
            // Changing settlement method can change the required mapping.
            // Clear the prior evidence instead of silently reusing or
            // inventing an account; the user must select an approved account.
            updateLine(idx, { credit_account: undefined });
        });
    };

    const handleItemChange = (index: number, itemId: number, explicitItem?: any) => {
        const item = explicitItem || items.find(it => it.id === itemId);
        if (!item) return;

        const qty = Number(lines[index]?.quantity) || 1;
        const price = Number(item.sale_price) || 0;
        const taxRate = item.tax_rate ?? 10;
        const amt = Number.isFinite(qty) && Number.isFinite(price) ? qty * price : undefined;
        const taxAmt = amt !== undefined && taxRate !== undefined ? (amt * taxRate) / 100 : undefined;
        const cost = Number(item.cost_price) || 0;
        const cogsAmt = Number.isFinite(qty) && Number.isFinite(cost) ? qty * cost : undefined;

        updateLine(index, {
            item_id: item.id,
            item_code: item.code,
            item_name: item.name,
            unit: item.unit || 'Cái',
            quantity: qty,
            unit_price: price,
            amount: amt,
            tax_rate: taxRate,
            tax_amount: taxAmt,
            debit_account: lines[index]?.debit_account,
            credit_account: lines[index]?.credit_account,
            tax_account: lines[index]?.tax_account,
            cogs_debit_account: lines[index]?.cogs_debit_account,
            cogs_credit_account: lines[index]?.cogs_credit_account,
            cogs_unit_price: cost,
            cogs_amount: cogsAmt,
        });
    };

    const handleCustomerChange = (_val: unknown, customerItem?: CustomerOption) => {
        if (!customerItem) return;
        form.setFieldsValue({
            customer_id: customerItem.id,
            customer_name: customerItem.name,
            customer_address: customerItem.address || '',
            tax_code: customerItem.tax_code || '',
            receiver_name: customerItem.contact_name || customerItem.name,
            description: `Hàng bán trả lại từ ${customerItem.name}`
        });
    };

    const mutation = useMutation({
        mutationFn: async (andNew: boolean) => {
            const values = await form.validateFields();
            const payload = {
                customer_id: values.customer_id,
                customer_name: values.customer_name,
                customer_address: values.customer_address,
                tax_code: values.tax_code,
                receiver_name: values.receiver_name,
                reason: values.reason || values.description,
                description: values.description || values.reason,
                employee_id: values.employee_id,
                payment_method: paymentMethod,
                is_inward: isInward,
                voucher_number: values.voucher_number,
                voucher_date: dayjs(values.voucher_date).format('YYYY-MM-DD'),
                accounting_date: dayjs(values.accounting_date).format('YYYY-MM-DD'),
                reference_invoice_id: values.reference_invoice_id || null,
                lines: (lines || []).map(l => ({
                    item_id: l.item_id,
                    item_code: l.item_code,
                    description: l.description || values.description,
                    unit: l.unit,
                    quantity: l.quantity,
                    unit_price: l.unit_price,
                    amount: l.amount,
                    debit_account: l.debit_account,
                    credit_account: l.credit_account,
                    tax_rate: l.tax_rate,
                    tax_amount: l.tax_amount,
                    tax_account: l.tax_account,
                    warehouse_id: l.warehouse_id,
                    cogs_debit_account: l.cogs_debit_account,
                    cogs_credit_account: l.cogs_credit_account,
                    cogs_unit_price: l.cogs_unit_price,
                    cogs_amount: l.cogs_amount,
                    order_reference: l.order_reference,
                    contract_reference: l.contract_reference,
                    expense_item_code: l.expense_item_code
                }))
            };

            const res = recordId
                ? await api.put(`/sales/returns/${recordId}`, payload)
                : await api.post('/sales/returns', payload);
            return { res, andNew };
        },
        onSuccess: ({ res, andNew }) => {
            const persistedReturn = res?.data?.data ?? res?.data;
            if (!persistedReturn || persistedReturn.id === undefined || persistedReturn.id === null) {
                message.error('Máy chủ không trả về chứng từ hàng bán trả lại đã lưu; không thể báo thành công.');
                return;
            }
            message.success(recordId ? 'Cập nhật chứng từ hàng bán trả lại thành công!' : 'Tạo chứng từ hàng bán trả lại thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-returns'] });
            onSuccess?.();
            if (andNew) {
                form.resetFields();
                refreshVoucherNumber();
                resetLines([defaultLineFactory()]);
            } else {
                onClose();
            }
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.message || apiErr?.response?.data?.error || 'Có lỗi xảy ra khi lưu chứng từ!');
        }
    });

    useVoucherShortcuts({
        onSave: () => !isViewMode && mutation.mutate(false),
        onSaveAndNew: () => !isViewMode && mutation.mutate(true),
        onAddLine: () => !isViewMode && addLine(),
        onDeleteLine: () => !isViewMode && lines.length > 0 && removeLine(lines.length - 1),
        onClose,
        enabled: open
    });

    const gridTabs = [
        { key: 'accounting', label: '1. Hạch toán' },
        { key: 'tax', label: '2. Thuế' },
        { key: 'cogs', label: '3. Giá vốn', hidden: !isInward },
        { key: 'statistic', label: '4. Thống kê' },
    ];

    return (
        <MisaVoucherModal
            open={open}
            onCancel={onClose}
            title="Chứng từ hàng bán trả lại"
            voucherCode={form.getFieldValue('voucher_number') || voucherNumber}
            onSave={!isViewMode ? () => mutation.mutate(false) : undefined}
            onSaveAndPrint={!isViewMode ? () => mutation.mutate(false) : undefined}
            isSaving={mutation.isPending}
        >
            {lookupError && (
                <Alert
                    type="error"
                    showIcon
                    message="Không thể tải danh mục cho phiếu hàng bán trả lại"
                    description="Danh sách khách hàng, nhân viên hoặc hàng hóa chưa tải được. Dữ liệu đã tải trước đó vẫn được giữ lại; hãy thử lại."
                    action={<Button size="small" onClick={retryLookups}>Thử lại</Button>}
                    className="misa-mb-12"
                />
            )}
            <Form form={form} layout="vertical" disabled={isViewMode} size="small">
                {/* Modular Master Card */}
                <SalesReturnMasterCard
                    form={form}
                    paymentMethod={paymentMethod}
                    isInward={isInward}
                    isViewMode={isViewMode}
                    customers={customers}
                    employees={employees}
                    grandTotal={totals.grandTotal}
                    onPaymentMethodChange={handlePaymentMethodChange}
                    onIsInwardChange={setIsInward}
                    onCustomerChange={handleCustomerChange}
                    onOpenAddCustomer={() => setIsAddCustomerOpen(true)}
                    onOpenAddEmployee={() => setIsAddEmployeeOpen(true)}
                    onOpenRefModal={() => setIsRefModalOpen(true)}
                />

                {/* Detail Grid */}
                <div className="misa-mt-12">
                    <MisaGridToolbar
                        tabs={gridTabs}
                        activeTab={activeTab}
                        onTabChange={key => setActiveTab(key as 'accounting' | 'tax' | 'cogs' | 'statistic')}
                    />

                    <SalesReturnGrid
                        activeTab={activeTab}
                        lines={lines}
                        items={items}
                        disabled={isViewMode}
                        onItemChange={handleItemChange}
                        onUpdateLine={updateLine}
                        onRemoveLine={removeLine}
                        onAddLine={addLine}
                        onRemoveAllLines={removeAllLines}
                        onQuickAddItem={(idx: number) => {
                            setActiveRowIndex(idx);
                            setIsAddItemOpen(true);
                        }}
                    />
                </div>

                {/* Summary Metrics Bar */}
                <div className="misa-mt-12">
                    <MisaTableSummaryBar
                        items={[
                            { label: 'Tổng số lượng', value: totals.totalQuantity, format: 'number' },
                            { label: 'Tiền hàng', value: totals.subTotal, format: 'currency' },
                            { label: 'Tiền thuế GTGT', value: totals.totalTax, format: 'currency' },
                            { label: 'Tổng thanh toán giảm trừ', value: totals.grandTotal, format: 'currency', highlight: true }
                        ]}
                        lineCount={lines.length}
                    />
                </div>
            </Form>

            {/* Quick Add Modals */}
            <QuickAddContactModal
                open={isAddCustomerOpen}
                onCancel={() => setIsAddCustomerOpen(false)}
                contactType="customer"
                onSuccess={() => queryClient.invalidateQueries({ queryKey: ['customers'] })}
            />

            <QuickAddEmployeeModal
                open={isAddEmployeeOpen}
                onCancel={() => setIsAddEmployeeOpen(false)}
                onSuccess={(newEmp: any) => {
                    queryClient.invalidateQueries({ queryKey: ['employees'] });
                    if (newEmp?.id) {
                        form.setFieldsValue({ employee_id: newEmp.id });
                    }
                }}
            />

            <QuickAddItemModal
                open={isAddItemOpen}
                onCancel={() => {
                    setIsAddItemOpen(false);
                    setActiveRowIndex(null);
                }}
                onSuccess={(newItem: any) => {
                    setIsAddItemOpen(false);
                    queryClient.invalidateQueries({ queryKey: ['inventory-items'] });
                    if (activeRowIndex !== null && newItem?.id) {
                        handleItemChange(activeRowIndex, newItem.id, newItem);
                    }
                    setActiveRowIndex(null);
                    message.success(`Đã thêm nhanh vật tư hàng hóa: ${newItem.name || newItem.code}`);
                }}
            />

            <ReferenceVoucherModal
                open={isRefModalOpen}
                onCancel={() => setIsRefModalOpen(false)}
                onSelect={(selected) => {
                    if (selected && selected.length > 0) {
                        form.setFieldsValue({ reference_invoice_id: selected[0].id });
                        message.success(`Đã chọn chứng từ tham chiếu: ${selected[0].voucher_number}`);
                    }
                    setIsRefModalOpen(false);
                }}
            />
        </MisaVoucherModal>
    );
};

export default SalesReturnModal;
