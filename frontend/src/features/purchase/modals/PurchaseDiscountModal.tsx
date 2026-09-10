import React, { useState, useEffect, useCallback } from 'react';
import { Alert, Form, Input, Select, DatePicker, Radio, Table, InputNumber, Tabs, Button, Space, Switch } from 'antd';
import Modal from '../../../components/layout/AppModal';
import ModalFrame from '../../../components/layout/ModalFrame';
import { toast as message } from '../../../components/feedback/toast';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { 
    DeleteOutlined,
    PlusOutlined,
    LinkOutlined,
    CloseOutlined,
    SettingOutlined,
    EditOutlined
} from '@ant-design/icons';
import api from '../../../api/axios';
import { AdaptiveSelect } from '../../../components/layout/AdaptiveSelect';
import {
    MultiColumnContactSelect,
    MisaMasterCard,
    MisaTotalCard,
    MisaTableSummaryBar,
    MisaGridActionFooter,
    AccountSelect,
    QuickAddItemModal,
    ReferenceVoucherModal,
    QuickAddContactModal,
    QuickAddEmployeeModal,
    useVoucherShortcuts,
    useVoucherTotals,
    useVoucherLines,
    useAutoVoucherNumber
} from '../../../components/misa';
import type {
    PurchaseDiscountRecord,
    PurchaseDiscountLine,
    PurchasePaymentMethod,
    SupplierOption,
    EmployeeOption,
    InventoryItemOption
} from '../types';

export interface PurchaseDiscountModalProps {
    open: boolean;
    onClose: () => void;
    recordId?: number | null;
    initialRecord?: PurchaseDiscountRecord | null;
    isViewMode?: boolean;
    onSuccess?: () => void;
}

function parsePurchaseDiscountCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: T[] }).data;
    }
    throw new Error(`Invalid ${resource} response.`);
}

export const PurchaseDiscountModal: React.FC<PurchaseDiscountModalProps> = ({
    open,
    onClose,
    recordId,
    initialRecord,
    isViewMode = false,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const [showAccounts, setShowAccounts] = useState<boolean>(true);
    const [isInternalViewMode, setIsInternalViewMode] = useState<boolean>(isViewMode);

    useEffect(() => {
        setIsInternalViewMode(isViewMode);
    }, [isViewMode]);

    const [activeTab, setActiveTab] = useState<'accounting' | 'tax' | 'statistic'>('accounting');
    const [paymentMethod, setPaymentMethod] = useState<PurchasePaymentMethod>('reduce_payable');
    const [isRefModalOpen, setIsRefModalOpen] = useState<boolean>(false);
    const [isAddSupplierOpen, setIsAddSupplierOpen] = useState<boolean>(false);
    const [isAddEmployeeOpen, setIsAddEmployeeOpen] = useState<boolean>(false);
    const [isAddItemOpen, setIsAddItemOpen] = useState<boolean>(false);
    const [activeRowIndex, setActiveRowIndex] = useState<number | null>(null);


    const {
        data: accounts = [],
    } = useQuery({
        queryKey: ['accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            const rows = parsePurchaseDiscountCollection<any>(data, 'accounts');
            return rows.filter((account: any) => account && account.is_parent !== true && account.is_active !== false);
        },
        enabled: open
    });

    const { voucherNumber } = useAutoVoucherNumber({
        prefix: 'GGMH',
        endpoint: '/purchase/discounts/next-code',
        enabled: open && !recordId
    });

    const {
        data: suppliers = [],
        isError: isSuppliersError,
        refetch: refetchSuppliers,
    } = useQuery<SupplierOption[]>({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parsePurchaseDiscountCollection<SupplierOption>(data, 'suppliers');
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
            return parsePurchaseDiscountCollection<EmployeeOption>(data, 'employees');
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
            return parsePurchaseDiscountCollection<InventoryItemOption>(data, 'inventory items');
        },
        enabled: open
    });

    const defaultLineFactory = useCallback((): PurchaseDiscountLine => {
        return {
            key: `line-${Date.now()}`,
            description: form.getFieldValue('description') || undefined
        };
    }, [form]);

    const { lines, setLines, addLine, removeLine, updateLine } = useVoucherLines<PurchaseDiscountLine>({
        initialLines: [],
        defaultLineFactory: defaultLineFactory
    });

    const totals = useVoucherTotals(lines);

    useEffect(() => {
        if (!open) return;

        if (recordId && initialRecord) {
            form.setFieldsValue({
                voucher_number: initialRecord.voucher_number,
                voucher_date: initialRecord.voucher_date ? dayjs(initialRecord.voucher_date) : dayjs(),
                accounting_date: initialRecord.accounting_date ? dayjs(initialRecord.accounting_date) : dayjs(),
                supplier_id: initialRecord.supplier_id,
                supplier_name: initialRecord.supplier_name,
                supplier_address: initialRecord.supplier_address,
                tax_code: initialRecord.tax_code,
                deliverer_name: initialRecord.deliverer_name,
                receiver_name: initialRecord.receiver_name,
                employee_id: initialRecord.employee_id,
                reason: initialRecord.reason,
                description: initialRecord.description,
                payment_method: initialRecord.payment_method || 'reduce_payable',
                bank_account_id: initialRecord.bank_account_id,
            });
            setPaymentMethod(initialRecord.payment_method || 'reduce_payable');

            if (initialRecord.lines && initialRecord.lines.length > 0) {
                setLines(initialRecord.lines.map((l, idx) => ({
                    ...l,
                    key: `line-${l.id || idx}`
                })));
            } else {
                setLines([defaultLineFactory()]);
            }
        } else {
            form.resetFields();
            form.setFieldsValue({
                voucher_number: voucherNumber || undefined,
                voucher_date: dayjs(),
                accounting_date: dayjs(),
                payment_method: 'reduce_payable',
                description: 'Giảm giá hàng mua từ nhà cung cấp'
            });
            setPaymentMethod('reduce_payable');
            setLines([defaultLineFactory()]);
        }
    }, [open, recordId, initialRecord, voucherNumber, defaultLineFactory, form, setLines]);

    const saveMutation = useMutation({
        mutationFn: async (payload: unknown) => {
            if (recordId) {
                return api.put(`/purchase/discounts/${recordId}`, payload);
            }
            return api.post('/purchase/discounts', payload);
        },
        onSuccess: (response) => {
            const persistedDiscount = response?.data?.data ?? response?.data;
            if (!persistedDiscount || persistedDiscount.id === undefined || persistedDiscount.id === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã lưu; không thể báo thành công.');
                return;
            }
            message.success(recordId ? 'Cập nhật chứng từ giảm giá thành công!' : 'Tạo mới chứng từ giảm giá hàng mua thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-discounts'] });
            if (onSuccess) onSuccess();
            onClose();
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Có lỗi xảy ra khi lưu chứng từ!');
        }
    });

    const handleSave = async (autoPost = false) => {
        try {
            if (paymentMethod === 'bank') {
                message.info('Thu tiền gửi/ngân hàng nằm ngoài phạm vi nội bộ; chứng từ cũ chỉ được xem.');
                return;
            }
            const values = await form.validateFields();
            if (!lines || lines.length === 0) {
                message.error('Vui lòng nhập ít nhất một dòng chi tiết!');
                return;
            }

            const payload = {
                ...values,
                voucher_date: values.voucher_date ? values.voucher_date.format('YYYY-MM-DD') : dayjs().format('YYYY-MM-DD'),
                accounting_date: values.accounting_date ? values.accounting_date.format('YYYY-MM-DD') : dayjs().format('YYYY-MM-DD'),
                payment_method: paymentMethod,
                sub_total: totals.subTotal,
                tax_amount: totals.totalTax,
                total_amount: totals.grandTotal,
                grand_total: totals.grandTotal,
                is_posted: autoPost,
                status: autoPost ? 'posted' : 'draft',
                lines: lines.map((l, index) => ({
                    line_order: index + 1,
                    item_id: l.item_id,
                    item_code: l.item_code,
                    item_name: l.item_name,
                    description: l.description,
                    unit: l.unit,
                    debit_account: l.debit_account,
                    credit_account: l.credit_account,
                    quantity: l.quantity,
                    unit_price: l.unit_price,
                    amount: l.amount,
                    discount_amount: l.discount_amount,
                    tax_rate: l.tax_rate,
                    tax_amount: l.tax_amount,
                    tax_account: l.tax_account,
                    invoice_number: l.invoice_number,
                    invoice_date: l.invoice_date,
                    order_id: l.order_id,
                    contract_id: l.contract_id
                }))
            };

            await saveMutation.mutateAsync(payload);
        } catch {
            message.error('Vui lòng kiểm tra lại các trường bắt buộc!');
        }
    };

    useVoucherShortcuts({
        onSave: () => handleSave(false),
        onPost: () => handleSave(true),
        onAddLine: addLine,
        onClose: onClose,
        enabled: open && !isViewMode
    });

    const handleSupplierChange = (supplierId: any, supItem?: any) => {
        const sup = supItem || suppliers.find(s => s.id === Number(supplierId) || s.code === supplierId);
        if (sup) {
            form.setFieldsValue({
                supplier_name: sup.name,
                supplier_address: sup.address,
                tax_code: sup.tax_code,
                deliverer_name: sup.contact_name,
                description: `Giảm giá hàng mua từ ${sup.name}`
            });
        }
    };

    const handleItemChange = (index: number, itemId: number, fallbackItem?: any) => {
        const itm = items.find(i => i.id === itemId) || fallbackItem;
        if (itm) {
            const rawPrice = itm.cost_price ?? itm.sale_price;
            const price = rawPrice === undefined || rawPrice === null ? undefined : Number(rawPrice);
            const currentQty = Number(lines[index]?.quantity) || 1;
            const amt = Number.isFinite(currentQty) && price !== undefined && Number.isFinite(price)
                ? currentQty * price
                : undefined;
            const taxRate = typeof itm.tax_rate === 'number' ? itm.tax_rate : 10;
            const taxAmt = amt !== undefined ? amt * (taxRate / 100) : undefined;

            updateLine(index, {
                item_id: itm.id,
                item_code: itm.code,
                item_name: itm.name,
                unit: itm.unit || 'Cái',
                quantity: lines[index]?.quantity || 1,
                unit_price: price,
                amount: amt,
                tax_rate: taxRate,
                tax_amount: taxAmt
            });
        }
    };

    const handleQuantityPriceChange = (index: number, field: 'quantity' | 'unit_price', val: number | null) => {
        const line = lines[index];
        const qty = field === 'quantity' ? (val || 0) : (Number(line?.quantity) || 0);
        const price = field === 'unit_price' ? (val || 0) : (Number(line?.unit_price) || 0);
        const amt = qty * price;
        const taxRate = Number(line?.tax_rate) || 0;
        const taxAmt = amt * (taxRate / 100);

        updateLine(index, {
            [field]: val,
            amount: amt,
            discount_amount: amt,
            tax_amount: taxAmt
        });
    };

    const accountingColumns = [
        {
            title: 'STT',
            width: 45,
            align: 'center' as const,
            render: (_: unknown, __: unknown, idx: number) => idx + 1
        },
        {
            title: 'Mã hàng',
            dataIndex: 'item_id',
            width: 140,
            render: (val: number, _: PurchaseDiscountLine, idx: number) => (
                <AdaptiveSelect
                    showSearch
                    size="small"
                    value={val}
                    disabled={isViewMode}
                    className="misa-w-full"
                    placeholder="Mã..."
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
                    onChange={(itemId: any) => handleItemChange(idx, itemId)}
                    options={items.map(it => ({
                        value: it.id,
                        label: it.code,
                        itemCode: it.code,
                        itemName: it.name,
                        itemStock: it.stock_quantity,
                        itemPrice: it.cost_price ?? it.sale_price
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
                                <span className="misa-text-right">Đơn giá</span>
                            </div>
                            {menu}
                            {!isViewMode && (
                                <div className="misa-grid-dropdown-footer">
                                    <Button
                                        type="link"
                                        size="small"
                                        icon={<PlusOutlined />}
                                        onClick={() => {
                                            setActiveRowIndex(idx);
                                            setIsAddItemOpen(true);
                                        }}
                                        className="misa-btn-tool-sm misa-text-blue misa-text-semibold"
                                    >
                                        Thêm mới
                                    </Button>
                                </div>
                            )}
                        </div>
                    )}
                />
            )
        },
        {
            title: 'Tên hàng hóa, dịch vụ',
            dataIndex: 'item_name',
            width: 220,
            render: (val: string, _: PurchaseDiscountLine, idx: number) => (
                <Input
                    size="small"
                    value={val}
                    disabled={isViewMode}
                    onChange={e => updateLine(idx, { item_name: e.target.value })}
                />
            )
        },
        {
            title: 'TK Nợ',
            dataIndex: 'debit_account',
            width: 110,
            render: (val: string, _: PurchaseDiscountLine, idx: number) => (
                <AccountSelect
                    accounts={accounts}
                    value={val}
                    disabled={isViewMode}
                    placeholder="TK Nợ"
                    onChange={newVal => updateLine(idx, { debit_account: newVal })}
                />
            )
        },
        {
            title: 'TK Có',
            dataIndex: 'credit_account',
            width: 110,
            render: (val: string, _: PurchaseDiscountLine, idx: number) => (
                <AccountSelect
                    accounts={accounts}
                    value={val}
                    disabled={isViewMode}
                    placeholder="TK Có"
                    onChange={newVal => updateLine(idx, { credit_account: newVal })}
                />
            )
        },
        {
            title: 'ĐVT',
            dataIndex: 'unit',
            width: 70,
            render: (val: string, _: PurchaseDiscountLine, idx: number) => (
                <Input
                    size="small"
                    value={val}
                    disabled={isViewMode}
                    onChange={e => updateLine(idx, { unit: e.target.value })}
                />
            )
        },
        {
            title: 'Số lượng',
            dataIndex: 'quantity',
            width: 90,
            align: 'right' as const,
            render: (val: number, _: PurchaseDiscountLine, idx: number) => (
                <InputNumber
                    size="small"
                    min={0}
                    value={val}
                    disabled={isViewMode}
                    style={{ width: '100%' }}
                    onChange={v => handleQuantityPriceChange(idx, 'quantity', v)}
                />
            )
        },
        {
            title: 'Đơn giá giảm',
            dataIndex: 'unit_price',
            width: 120,
            align: 'right' as const,
            render: (val: number, _: PurchaseDiscountLine, idx: number) => (
                <InputNumber
                    size="small"
                    min={0}
                    value={val}
                    disabled={isViewMode}
                    style={{ width: '100%' }}
                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                    onChange={v => handleQuantityPriceChange(idx, 'unit_price', v)}
                />
            )
        },
        {
            title: 'Tiền giảm giá',
            dataIndex: 'amount',
            width: 130,
            align: 'right' as const,
            render: (val: number) => (
                <span style={{ fontWeight: 600 }}>
                    {new Intl.NumberFormat('vi-VN').format(val || 0)}
                </span>
            )
        },
        {
            title: '',
            width: 40,
            align: 'center' as const,
            render: (_: unknown, __: unknown, idx: number) => !isViewMode && (
                <DeleteOutlined 
                    className="misa-delete-icon" 
                    onClick={() => removeLine(idx)}
                />
            )
        }
    ];

    const taxColumns = [
        {
            title: 'STT',
            width: 45,
            align: 'center' as const,
            render: (_: unknown, __: unknown, idx: number) => idx + 1
        },
        {
            title: 'Mã hàng',
            dataIndex: 'item_code',
            width: 140
        },
        {
            title: 'Tên hàng hóa',
            dataIndex: 'item_name',
            width: 220
        },
        {
            title: '% Thuế GTGT',
            dataIndex: 'tax_rate',
            width: 100,
            align: 'right' as const,
            render: (val: number, _: PurchaseDiscountLine, idx: number) => (
                <AdaptiveSelect
                    size="small"
                    value={val}
                    disabled={isViewMode}
                    style={{ width: '100%' }}
                    onChange={(rate: any) => {
                        const amt = Number(lines[idx]?.amount) || 0;
                        updateLine(idx, {
                            tax_rate: rate,
                            tax_amount: amt * (rate / 100)
                        });
                    }}
                    options={[0, 5, 8, 10].map(value => ({ value, label: `${value}%` }))}
                />
            )
        },
        {
            title: 'Tiền thuế GTGT',
            dataIndex: 'tax_amount',
            width: 130,
            align: 'right' as const,
            render: (val: number, _: PurchaseDiscountLine, idx: number) => (
                <InputNumber
                    size="small"
                    value={val}
                    disabled={isViewMode}
                    style={{ width: '100%' }}
                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                    onChange={v => updateLine(idx, { tax_amount: Number(v) || 0 })}
                />
            )
        },
        {
            title: 'TK Thuế',
            dataIndex: 'tax_account',
            width: 110,
            render: (val: string, _: PurchaseDiscountLine, idx: number) => (
                <AccountSelect
                    accounts={accounts}
                    value={val}
                    disabled={isViewMode}
                    placeholder="TK Thuế"
                    onChange={newVal => updateLine(idx, { tax_account: newVal })}
                />
            )
        },
        {
            title: 'Số hóa đơn mua',
            dataIndex: 'invoice_number',
            width: 130,
            render: (val: string, _: PurchaseDiscountLine, idx: number) => (
                <Input
                    size="small"
                    value={val}
                    disabled={isViewMode}
                    onChange={e => updateLine(idx, { invoice_number: e.target.value })}
                />
            )
        }
    ];

    return (
        <Modal
            open={open}
            onCancel={onClose}
            width="100vw"
            style={{ top: 0, margin: 0, paddingBottom: 0, maxWidth: '100vw' }}
            className="misa-voucher-modal misa-voucher-modal-container"
            closeIcon={<CloseOutlined className="misa-btn-tool-sm" />}
            destroyOnHidden
            styles={{
                header: { padding: '10px 18px', borderBottom: '1px solid #e5e7eb' },
                body: { padding: '12px 16px', maxHeight: 'calc(100vh - 190px)', overflowY: 'auto', overflowX: 'hidden', display: 'flex', flexDirection: 'column', gap: 10, background: '#eaedf2' }
            }}
            title={
                <div className="misa-modal-header-wrapper">
                    <div className="misa-flex-center misa-gap-12">
                        <span className="misa-voucher-header-title">
                            {recordId ? (isInternalViewMode ? `Xem chứng từ giảm giá hàng mua: ${initialRecord?.voucher_number}` : `Sửa chứng từ giảm giá hàng mua: ${initialRecord?.voucher_number}`) : 'Chứng từ giảm giá hàng mua'}
                        </span>
                        {isInternalViewMode && (
                            <span className="misa-apple-pill misa-apple-pill-blue">
                                <span className="misa-apple-pill-dot" />
                                Chế độ xem
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
            footer={
                <div className="misa-modal-footer">
                    <div className="misa-footer-left">
                        <Switch
                            size="small"
                            checked={showAccounts}
                            onChange={setShowAccounts}
                            className="misa-switch-green"
                        />
                        <span className="misa-footer-switch-label">Hiển thị tài khoản</span>
                    </div>
                    <div className="misa-footer-right">
                        {isInternalViewMode ? (
                            <Space size={8}>
                                <Button onClick={onClose} className="misa-btn-secondary">
                                    Đóng (Esc)
                                </Button>
                                <Button 
                                    type="primary" 
                                    icon={<EditOutlined />}
                                    onClick={() => setIsInternalViewMode(false)}
                                    className="misa-btn-primary misa-text-bold"
                                >
                                    Sửa (Ctrl+E)
                                </Button>
                            </Space>
                        ) : (
                            <Space size={8}>
                                <Button onClick={onClose} className="misa-btn-secondary">
                                    Hủy (Esc)
                                </Button>
                                <Button
                                    onClick={() => handleSave(false)}
                                    loading={saveMutation.isPending}
                                    className="misa-btn-secondary misa-text-bold"
                                >
                                    Cất và Thêm
                                </Button>
                                <Button
                                    type="primary"
                                    onClick={() => handleSave(true)}
                                    loading={saveMutation.isPending}
                                    className="misa-btn-primary misa-text-bold"
                                >
                                    Cất
                                </Button>
                            </Space>
                        )}
                    </div>
                </div>
            }
        >
            <ModalFrame bodyStyle={{ width: '100%', maxWidth: '100%', minWidth: 0, overflowX: 'hidden' }}>
            {(isSuppliersError || isEmployeesError || isItemsError) && (
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
                </div>
            )}
            <Form form={form} layout="vertical">
                {/* Header controls: Payment method options */}
                <div className="misa-config-bar">
                    <div className="misa-config-bar-left">
                        <Radio.Group 
                            value={paymentMethod} 
                            onChange={e => setPaymentMethod(e.target.value)}
                            disabled={isViewMode}
                        >
                            <Radio value="reduce_payable"><span className="misa-text-semibold">Giảm trừ công nợ</span></Radio>
                            <Radio value="cash"><span className="misa-text-semibold">Thu tiền mặt</span></Radio>
                        </Radio.Group>
                    </div>

                    <div className="misa-config-bar-right">
                        <button 
                            type="button" 
                            className="misa-btn-link-action"
                            onClick={() => setIsRefModalOpen(true)}
                        >
                            <LinkOutlined /> Chọn từ hóa đơn mua hàng
                        </button>
                    </div>
                </div>

                {/* Master Info Cards */}
                <MisaMasterCard className="misa-mb-12">
                    <div className="misa-master-layout">
                        {/* LEFT: General Info */}
                        <div className="misa-master-left">
                            <div className="misa-form-grid">
                                <div className="misa-col-4">
                                    <div className="misa-field-label">Mã nhà cung cấp <span className="misa-text-danger">*</span></div>
                                    <Form.Item name="supplier_id" noStyle rules={[{ required: true, message: 'Chọn nhà cung cấp' }]}>
                                        <MultiColumnContactSelect
                                            placeholder="Chọn nhà cung cấp..."
                                            entityType="supplier"
                                            disabled={isViewMode}
                                            options={suppliers.map(s => ({
                                                id: s.id,
                                                code: s.code || '',
                                                name: s.name,
                                                address: s.address,
                                                tax_code: s.tax_code,
                                                phone: s.phone,
                                                contact_person: s.contact_name
                                            }))}
                                            value={form.getFieldValue('supplier_id')}
                                            onChange={(val, item) => handleSupplierChange(val, item)}
                                            onQuickAdd={isViewMode ? undefined : () => setIsAddSupplierOpen(true)}
                                        />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-8">
                                    <div className="misa-field-label">Tên nhà cung cấp</div>
                                    <Form.Item name="supplier_name" noStyle>
                                        <Input disabled={isViewMode} className="misa-input" placeholder="Tên nhà cung cấp" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-8">
                                    <div className="misa-field-label">Địa chỉ</div>
                                    <Form.Item name="supplier_address" noStyle>
                                        <Input disabled={isViewMode} className="misa-input" placeholder="Địa chỉ nhà cung cấp" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-4">
                                    <div className="misa-field-label">Mã số thuế</div>
                                    <Form.Item name="tax_code" noStyle>
                                        <Input disabled={isViewMode} className="misa-input" placeholder="Mã số thuế" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-4">
                                    <div className="misa-field-label">Người liên hệ</div>
                                    <Form.Item name="deliverer_name" noStyle>
                                        <Input disabled={isViewMode} className="misa-input" placeholder="Người liên hệ phía NCC" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-5">
                                    <div className="misa-field-label">Nhân viên mua hàng</div>
                                    <div className="misa-input-group">
                                        <Form.Item name="employee_id" noStyle>
                                            <Select 
                                                showSearch 
                                                variant="borderless" 
                                                className="misa-w-full"
                                                allowClear
                                                placeholder="Chọn nhân viên"
                                                disabled={isViewMode}
                                                options={employees.map((e: any) => ({ value: e.id, label: (e.code ? e.code + ' - ' : '') + e.name }))}
                                            />
                                        </Form.Item>
                                        <button 
                                            type="button" 
                                            className={"misa-plus-btn " + (isViewMode ? "misa-btn-disabled" : "")}
                                            disabled={isViewMode}
                                            onClick={isViewMode ? undefined : () => setIsAddEmployeeOpen(true)}
                                            title="Thêm nhanh nhân viên"
                                        >
                                            <PlusOutlined className="misa-btn-plus-icon-sm" />
                                        </button>
                                    </div>
                                </div>

                                <div className="misa-col-3">
                                    <div className="misa-field-label">Kèm theo</div>
                                    <div className="misa-flex-center misa-gap-4">
                                        <Form.Item name="attached_docs" noStyle>
                                            <Input disabled={isViewMode} className="misa-input" style={{ width: 90 }} placeholder="Số lượng" />
                                        </Form.Item>
                                        <span className="apple-muted-text misa-nowrap">Chứng từ gốc</span>
                                    </div>
                                </div>

                                <div className="misa-col-10">
                                    <div className="misa-field-label">Diễn giải</div>
                                    <Form.Item name="description" noStyle>
                                        <Input disabled={isViewMode} className="misa-input" placeholder="Lý do giảm giá hàng mua..." />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-2">
                                    <div className="misa-field-label">Tham chiếu</div>
                                    <div className="misa-flex-center misa-gap-6">
                                        <button 
                                            type="button" 
                                            className="misa-btn-tool misa-btn-tool-ref" 
                                            onClick={() => setIsRefModalOpen(true)}
                                            title="Chọn chứng từ tham chiếu"
                                        >
                                            ...
                                        </button>
                                        {form.getFieldValue('reference_invoice_id') && (
                                            <span className="misa-btn-ref-count">(1 CT)</span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* RIGHT: Voucher Info + Total Card */}
                        <div className="misa-master-right">
                            <div className="misa-flex-col misa-gap-6">
                                <div className="misa-meta-row">
                                    <span className="misa-field-label required">Ngày hạch toán</span>
                                    <Form.Item name="accounting_date" noStyle rules={[{ required: true }]}>
                                        <DatePicker className="misa-input misa-col-w-175" format="DD/MM/YYYY" disabled={isViewMode} />
                                    </Form.Item>
                                </div>

                                <div className="misa-meta-row">
                                    <span className="misa-field-label required">Ngày chứng từ</span>
                                    <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                        <DatePicker className="misa-input misa-col-w-175" format="DD/MM/YYYY" disabled={isViewMode} />
                                    </Form.Item>
                                </div>

                                <div className="misa-meta-row">
                                    <span className="misa-field-label required">Số chứng từ</span>
                                    <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                        <Input className="misa-input misa-col-w-175 misa-text-semibold misa-text-blue" disabled={isViewMode} />
                                    </Form.Item>
                                </div>
                            </div>

                            <MisaTotalCard
                                label="TỔNG TIỀN GIẢM GIÁ"
                                amount={totals.grandTotal}
                            />
                        </div>
                    </div>
                </MisaMasterCard>

                {/* Detail Grid */}
                <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 6, padding: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                        <Tabs
                            activeKey={activeTab}
                            onChange={k => setActiveTab(k as 'accounting' | 'tax' | 'statistic')}
                            items={[
                                { key: 'accounting', label: '1. Hạch toán chi tiết' },
                                { key: 'tax', label: '2. Thuế GTGT' },
                                { key: 'statistic', label: '3. Thống kê' }
                            ]}
                        />
                    </div>

                    <Table
                        columns={activeTab === 'tax' ? taxColumns : accountingColumns}
                        dataSource={lines}
                        pagination={false}
                        size="small"
                        bordered
                        scroll={{ x: 1250, y: 320 }}
                    />

                    <MisaGridActionFooter
                        onAddLine={() => addLine()}
                        onAddNote={() => {
                            const curDesc = form.getFieldValue('description') || '';
                            addLine();
                            if (lines.length > 0) {
                                updateLine(lines.length, { description: curDesc ? `Ghi chú: ${curDesc}` : 'Ghi chú hàng hóa' });
                            }
                        }}
                        onDeleteAll={() => setLines([])}
                        lineCount={lines.length}
                        disabled={isViewMode}
                    />

                    <MisaTableSummaryBar
                        metrics={[
                            { label: 'Tổng số lượng', value: totals.totalQuantity, format: 'number' },
                            { label: 'Tổng tiền giảm giá', value: totals.subTotal, format: 'currency', isCurrency: true },
                            { label: 'Tổng thuế GTGT', value: totals.totalTax, format: 'currency', isCurrency: true },
                            { label: 'Tổng thanh toán', value: totals.grandTotal, format: 'currency', isCurrency: true, isHighlight: true }
                        ]}
                    />
                </div>
            </Form>

            {/* Cross Voucher Reference Search Modal */}
            <ReferenceVoucherModal
                open={isRefModalOpen}
                onCancel={() => setIsRefModalOpen(false)}
                onSelect={(refs: any[]) => {
                    if (refs && refs.length > 0) {
                        const ref = refs[0];
                        form.setFieldsValue({
                            supplier_name: ref.contact_name,
                            description: `Giảm giá hàng mua theo ${ref.voucher_number}`,
                            reference_invoice_id: ref.real_id
                        });
                        setIsRefModalOpen(false);
                    }
                }}
            />

            </ModalFrame>

            {/* Quick Modals */}
            <QuickAddContactModal
                open={isAddSupplierOpen}
                onCancel={() => setIsAddSupplierOpen(false)}
                contactType="supplier"
                onSuccess={(newSup: any) => {
                    queryClient.invalidateQueries({ queryKey: ['suppliers'] });
                    form.setFieldsValue({ supplier_id: newSup.id });
                    handleSupplierChange(newSup.id);
                    setIsAddSupplierOpen(false);
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

            <QuickAddEmployeeModal
                open={isAddEmployeeOpen}
                onCancel={() => setIsAddEmployeeOpen(false)}
                onSuccess={(newEmp: any) => {
                    queryClient.invalidateQueries({ queryKey: ['employees'] });
                    form.setFieldsValue({ employee_id: newEmp.id });
                    setIsAddEmployeeOpen(false);
                }}
            />
        </Modal>
    );
};
