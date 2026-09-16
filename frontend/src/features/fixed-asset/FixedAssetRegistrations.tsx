import React, { useState } from 'react';
import { Table, Button, Form, Input, InputNumber, Space, Select, DatePicker, Tabs, Row, Col } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { MenuProps } from 'antd';
import {
    PlusOutlined,
    ReloadOutlined,
    EditOutlined,
    DeleteOutlined,
    CheckCircleOutlined,
    CloseCircleOutlined,
    CopyOutlined,
    PrinterOutlined,
    DollarOutlined,
    SwapOutlined,
    SearchOutlined,
    SettingOutlined,
    CloseOutlined,
    QuestionCircleOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { formatDate } from '../../utils/dateUtils';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';
import { VoucherPrintModal, MisaMasterCard, MisaTotalCard, QuickAddContactModal, MisaInputGroup } from '../../components/misa';
import { AssetDisposalModal } from './modals/AssetDisposalModal';
import { AssetRevaluationModal } from './modals/AssetRevaluationModal';
import { useVoucherShortcuts } from '../../hooks/useVoucherShortcuts';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';
import { runManualDataLoad } from '../../components/feedback/runManualDataLoad';

const { TabPane } = Tabs;

type FixedAssetRegistrationsProps = { embedded?: boolean };

export const FixedAssetRegistrations: React.FC<FixedAssetRegistrationsProps> = ({ embedded = false }) => {
    const [isModalVisible, setIsModalVisible] = useState(false);
    const [isEditMode, setIsEditMode] = useState(false);
    const [editAssetId, setEditAssetId] = useState<number | null>(null);
    const [selectedAsset, setSelectedAsset] = useState<any>(null);
    const [selectedRowKeys, setSelectedRowKeys] = useState<React.Key[]>([]);

    // Modals
    const [isDisposalModalOpen, setIsDisposalModalOpen] = useState(false);
    const [isRevaluationModalOpen, setIsRevaluationModalOpen] = useState(false);
    const [isPrintModalOpen, setIsPrintModalOpen] = useState(false);
    const [printRecord, setPrintRecord] = useState<any>(null);

    // Quick Add Modals & Options
    const [isSupplierModalVisible, setIsSupplierModalVisible] = useState(false);
    const [isCategoryModalVisible, setIsCategoryModalVisible] = useState(false);
    const [isDepartmentModalVisible, setIsDepartmentModalVisible] = useState(false);

    const [categoryForm] = Form.useForm();
    const [departmentForm] = Form.useForm();

    const [customCategories, setCustomCategories] = useState<{ value: string; label: string }[]>([
        { value: 'MAY_MOC', label: 'Máy móc, thiết bị' },
        { value: 'PHUONG_TIEN', label: 'Phương tiện vận tải' },
        { value: 'NHA_CUA', label: 'Nhà cửa, vật kiến trúc' },
        { value: 'THIET_BI_VP', label: 'Thiết bị, dụng cụ quản lý' },
        { value: 'VO_HINH', label: 'Tài sản cố định vô hình' },
        { value: 'KHAC', label: 'Tài sản cố định khác' },
    ]);

    const [customDepartments, setCustomDepartments] = useState<{ value: string; label: string }[]>([
        { value: 'QLDN', label: 'Bộ phận Quản lý doanh nghiệp (TK 6424)' },
        { value: 'BAN_HANG', label: 'Bộ phận Bán hàng (TK 6414)' },
        { value: 'SAN_XUAT', label: 'Bộ phận Phân xưởng / Sản xuất (TK 154)' },
        { value: 'VAN_PHONG', label: 'Bộ phận Văn phòng (TK 642)' },
        { value: 'KHO_VAN', label: 'Bộ phận Kho vận / Logistics' },
    ]);

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    // Filters
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<'all' | 'posted' | 'draft' | 'disposed'>('all');

    const { data: assets = [], isLoading } = useQuery<any[]>({
        queryKey: ['fixed-assets'],
        queryFn: async () => {
            const { data } = await api.get('/fixed-assets');
            return Array.isArray(data) ? data : (data?.data || []);
        },
    });

    const filteredAssets = React.useMemo(() => {
        return assets.filter((asset: any) => {
            if (searchText.trim()) {
                const q = searchText.toLowerCase();
                const matchCode = (asset.asset_code || '').toLowerCase().includes(q);
                const matchName = (asset.asset_name || '').toLowerCase().includes(q);
                const matchVoucher = (asset.voucher_number || '').toLowerCase().includes(q);
                const matchDept = (asset.department_code || '').toLowerCase().includes(q);
                if (!matchCode && !matchName && !matchVoucher && !matchDept) return false;
            }
            if (statusFilter === 'posted') {
                if (!asset.is_posted) return false;
            } else if (statusFilter === 'draft') {
                if (asset.is_posted) return false;
            } else if (statusFilter === 'disposed') {
                if (asset.is_active !== false && asset.status !== 'disposed') return false;
            }
            return true;
        });
    }, [assets, searchText, statusFilter]);

    const { data: chartOfAccounts = [] } = useQuery<any[]>({
        queryKey: ['chart-of-accounts'],
        queryFn: async () => {
            const { data } = await api.get('/master/accounts');
            return Array.isArray(data) ? data : (data?.data || []);
        },
    });

    const { data: suppliers = [] } = useQuery<any[]>({
        queryKey: ['suppliers-list'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return Array.isArray(data) ? data : (data?.data || []);
        },
    });

    // Auto-select first row
    React.useEffect(() => {
        if (assets.length > 0 && !selectedAsset) {
            setSelectedAsset(assets[0]);
            setSelectedRowKeys([assets[0].id]);
        }
    }, [assets, selectedAsset]);

    // Fetch full details of selected asset (including depreciation lines & references)
    const { data: selectedAssetDetail } = useQuery<any>({
        queryKey: ['fixed-asset-detail', selectedAsset?.id],
        queryFn: async () => {
            if (!selectedAsset?.id) return null;
            const { data } = await api.get(`/fixed-assets/${selectedAsset.id}`);
            return data;
        },
        enabled: !!selectedAsset?.id,
    });

    const createOrUpdateMutation = useMutation({
        mutationFn: async (values: any) => {
            const payload = {
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date.format('YYYY-MM-DD'),
                asset_code: values.asset_code,
                asset_name: values.asset_name,
                category_code: values.category_code,
                department_code: values.department_code,
                quantity: values.quantity,
                supplier_id: values.supplier_id,
                purchase_date: values.purchase_date.format('YYYY-MM-DD'),
                start_depreciation_date: values.start_depreciation_date.format('YYYY-MM-DD'),
                original_cost: values.original_cost,
                depreciable_cost: values.depreciable_cost,
                useful_life_months: values.useful_life_months,
                accumulated_depreciation: values.accumulated_depreciation || 0,
                asset_account: values.asset_account,
                depreciation_account: values.depreciation_account,
                expense_account: values.expense_account,
                credit_account: values.credit_account,
                // Capture as draft until an explicit, owner-approved posting
                // mapping is available and the user invokes the post action.
                is_posted: values.is_posted ?? false,
            };

            if (isEditMode && editAssetId) {
                return api.put(`/fixed-assets/${editAssetId}`, payload);
            } else {
                return api.post('/fixed-assets', payload);
            }
        },
        onSuccess: (response: any) => {
            const persistedAsset = response?.data;
            if (!persistedAsset || persistedAsset.id === undefined || persistedAsset.id === null) {
                message.error('Máy chủ không trả về TSCĐ đã lưu; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success(isEditMode ? 'Cập nhật TSCĐ thành công!' : 'Ghi tăng TSCĐ thành công!');
            setIsModalVisible(false);
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-asset-detail'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || err.response?.data?.error || 'Có lỗi xảy ra!');
        }
    });

    const postMutation = useMutation({
        mutationFn: async (id: number) => api.post(`/fixed-assets/${id}/post`),
        onSuccess: (response: any) => {
            const persistedAsset = response?.data?.data;
            if (!persistedAsset || persistedAsset.id === undefined || persistedAsset.id === null) {
                message.error('Máy chủ không trả về TSCĐ đã ghi sổ; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Ghi sổ TSCĐ thành công!');
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-asset-detail'] });
        },
        onError: (err: any) => message.error(err?.response?.data?.message || 'Lỗi khi ghi sổ!')
    });

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => api.post(`/fixed-assets/${id}/unpost`),
        onSuccess: (response: any) => {
            const persistedAsset = response?.data?.data;
            if (!persistedAsset || persistedAsset.id === undefined || persistedAsset.id === null) {
                message.error('Máy chủ không trả về TSCĐ đã bỏ ghi sổ; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Bỏ ghi sổ TSCĐ thành công!');
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
            queryClient.invalidateQueries({ queryKey: ['fixed-asset-detail'] });
        },
        onError: (err: any) => message.error(err?.response?.data?.message || 'Lỗi khi bỏ ghi sổ!')
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => api.post(`/fixed-assets/${id}/duplicate`),
        onSuccess: (response: any) => {
            const persistedAsset = response?.data?.data;
            if (!persistedAsset || persistedAsset.id === undefined || persistedAsset.id === null) {
                message.error('Máy chủ không trả về TSCĐ đã nhân bản; không thể xác nhận thao tác thành công.');
                return;
            }

            message.success('Nhân bản TSCĐ thành công!');
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
        },
        onError: (err: any) => message.error(err?.response?.data?.message || 'Lỗi khi nhân bản!')
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => api.delete(`/fixed-assets/${id}`),
        onSuccess: (response: any) => {
            if (!response?.data?.message) {
                message.error('Máy chủ không xác nhận đã xóa TSCĐ; không thể báo thành công.');
                return;
            }

            message.success('Đã xóa TSCĐ thành công!');
            setSelectedAsset(null);
            queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
        },
        onError: (err: any) => message.error(err?.response?.data?.message || 'Lỗi khi xóa TSCĐ!')
    });

    const handleOpenCreateModal = React.useCallback(() => {
        setIsEditMode(false);
        setEditAssetId(null);
        form.resetFields();
        const now = dayjs();
        form.setFieldsValue({
            voucher_date: now,
            purchase_date: now,
            start_depreciation_date: now,
            quantity: undefined,
            original_cost: undefined,
            depreciable_cost: undefined,
            accumulated_depreciation: 0,
            useful_life_months: undefined,
            asset_account: undefined,
            depreciation_account: undefined,
            expense_account: undefined,
            credit_account: undefined,
            is_posted: false,
        });

        // Lấy mã tiếp theo
        api.get('/fixed-assets/next-code?type=TS').then(({ data }) => {
            if (data?.code) form.setFieldsValue({ asset_code: data.code });
        }).catch(() => {});

        api.get('/fixed-assets/next-code?type=TSCD').then(({ data }) => {
            if (data?.code) form.setFieldsValue({ voucher_number: data.code });
        }).catch(() => {});

        setIsModalVisible(true);
    }, [form]);

    const handleExportExcel = React.useCallback(() => {
        if (!filteredAssets || filteredAssets.length === 0) {
            message.warning('Không có dữ liệu để xuất khẩu.');
            return;
        }
        const headers = ['Mã TSCĐ', 'Tên TSCĐ', 'Bộ phận', 'Ngày ghi tăng', 'Nguyên giá', 'Hao mòn lũy kế', 'Giá trị còn lại', 'Trạng thái'];
        const rows = filteredAssets.map((a: any) => [
            `"${a.asset_code || ''}"`,
            `"${(a.asset_name || '').replace(/"/g, '""')}"`,
            `"${a.department_code || ''}"`,
            `"${a.purchase_date ? dayjs(a.purchase_date).format('DD/MM/YYYY') : ''}"`,
            Number(a.original_cost || 0),
            Number(a.accumulated_depreciation || 0),
            Number(a.net_value || 0),
            `"${a.status === 'disposed' ? 'Đã thanh lý' : (a.is_posted ? 'Đã ghi sổ' : 'Chưa ghi sổ')}"`
        ]);
        const csvContent = '\uFEFF' + [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', `HoSo_TSCD_${dayjs().format('YYYYMMDD')}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        message.success('Đã xuất danh sách hồ sơ TSCĐ sang Excel thành công!');
    }, [filteredAssets]);

    React.useEffect(() => {
        const handleOpen = () => handleOpenCreateModal();
        const handleFilter = (e: any) => {
            if (e.detail?.searchText !== undefined) setSearchText(e.detail.searchText);
            if (e.detail?.statusFilter !== undefined) {
                if (e.detail.statusFilter === 'all' || e.detail.statusFilter === 'posted' || e.detail.statusFilter === 'draft' || e.detail.statusFilter === 'disposed') {
                    setStatusFilter(e.detail.statusFilter);
                }
            }
        };
        const handleExport = (e: any) => {
            if (e.detail?.activeTabKey === 'tab-registrations') {
                handleExportExcel();
            }
        };

        window.addEventListener('open-fixed-asset-registration', handleOpen);
        window.addEventListener('fixed-asset-filter-change', handleFilter);
        window.addEventListener('fixed-asset-export', handleExport);
        return () => {
            window.removeEventListener('open-fixed-asset-registration', handleOpen);
            window.removeEventListener('fixed-asset-filter-change', handleFilter);
            window.removeEventListener('fixed-asset-export', handleExport);
        };
    }, [handleOpenCreateModal, handleExportExcel]);

    const handleOpenEditModal = (asset: any) => {
        setIsEditMode(true);
        setEditAssetId(asset.id);
        form.resetFields();
        form.setFieldsValue({
            voucher_number: asset.voucher_number,
            voucher_date: asset.voucher_date ? dayjs(asset.voucher_date) : dayjs(asset.purchase_date),
            purchase_date: dayjs(asset.purchase_date),
            start_depreciation_date: asset.start_depreciation_date ? dayjs(asset.start_depreciation_date) : dayjs(asset.purchase_date),
            asset_code: asset.asset_code,
            asset_name: asset.asset_name,
            category_code: asset.category_code,
            department_code: asset.department_code,
            quantity: asset.quantity,
            supplier_id: asset.supplier_id,
            original_cost: Number(asset.original_cost) || 0,
            depreciable_cost: Number(asset.depreciable_cost) || Number(asset.original_cost) || 0,
            useful_life_months: asset.useful_life_months || 0,
            accumulated_depreciation: Number(asset.accumulated_depreciation) || 0,
            asset_account: asset.asset_account,
            depreciation_account: asset.depreciation_account,
            expense_account: asset.expense_account,
            credit_account: asset.credit_account,
            is_posted: asset.is_posted,
        });
        setIsModalVisible(true);
    };

    const handleFormSubmit = () => {
        form.submit();
    };

    useVoucherShortcuts({
        onSave: handleFormSubmit,
        onPost: handleFormSubmit,
        onClose: () => setIsModalVisible(false),
        enabled: isModalVisible,
    });

    const originalCost = Form.useWatch('original_cost', form) || 0;
    const accumulatedDepreciation = Form.useWatch('accumulated_depreciation', form) || 0;
    const netBookValue = Math.max(0, Number(originalCost || 0) - Number(accumulatedDepreciation || 0));

    const masterColumns = [
        {
            title: 'Ngày ghi tăng',
            dataIndex: 'purchase_date',
            key: 'purchase_date',
            width: 110,
            render: (val: any) => formatDate(val) || '—',
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 140,
            render: (text: string) => <span className="font-semibold text-blue-700">{text}</span>
        },
        {
            title: 'Mã tài sản',
            dataIndex: 'asset_code',
            key: 'asset_code',
            width: 110,
        },
        {
            title: 'Tên tài sản',
            dataIndex: 'asset_name',
            key: 'asset_name',
            ellipsis: true,
        },
        {
            title: 'Bộ phận',
            dataIndex: 'department_code',
            key: 'department_code',
            width: 100,
        },
        {
            title: 'Nguyên giá',
            dataIndex: 'original_cost',
            key: 'original_cost',
            align: 'right' as const,
            render: (val: number) => <span className="font-semibold text-slate-800">{Number(val || 0).toLocaleString('vi-VN')} đ</span>
        },
        {
            title: 'Hao mòn lũy kế',
            dataIndex: 'accumulated_depreciation',
            key: 'accumulated_depreciation',
            align: 'right' as const,
            render: (val: number) => <span className="text-orange-600">{Number(val || 0).toLocaleString('vi-VN')} đ</span>
        },
        {
            title: 'Giá trị còn lại',
            dataIndex: 'net_value',
            key: 'net_value',
            align: 'right' as const,
            render: (val: number) => <strong className="text-emerald-700">{Number(val || 0).toLocaleString('vi-VN')} đ</strong>
        },
        {
            title: 'Trạng thái',
            key: 'status',
            width: 120,
            align: 'center' as const,
            render: (_: any, record: any) => <VoucherStatusBadge status={record} />
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 140,
            align: 'center' as const,
            render: (_: any, record: any) => {
                const isDisposed = record.status === 'disposed' || !record.is_active;
                const menuItems: MenuProps['items'] = [
                    {
                        key: 'view',
                        icon: <EditOutlined />,
                        label: 'Xem chi tiết',
                        onClick: () => {
                            setSelectedAsset(record);
                            setSelectedRowKeys([record.id]);
                        },
                    },
                    {
                        key: 'edit',
                        icon: <EditOutlined />,
                        label: 'Sửa thông tin',
                        disabled: isDisposed,
                        onClick: () => handleOpenEditModal(record),
                    },
                    {
                        key: 'duplicate',
                        icon: <CopyOutlined />,
                        label: 'Nhân bản',
                        onClick: () => duplicateMutation.mutate(record.id),
                    },
                    {
                        type: 'divider',
                    },
                    {
                        key: 'revalue',
                        icon: <SwapOutlined />,
                        label: 'Đánh giá lại TSCĐ',
                        disabled: isDisposed,
                        onClick: () => {
                            setSelectedAsset(record);
                            setIsRevaluationModalOpen(true);
                        }
                    },
                    {
                        key: 'dispose',
                        icon: <DollarOutlined />,
                        label: 'Thanh lý / Ghi giảm',
                        disabled: isDisposed,
                        onClick: () => {
                            setSelectedAsset(record);
                            setIsDisposalModalOpen(true);
                        }
                    },
                    {
                        type: 'divider',
                    },
                    {
                        key: 'post',
                        icon: <CheckCircleOutlined />,
                        label: 'Ghi sổ',
                        disabled: record.is_posted || isDisposed,
                        onClick: () => postMutation.mutate(record.id),
                    },
                    {
                        key: 'unpost',
                        icon: <CloseCircleOutlined />,
                        label: 'Bỏ ghi sổ',
                        disabled: !record.is_posted || isDisposed,
                        onClick: () => unpostMutation.mutate(record.id),
                    },
                    {
                        key: 'print',
                        icon: <PrinterOutlined />,
                        label: 'In hồ sơ TSCĐ',
                        onClick: () => {
                            setPrintRecord({
                                ...record,
                                voucher_type_name: 'HỒ SƠ TÀI SẢN CỐ ĐỊNH',
                            });
                            setIsPrintModalOpen(true);
                        }
                    },
                    {
                        key: 'delete',
                        icon: <DeleteOutlined />,
                        danger: true,
                        label: 'Xóa tài sản',
                        disabled: isDisposed,
                        onClick: () => {
                            Modal.confirm({
                                title: `Xác nhận xóa tài sản ${record.asset_code}?`,
                                content: 'Hành động này không thể hoàn tác.',
                                okText: 'Xóa',
                                okType: 'danger',
                                cancelText: 'Hủy',
                                onOk: () => deleteMutation.mutate(record.id),
                            });
                        }
                    }
                ];

                return (
                    <VoucherActionCell
                        primaryActionLabel={record.is_posted ? 'Xem' : 'Sửa'}
                        onPrimaryAction={() => {
                            if (record.is_posted) {
                                setSelectedAsset(record);
                                setSelectedRowKeys([record.id]);
                            } else {
                                handleOpenEditModal(record);
                            }
                        }}
                        menuItems={menuItems}
                    />
                );
            }
        }
    ];

    return (
        <PageShell
            embedded={embedded}
            title={<PageHeader eyebrow="Tài sản cố định" title="Hồ sơ Ghi tăng Tài sản cố định" description={`${assets.length} tài sản trong danh sách.`} />}
            toolbar={
                <PageToolbar
                    filters={
                        <div className="flex items-center gap-2">
                            <Input
                                placeholder="Tìm theo mã, tên, số chứng từ..."
                                prefix={<SearchOutlined className="text-slate-400" />}
                                value={searchText}
                                onChange={(e) => setSearchText(e.target.value)}
                                style={{ width: 240 }}
                                size="small"
                                allowClear
                            />
                            <Select
                                value={statusFilter}
                                onChange={setStatusFilter}
                                size="small"
                                style={{ width: 140 }}
                                options={[
                                    { label: 'Tất cả trạng thái', value: 'all' },
                                    { label: 'Đã ghi sổ', value: 'posted' },
                                    { label: 'Chưa ghi sổ', value: 'draft' },
                                    { label: 'Đã thanh lý', value: 'disposed' },
                                ]}
                            />
                        </div>
                    }
                    actions={
                        <Space>
                            <Button
                                icon={<ReloadOutlined />}
                                onClick={() => void runManualDataLoad(
                                    () => queryClient.invalidateQueries({ queryKey: ['fixed-assets'] }),
                                    { success: 'Tải lại danh sách tài sản thành công.', failure: 'Không thể tải lại danh sách tài sản.' },
                                )}
                            >
                                Nạp lại
                            </Button>
                            <Button
                                type="primary"
                                icon={<PlusOutlined />}
                                onClick={handleOpenCreateModal}
                                className="misa-btn-primary"
                            >
                                Thêm ghi tăng
                            </Button>
                        </Space>
                    }
                />
            }
        >

            {/* Split Pane: Master (Top) / Detail (Bottom) */}
            <DataTableSurface className="fixed-asset-registrations-table-surface">
                <div className="flex-1 flex flex-col p-3 gap-3 overflow-hidden">
                    {/* Master Table Panel */}
                    <div className="flex-1 bg-white rounded-md border border-slate-200 overflow-hidden flex flex-col">
                        <div className="px-3 py-2 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center justify-between gap-2">
                            <span className="text-xs font-bold text-slate-700 uppercase">
                                Danh sách tài sản cố định
                            </span>
                            <div className="flex items-center gap-2">
                                <Input
                                    placeholder="Tìm theo mã, tên, số chứng từ..."
                                    prefix={<SearchOutlined className="text-slate-400" />}
                                    value={searchText}
                                    onChange={(e) => setSearchText(e.target.value)}
                                    style={{ width: 220 }}
                                    size="small"
                                    allowClear
                                />
                                <Select
                                    value={statusFilter}
                                    onChange={setStatusFilter}
                                    size="small"
                                    style={{ width: 130 }}
                                    options={[
                                        { label: 'Tất cả', value: 'all' },
                                        { label: 'Đã ghi sổ', value: 'posted' },
                                        { label: 'Chưa ghi sổ', value: 'draft' },
                                        { label: 'Đã thanh lý', value: 'disposed' },
                                    ]}
                                />
                            </div>
                        </div>
                        <div className="flex-1 overflow-auto">
                            <Table
                                columns={masterColumns}
                                dataSource={filteredAssets}
                                rowKey="id"
                                loading={isLoading}
                                size="small"
                                pagination={{ pageSize: 6, size: 'small' }}
                                rowSelection={{
                                    type: 'radio',
                                    selectedRowKeys,
                                    onChange: (keys, rows) => {
                                        setSelectedRowKeys(keys);
                                        if (rows.length > 0) {
                                            setSelectedAsset(rows[0]);
                                        }
                                    }
                                }}
                                onRow={(record) => ({
                                    onClick: () => {
                                        setSelectedRowKeys([record.id]);
                                        setSelectedAsset(record);
                                    }
                                })}
                            />
                        </div>
                    </div>

                {/* Detail Sub-Tabs Panel */}
                <div className="h-[250px] bg-white rounded-md border border-slate-200 overflow-hidden flex flex-col">
                    <Tabs defaultActiveKey="1" size="small" className="misa-tabs h-full flex flex-col">
                        <TabPane tab="1. Thiết lập hạch toán & khấu hao" key="1">
                            <div className="p-3 overflow-auto">
                                <Row gutter={16} className="text-xs">
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Tài khoản nguyên giá</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border border-slate-200">
                                            {selectedAssetDetail?.asset_account || '—'}
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Tài khoản khấu hao</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border border-slate-200">
                                            {selectedAssetDetail?.depreciation_account || '—'}
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Tài khoản chi phí</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border border-slate-200">
                                            {selectedAssetDetail?.expense_account || '—'}
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Tài khoản đối ứng (Có)</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border border-slate-200">
                                            {selectedAssetDetail?.credit_account || '—'}
                                        </div>
                                    </Col>
                                </Row>

                                <Row gutter={16} className="text-xs mt-3">
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Thời gian sử dụng</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border border-slate-200">
                                            {selectedAssetDetail?.useful_life_months || 0} tháng ({(Number(selectedAssetDetail?.useful_life_months || 0) / 12).toFixed(1)} năm)
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Mức trích KH hàng tháng</div>
                                        <div className="font-semibold text-blue-700 bg-slate-50 p-1.5 rounded border border-slate-200">
                                            {Number(selectedAssetDetail?.monthly_depreciation || 0).toLocaleString('vi-VN')} đ
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Ngày bắt đầu tính KH</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border border-slate-200">
                                            {formatDate(selectedAssetDetail?.start_depreciation_date) || '—'}
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Nhà cung cấp</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border border-slate-200 truncate">
                                            {selectedAssetDetail?.supplier?.name || selectedAssetDetail?.supplier_name || '—'}
                                        </div>
                                    </Col>
                                </Row>
                            </div>
                        </TabPane>

                        <TabPane tab={`2. Lịch sử trích khấu hao (${selectedAssetDetail?.depreciation_lines?.length || 0})`} key="2">
                            <div className="p-2 overflow-auto">
                                <Table
                                    dataSource={selectedAssetDetail?.depreciation_lines || []}
                                    rowKey="id"
                                    size="small"
                                    pagination={false}
                                    columns={[
                                        { title: 'Kỳ trích', dataIndex: ['depreciation_log', 'month'], key: 'month', width: 100 },
                                        { title: 'Số chứng từ KH', dataIndex: ['depreciation_log', 'voucher_number'], key: 'voucher_number', width: 140 },
                                        {
                                            title: 'Ngày hạch toán',
                                            dataIndex: ['depreciation_log', 'voucher_date'],
                                            key: 'voucher_date',
                                            width: 120,
                                            render: (v: any) => formatDate(v) || '—'
                                        },
                                        {
                                            title: 'Số trích kỳ này',
                                            dataIndex: 'monthly_depreciation',
                                            key: 'monthly_depreciation',
                                            align: 'right' as const,
                                            render: (v: number) => <strong className="text-blue-700">{Number(v || 0).toLocaleString('vi-VN')} đ</strong>
                                        },
                                        {
                                            title: 'Hao mòn lũy kế sau trích',
                                            dataIndex: 'accumulated_depreciation_after',
                                            key: 'accumulated_depreciation_after',
                                            align: 'right' as const,
                                            render: (v: number) => Number(v || 0).toLocaleString('vi-VN') + ' đ'
                                        },
                                        {
                                            title: 'Giá trị còn lại sau trích',
                                            dataIndex: 'net_value_after',
                                            key: 'net_value_after',
                                            align: 'right' as const,
                                            render: (v: number) => Number(v || 0).toLocaleString('vi-VN') + ' đ'
                                        },
                                        { title: 'TK Chi phí', dataIndex: 'expense_account', key: 'expense_account', align: 'center' as const, width: 90 },
                                    ]}
                                />
                            </div>
                        </TabPane>

                        <TabPane tab={`3. Chứng từ tham chiếu & Biến động`} key="3">
                            <div className="p-3 text-xs overflow-auto">
                                <Row gutter={16}>
                                    <Col span={12}>
                                        <div className="font-bold text-slate-700 mb-2">Chứng từ Đánh giá lại:</div>
                                        {selectedAssetDetail?.revaluations?.length > 0 ? (
                                            <ul className="list-disc pl-4 space-y-1">
                                                {selectedAssetDetail.revaluations.map((r: any) => (
                                                    <li key={r.id}>
                                                        [{r.voucher_number}] {formatDate(r.voucher_date)}: Nguyên giá mới {Number(r.new_original_cost).toLocaleString('vi-VN')} đ (Lý do: {r.reason})
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <div className="text-gray-400 italic">Chưa có chứng từ đánh giá lại</div>
                                        )}
                                    </Col>
                                    <Col span={12}>
                                        <div className="font-bold text-slate-700 mb-2">Chứng từ Thanh lý / Ghi giảm:</div>
                                        {selectedAssetDetail?.disposals?.length > 0 ? (
                                            <ul className="list-disc pl-4 space-y-1">
                                                {selectedAssetDetail.disposals.map((d: any) => (
                                                    <li key={d.id}>
                                                        [{d.voucher_number}] {formatDate(d.voucher_date)}: Giá bán {Number(d.disposal_price).toLocaleString('vi-VN')} đ ({d.disposal_reason})
                                                    </li>
                                                ))}
                                            </ul>
                                        ) : (
                                            <div className="text-gray-400 italic">Chưa phát sinh thanh lý</div>
                                        )}
                                    </Col>
                                </Row>
                            </div>
                        </TabPane>
                    </Tabs>
                </div>
            </div></DataTableSurface>

            {/* Create/Edit Asset Modal */}
            <Modal
                className="misa-voucher-modal"
                closable={false}
                centered
                destroyOnHidden
                title={
                    <div className="misa-voucher-custom-header">
                        <div className="misa-voucher-header-left">
                            <span className="misa-voucher-title">
                                {isEditMode ? 'Sửa chứng từ Ghi tăng TSCĐ' : 'Chứng từ Ghi tăng TSCĐ'}
                            </span>
                        </div>
                        <div className="misa-voucher-header-right">
                            <button type="button" className="misa-voucher-header-icon-btn" title="Thiết lập">
                                <SettingOutlined />
                            </button>
                            <button
                                type="button"
                                className="misa-voucher-header-icon-btn"
                                title="Đóng (Esc)"
                                onClick={() => setIsModalVisible(false)}
                            >
                                <CloseOutlined />
                            </button>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                width="min(1820px, calc(100vw - 32px))"
                footer={
                    <div className="misa-voucher-fixed-footer">
                        <div className="misa-footer-left">
                            <Button
                                className="misa-btn-footer-cancel"
                                icon={<QuestionCircleOutlined />}
                                onClick={() => message.info('Trợ giúp: Khai báo đầy đủ nguyên giá, ngày ghi tăng và các tài khoản hạch toán theo TT200/TT133.')}
                            >
                                Giúp
                            </Button>
                        </div>
                        <div className="misa-footer-right">
                            <Button onClick={() => setIsModalVisible(false)} className="misa-btn-footer-cancel">
                                Hủy
                            </Button>
                            <Button
                                type="primary"
                                onClick={handleFormSubmit}
                                className="misa-btn-footer-save-add"
                                loading={createOrUpdateMutation.isPending}
                            >
                                Cất
                            </Button>
                        </div>
                    </div>
                }
            >
                <ModalFrame>
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={createOrUpdateMutation.mutate}
                    size="small"
                    className="misa-voucher-form-container"
                    onValuesChange={(changedValues) => {
                        if (changedValues.original_cost !== undefined) {
                            form.setFieldsValue({ depreciable_cost: changedValues.original_cost });
                        }
                    }}
                >
                    <div className="misa-voucher-scroll-body">
                        <MisaMasterCard>
                            <MisaMasterCard.Left>
                                <div className="text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                                    Thông tin tài sản cố định
                                </div>
                                <div className="grid grid-cols-12 gap-x-3 gap-y-3">
                                    <div className="col-span-3">
                                        <Form.Item name="asset_code" label="Mã TSCĐ" rules={[{ required: true }]} className="mb-0">
                                            <Input placeholder="TS00001" className="w-full" />
                                        </Form.Item>
                                    </div>
                                    <div className="col-span-9">
                                        <Form.Item name="asset_name" label="Tên TSCĐ" rules={[{ required: true }]} className="mb-0">
                                            <Input placeholder="Nhập tên tài sản cố định" className="w-full" />
                                        </Form.Item>
                                    </div>
                                    <div className="col-span-4">
                                        <Form.Item name="category_code" label="Loại TSCĐ" className="mb-0">
                                            <MisaInputGroup onPlusClick={() => setIsCategoryModalVisible(true)} plusTitle="Thêm nhanh loại TSCĐ">
                                                <Select allowClear placeholder="Chọn loại tài sản" className="w-full" style={{ width: '100%' }}>
                                                    {customCategories.map(cat => (
                                                        <Select.Option key={cat.value} value={cat.value}>{cat.label}</Select.Option>
                                                    ))}
                                                </Select>
                                            </MisaInputGroup>
                                        </Form.Item>
                                    </div>
                                    <div className="col-span-4">
                                        <Form.Item name="department_code" label="Phòng ban / Bộ phận sử dụng" className="mb-0">
                                            <MisaInputGroup onPlusClick={() => setIsDepartmentModalVisible(true)} plusTitle="Thêm nhanh bộ phận sử dụng">
                                                <Select allowClear placeholder="Chọn bộ phận sử dụng" className="w-full" style={{ width: '100%' }}>
                                                    {customDepartments.map(dep => (
                                                        <Select.Option key={dep.value} value={dep.value}>{dep.label}</Select.Option>
                                                    ))}
                                                </Select>
                                            </MisaInputGroup>
                                        </Form.Item>
                                    </div>
                                    <div className="col-span-4">
                                        <Form.Item name="supplier_id" label="Nhà cung cấp" className="mb-0">
                                            <MisaInputGroup onPlusClick={() => setIsSupplierModalVisible(true)} plusTitle="Thêm nhanh nhà cung cấp">
                                                <Select
                                                    showSearch
                                                    allowClear
                                                    placeholder="Chọn nhà cung cấp"
                                                    optionFilterProp="children"
                                                    className="w-full"
                                                    style={{ width: '100%' }}
                                                >
                                                    {suppliers.map((s: any) => (
                                                        <Select.Option key={s.id} value={s.id}>
                                                            [{s.code || s.supplier_code}] {s.name}
                                                        </Select.Option>
                                                    ))}
                                                </Select>
                                            </MisaInputGroup>
                                        </Form.Item>
                                    </div>
                                </div>
                            </MisaMasterCard.Left>

                            <MisaMasterCard.Right>
                                <MisaMasterCard.MetaRow label="Ngày chứng từ" required>
                                    <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                        <DatePicker className="w-full" format="DD/MM/YYYY" />
                                    </Form.Item>
                                </MisaMasterCard.MetaRow>
                                <MisaMasterCard.MetaRow label="Số chứng từ" required>
                                    <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                        <Input placeholder="TSCD-YYYY-XXXX" className="font-semibold" />
                                    </Form.Item>
                                </MisaMasterCard.MetaRow>
                                <MisaTotalCard
                                    label="TỔNG NGUYÊN GIÁ"
                                    value={Number(originalCost || 0)}
                                />
                            </MisaMasterCard.Right>
                        </MisaMasterCard>

                        <div className="mt-3 bg-white rounded-md border border-slate-200 overflow-hidden">
                            <Tabs type="card" size="small" className="misa-tabs">
                                <TabPane tab="1. Thông tin khấu hao" key="1">
                                    <div className="p-4 grid grid-cols-4 gap-4">
                                        <Form.Item name="purchase_date" label="Ngày ghi tăng" rules={[{ required: true }]}>
                                            <DatePicker className="w-full" style={{ width: '100%' }} format="DD/MM/YYYY" />
                                        </Form.Item>
                                        <Form.Item name="start_depreciation_date" label="Ngày bắt đầu tính KH" rules={[{ required: true }]}>
                                            <DatePicker className="w-full" style={{ width: '100%' }} format="DD/MM/YYYY" />
                                        </Form.Item>
                                        <Form.Item name="useful_life_months" label="Thời gian sử dụng (tháng)" rules={[{ required: true }]}>
                                            <InputNumber className="w-full" style={{ width: '100%' }} min={1} />
                                        </Form.Item>
                                        <Form.Item name="quantity" label="Số lượng" rules={[{ required: true }]}>
                                            <InputNumber className="w-full" style={{ width: '100%' }} min={1} />
                                        </Form.Item>
                                        <Form.Item name="original_cost" label="Nguyên giá" rules={[{ required: true }]}>
                                            <InputNumber
                                                className="w-full"
                                                style={{ width: '100%' }}
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                min={0}
                                            />
                                        </Form.Item>
                                        <Form.Item name="depreciable_cost" label="Giá trị tính KH" rules={[{ required: true }]}>
                                            <InputNumber
                                                className="w-full"
                                                style={{ width: '100%' }}
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                min={0}
                                            />
                                        </Form.Item>
                                        <Form.Item name="accumulated_depreciation" label="Hao mòn lũy kế">
                                            <InputNumber
                                                className="w-full"
                                                style={{ width: '100%' }}
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                min={0}
                                            />
                                        </Form.Item>
                                        <Form.Item label="Giá trị còn lại">
                                            <InputNumber
                                                className="w-full"
                                                style={{ width: '100%' }}
                                                value={netBookValue}
                                                disabled
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            />
                                        </Form.Item>
                                    </div>
                                </TabPane>
                                <TabPane tab="2. Thiết lập hạch toán" key="2">
                                    <div className="p-4 grid grid-cols-4 gap-4">
                                        <Form.Item name="asset_account" label="Tài khoản nguyên giá" rules={[{ required: true }]}>
                                            <Select showSearch className="w-full" style={{ width: '100%' }}>
                                                {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => (
                                                    <Select.Option key={acc.code} value={acc.code}>
                                                        {acc.code} - {acc.name}
                                                    </Select.Option>
                                                ))}
                                            </Select>
                                        </Form.Item>
                                        <Form.Item name="depreciation_account" label="Tài khoản khấu hao" rules={[{ required: true }]}>
                                            <Select showSearch className="w-full" style={{ width: '100%' }}>
                                                {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => (
                                                    <Select.Option key={acc.code} value={acc.code}>
                                                        {acc.code} - {acc.name}
                                                    </Select.Option>
                                                ))}
                                            </Select>
                                        </Form.Item>
                                        <Form.Item name="expense_account" label="Tài khoản chi phí" rules={[{ required: true }]}>
                                            <Select showSearch className="w-full" style={{ width: '100%' }}>
                                                {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => (
                                                    <Select.Option key={acc.code} value={acc.code}>
                                                        {acc.code} - {acc.name}
                                                    </Select.Option>
                                                ))}
                                            </Select>
                                        </Form.Item>
                                        <Form.Item name="credit_account" label="Tài khoản đối ứng (Có)" rules={[{ required: true }]}>
                                            <Select showSearch allowClear className="w-full" style={{ width: '100%' }}>
                                                {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => (
                                                    <Select.Option key={acc.code} value={acc.code}>
                                                        {acc.code} - {acc.name}
                                                    </Select.Option>
                                                ))}
                                            </Select>
                                        </Form.Item>
                                    </div>
                                </TabPane>
                            </Tabs>
                        </div>
                    </div>
                </Form>
                </ModalFrame>
            </Modal>

            {/* Quick Add Supplier Modal */}
            <QuickAddContactModal
                open={isSupplierModalVisible}
                contactType="supplier"
                onCancel={() => setIsSupplierModalVisible(false)}
                onSuccess={(newSupplier: any) => {
                    setIsSupplierModalVisible(false);
                    queryClient.invalidateQueries({ queryKey: ['suppliers-list'] });
                    if (newSupplier?.id) {
                        form.setFieldsValue({ supplier_id: newSupplier.id });
                    }
                    message.success('Thêm nhà cung cấp thành công');
                }}
            />

            {/* Quick Add Category Modal */}
            <Modal
                title="Thêm nhanh loại tài sản cố định"
                open={isCategoryModalVisible}
                onCancel={() => {
                    setIsCategoryModalVisible(false);
                    categoryForm.resetFields();
                }}
                onOk={() => categoryForm.submit()}
                okText="Lưu"
                cancelText="Hủy"
                destroyOnHidden
                centered
                width={480}
            >
                <Form
                    form={categoryForm}
                    layout="vertical"
                    onFinish={(values: { code: string; name: string }) => {
                        const newCat = { value: values.code.trim().toUpperCase(), label: values.name.trim() };
                        setCustomCategories(prev => [...prev, newCat]);
                        form.setFieldsValue({ category_code: newCat.value });
                        setIsCategoryModalVisible(false);
                        categoryForm.resetFields();
                        message.success(`Đã thêm loại TSCĐ: ${newCat.label}`);
                    }}
                >
                    <Form.Item
                        name="code"
                        label="Mã loại TSCĐ"
                        rules={[{ required: true, message: 'Vui lòng nhập mã loại TSCĐ' }]}
                    >
                        <Input placeholder="Ví dụ: DAY_CHUYEN" />
                    </Form.Item>
                    <Form.Item
                        name="name"
                        label="Tên loại TSCĐ"
                        rules={[{ required: true, message: 'Vui lòng nhập tên loại TSCĐ' }]}
                    >
                        <Input placeholder="Ví dụ: Dây chuyền sản xuất" />
                    </Form.Item>
                </Form>
            </Modal>

            {/* Quick Add Department Modal */}
            <Modal
                title="Thêm nhanh bộ phận sử dụng"
                open={isDepartmentModalVisible}
                onCancel={() => {
                    setIsDepartmentModalVisible(false);
                    departmentForm.resetFields();
                }}
                onOk={() => departmentForm.submit()}
                okText="Lưu"
                cancelText="Hủy"
                destroyOnHidden
                centered
                width={480}
            >
                <Form
                    form={departmentForm}
                    layout="vertical"
                    onFinish={(values: { code: string; name: string }) => {
                        const newDept = { value: values.code.trim().toUpperCase(), label: values.name.trim() };
                        setCustomDepartments(prev => [...prev, newDept]);
                        form.setFieldsValue({ department_code: newDept.value });
                        setIsDepartmentModalVisible(false);
                        departmentForm.resetFields();
                        message.success(`Đã thêm bộ phận: ${newDept.label}`);
                    }}
                >
                    <Form.Item
                        name="code"
                        label="Mã bộ phận"
                        rules={[{ required: true, message: 'Vui lòng nhập mã bộ phận' }]}
                    >
                        <Input placeholder="Ví dụ: BOPHAN_IT" />
                    </Form.Item>
                    <Form.Item
                        name="name"
                        label="Tên bộ phận"
                        rules={[{ required: true, message: 'Vui lòng nhập tên bộ phận' }]}
                    >
                        <Input placeholder="Ví dụ: Phòng Công nghệ thông tin" />
                    </Form.Item>
                </Form>
            </Modal>

            {/* Disposal Modal */}
            <AssetDisposalModal
                open={isDisposalModalOpen}
                onCancel={() => setIsDisposalModalOpen(false)}
                selectedAsset={selectedAsset}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
                    queryClient.invalidateQueries({ queryKey: ['fixed-asset-detail'] });
                }}
            />

            {/* Revaluation Modal */}
            <AssetRevaluationModal
                open={isRevaluationModalOpen}
                onCancel={() => setIsRevaluationModalOpen(false)}
                selectedAsset={selectedAsset}
                onSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['fixed-assets'] });
                    queryClient.invalidateQueries({ queryKey: ['fixed-asset-detail'] });
                }}
            />

            {/* Print Modal */}
            <VoucherPrintModal
                open={isPrintModalOpen}
                onClose={() => setIsPrintModalOpen(false)}
                data={printRecord}
            />
        </PageShell>
    );
};

export default FixedAssetRegistrations;
