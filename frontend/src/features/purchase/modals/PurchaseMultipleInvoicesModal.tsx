import React, { useState, useEffect } from 'react';
import { Alert, Form, Input, InputNumber, Select, DatePicker, Radio, Checkbox, Table, Button, Space, Popconfirm } from 'antd';
import { toast as message } from '../../../components/feedback/toast';
import Modal from '../../../components/layout/AppModal';
import ModalFrame from '../../../components/layout/ModalFrame';
import { 
    DeleteOutlined,
    SaveOutlined
} from '@ant-design/icons';
import dayjs from 'dayjs';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../../api/axios';
import { 
    MisaMasterCard,
    MisaGridActionFooter,
    MisaTableSummaryBar,
    MisaTotalCard
} from '../../../components/misa';
import { useVoucherShortcuts } from '../../../hooks/useVoucherShortcuts';
import { useVoucherTotals } from '../../../hooks/useVoucherTotals';

interface PurchaseMultipleInvoicesModalProps {
    open: boolean;
    onCancel: () => void;
    onSuccess?: () => void;
}

interface MultiInvoiceLine {
    key: string;
    supplier_id?: number;
    supplier_name?: string;
    tax_code?: string;
    item_id?: number;
    item_code?: string;
    item_name?: string;
    warehouse?: string;
    debit_account?: string;
    credit_account?: string;
    unit?: string;
    quantity?: number;
    unit_price?: number;
    amount?: number;
    tax_rate?: number;
    tax_amount?: number;
    tax_account?: string;
    invoice_symbol?: string;
    invoice_number?: string;
    invoice_date?: any;
}

function parsePurchaseMultipleCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: T[] }).data;
    }
    throw new Error(`Invalid ${resource} response.`);
}

export const PurchaseMultipleInvoicesModal: React.FC<PurchaseMultipleInvoicesModalProps> = ({
    open,
    onCancel,
    onSuccess
}) => {
    const [form] = Form.useForm();
    const voucherNumber = Form.useWatch('voucher_number', form);
    const queryClient = useQueryClient();

    const [paymentMethod, setPaymentMethod] = useState<'unpaid' | 'cash' | 'bank'>('unpaid');
    const [isStockInward, setIsStockInward] = useState(true);
    const [activeTab, setActiveTab] = useState<'accounting' | 'invoices'>('accounting');

    const {
        data: suppliers = [],
        isError: isSuppliersError,
        refetch: refetchSuppliers,
    } = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parsePurchaseMultipleCollection<any>(data, 'suppliers');
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
            return parsePurchaseMultipleCollection<any>(data, 'employees');
        },
        enabled: open
    });

    const {
        data: items = [],
        isError: isItemsError,
        refetch: refetchItems,
    } = useQuery({
        queryKey: ['items-multi'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parsePurchaseMultipleCollection<any>(data, 'inventory items');
        },
        enabled: open
    });

    const supplierList = suppliers;
    const employeeList = employees;
    const itemList = items;

    const [lines, setLines] = useState<MultiInvoiceLine[]>([]);

    const { subTotal, totalTax, grandTotal } = useVoucherTotals(lines);

    useEffect(() => {
        if (open) {
            form.resetFields();
            form.setFieldsValue({
                voucher_number: undefined,
                accounting_date: dayjs(),
                voucher_date: dayjs(),
                description: 'Mua hàng nhiều hóa đơn'
            });
        }
    }, [open, form]);

    const updateLine = (key: string, field: keyof MultiInvoiceLine, value: any) => {
        setLines(prev => prev.map(l => {
            if (l.key !== key) return l;
            const updated = { ...l, [field]: value };
            if (field === 'quantity' || field === 'unit_price') {
                const q = field === 'quantity' ? Number(value) || 0 : Number(l.quantity ?? 0);
                const p = field === 'unit_price' ? Number(value) || 0 : Number(l.unit_price ?? 0);
                updated.amount = q * p;
                updated.tax_amount = (updated.amount * Number(updated.tax_rate ?? 0)) / 100;
            }
            if (field === 'tax_rate') {
                updated.tax_amount = (Number(updated.amount ?? 0) * (Number(value) || 0)) / 100;
            }
            return updated;
        }));
    };

    const addLine = () => {
        setLines(prev => [
            ...prev,
            {
                key: `${Date.now()}`,
            }
        ]);
    };

    const removeLine = (key: string) => {
        if (lines.length <= 1) {
            message.warning('Chứng từ phải có ít nhất 1 dòng!');
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
            const payload = {
                voucher_number: values.voucher_number,
                voucher_type: '2. Mua hàng nhiều hóa đơn',
                employee_id: values.employee_id,
                accounting_date: values.accounting_date?.format('YYYY-MM-DD'),
                invoice_date: values.voucher_date?.format('YYYY-MM-DD'),
                description: values.description,
                payment_method: paymentMethod,
                is_stock_inward: isStockInward,
                sub_total: subTotal,
                tax_amount: totalTax,
                total_amount: grandTotal,
                lines: lines.map(l => ({
                    supplier_id: l.supplier_id,
                    item_id: l.item_id,
                    description: l.item_name,
                    unit: l.unit,
                    quantity: l.quantity,
                    unit_price: l.unit_price,
                    amount: l.amount,
                    debit_account: l.debit_account,
                    credit_account: l.credit_account,
                    tax_rate: l.tax_rate,
                    tax_amount: l.tax_amount,
                    tax_account: l.tax_account,
                    invoice_symbol: l.invoice_symbol,
                    invoice_number: l.invoice_number,
                    invoice_date: l.invoice_date ? dayjs(l.invoice_date).format('YYYY-MM-DD') : undefined
                }))
            };

            const response = await api.post('/purchase/invoices', payload);
            const persistedInvoice = response?.data?.data ?? response?.data;
            if (!persistedInvoice || persistedInvoice.id === undefined || persistedInvoice.id === null) {
                throw new Error('Máy chủ không trả về chứng từ mua nhiều hóa đơn đã lưu; không thể báo thành công.');
            }
            message.success('Đã lưu chứng từ mua hàng nhiều hóa đơn thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-invoices'] });
            if (onSuccess) onSuccess();

            if (andNew) {
                form.resetFields();
                form.setFieldsValue({
                    voucher_number: undefined,
                    accounting_date: dayjs(),
                    voucher_date: dayjs(),
                    description: 'Mua hàng nhiều hóa đơn'
                });
                deleteAllLines();
            } else {
                onCancel();
            }
        } catch (e: any) {
            if (e?.errorFields) return;
            message.error(e?.response?.data?.message || 'Có lỗi khi lưu chứng từ!');
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
            key: 'idx',
            width: 40,
            align: 'center' as const,
            render: (_: any, __: any, index: number) => index + 1
        },
        {
            title: 'Nhà cung cấp',
            dataIndex: 'supplier_id',
            key: 'supplier_id',
            width: 200,
            render: (val: number, r: MultiInvoiceLine) => (
                <Select
                    showSearch
                    value={val || undefined}
                    placeholder="Chọn nhà cung cấp"
                    className="misa-input misa-w-full"
                    onChange={(v) => {
                        const sup = supplierList.find((s: any) => s.id === v);
                        if (sup) {
                            updateLine(r.key, 'supplier_id', sup.id);
                            updateLine(r.key, 'supplier_name', sup.name);
                            updateLine(r.key, 'tax_code', sup.tax_code);
                        }
                    }}
                    options={supplierList.map((s: any) => ({ value: s.id, label: `${s.code} - ${s.name}` }))}
                />
            )
        },
        {
            title: 'Mã hàng',
            dataIndex: 'item_code',
            key: 'item_code',
            width: 140,
            render: (val: string, r: MultiInvoiceLine) => (
                <Select
                    showSearch
                    value={val || undefined}
                    placeholder="Mã hàng"
                    className="misa-input misa-w-full"
                    onChange={(v) => {
                        const itm = itemList.find((i: any) => i.code === v);
                        if (itm) {
                            updateLine(r.key, 'item_id', itm.id);
                            updateLine(r.key, 'item_code', itm.code);
                            updateLine(r.key, 'item_name', itm.name);
                            updateLine(r.key, 'unit', itm.unit);
                            updateLine(r.key, 'unit_price', itm.purchase_price ?? itm.unit_price);
                        } else {
                            updateLine(r.key, 'item_code', v);
                        }
                    }}
                    options={itemList.map((i: any) => ({ value: i.code, label: `${i.code} - ${i.name}` }))}
                />
            )
        },
        {
            title: 'Tên hàng',
            dataIndex: 'item_name',
            key: 'item_name',
            minWidth: 180,
            render: (val: string, r: MultiInvoiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'item_name', e.target.value)} 
                    placeholder="Tên hàng hóa, vật tư"
                    className="misa-input"
                />
            )
        },
        {
            title: 'TK Nợ',
            dataIndex: 'debit_account',
            key: 'debit_account',
            width: 90,
            render: (val: string, r: MultiInvoiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'debit_account', e.target.value)} 
                    className="misa-input misa-text-center"
                />
            )
        },
        {
            title: 'TK Có',
            dataIndex: 'credit_account',
            key: 'credit_account',
            width: 90,
            render: (val: string, r: MultiInvoiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'credit_account', e.target.value)} 
                    className="misa-input misa-text-center"
                />
            )
        },
        {
            title: 'ĐVT',
            dataIndex: 'unit',
            key: 'unit',
            width: 70,
            render: (val: string, r: MultiInvoiceLine) => (
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
            render: (val: number, r: MultiInvoiceLine) => (
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
            render: (val: number, r: MultiInvoiceLine) => (
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
            title: '',
            key: 'action',
            width: 35,
            align: 'center' as const,
            render: (_: any, r: MultiInvoiceLine) => (
                <Popconfirm title="Xóa dòng này?" onConfirm={() => removeLine(r.key)} okText="Xóa" cancelText="Hủy">
                    <button type="button" className="misa-btn-plain-danger">
                        <DeleteOutlined />
                    </button>
                </Popconfirm>
            )
        }
    ];

    const invoiceColumns = [
        {
            title: '#',
            key: 'idx',
            width: 40,
            align: 'center' as const,
            render: (_: any, __: any, index: number) => index + 1
        },
        {
            title: 'Tên hàng hóa',
            dataIndex: 'item_name',
            key: 'item_name',
            minWidth: 160,
            render: (val: string) => <span className="misa-text-semibold">{val || '-'}</span>
        },
        {
            title: 'Ký hiệu HĐ',
            dataIndex: 'invoice_symbol',
            key: 'invoice_symbol',
            width: 110,
            render: (val: string, r: MultiInvoiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'invoice_symbol', e.target.value)} 
                    placeholder="Ký hiệu hóa đơn"
                    className="misa-input"
                />
            )
        },
        {
            title: 'Số hóa đơn',
            dataIndex: 'invoice_number',
            key: 'invoice_number',
            width: 120,
            render: (val: string, r: MultiInvoiceLine) => (
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
            width: 130,
            render: (val: any, r: MultiInvoiceLine) => (
                <DatePicker 
                    value={val ? dayjs(val) : null} 
                    onChange={d => updateLine(r.key, 'invoice_date', d)}
                    format="DD/MM/YYYY"
                    className="misa-input misa-w-full"
                />
            )
        },
        {
            title: '% Thuế',
            dataIndex: 'tax_rate',
            key: 'tax_rate',
            width: 90,
            render: (val: number, r: MultiInvoiceLine) => (
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
            width: 130,
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
            width: 90,
            render: (val: string, r: MultiInvoiceLine) => (
                <Input 
                    value={val} 
                    onChange={e => updateLine(r.key, 'tax_account', e.target.value)} 
                    className="misa-input misa-text-center"
                />
            )
        }
    ];

    return (
        <Modal
            title={
                <div className="misa-modal-header-wrapper">
                    <div className="misa-flex-center misa-gap-12">
                        <span className="misa-voucher-header-title">
                            Chứng từ mua hàng nhiều hóa đơn
                        </span>
                        <span className="misa-status-tag misa-status-tag-draft">
                            {voucherNumber || '—'}
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
                            Lưu và thêm mới (Ctrl+Shift+S)
                        </Button>
                        <Button 
                            type="primary"
                            onClick={() => handleSave(false)}
                            icon={<SaveOutlined />}
                            className="misa-btn-footer-primary"
                        >
                            Lưu (Ctrl+S)
                        </Button>
                    </Space>
                </div>
            }
        >
            <ModalFrame>
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
                {/* Top Config Bar */}
                <div className="misa-config-bar">
                    <div className="misa-config-bar-left">
                        <Radio.Group value={paymentMethod} onChange={e => setPaymentMethod(e.target.value)}>
                            <Radio value="unpaid"><span className="misa-text-bold">Chưa thanh toán</span></Radio>
                            <Radio value="cash"><span>Thanh toán ngay bằng Tiền mặt</span></Radio>
                        </Radio.Group>
                    </div>

                    <div className="misa-config-bar-right">
                        <Checkbox checked={isStockInward} onChange={e => setIsStockInward(e.target.checked)}>
                            <span className="misa-text-bold">Kiêm phiếu nhập kho</span>
                        </Checkbox>
                    </div>
                </div>

                {/* Master Card */}
                <MisaMasterCard className="mb-2">
                    <MisaMasterCard.Left>
                        <MisaMasterCard.FormGrid>
                            <div className="misa-col-4">
                                <div className="misa-field-label">Nhân viên mua hàng</div>
                                <Form.Item name="employee_id" noStyle>
                                    <Select 
                                        showSearch 
                                        allowClear 
                                        placeholder="Chọn nhân viên"
                                        className="misa-input"
                                        options={employeeList.map((e: any) => ({ value: e.id, label: `${e.code} - ${e.name}` }))}
                                    />
                                </Form.Item>
                            </div>

                            <div className="misa-col-8">
                                <div className="misa-field-label">Diễn giải</div>
                                <Form.Item name="description" noStyle initialValue="Mua hàng nhiều hóa đơn">
                                    <Input className="misa-input" placeholder="Nội dung diễn giải chứng từ mua hàng..." />
                                </Form.Item>
                            </div>
                        </MisaMasterCard.FormGrid>
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

                        <MisaTotalCard label="Tổng tiền thanh toán" value={grandTotal} />
                    </MisaMasterCard.Right>
                </MisaMasterCard>

                {/* Detail Grid Section */}
                <div className="misa-detail-card">
                    <div className="misa-grid-tab-bar">
                        <div className="misa-grid-tabs">
                            <button
                                type="button"
                                onClick={() => setActiveTab('accounting')}
                                className={`misa-grid-tab-btn ${activeTab === 'accounting' ? 'active' : ''}`}
                            >
                                1. Hạch toán ({lines.length})
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('invoices')}
                                className={`misa-grid-tab-btn ${activeTab === 'invoices' ? 'active' : ''}`}
                            >
                                2. Hóa đơn ({lines.filter(l => l.invoice_number).length})
                            </button>
                        </div>
                    </div>

                    {activeTab === 'accounting' && (
                        <Table 
                            columns={accountingColumns}
                            dataSource={lines}
                            pagination={false}
                            size="small"
                            scroll={{ y: 240, x: 1200 }}
                            className="misa-voucher-table"
                        />
                    )}

                    {activeTab === 'invoices' && (
                        <Table 
                            columns={invoiceColumns}
                            dataSource={lines}
                            pagination={false}
                            size="small"
                            scroll={{ y: 240, x: 1100 }}
                            className="misa-voucher-table"
                        />
                    )}

                    {/* Action buttons BELOW table */}
                    <MisaGridActionFooter 
                        onAddLine={addLine}
                        onDeleteAll={deleteAllLines}
                        lineCount={lines.length}
                    />

                    {/* Summary Bar at bottom */}
                    <MisaTableSummaryBar 
                        items={[
                            { label: 'Tổng tiền hàng', value: subTotal, format: 'currency' },
                            { label: 'Tổng tiền thuế GTGT', value: totalTax, format: 'currency' },
                            { label: 'Tổng thanh toán', value: grandTotal, format: 'currency', highlight: true }
                        ]}
                    />
                </div>
            </Form>
            </ModalFrame>
        </Modal>
    );
};

export default PurchaseMultipleInvoicesModal;
