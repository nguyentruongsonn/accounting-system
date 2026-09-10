import React, { useState, useEffect } from 'react';
import { Alert, Table, Button, Input, Select, DatePicker, Form, InputNumber, Dropdown, Checkbox, Tabs, Space } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { MenuProps } from 'antd';
import { 
    PlusOutlined, 
    DeleteOutlined, 
    PaperClipOutlined,
    CloseOutlined,
    CopyOutlined,
    EditOutlined,
    EyeOutlined,
    CheckCircleOutlined,
    CaretDownOutlined,
    CaretRightOutlined,
    InboxOutlined,
    SearchOutlined,
    SettingOutlined,
    ReloadOutlined,
    PrinterOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { 
    QuickAddContactModal, 
    QuickAddEmployeeModal,
    QuickAddPaymentTermModal,
    QuickAddItemModal,
    ReferenceVoucherModal,
    MultiColumnContactSelect,
    MisaMasterCard,
    MisaTotalCard
} from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';

/**
 * Keep malformed or failed catalogue responses distinct from a valid empty list.
 * React Query must receive the rejection so the screen can expose a retry action
 * instead of silently presenting an empty purchase-contract workspace.
 */
function parsePurchaseCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: T[] }).data;
    }
    throw new Error(`Invalid ${resource} response.`);
}

export const PurchaseContracts: React.FC = () => {
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<string>('all');
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingContract, setEditingContract] = useState<any>(null);
    const [selectedContractId, setSelectedContractId] = useState<number | null>(null);
    const [isDetailPaneOpen, setIsDetailPaneOpen] = useState(true);
    const [isProgressModalOpen, setIsProgressModalOpen] = useState(false);
    const [progressContract, setProgressContract] = useState<any>(null);
    const [isPreSoftware, setIsPreSoftware] = useState(false);
    const [isReferenceModalOpen, setIsReferenceModalOpen] = useState(false);
    const [modalActiveTab, setModalActiveTab] = useState<'goods' | 'schedule' | 'extra'>('goods');

    // Quick Add Modal States
    const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
    const [isEmployeeModalVisible, setIsEmployeeModalVisible] = useState(false);
    const [isPaymentTermModalVisible, setIsPaymentTermModalVisible] = useState(false);
    const [isItemModalVisible, setIsItemModalVisible] = useState(false);
    const [activeRowIndex, setActiveRowIndex] = useState<number>(0);

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    // Query Contracts from Backend API
    const {
        data: contracts = [],
        isLoading,
        isError: isContractsError,
        refetch: refetchContracts,
    } = useQuery({
        queryKey: ['purchase-contracts', statusFilter, searchText],
        queryFn: async () => {
            const { data } = await api.get('/purchase/contracts', {
                params: { status: statusFilter, search: searchText }
            });
            return parsePurchaseCollection<any>(data, 'purchase contracts');
        }
    });

    // Query POs for import selector
    const { data: orders = [] } = useQuery({
        queryKey: ['purchase-orders'],
        queryFn: async () => {
            const { data } = await api.get('/purchase/orders');
            return parsePurchaseCollection<any>(data, 'purchase orders');
        }
    });

    // Query Suppliers, Employees, Items, Payment Terms
    const { data: suppliers = [] } = useQuery({
        queryKey: ['suppliers'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parsePurchaseCollection<any>(data, 'suppliers');
        }
    });

    const { data: employees = [] } = useQuery({
        queryKey: ['employees'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parsePurchaseCollection<any>(data, 'employees');
        }
    });

    const { data: items = [] } = useQuery({
        queryKey: ['inventory-items'],
        queryFn: async () => {
            const { data } = await api.get('/inventory/items');
            return parsePurchaseCollection<any>(data, 'inventory items');
        }
    });

    const { data: paymentTerms = [] } = useQuery({
        queryKey: ['payment-terms'],
        queryFn: async () => {
            const { data } = await api.get('/master/payment-terms');
            return parsePurchaseCollection<any>(data, 'payment terms');
        }
    });

    const supplierList = suppliers;
    const employeeList = employees;
    const itemList = items;
    const termList = paymentTerms;
    const orderList = orders;
    const contractList = contracts;

    // Select first contract by default
    useEffect(() => {
        if (contractList.length > 0 && selectedContractId === null) {
            setSelectedContractId(contractList[0].id);
        }
    }, [contractList, selectedContractId]);

    const activeContract = contractList.find((c: any) => c.id === selectedContractId) || contractList[0];

    // Save Mutation (Create / Update)
    const saveMutation = useMutation({
        mutationFn: async (values: any) => {
            const payload = {
                contract_number: values.contract_number,
                contract_name: values.contract_name || `Hợp đồng mua hàng ${values.contract_number}`,
                signed_date: values.signed_date?.format('YYYY-MM-DD') || dayjs().format('YYYY-MM-DD'),
                effective_date: values.effective_date?.format('YYYY-MM-DD') || null,
                end_date: values.end_date?.format('YYYY-MM-DD') || null,
                delivery_deadline: values.delivery_deadline?.format('YYYY-MM-DD') || null,
                payment_deadline: values.payment_deadline?.format('YYYY-MM-DD') || null,
                currency: 'VND',
                exchange_rate: 1,
                reference: values.reference || null,
                supplier_id: values.supplier_id,
                supplier_code: supplierList.find((s: any) => s.id === values.supplier_id)?.code || '',
                supplier_name: values.supplier_name,
                supplier_address: values.supplier_address,
                tax_code: values.tax_code,
                contact_person: values.contact_person,
                buyer_name: employeeList.find((e: any) => e.id === values.employee_id)?.name || '',
                contract_value: grandTotalAmount,
                discount_amount: totalDiscount,
                vat_amount: totalTax,
                total_amount: grandTotalAmount,
                status: values.status || 'pending',
                delivery_status: values.delivery_status || 'not_delivered',
                summary: values.summary,
                other_terms: values.other_terms,
                shipping_address: values.shipping_address,
                is_pre_software: isPreSoftware,
                liquidation_amount: values.liquidation_amount || 0,
                liquidation_date: values.liquidation_date?.format('YYYY-MM-DD') || null,
                liquidation_reason: values.liquidation_reason || null,
                lines: values.lines?.map((line: any) => {
                    const q = Number(line.quantity_requested) || 1;
                    const p = Number(line.unit_price) || 0;
                    const amt = q * p;
                    const discRate = Number(line.discount_rate) || 0;
                    const discAmt = Number(line.discount_amount) || (amt * discRate / 100);
                    const vatRate = typeof line.tax_rate === 'number' ? line.tax_rate : 10;
                    const vatAmt = Number(line.tax_amount) || ((amt - discAmt) * (vatRate / 100));
                    return {
                        item_id: line.item_id,
                        item_code: itemList.find((i: any) => i.id === line.item_id)?.code || line.item_code,
                        item_name: line.item_name || line.description,
                        unit: line.unit || 'Cái',
                        quantity_requested: q,
                        quantity_delivered: Number(line.quantity_delivered) || 0,
                        unit_price: p,
                        amount: amt,
                        discount_rate: discRate,
                        discount_amount: discAmt,
                        tax_rate: vatRate,
                        tax_amount: vatAmt,
                        total_amount: amt - discAmt + vatAmt,
                    };
                }) || [],
                payments: values.payments?.map((pm: any, idx: number) => ({
                    stage_number: idx + 1,
                    stage_name: pm.stage_name || `Đợt ${idx + 1}`,
                    payment_rate: Number(pm.payment_rate) || 0,
                    payment_amount: Number(pm.payment_amount) || 0,
                    due_date: pm.due_date?.format ? pm.due_date.format('YYYY-MM-DD') : (pm.due_date || null),
                    payment_date: pm.payment_date?.format ? pm.payment_date.format('YYYY-MM-DD') : (pm.payment_date || null),
                    paid_amount: Number(pm.paid_amount) || 0,
                    prev_year_paid: Number(pm.prev_year_paid) || 0,
                    remaining_amount: (Number(pm.payment_amount) || 0) - (Number(pm.paid_amount) || 0),
                    notes: pm.notes || `Đợt ${idx + 1}`,
                    voucher_ref: pm.voucher_ref || null
                })) || []
            };

            if (editingContract?.id) {
                return api.put(`/purchase/contracts/${editingContract.id}`, payload);
            }
            return api.post('/purchase/contracts', payload);
        },
        onSuccess: (response) => {
            if (response?.data?.id === undefined || response?.data?.id === null) {
                message.error('Máy chủ không trả về hợp đồng mua hàng đã lưu; không thể báo thành công.');
                return;
            }
            message.success(editingContract?.id ? 'Cập nhật hợp đồng mua hàng thành công!' : 'Cất hợp đồng mua hàng thành công!');
            setIsModalOpen(false);
            setEditingContract(null);
            queryClient.invalidateQueries({ queryKey: ['purchase-contracts'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra!');
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => api.delete(`/purchase/contracts/${id}`),
        onSuccess: (response) => {
            if (typeof response?.data?.message !== 'string' || response.data.message.trim() === '') {
                message.error('Máy chủ không xác nhận đã xóa hợp đồng mua hàng; không thể báo thành công.');
                return;
            }
            message.success('Đã xóa hợp đồng mua hàng thành công');
            queryClient.invalidateQueries({ queryKey: ['purchase-contracts'] });
        }
    });

    const updateStatusMutation = useMutation({
        mutationFn: async ({ id, status }: { id: number, status: string }) => {
            return api.put(`/purchase/contracts/${id}`, { status });
        },
        onSuccess: (response) => {
            if (response?.data?.id === undefined || response?.data?.id === null) {
                message.error('Máy chủ không trả về hợp đồng mua hàng đã cập nhật; không thể báo thành công.');
                return;
            }
            message.success('Cập nhật trạng thái thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-contracts'] });
        }
    });

    const handleOpenCreateModal = async () => {
        form.resetFields();
        setEditingContract(null);
        setIsPreSoftware(false);
        const today = dayjs();
        form.setFieldsValue({
            contract_number: '',
            contract_name: undefined,
            signed_date: today,
            effective_date: today,
            end_date: today.add(1, 'year'),
            delivery_deadline: today.add(1, 'month'),
            payment_deadline: today.add(30, 'day'),
            status: 'pending',
            delivery_status: 'not_delivered',
            payment_terms: 'ĐKTT30',
            summary: undefined,
            lines: [],
            payments: [
                {
                    stage_name: 'Đợt 1 (Tạm ứng)',
                    payment_rate: 30,
                    payment_amount: 0,
                    due_date: today.add(7, 'day'),
                    paid_amount: 0,
                    prev_year_paid: 0,
                    remaining_amount: 0,
                    notes: 'Tạm ứng khi ký hợp đồng'
                },
                {
                    stage_name: 'Đợt 2 (Nghiệm thu)',
                    payment_rate: 70,
                    payment_amount: 0,
                    due_date: today.add(30, 'day'),
                    paid_amount: 0,
                    prev_year_paid: 0,
                    remaining_amount: 0,
                    notes: 'Thanh toán sau khi bàn giao đủ hàng'
                }
            ]
        });
        setIsModalOpen(true);

        try {
            const { data } = await api.get('/purchase/contracts/next-code');
            if (data?.next_code) {
                form.setFieldsValue({ contract_number: data.next_code });
            }
        } catch (e) {
            // Keep empty
        }
    };

    const handleEditContract = (record: any) => {
        setEditingContract(record);
        setIsPreSoftware(record.is_pre_software || false);
        form.resetFields();
        form.setFieldsValue({
            ...record,
            signed_date: record.signed_date ? dayjs(record.signed_date) : dayjs(),
            effective_date: record.effective_date ? dayjs(record.effective_date) : null,
            end_date: record.end_date ? dayjs(record.end_date) : null,
            delivery_deadline: record.delivery_deadline ? dayjs(record.delivery_deadline) : null,
            payment_deadline: record.payment_deadline ? dayjs(record.payment_deadline) : null,
            liquidation_date: record.liquidation_date ? dayjs(record.liquidation_date) : null,
            lines: record.lines?.length > 0 ? record.lines : [],
            payments: record.payments?.length > 0 ? record.payments.map((p: any) => ({
                ...p,
                stage_name: p.stage_name || p.notes || `Đợt ${p.stage_number}`,
                due_date: p.due_date ? dayjs(p.due_date) : null,
                payment_date: p.payment_date ? dayjs(p.payment_date) : null,
            })) : []
        });
        setIsModalOpen(true);
    };

    const handleDuplicateContract = async (record: any) => {
        let nextCode = '';
        try {
            const { data } = await api.get('/purchase/contracts/next-code');
            if (data?.next_code) nextCode = data.next_code;
        } catch (e) {
            message.warning('Chưa lấy được số hợp đồng nhân bản từ máy chủ; không mở bản sao với số tự sinh.');
            return;
        }

        if (!nextCode) {
            message.warning('Máy chủ chưa cấp số hợp đồng nhân bản; không thể tiếp tục.');
            return;
        }

        setEditingContract(null);
        form.resetFields();
        form.setFieldsValue({
            ...record,
            contract_number: nextCode,
            contract_name: `Nhân bản từ ${record.contract_number} - ${record.contract_name}`,
            signed_date: dayjs(),
            status: 'pending',
            delivery_status: 'not_delivered',
            summary: `Nhân bản từ hợp đồng ${record.contract_number}`
        });
        setIsModalOpen(true);
        message.info(`Đã nạp dữ liệu nhân bản sang mã mới ${nextCode}; hãy kiểm tra và bấm Cất để lưu hợp đồng mới.`);
    };

    const handleImportFromPO = (poId: number) => {
        const po = orderList.find((o: any) => o.id === poId);
        if (po) {
            form.setFieldsValue({
                supplier_id: po.supplier_id,
                supplier_name: po.supplier_name,
                supplier_address: po.supplier_address,
                tax_code: po.tax_code,
                contact_person: po.contact_person,
                contract_name: `Hợp đồng theo đơn mua hàng ${po.order_number}`,
                summary: `Ký kết theo ĐMH ${po.order_number}: ${po.description || ''}`,
                lines: po.lines?.map((line: any) => ({
                    item_id: line.item_id,
                    item_code: line.item_code,
                    item_name: line.item_name,
                    unit: line.unit,
                    quantity_requested: line.quantity,
                    quantity_delivered: line.received_quantity || 0,
                    unit_price: line.unit_price,
                    amount: line.amount,
                    discount_rate: line.discount_rate || 0,
                    discount_amount: line.discount_amount || 0,
                    tax_rate: line.tax_rate || 10,
                    tax_amount: line.tax_amount || 0,
                    total_amount: line.amount - (line.discount_amount || 0) + (line.tax_amount || 0)
                }))
            });
            message.success(`Đã lấy dữ liệu từ đơn mua hàng ${po.order_number}!`);
        }
    };

    const handleSupplierChange = (supplierId: any, item?: any) => {
        const supplier = item || supplierList?.find((s: any) => s.id === Number(supplierId) || s.code === supplierId);
        if (supplier) {
            form.setFieldsValue({
                supplier_id: supplier.id,
                supplier_name: supplier.name,
                supplier_address: supplier.address,
                tax_code: supplier.tax_code,
                contact_person: supplier.contact_name || supplier.contact_person || '',
            });
        }
    };


    const handleItemChange = (index: number, itemId: number) => {
        const item = itemList?.find((i: any) => i.id === itemId);
        if (item) {
            const curLines = form.getFieldValue('lines') || [];
            const price = Number(item.cost_price) || 0;
            const qty = Number(curLines[index]?.quantity_requested) || 1;
            const amt = price * qty;
            const discRate = Number(curLines[index]?.discount_rate) || 0;
            const discAmt = amt * (discRate / 100);
            const taxRate = typeof curLines[index]?.tax_rate === 'number' ? curLines[index]?.tax_rate : 10;
            const taxAmt = (amt - discAmt) * (taxRate / 100);
            const tot = amt - discAmt + taxAmt;
            curLines[index] = {
                ...curLines[index],
                item_id: item.id,
                item_code: item.code,
                item_name: item.name,
                unit: item.unit || 'Cái',
                unit_price: price,
                amount: amt,
                discount_rate: discRate,
                discount_amount: discAmt,
                tax_amount: taxAmt,
                total_amount: tot
            };
            form.setFieldsValue({ lines: [...curLines] });
        }
    };

    const formLines = Form.useWatch('lines', form) || [];
    const totalQty = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.quantity_requested) || 0), 0);
    const totalDeliveredQty = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.quantity_delivered) || 0), 0);
    const totalAmount = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.amount) || ((Number(curr?.quantity_requested) || 0) * (Number(curr?.unit_price) || 0))), 0);
    const totalDiscount = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.discount_amount) || 0), 0);
    const totalTax = formLines.reduce((acc: number, curr: any) => acc + (Number(curr?.tax_amount) || 0), 0);
    const grandTotalAmount = (totalAmount - totalDiscount) + totalTax;

    const getActionMenu = (record: any): MenuProps => ({
        items: [
            {
                key: 'view_progress',
                icon: <EyeOutlined />,
                label: 'Xem tình hình thực hiện',
                onClick: () => {
                    setProgressContract(record);
                    setIsProgressModalOpen(true);
                }
            },
            {
                key: 'edit',
                icon: <EditOutlined />,
                label: 'Sửa',
                onClick: () => handleEditContract(record)
            },
            {
                key: 'duplicate',
                icon: <CopyOutlined />,
                label: 'Nhân bản',
                onClick: () => handleDuplicateContract(record)
            },
            {
                key: 'status',
                icon: <CheckCircleOutlined />,
                label: 'Cập nhật tình trạng',
                children: [
                    { key: 'st_pending', label: 'Chưa thực hiện', onClick: () => updateStatusMutation.mutate({ id: record.id, status: 'pending' }) },
                    { key: 'st_active', label: 'Đang thực hiện', onClick: () => updateStatusMutation.mutate({ id: record.id, status: 'active' }) },
                    { key: 'st_liquidated', label: 'Đã hoàn thành', onClick: () => updateStatusMutation.mutate({ id: record.id, status: 'liquidated' }) },
                    { key: 'st_cancelled', label: 'Đã hủy bỏ', onClick: () => updateStatusMutation.mutate({ id: record.id, status: 'cancelled' }) },
                ]
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

    const masterColumns = [
        { 
            title: 'Số hợp đồng', 
            dataIndex: 'contract_number', 
            key: 'contract_number', 
            width: 130,
            fixed: 'left' as const,
            render: (t: string, r: any) => (
                <span 
                    className="misa-table-link-bold misa-cursor-pointer"
                    onClick={() => handleEditContract(r)}
                >
                    {t}
                </span>
            )
        },
        { 
            title: 'Ngày ký', 
            dataIndex: 'signed_date', 
            key: 'signed_date', 
            width: 110, 
            align: 'center' as const,
            render: (d: string) => d ? dayjs(d).format('DD/MM/YYYY') : '-'
        },
        { 
            title: 'Nhà cung cấp', 
            dataIndex: 'supplier_name', 
            key: 'supplier_name', 
            width: 280,
            render: (t: string, r: any) => (
                <div className="misa-cell-title-box">
                    <div className="misa-cell-main-title">{t || '—'}</div>
                    <div className="misa-cell-sub-title">{r.contract_name || ''}</div>
                </div>
            )
        },
        { 
            title: 'Giá trị hợp đồng', 
            dataIndex: 'contract_value', 
            key: 'contract_value', 
            width: 150, 
            align: 'right' as const,
            render: (v: number) => <span className="misa-table-amount-dark">{new Intl.NumberFormat('vi-VN').format(v || 0)} ₫</span>
        },
        { 
            title: 'Giá trị thanh lý', 
            dataIndex: 'liquidation_amount', 
            key: 'liquidation_amount', 
            width: 130, 
            align: 'right' as const,
            render: (v: number) => <span>{new Intl.NumberFormat('vi-VN').format(v || 0)} ₫</span>
        },
        { 
            title: 'Giá trị thực hiện', 
            dataIndex: 'fulfilled_amount', 
            key: 'fulfilled_amount', 
            width: 140, 
            align: 'right' as const,
            render: (_: any, r: any) => r.fulfilled_amount == null
                ? <span>—</span>
                : <span>{new Intl.NumberFormat('vi-VN').format(r.fulfilled_amount)} ₫</span>
        },
        { 
            title: 'Số đợt TT', 
            dataIndex: 'payments', 
            key: 'payment_count', 
            width: 95, 
            align: 'center' as const,
            render: (p: any[]) => p == null ? '—' : p.length
        },
        { 
            title: 'Số đã trả', 
            dataIndex: 'paid_amount', 
            key: 'paid_amount', 
            width: 130, 
            align: 'right' as const,
            render: (v: number) => <span>{new Intl.NumberFormat('vi-VN').format(v || 0)} ₫</span>
        },
        { 
            title: 'Số còn phải trả', 
            dataIndex: 'remain_amount', 
            key: 'remain_amount', 
            width: 135, 
            align: 'right' as const,
            render: (_: any, r: any) => {
                const remain = (Number(r.contract_value) || 0) - (Number(r.paid_amount) || 0);
                return <span>{new Intl.NumberFormat('vi-VN').format(remain)} ₫</span>;
            }
        },
        { 
            title: 'Tình trạng HĐ', 
            dataIndex: 'status', 
            key: 'status', 
            width: 140, 
            align: 'center' as const,
            render: (s: string) => {
                if (s === 'active' || s === 'processing') return <span>Đang thực hiện</span>;
                if (s === 'completed' || s === 'liquidated') return <span>Đã hoàn thành</span>;
                if (s === 'cancelled') return <span className="misa-text-disabled">Đã hủy bỏ</span>;
                return <span>Chưa thực hiện</span>;
            }
        },
        { 
            title: 'Tình trạng giao hàng', 
            dataIndex: 'delivery_status', 
            key: 'delivery_status', 
            width: 140, 
            align: 'center' as const,
            render: (s: string) => {
                if (s === 'delivered') return <span>Đã giao đủ</span>;
                if (s === 'partial') return <span>Giao một phần</span>;
                return <span>Chưa giao</span>;
            }
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 170,
            fixed: 'right' as const,
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

    const detailColumns = [
        { title: '#', width: 40, render: (_: any, __: any, idx: number) => idx + 1, align: 'center' as const },
        { title: 'Mã hàng', dataIndex: 'item_code', key: 'item_code', width: 140, render: (t: string) => <span className="misa-text-bold">{t || '—'}</span> },
        { title: 'Tên hàng', dataIndex: 'item_name', key: 'item_name', width: 240 },
        { title: 'ĐVT', dataIndex: 'unit', key: 'unit', width: 70 },
        { title: 'Số lượng yêu cầu', dataIndex: 'quantity_requested', key: 'quantity_requested', width: 120, align: 'right' as const, render: (v: number) => v == null ? '—' : Number(v) },
        { title: 'Số lượng đã giao', dataIndex: 'quantity_delivered', key: 'quantity_delivered', width: 120, align: 'right' as const, render: (v: number) => v == null ? '—' : Number(v) },
        { title: 'Đơn giá', dataIndex: 'unit_price', key: 'unit_price', width: 120, align: 'right' as const, render: (v: number) => new Intl.NumberFormat('vi-VN').format(v || 0) },
        { title: 'Thành tiền', dataIndex: 'amount', key: 'amount', width: 130, align: 'right' as const, render: (v: number) => <span className="misa-text-bold">{new Intl.NumberFormat('vi-VN').format(v || 0)} ₫</span> },
        { title: '% Thuế GTGT', dataIndex: 'tax_rate', key: 'tax_rate', width: 90, align: 'right' as const, render: (v: number) => v == null ? '—' : `${v}%` },
        { title: 'Tiền thuế GTGT', dataIndex: 'tax_amount', key: 'tax_amount', width: 120, align: 'right' as const, render: (v: number) => new Intl.NumberFormat('vi-VN').format(v || 0) },
        { title: 'Tổng tiền thanh toán', dataIndex: 'total_amount', key: 'total_amount', width: 140, align: 'right' as const, render: (v: number) => <span className="misa-text-bold">{new Intl.NumberFormat('vi-VN').format(v || 0)} ₫</span> },
    ];

    return (
        <PageShell
            className="apple-ledger-page"
            title={<PageHeader
                eyebrow="MUA HÀNG"
                title="Hợp đồng mua hàng"
                description="Quản lý thông tin các hợp đồng kinh tế và tiến độ thực hiện với nhà cung cấp."
            />}
            toolbar={(
                <PageToolbar
                    filters={(
                        <div className="ui-page-toolbar__filter-group flex items-center gap-2">
                            <Input
                                placeholder="Tìm kiếm số hợp đồng, nhà cung cấp..."
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
                                    { value: 'active', label: 'Đang thực hiện' },
                                    { value: 'liquidated', label: 'Đã hoàn thành' },
                                    { value: 'cancelled', label: 'Đã hủy bỏ' },
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
                                onClick={() => queryClient.invalidateQueries({ queryKey: ['purchase-contracts'] })}
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
                                Thêm hợp đồng mua
                            </Button>
                        </div>
                    )}
                />
            )}
        >
            <DataTableSurface className="misa-voucher-surface">

            {/* Master Table Grid */}
            <div className="misa-table-card-auto">
                {isContractsError && (
                    <Alert
                        className="m-3"
                        type="error"
                        showIcon
                        message="Không thể tải danh sách hợp đồng mua hàng"
                        description="Không hiển thị dữ liệu thay thế; hãy thử tải lại danh sách hợp đồng."
                        action={(
                            <Button size="small" onClick={() => void refetchContracts()}>
                                Thử lại danh sách hợp đồng mua hàng
                            </Button>
                        )}
                    />
                )}
                <Table 
                    className="misa-voucher-table"
                    columns={masterColumns}
                    dataSource={contractList}
                    rowKey="id"
                    loading={isLoading}
                    pagination={false}
                    size="small"
                    scroll={{ x: 1750 }}
                    onRow={(record) => ({
                        onClick: () => setSelectedContractId(record.id),
                        className: selectedContractId === record.id ? 'misa-table-cell-pointer misa-voucher-table-row-selected' : 'misa-table-cell-pointer'
                    })}
                    summary={() => {
                        const totalVal = contractList.reduce((acc: number, c: any) => acc + (Number(c.contract_value) || 0), 0);
                        const totalPaid = contractList.reduce((acc: number, c: any) => acc + (Number(c.paid_amount) || 0), 0);
                        const totalRemain = totalVal - totalPaid;
                        return (
                            <Table.Summary fixed>
                                <Table.Summary.Row className="misa-table-summary-row">
                                    <Table.Summary.Cell index={0} colSpan={3}>
                                        <span>Tổng ({contractList.length} hợp đồng)</span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={1} align="right">
                                        <span className="misa-table-summary-total">
                                            {new Intl.NumberFormat('vi-VN').format(totalVal)} ₫
                                        </span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={2} align="right">0 ₫</Table.Summary.Cell>
                                    <Table.Summary.Cell index={3} align="right">
                                        <span>{new Intl.NumberFormat('vi-VN').format(totalVal)} ₫</span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={4} colSpan={1}></Table.Summary.Cell>
                                    <Table.Summary.Cell index={5} align="right">
                                        <span>{new Intl.NumberFormat('vi-VN').format(totalPaid)} ₫</span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={6} align="right">
                                        <span>{new Intl.NumberFormat('vi-VN').format(totalRemain)} ₫</span>
                                    </Table.Summary.Cell>
                                    <Table.Summary.Cell index={7} colSpan={3}></Table.Summary.Cell>
                                </Table.Summary.Row>
                            </Table.Summary>
                        );
                    }}
                />
            </div>

            {/* Collapsible Detail Split Pane */}
            <div className="misa-split-pane-container">
                <div 
                    onClick={() => setIsDetailPaneOpen(!isDetailPaneOpen)}
                    className="misa-split-pane-header"
                >
                    <div className="misa-split-pane-title">
                        {isDetailPaneOpen ? <CaretDownOutlined /> : <CaretRightOutlined />}
                        <span>Chi tiết hàng hóa hợp đồng {activeContract?.contract_number || '—'}</span>
                        <span className="misa-split-pane-subtitle">({activeContract?.supplier_name || '—'})</span>
                    </div>

                    <div className="misa-split-pane-subtitle">
                        Tổng số: <strong>{activeContract?.lines?.length || 0}</strong> mặt hàng
                    </div>
                </div>

                {isDetailPaneOpen && (
                    <div className="misa-split-pane-body">
                        <Table 
                            className="misa-voucher-table"
                            columns={detailColumns}
                            dataSource={activeContract?.lines || []}
                            rowKey="id"
                            pagination={false}
                            size="small"
                        />
                    </div>
                )}
            </div>

            {/* Modal "Thêm / Sửa Hợp đồng mua" (Exact MISA AMIS UI) */}
            <Modal
                open={isModalOpen}
                onCancel={() => setIsModalOpen(false)}
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
                                {editingContract?.id ? `Sửa Hợp đồng mua ${form.getFieldValue('contract_number')}` : `Hợp đồng mua ${form.getFieldValue('contract_number') || '—'}`}
                            </span>

                            {/* PO Import Selector */}
                            <Select 
                                showSearch
                                placeholder="Nhập số đơn mua hàng để lấy dữ liệu"
                                prefix={<SearchOutlined />}
                                className="misa-input misa-w-280"
                                onChange={handleImportFromPO}
                                options={orderList.map((o: any) => ({
                                    value: o.id,
                                    label: `${o.order_number} - ${o.supplier_name}`
                                }))}
                            />
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
                        <div className="misa-footer-left"></div>
                        <div className="misa-footer-right">
                            <Space size={8}>
                                <Button onClick={() => setIsModalOpen(false)} className="misa-btn-secondary">
                                    Hủy (Esc)
                                </Button>
                                <Button 
                                    onClick={() => form.validateFields().then(values => {
                                        saveMutation.mutate(values);
                                    })}
                                    loading={saveMutation.isPending}
                                    className="misa-btn-secondary misa-text-bold"
                                >
                                    Cất
                                </Button>
                                <Button 
                                    type="primary" 
                                    className="misa-btn-primary misa-text-bold" 
                                    loading={saveMutation.isPending}
                                    onClick={() => form.validateFields().then(values => saveMutation.mutate(values))}
                                >
                                    Cất và Đóng
                                </Button>
                            </Space>
                        </div>
                    </div>
                }
            >
                <ModalFrame bodyStyle={{ width: '100%', maxWidth: '100%', minWidth: 0, overflowX: 'hidden' }}>
                    <Form form={form} layout="vertical">
                                
                                {/* 1. Master Info Section */}
                                <MisaMasterCard className="misa-mb-12">
                                    <div className="misa-master-layout">
                                        {/* Left: General Info */}
                                        <div className="misa-master-left">
                                            <div className="misa-form-grid">
                                                <div className="misa-col-4">
                                                    <div className="misa-field-label">Mã nhà cung cấp <span className="misa-text-danger">*</span></div>
                                                    <Form.Item name="supplier_id" noStyle rules={[{ required: true, message: 'Chọn nhà cung cấp' }]}>
                                                        <MultiColumnContactSelect
                                                            placeholder="Chọn nhà cung cấp..."
                                                            entityType="supplier"
                                                            options={supplierList.map((s: any) => ({
                                                                id: s.id,
                                                                code: s.code || '',
                                                                name: s.name,
                                                                tax_code: s.tax_code,
                                                                address: s.address,
                                                                phone: s.phone,
                                                                contact_person: s.contact_name
                                                            }))}
                                                            value={form.getFieldValue('supplier_id')}
                                                            onChange={(val, item) => handleSupplierChange(val, item)}
                                                            onQuickAdd={() => setIsSupplierModalVisible(true)}
                                                        />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-col-8">
                                                    <div className="misa-field-label">Tên nhà cung cấp</div>
                                                    <Form.Item name="supplier_name" noStyle>
                                                        <Input className="misa-input" placeholder="Tên đối tác" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-col-8">
                                                    <div className="misa-field-label">Địa chỉ</div>
                                                    <Form.Item name="supplier_address" noStyle>
                                                        <Input className="misa-input" placeholder="Địa chỉ nhà cung cấp" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-col-4">
                                                    <div className="misa-field-label">Mã số thuế</div>
                                                    <Form.Item name="tax_code" noStyle>
                                                        <Input className="misa-input" placeholder="Mã số thuế" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-col-4">
                                                    <div className="misa-field-label">Người liên hệ</div>
                                                    <Form.Item name="contact_person" noStyle>
                                                        <Input className="misa-input" placeholder="Người liên hệ" />
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
                                                                options={employeeList.map((e: any) => ({ value: e.id, label: (e.code ? e.code + ' - ' : '') + e.name }))}
                                                            />
                                                        </Form.Item>
                                                        <button 
                                                            type="button" 
                                                            className="misa-plus-btn" 
                                                            onClick={() => setIsEmployeeModalVisible(true)}
                                                            title="Thêm nhanh nhân viên"
                                                        >
                                                            <PlusOutlined className="misa-btn-plus-icon-sm" />
                                                        </button>
                                                    </div>
                                                </div>

                                                <div className="misa-col-3">
                                                    <div className="misa-field-label">Tham chiếu</div>
                                                    <div className="misa-flex-center misa-gap-6">
                                                        <Form.Item name="reference" noStyle>
                                                            <Input className="misa-input" placeholder="Số CT tham chiếu..." />
                                                        </Form.Item>
                                                        <button
                                                            type="button" 
                                                            onClick={() => setIsReferenceModalOpen(true)}
                                                            className="misa-btn-tool misa-btn-tool-ref"
                                                            title="Chọn chứng từ tham chiếu"
                                                        >
                                                            ...
                                                        </button>
                                                    </div>
                                                </div>

                                                <div className="misa-col-6">
                                                    <div className="misa-field-label">Tên hợp đồng <span className="misa-text-danger">*</span></div>
                                                    <Form.Item name="contract_name" noStyle rules={[{ required: true, message: 'Nhập tên hợp đồng' }]}>
                                                        <Input className="misa-input" placeholder="Tên hợp đồng..." />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-col-3">
                                                    <div className="misa-field-label">Tình trạng HĐ <span className="misa-text-danger">*</span></div>
                                                    <Form.Item name="status" noStyle initialValue="pending">
                                                        <Select 
                                                            className="misa-input misa-w-full" 
                                                            options={[
                                                                { value: 'pending', label: 'Chưa thực hiện' },
                                                                { value: 'active', label: 'Đang thực hiện' },
                                                                { value: 'liquidated', label: 'Đã hoàn thành' },
                                                                { value: 'cancelled', label: 'Đã hủy bỏ' },
                                                            ]}
                                                        />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-col-3">
                                                    <div className="misa-field-label">Tình trạng giao hàng</div>
                                                    <Form.Item name="delivery_status" noStyle initialValue="not_delivered">
                                                        <Select 
                                                            className="misa-input misa-w-full" 
                                                            options={[
                                                                { value: 'not_delivered', label: 'Chưa giao' },
                                                                { value: 'partial', label: 'Giao một phần' },
                                                                { value: 'delivered', label: 'Đã giao đủ' },
                                                            ]}
                                                        />
                                                    </Form.Item>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Right: Meta & Total */}
                                        <div className="misa-master-right">
                                            <div className="misa-flex-col misa-gap-6">
                                                <div className="misa-meta-row">
                                                    <span className="misa-field-label required">Ngày ký</span>
                                                    <Form.Item name="signed_date" noStyle rules={[{ required: true }]}>
                                                        <DatePicker className="misa-input misa-col-w-175" format="DD/MM/YYYY" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-meta-row">
                                                    <span className="misa-field-label">Ngày hiệu lực</span>
                                                    <Form.Item name="effective_date" noStyle>
                                                        <DatePicker className="misa-input misa-col-w-175" format="DD/MM/YYYY" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-meta-row">
                                                    <span className="misa-field-label">Hạn giao hàng</span>
                                                    <Form.Item name="delivery_deadline" noStyle>
                                                        <DatePicker className="misa-input misa-col-w-175" format="DD/MM/YYYY" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-meta-row">
                                                    <span className="misa-field-label">Hạn thanh toán</span>
                                                    <Form.Item name="payment_deadline" noStyle>
                                                        <DatePicker className="misa-input misa-col-w-175" format="DD/MM/YYYY" />
                                                    </Form.Item>
                                                </div>

                                                <div className="misa-meta-row">
                                                    <span className="misa-field-label required">Số hợp đồng</span>
                                                    <Form.Item name="contract_number" noStyle rules={[{ required: true }]}>
                                                        <Input className="misa-input misa-col-w-175 misa-table-link-bold misa-text-blue" />
                                                    </Form.Item>
                                                </div>
                                            </div>

                                            <MisaTotalCard
                                                label="GIÁ TRỊ HỢP ĐỒNG"
                                                amount={grandTotalAmount}
                                            />
                                        </div>
                                    </div>
                                </MisaMasterCard>

                                {/* 2. Detail Section with Clean Tabs */}
                                <div style={{ background: '#fff', border: '1px solid #e2e8f0', borderRadius: 6, padding: 12 }}>
                                    <Tabs
                                        activeKey={modalActiveTab}
                                        onChange={k => setModalActiveTab(k as any)}
                                        items={[
                                            {
                                                key: 'goods',
                                                label: <span className="misa-text-semibold">1. Hàng hóa / Dịch vụ</span>
                                            },
                                            {
                                                key: 'schedule',
                                                label: <span className="misa-text-semibold">2. Tiến độ thanh toán</span>
                                            },
                                            {
                                                key: 'extra',
                                                label: <span className="misa-text-semibold">3. Thông tin mở rộng & Điều khoản</span>
                                            }
                                        ]}
                                    />

                                    {/* Tab 1: Hàng hóa / Dịch vụ */}
                                    {modalActiveTab === 'goods' && (
                                        <div className="misa-grid-container">
                                            <div className="misa-grid-scroll-box-240" style={{ maxHeight: 300 }}>
                                                <Form.List name="lines">
                                                    {(fields, { remove }) => (
                                                        <table className="misa-voucher-table">
                                                            <thead>
                                                                <tr>
                                                                    <th className="misa-col-w-36 misa-text-center">#</th>
                                                                    <th className="misa-col-w-150">Mã hàng</th>
                                                                    <th className="misa-col-min-w-220">Tên hàng</th>
                                                                    <th className="misa-col-w-65">ĐVT</th>
                                                                    <th className="misa-col-w-95 misa-text-right">Số lượng yêu cầu</th>
                                                                    <th className="misa-col-w-95 misa-text-right">Số lượng đã giao</th>
                                                                    <th className="misa-col-w-120 misa-text-right">Đơn giá</th>
                                                                    <th className="misa-col-w-130 misa-text-right">Thành tiền</th>
                                                                    <th className="misa-col-w-80 misa-text-right">Tỷ lệ CK (%)</th>
                                                                    <th className="misa-col-w-110 misa-text-right">Tiền chiết khấu</th>
                                                                    <th className="misa-col-w-85 misa-text-right">% Thuế GTGT</th>
                                                                    <th className="misa-col-w-110 misa-text-right">Tiền thuế GTGT</th>
                                                                    <th className="misa-col-w-140 misa-text-right">Tổng tiền thanh toán</th>
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
                                                                                    options={itemList.map((it: any) => ({
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
                                                                            <Form.Item name={[field.name, 'quantity_requested']} noStyle initialValue={1}>
                                                                                <InputNumber 
                                                                                    variant="borderless" 
                                                                                    className="misa-w-full misa-text-right" 
                                                                                    onChange={val => {
                                                                                        const cur = form.getFieldValue('lines') || [];
                                                                                        const q = Number(val) || 0;
                                                                                        const p = Number(cur[index]?.unit_price) || 0;
                                                                                        const amt = q * p;
                                                                                        const discRate = Number(cur[index]?.discount_rate) || 0;
                                                                                        const discAmt = amt * (discRate / 100);
                                                                                        const tr = typeof cur[index]?.tax_rate === 'number' ? cur[index]?.tax_rate : 10;
                                                                                        const taxAmt = (amt - discAmt) * (tr / 100);
                                                                                        cur[index].amount = amt;
                                                                                        cur[index].discount_amount = discAmt;
                                                                                        cur[index].tax_amount = taxAmt;
                                                                                        cur[index].total_amount = amt - discAmt + taxAmt;
                                                                                        form.setFieldsValue({ lines: [...cur] });
                                                                                    }}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td className="misa-text-right">
                                                                            <Form.Item name={[field.name, 'quantity_delivered']} noStyle initialValue={0}>
                                                                                <InputNumber variant="borderless" className="misa-w-full misa-text-right" />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'unit_price']} noStyle initialValue={0}>
                                                                                <InputNumber 
                                                                                    variant="borderless" 
                                                                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                                    className="misa-w-full misa-text-right" 
                                                                                    onChange={val => {
                                                                                        const cur = form.getFieldValue('lines') || [];
                                                                                        const p = Number(val) || 0;
                                                                                        const q = Number(cur[index]?.quantity_requested) || 0;
                                                                                        const amt = q * p;
                                                                                        const discRate = Number(cur[index]?.discount_rate) || 0;
                                                                                        const discAmt = amt * (discRate / 100);
                                                                                        const tr = typeof cur[index]?.tax_rate === 'number' ? cur[index]?.tax_rate : 10;
                                                                                        const taxAmt = (amt - discAmt) * (tr / 100);
                                                                                        cur[index].amount = amt;
                                                                                        cur[index].discount_amount = discAmt;
                                                                                        cur[index].tax_amount = taxAmt;
                                                                                        cur[index].total_amount = amt - discAmt + taxAmt;
                                                                                        form.setFieldsValue({ lines: [...cur] });
                                                                                    }}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td className="misa-text-right misa-text-semibold">
                                                                            {new Intl.NumberFormat('vi-VN').format(formLines[index]?.amount || 0)}
                                                                        </td>
                                                                        <td>
                                                                            <Form.Item name={[field.name, 'discount_rate']} noStyle initialValue={0}>
                                                                                <InputNumber 
                                                                                    variant="borderless" 
                                                                                    min={0} 
                                                                                    max={100}
                                                                                    className="misa-w-full misa-text-right"
                                                                                    onChange={val => {
                                                                                        const cur = form.getFieldValue('lines') || [];
                                                                                        const amt = Number(cur[index]?.amount) || 0;
                                                                                        const discRate = Number(val) || 0;
                                                                                        const discAmt = amt * (discRate / 100);
                                                                                        const tr = typeof cur[index]?.tax_rate === 'number' ? cur[index]?.tax_rate : 10;
                                                                                        const taxAmt = (amt - discAmt) * (tr / 100);
                                                                                        cur[index].discount_amount = discAmt;
                                                                                        cur[index].tax_amount = taxAmt;
                                                                                        cur[index].total_amount = amt - discAmt + taxAmt;
                                                                                        form.setFieldsValue({ lines: [...cur] });
                                                                                    }}
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td className="misa-text-right">
                                                                            <Form.Item name={[field.name, 'discount_amount']} noStyle initialValue={0}>
                                                                                <InputNumber 
                                                                                    variant="borderless" 
                                                                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                                    className="misa-w-full misa-text-right" 
                                                                                />
                                                                            </Form.Item>
                                                                        </td>
                                                                        <td className="misa-text-right">
                                                                            <Form.Item name={[field.name, 'tax_rate']} noStyle initialValue={10}>
                                                                                <Select 
                                                                                    variant="borderless" 
                                                                                    className="misa-w-full"
                                                                                    onChange={val => {
                                                                                        const cur = form.getFieldValue('lines') || [];
                                                                                        const amt = Number(cur[index]?.amount) || 0;
                                                                                        const discAmt = Number(cur[index]?.discount_amount) || 0;
                                                                                        const tr = typeof val === 'number' ? val : 0;
                                                                                        const taxAmt = (amt - discAmt) * (tr / 100);
                                                                                        cur[index].tax_amount = taxAmt;
                                                                                        cur[index].total_amount = amt - discAmt + taxAmt;
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
                                                                        <td className="misa-text-right misa-text-semibold">
                                                                            {new Intl.NumberFormat('vi-VN').format(formLines[index]?.tax_amount || 0)}
                                                                        </td>
                                                                        <td className="misa-text-right misa-text-bold">
                                                                            {new Intl.NumberFormat('vi-VN').format(formLines[index]?.total_amount || 0)}
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
                                                                    <td className="misa-text-right misa-text-semibold">{totalQty}</td>
                                                                    <td className="misa-text-right misa-text-semibold">{totalDeliveredQty}</td>
                                                                    <td></td>
                                                                    <td className="misa-text-right misa-table-summary-total">{new Intl.NumberFormat('vi-VN').format(totalAmount)}</td>
                                                                    <td></td>
                                                                    <td className="misa-text-right misa-text-semibold">{new Intl.NumberFormat('vi-VN').format(totalDiscount)}</td>
                                                                    <td></td>
                                                                    <td className="misa-text-right misa-text-semibold">{new Intl.NumberFormat('vi-VN').format(totalTax)}</td>
                                                                    <td className="misa-text-right misa-table-summary-primary">{new Intl.NumberFormat('vi-VN').format(grandTotalAmount)}</td>
                                                                    <td></td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    )}
                                                </Form.List>
                                            </div>

                                            {/* Sub-toolbar buttons */}
                                            <div className="misa-grid-footer-bar">
                                                <div className="misa-flex-center misa-gap-16">
                                                    <span className="misa-cell-sub-title">Tổng số: <strong>{formLines.length}</strong> dòng</span>
                                                    <button 
                                                        type="button" 
                                                        className="misa-btn-tool"
                                                        onClick={() => {
                                                            const cur = form.getFieldValue('lines') || [];
                                                            form.setFieldsValue({
                                                                lines: [
                                                                    ...cur,
                                                                    {
                                                                        item_id: undefined,
                                                                        item_code: undefined,
                                                                        item_name: undefined,
                                                                        unit: undefined,
                                                                        quantity_requested: undefined,
                                                                        quantity_delivered: 0,
                                                                        unit_price: undefined,
                                                                        amount: undefined,
                                                                        discount_rate: 0,
                                                                        discount_amount: 0,
                                                                        tax_rate: 10,
                                                                        tax_amount: 0,
                                                                        total_amount: 0
                                                                    }
                                                                ]
                                                            });
                                                        }}
                                                    >
                                                        <PlusOutlined className="misa-icon-green" /> <span>Thêm dòng (F7)</span>
                                                    </button>
                                                    <button 
                                                        type="button" 
                                                        className="misa-btn-tool"
                                                        onClick={() => {
                                                            const cur = form.getFieldValue('lines') || [];
                                                            form.setFieldsValue({
                                                                lines: [
                                                                    ...cur,
                                                                    { 
                                                                        item_code: '',
                                                                        item_name: '--- Ghi chú bổ sung ---',
                                                                        unit: '',
                                                                        quantity_requested: 0,
                                                                        unit_price: 0,
                                                                        amount: 0
                                                                    }
                                                                ]
                                                            });
                                                        }}
                                                    >
                                                        <span>Thêm ghi chú</span>
                                                    </button>
                                                    <button 
                                                        type="button" 
                                                        className="misa-btn-tool misa-text-danger" 
                                                        onClick={() => form.setFieldsValue({ lines: [] })}
                                                    >
                                                        <span>Xóa hết dòng</span>
                                                    </button>
                                                </div>

                                                <button type="button" className="misa-btn-tool misa-text-blue" onClick={() => setModalActiveTab('extra')}>
                                                    <PaperClipOutlined /> Đính kèm tệp
                                                </button>
                                            </div>

                                            {/* Financial Summary Card */}
                                            <div className="misa-flex-end misa-top-12">
                                                <div className="misa-summary-financial-card" style={{ width: 340 }}>
                                                    <div className="misa-summary-row-line">
                                                        <span>Tổng tiền hàng:</span>
                                                        <span className="misa-table-amount-dark">{new Intl.NumberFormat('vi-VN').format(totalAmount)} ₫</span>
                                                    </div>
                                                    <div className="misa-summary-row-line">
                                                        <span>Tiền chiết khấu:</span>
                                                        <span className="misa-table-amount-dark">{new Intl.NumberFormat('vi-VN').format(totalDiscount)} ₫</span>
                                                    </div>
                                                    <div className="misa-summary-row-line">
                                                        <span>Thuế GTGT:</span>
                                                        <span className="misa-table-amount-dark">{new Intl.NumberFormat('vi-VN').format(totalTax)} ₫</span>
                                                    </div>
                                                    <div className="misa-summary-grand-total">
                                                        <span className="misa-field-label misa-text-bold">TỔNG TIỀN THANH TOÁN:</span>
                                                        <span className="misa-summary-grand-value-green">
                                                            {new Intl.NumberFormat('vi-VN').format(grandTotalAmount)} ₫
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Tab 2: Tiến độ thanh toán */}
                                    {modalActiveTab === 'schedule' && (
                                        <div className="misa-flex-col misa-gap-10">
                                            <div className="misa-flex-center misa-gap-12 misa-mb-12">
                                                <div className="misa-w-280">
                                                    <div className="misa-field-label">Điều khoản thanh toán ngầm định</div>
                                                    <div className="misa-input-group">
                                                        <Form.Item name="payment_terms" noStyle initialValue="ĐKTT30">
                                                            <Select 
                                                                variant="borderless" 
                                                                className="misa-w-full"
                                                                options={termList.map((t: any) => ({ value: t.code, label: `${t.code} - ${t.name}` }))}
                                                            />
                                                        </Form.Item>
                                                        <button type="button" className="misa-plus-btn" onClick={() => setIsPaymentTermModalVisible(true)}>
                                                            <PlusOutlined className="misa-btn-plus-icon-sm" />
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Installments Table */}
                                            <Form.List name="payments">
                                                {(fields, { add, remove }) => {
                                                    const pmFields = form.getFieldValue('payments') || [];
                                                    const sumRate = pmFields.reduce((acc: number, p: any) => acc + (Number(p?.payment_rate) || 0), 0);
                                                    const sumAmt = pmFields.reduce((acc: number, p: any) => acc + (Number(p?.payment_amount) || 0), 0);
                                                    const sumPaid = pmFields.reduce((acc: number, p: any) => acc + (Number(p?.paid_amount) || 0), 0);
                                                    const sumPrevPaid = pmFields.reduce((acc: number, p: any) => acc + (Number(p?.prev_year_paid) || 0), 0);
                                                    const sumRemain = sumAmt - sumPaid;

                                                    return (
                                                        <>
                                                            <table className="misa-voucher-table">
                                                                <thead>
                                                                    <tr>
                                                                        <th className="misa-col-w-36 misa-text-center">#</th>
                                                                        <th className="misa-col-w-140">Đợt thanh toán</th>
                                                                        <th className="misa-col-w-120 misa-text-right">Tỷ lệ TT (%)</th>
                                                                        <th className="misa-col-w-140 misa-text-right">Giá trị thanh toán</th>
                                                                        <th className="misa-col-w-120 misa-text-center">Hạn thanh toán</th>
                                                                        <th className="misa-col-w-120 misa-text-center">Ngày thanh toán</th>
                                                                        <th className="misa-col-w-120 misa-text-right">Số đã trả</th>
                                                                        <th className="misa-col-w-130 misa-text-right">Số đã trả năm trước</th>
                                                                        <th className="misa-col-w-130 misa-text-right">Số còn phải trả</th>
                                                                        <th>Ghi chú</th>
                                                                        <th className="misa-col-w-120">Chứng từ trả tiền</th>
                                                                        <th className="misa-col-w-36 misa-text-center"></th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    {fields.map((field, idx) => (
                                                                        <tr key={field.key}>
                                                                            <td className="misa-text-center">{idx + 1}</td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'stage_name']} noStyle initialValue={`Đợt ${idx + 1}`}>
                                                                                    <input className="misa-table-input" />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'payment_rate']} noStyle initialValue={50}>
                                                                                    <InputNumber 
                                                                                        variant="borderless" 
                                                                                        className="misa-w-full misa-text-right" 
                                                                                        onChange={val => {
                                                                                            const cur = form.getFieldValue('payments') || [];
                                                                                            const rate = Number(val) || 0;
                                                                                            cur[idx].payment_amount = (grandTotalAmount * rate) / 100;
                                                                                            form.setFieldsValue({ payments: [...cur] });
                                                                                        }}
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'payment_amount']} noStyle initialValue={0}>
                                                                                    <InputNumber 
                                                                                        variant="borderless" 
                                                                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                                        className="misa-w-full misa-text-right misa-text-semibold" 
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td className="misa-text-center">
                                                                                <Form.Item name={[field.name, 'due_date']} noStyle>
                                                                                    <DatePicker variant="borderless" format="DD/MM/YYYY" className="misa-w-full" />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td className="misa-text-center">
                                                                                <Form.Item name={[field.name, 'payment_date']} noStyle>
                                                                                    <DatePicker variant="borderless" format="DD/MM/YYYY" className="misa-w-full" />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'paid_amount']} noStyle initialValue={0}>
                                                                                    <InputNumber 
                                                                                        variant="borderless" 
                                                                                        disabled={!isPreSoftware}
                                                                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                                        className="misa-w-full misa-text-right" 
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'prev_year_paid']} noStyle initialValue={0}>
                                                                                    <InputNumber 
                                                                                        variant="borderless" 
                                                                                        disabled={!isPreSoftware}
                                                                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                                                        className="misa-w-full misa-text-right" 
                                                                                    />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td className="misa-text-right misa-text-semibold">
                                                                                {new Intl.NumberFormat('vi-VN').format((Number(pmFields[idx]?.payment_amount) || 0) - (Number(pmFields[idx]?.paid_amount) || 0))}
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'notes']} noStyle>
                                                                                    <input className="misa-table-input" placeholder="Ghi chú đợt..." />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td>
                                                                                <Form.Item name={[field.name, 'voucher_ref']} noStyle>
                                                                                    <input className="misa-table-input" placeholder="Số CT chi..." />
                                                                                </Form.Item>
                                                                            </td>
                                                                            <td className="misa-text-center">
                                                                                <button type="button" onClick={() => remove(idx)} className="misa-btn-plain-danger">
                                                                                    <DeleteOutlined />
                                                                                </button>
                                                                            </td>
                                                                        </tr>
                                                                    ))}
                                                                    <tr className="summary-row">
                                                                        <td></td>
                                                                        <td>Tổng</td>
                                                                        <td className="misa-text-right misa-text-semibold">{sumRate}%</td>
                                                                        <td className="misa-text-right misa-table-summary-total">{new Intl.NumberFormat('vi-VN').format(sumAmt)}</td>
                                                                        <td colSpan={2}></td>
                                                                        <td className="misa-text-right misa-text-semibold">{new Intl.NumberFormat('vi-VN').format(sumPaid)}</td>
                                                                        <td className="misa-text-right misa-text-semibold">{new Intl.NumberFormat('vi-VN').format(sumPrevPaid)}</td>
                                                                        <td className="misa-text-right misa-text-semibold">{new Intl.NumberFormat('vi-VN').format(sumRemain)}</td>
                                                                        <td colSpan={3}></td>
                                                                    </tr>
                                                                </tbody>
                                                            </table>
                                                            <div className="misa-flex-center misa-gap-12 misa-top-8">
                                                                <Button 
                                                                    type="dashed" 
                                                                    size="small" 
                                                                    icon={<PlusOutlined />} 
                                                                    onClick={() => add({ stage_name: `Đợt ${fields.length + 1}`, payment_rate: 0, payment_amount: 0, paid_amount: 0, prev_year_paid: 0 })}
                                                                >
                                                                    Thêm dòng
                                                                </Button>
                                                                <Button 
                                                                    type="text" 
                                                                    size="small" 
                                                                    danger
                                                                    onClick={() => form.setFieldsValue({ payments: [] })}
                                                                >
                                                                    Xóa hết dòng
                                                                </Button>
                                                            </div>
                                                        </>
                                                    );
                                                }}
                                            </Form.List>
                                        </div>
                                    )}

                                    {/* Tab 3: Thông tin mở rộng & Điều khoản */}
                                    {modalActiveTab === 'extra' && (
                                        <div className="misa-form-grid">
                                            <div className="misa-col-6">
                                                <div className="misa-field-label">Trích yếu</div>
                                                <Form.Item name="summary" noStyle>
                                                    <Input.TextArea rows={2} className="misa-input" placeholder="Tóm tắt nội dung hợp đồng..." />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-col-6">
                                                <div className="misa-field-label">Địa chỉ giao hàng</div>
                                                <Form.Item name="shipping_address" noStyle>
                                                    <Input.TextArea rows={2} className="misa-input" placeholder="Địa điểm kho/công trình giao nhận..." />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-col-4">
                                                <div className="misa-field-label">Nhân viên mua hàng</div>
                                                <div className="misa-input-group">
                                                    <Form.Item name="employee_id" noStyle>
                                                        <Select 
                                                            showSearch 
                                                            variant="borderless" 
                                                            className="misa-w-full"
                                                            placeholder="Chọn NV"
                                                            options={employeeList.map((e: any) => ({ value: e.id, label: `${e.code} - ${e.name}` }))}
                                                        />
                                                    </Form.Item>
                                                    <button type="button" className="misa-plus-btn" onClick={() => setIsEmployeeModalVisible(true)}>
                                                        <PlusOutlined className="misa-btn-plus-icon-sm" />
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="misa-col-4">
                                                <div className="misa-field-label">Ngày kết thúc</div>
                                                <Form.Item name="end_date" noStyle>
                                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" />
                                                </Form.Item>
                                            </div>

                                            {/* Pre-software contract Checkbox */}
                                            <div className="misa-col-12 misa-top-8">
                                                <Checkbox 
                                                    checked={isPreSoftware} 
                                                    onChange={(e) => setIsPreSoftware(e.target.checked)}
                                                    className="misa-text-semibold"
                                                >
                                                    Là hợp đồng phát sinh trước khi sử dụng phần mềm
                                                </Checkbox>
                                            </div>

                                            <div className="misa-col-4">
                                                <div className="misa-field-label">Giá trị thanh lý</div>
                                                <Form.Item name="liquidation_amount" noStyle initialValue={0}>
                                                    <InputNumber 
                                                        className="misa-input misa-w-full" 
                                                        disabled={!isPreSoftware} 
                                                        formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                    />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-col-4">
                                                <div className="misa-field-label">Ngày thanh lý/hủy bỏ</div>
                                                <Form.Item name="liquidation_date" noStyle>
                                                    <DatePicker className="misa-input misa-w-full" format="DD/MM/YYYY" disabled={!isPreSoftware} />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-col-4">
                                                <div className="misa-field-label">Lý do thanh lý/hủy bỏ</div>
                                                <Form.Item name="liquidation_reason" noStyle>
                                                    <Input className="misa-input" placeholder="" disabled={!isPreSoftware} />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-col-12">
                                                <div className="misa-field-label">Điều khoản khác</div>
                                                <Form.Item name="other_terms" noStyle>
                                                    <Input.TextArea rows={2} className="misa-input" placeholder="Ghi chú điều khoản bảo hành, phạt hợp đồng, cam kết chất lượng..." />
                                                </Form.Item>
                                            </div>

                                            <div className="misa-col-12 misa-top-8">
                                                <div className="misa-dropzone-box">
                                                    <InboxOutlined className="misa-footer-upload-icon misa-icon-muted" />
                                                    <div className="misa-field-label misa-text-bold misa-top-8">
                                                        Đính kèm (Dung lượng tối đa 5MB)
                                                    </div>
                                                    <div className="misa-cell-sub-title">
                                                        Chọn tệp hoặc kéo và thả tệp vào đây
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </Form>
                </ModalFrame>
            </Modal>

            {/* Progress Fulfillment Dialog */}
            {isProgressModalOpen && progressContract && (
                <Modal
                    title={<span className="misa-text-bold">Tình hình thực hiện hợp đồng {progressContract.contract_number}</span>}
                    open={isProgressModalOpen}
                    onCancel={() => setIsProgressModalOpen(false)}
                    footer={[
                        <Button key="close" type="primary" onClick={() => setIsProgressModalOpen(false)}>Đóng</Button>
                    ]}
                    width={700}
                >
                    <div className="misa-flex-col misa-gap-12 misa-top-8">
                        <div className="misa-banner-workflow">
                            <div className="misa-text-bold misa-cell-main-title">{progressContract.contract_name}</div>
                            <div className="misa-cell-sub-title misa-top-8">Nhà cung cấp: {progressContract.supplier_name}</div>
                        </div>

                        <div className="misa-form-grid misa-text-center">
                            <div className="misa-col-4 misa-banner-workflow">
                                <div className="misa-field-label misa-text-bold">GIÁ TRỊ HỢP ĐỒNG</div>
                                <div className="misa-table-summary-total misa-top-8">
                                    {new Intl.NumberFormat('vi-VN').format(progressContract.contract_value || 0)} ₫
                                </div>
                            </div>

                            <div className="misa-col-4 misa-banner-workflow">
                                <div className="misa-field-label misa-text-bold">ĐÃ THANH TOÁN</div>
                                <div className="misa-table-summary-total misa-top-8">
                                    {new Intl.NumberFormat('vi-VN').format(progressContract.paid_amount || 0)} ₫
                                </div>
                            </div>

                            <div className="misa-col-4 misa-banner-workflow">
                                <div className="misa-field-label misa-text-bold">CÒN PHẢI TRẢ</div>
                                <div className="misa-table-summary-total misa-top-8">
                                    {new Intl.NumberFormat('vi-VN').format((Number(progressContract.contract_value) || 0) - (Number(progressContract.paid_amount) || 0))} ₫
                                </div>
                            </div>
                        </div>
                    </div>
                </Modal>
            )}

            {/* Reference Voucher Modal */}
            <ReferenceVoucherModal 
                open={isReferenceModalOpen}
                onCancel={() => setIsReferenceModalOpen(false)}
                onSelect={(selectedVouchers) => {
                    if (selectedVouchers && selectedVouchers.length > 0) {
                        const refs = selectedVouchers.map(v => v.voucher_number).join(', ');
                        form.setFieldsValue({ reference: refs });
                    }
                }}
            />

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
                    form.setFieldsValue({ payment_terms: newTerm.code });
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
            </DataTableSurface>
        </PageShell>
    );
};

export default PurchaseContracts;
