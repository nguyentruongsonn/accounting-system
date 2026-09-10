import React, { useState, useEffect, useCallback } from 'react';
import { Alert, Form, Button } from 'antd';
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
import { SalesDiscountGrid } from '../components/SalesDiscountGrid';
import { SalesDiscountMasterCard } from '../components/SalesDiscountMasterCard';
import type {
    SalesDiscountRecord,
    SalesDiscountLine,
    SalesPaymentMethod,
    CustomerOption,
    EmployeeOption,
    InventoryItemOption
} from '../types';

export interface SalesDiscountModalProps {
    open: boolean;
    onClose: () => void;
    recordId?: number | null;
    initialRecord?: SalesDiscountRecord | null;
    isViewMode?: boolean;
    onSuccess?: () => void;
}

function parseSalesDiscountCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: T[] }).data;
    }
    throw new Error(`Invalid ${resource} response.`);
}

export const SalesDiscountModal: React.FC<SalesDiscountModalProps> = ({
    open,
    onClose,
    recordId,
    initialRecord,
    isViewMode = false,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const [activeTab, setActiveTab] = useState<'accounting' | 'tax' | 'statistic'>('accounting');
    const [paymentMethod, setPaymentMethod] = useState<SalesPaymentMethod>('reduce_receivable');
    const [isRefModalOpen, setIsRefModalOpen] = useState<boolean>(false);
    const [isAddCustomerOpen, setIsAddCustomerOpen] = useState<boolean>(false);
    const [isAddEmployeeOpen, setIsAddEmployeeOpen] = useState<boolean>(false);
    const [isAddItemOpen, setIsAddItemOpen] = useState<boolean>(false);
    const [activeRowIndex, setActiveRowIndex] = useState<number | null>(null);

    const { voucherNumber, refreshVoucherNumber } = useAutoVoucherNumber({
        prefix: 'GGHB',
        endpoint: '/sales/discounts/next-code',
        enabled: open && !recordId
    });

    const {
        data: customers = [],
        isError: isCustomersError,
        refetch: refetchCustomers,
    } = useQuery<CustomerOption[]>({
        queryKey: ['customers'],
        queryFn: async () => {
            const { data } = await api.get('/master/customers');
            return parseSalesDiscountCollection<CustomerOption>(data, 'customers');
        },
        enabled: open
    });

    const {
        data: employees = [],
        isError: isEmployeesError,
        refetch: refetchEmployees,
    } = useQuery<EmployeeOption[]>({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parseSalesDiscountCollection<EmployeeOption>(data, 'employees');
        },
        enabled: open
    });

    const {
        data: items = [],
        isError: isItemsError,
        refetch: refetchItems,
    } = useQuery<InventoryItemOption[]>({
        queryKey: ['inventory-items'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parseSalesDiscountCollection<InventoryItemOption>(data, 'inventory items');
        },
        enabled: open
    });

    const defaultLineFactory = useCallback((): SalesDiscountLine => {
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
    } = useVoucherLines<SalesDiscountLine>({
        initialLines: [],
        defaultLineFactory
    });

    const totals = useVoucherTotals(lines);

    const syncFormAndLines = useCallback((record: Partial<SalesDiscountRecord>) => {
        const method = record.payment_method || 'reduce_receivable';
        setPaymentMethod(method);

        form.setFieldsValue({
            customer_id: record.customer_id,
            customer_name: record.customer_name || record.customer?.name,
            customer_address: record.customer_address || record.customer?.address,
            tax_code: record.tax_code || record.customer?.tax_code,
            receiver_name: record.receiver_name || record.customer?.contact_name,
            reason: record.reason || record.description || 'Giảm giá hàng bán',
            description: record.description || record.reason || 'Giảm giá hàng bán',
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
                reason: 'Giảm giá hàng bán theo chính sách khuyến mãi',
                description: 'Giảm giá hàng bán theo chính sách khuyến mãi'
            });
            setPaymentMethod('reduce_receivable');
            resetLines([defaultLineFactory()]);
        }
    }, [open, recordId, initialRecord, voucherNumber, defaultLineFactory, resetLines, syncFormAndLines, form]);

    const handlePaymentMethodChange = (method: SalesPaymentMethod) => {
        setPaymentMethod(method);
        lines.forEach((_, idx) => {
            updateLine(idx, { credit_account: undefined });
        });
    };

    const handleItemChange = (index: number, itemId: number, explicitItem?: any) => {
        const item = explicitItem || items.find(it => it.id === itemId);
        if (!item) return;

        const qty = Number(lines[index]?.quantity) || 1;
        const salePrice = Number(item.sale_price);
        const discountPrice = Number.isFinite(salePrice) ? salePrice * 0.05 : undefined;
        const taxRate = item.tax_rate ?? 10;
        const amt = Number.isFinite(qty) && discountPrice !== undefined ? qty * discountPrice : undefined;
        const taxAmt = amt !== undefined && taxRate !== undefined ? (amt * taxRate) / 100 : undefined;

        updateLine(index, {
            item_id: item.id,
            item_code: item.code,
            item_name: item.name,
            unit: item.unit,
            quantity: qty,
            unit_price: discountPrice,
            amount: amt,
            tax_rate: taxRate,
            tax_amount: taxAmt,
            debit_account: lines[index]?.debit_account,
            credit_account: lines[index]?.credit_account,
            tax_account: lines[index]?.tax_account,
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
            description: `Giảm giá hàng bán cho ${customerItem.name}`
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
                    order_reference: l.order_reference,
                    contract_reference: l.contract_reference,
                    expense_item_code: l.expense_item_code
                }))
            };

            const res = recordId
                ? await api.put(`/sales/discounts/${recordId}`, payload)
                : await api.post('/sales/discounts', payload);
            return { res, andNew };
        },
        onSuccess: ({ res, andNew }) => {
            const persistedDiscount = res?.data?.data ?? res?.data;
            if (!persistedDiscount || persistedDiscount.id === undefined || persistedDiscount.id === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá hàng bán đã lưu; không thể báo thành công.');
                return;
            }
            message.success(recordId ? 'Cập nhật chứng từ giảm giá hàng bán thành công!' : 'Tạo chứng từ giảm giá hàng bán thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-discounts'] });
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
        { key: 'statistic', label: '3. Thống kê' },
    ];

    return (
        <MisaVoucherModal
            open={open}
            onCancel={onClose}
            title="Chứng từ giảm giá hàng bán"
            voucherCode={form.getFieldValue('voucher_number') || voucherNumber}
            onSave={!isViewMode ? () => mutation.mutate(false) : undefined}
            onSaveAndPrint={!isViewMode ? () => mutation.mutate(false) : undefined}
            isSaving={mutation.isPending}
        >
            {(isCustomersError || isEmployeesError || isItemsError) && (
                <div className="misa-modal-data-errors misa-flex-col misa-gap-8 misa-bottom-8">
                    {isCustomersError && (
                        <Alert
                            type="error"
                            showIcon
                            title="Không thể tải danh mục khách hàng"
                            action={<Button size="small" onClick={() => void refetchCustomers()}>Thử lại danh mục khách hàng</Button>}
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
                </div>
            )}
            <Form form={form} layout="vertical" disabled={isViewMode} size="small">
                {/* Modular Master Card */}
                <SalesDiscountMasterCard
                    form={form}
                    paymentMethod={paymentMethod}
                    isViewMode={isViewMode}
                    customers={customers}
                    employees={employees}
                    grandTotal={totals.grandTotal}
                    onPaymentMethodChange={handlePaymentMethodChange}
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
                        onTabChange={key => setActiveTab(key as 'accounting' | 'tax' | 'statistic')}
                    />

                    <SalesDiscountGrid
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
                            { label: 'Tiền giảm giá', value: totals.subTotal, format: 'currency' },
                            { label: 'Tiền thuế GTGT giảm', value: totals.totalTax, format: 'currency' },
                            { label: 'Tổng thanh toán giảm giá', value: totals.grandTotal, format: 'currency', highlight: true }
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

export default SalesDiscountModal;
