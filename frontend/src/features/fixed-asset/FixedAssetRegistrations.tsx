import React, { useState } from 'react';
import { Table, Button, Form, Input, InputNumber, Space, Select, DatePicker, Tabs, Tag, Dropdown, Row, Col } from 'antd';
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
    MoreOutlined,
    PrinterOutlined,
    DollarOutlined,
    SwapOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { VoucherPrintModal } from '../../components/misa';
import { AssetDisposalModal } from './modals/AssetDisposalModal';
import { AssetRevaluationModal } from './modals/AssetRevaluationModal';
import { useVoucherShortcuts } from '../../hooks/useVoucherShortcuts';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import ModalFrame from '../../components/layout/ModalFrame';

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

    const [form] = Form.useForm();
    const queryClient = useQueryClient();

    const { data: assets = [], isLoading } = useQuery<any[]>({
        queryKey: ['fixed-assets'],
        queryFn: async () => {
            const { data } = await api.get('/fixed-assets');
            return Array.isArray(data) ? data : (data?.data || []);
        },
    });

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

    const handleOpenCreateModal = () => {
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
    };

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

    const masterColumns = [
        {
            title: 'Ngày ghi tăng',
            dataIndex: 'purchase_date',
            key: 'purchase_date',
            width: 110,
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
            dataIndex: 'status',
            key: 'status',
            width: 120,
            align: 'center' as const,
            render: (status: string, record: any) => {
                if (status === 'disposed' || !record.is_active) {
                    return <Tag color="default">Đã ghi giảm</Tag>;
                }
                if (record.is_posted) {
                    return <Tag color="success">Đã ghi sổ</Tag>;
                }
                return <Tag color="warning">Bản nháp</Tag>;
            }
        },
        {
            title: 'Chức năng',
            key: 'action',
            width: 130,
            align: 'center' as const,
            render: (_: any, record: any) => {
                const isDisposed = record.status === 'disposed' || !record.is_active;
                const menuItems: MenuProps['items'] = [
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
                    <Space size="small">
                        <Button
                            type="link"
                            size="small"
                            onClick={() => {
                                setSelectedAsset(record);
                                setSelectedRowKeys([record.id]);
                            }}
                        >
                            Xem
                        </Button>
                        <Dropdown menu={{ items: menuItems }} trigger={['click']}>
                            <Button type="text" size="small" icon={<MoreOutlined />} />
                        </Dropdown>
                    </Space>
                );
            }
        }
    ];

    return (
        <PageShell embedded={embedded} title={<PageHeader eyebrow="Tài sản cố định" title="Hồ sơ Ghi tăng Tài sản cố định" description={`${assets.length} tài sản trong danh sách.`} />} toolbar={<PageToolbar actions={<Space>
                    <Button
                        icon={<ReloadOutlined />}
                        onClick={() => queryClient.invalidateQueries({ queryKey: ['fixed-assets'] })}
                    >
                        Nạp lại
                    </Button>
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        onClick={handleOpenCreateModal}
                        className="misa-btn-primary"
                    >
                        Thêm Ghi tăng (F8/F9)
                    </Button>
                </Space>} />}>

            {/* Split Pane: Master (Top) / Detail (Bottom) */}
            <DataTableSurface className="fixed-asset-registrations-table-surface"><div className="flex-1 flex flex-col p-3 gap-3 overflow-hidden">
                {/* Master Table Panel */}
                <div className="flex-1 bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden flex flex-col">
                    <div className="px-3 py-2 bg-slate-50 border-b border-slate-200 text-xs font-bold text-slate-700 uppercase">
                        Danh sách tài sản cố định
                    </div>
                    <div className="flex-1 overflow-auto">
                        <Table
                            columns={masterColumns}
                            dataSource={assets}
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
                <div className="h-[250px] bg-white rounded-lg shadow-sm border border-slate-200 overflow-hidden flex flex-col">
                    <Tabs defaultActiveKey="1" size="small" className="misa-tabs h-full flex flex-col">
                        <TabPane tab="1. Thiết lập hạch toán & khấu hao" key="1">
                            <div className="p-3 overflow-auto">
                                <Row gutter={16} className="text-xs">
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Tài khoản nguyên giá</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border">
                                            {selectedAssetDetail?.asset_account || '—'}
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Tài khoản khấu hao</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border">
                                            {selectedAssetDetail?.depreciation_account || '—'}
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Tài khoản chi phí</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border">
                                            {selectedAssetDetail?.expense_account || '—'}
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Tài khoản đối ứng (Có)</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border">
                                            {selectedAssetDetail?.credit_account || '—'}
                                        </div>
                                    </Col>
                                </Row>

                                <Row gutter={16} className="text-xs mt-3">
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Thời gian sử dụng</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border">
                                            {selectedAssetDetail?.useful_life_months || 0} tháng ({(Number(selectedAssetDetail?.useful_life_months || 0) / 12).toFixed(1)} năm)
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Mức trích KH hàng tháng</div>
                                        <div className="font-semibold text-blue-700 bg-slate-50 p-1.5 rounded border">
                                            {Number(selectedAssetDetail?.monthly_depreciation || 0).toLocaleString('vi-VN')} đ
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Ngày bắt đầu tính KH</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border">
                                            {selectedAssetDetail?.start_depreciation_date || '---'}
                                        </div>
                                    </Col>
                                    <Col span={6}>
                                        <div className="text-gray-500 mb-1">Nhà cung cấp</div>
                                        <div className="font-semibold text-slate-800 bg-slate-50 p-1.5 rounded border truncate">
                                            {selectedAssetDetail?.supplier?.name || selectedAssetDetail?.supplier_name || '---'}
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
                                        { title: 'Ngày hạch toán', dataIndex: ['depreciation_log', 'voucher_date'], key: 'voucher_date', width: 120 },
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
                                                        [{r.voucher_number}] {r.voucher_date}: Nguyên giá mới {Number(r.new_original_cost).toLocaleString('vi-VN')} đ (Lý do: {r.reason})
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
                                                        [{d.voucher_number}] {d.voucher_date}: Giá bán {Number(d.disposal_price).toLocaleString('vi-VN')} đ ({d.disposal_reason})
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
                title={
                    <div className="flex justify-between items-center w-full pr-8 border-b pb-2 mb-2">
                        <span className="text-xl font-bold text-slate-800">
                            {isEditMode ? 'Sửa chứng từ Ghi tăng TSCĐ' : 'Chứng từ Ghi tăng TSCĐ'}
                        </span>
                        <div className="text-right flex items-center gap-4">
                            <span className="text-sm text-gray-500 block">Tổng nguyên giá</span>
                            <span className="text-2xl font-bold text-blue-700 w-48">
                                {Number(originalCost || 0).toLocaleString('vi-VN')} đ
                            </span>
                        </div>
                    </div>
                }
                open={isModalVisible}
                onCancel={() => setIsModalVisible(false)}
                width="95vw"
                footer={
                    <div className="flex justify-between items-center">
                        <div className="text-xs text-gray-500">
                            <span className="font-semibold">Phím tắt:</span> <kbd className="px-1 bg-gray-100 border rounded">F8</kbd> Cất / <kbd className="px-1 bg-gray-100 border rounded">F9</kbd> Ghi sổ | <kbd className="px-1 bg-gray-100 border rounded">Esc</kbd> Đóng
                        </div>
                        <Space>
                            <Button onClick={() => setIsModalVisible(false)} className="misa-btn-secondary">
                                Hủy
                            </Button>
                            <Button
                                type="primary"
                                onClick={handleFormSubmit}
                                className="misa-btn-primary"
                                loading={createOrUpdateMutation.isPending}
                            >
                                Cất
                            </Button>
                        </Space>
                    </div>
                }
            >
                <ModalFrame>
                <Form
                    form={form}
                    layout="vertical"
                    onFinish={createOrUpdateMutation.mutate}
                    size="small"
                    onValuesChange={(changedValues) => {
                        if (changedValues.original_cost !== undefined) {
                            form.setFieldsValue({ depreciable_cost: changedValues.original_cost });
                        }
                    }}
                >
                    <div className="flex gap-6 mb-4">
                        <div className="flex-1 border p-4 rounded bg-gray-50">
                            <h3 className="font-bold text-slate-700 mb-2">Thông tin tài sản</h3>
                            <div className="grid grid-cols-4 gap-x-4">
                                <Form.Item name="asset_code" label="Mã TSCĐ" rules={[{ required: true }]} className="mb-2">
                                    <Input placeholder="TS00001" />
                                </Form.Item>
                                <Form.Item name="asset_name" label="Tên TSCĐ" rules={[{ required: true }]} className="col-span-3 mb-2">
                                    <Input placeholder="Nhập tên tài sản cố định" />
                                </Form.Item>
                                <Form.Item name="category_code" label="Loại TSCĐ" className="col-span-2 mb-2">
                                    <Select allowClear placeholder="Chọn loại tài sản">
                                        <Select.Option value="MAY_MOC">Máy móc, thiết bị</Select.Option>
                                        <Select.Option value="PHUONG_TIEN">Phương tiện vận tải</Select.Option>
                                        <Select.Option value="NHA_CUA">Nhà cửa, vật kiến trúc</Select.Option>
                                        <Select.Option value="THIET_BI_VP">Thiết bị, dụng cụ quản lý</Select.Option>
                                    </Select>
                                </Form.Item>
                                <Form.Item name="department_code" label="Phòng ban / Bộ phận sử dụng" className="col-span-2 mb-2">
                                    <Select allowClear placeholder="Chọn bộ phận sử dụng">
                                        <Select.Option value="QLDN">Bộ phận Quản lý doanh nghiệp (TK 6424)</Select.Option>
                                        <Select.Option value="BAN_HANG">Bộ phận Bán hàng (TK 6414)</Select.Option>
                                        <Select.Option value="SAN_XUAT">Bộ phận Phân xưởng / Sản xuất (TK 154)</Select.Option>
                                    </Select>
                                </Form.Item>
                            </div>
                        </div>

                        <div className="w-[350px] border p-4 rounded bg-gray-50">
                            <h3 className="font-bold text-slate-700 mb-2">Chứng từ ghi tăng</h3>
                            <div className="grid grid-cols-2 gap-x-4">
                                <Form.Item name="voucher_date" label="Ngày chứng từ" rules={[{ required: true }]} className="mb-2">
                                    <DatePicker className="w-full" format="DD/MM/YYYY" />
                                </Form.Item>
                                <Form.Item name="voucher_number" label="Số chứng từ" rules={[{ required: true }]} className="mb-2">
                                    <Input placeholder="TSCD-YYYY-XXXX" />
                                </Form.Item>
                                <Form.Item name="supplier_id" label="Nhà cung cấp" className="col-span-2 mb-2">
                                    <Select showSearch allowClear placeholder="Chọn nhà cung cấp" optionFilterProp="children">
                                        {suppliers.map((s: any) => (
                                            <Select.Option key={s.id} value={s.id}>
                                                [{s.code || s.supplier_code}] {s.name}
                                            </Select.Option>
                                        ))}
                                    </Select>
                                </Form.Item>
                            </div>
                        </div>
                    </div>

                    <div className="border border-gray-200 rounded">
                        <Tabs type="card" size="small" className="misa-tabs">
                            <TabPane tab="1. Thông tin khấu hao" key="1">
                                <div className="p-4 grid grid-cols-4 gap-4">
                                    <Form.Item name="purchase_date" label="Ngày ghi tăng" rules={[{ required: true }]}>
                                        <DatePicker className="w-full" format="DD/MM/YYYY" />
                                    </Form.Item>
                                    <Form.Item name="start_depreciation_date" label="Ngày bắt đầu tính KH" rules={[{ required: true }]}>
                                        <DatePicker className="w-full" format="DD/MM/YYYY" />
                                    </Form.Item>
                                    <Form.Item name="useful_life_months" label="Thời gian sử dụng (tháng)" rules={[{ required: true }]}>
                                        <InputNumber className="w-full" min={1} />
                                    </Form.Item>
                                    <Form.Item name="quantity" label="Số lượng" rules={[{ required: true }]}>
                                        <InputNumber className="w-full" min={1} />
                                    </Form.Item>
                                    <Form.Item name="original_cost" label="Nguyên giá" rules={[{ required: true }]}>
                                        <InputNumber
                                            className="w-full"
                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            min={0}
                                        />
                                    </Form.Item>
                                    <Form.Item name="depreciable_cost" label="Giá trị tính KH" rules={[{ required: true }]}>
                                        <InputNumber
                                            className="w-full"
                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            min={0}
                                        />
                                    </Form.Item>
                                    <Form.Item name="accumulated_depreciation" label="Hao mòn lũy kế">
                                        <InputNumber
                                            className="w-full"
                                            formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            min={0}
                                        />
                                    </Form.Item>
                                </div>
                            </TabPane>
                            <TabPane tab="2. Thiết lập hạch toán" key="2">
                                <div className="p-4 grid grid-cols-4 gap-4">
                                    <Form.Item name="asset_account" label="Tài khoản nguyên giá" rules={[{ required: true }]}>
                                        <Select showSearch>
                                            {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => (
                                                <Select.Option key={acc.code} value={acc.code}>
                                                    {acc.code} - {acc.name}
                                                </Select.Option>
                                            ))}
                                        </Select>
                                    </Form.Item>
                                    <Form.Item name="depreciation_account" label="Tài khoản khấu hao" rules={[{ required: true }]}>
                                        <Select showSearch>
                                            {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => (
                                                <Select.Option key={acc.code} value={acc.code}>
                                                    {acc.code} - {acc.name}
                                                </Select.Option>
                                            ))}
                                        </Select>
                                    </Form.Item>
                                    <Form.Item name="expense_account" label="Tài khoản chi phí" rules={[{ required: true }]}>
                                        <Select showSearch>
                                            {chartOfAccounts?.filter((a:any) => !a.is_parent && a.is_active !== false).map((acc:any) => (
                                                <Select.Option key={acc.code} value={acc.code}>
                                                    {acc.code} - {acc.name}
                                                </Select.Option>
                                            ))}
                                        </Select>
                                    </Form.Item>
                                    <Form.Item name="credit_account" label="Tài khoản đối ứng (Có)" rules={[{ required: true }]}>
                                        <Select showSearch allowClear>
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
                </Form>
                </ModalFrame>
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
