import React, { useEffect, useState, useMemo } from 'react';
import { ConfigProvider, Table, Button, Form, Input, InputNumber, Space, Select, DatePicker, Switch, Alert, Tag } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import { 
    PlusOutlined, 
    DeleteOutlined, 
    InboxOutlined, 
    DownOutlined, 
    SettingOutlined, 
    ReloadOutlined,
    QrcodeOutlined,
    PrinterOutlined,
    SearchOutlined,
    LinkOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import api from '../../api/axios';
import { formatDate } from '../../utils/dateUtils';
import { 
    MisaMasterCard,
    MisaGridActionFooter,
    MisaTableSummaryBar,
    MisaTotalCard,
    QuickAddContactModal,
    QuickAddEmployeeModal,
    QuickAddItemModal,
    ReferenceVoucherModal,
    MultiColumnContactSelect,
    useVoucherShortcuts,
    useVoucherTotals
} from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import {
    INVENTORY_DOCUMENT_ITEMS_QUERY_KEY,
    parseInventoryDocumentList,
    parseInventoryItemCatalogue,
    parseInventoryAccountCatalogue,
    parseInventoryWarehouseCatalogue,
    parseInventoryContactCatalogue,
    getActiveLeafInventoryAccounts,
    canPostPersistedInventoryDocument,
    requirePersistedInventoryDocument,
    getInventoryMutationError,
} from './inventoryDocumentIntegrity';

interface InventoryReceiptLine {
    key?: string;
    item_id?: number;
    item_code?: string;
    item_name?: string;
    warehouse_code?: string;
    debit_account?: string;
    credit_account?: string;
    unit?: string;
    quantity?: number;
    unit_price?: number;
    amount?: number;
    description?: string;
}

interface InventoryReceiptRecord {
    id: number;
    voucher_type?: string;
    voucher_number: string;
    voucher_date: string;
    posting_date: string;
    description: string;
    total_amount: number;
    contact_name: string;
    is_posted: boolean;
    lines?: InventoryReceiptLine[];
}

const receiptVoucherTypes = [
    { value: '1. Nhập kho mua hàng', label: '1. Nhập kho mua hàng' },
    { value: '2. Nhập kho từ sản xuất', label: '2. Nhập kho từ sản xuất' },
    { value: '3. Nhập kho khác', label: '3. Nhập kho khác' },
    { value: '4. Nhập kho hàng trả lại', label: '4. Nhập kho hàng trả lại' },
];

export const InventoryReceipts: React.FC<{ embedded?: boolean; openDraftId?: number | null; onDraftOpened?: () => void }> = ({ embedded = false, openDraftId = null, onDraftOpened }) => {
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [isViewMode, setIsViewMode] = useState(false);
    const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
    const [isItemModalVisible, setIsItemModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [isRefModalVisible, setIsRefModalVisible] = useState(false);
    const [referencedVouchers, setReferencedVouchers] = useState<any[]>([]);
    const [activeRowIndex, setActiveRowIndex] = useState<number | null>(null);
    const [datePreset, setDatePreset] = useState('Tháng này');
    const [searchText, setSearchText] = useState('');
    const [activeGridTab, setActiveGridTab] = useState<'items' | 'stats'>('items');

    const [form] = Form.useForm();
    const queryClient = useQueryClient();
    const formLines: InventoryReceiptLine[] = Form.useWatch('lines', form) || [];
    const totals = useVoucherTotals(formLines);
    const voucherNumber = Form.useWatch('voucher_number', form);
    const voucherType = Form.useWatch('voucher_type', form);
    const effectiveVoucherType = voucherType ?? receiptVoucherTypes[0].value;
    const postingDate = Form.useWatch('posting_date', form);
    const voucherDate = Form.useWatch('voucher_date', form);

    const { data: employees = [] } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return data?.data ?? data ?? [];
        },
    });

    const { data: receipts = [], isLoading, isError: isReceiptsError, refetch: refetchReceipts } = useQuery({
        queryKey: ['inventory-receipts'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/receipts');
            return parseInventoryDocumentList(data, 'receipt') as InventoryReceiptRecord[];
        },
    });

    const { data: chartOfAccounts = [], isError: isAccountsError, refetch: refetchAccounts } = useQuery({
        queryKey: ['chart-of-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return parseInventoryAccountCatalogue(data);
        },
    });

    const { data: suppliers = [], isError: isSuppliersError, refetch: refetchSuppliers } = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parseInventoryContactCatalogue(data, 'supplier');
        },
    });

    const { data: items = [], isError: isItemsError, refetch: refetchItems } = useQuery({
        queryKey: INVENTORY_DOCUMENT_ITEMS_QUERY_KEY,
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parseInventoryItemCatalogue(data);
        },
    });

    const { data: warehouses = [], isError: isWarehousesError, refetch: refetchWarehouses } = useQuery({
        queryKey: ['inventory-warehouses'],
        queryFn: async () => {
            const { data } = await api.get('/master/warehouses');
            return parseInventoryWarehouseCatalogue(data);
        },
    });

    const activeLeafAccounts = getActiveLeafInventoryAccounts(chartOfAccounts);
    const hasCatalogueError = isSuppliersError || isItemsError || isAccountsError || isWarehousesError;
    const catalogueUnavailable = hasCatalogueError || items.length === 0 || warehouses.length === 0 || activeLeafAccounts.length === 0;
    const retryCatalogues = () => {
        void Promise.all([
            isSuppliersError ? refetchSuppliers() : undefined,
            isItemsError ? refetchItems() : undefined,
            isAccountsError ? refetchAccounts() : undefined,
            isWarehousesError ? refetchWarehouses() : undefined,
        ]);
    };
    const hasRequiredDocumentEvidence = !catalogueUnavailable
        && typeof effectiveVoucherType === 'string' && effectiveVoucherType.trim().length > 0
        && typeof voucherNumber === 'string' && voucherNumber.trim().length > 0
        && Boolean(postingDate) && Boolean(voucherDate)
        && formLines.length > 0
        && formLines.every((line) => (
            items.some((item) => item.id === line.item_id)
            && warehouses.some((warehouse) => warehouse.code === line.warehouse_code)
            && activeLeafAccounts.some((account: any) => account.code === line.debit_account)
            && activeLeafAccounts.some((account: any) => account.code === line.credit_account)
            && typeof line.quantity === 'number' && Number.isFinite(line.quantity) && line.quantity >= 0
            && typeof line.unit_price === 'number' && Number.isFinite(line.unit_price) && line.unit_price >= 0
        ));

    const invalidateInventoryCaches = () => {
        queryClient.invalidateQueries({ queryKey: ['inventory-receipts'] });
        queryClient.invalidateQueries({ queryKey: ['stock-report'] });
    };

    const mutation = useMutation({
        mutationFn: async (payloadWithFlags: any) => {
            const { andNew, andPrint, ...values } = payloadWithFlags;
            const payload = {
                contact_type: 'supplier',
                voucher_type: values.voucher_type ?? voucherType ?? receiptVoucherTypes[0].value,
                contact_id: values.contact_id,
                contact_name: suppliers?.find((c: any) => c.id === values.contact_id)?.name ?? values.contact_name,
                deliverer_name: values.deliverer_name,
                receiver_address: values.receiver_address,
                description: values.description,
                attached_docs: values.attached_docs,
                currency: values.currency,
                exchange_rate: values.exchange_rate,
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date.format('YYYY-MM-DD'),
                posting_date: values.posting_date.format('YYYY-MM-DD'),
                lines: values.lines.map((line: any) => ({
                    item_id: line.item_id,
                    warehouse_code: line.warehouse_code,
                    debit_account: line.debit_account,
                    credit_account: line.credit_account,
                    unit: line.unit,
                    quantity: line.quantity ?? undefined,
                    unit_price: line.unit_price ?? undefined,
                    amount: line.quantity * line.unit_price,
                    description: line.description ?? values.description,
                }))
            };
            const res = editingId
                ? await api.put(`/inventory/receipts/${editingId}`, payload)
                : await api.post('/inventory/receipts', payload);
            requirePersistedInventoryDocument(res.data);
            return { res, andNew, andPrint, editingId };
        },
        onSuccess: (data: any) => {
            message.success(data.editingId ? 'Đã cập nhật phiếu nhập kho nháp.' : 'Tạo Phiếu nhập kho thành công!');
            invalidateInventoryCaches();
            if (data?.andNew) {
                handleOpenModal();
            } else if (data?.andPrint) {
                setIsModalVisible(false);
                window.print();
            } else {
                setIsModalVisible(false);
            }
            setEditingId(null);
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra!');
        }
    });

    const postMutation = useMutation({
        mutationFn: async (id: number) => {
            const response = await api.post(`/inventory/receipts/${id}/post`);
            requirePersistedInventoryDocument(response.data, true);
            return response;
        },
        onSuccess: () => {
            message.success('Ghi sổ thành công');
            invalidateInventoryCaches();
        },
        onError: (err: any) => message.error(getInventoryMutationError(err, 'Không thể ghi sổ chứng từ.')),
    });

    const handleOpenModal = React.useCallback(() => {
        setEditingId(null);
        setIsViewMode(false);
        form.resetFields();
        form.setFieldsValue({ lines: [], voucher_type: receiptVoucherTypes[0].value });
        setActiveGridTab('items');
        setIsModalVisible(true);
    }, [form]);

    const handleEditModal = (record: InventoryReceiptRecord) => {
        setIsViewMode(false);
        setEditingId(record.id);
        form.setFieldsValue({
            voucher_type: record.voucher_type ?? receiptVoucherTypes[0].value,
            voucher_number: record.voucher_number,
            voucher_date: record.voucher_date ? dayjs(record.voucher_date) : undefined,
            posting_date: record.posting_date ? dayjs(record.posting_date) : undefined,
            description: record.description,
            contact_name: record.contact_name,
            lines: (record.lines ?? []).map((line) => ({
                item_id: line.item_id,
                warehouse_code: line.warehouse_code,
                debit_account: line.debit_account,
                credit_account: line.credit_account,
                unit: line.unit,
                quantity: line.quantity,
                unit_price: line.unit_price,
                amount: line.amount,
                description: line.description,
            })),
        });
        setActiveGridTab('items');
        setIsModalVisible(true);
    };

    const handleViewModal = async (record: InventoryReceiptRecord) => {
        try {
            const { data } = await api.get(`/inventory/receipts/${record.id}`);
            const persisted = requirePersistedInventoryDocument(data);
            if (persisted.id !== record.id) throw new Error('Máy chủ không trả về đúng phiếu nhập kho được yêu cầu.');
            handleEditModal((data?.data ?? data) as InventoryReceiptRecord);
            setIsViewMode(true);
        } catch (error) {
            message.error(getInventoryMutationError(error, 'Không thể mở chi tiết phiếu nhập kho.'));
        }
    };

    useEffect(() => {
        if (isModalVisible && !form.getFieldValue('voucher_type')) {
            form.setFieldValue('voucher_type', receiptVoucherTypes[0].value);
        }
    }, [form, isModalVisible]);

    useEffect(() => {
        const handleCreate = () => handleOpenModal();
        window.addEventListener('open-inventory-receipt', handleCreate);
        return () => window.removeEventListener('open-inventory-receipt', handleCreate);
    }, [handleOpenModal]);
    useEffect(() => {
        const handleRefresh = () => { queryClient.invalidateQueries({ queryKey: ['inventory-receipts'] }); };
        window.addEventListener('refresh-inventory-receipt', handleRefresh);
        return () => window.removeEventListener('refresh-inventory-receipt', handleRefresh);
    }, [queryClient]);

    useEffect(() => {
        const handleSearch = (e: Event) => {
            const customEvent = e as CustomEvent<string>;
            setSearchText(customEvent.detail ?? '');
        };
        window.addEventListener('search-inventory-receipt', handleSearch);
        return () => window.removeEventListener('search-inventory-receipt', handleSearch);
    }, []);

    const filteredReceipts = useMemo(() => {
        const list = receipts.map((v: any) => ({
            ...v,
            total_amount: v.total_amount === null || v.total_amount === undefined || v.total_amount === ''
                ? undefined
                : v.total_amount,
        }));
        if (!searchText.trim()) return list;
        const q = searchText.toLowerCase().trim();
        return list.filter((item: any) => {
            const code = String(item.voucher_number || '').toLowerCase();
            const contact = String(item.contact_name || '').toLowerCase();
            const desc = String(item.description || '').toLowerCase();
            return code.includes(q) || contact.includes(q) || desc.includes(q);
        });
    }, [receipts, searchText]);

    useEffect(() => {
        if (!openDraftId) return;
        let active = true;
        api.get(`/inventory/receipts/${openDraftId}`)
            .then(({ data }) => {
                const persisted = requirePersistedInventoryDocument(data);
                if (!active || persisted.is_posted === true || persisted.status === 'posted') return;
                const record = (data?.data ?? data) as InventoryReceiptRecord;
                setEditingId(record.id);
                form.setFieldsValue({
                    voucher_type: record.voucher_type ?? receiptVoucherTypes[0].value,
                    voucher_number: record.voucher_number,
                    voucher_date: record.voucher_date ? dayjs(record.voucher_date) : undefined,
                    posting_date: record.posting_date ? dayjs(record.posting_date) : undefined,
                    description: record.description,
                    contact_name: record.contact_name,
                    lines: (record.lines ?? []).map((line) => ({
                        item_id: line.item_id,
                        warehouse_code: line.warehouse_code,
                        debit_account: line.debit_account,
                        credit_account: line.credit_account,
                        unit: line.unit,
                        quantity: line.quantity,
                        unit_price: line.unit_price,
                        amount: line.amount,
                        description: line.description,
                    })),
                });
                setActiveGridTab('items');
                setIsModalVisible(true);
                onDraftOpened?.();
            })
            .catch((error) => message.error(getInventoryMutationError(error, 'Không thể mở phiếu nhập nháp từ biên bản kiểm kê.')));
        return () => { active = false; };
    }, [form, onDraftOpened, openDraftId]);

    useEffect(() => {
        const searchParams = new URLSearchParams(window.location.search);
        if (searchParams.get('action') === 'create' && !isModalVisible) {
            handleOpenModal();
            searchParams.delete('action');
            const nextQuery = searchParams.toString();
            window.history.replaceState({}, '', `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash}`);
        }
    }, [isModalVisible, handleOpenModal]);

    const handleSaveForm = (andNew = false, andPrint = false) => {
        if (!hasRequiredDocumentEvidence) {
            message.error('Chọn dữ liệu máy chủ và nhập đủ số phiếu, ngày, số lượng, đơn giá, TK Nợ/TK Có trước khi cất.');
            return;
        }
        form.validateFields().then(values => {
            mutation.mutate({ ...values, andNew, andPrint });
        }).catch(() => {
            message.error('Vui lòng kiểm tra lại các trường thông tin bắt buộc (màu đỏ)!');
        });
    };

    const handleAddLine = () => {
        const curLines = form.getFieldValue('lines') || [];
        form.setFieldsValue({
            lines: [...curLines, {}]
        });
    };

    // Voucher keyboard shortcuts
    useVoucherShortcuts({
        onSave: () => handleSaveForm(false, false),
        onSaveAndNew: () => handleSaveForm(true, false),
        onPrint: () => handleSaveForm(false, true),
        onAddLine: handleAddLine,
        onClose: () => {
            if (!isSupplierModalVisible && !isItemModalVisible) {
                setIsModalVisible(false);
            }
        },
        enabled: isModalVisible && !isViewMode
    });

    const handleItemChange = (index: number, itemId: number) => {
        const item = items?.find((i: any) => i.id === itemId);
        if (item) {
            const curLines = form.getFieldValue('lines') || [];
            const quantity = curLines[index]?.quantity ?? 0;

            curLines[index] = {
                ...curLines[index],
                item_id: item.id,
                item_name: item.name,
                unit: item.unit ?? undefined,
                amount: (curLines[index]?.unit_price ?? 0) * quantity,
                description: curLines[index]?.description ?? item.name,
            };
            form.setFieldsValue({ lines: [...curLines] });
        }
    };

    const columns: ColumnsType<InventoryReceiptRecord> = [
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
            render: (t: string) => <span className="misa-code-link">{t}</span> 
        },
        { 
            title: 'Diễn giải', 
            dataIndex: 'description', 
            key: 'description' 
        },
        { 
            title: 'Tổng giá trị (VND)', 
            dataIndex: 'total_amount', 
            key: 'total_amount',
            width: 150,
            align: 'right',
            render: (val: number | string | null | undefined, record: any) => {
                const lineTotal = Array.isArray(record.lines)
                    && record.lines.length > 0
                    && record.lines.every((line: any) => typeof line.quantity === 'number' && typeof line.unit_price === 'number')
                    ? record.lines.reduce((sum: number, line: any) => sum + (line.quantity * line.unit_price), 0)
                    : undefined;
                const total = val === null || val === undefined || val === '' ? lineTotal : val;
                if (total === null || total === undefined) {
                    return <span className="apple-muted-text">—</span>;
                }
                return <span className="misa-text-semibold">{new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(total)}</span>;
            }
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
            render: (posted: boolean) => (
                <span className={`misa-status-tag ${posted ? 'posted' : 'draft'}`}>
                    {posted ? 'Đã ghi sổ' : 'Bản nháp'}
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 130,
            align: 'center',
            render: (_: any, record: InventoryReceiptRecord) => (
                <Space size="middle">
                    {!record.is_posted && (
                        <button
                            type="button"
                            className="misa-action-link misa-action-link-primary"
                            onClick={() => handleEditModal(record)}
                        >
                            Sửa
                        </button>
                    )}
                    <button 
                        type="button" 
                        className="misa-action-link misa-action-link-primary"
                        onClick={() => void handleViewModal(record)}
                    >
                        Xem
                    </button>
                    {!record.is_posted && (
                        <button 
                            type="button" 
                            className="misa-action-link misa-action-link-success"
                            onClick={() => postMutation.mutate(record.id)}
                            disabled={!canPostPersistedInventoryDocument(record, postMutation.isPending)}
                        >
                            Ghi sổ
                        </button>
                    )}
                </Space>
            ),
        },
    ];

    return (
        <PageShell
            embedded={embedded}
            title={(
                <PageHeader
                    eyebrow="KHO"
                    title="Phiếu nhập kho"
                    description="Quản lý và lập phiếu nhập kho theo chứng từ thực tế."
                />
            )}
            toolbar={!embedded && (
            <PageToolbar
                filters={(
                    <div className="misa-toolbar-left flex items-center gap-2">
                        <Input 
                            placeholder="Tìm kiếm theo số phiếu, đối tượng..." 
                            prefix={<SearchOutlined className="misa-header-qrcode" />}
                            className="misa-w-280"
                            allowClear 
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                        />
                        <Select 
                            value={datePreset} 
                            onChange={setDatePreset} 
                            className="misa-w-140"
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
                    <div className="misa-toolbar-right flex items-center gap-2">
                        <Button 
                            className="misa-btn-tool" 
                            title="Làm mới (F5)" 
                            icon={<ReloadOutlined />}
                            onClick={() => queryClient.invalidateQueries({ queryKey: ['inventory-receipts'] })}
                        />
                        <Button 
                            className="misa-btn-tool" 
                            title="In danh sách" 
                            icon={<PrinterOutlined />}
                            onClick={() => window.print()}
                        />
                        <Button 
                            type="primary"
                            className="misa-btn-primary"
                            icon={<PlusOutlined />} 
                            onClick={() => handleOpenModal()}
                        >
                            Thêm Nhập kho
                        </Button>
                    </div>
                )}
            />
            )}
        >
            <DataTableSurface className="misa-voucher-surface">
                {isReceiptsError ? (
                    <Alert
                        type="error"
                        showIcon
                        message="Không thể tải danh sách phiếu nhập kho"
                        action={<Button size="small" onClick={() => void refetchReceipts()}>Thử lại danh sách phiếu nhập kho</Button>}
                    />
                ) : null}
                <Table 
                    columns={columns} 
                    dataSource={filteredReceipts} 
                    rowKey="id" 
                    loading={isLoading} 
                    size="small"
                    bordered
                    className="misa-voucher-table"
                    pagination={{ pageSize: 20 }}
                    locale={{ emptyText: (
                        <div className="misa-empty-box">
                            <InboxOutlined className="misa-empty-icon" />
                            <div className="misa-empty-text">Chưa có chứng từ nhập kho nào</div>
                        </div>
                    )}}
                />
            </DataTableSurface>

            {/* Receipt Modal */}
            <Modal
                title={
                    <div className="misa-modal-header-wrapper">
                        <div className="misa-modal-header-left">
                            <span className="misa-voucher-header-title">
                                Phiếu nhập kho {voucherNumber ? `: ${voucherNumber}` : ''}
                            </span>
                            {isViewMode && (
                                <span className="misa-status-tag posted misa-ml-8">
                                    Chế độ xem
                                </span>
                            )}
                            <div className="misa-input-group">
                                <Select 
                                    value={voucherType ?? receiptVoucherTypes[0].value} 
                                    disabled={isViewMode}
                                    onChange={(value) => form.setFieldValue('voucher_type', value)} 
                                    variant="borderless" 
                                    className="misa-input-w230 misa-text-semibold"
                                    popupMatchSelectWidth={false}
                                    options={receiptVoucherTypes.map(t => ({ value: t.value, label: t.label }))}
                                />
                            </div>
                        </div>
                        <div className="misa-modal-header-right">
                            <button type="button" className="misa-icon-btn">
                                <SettingOutlined />
                            </button>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => { setIsModalVisible(false); setEditingId(null); setIsViewMode(false); form.resetFields(); }}
                footer={
                    <ConfigProvider componentSize="small">
                        <div className="misa-modal-footer-container">
                            <div className="misa-flex-center misa-gap-8">
                                <Switch size="small" defaultChecked disabled={isViewMode} />
                                <span className="misa-unit-label">Hiển thị tài khoản</span>
                            </div>
                            <div className="misa-flex-center misa-gap-8">
                                <button 
                                    type="button" 
                                    className="misa-btn-footer-secondary"
                                    onClick={() => { setIsModalVisible(false); setEditingId(null); setIsViewMode(false); form.resetFields(); }}
                                >
                                    {isViewMode ? 'Đóng' : 'Hủy'}
                                </button>
                                {!isViewMode && <button 
                                    type="button" 
                                    className="misa-btn-footer-save"
                                    onClick={() => handleSaveForm(false, false)}
                                    disabled={!hasRequiredDocumentEvidence || hasCatalogueError || mutation.isPending}
                                >
                                    Cất
                                </button>}
                                {!isViewMode && <button 
                                    type="button" 
                                    className="misa-btn-footer-primary"
                                    onClick={() => handleSaveForm(true, false)}
                                    disabled={!hasRequiredDocumentEvidence || hasCatalogueError || mutation.isPending}
                                >
                                    <span>Cất và Thêm</span>
                                    <DownOutlined />
                                </button>}
                            </div>
                        </div>
                    </ConfigProvider>
                }
                closable={true}
                className={`misa-voucher-modal ${isViewMode ? 'misa-voucher-modal--view' : ''}`}
            >
                <ModalFrame>
                    {hasCatalogueError && (
                        <Alert
                            type="error"
                            showIcon
                            title="Không thể tải danh mục cho phiếu nhập kho"
                            description="Hệ thống không thể tải đầy đủ danh mục hàng hóa, nhà cung cấp, kho hoặc tài khoản để lập chứng từ."
                            action={<Button size="small" onClick={retryCatalogues}>Thử lại danh mục phiếu nhập kho</Button>}
                            className="misa-mb-12"
                        />
                    )}
                    <Form form={form} layout="vertical" size="small" className="misa-form-flex-col" initialValues={{ voucher_type: receiptVoucherTypes[0].value }}>
                        <Form.Item name="voucher_type" hidden initialValue={receiptVoucherTypes[0].value}>
                            <Input />
                        </Form.Item>
                        {/* Top Master Card */}
                        <MisaMasterCard>
                            <MisaMasterCard.Left>
                                <MisaMasterCard.FormGrid>
                                    {/* Đối tượng (Nhà cung cấp) */}
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">
                                            Đối tượng
                                        </div>
                                        <Form.Item name="contact_id" noStyle>
                                            <MultiColumnContactSelect 
                                                placeholder="Chọn nhà cung cấp / đối tượng..."
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
                                                        deliverer_name: item?.contact_person || item?.name || '',
                                                        receiver_address: item?.address || '',
                                                        description: `Nhập kho từ ${item?.name || ''}`
                                                    });
                                                }}
                                                onQuickAdd={() => setIsSupplierModalVisible(true)}
                                            />
                                        </Form.Item>
                                    </div>

                                    {/* Tên đối tượng */}
                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Tên đối tượng</div>
                                        <Form.Item name="contact_name" noStyle>
                                            <Input className="misa-input" placeholder="Tên đối tượng / nhà cung cấp" disabled={isViewMode} />
                                        </Form.Item>
                                    </div>

                                    {/* Người giao hàng */}
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Người giao hàng</div>
                                        <Form.Item name="deliverer_name" noStyle>
                                            <Input className="misa-input" placeholder="Họ và tên người giao" disabled={isViewMode} />
                                        </Form.Item>
                                    </div>

                                    {/* Địa chỉ */}
                                    <div className="misa-col-8">
                                        <div className="misa-field-label">Địa chỉ</div>
                                        <Form.Item name="receiver_address" noStyle>
                                            <Input className="misa-input" placeholder="Địa chỉ đối tượng" disabled={isViewMode} />
                                        </Form.Item>
                                    </div>

                                    {/* Row 3: Nhân viên tiếp nhận + Lý do nhập + Kèm theo */}
                                    <div className="misa-col-4">
                                        <div className="misa-field-label">Nhân viên tiếp nhận</div>
                                        <div className="misa-input-group">
                                            <Form.Item name="employee_id" noStyle>
                                                <Select
                                                    allowClear
                                                    showSearch
                                                    variant="borderless"
                                                    placeholder="Chọn nhân viên"
                                                    className="misa-w-full"
                                                    disabled={isViewMode}
                                                    optionFilterProp="label"
                                                    options={employees?.map((emp: any) => ({
                                                        value: emp.id,
                                                        label: `${emp.code} - ${emp.name}`
                                                    }))}
                                                />
                                            </Form.Item>
                                            {!isViewMode && (
                                                <button
                                                    type="button"
                                                    className="misa-plus-btn"
                                                    title="Thêm nhân viên"
                                                    onClick={() => setIsEmployeeModalVisible(true)}
                                                >
                                                    <PlusOutlined />
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    {/* Lý do nhập */}
                                    <div className="misa-col-6">
                                        <div className="misa-field-label">Lý do nhập</div>
                                        <Form.Item name="description" noStyle>
                                            <Input 
                                                className="misa-input" 
                                                suffix={<QrcodeOutlined className="misa-header-qrcode" />} 
                                                placeholder="Lý do nhập kho..."
                                                disabled={isViewMode}
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

                                    {/* Kèm theo chứng từ */}
                                    <div className="misa-col-2">
                                        <div className="misa-field-label">Kèm theo</div>
                                        <div className="misa-flex-center misa-gap-6">
                                            <Form.Item name="attached_docs" noStyle>
                                                <Input className="misa-input misa-input-w55" placeholder="SL" disabled={isViewMode} />
                                            </Form.Item>
                                            <span className="misa-unit-label">chứng từ gốc</span>
                                        </div>
                                    </div>
                                </MisaMasterCard.FormGrid>

                                {/* Tham chiếu link */}
                                <div className="misa-ref-chip-container" style={{ marginTop: 8, display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 6 }}>
                                    <span className="misa-ref-label" style={{ fontSize: 13, color: '#595959', marginRight: 4 }}>Tham chiếu:</span>
                                    {referencedVouchers.map((v: any, idx: number) => (
                                        <Tag
                                            key={`ref-${v.voucher_number ?? idx}`}
                                            color="blue"
                                            closable={!isViewMode}
                                            onClose={() => setReferencedVouchers(referencedVouchers.filter((_, i) => i !== idx))}
                                        >
                                            <LinkOutlined style={{ marginRight: 4 }} />
                                            <span>{v.voucher_number} ({new Intl.NumberFormat('vi-VN').format(v.total_amount || 0)} ₫)</span>
                                        </Tag>
                                    ))}
                                    {!isViewMode && (
                                        <button
                                            type="button"
                                            className="misa-ref-btn"
                                            style={{
                                                border: '1px dashed #d9d9d9',
                                                background: '#fafafa',
                                                cursor: 'pointer',
                                                borderRadius: 4,
                                                padding: '0 8px',
                                                fontSize: 12,
                                                lineHeight: '22px',
                                                color: '#1677ff',
                                                fontWeight: 600
                                            }}
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
                                        <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" disabled={isViewMode} />
                                    </Form.Item>
                                </MisaMasterCard.MetaRow>

                                <MisaMasterCard.MetaRow label="Ngày chứng từ" required>
                                    <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                        <DatePicker className="misa-input misa-input-w160" format="DD/MM/YYYY" disabled={isViewMode} />
                                    </Form.Item>
                                </MisaMasterCard.MetaRow>

                                <MisaMasterCard.MetaRow label="Số chứng từ" required>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                                        <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                            <Input className="misa-input misa-input-w160 misa-text-semibold" disabled={isViewMode} />
                                        </Form.Item>
                                        {!isViewMode && !editingId && (
                                            <Button
                                                type="text"
                                                size="small"
                                                icon={<ReloadOutlined />}
                                                title="Lấy số chứng từ mới"
                                                onClick={async () => {
                                                    try {
                                                        const { data } = await api.get('/inventory/receipts/next-code');
                                                        const nextCode = data?.data?.code || data?.code || data?.data;
                                                        if (nextCode) form.setFieldsValue({ voucher_number: nextCode });
                                                    } catch {}
                                                }}
                                            />
                                        )}
                                    </div>
                                </MisaMasterCard.MetaRow>

                                <MisaTotalCard 
                                    label="TỔNG TIỀN HÀNG" 
                                    value={totals.grandTotal} 
                                />
                            </MisaMasterCard.Right>
                        </MisaMasterCard>

                        {/* Middle Section - Detail Accounting Grid */}
                        <div className="misa-detail-card-section">
                            <div className="misa-grid-tab-bar" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                <div className="misa-grid-tabs">
                                    <button 
                                        type="button" 
                                        className={`misa-grid-tab-btn ${activeGridTab === 'items' ? 'active' : ''}`}
                                        onClick={() => setActiveGridTab('items')}
                                    >
                                        1. Hàng tiền
                                    </button>
                                    <button 
                                        type="button" 
                                        className={`misa-grid-tab-btn ${activeGridTab === 'stats' ? 'active' : ''}`}
                                        onClick={() => setActiveGridTab('stats')}
                                    >
                                        2. Thống kê
                                    </button>
                                </div>
                            </div>

                            <div className="misa-form-flex-col">
                                <Form.List name="lines">
                                    {(fields, { remove }) => (
                                        <div className="misa-detail-flex-col">
                                            <div className="misa-table-container">
                                                <table className="misa-voucher-table">
                                                    <thead>
                                                        {activeGridTab === 'items' ? (
                                                            <tr>
                                                                <th className="misa-text-center">#</th>
                                                                <th className="misa-input-w140">Mã hàng</th>
                                                                <th>Tên hàng</th>
                                                                <th className="misa-input-w100">Kho</th>
                                                                <th className="misa-input-w90">TK Nợ (Kho)</th>
                                                                <th className="misa-input-w90">TK Có</th>
                                                                <th className="misa-input-w75 misa-text-center">ĐVT</th>
                                                                <th className="misa-input-w90 misa-text-right">Số lượng</th>
                                                                <th className="misa-input-w120 misa-text-right">Đơn giá</th>
                                                                <th className="misa-input-w120 misa-text-right">Thành tiền</th>
                                                                <th className="misa-text-center"></th>
                                                            </tr>
                                                        ) : (
                                                            <tr>
                                                                <th className="misa-text-center">#</th>
                                                                <th>Mã hàng</th>
                                                                <th>Tên hàng</th>
                                                                <th className="misa-input-w180">Đối tượng</th>
                                                                <th className="misa-input-w160">Khoản mục CP</th>
                                                                <th className="misa-input-w160">Đơn hàng mua</th>
                                                                <th className="misa-text-center"></th>
                                                            </tr>
                                                        )}
                                                    </thead>
                                                    <tbody>
                                                        {fields.map((field, index) => {
                                                            const itemId = formLines[index]?.item_id;
                                                            const it = items?.find((x: any) => x.id === itemId);
                                                            return (
                                                                <tr 
                                                                    key={field.key}
                                                                    title="Mẹo: Nhấn Ctrl + Chuột phải để xóa nhanh dòng này"
                                                                    onContextMenu={(e) => {
                                                                        if (e.ctrlKey) {
                                                                            e.preventDefault();
                                                                            remove(index);
                                                                            message.info(`Đã xóa dòng ${index + 1}`);
                                                                        }
                                                                    }}
                                                                >
                                                                    <td className="misa-text-center misa-text-muted misa-text-semibold">
                                                                        {index + 1}
                                                                    </td>

                                                                    {activeGridTab === 'items' ? (
                                                                        <>
                                                                            <td>
                                                                                <div className="misa-flex-center misa-gap-4">
                                                                                    <Form.Item name={[field.name, 'item_id']} noStyle rules={[{ required: true, message: 'Chọn mã hàng' }]}>
                                                                                        <Select 
                                                                                            showSearch 
                                                                                            variant="borderless" 
                                                                                            className="misa-w-full"
                                                                                            placeholder="Chọn mã hàng"
                                                                                            disabled={isViewMode}
                                                                                            optionFilterProp="label"
                                                                                            onChange={(val: any) => handleItemChange(index, val)}
                                                                                            popupMatchSelectWidth={false}
                                                                                            popupClassName="misa-multicolumn-item-popup"
                                                                                            dropdownStyle={{ minWidth: 680, width: 680 }}
                                                                                            optionLabelProp="label"
                                                                                            options={items?.map((i: any) => ({ 
                                                                                                value: i.id, 
                                                                                                label: i.code,
                                                                                                itemCode: i.code,
                                                                                                itemName: i.name,
                                                                                                itemStock: i.stock_quantity ?? i.quantity ?? i.on_hand ?? '—',
                                                                                                itemPrice: i.cost_price ?? i.purchase_price ?? i.sale_price
                                                                                            }))}
                                                                                            optionRender={(option) => (
                                                                                                <div className="misa-cell-dropdown-grid-4col">
                                                                                                    <span className="misa-text-semibold">{option.data.itemCode}</span>
                                                                                                    <span className="misa-text-truncate">{option.data.itemName}</span>
                                                                                                    <span className="misa-text-right misa-text-blue">{option.data.itemStock ?? '—'}</span>
                                                                                                    <span className="misa-text-right">{option.data.itemPrice === undefined ? '—' : new Intl.NumberFormat('vi-VN').format(option.data.itemPrice)}</span>
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
                                                                            onClick={() => { setActiveRowIndex(index); setIsItemModalVisible(true); }}
                                                                            title="Thêm nhanh vật tư hàng hóa"
                                                                            className="misa-btn-tool-sm misa-text-blue misa-text-semibold"
                                                                        >
                                                                            Thêm nhanh VTHH
                                                                        </Button>
                                                                                                    </div>
                                                                                                </div>
                                                                                            )}
                                                                                        />
                                                                                    </Form.Item>
                                                                                    
                                                                                </div>
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'description']} noStyle>
                                                                                    <input 
                                                                                        className="misa-table-input" 
                                                                                        placeholder="Tên hàng hóa..." 
                                                                                        disabled={isViewMode}
                                                                                        defaultValue={it?.name}
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'warehouse_code']} noStyle rules={[{ required: true, message: 'Chọn kho' }]}>
                                                                                    <Select
                                                                                        showSearch
                                                                                        variant="borderless"
                                                                                        className="misa-w-full misa-text-semibold"
                                                                                        placeholder="Chọn kho"
                                                                                        disabled={isViewMode}
                                                                                        popupMatchSelectWidth={false}
                                                                                        popupClassName="misa-multicolumn-account-popup"
                                                                                        dropdownStyle={{ minWidth: 420, width: 420 }}
                                                                                        optionLabelProp="label"
                                                                                        options={warehouses.map((warehouse) => ({ 
                                                                                            value: warehouse.code, 
                                                                                            label: warehouse.code,
                                                                                            warehouseCode: warehouse.code, 
                                                                                            warehouseName: warehouse.name 
                                                                                        }))}
                                                                                        filterOption={(input, option) => {
                                                                                            const code = String(option?.warehouseCode || option?.value || '').toLowerCase();
                                                                                            const name = String(option?.warehouseName || option?.label || '').toLowerCase();
                                                                                            const q = input.toLowerCase();
                                                                                            return code.includes(q) || name.includes(q);
                                                                                        }}
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
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'debit_account']} noStyle rules={[{ required: true, message: 'Chọn TK Nợ' }]}>
                                                                                    <Select 
                                                                                        showSearch 
                                                                                        variant="borderless" 
                                                                                        className="misa-w-full misa-text-semibold"
                                                                                        popupMatchSelectWidth={false}
                                                                                        options={activeLeafAccounts.map((acc: any) => ({
                                                                                            value: acc.code,
                                                                                            label: `${acc.code} - ${acc.name}`
                                                                                        }))}
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'credit_account']} noStyle rules={[{ required: true, message: 'Chọn TK Có' }]}>
                                                                                    <Select
                                                                                        showSearch
                                                                                        variant="borderless"
                                                                                        className="misa-w-full misa-text-semibold"
                                                                                        popupMatchSelectWidth={false}
                                                                                        options={activeLeafAccounts.map((acc: any) => ({ value: acc.code, label: `${acc.code} - ${acc.name}` }))}
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td className="misa-text-center">
                                                                                <Form.Item name={[field.name, 'unit']} noStyle>
                                                                                    <input className="misa-table-input misa-text-center" disabled={isViewMode} />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td className="misa-text-right">
                                                                                <Form.Item name={[field.name, 'quantity']} noStyle rules={[{ required: true, message: 'Nhập số lượng' }]}>
                                                                                    <InputNumber 
                                                                                        variant="borderless" 
                                                                                        min={0}
                                                                                        className="misa-w-full misa-text-right"
                                                                                        onChange={() => {
                                                                                            const cur = form.getFieldValue('lines') || [];
                                                                                            const q = cur[index]?.quantity ?? 0;
                                                                                            const p = cur[index]?.unit_price ?? 0;
                                                                                            cur[index].amount = q * p;
                                                                                            form.setFieldsValue({ lines: [...cur] });
                                                                                        }}
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td className="misa-text-right">
                                                                                <Form.Item name={[field.name, 'unit_price']} noStyle rules={[{ required: true, message: 'Nhập đơn giá' }]}>
                                                                                    <InputNumber 
                                                                                        variant="borderless" 
                                                                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} 
                                                                                        className="misa-w-full misa-text-right"
                                                                                        onChange={() => {
                                                                                            const cur = form.getFieldValue('lines') || [];
                                                                                            const q = cur[index]?.quantity ?? 0;
                                                                                            const p = cur[index]?.unit_price ?? 0;
                                                                                            cur[index].amount = q * p;
                                                                                            form.setFieldsValue({ lines: [...cur] });
                                                                                        }}
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td className="misa-text-right misa-text-bold">
                                                                                {new Intl.NumberFormat('vi-VN').format((formLines[index]?.quantity || 0) * (formLines[index]?.unit_price || 0))}
                                                                            </td>
                                                                        </>
                                                                    ) : (
                                                                        <>
                                                                            <td><span className="misa-text-semibold">{it?.code || '-'}</span></td>
                                                                            <td><span>{formLines[index]?.description || it?.name || '-'}</span></td>
                                                                            <td><span>{form.getFieldValue('contact_name') || '-'}</span></td>
                                                                            <td><input className="misa-table-input" placeholder="Khoản mục CP..." disabled={isViewMode} /></td>
                                                                            <td><input className="misa-table-input" placeholder="Đơn mua hàng..." disabled={isViewMode} /></td>
                                                                        </>
                                                                    )}

                                                                    <td className="misa-text-center">
                                                                        <button 
                                                                            type="button" 
                                                                            title="Xóa dòng"
                                                                            className="misa-row-del-btn"
                                                                            disabled={isViewMode}
                                                                            onClick={() => remove(index)}
                                                                        >
                                                                            <DeleteOutlined />
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            );
                                                        })}
                                                    </tbody>
                                                </table>
                                            </div>

                                            {/* Action Footer placed BELOW the table */}
                                            <MisaGridActionFooter 
                                                onAddLine={handleAddLine}
                                                onDeleteAll={() => {
                                                    form.setFieldValue('lines', []);
                                                    message.success('Đã xóa toàn bộ dòng nhập kho');
                                                }}
                                                lineCount={fields.length}
                                            />

                                            {/* Summary Bar */}
                                            <MisaTableSummaryBar 
                                                items={[
                                                    { label: 'Tổng số lượng', value: totals.totalQuantity, format: 'number' },
                                                    { label: 'Tổng tiền hàng', value: totals.grandTotal, format: 'currency', highlight: true }
                                                ]}
                                            />
                                        </div>
                                    )}
                                </Form.List>
                            </div>
                        </div>
                    </Form>
                </ModalFrame>
            </Modal>

                        {/* Quick Add Employee Modal */}
            <QuickAddEmployeeModal
                open={isEmployeeModalVisible}
                onCancel={() => setIsEmployeeModalVisible(false)}
                onSuccess={(newEmp: any) => {
                    queryClient.invalidateQueries({ queryKey: ['employees'] });
                    form.setFieldsValue({ employee_id: newEmp.id });
                    message.success(`Đã thêm nhanh nhân viên: ${newEmp.name}`);
                }}
            />

            {/* Reference Voucher Selection Modal */}
            <ReferenceVoucherModal
                open={isRefModalVisible}
                onCancel={() => setIsRefModalVisible(false)}
                onSelect={(selected: any[]) => {
                    const newRefs = [...referencedVouchers, ...selected];
                    setReferencedVouchers(newRefs);
                    if (selected.length > 0) {
                        const first = selected[0];
                        const patch: any = {
                            description: `Nhập kho theo ${selected.map((s: any) => s.voucher_number).join(', ')}`,
                        };
                        if (first.contact_id) patch.contact_id = first.contact_id;
                        if (first.contact_name) {
                            patch.contact_name = first.contact_name;
                            patch.deliverer_name = first.contact_name;
                        }
                        if (first.employee_id) patch.employee_id = first.employee_id;
                        form.setFieldsValue(patch);

                        const lineCandidates: any[] = [];
                        selected.forEach((v: any) => {
                            if (Array.isArray(v.lines) && v.lines.length > 0) {
                                v.lines.forEach((l: any) => {
                                    lineCandidates.push({
                                        item_id: l.item_id,
                                        warehouse_code: l.warehouse_code || warehouses?.[0]?.code,
                                        debit_account: l.debit_account || '1561',
                                        credit_account: l.credit_account || '331',
                                        unit: l.unit,
                                        quantity: l.quantity,
                                        unit_price: l.unit_price ?? l.cost_price,
                                        amount: (l.quantity || 0) * (l.unit_price || l.cost_price || 0),
                                        description: l.description || patch.description,
                                    });
                                });
                            }
                        });
                        if (lineCandidates.length > 0) {
                            form.setFieldsValue({ lines: lineCandidates });
                        }
                        message.success(`Đã nạp ${selected.length} chứng từ tham chiếu!`);
                    }
                }}
            />

            {/* Quick Add Supplier Modal */}
            <QuickAddContactModal 
                open={isSupplierModalVisible} 
                onCancel={() => setIsSupplierModalVisible(false)} 
                contactType="supplier"
                onSuccess={(newSupp) => {
                    queryClient.invalidateQueries({ queryKey: ['suppliers'] });
                    form.setFieldsValue({ 
                        contact_id: newSupp.id, 
                        contact_name: newSupp.name, 
                        receiver_address: newSupp.address,
                        deliverer_name: newSupp.contact_person || newSupp.name 
                    });
                }}
            />

            {/* Quick Add Item Modal */}
            <QuickAddItemModal 
                open={isItemModalVisible} 
                onCancel={() => { setIsItemModalVisible(false); setActiveRowIndex(null); }} 
                onSuccess={(newItem: any) => {
                    setIsItemModalVisible(false);
                    queryClient.invalidateQueries({ queryKey: ['items'] });
                    queryClient.invalidateQueries({ queryKey: ['inventory-items'] });
                    if (activeRowIndex !== null && newItem?.id) {
                        const curLines = form.getFieldValue('lines') || [];
                        const quantity = curLines[activeRowIndex]?.quantity || 1;
                        const price = Number(newItem.cost_price || newItem.sale_price) || 0;
                        curLines[activeRowIndex] = {
                            ...curLines[activeRowIndex],
                            item_id: newItem.id,
                            item_name: newItem.name,
                            unit: newItem.unit || 'Cái',
                            unit_price: price,
                            amount: price * quantity,
                            description: `Nhập kho ${newItem.name}`,
                        };
                        form.setFieldsValue({ lines: [...curLines] });
                    }
                    setActiveRowIndex(null);
                    message.success(`Đã thêm nhanh vật tư hàng hóa: ${newItem.name || newItem.code}`);
                }}
            />
        </PageShell>
    );
};

export default InventoryReceipts;
