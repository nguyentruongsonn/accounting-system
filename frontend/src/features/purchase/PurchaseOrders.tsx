import React, { useState } from 'react';
import { Alert, Table, Button, Input, Select, DatePicker, Popconfirm, Form, InputNumber, Dropdown } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { MenuProps } from 'antd';
import { 
    PlusOutlined, 
    DeleteOutlined, 
    PaperClipOutlined,
    SearchOutlined,
    CloseOutlined,
    FullscreenOutlined,
    CopyOutlined,
    EditOutlined,
    EyeOutlined,
    PrinterOutlined,
    CheckCircleOutlined,
    ReloadOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { 
    QuickAddContactModal, 
    QuickAddEmployeeModal,
    QuickAddPaymentTermModal,
    QuickAddItemModal,
    VoucherPrintModal,
    useVoucherShortcuts
} from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

/**
 * Accept the API's supported collection envelopes while rejecting malformed
 * payloads so catalogue failures cannot masquerade as empty selectors.
 */
function parsePurchaseOrderCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];

    const envelope = payload && typeof payload === 'object'
        ? payload as { data?: unknown }
        : null;
    if (Array.isArray(envelope?.data)) return envelope.data as T[];

    const nested = envelope?.data && typeof envelope.data === 'object'
        ? envelope.data as { data?: unknown }
        : null;
    if (Array.isArray(nested?.data)) return nested.data as T[];

    throw new Error(`Invalid ${resource} response.`);
}

export const PurchaseOrders: React.FC = () => {
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingOrder, setEditingOrder] = useState<any>(null);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printData, setPrintData] = useState<any>(null);
    
    // Quick Add Modal States
    const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [isPaymentTermModalVisible, setIsPaymentTermModalVisible] = useState(false);
    const [isItemModalVisible, setIsItemModalVisible] = useState(false);
    const [activeRowIndex, setActiveRowIndex] = useState<number>(0);

    const [discountMode, setDiscountMode] = useState<string>('none'); // 'none' | 'by_item' | 'by_percent' | 'by_amount'

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    // Hotkey integration (F8/Ctrl+S: Save, Ctrl+Shift+S: Save & New, Esc: Close)
    useVoucherShortcuts({
        onSave: () => {
            if (isModalOpen) {
                form.validateFields().then(values => saveMutation.mutate(values));
            }
        },
        onSaveAndNew: () => {
            if (isModalOpen) {
                form.validateFields().then(values => {
                    saveMutation.mutate(values);
                    handleOpenCreateModal();
                });
            }
        },
        onClose: () => {
            if (isModalOpen) {
                setIsModalOpen(false);
            }
        },
        enabled: isModalOpen
    });

    // Query POs from Backend API
    const { data: orders = [], isLoading, isError: isOrdersError, refetch: refetchOrders } = useQuery({
        queryKey: ['purchase-orders', statusFilter, searchText],
        queryFn: async () => {
            const { data } = await api.get('/purchase/orders', {
                params: { status: statusFilter, search: searchText }
            });
            return parsePurchaseOrderCollection<any>(data, 'purchase order list');
        }
    });

    // Query Suppliers, Employees, Items, Payment Terms
    const { data: suppliers = [] } = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parsePurchaseOrderCollection<any>(data, 'suppliers');
        }
    });

    const { data: employees = [] } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parsePurchaseOrderCollection<any>(data, 'employees');
        }
    });

    const { data: items = [] } = useQuery({
        queryKey: ['inventory-items'],
        refetchOnMount: 'always',
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parsePurchaseOrderCollection<any>(data, 'inventory items');
        }
    });

    const { data: paymentTerms = [] } = useQuery({
        queryKey: ['payment-terms'],
        queryFn: async () => {
            const { data } = await api.get('/master/payment-terms');
            return parsePurchaseOrderCollection<any>(data, 'payment terms');
        }
    });

    const supplierList = suppliers;
    const employeeList = employees;
    const itemList = items;
    const termList = paymentTerms;
    const orderList = orders;

    // Create / Update PO Mutation
    const saveMutation = useMutation({
        mutationFn: async (values: any) => {
            const payload = {
                order_number: values.order_number,
                order_date: values.order_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                delivery_date: values.delivery_date?.format('YYYY-MM-DD') || null,
                supplier_id: values.supplier_id,
                supplier_code: supplierList.find((s: any) => s.id === values.supplier_id)?.code || '',
                supplier_name: values.supplier_name,
                supplier_address: values.supplier_address,
                tax_code: values.tax_code,
                contact_person: values.contact_person,
                employee_id: values.employee_id,
                buyer_name: employeeList.find((e: any) => e.id === values.employee_id)?.name || '',
                payment_terms: values.payment_terms || 'ĐKTT30',
                due_days: values.due_days || 30,
                delivery_address: values.delivery_address,
                other_terms: values.other_terms,
                description: values.description,
                total_amount: totalPOAmount,
                discount_amount: totalPODiscount,
                vat_amount: totalPOTax,
                grand_total: grandPOTotal,
                status: values.status || 'pending',
                lines: values.lines?.map((line: any) => ({
                    item_id: line.item_id,
                    item_code: itemList.find((i: any) => i.id === line.item_id)?.code || line.item_code,
                    item_name: line.item_name || line.description,
                    unit: line.unit || 'Cái',
                    quantity: Number(line.quantity) || 1,
                    received_quantity: Number(line.received_quantity) || 0,
                    unit_price: Number(line.unit_price) || 0,
                    amount: (Number(line.quantity) || 1) * (Number(line.unit_price) || 0),
                    discount_rate: Number(line.discount_rate) || 0,
                    discount_amount: Number(line.discount_amount) || 0,
                    tax_rate: typeof line.tax_rate === 'number' ? line.tax_rate : 0,
                    tax_amount: Number(line.tax_amount) || 0,
                })) || []
            };

            if (editingOrder?.id) {
                return api.put(`/purchase/orders/${editingOrder.id}`, payload);
            }
            return api.post('/purchase/orders', payload);
        },
        onSuccess: async (response) => {
            if (response?.data?.id === undefined || response?.data?.id === null) {
                message.error('Máy chủ không trả về đơn mua hàng đã lưu; không thể báo thành công.');
                return;
            }
            message.success(editingOrder?.id ? 'Cập nhật đơn mua hàng thành công!' : 'Cất đơn mua hàng thành công!');
            setIsModalOpen(false);
            setEditingOrder(null);
            await queryClient.invalidateQueries({ queryKey: ['purchase-orders'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra!');
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => api.delete(`/purchase/orders/${id}`),
        onSuccess: (response) => {
            if (typeof response?.data?.message !== 'string' || response.data.message.trim() === '') {
                message.error('Máy chủ không xác nhận đã xóa đơn mua hàng; không thể báo thành công.');
                return;
            }
            message.success('Đã xóa đơn mua hàng thành công');
            queryClient.invalidateQueries({ queryKey: ['purchase-orders'] });
        }
    });

    const updateStatusMutation = useMutation({
        mutationFn: async ({ id, status }: { id: number, status: string }) => {
            return api.put(`/purchase/orders/${id}`, { status });
        },
        onSuccess: (response) => {
            if (response?.data?.id === undefined || response?.data?.id === null) {
                message.error('Máy chủ không trả về đơn mua hàng đã cập nhật; không thể báo thành công.');
                return;
            }
            message.success('Cập nhật trạng thái thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-orders'] });
        }
    });

    const handleOpenCreateModal = async () => {
        form.resetFields();
        setEditingOrder(null);
        const today = dayjs();
        form.setFieldsValue({
            order_number: '',
            order_date: today,
            delivery_date: today.add(7, 'day'),
            status: 'pending',
            payment_terms: 'ĐKTT30',
            due_days: 30,
            payment_due_date: today.add(30, 'day'),
            description: `Mua hàng theo đơn mua hàng ngày ${today.format('DD/MM/YYYY')}`,
            lines: [{}]
        });
        setDiscountMode('none');
        setIsModalOpen(true);

        try {
            const { data } = await api.get('/purchase/orders/next-code');
            if (data?.next_code) {
                form.setFieldsValue({ order_number: data.next_code });
            }
        } catch (e) {
            // Keep empty or user entered
        }
    };

    const handleEditOrder = (record: any) => {
        setEditingOrder(record);
        form.resetFields();
        form.setFieldsValue({
            ...record,
            order_date: record.order_date ? dayjs(record.order_date) : dayjs(),
            delivery_date: record.delivery_date ? dayjs(record.delivery_date) : null,
            payment_due_date: record.order_date ? dayjs(record.order_date).add(record.due_days || 30, 'day') : null,
            lines: record.lines?.length > 0 ? record.lines : [{}]
        });
        setIsModalOpen(true);
    };

    const handleDuplicateOrder = async (record: any) => {
        let nextCode = '';
        try {
            const { data } = await api.get('/purchase/orders/next-code');
            if (data?.next_code) nextCode = data.next_code;
        } catch (e) {
            message.error('Không lấy được số đơn mua hàng từ máy chủ; không thể nhân bản.');
            return;
        }
        if (!nextCode) {
            message.error('Máy chủ chưa cấp số đơn mua hàng; không thể nhân bản.');
            return;
        }

        setEditingOrder(null);
        form.resetFields();
        form.setFieldsValue({
            ...record,
            order_number: nextCode,
            order_date: dayjs(),
            delivery_date: dayjs().add(7, 'day'),
            status: 'pending',
            payment_due_date: dayjs().add(record.due_days || 30, 'day'),
            description: `Nhân bản từ đơn mua hàng ${record.order_number}`
        });
        setIsModalOpen(true);
        message.info(`Đã nạp dữ liệu nhân bản sang mã mới ${nextCode}; hãy kiểm tra và bấm Cất để lưu đơn mới.`);
    };

    const handleSupplierChange = (supplierId: number) => {
        const supplier = supplierList?.find((s: any) => s.id === supplierId);
        if (supplier) {
            form.setFieldsValue({
                supplier_name: supplier.name,
                supplier_address: supplier.address,
                tax_code: supplier.tax_code,
                contact_person: supplier.contact_name || '',
                description: `Mua hàng theo đơn mua hàng từ ${supplier.name}`
            });
        }
    };

    const handlePaymentTermChange = (termCode: string) => {
        const term = termList?.find((t: any) => t.code === termCode);
        const days = term ? term.due_days : 30;
        const orderDate = form.getFieldValue('order_date') || dayjs();
        form.setFieldsValue({
            payment_terms: termCode,
            due_days: days,
            payment_due_date: dayjs(orderDate).add(days, 'day')
        });
    };

    const handleItemChange = (index: number, itemId: number) => {
        const item = itemList?.find((i: any) => i.id === itemId);
        if (item) {
            const curLines = form.getFieldValue('lines') || [];
            const price = Number(item.cost_price) || 0;
            const qty = Number(curLines[index]?.quantity) || 1;
            const amt = price * qty;
            const taxRate = typeof curLines[index]?.tax_rate === 'number' ? curLines[index]?.tax_rate : 0;
            const taxAmt = amt * (taxRate / 100);
            curLines[index] = {
                ...curLines[index],
                item_id: item.id,
                item_code: item.code,
                item_name: item.name,
                unit: item.unit || 'Cái',
                unit_price: price,
                amount: amt,
                tax_amount: taxAmt
            };
            form.setFieldsValue({ lines: [...curLines] });
        }
    };

    const handleDueDaysChange = (days: number | null) => {
        const orderDate = form.getFieldValue('order_date') || dayjs();
        const d = days || 0;
        form.setFieldsValue({
            due_days: d,
            payment_due_date: dayjs(orderDate).add(d, 'day')
        });
    };

    const formLines = Form.useWatch('lines', form) || [];
    const totalPOQty = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.quantity) || 0), 0);
    const totalPOAmount = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.amount) || ((Number(curr?.quantity) || 0) * (Number(curr?.unit_price) || 0))), 0);
    const totalPODiscount = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.discount_amount) || 0), 0);
    const totalPOTax = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.tax_amount) || 0), 0);
    const grandPOTotal = (totalPOAmount - totalPODiscount) + totalPOTax;

    const getActionMenu = (record: any): MenuProps => ({
        items: [
            {
                key: 'view',
                icon: <EyeOutlined />,
                label: 'Xem',
                onClick: () => handleEditOrder(record)
            },
            {
                key: 'edit',
                icon: <EditOutlined />,
                label: 'Sửa',
                onClick: () => handleEditOrder(record)
            },
            {
                key: 'duplicate',
                icon: <CopyOutlined />,
                label: 'Nhân bản',
                onClick: () => handleDuplicateOrder(record)
            },
            {
                key: 'status',
                icon: <CheckCircleOutlined />,
                label: 'Cập nhật trạng thái',
                children: [
                    { key: 'st_pending', label: 'Chưa thực hiện', onClick: () => updateStatusMutation.mutate({ id: record.id, status: 'pending' }) },
                    { key: 'st_processing', label: 'Đang thực hiện', onClick: () => updateStatusMutation.mutate({ id: record.id, status: 'processing' }) },
                    { key: 'st_completed', label: 'Hoàn thành', onClick: () => updateStatusMutation.mutate({ id: record.id, status: 'completed' }) },
                    { key: 'st_cancelled', label: 'Hủy bỏ', onClick: () => updateStatusMutation.mutate({ id: record.id, status: 'cancelled' }) },
                ]
            },
            {
                key: 'print',
                icon: <PrinterOutlined />,
                label: 'In đơn mua hàng',
                onClick: () => {
                    if (!Array.isArray(record.lines) || record.lines.length === 0) {
                        message.warning('Không thể in đơn mua hàng vì máy chủ chưa cung cấp dòng chi tiết đã lưu.');
                        return;
                    }
                    setPrintData({
                        voucher_number: record.order_number,
                        order_number: record.order_number,
                        voucher_date: record.order_date,
                        order_date: record.order_date,
                        delivery_date: record.delivery_date,
                        supplier_name: record.supplier_name || record.supplier?.name,
                        contact_name: record.supplier_name || record.supplier?.name,
                        supplier_address: record.supplier_address || record.supplier?.address,
                        tax_code: record.tax_code || record.supplier?.tax_code,
                        payment_term: record.payment_term,
                        description: record.description,
                        total_amount: record.grand_total != null ? Number(record.grand_total) : (record.total_amount == null ? undefined : Number(record.total_amount)),
                        sub_total: record.total_amount == null ? undefined : Number(record.total_amount),
                        tax_amount: record.vat_amount == null ? undefined : Number(record.vat_amount),
                        lines: Array.isArray(record.lines) ? record.lines : []
                    });
                    setIsPrintModalOpen(true);
                }
            },
            {
                type: 'divider'
            },
            {
                key: 'delete',
                icon: <DeleteOutlined />,
                danger: true,
                label: 'Xóa',
                onClick: () => deleteMutation.mutate(record.id)
            }
        ]
    });

    const columns = [
        { 
            title: 'Ngày đơn hàng', 
            dataIndex: 'order_date', 
            key: 'order_date', 
            width: 110, 
            align: 'center' as const,
            render: (d: string) => d ? dayjs(d).format('DD/MM/YYYY') : '-'
        },
        { 
            title: 'Số đơn hàng', 
            dataIndex: 'order_number', 
            key: 'order_number', 
            width: 125, 
            render: (t: string) => <span className="misa-table-link-bold">{t}</span> 
        },
        { 
            title: 'Hạn giao hàng', 
            dataIndex: 'delivery_date', 
            key: 'delivery_date', 
            width: 110, 
            align: 'center' as const,
            render: (d: string) => d ? dayjs(d).format('DD/MM/YYYY') : '-'
        },
        { 
            title: 'Nhà cung cấp', 
            dataIndex: 'supplier_name', 
            key: 'supplier_name', 
            minWidth: 200,
            render: (t: string, r: any) => (
                <div className="misa-cell-title-box">
                    <div className="misa-cell-main-title">{t || '—'}</div>
                    <div className="misa-cell-sub-title">MST: {r.tax_code || '—'}</div>
                </div>
            )
        },
        { title: 'Diễn giải', dataIndex: 'description', key: 'description', minWidth: 200 },
        { 
            title: 'Tổng tiền', 
            dataIndex: 'grand_total', 
            key: 'grand_total', 
            width: 150, 
            align: 'right' as const,
            render: (v: number) => <span className="misa-table-summary-total-800">{new Intl.NumberFormat('vi-VN').format(v || 0)} ₫</span>
        },
        { 
            title: 'Tình trạng', 
            dataIndex: 'status', 
            key: 'status', 
            width: 140, 
            align: 'center' as const,
            render: (s: string) => {
                if (s === 'pending') return <span>Chưa thực hiện</span>;
                if (s === 'processing') return <span>Đang thực hiện</span>;
                if (s === 'completed') return <span>Hoàn thành</span>;
                return <span className="misa-text-disabled">Hủy bỏ</span>;
            }
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 180,
            align: 'center' as const,
            render: (_: any, r: any) => (
                <div className="misa-table-cell-actions">
                    <Dropdown menu={getActionMenu(r)} trigger={['click']} placement="bottomRight">
                        <span className="misa-dropdown-arrow">
                            ▼
                        </span>
                    </Dropdown>
                </div>
            )
        }
    ];

    return (
        <PageShell
            className="apple-ledger-page"
            title={<PageHeader
                eyebrow="MUA HÀNG"
                title="Đơn mua hàng"
                description="Quản lý và theo dõi tiến độ thực hiện các đơn mua hàng từ nhà cung cấp."
            />}
            toolbar={(
                <PageToolbar
                    filters={(
                        <div className="ui-page-toolbar__filter-group flex items-center gap-2">
                            <Input
                                placeholder="Tìm kiếm số đơn hàng, nhà cung cấp..."
                                prefix={<SearchOutlined className="misa-color-muted" />}
                                className="misa-w-280"
                                allowClear
                                value={searchText}
                                onChange={e => setSearchText(e.target.value)}
                            />
                            <Select
                                value={statusFilter}
                                onChange={setStatusFilter}
                                className="misa-w-160"
                                options={[
                                    { value: 'all', label: 'Tất cả tình trạng' },
                                    { value: 'pending', label: 'Chưa thực hiện' },
                                    { value: 'processing', label: 'Đang thực hiện' },
                                    { value: 'completed', label: 'Hoàn thành' },
                                ]}
                            />
                        </div>
                    )}
                    actions={(
                        <div className="ui-page-toolbar__action-group flex items-center gap-2">
                            <Button
                                icon={<ReloadOutlined />}
                                className="misa-btn-tool"
                                title="Làm mới (F5)"
                                onClick={() => void refetchOrders()}
                            />
                            <Button
                                icon={<PrinterOutlined />}
                                className="misa-btn-tool"
                                title="In danh sách"
                                onClick={() => window.print()}
                            />
                            <Button
                                type="primary"
                                className="misa-btn-primary"
                                icon={<PlusOutlined />}
                                onClick={handleOpenCreateModal}
                            >
                                Thêm đơn mua hàng
                            </Button>
                        </div>
                    )}
                />
            )}
        >
            <DataTableSurface className="misa-voucher-surface">

            {isOrdersError && (
                <Alert
                    className="mb-3"
                    type="error"
                    showIcon
                    message="Không thể tải danh sách đơn mua hàng"
                    description="Không hiển thị dữ liệu thay thế; hãy thử tải lại danh sách đơn mua hàng."
                    action={<Button size="small" onClick={() => void refetchOrders()}>Thử lại danh sách đơn mua hàng</Button>}
                />
            )}

            <div className="misa-table-card-auto">
                <Table 
                    className="misa-voucher-table"
                    columns={columns}
                    dataSource={orderList}
                    rowKey="id"
                    loading={isLoading}
                    pagination={false}
                    size="small"
                    summary={() => {
                        const hasMissingTotal = orderList.some((o: any) => o?.grand_total == null && o?.total_amount == null);
                        const totalSum = hasMissingTotal ? null : orderList.reduce((acc: number, o: any) => acc + Number(o.grand_total ?? o.total_amount), 0);
                        return (
                            <Table.Summary fixed>
                                <Table.Summary.Row className="misa-table-summary-row">
                                    <Table.Summary.Cell index={0} colSpan={5}>
                                        <span>Tổng cộng ({orderList.length} đơn hàng)</span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={1} align="right">
                                        <span className="misa-table-summary-total-800">
                                            {totalSum == null ? '—' : `${new Intl.NumberFormat('vi-VN').format(totalSum)} ₫`}
                                        </span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={2} colSpan={2}></Table.Summary.Cell>
                                </Table.Summary.Row>
                            </Table.Summary>
                        );
                    }}
                />
            </div>

            {/* Exact MISA AMIS Custom Frame Modal for PO */}
            <Modal
                open={isModalOpen}
                onCancel={() => setIsModalOpen(false)}
                width="96vw"
                style={{ top: 16, maxWidth: 1320, paddingBottom: 0 }}
                footer={null}
                closable={false}
                destroyOnHidden
                className="misa-custom-modal misa-voucher-ant-modal"
            >
                <div className="misa-modal-window" style={{ width: '100%', maxWidth: '100%', maxHeight: 'calc(95vh - 32px)' }}>
                        
                        {/* 1. Modal Header Bar */}
                        <div className="misa-modal-top-bar">
                            <div className="misa-modal-top-bar-left">
                                <span className="misa-modal-title-text">
                                    {editingOrder?.id ? `Sửa Đơn mua hàng ${form.getFieldValue('order_number') || '—'}` : `Đơn mua hàng ${form.getFieldValue('order_number') || '—'}`}
                                </span>
                                <Input 
                                    prefix={<SearchOutlined className="misa-icon-muted" />} 
                                    placeholder="Nhập số đơn đặt hàng" 
                                    className="misa-input misa-w-220"
                                />
                            </div>

                            <div className="misa-modal-top-bar-right">
                                <button type="button" className="misa-btn-plain">
                                    <FullscreenOutlined />
                                </button>
                                <button type="button" onClick={() => setIsModalOpen(false)} className="misa-btn-plain">
                                    <CloseOutlined />
                                </button>
                            </div>
                        </div>

                        {/* 2. Modal Body Form */}
                        <div className="misa-modal-scroll-body">
                            <Form form={form} layout="vertical">
                                {/* Master Section (2 Columns) */}
                                <div className="misa-master-layout misa-master-card">
                                    {/* Left: General Master Info */}
                                    <div className="misa-master-left misa-form-grid">
                                        {/* Row 1 */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label required">Nhà cung cấp</div>
                                            <div className="misa-input-group">
                                                <Form.Item name="supplier_id" noStyle rules={[{ required: true, message: 'Chọn nhà cung cấp' }]}>
                                                    <Select 
                                                        showSearch 
                                                        variant="borderless" 
                                                        className="misa-w-full"
                                                        placeholder="Chọn NCC"
                                                        onChange={handleSupplierChange}
                                                        options={supplierList?.map((s: any) => ({ value: s.id, label: `${s.code} - ${s.name}` }))}
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

                                        {/* Row 2 */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Mã số thuế</div>
                                            <Form.Item name="tax_code" noStyle>
                                                <Input className="misa-input" placeholder="Mã số thuế" />
                                            </Form.Item>
                                        </div>
                                        <div className="misa-col-8">
                                            <div className="misa-field-label">Địa chỉ</div>
                                            <Form.Item name="supplier_address" noStyle>
                                                <Input className="misa-input" placeholder="Địa chỉ đối tác" />
                                            </Form.Item>
                                        </div>

                                        {/* Row 3 */}
                                        <div className="misa-col-4">
                                            <div className="misa-field-label">Người liên hệ</div>
                                            <Form.Item name="contact_person" noStyle>
                                                <Input className="misa-input" placeholder="Người liên hệ" />
                                            </Form.Item>
                                        </div>
                                        <div className="misa-col-8">
                                            <div className="misa-field-label">Diễn giải</div>
                                            <Form.Item name="description" noStyle>
                                                <Input className="misa-input" placeholder="Nội dung đơn đặt mua hàng..." />
                                            </Form.Item>
                                        </div>

                                        {/* Row 4: NV, Điều khoản TT, Số ngày nợ, Hạn thanh toán */}
                                        <div className="misa-col-3">
                                            <div className="misa-field-label">Nhân viên mua hàng</div>
                                            <div className="misa-input-group">
                                                <Form.Item name="employee_id" noStyle>
                                                    <Select 
                                                        showSearch 
                                                        variant="borderless" 
                                                        className="misa-w-full"
                                                        placeholder="Chọn NV"
                                                        options={employeeList?.map((e: any) => ({ value: e.id, label: `${e.code} - ${e.name}` }))}
                                                    />
                                                </Form.Item>
                                                <button type="button" className="misa-plus-btn" onClick={() => setIsEmployeeModalVisible(true)}>
                                                    <PlusOutlined className="misa-btn-plus-icon-sm" />
                                                </button>
                                            </div>
                                        </div>
                                        <div className="misa-col-3">
                                            <div className="misa-field-label">Điều khoản TT</div>
                                            <div className="misa-input-group">
                                                <Form.Item name="payment_terms" noStyle initialValue="ĐKTT30">
                                                    <Select 
                                                        variant="borderless" 
                                                        className="misa-w-full"
                                                        onChange={handlePaymentTermChange}
                                                        options={termList?.map((t: any) => ({ value: t.code, label: `${t.code} - ${t.name}` }))}
                                                    />
                                                </Form.Item>
                                                <button type="button" className="misa-plus-btn" onClick={() => setIsPaymentTermModalVisible(true)}>
                                                    <PlusOutlined className="misa-btn-plus-icon-sm" />
                                                </button>
                                            </div>
                                        </div>
                                        <div className="misa-col-3">
                                            <div className="misa-field-label">Số ngày được nợ</div>
                                            <Form.Item name="due_days" noStyle initialValue={30}>
                                                <InputNumber 
                                                    className="misa-input misa-w-full" 
                                                    onChange={handleDueDaysChange}
                                                />
                                            </Form.Item>
                                        </div>
                                        <div className="misa-col-3">
                                            <div className="misa-field-label">Hạn thanh toán</div>
                                            <Form.Item name="payment_due_date" noStyle>
                                                <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                            </Form.Item>
                                        </div>
                                    </div>

                                    {/* Right: Voucher Date, Code, Status & Total Box */}
                                    <div className="misa-master-right">
                                        <div className="misa-flex-col misa-gap-6">
                                            <div className="misa-flex-between">
                                                <span className="misa-field-label misa-w-110">Ngày đơn hàng:</span>
                                                <Form.Item name="order_date" noStyle rules={[{ required: true }]}>
                                                    <DatePicker 
                                                        className="misa-input misa-w-180" 
                                                        format="DD/MM/YYYY" 
                                                        onChange={(d) => {
                                                            const days = form.getFieldValue('due_days') || 0;
                                                            form.setFieldValue('payment_due_date', d ? dayjs(d).add(days, 'day') : null);
                                                        }}
                                                    />
                                                </Form.Item>
                                            </div>
                                            <div className="misa-flex-between">
                                                <span className="misa-field-label misa-w-110">Số đơn hàng:</span>
                                                <Form.Item name="order_number" noStyle rules={[{ required: true }]}>
                                                    <Input className="misa-input misa-w-180 misa-table-link-bold" />
                                                </Form.Item>
                                            </div>
                                            <div className="misa-flex-between">
                                                <span className="misa-field-label misa-w-110 required">Tình trạng:</span>
                                                <Form.Item name="status" noStyle initialValue="pending">
                                                    <Select 
                                                        className="misa-input misa-w-180" 
                                                        options={[
                                                            { value: 'pending', label: 'Chưa thực hiện' },
                                                            { value: 'processing', label: 'Đang thực hiện' },
                                                            { value: 'completed', label: 'Hoàn thành' },
                                                            { value: 'cancelled', label: 'Hủy bỏ' },
                                                        ]}
                                                    />
                                                </Form.Item>
                                            </div>
                                            <div className="misa-flex-between">
                                                <span className="misa-field-label misa-w-110">Hạn giao hàng:</span>
                                                <Form.Item name="delivery_date" noStyle>
                                                    <DatePicker className="misa-input misa-w-180" format="DD/MM/YYYY" />
                                                </Form.Item>
                                            </div>
                                        </div>

                                        <div className="misa-total-box">
                                            <div className="total-label">TỔNG TIỀN THANH TOÁN</div>
                                            <div className="total-amount">
                                                {new Intl.NumberFormat('vi-VN').format(grandPOTotal)} ₫
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* 3. Grid Detail Area with Sub-Toolbar */}
                                <div className="misa-grid-container misa-mt-12">
                                    {/* Sub-toolbar */}
                                    <div className="misa-grid-subtoolbar">
                                        <div className="misa-flex-center misa-gap-16">
                                            <div className="misa-grid-tab-btn active">
                                                1. Hàng tiền
                                            </div>
                                        </div>

                                        <div className="misa-flex-center misa-gap-8">
                                            <span className="misa-field-label">Chiết khấu:</span>
                                            <Select 
                                                value={discountMode}
                                                onChange={setDiscountMode}
                                                size="small"
                                                className="misa-input misa-w-180"
                                                options={[
                                                    { value: 'none', label: 'Không chiết khấu' },
                                                    { value: 'by_item', label: 'Theo từng mặt hàng' },
                                                    { value: 'by_percent', label: 'Theo % trên tổng đơn' },
                                                    { value: 'by_amount', label: 'Theo số tiền trên tổng đơn' },
                                                ]}
                                            />
                                        </div>
                                    </div>

                                    {/* Grid Table */}
                                    <div className="misa-grid-scroll-box">
                                        <Form.List name="lines">
                                            {(fields, { remove }) => (
                                                <table className="misa-voucher-table">
                                                    <thead>
                                                        <tr>
                                                            <th className="misa-col-w-36 misa-text-center">#</th>
                                                            <th className="misa-col-w-160">Mã hàng</th>
                                                            <th className="misa-col-min-w-220">Tên hàng</th>
                                                            <th className="misa-col-w-65">ĐVT</th>
                                                            <th className="misa-col-w-85 misa-text-right">Số lượng</th>
                                                            <th className="misa-col-w-95 misa-text-right">Số lượng nhận</th>
                                                            <th className="misa-col-w-120 misa-text-right">Đơn giá</th>
                                                            <th className="misa-col-w-130 misa-text-right">Thành tiền</th>
                                                            {discountMode === 'by_item' && (
                                                                <>
                                                                    <th className="misa-col-w-75 misa-text-right">% CK</th>
                                                                    <th className="misa-col-w-110 misa-text-right">Tiền CK</th>
                                                                </>
                                                            )}
                                                            <th className="misa-col-w-80 misa-text-right">% Thuế GTGT</th>
                                                            <th className="misa-col-w-110 misa-text-right">Tiền thuế GTGT</th>
                                                            <th className="misa-col-w-36 misa-text-center"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {fields.map((field, index) => (
                                                            <tr key={field.key}>
                                                                <td className="misa-text-center">{index + 1}</td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'item_id']} noStyle>
                                                                        <Select 
                                                                            showSearch 
                                                                            variant="borderless" 
                                                                            className="misa-w-full"
                                                                            placeholder="Chọn mã..."
                                                                            onChange={val => handleItemChange(index, val)}
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
                                                                            options={itemList?.map((it: any) => ({
                                                                                value: it.id,
                                                                                label: it.code,
                                                                                itemCode: it.code,
                                                                                itemName: it.name,
                                                                                itemStock: it.stock_quantity ?? it.stock ?? it.on_hand ?? it.minimum_stock,
                                                                                itemPrice: it.cost_price ?? it.purchase_price,
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
                                                                        <input className="misa-table-input" placeholder="Tên hàng hóa..." />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'unit']} noStyle initialValue="Cái">
                                                                        <input className="misa-table-input" />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'quantity']} noStyle initialValue={1}>
                                                                        <InputNumber 
                                                                            variant="borderless" 
                                                                            className="misa-w-full misa-text-right" 
                                                                            onChange={val => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                const q = Number(val) || 0;
                                                                                const p = Number(cur[index]?.unit_price) || 0;
                                                                                const amt = q * p;
                                                                                const tr = typeof cur[index]?.tax_rate === 'number' ? cur[index]?.tax_rate : 0;
                                                                                cur[index].amount = amt;
                                                                                cur[index].tax_amount = amt * (tr / 100);
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right misa-text-blue">0,00</td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'unit_price']} noStyle initialValue={0}>
                                                                        <InputNumber 
                                                                            variant="borderless" 
                                                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                            className="misa-w-full misa-text-right" 
                                                                            onChange={val => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                const p = Number(val) || 0;
                                                                                const q = Number(cur[index]?.quantity) || 0;
                                                                                const amt = q * p;
                                                                                const tr = typeof cur[index]?.tax_rate === 'number' ? cur[index]?.tax_rate : 0;
                                                                                cur[index].amount = amt;
                                                                                cur[index].tax_amount = amt * (tr / 100);
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right misa-text-bold">
                                                                    {new Intl.NumberFormat('vi-VN').format(formLines[index]?.amount || 0)}
                                                                </td>

                                                                {discountMode === 'by_item' && (
                                                                    <>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'discount_rate']} noStyle initialValue={0}>
                                                                                <InputNumber 
                                                                                    variant="borderless" 
                                                                                    className="misa-w-full misa-text-right" 
                                                                                    onChange={val => {
                                                                                        const cur = form.getFieldValue('lines') || [];
                                                                                        const rate = Number(val) || 0;
                                                                                        const amt = Number(cur[index]?.amount) || 0;
                                                                                        const discAmt = amt * (rate / 100);
                                                                                        cur[index].discount_rate = rate;
                                                                                        cur[index].discount_amount = discAmt;
                                                                                        form.setFieldsValue({ lines: [...cur] });
                                                                                    }}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'discount_amount']} noStyle initialValue={0}>
                                                                                <InputNumber variant="borderless" className="misa-w-full misa-text-right" />
                                                                            </Form.Item>
                                                                        </td>
                                                                    </>
                                                                )}

                                                                <td className="misa-text-right">
                                                                    <Form.Item name={[field.name, 'tax_rate']} noStyle initialValue={0}>
                                                                        <Select 
                                                                            variant="borderless" 
                                                                            className="misa-w-full"
                                                                            onChange={val => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                const amt = Number(cur[index]?.amount) || 0;
                                                                                const tr = typeof val === 'number' ? val : 0;
                                                                                cur[index].tax_rate = val;
                                                                                cur[index].tax_amount = amt * (tr / 100);
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                            options={[
                                                                                { value: 0, label: '0%' },
                                                                                { value: 5, label: '5%' },
                                                                                { value: 8, label: '8%' },
                                                                                { value: 10, label: '10%' },
                                                                                { value: 'KCT', label: 'KCT' },
                                                                                { value: 'KTT', label: 'KTT' }
                                                                            ]}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right misa-text-bold misa-text-green">
                                                                    {new Intl.NumberFormat('vi-VN').format(formLines[index]?.tax_amount || 0)}
                                                                </td>
                                                                <td className="misa-text-center">
                                                                    <button type="button" className="misa-btn-plain-danger" onClick={() => remove(index)}>
                                                                        <DeleteOutlined />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                        <tr className="summary-row">
                                                            <td></td>
                                                            <td>Tổng cộng</td>
                                                            <td colSpan={2}></td>
                                                            <td className="misa-text-right misa-table-summary-total-800">{totalPOQty}</td>
                                                            <td></td>
                                                            <td></td>
                                                            <td className="misa-text-right misa-table-summary-total-800">{new Intl.NumberFormat('vi-VN').format(totalPOAmount)}</td>
                                                            {discountMode === 'by_item' && (
                                                                <>
                                                                    <td></td>
                                                                    <td className="misa-text-right misa-table-summary-total-800 misa-text-danger">{new Intl.NumberFormat('vi-VN').format(totalPODiscount)}</td>
                                                                </>
                                                            )}
                                                            <td></td>
                                                            <td className="misa-text-right misa-table-summary-total-800 misa-text-green">{new Intl.NumberFormat('vi-VN').format(totalPOTax)}</td>
                                                            <td></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            )}
                                        </Form.List>
                                    </div>

                                    {/* Action Buttons Below Table */}
                                    <div className="misa-grid-footer-bar">
                                        <div className="misa-flex-center misa-gap-16">
                                            <span className="misa-cell-sub-title">Tổng số: <strong>{formLines.length}</strong> dòng</span>
                                            <div className="misa-flex-center misa-gap-8">
                                                <button 
                                                    type="button" 
                                                    className="misa-btn-tool"
                                                    onClick={() => {
                                                        const cur = form.getFieldValue('lines') || [];
                                                        form.setFieldsValue({
                                                            lines: [
                                                                ...cur,
                                                                { 
                                                                    item_id: itemList?.[0]?.id,
                                                                    item_code: itemList?.[0]?.code || '',
                                                                    item_name: itemList?.[0]?.name || '',
                                                                    unit: itemList?.[0]?.unit || 'Cái',
                                                                    quantity: 1, 
                                                                    received_quantity: 0,
                                                                    unit_price: 0, 
                                                                    amount: 0, 
                                                                    discount_rate: 0,
                                                                    discount_amount: 0,
                                                                    tax_rate: 0,
                                                                    tax_amount: 0
                                                                }
                                                            ]
                                                        });
                                                    }}
                                                >
                                                    <PlusOutlined className="misa-icon-green" /> <span>Thêm dòng (F7)</span>
                                                </button>
                                                <Popconfirm
                                                    title="Xác nhận xóa hết dòng"
                                                    description="Bạn có chắc chắn muốn xóa toàn bộ các dòng không?"
                                                    okText="Xóa"
                                                    cancelText="Hủy"
                                                    okButtonProps={{ danger: true }}
                                                    onConfirm={() => {
                                                        form.setFieldValue('lines', []);
                                                        message.success('Đã xóa toàn bộ dòng');
                                                    }}
                                                >
                                                    <button type="button" className="misa-btn-tool">
                                                        <DeleteOutlined className="misa-icon-red" /> <span>Xóa hết dòng</span>
                                                    </button>
                                                </Popconfirm>
                                            </div>
                                        </div>

                                        <div className="misa-flex-center misa-gap-12">
                                            <button type="button" className="misa-btn-tool misa-text-blue">
                                                <PaperClipOutlined /> Đính kèm tệp
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                {/* 4. Footer Information, Attachments & Financial Summary Box */}
                                <div className="misa-form-grid misa-mt-12">
                                    {/* Left 4 cols: Delivery & Terms */}
                                    <div className="misa-col-4 misa-flex-col misa-gap-8">
                                        <div>
                                            <div className="misa-field-label">Địa điểm giao hàng</div>
                                            <Form.Item name="delivery_address" noStyle>
                                                <Input.TextArea rows={2} className="misa-input" placeholder="Địa chỉ giao nhận hàng..." />
                                            </Form.Item>
                                        </div>
                                        <div>
                                            <div className="misa-field-label">Điều khoản khác</div>
                                            <Form.Item name="other_terms" noStyle>
                                                <Input.TextArea rows={2} className="misa-input" placeholder="Các điều khoản thương mại khác..." />
                                            </Form.Item>
                                        </div>
                                    </div>

                                    {/* Middle 4 cols: Drag & Drop Attachment Box */}
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Đính kèm tệp</div>
                                        <div className="misa-dropzone-box">
                                            <PaperClipOutlined className="misa-footer-upload-icon misa-icon-muted" />
                                            <span className="misa-field-label">
                                                Kéo, thả tệp vào đây hoặc <strong className="misa-text-blue">bấm vào đây</strong>
                                            </span>
                                            <span className="misa-cell-sub-title">
                                                Dung lượng tối đa 5MB.
                                            </span>
                                        </div>
                                    </div>

                                    {/* Right 4 cols: Full Financial Summary Block */}
                                    <div className="misa-col-4 misa-summary-financial-card">
                                        <div className="misa-flex-col misa-gap-6">
                                            <div className="misa-summary-row-line">
                                                <span>Tổng tiền hàng:</span>
                                                <span className="misa-table-amount-dark">{new Intl.NumberFormat('vi-VN').format(totalPOAmount)} ₫</span>
                                            </div>
                                            <div className="misa-summary-row-line">
                                                <span>Tiền chiết khấu:</span>
                                                <span className="misa-text-bold misa-text-danger">-{new Intl.NumberFormat('vi-VN').format(totalPODiscount)} ₫</span>
                                            </div>
                                            <div className="misa-summary-row-line">
                                                <span>Thuế GTGT:</span>
                                                <span className="misa-text-bold misa-text-green">{new Intl.NumberFormat('vi-VN').format(totalPOTax)} ₫</span>
                                            </div>
                                        </div>

                                        <div className="misa-summary-grand-total">
                                            <span className="misa-field-label misa-text-bold">TỔNG THANH TOÁN:</span>
                                            <span className="misa-summary-grand-value">
                                                {new Intl.NumberFormat('vi-VN').format(grandPOTotal)} ₫
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </Form>
                        </div>

                        {/* 5. Modal Footer Action Bar */}
                        <div className="misa-modal-bottom-bar">
                            <Button onClick={() => setIsModalOpen(false)} className="misa-btn-secondary">
                                Hủy (Esc)
                            </Button>

                            <div className="misa-flex-center misa-gap-8">
                                <Button 
                                    onClick={() => form.validateFields().then(values => {
                                        saveMutation.mutate(values);
                                        handleOpenCreateModal();
                                    })}
                                    className="misa-btn-secondary misa-text-bold"
                                >
                                    Lưu và thêm mới
                                </Button>
                                <Button 
                                    type="primary" 
                                    className="misa-btn-primary" 
                                    onClick={() => form.validateFields().then(values => saveMutation.mutate(values))}
                                >
                                    Lưu
                                </Button>
                            </div>
                        </div>
                    </div>
            </Modal>

            {/* Quick Add Supplier Modal */}
            <QuickAddContactModal 
                open={isSupplierModalVisible}
                onCancel={() => setIsSupplierModalVisible(false)}
                contactType="supplier"
                onSuccess={(newSupplier) => {
                    queryClient.invalidateQueries({ queryKey: ['suppliers'] });
                    form.setFieldsValue({
                        supplier_id: newSupplier.id,
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
                    handleItemChange(activeRowIndex, newItem.id);
                }}
            />

            {/* Purchase Order Print Modal */}
            <VoucherPrintModal
                open={isPrintModalOpen}
                type="PO"
                data={printData}
                onCancel={() => setIsPrintModalOpen(false)}
            />
            </DataTableSurface>
        </PageShell>
    );
};

export default PurchaseOrders;
