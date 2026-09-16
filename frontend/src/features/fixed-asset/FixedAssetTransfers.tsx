import React, { useState, useEffect } from 'react';
import { Button, DatePicker, Form, Input, Select, Table } from 'antd';
import { SettingOutlined, CloseOutlined, SwapOutlined, SearchOutlined, QuestionCircleOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { toast as message } from '../../components/feedback/toast';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import Modal from '../../components/layout/AppModal';
import ModalFrame from '../../components/layout/ModalFrame';
import { MisaMasterCard, MisaTotalCard, MisaInputGroup } from '../../components/misa';
import { useVoucherShortcuts } from '../../hooks/useVoucherShortcuts';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';

type FixedAssetTransfersProps = { embedded?: boolean };

type AssetRecord = {
    id: number;
    asset_code: string;
    asset_name: string;
    department_code?: string | null;
    net_value?: number | string | null;
    status?: string;
};

const readAssets = (payload: any): AssetRecord[] => {
    const rows = Array.isArray(payload) ? payload : payload?.data;
    return Array.isArray(rows) ? rows : [];
};

export const FixedAssetTransfers: React.FC<FixedAssetTransfersProps> = ({ embedded = false }) => {
    const [assets, setAssets] = useState<AssetRecord[]>([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedAsset, setSelectedAsset] = useState<AssetRecord | null>(null);
    const [lastEvent, setLastEvent] = useState<any>(null);
    const [searchText, setSearchText] = useState('');
    const [isDepartmentModalVisible, setIsDepartmentModalVisible] = useState(false);

    const [customDepartments, setCustomDepartments] = useState<{ value: string; label: string }[]>([
        { value: 'QLDN', label: 'Bộ phận Quản lý doanh nghiệp (TK 6424)' },
        { value: 'BAN_HANG', label: 'Bộ phận Bán hàng (TK 6414)' },
        { value: 'SAN_XUAT', label: 'Bộ phận Phân xưởng / Sản xuất (TK 154)' },
        { value: 'VAN_PHONG', label: 'Bộ phận Văn phòng (TK 642)' },
        { value: 'KHO_VAN', label: 'Bộ phận Kho vận / Logistics' },
    ]);

    const [form] = Form.useForm();
    const [deptForm] = Form.useForm();

    const loadAssets = async () => {
        setLoading(true);
        try {
            const { data } = await api.get('/fixed-assets');
            setAssets(readAssets(data));
        } catch {
            message.error('Không thể tải danh sách TSCĐ để điều chuyển.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadAssets();
    }, []);

    const openTransferModal = React.useCallback((asset?: AssetRecord) => {
        form.resetFields();
        const now = dayjs();
        const target = asset || (assets.length > 0 ? assets[0] : null);
        setSelectedAsset(target);

        form.setFieldsValue({
            voucher_date: now,
            voucher_number: `ĐCTS-${now.format('YYYYMMDD')}-${String(target?.id || 1).padStart(3, '0')}`,
            fixed_asset_id: target?.id,
            from_department_code: target?.department_code || 'Chưa phân bổ',
        });
        setIsModalOpen(true);
    }, [assets, form]);

    useEffect(() => {
        const handleOpen = () => {
            openTransferModal();
        };
        window.addEventListener('open-fixed-asset-transfer', handleOpen);
        return () => window.removeEventListener('open-fixed-asset-transfer', handleOpen);
    }, [openTransferModal]);

    const handleAssetSelect = (assetId: number) => {
        const found = assets.find(a => a.id === assetId);
        if (found) {
            setSelectedAsset(found);
            form.setFieldsValue({
                from_department_code: found.department_code || 'Chưa phân bổ',
            });
        }
    };

    const submit = async (values: any) => {
        setSaving(true);
        try {
            const response = await api.put(`/fixed-assets/${values.fixed_asset_id}`, {
                lifecycle_action: 'transfer',
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date.format('YYYY-MM-DD'),
                to_department_code: values.to_department_code,
                reason: values.reason,
            });
            const event = response?.data?.data;
            if (!event?.event_id || !event.before || !event.after || !event.asset) {
                message.error('Máy chủ chưa trả về bằng chứng điều chuyển đã lưu.');
                return;
            }
            setLastEvent(event);
            message.success('Đã điều chuyển tài sản cố định thành công!');
            setAssets(current => current.map(asset => asset.id === event.asset.id ? event.asset : asset));
            setIsModalOpen(false);
            form.resetFields();
        } catch (error: any) {
            message.error(error?.response?.data?.message || error?.response?.data?.error || 'Không thể điều chuyển TSCĐ.');
        } finally {
            setSaving(false);
        }
    };

    useVoucherShortcuts({
        onSave: () => form.submit(),
        onPost: () => form.submit(),
        onClose: () => setIsModalOpen(false),
        enabled: isModalOpen,
    });

    const filteredAssets = React.useMemo(() => {
        if (!searchText.trim()) return assets;
        const q = searchText.toLowerCase();
        return assets.filter(a =>
            a.asset_code?.toLowerCase().includes(q) ||
            a.asset_name?.toLowerCase().includes(q) ||
            a.department_code?.toLowerCase().includes(q)
        );
    }, [assets, searchText]);

    return (
        <PageShell
            embedded={embedded}
            title={<PageHeader eyebrow="Tài sản cố định" title="Điều chuyển TSCĐ" description="Cập nhật bộ phận sử dụng và theo dõi biến động điều chuyển nội bộ." />}
            toolbar={<PageToolbar actions={
                <Button
                    type="primary"
                    icon={<SwapOutlined />}
                    className="misa-btn-primary"
                    onClick={() => openTransferModal()}
                >
                    Lập điều chuyển
                </Button>
            } />}
        >
            <DataTableSurface>
                {/* Search Toolbar */}
                <div className="p-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2 flex-1 max-w-md">
                        <Input
                            placeholder="Tìm theo mã, tên TSCĐ hoặc bộ phận..."
                            prefix={<SearchOutlined className="text-slate-400" />}
                            value={searchText}
                            onChange={e => setSearchText(e.target.value)}
                            allowClear
                            size="small"
                        />
                    </div>
                    <div className="text-xs text-slate-500 font-medium">
                        Tổng số: <span className="font-bold text-slate-700">{filteredAssets.length}</span> tài sản
                    </div>
                </div>

                {lastEvent && (
                    <div className="p-3 text-xs bg-emerald-50 text-emerald-800 border-b border-slate-200 flex items-center justify-between">
                        <span>
                            ✓ Biên bản điều chuyển #{lastEvent.event_id}: Chuyển từ{' '}
                            <strong>{lastEvent.before?.department_code || 'Chưa phân bổ'}</strong> sang{' '}
                            <strong>{lastEvent.after?.department_code || '—'}</strong> thành công.
                        </span>
                        <Button size="small" type="link" onClick={() => setLastEvent(null)}>Đóng</Button>
                    </div>
                )}

                <Table
                    rowKey="id"
                    dataSource={filteredAssets}
                    loading={loading}
                    pagination={{ pageSize: 10, showSizeChanger: true, showTotal: (total) => `Tổng ${total} bản ghi` }}
                    size="small"
                    className="misa-voucher-table"
                    columns={[
                        {
                            title: 'Mã TSCĐ',
                            dataIndex: 'asset_code',
                            key: 'asset_code',
                            width: 140,
                            render: (text: string) => <span className="font-semibold text-blue-700">{text}</span>
                        },
                        { title: 'Tên TSCĐ', dataIndex: 'asset_name', key: 'asset_name' },
                        {
                            title: 'Bộ phận hiện tại',
                            dataIndex: 'department_code',
                            key: 'department_code',
                            width: 160,
                            render: (v: string) => v ? <span className="text-slate-700 font-medium">{v}</span> : <span className="text-slate-400 italic">Chưa phân bổ</span>
                        },
                        {
                            title: 'Giá trị còn lại',
                            dataIndex: 'net_value',
                            key: 'net_value',
                            width: 160,
                            align: 'right' as const,
                            render: (v: any) => `${Number(v || 0).toLocaleString('vi-VN')} đ`
                        },
                        {
                            title: 'Trạng thái',
                            key: 'status',
                            width: 130,
                            align: 'center' as const,
                            render: (_: any, r: any) => <VoucherStatusBadge status={r} />
                        },
                        {
                            title: 'Chức năng',
                            key: 'action',
                            width: 120,
                            align: 'center' as const,
                            render: (_: any, r: any) => (
                                <VoucherActionCell
                                    primaryActionLabel="Điều chuyển"
                                    onPrimaryAction={() => openTransferModal(r)}
                                    menuItems={[]}
                                />
                            )
                        }
                    ]}
                />
            </DataTableSurface>

            {/* MISA AMIS Voucher Modal: Điều chuyển TSCĐ */}
            <Modal
                className="misa-voucher-modal"
                closable={false}
                centered
                destroyOnHidden
                open={isModalOpen}
                onCancel={() => setIsModalOpen(false)}
                width="min(1820px, calc(100vw - 32px))"
                title={
                    <div className="misa-voucher-custom-header">
                        <div className="misa-voucher-header-left">
                            <span className="misa-voucher-title">Chứng từ Điều chuyển TSCĐ</span>
                            <span className="text-xs bg-amber-50 text-amber-700 px-2 py-0.5 rounded font-medium border border-amber-200 ml-3">
                                Điều chuyển nội bộ
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
                                onClick={() => setIsModalOpen(false)}
                            >
                                <CloseOutlined />
                            </button>
                        </div>
                    </div>
                }
                footer={
                    <div className="misa-voucher-fixed-footer">
                        <div className="misa-footer-left">
                            <Button
                                className="misa-btn-footer-cancel"
                                icon={<QuestionCircleOutlined />}
                                onClick={() => message.info('Trợ giúp: Điều chuyển TSCĐ giữa các phòng ban, cập nhật nơi sử dụng và chi phí trích khấu hao.')}
                            >
                                Giúp
                            </Button>
                        </div>
                        <div className="misa-footer-right">
                            <Button onClick={() => setIsModalOpen(false)} className="misa-btn-footer-cancel">
                                Hủy
                            </Button>
                            <Button
                                type="primary"
                                onClick={() => form.submit()}
                                loading={saving}
                                className="misa-btn-footer-save-add"
                            >
                                Lưu điều chuyển
                            </Button>
                        </div>
                    </div>
                }
            >
                <ModalFrame>
                    <Form form={form} layout="vertical" onFinish={submit} size="small" className="misa-voucher-form-container">
                        <div className="misa-voucher-scroll-body">
                            <MisaMasterCard>
                                <MisaMasterCard.Left>
                                    <div className="text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                                        Thông tin điều chuyển nội bộ
                                    </div>
                                    <div className="grid grid-cols-12 gap-x-3 gap-y-2">
                                        <div className="col-span-12">
                                            <Form.Item
                                                name="fixed_asset_id"
                                                label="Tài sản cố định điều chuyển"
                                                rules={[{ required: true, message: 'Vui lòng chọn tài sản cần điều chuyển' }]}
                                                className="mb-0"
                                            >
                                                <Select
                                                    showSearch
                                                    placeholder="Tìm theo mã hoặc tên TSCĐ"
                                                    optionFilterProp="children"
                                                    onChange={handleAssetSelect}
                                                    style={{ width: '100%' }}
                                                >
                                                    {assets.map(a => (
                                                        <Select.Option key={a.id} value={a.id}>
                                                            [{a.asset_code}] {a.asset_name} (Hiện tại: {a.department_code || 'Chưa phân bổ'} | GTCL: {Number(a.net_value || 0).toLocaleString('vi-VN')} đ)
                                                        </Select.Option>
                                                    ))}
                                                </Select>
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-6">
                                            <Form.Item
                                                name="from_department_code"
                                                label="Bộ phận hiện tại (Bàn giao)"
                                                className="mb-0"
                                            >
                                                <Input disabled placeholder="Bộ phận đang quản lý" style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-6">
                                            <Form.Item
                                                name="to_department_code"
                                                label="Bộ phận nhận mới (Tiếp nhận)"
                                                rules={[{ required: true, message: 'Chọn hoặc nhập bộ phận tiếp nhận' }]}
                                                className="mb-0"
                                            >
                                                <MisaInputGroup
                                                    onPlusClick={() => {
                                                        deptForm.resetFields();
                                                        setIsDepartmentModalVisible(true);
                                                    }}
                                                    plusTitle="Thêm nhanh bộ phận tiếp nhận"
                                                >
                                                    <Select
                                                        showSearch
                                                        placeholder="Chọn bộ phận nhận"
                                                        optionFilterProp="label"
                                                        options={customDepartments}
                                                        style={{ width: '100%' }}
                                                    />
                                                </MisaInputGroup>
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-12">
                                            <Form.Item
                                                name="reason"
                                                label="Lý do điều chuyển"
                                                rules={[{ required: true, message: 'Nhập lý do điều chuyển' }]}
                                                className="mb-0"
                                            >
                                                <Input placeholder="Ví dụ: Điều chuyển phục vụ mở rộng dự án kinh doanh..." style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                    </div>
                                </MisaMasterCard.Left>

                                <MisaMasterCard.Right>
                                    <MisaMasterCard.MetaRow label="Ngày điều chuyển" required>
                                        <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                            <DatePicker className="w-full" format="DD/MM/YYYY" style={{ width: '100%' }} />
                                        </Form.Item>
                                    </MisaMasterCard.MetaRow>
                                    <MisaMasterCard.MetaRow label="Số chứng từ" required>
                                        <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                            <Input placeholder="ĐCTS-YYYY-XXXX" className="font-semibold" style={{ width: '100%' }} />
                                        </Form.Item>
                                    </MisaMasterCard.MetaRow>
                                    <MisaTotalCard
                                        label="GIÁ TRỊ CÒN LẠI"
                                        value={Number(selectedAsset?.net_value || 0)}
                                    />
                                </MisaMasterCard.Right>
                            </MisaMasterCard>

                            <div className="mt-3 bg-white rounded-md border border-slate-200 overflow-hidden p-3">
                                <h4 className="font-semibold text-slate-700 text-sm mb-2">Thông tin luân chuyển tài sản</h4>
                                <div className="grid grid-cols-12 gap-3 mb-2">
                                    <div className="col-span-6">
                                        <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200">
                                            <span className="text-xs text-slate-500 block">Bộ phận bàn giao (Hiện tại)</span>
                                            <span className="text-sm font-bold text-slate-800">
                                                {selectedAsset?.department_code || 'Chưa phân bổ'}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="col-span-6">
                                        <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200">
                                            <span className="text-xs text-slate-500 block">Giá trị tài sản luân chuyển</span>
                                            <span className="text-sm font-bold text-blue-700">
                                                {Number(selectedAsset?.net_value || 0).toLocaleString('vi-VN')} đ
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div className="text-xs text-slate-600 italic bg-blue-50 p-2 rounded border border-blue-200">
                                    ℹ Khi hoàn thành điều chuyển, từ kỳ tính khấu hao tiếp theo chi phí trích khấu hao TSCĐ sẽ tự động được hạch toán vào bộ phận tiếp nhận.
                                </div>
                            </div>
                        </div>
                    </Form>
                </ModalFrame>
            </Modal>

            {/* Quick Add Department Modal */}
            <Modal
                title="Thêm nhanh bộ phận sử dụng"
                open={isDepartmentModalVisible}
                onCancel={() => {
                    setIsDepartmentModalVisible(false);
                    deptForm.resetFields();
                }}
                onOk={() => deptForm.submit()}
                okText="Lưu"
                cancelText="Hủy"
                destroyOnHidden
                centered
                width={480}
            >
                <Form
                    form={deptForm}
                    layout="vertical"
                    onFinish={(values: { code: string; name: string }) => {
                        const newDept = { value: values.code.trim().toUpperCase(), label: values.name.trim() };
                        setCustomDepartments(prev => [...prev, newDept]);
                        form.setFieldsValue({ to_department_code: newDept.value });
                        setIsDepartmentModalVisible(false);
                        deptForm.resetFields();
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
        </PageShell>
    );
};

export default FixedAssetTransfers;
