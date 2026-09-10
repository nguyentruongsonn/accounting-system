import React, { useState } from 'react';
import { ConfigProvider, Table, Button, Form, Input, InputNumber, Select, DatePicker, Tag, Dropdown, Alert } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import type { MenuProps } from 'antd';
import { 
    PlusOutlined, 
    DeleteOutlined, 
    DownOutlined, 
    SettingOutlined, 
    ReloadOutlined,
    SearchOutlined,
    LinkOutlined,
    CopyOutlined,
    EditOutlined,
    CheckCircleOutlined,
    PrinterOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { formatDate } from '../../utils/dateUtils';
import { 
    MisaMasterCard,
    MisaGridActionFooter,
    MisaTableSummaryBar,
    MisaTotalCard,
    QuickAddContactModal,
    QuickAddItemModal,
    MultiColumnContactSelect,
    ReferenceVoucherModal,
    VoucherPrintModal,
    useVoucherTotals
} from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

interface SalesOrderLine {
    key?: string;
    item_id?: number;
    item_code?: string;
    item_name?: string;
    unit?: string;
    quantity?: number;
    delivered_quantity?: number;
    invoiced_quantity?: number;
    unit_price?: number;
    amount?: number;
    discount_rate?: number;
    discount_amount?: number;
    tax_rate?: number;
    tax_amount?: number;
    total_amount?: number;
    delivery_date?: string;
    description?: string;
    note?: string;
}

interface SalesOrderRecord {
    id: number;
    order_number: string;
    order_date: string;
    delivery_date: string;
    customer_name: string;
    contact_person?: string;
    description: string;
    total_amount: number;
    status: string;
    delivery_status: string;
    invoice_status: string;
    lines?: SalesOrderLine[];
    referenced_vouchers?: any[];
}

function persistedOrder(response: any): any {
    return response?.data?.data ?? response?.data;
}

function hasOrderEvidence(response: any): boolean {
    const resource = persistedOrder(response);
    return Boolean(resource && resource.id !== undefined && resource.id !== null);
}

function parseSalesOrderCollection<T = SalesOrderRecord>(value: unknown, resource = 'sales data'): T[] {
    if (Array.isArray(value)) return value as T[];
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: T[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
}

export const SalesOrders: React.FC = () => {
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [isCustomerModalVisible, setIsCustomerModalVisible] = useState(false);
    const [editingRecord, setEditingRecord] = useState<SalesOrderRecord | null>(null);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printData, setPrintData] = useState<any>(null);
    const [datePreset, setDatePreset] = useState('Năm nay');
    const [isRefModalVisible, setIsRefModalVisible] = useState(false);
    const [referencedVouchers, setReferencedVouchers] = useState<any[]>([]);
    const [activeGridTab, setActiveGridTab] = useState<'items' | 'terms' | 'reference'>('items');
    const [searchText, setSearchText] = useState('');
    const [isItemModalVisible, setIsItemModalVisible] = useState(false);
    const [activeRowIndex, setActiveRowIndex] = useState<number | null>(null);

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const formLines: SalesOrderLine[] = Form.useWatch('lines', form) || [];
    const totals = useVoucherTotals(formLines);
    const orderNumber = Form.useWatch('order_number', form);

    const { data: orders = [], isLoading, isError: isOrdersError, refetch } = useQuery({
        queryKey: ['sales-orders'],
        queryFn: async () => {
            const { data } = await api.get('/sales/orders');
            return parseSalesOrderCollection(data);
        },
    });

    const { data: customers = [], isError: isCustomersError, refetch: refetchCustomers } = useQuery({
        queryKey: ['customers'],
        queryFn: async () => {
            const { data } = await api.get('/master/customers');
            return parseSalesOrderCollection<any>(data, 'customers');
        },
    });

    const { data: employees = [], isError: isEmployeesError, refetch: refetchEmployees } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parseSalesOrderCollection<any>(data, 'employees');
        },
    });

    const { data: items = [], isError: isItemsError, refetch: refetchItems } = useQuery({
        queryKey: ['inventory-items'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parseSalesOrderCollection<any>(data, 'inventory items');
        },
    });

    const hasCatalogueError = isCustomersError || isEmployeesError || isItemsError;
    const retryCatalogues = () => {
        void Promise.all([
            isCustomersError ? refetchCustomers() : undefined,
            isEmployeesError ? refetchEmployees() : undefined,
            isItemsError ? refetchItems() : undefined,
        ]);
    };

    const createMutation = useMutation({
        mutationFn: async (payloadWithFlags: any) => {
            const { andNew, andPrint, ...values } = payloadWithFlags;
            const payload = {
                customer_id: values.customer_id,
                customer_name: customers?.find((s: any) => s.id === values.customer_id)?.name || values.customer_name,
                customer_address: values.customer_address,
                tax_code: values.tax_code,
                contact_person: values.contact_person,
                contact_phone: values.contact_phone,
                contact_email: values.contact_email,
                employee_id: values.employee_id,
                payment_terms: values.payment_terms,
                due_days: values.due_days ?? 30,
                delivery_address: values.delivery_address,
                other_terms: values.other_terms,
                currency: 'VND',
                exchange_rate: 1,
                order_number: values.order_number,
                order_date: values.order_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                delivery_date: values.delivery_date?.format('YYYY-MM-DD') || dayjs().add(15, 'day').format('YYYY-MM-DD'),
                description: values.description,
                status: values.status || 'pending',
                delivery_status: values.delivery_status || 'not_delivered',
                invoice_status: values.invoice_status || 'not_invoiced',
                lines: values.lines?.map((line: any) => ({
                    item_id: line.item_id,
                    item_code: items?.find((it: any) => it.id === line.item_id)?.code,
                    item_name: line.description || items?.find((it: any) => it.id === line.item_id)?.name,
                    description: line.description || values.description,
                    unit: line.unit || 'Cái',
                    quantity: line.quantity ?? 1,
                    delivered_quantity: line.delivered_quantity || 0,
                    invoiced_quantity: line.invoiced_quantity || 0,
                    unit_price: line.unit_price ?? 0,
                    amount: (line.quantity ?? 1) * (line.unit_price ?? 0),
                    discount_rate: line.discount_rate || 0,
                    discount_amount: line.discount_amount || 0,
                    tax_rate: line.tax_rate ?? 10,
                    tax_amount: line.tax_amount || 0,
                    total_amount: (line.amount || 0) + (line.tax_amount || 0),
                    note: line.note,
                })),
                referenced_vouchers: referencedVouchers,
            };

            if (editingRecord) {
                return await api.put(`/sales/orders/${editingRecord.id}`, payload);
            } else {
                return await api.post('/sales/orders', payload);
            }
        },
        onSuccess: (response, variables) => {
            if (!hasOrderEvidence(response)) {
                message.error('Máy chủ không trả về đơn hàng đã lưu; không thể báo thành công.');
                return;
            }
            queryClient.invalidateQueries({ queryKey: ['sales-orders'] });
            message.success(editingRecord ? 'Cập nhật đơn đặt hàng thành công' : 'Lưu đơn đặt hàng thành công');

            if (variables?.andNew) {
                handleOpenCreate();
            } else {
                setIsModalVisible(false);
                setEditingRecord(null);
            }
        },
        onError: (err: any) => {
            message.error(err?.response?.data?.error || 'Có lỗi xảy ra khi lưu đơn đặt hàng');
        },
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => {
            return await api.post(`/sales/orders/${id}/duplicate`);
        },
        onSuccess: (response) => {
            if (!hasOrderEvidence(response)) {
                message.error('Máy chủ không trả về đơn hàng nhân bản đã lưu; không thể báo thành công.');
                return;
            }
            queryClient.invalidateQueries({ queryKey: ['sales-orders'] });
            message.success('Nhân bản đơn hàng thành công');
        },
    });

    const statusMutation = useMutation({
        mutationFn: async ({ id, status }: { id: number; status: string }) => {
            return await api.post(`/sales/orders/${id}/status`, { status });
        },
        onSuccess: (response) => {
            if (!hasOrderEvidence(response)) {
                message.error('Máy chủ không trả về trạng thái đơn hàng đã lưu; không thể báo thành công.');
                return;
            }
            queryClient.invalidateQueries({ queryKey: ['sales-orders'] });
            message.success('Cập nhật trạng thái thành công');
        },
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return await api.delete(`/sales/orders/${id}`);
        },
        onSuccess: (response) => {
            if (response?.data?.success !== true || typeof response?.data?.message !== 'string') {
                message.error('Máy chủ không xác nhận đã xóa đơn hàng; không thể báo thành công.');
                return;
            }
            queryClient.invalidateQueries({ queryKey: ['sales-orders'] });
            message.success('Xóa đơn hàng thành công');
        },
    });

    const handleOpenCreate = async () => {
        form.resetFields();
        setEditingRecord(null);
        setReferencedVouchers([]);
        setActiveGridTab('items');

        try {
            const { data } = await api.get('/sales/orders/next-code');
            const code = data?.code || data?.data;
            form.setFieldsValue({
                order_number: code,
                order_date: dayjs(),
                delivery_date: dayjs().add(15, 'day'),
                due_days: 30,
                status: 'pending',
                delivery_status: 'not_delivered',
                invoice_status: 'not_invoiced',
                lines: [{}],
            });
        } catch {
            form.setFieldsValue({
                order_number: undefined,
                order_date: dayjs(),
                delivery_date: dayjs().add(15, 'day'),
                due_days: 30,
                status: 'pending',
                lines: [{}],
            });
        }

        setIsModalVisible(true);
    };

    const handleEdit = (record: SalesOrderRecord) => {
        setEditingRecord(record);
        setReferencedVouchers(record.referenced_vouchers || []);
        setActiveGridTab('items');

        form.setFieldsValue({
            ...record,
            order_date: record.order_date ? dayjs(record.order_date) : dayjs(),
            delivery_date: record.delivery_date ? dayjs(record.delivery_date) : dayjs().add(15, 'day'),
            lines: record.lines?.map((l: any) => ({
                ...l,
                item_id: l.item_id,
                description: l.description || l.item_name,
                quantity: l.quantity,
                delivered_quantity: l.delivered_quantity,
                invoiced_quantity: l.invoiced_quantity,
                unit_price: l.unit_price,
                amount: l.amount,
                discount_rate: l.discount_rate,
                discount_amount: l.discount_amount,
                tax_rate: l.tax_rate,
                tax_amount: l.tax_amount,
            })) || [{}],
        });

        setIsModalVisible(true);
    };

    const handleItemChange = (index: number, itemId: number, explicitItem?: any) => {
        const item = explicitItem || items.find((i: any) => i.id === itemId);
        if (!item) return;

        const cur = form.getFieldValue('lines') || [];
        const qty = cur[index]?.quantity ?? 1;
        const price = item.selling_price ?? item.purchase_price ?? 0;
        const discRate = cur[index]?.discount_rate || 0;
        const taxRate = cur[index]?.tax_rate ?? item.tax_rate ?? 10;
        const amt = qty * price;
        const disc = amt * (discRate / 100);
        const tax = (amt - disc) * (taxRate / 100);

        cur[index] = {
            ...cur[index],
            item_id: item.id,
            item_code: item.code,
            item_name: item.name,
            description: item.name,
            unit: item.unit || 'Cái',
            unit_price: price,
            amount: amt,
            discount_amount: disc,
            tax_rate: taxRate,
            tax_amount: tax,
            total_amount: (amt - disc) + tax,
        };

        form.setFieldsValue({ lines: [...cur] });
    };

    const filteredOrders = orders.filter((o: SalesOrderRecord) => {
        if (!searchText) return true;
        const s = searchText.toLowerCase();
        return (
            o.order_number?.toLowerCase().includes(s) ||
            o.customer_name?.toLowerCase().includes(s) ||
            o.description?.toLowerCase().includes(s)
        );
    });

    const getStatusTag = (status: string) => {
        switch (status) {
            case 'confirmed':
                return <Tag color="blue">Đã xác nhận</Tag>;
            case 'processing':
                return <Tag color="orange">Đang xử lý</Tag>;
            case 'delivering':
                return <Tag color="gold">Đang giao</Tag>;
            case 'completed':
                return <Tag color="green">Hoàn thành</Tag>;
            case 'cancelled':
                return <Tag color="red">Đã hủy</Tag>;
            default:
                return <Tag color="default">Chờ duyệt</Tag>;
        }
    };

    const columns: ColumnsType<SalesOrderRecord> = [
        {
            title: 'Ngày đơn hàng',
            dataIndex: 'order_date',
            key: 'order_date',
            width: 110,
            render: (d) => formatDate(d),
            sorter: (a, b) => dayjs(a.order_date).unix() - dayjs(b.order_date).unix(),
        },
        {
            title: 'Số đơn hàng',
            dataIndex: 'order_number',
            key: 'order_number',
            width: 140,
            render: (text, r) => (
                <a className="misa-text-semibold text-blue-600 hover:underline" onClick={() => handleEdit(r)}>
                    {text}
                </a>
            ),
        },
        {
            title: 'Hạn giao hàng',
            dataIndex: 'delivery_date',
            key: 'delivery_date',
            width: 110,
            render: (d) => formatDate(d),
        },
        {
            title: 'Khách hàng',
            dataIndex: 'customer_name',
            key: 'customer_name',
            width: 220,
        },
        {
            title: 'Diễn giải',
            dataIndex: 'description',
            key: 'description',
            ellipsis: true,
        },
        {
            title: 'Tổng tiền',
            dataIndex: 'total_amount',
            key: 'total_amount',
            width: 150,
            align: 'right',
            render: (v) => <span className="misa-text-semibold">{new Intl.NumberFormat('vi-VN').format(v || 0)} ₫</span>,
        },
        {
            title: 'Trạng thái',
            dataIndex: 'status',
            key: 'status',
            width: 120,
            align: 'center',
            render: (s) => getStatusTag(s),
        },
        {
            title: 'Giao hàng',
            dataIndex: 'delivery_status',
            key: 'delivery_status',
            width: 120,
            align: 'center',
            render: (s) => {
                if (s === 'delivered') return <Tag color="green">Đã giao đủ</Tag>;
                if (s === 'partial') return <Tag color="orange">Giao 1 phần</Tag>;
                return <Tag color="default">Chưa giao</Tag>;
            },
        },
        {
            title: 'Hóa đơn',
            dataIndex: 'invoice_status',
            key: 'invoice_status',
            width: 120,
            align: 'center',
            render: (s) => {
                if (s === 'invoiced') return <Tag color="green">Đã xuất đủ</Tag>;
                if (s === 'partial') return <Tag color="orange">Xuất 1 phần</Tag>;
                return <Tag color="default">Chưa xuất</Tag>;
            },
        },
        {
            title: 'Thao tác',
            key: 'action',
            width: 130,
            align: 'center',
            render: (_, record) => {
                const menuItems: MenuProps['items'] = [
                    {
                        key: 'edit',
                        icon: <EditOutlined />,
                        label: 'Sửa',
                        onClick: () => handleEdit(record),
                    },
                    {
                        key: 'duplicate',
                        icon: <CopyOutlined />,
                        label: 'Nhân bản',
                        onClick: () => duplicateMutation.mutate(record.id),
                    },
                    {
                        key: 'print',
                        icon: <PrinterOutlined className="misa-icon-success" />,
                        label: 'In đơn đặt hàng',
                        onClick: () => {
                            setPrintData({
                                voucher_number: record.order_number,
                                order_number: record.order_number,
                                voucher_date: record.order_date,
                                order_date: record.order_date,
                                delivery_date: record.delivery_date,
                                customer_name: record.customer_name,
                                contact_person: record.contact_person,
                                description: record.description,
                                total_amount: record.total_amount == null ? undefined : Number(record.total_amount),
                                lines: Array.isArray(record.lines) ? record.lines : []
                            });
                            setIsPrintModalOpen(true);
                        },
                    },
                    {
                        key: 'confirm',
                        icon: <CheckCircleOutlined className="misa-icon-success" />,
                        label: 'Xác nhận đơn',
                        onClick: () => statusMutation.mutate({ id: record.id, status: 'confirmed' }),
                    },
                    {
                        type: 'divider',
                    },
                    {
                        key: 'delete',
                        icon: <DeleteOutlined />,
                        label: 'Xóa',
                        danger: true,
                        onClick: () => {
                            Modal.confirm({
                                title: 'Xác nhận xóa',
                                content: `Bạn có chắc muốn xóa đơn hàng ${record.order_number}?`,
                                okText: 'Xóa',
                                cancelText: 'Hủy',
                                okType: 'danger',
                                onOk: () => deleteMutation.mutate(record.id),
                            });
                        },
                    },
                ];

                return (
                    <Dropdown menu={{ items: menuItems }} trigger={['click']}>
                        <Button size="small" className="misa-btn-dropdown">
                            Thao tác <DownOutlined className="misa-fs-9" />
                        </Button>
                    </Dropdown>
                );
            },
        },
    ];

    const handleSaveForm = (andNew: boolean) => {
        form.validateFields().then((v) => {
            createMutation.mutate({ ...v, andNew });
        });
    };

    return (
        <PageShell>
            <PageToolbar />
            <DataTableSurface className="misa-voucher-surface">
            {/* Top Command Action Bar */}
            <div className="misa-action-bar">
                <div className="misa-action-bar-left">
                    <Select
                        value={datePreset}
                        onChange={setDatePreset}
                        className="misa-select-date-preset"
                        options={[
                            { value: 'Hôm nay', label: 'Hôm nay' },
                            { value: 'Tuần này', label: 'Tuần này' },
                            { value: 'Tháng này', label: 'Tháng này' },
                            { value: 'Năm nay', label: 'Năm nay' },
                        ]}
                    />
                    <Input
                        placeholder="Tìm kiếm số đơn hàng, khách hàng..."
                        prefix={<SearchOutlined className="misa-color-muted" />}
                        value={searchText}
                        onChange={(e) => setSearchText(e.target.value)}
                        className="misa-input-search"
                        allowClear
                    />
                </div>

                <div className="misa-action-bar-right">
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        onClick={handleOpenCreate}
                        className="misa-btn-primary"
                    >
                        Thêm đơn đặt hàng
                    </Button>
                    <Button className="misa-btn-tool" icon={<ReloadOutlined />} onClick={() => refetch()} title="Nạp lại">
                        Nạp
                    </Button>
                </div>
            </div>

            {/* Master Grid Table */}
            <div className="misa-grid-wrapper">
                {isOrdersError ? <Alert
                    type="error"
                    showIcon
                    title="Không thể tải danh sách đơn đặt hàng"
                    description="Dữ liệu chưa được xác minh từ máy chủ; không hiển thị danh sách rỗng thay thế."
                    action={<Button onClick={() => void refetch()}>Thử lại danh sách đơn đặt hàng</Button>}
                /> : <Table
                    columns={columns}
                    dataSource={filteredOrders}
                    rowKey="id"
                    loading={isLoading}
                    pagination={{ pageSize: 20, showSizeChanger: true }}
                    size="small"
                    className="misa-table"
                />}
            </div>

            {/* Total Summary Bar */}
            <MisaTableSummaryBar
                items={[
                    { label: 'Số lượng đơn hàng', value: filteredOrders.length, format: 'number' },
                    { 
                        label: 'Tổng tiền đơn hàng', 
                        value: filteredOrders.reduce((sum: number, o: SalesOrderRecord) => sum + Number(o.total_amount || 0), 0), 
                        format: 'currency', 
                        highlight: true 
                    }
                ]}
            />

            {/* Create/Edit Sales Order Modal */}
            <Modal
                title={
                    <div className="misa-modal-header">
                        <div className="misa-modal-header-left">
                            <span className="misa-modal-header-title">
                                {editingRecord ? `Đơn đặt hàng ${orderNumber}` : `Thêm Đơn đặt hàng mới ${orderNumber}`}
                            </span>
                        </div>
                        <div className="misa-modal-header-right">
                            <button type="button" className="misa-icon-btn">
                                <SettingOutlined />
                            </button>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                width="95vw"
                footer={
                    <ConfigProvider componentSize="small">
                        <div className="misa-modal-footer-container">
                            <div></div>
                            <div className="misa-flex-center misa-gap-8">
                                <button
                                    type="button"
                                    className="misa-btn-footer-secondary"
                                    onClick={() => setIsModalVisible(false)}
                                >
                                    Hủy (Esc)
                                </button>
                                <button
                                    type="button"
                                    className="misa-btn-footer-save"
                                    onClick={() => handleSaveForm(false)}
                                    disabled={createMutation.isPending || hasCatalogueError}
                                >
                                    Cất
                                </button>
                                <button
                                    type="button"
                                    className="misa-btn-footer-primary"
                                    onClick={() => handleSaveForm(true)}
                                    disabled={createMutation.isPending || hasCatalogueError}
                                >
                                    <span>Cất và Thêm</span>
                                    <DownOutlined />
                                </button>
                            </div>
                        </div>
                    </ConfigProvider>
                }
                className="misa-voucher-modal"
            >
                {hasCatalogueError && <Alert
                    type="error"
                    showIcon
                    title="Không thể tải danh mục cho đơn đặt hàng"
                    description="Không có dữ liệu thay thế; hãy tải lại danh mục khách hàng, nhân viên và hàng hóa trước khi lưu."
                    action={<Button size="small" onClick={retryCatalogues}>Thử lại danh mục đơn hàng</Button>}
                    className="misa-mb-8"
                />}
                <Form form={form} layout="vertical" size="small" className="misa-form-flex-col">
                    {/* Master Card Header */}
                    <MisaMasterCard>
                        <MisaMasterCard.Left>
                            <MisaMasterCard.FormGrid>
                                {/* Khách hàng */}
                                <div className="misa-col-4">
                                    <div className="misa-field-label required">Khách hàng</div>
                                    <Form.Item name="customer_id" noStyle rules={[{ required: true, message: 'Chọn khách hàng' }]}>
                                        <MultiColumnContactSelect
                                            placeholder="Chọn khách hàng..."
                                            options={customers?.map((c: any) => ({
                                                id: c.id,
                                                code: c.code,
                                                name: c.name,
                                                tax_code: c.tax_code,
                                                address: c.address,
                                                phone: c.phone,
                                                type: 'customer',
                                            }))}
                                            value={form.getFieldValue('customer_id')}
                                            onChange={(val, item) => {
                                                form.setFieldsValue({
                                                    customer_id: val,
                                                    customer_name: item?.name || '',
                                                    customer_address: item?.address || '',
                                                    delivery_address: item?.address || '',
                                                    tax_code: item?.tax_code || '',
                                                    contact_person: item?.name || '',
                                                    contact_phone: item?.phone || '',
                                                });
                                            }}
                                            onQuickAdd={() => setIsCustomerModalVisible(true)}
                                        />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-8">
                                    <div className="misa-field-label">Tên khách hàng</div>
                                    <Form.Item name="customer_name" noStyle>
                                        <Input className="misa-input" placeholder="Tên khách hàng" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-8">
                                    <div className="misa-field-label">Địa chỉ</div>
                                    <Form.Item name="customer_address" noStyle>
                                        <Input className="misa-input" placeholder="Địa chỉ khách hàng" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-4">
                                    <div className="misa-field-label">Mã số thuế</div>
                                    <Form.Item name="tax_code" noStyle>
                                        <Input className="misa-input" placeholder="Mã số thuế" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-4">
                                    <div className="misa-field-label">Người nhận</div>
                                    <Form.Item name="contact_person" noStyle>
                                        <Input className="misa-input" placeholder="Người nhận hàng" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-4">
                                    <div className="misa-field-label">Điện thoại</div>
                                    <Form.Item name="contact_phone" noStyle>
                                        <Input className="misa-input" placeholder="Điện thoại" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-4">
                                    <div className="misa-field-label">Nhân viên bán hàng</div>
                                    <Form.Item name="employee_id" noStyle>
                                        <Select
                                            showSearch
                                            placeholder="Chọn nhân viên"
                                            className="misa-w-full"
                                            allowClear
                                            options={employees?.map((e: any) => ({ value: e.id, label: `${e.code} - ${e.name}` }))}
                                        />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-8">
                                    <div className="misa-field-label">Địa chỉ giao hàng</div>
                                    <Form.Item name="delivery_address" noStyle>
                                        <Input className="misa-input" placeholder="Địa chỉ nhận hàng cụ thể" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-4">
                                    <div className="misa-field-label">Hạn thanh toán (ngày)</div>
                                    <Form.Item name="due_days" noStyle initialValue={30}>
                                        <InputNumber className="misa-w-full" />
                                    </Form.Item>
                                </div>

                                <div className="misa-col-12">
                                    <div className="misa-field-label">Diễn giải</div>
                                    <Form.Item name="description" noStyle>
                                        <Input className="misa-input" placeholder="Nội dung đơn đặt hàng..." />
                                    </Form.Item>
                                </div>
                            </MisaMasterCard.FormGrid>
                        </MisaMasterCard.Left>

                        <MisaMasterCard.Right>
                            <MisaMasterCard.MetaRow label="Ngày đơn hàng" required>
                                <Form.Item name="order_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Hạn giao hàng" required>
                                <Form.Item name="delivery_date" noStyle rules={[{ required: true }]}>
                                    <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Số đơn hàng" required>
                                <Form.Item name="order_number" noStyle rules={[{ required: true }]}>
                                    <Input className="misa-input misa-input-w160 misa-text-semibold" />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaMasterCard.MetaRow label="Trạng thái">
                                <Form.Item name="status" noStyle initialValue="pending">
                                    <Select
                                        className="misa-input-w160"
                                        options={[
                                            { value: 'pending', label: 'Chờ duyệt' },
                                            { value: 'confirmed', label: 'Đã xác nhận' },
                                            { value: 'processing', label: 'Đang xử lý' },
                                            { value: 'delivering', label: 'Đang giao' },
                                            { value: 'completed', label: 'Hoàn thành' },
                                            { value: 'cancelled', label: 'Hủy bỏ' },
                                        ]}
                                    />
                                </Form.Item>
                            </MisaMasterCard.MetaRow>

                            <MisaTotalCard label="TỔNG GIÁ TRỊ ĐƠN HÀNG" value={totals.grandTotal} />
                        </MisaMasterCard.Right>
                    </MisaMasterCard>

                    {/* Detail Grid Tabs Section */}
                    <div className="misa-detail-card-section">
                        <div className="misa-grid-tab-bar">
                            <div className="misa-grid-tabs">
                                <button
                                    type="button"
                                    className={`misa-grid-tab-btn ${activeGridTab === 'items' ? 'active' : ''}`}
                                    onClick={() => setActiveGridTab('items')}
                                >
                                    1. Hàng hóa, dịch vụ
                                </button>
                                <button
                                    type="button"
                                    className={`misa-grid-tab-btn ${activeGridTab === 'terms' ? 'active' : ''}`}
                                    onClick={() => setActiveGridTab('terms')}
                                >
                                    2. Điều khoản khác
                                </button>
                                <button
                                    type="button"
                                    className={`misa-grid-tab-btn ${activeGridTab === 'reference' ? 'active' : ''}`}
                                    onClick={() => setActiveGridTab('reference')}
                                >
                                    3. Tham chiếu {referencedVouchers.length > 0 && `(${referencedVouchers.length})`}
                                </button>
                            </div>

                            <Button
                                size="small"
                                icon={<LinkOutlined />}
                                onClick={() => setIsRefModalVisible(true)}
                                className="misa-btn-reference"
                            >
                                Chọn chứng từ tham chiếu
                            </Button>
                        </div>

                        {activeGridTab === 'reference' ? (
                            <div className="misa-p-8">
                                {referencedVouchers.length > 0 ? (
                                    <div className="misa-table-container">
                                        <table className="misa-voucher-table">
                                            <thead>
                                                <tr>
                                                    <th style={{ width: 40 }}>#</th>
                                                    <th style={{ width: 140 }}>Loại chứng từ</th>
                                                    <th style={{ width: 130 }}>Số chứng từ</th>
                                                    <th style={{ width: 120 }}>Ngày chứng từ</th>
                                                    <th style={{ width: 160 }}>Đối tượng</th>
                                                    <th>Diễn giải</th>
                                                    <th style={{ width: 140, textAlign: 'right' }}>Số tiền</th>
                                                    <th style={{ width: 50 }}></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {referencedVouchers.map((v, i) => (
                                                    <tr key={v.id || i}>
                                                        <td>{i + 1}</td>
                                                        <td>{v.voucher_type}</td>
                                                        <td><span className="text-blue-600 font-semibold">{v.voucher_number}</span></td>
                                                        <td>{v.voucher_date || '-'}</td>
                                                        <td>{v.contact_name || '-'}</td>
                                                        <td>{v.description || '-'}</td>
                                                        <td style={{ textAlign: 'right', fontWeight: 600 }}>
                                                            {new Intl.NumberFormat('vi-VN').format(v.total_amount || 0)} ₫
                                                        </td>
                                                        <td style={{ textAlign: 'center' }}>
                                                            <button
                                                                type="button"
                                                                className="misa-btn-row-delete"
                                                                onClick={() => setReferencedVouchers(referencedVouchers.filter((_, idx) => idx !== i))}
                                                            >
                                                                <DeleteOutlined />
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="misa-empty-reference-panel">
                                        <p className="text-gray-500 mb-2">Chưa có chứng từ tham chiếu nào</p>
                                        <Button
                                            type="primary"
                                            size="small"
                                            icon={<LinkOutlined />}
                                            onClick={() => setIsRefModalVisible(true)}
                                            className="misa-btn-primary"
                                        >
                                            Chọn chứng từ tham chiếu ngay
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ) : activeGridTab === 'terms' ? (
                            <div className="misa-p-12">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <div className="misa-field-label">Điều khoản thanh toán</div>
                                        <Form.Item name="payment_terms">
                                            <Input.TextArea rows={3} placeholder="Điều khoản thanh toán công nợ..." />
                                        </Form.Item>
                                    </div>
                                    <div>
                                        <div className="misa-field-label">Ghi chú & Điều khoản khác</div>
                                        <Form.Item name="other_terms">
                                            <Input.TextArea rows={3} placeholder="Ghi chú giao hàng, đóng gói, bảo hành..." />
                                        </Form.Item>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div className="misa-form-flex-col">
                                <Form.List name="lines">
                                    {(fields, { add, remove }) => (
                                        <div className="misa-detail-flex-col">
                                            <div className="misa-table-container" style={{ overflowX: 'auto', width: '100%' }}>
                                                 <table className="misa-voucher-table" style={{ minWidth: 1700, width: 'max-content' }}>
                                                    <thead>
                                                        <tr>
                                                            <th className="misa-text-center" style={{ width: 45 }}>#</th>
                                                            <th style={{ minWidth: 160 }}>Mã hàng</th>
                                                            <th style={{ minWidth: 220 }}>Tên hàng</th>
                                                            <th className="misa-text-center" style={{ minWidth: 85 }}>ĐVT</th>
                                                            <th className="misa-text-right" style={{ minWidth: 100 }}>Số lượng</th>
                                                            <th className="misa-text-right" style={{ minWidth: 100 }}>Đã giao</th>
                                                            <th className="misa-text-right" style={{ minWidth: 100 }}>Đã xuất HĐ</th>
                                                            <th className="misa-text-right" style={{ minWidth: 130 }}>Đơn giá</th>
                                                            <th className="misa-text-right" style={{ minWidth: 140 }}>Thành tiền</th>
                                                            <th className="misa-text-right" style={{ minWidth: 85 }}>% CK</th>
                                                            <th className="misa-text-right" style={{ minWidth: 120 }}>Tiền CK</th>
                                                            <th className="misa-text-center" style={{ minWidth: 85 }}>% VAT</th>
                                                            <th className="misa-text-right" style={{ minWidth: 120 }}>Tiền VAT</th>
                                                            <th className="misa-text-right" style={{ minWidth: 150 }}>Tổng cộng</th>
                                                            <th className="misa-text-center" style={{ width: 50 }}></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {fields.map((field, index) => (
                                                            <tr key={field.key}>
                                                                <td className="misa-text-center apple-muted-text misa-text-semibold">{index + 1}</td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'item_id']} noStyle>
                                                                        <Select
                                                                            showSearch
                                                                            className="misa-w-full"
                                                                            placeholder="Mã..."
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
                                                                                itemPrice: it.sale_price ?? it.selling_price ?? it.purchase_price,
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
                                                                            onChange={(val) => handleItemChange(index, val)}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td>
                                                                    <Form.Item name={[field.name, 'description']} noStyle>
                                                                        <input className="misa-table-input" placeholder="Tên hàng / Dịch vụ..." />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-center">
                                                                    <Form.Item name={[field.name, 'unit']} noStyle initialValue="Cái">
                                                                        <input className="misa-table-input misa-text-center" />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right">
                                                                    <Form.Item name={[field.name, 'quantity']} noStyle initialValue={1}>
                                                                        <InputNumber
                                                                            className="misa-w-full misa-text-right"
                                                                            onChange={() => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                const q = cur[index]?.quantity ?? 1;
                                                                                const p = cur[index]?.unit_price ?? 0;
                                                                                const amt = q * p;
                                                                                const disc = amt * ((cur[index]?.discount_rate || 0) / 100);
                                                                                const tax = (amt - disc) * ((cur[index]?.tax_rate ?? 10) / 100);
                                                                                cur[index].amount = amt;
                                                                                cur[index].discount_amount = disc;
                                                                                cur[index].tax_amount = tax;
                                                                                cur[index].total_amount = (amt - disc) + tax;
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right">
                                                                    <Form.Item name={[field.name, 'delivered_quantity']} noStyle initialValue={0}>
                                                                        <InputNumber min={0} className="misa-w-full misa-text-right" />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right">
                                                                    <Form.Item name={[field.name, 'invoiced_quantity']} noStyle initialValue={0}>
                                                                        <InputNumber className="misa-w-full misa-text-right" readOnly />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right">
                                                                    <Form.Item name={[field.name, 'unit_price']} noStyle initialValue={0}>
                                                                        <InputNumber
                                                                            formatter={(v) => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                            className="misa-w-full misa-text-right"
                                                                            onChange={() => {
                                                                                const cur = form.getFieldValue('lines') || [];
                                                                                const q = cur[index]?.quantity ?? 1;
                                                                                const p = cur[index]?.unit_price ?? 0;
                                                                                const amt = q * p;
                                                                                const disc = amt * ((cur[index]?.discount_rate || 0) / 100);
                                                                                const tax = (amt - disc) * ((cur[index]?.tax_rate ?? 10) / 100);
                                                                                cur[index].amount = amt;
                                                                                cur[index].discount_amount = disc;
                                                                                cur[index].tax_amount = tax;
                                                                                cur[index].total_amount = (amt - disc) + tax;
                                                                                form.setFieldsValue({ lines: [...cur] });
                                                                            }}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right misa-text-bold">
                                                                    {new Intl.NumberFormat('vi-VN').format(formLines[index]?.amount || 0)}
                                                                </td>
                                                                <td className="misa-text-right">
                                                                    <Form.Item name={[field.name, 'discount_rate']} noStyle initialValue={0}>
                                                                        <InputNumber min={0} max={100} className="misa-w-full misa-text-right" />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right">
                                                                    <Form.Item name={[field.name, 'discount_amount']} noStyle initialValue={0}>
                                                                        <InputNumber formatter={(v) => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} className="misa-w-full misa-text-right" />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-center">
                                                                    <Form.Item name={[field.name, 'tax_rate']} noStyle initialValue={10}>
                                                                        <Select
                                                                            variant="borderless"
                                                                            options={[
                                                                                { value: 0, label: '0%' },
                                                                                { value: 5, label: '5%' },
                                                                                { value: 8, label: '8%' },
                                                                                { value: 10, label: '10%' },
                                                                            ]}
                                                                        />
                                                                    </Form.Item>
                                                                </td>
                                                                <td className="misa-text-right">
                                                                    {new Intl.NumberFormat('vi-VN').format(formLines[index]?.tax_amount || 0)}
                                                                </td>
                                                                <td className="misa-text-right misa-text-bold">
                                                                    {new Intl.NumberFormat('vi-VN').format(
                                                                        ((formLines[index]?.amount || 0) - (formLines[index]?.discount_amount || 0)) +
                                                                        (formLines[index]?.tax_amount || 0)
                                                                    )}
                                                                </td>
                                                                <td className="misa-text-center">
                                                                    <button
                                                                        type="button"
                                                                        className="misa-btn-row-delete"
                                                                        onClick={() => remove(index)}
                                                                    >
                                                                        <DeleteOutlined />
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>

                                             <MisaGridActionFooter
                                                onAddLine={() => add({ unit: 'Cái', quantity: 1, unit_price: 0, tax_rate: 10 })}
                                                onAddNote={() => add({ description: 'Ghi chú đơn đặt hàng' })}
                                                onDeleteAll={() => {
                                                    form.setFieldValue('lines', []);
                                                    message.success('Đã xóa toàn bộ dòng');
                                                }}
                                                lineCount={fields.length}
                                            />
                                        </div>
                                    )}
                                </Form.List>
                            </div>
                        )}
                    </div>

                </Form>
            </Modal>

            {/* Quick Add Customer Modal */}
            <QuickAddContactModal
                open={isCustomerModalVisible}
                contactType="customer"
                onCancel={() => setIsCustomerModalVisible(false)}
                onSuccess={() => {
                    setIsCustomerModalVisible(false);
                    queryClient.invalidateQueries({ queryKey: ['customers'] });
                    message.success('Thêm khách hàng thành công');
                }}
            />

            {/* Quick Add Item Modal */}
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

            {/* Voucher Reference Modal */}
            <ReferenceVoucherModal
                open={isRefModalVisible}
                onCancel={() => setIsRefModalVisible(false)}
                onSelect={(selectedList) => {
                    const mapped = selectedList.map((item: any) => ({
                        id: item.id,
                        voucher_type: item.voucher_type || 'Chứng từ',
                        voucher_number: item.voucher_number || item.invoice_number,
                        voucher_date: item.voucher_date || item.invoice_date,
                        contact_name: item.contact_name || item.customer_name || item.supplier_name,
                        description: item.description,
                        total_amount: item.total_amount || item.amount,
                    }));
                    setReferencedVouchers([...referencedVouchers, ...mapped]);
                    setIsRefModalVisible(false);
                    message.success(`Đã thêm ${mapped.length} chứng từ tham chiếu`);
                }}
            />

            {/* Sales Order Print Modal */}
            <VoucherPrintModal 
                open={isPrintModalOpen}
                type="sales_order"
                data={printData}
                onCancel={() => setIsPrintModalOpen(false)}
            />
            </DataTableSurface>
        </PageShell>
    );
};

export default SalesOrders;
