import React, { useState, useEffect } from 'react';
import { Button, DatePicker, Form, Input, InputNumber, Select, Table } from 'antd';
import { SettingOutlined, CloseOutlined, FileTextOutlined, SearchOutlined, QuestionCircleOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { toast as message } from '../../components/feedback/toast';
import api from '../../api/axios';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import Modal from '../../components/layout/AppModal';
import ModalFrame from '../../components/layout/ModalFrame';
import { MisaMasterCard, MisaTotalCard } from '../../components/misa';
import { useVoucherShortcuts } from '../../hooks/useVoucherShortcuts';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';

type FixedAssetInventoryProps = { embedded?: boolean };

type AssetRecord = {
    id: number;
    asset_code: string;
    asset_name: string;
    quantity?: number | null;
    net_value?: number | string | null;
    status?: string;
};

const readAssets = (payload: any): AssetRecord[] => {
    const rows = Array.isArray(payload) ? payload : payload?.data;
    return Array.isArray(rows) ? rows : [];
};

export const FixedAssetInventory: React.FC<FixedAssetInventoryProps> = ({ embedded = false }) => {
    const [assets, setAssets] = useState<AssetRecord[]>([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedAsset, setSelectedAsset] = useState<AssetRecord | null>(null);
    const [lastEvent, setLastEvent] = useState<any>(null);
    const [searchText, setSearchText] = useState('');
    const [form] = Form.useForm();

    const loadAssets = async () => {
        setLoading(true);
        try {
            const { data } = await api.get('/fixed-assets');
            setAssets(readAssets(data));
        } catch {
            message.error('Không thể tải danh sách TSCĐ để kiểm kê.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        loadAssets();
    }, []);

    const openInventoryModal = React.useCallback((asset?: AssetRecord) => {
        form.resetFields();
        const now = dayjs();
        const target = asset || (assets.length > 0 ? assets[0] : null);
        setSelectedAsset(target);

        const bookQty = target?.quantity ?? 1;
        const bookNet = Number(target?.net_value ?? 0);

        form.setFieldsValue({
            voucher_date: now,
            voucher_number: `KKTS-${now.format('YYYYMMDD')}-${String(target?.id || 1).padStart(3, '0')}`,
            fixed_asset_id: target?.id,
            book_quantity: bookQty,
            counted_quantity: bookQty,
            book_net_value: bookNet,
            counted_net_value: bookNet,
        });
        setIsModalOpen(true);
    }, [assets, form]);

    useEffect(() => {
        const handleOpen = () => {
            openInventoryModal();
        };
        window.addEventListener('open-fixed-asset-inventory', handleOpen);
        return () => window.removeEventListener('open-fixed-asset-inventory', handleOpen);
    }, [openInventoryModal]);

    const handleAssetSelect = (assetId: number) => {
        const found = assets.find(a => a.id === assetId);
        if (found) {
            setSelectedAsset(found);
            const bookQty = found.quantity ?? 1;
            const bookNet = Number(found.net_value ?? 0);
            form.setFieldsValue({
                book_quantity: bookQty,
                counted_quantity: bookQty,
                book_net_value: bookNet,
                counted_net_value: bookNet,
            });
        }
    };

    const submit = async (values: any) => {
        setSaving(true);
        try {
            const response = await api.put(`/fixed-assets/${values.fixed_asset_id}`, {
                lifecycle_action: 'inventory',
                voucher_number: values.voucher_number,
                voucher_date: values.voucher_date.format('YYYY-MM-DD'),
                counted_quantity: Number(values.counted_quantity),
                counted_net_value: Number(values.counted_net_value),
                reason: values.reason,
            });
            const event = response?.data?.data;
            if (!event?.event_id || !event.before || !event.after || !event.variance) {
                message.error('Máy chủ chưa trả về biên bản kiểm kê đã lưu.');
                return;
            }
            setLastEvent(event);
            message.success(event.requires_follow_up ? 'Đã lưu biên bản kiểm kê; có chênh lệch cần xử lý.' : 'Đã lưu biên bản kiểm kê TSCĐ thành công.');
            setIsModalOpen(false);
            form.resetFields();
            await loadAssets();
        } catch (error: any) {
            message.error(error?.response?.data?.message || error?.response?.data?.error || 'Không thể lưu kiểm kê TSCĐ.');
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

    const watchedBookNet = Form.useWatch('book_net_value', form) || 0;
    const watchedCountedNet = Form.useWatch('counted_net_value', form) || 0;
    const watchedBookQty = Form.useWatch('book_quantity', form) || 0;
    const watchedCountedQty = Form.useWatch('counted_quantity', form) || 0;

    const varianceNet = Number(watchedCountedNet) - Number(watchedBookNet);
    const varianceQty = Number(watchedCountedQty) - Number(watchedBookQty);

    const filteredAssets = React.useMemo(() => {
        if (!searchText.trim()) return assets;
        const q = searchText.toLowerCase();
        return assets.filter(a =>
            a.asset_code?.toLowerCase().includes(q) ||
            a.asset_name?.toLowerCase().includes(q)
        );
    }, [assets, searchText]);

    return (
        <PageShell
            embedded={embedded}
            title={<PageHeader eyebrow="Tài sản cố định" title="Kiểm kê TSCĐ" description="Biên bản kiểm kê tài sản cố định định kỳ và xử lý chênh lệch." />}
            toolbar={<PageToolbar actions={
                <Button
                    type="primary"
                    icon={<FileTextOutlined />}
                    className="misa-btn-primary"
                    onClick={() => openInventoryModal()}
                >
                    Lập biên bản kiểm kê
                </Button>
            } />}
        >
            <DataTableSurface>
                {/* Search Toolbar */}
                <div className="p-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2 flex-1 max-w-md">
                        <Input
                            placeholder="Tìm theo mã hoặc tên TSCĐ kiểm kê..."
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
                            ✓ Biên bản kiểm kê #{lastEvent.event_id}: Chênh lệch giá trị{' '}
                            <strong>{Number(lastEvent.variance?.net_value || 0).toLocaleString('vi-VN')} đ</strong>{' '}
                            ({lastEvent.requires_follow_up ? 'Có chênh lệch cần xử lý tiếp' : 'Khớp số liệu kiểm kê thực tế'}).
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
                            title: 'Số lượng sổ',
                            dataIndex: 'quantity',
                            key: 'quantity',
                            width: 120,
                            align: 'right' as const,
                            render: (v: any) => Number(v ?? 1).toLocaleString('vi-VN')
                        },
                        {
                            title: 'Giá trị sổ',
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
                                    primaryActionLabel="Kiểm kê"
                                    onPrimaryAction={() => openInventoryModal(r)}
                                    menuItems={[]}
                                />
                            )
                        }
                    ]}
                />
            </DataTableSurface>

            {/* MISA AMIS Voucher Modal: Kiểm kê TSCĐ */}
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
                            <span className="misa-voucher-title">Biên bản Kiểm kê Tài sản cố định</span>
                            <span className="text-xs bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded font-medium border border-indigo-200 ml-3">
                                {selectedAsset ? `TSCĐ: ${selectedAsset.asset_code}` : 'Kiểm kê định kỳ'}
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
                                onClick={() => message.info('Trợ giúp: Lập biên bản kiểm kê thực tế TSCĐ và xử lý chênh lệch theo quy định kế toán.')}
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
                                Lưu biên bản kiểm kê
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
                                        Đối chiếu số liệu sổ sách & Thực tế kiểm kê
                                    </div>
                                    <div className="grid grid-cols-12 gap-x-3 gap-y-2">
                                        <div className="col-span-12">
                                            <Form.Item
                                                name="fixed_asset_id"
                                                label="Tài sản cố định kiểm kê"
                                                rules={[{ required: true, message: 'Vui lòng chọn tài sản kiểm kê' }]}
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
                                                            [{a.asset_code}] {a.asset_name} (Sổ sách: {Number(a.quantity ?? 1)} cái | GTCL: {Number(a.net_value || 0).toLocaleString('vi-VN')} đ)
                                                        </Select.Option>
                                                    ))}
                                                </Select>
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-3">
                                            <Form.Item name="book_quantity" label="Số lượng trên sổ" className="mb-0">
                                                <InputNumber disabled className="w-full" style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-3">
                                            <Form.Item
                                                name="counted_quantity"
                                                label="Số lượng thực tế"
                                                rules={[{ required: true, message: 'Nhập số lượng thực tế' }]}
                                                className="mb-0"
                                            >
                                                <InputNumber min={0} precision={0} className="w-full" style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-3">
                                            <Form.Item name="book_net_value" label="Giá trị sổ sách (đ)" className="mb-0">
                                                <InputNumber
                                                    disabled
                                                    className="w-full"
                                                    style={{ width: '100%' }}
                                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                />
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-3">
                                            <Form.Item
                                                name="counted_net_value"
                                                label="Giá trị thực tế (đ)"
                                                rules={[{ required: true, message: 'Nhập giá trị thực tế' }]}
                                                className="mb-0"
                                            >
                                                <InputNumber
                                                    min={0}
                                                    className="w-full"
                                                    style={{ width: '100%' }}
                                                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                                />
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-12">
                                            <Form.Item name="reason" label="Kết luận kiểm kê / Mô tả chênh lệch" className="mb-0">
                                                <Input placeholder="Nhập ghi chú chênh lệch (nếu có)..." style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                    </div>
                                </MisaMasterCard.Left>

                                <MisaMasterCard.Right>
                                    <MisaMasterCard.MetaRow label="Ngày kiểm kê" required>
                                        <Form.Item name="voucher_date" noStyle rules={[{ required: true }]}>
                                            <DatePicker className="w-full" format="DD/MM/YYYY" style={{ width: '100%' }} />
                                        </Form.Item>
                                    </MisaMasterCard.MetaRow>
                                    <MisaMasterCard.MetaRow label="Số biên bản" required>
                                        <Form.Item name="voucher_number" noStyle rules={[{ required: true }]}>
                                            <Input placeholder="KKTS-YYYY-XXXX" className="font-semibold" style={{ width: '100%' }} />
                                        </Form.Item>
                                    </MisaMasterCard.MetaRow>
                                    <MisaTotalCard
                                        label="CHÊNH LỆCH GIÁ TRỊ"
                                        value={varianceNet}
                                    />
                                </MisaMasterCard.Right>
                            </MisaMasterCard>

                            <div className="mt-3 bg-white rounded-md border border-slate-200 overflow-hidden p-3">
                                <h4 className="font-semibold text-slate-700 text-sm mb-2">Tóm tắt kết quả kiểm kê</h4>
                                <div className="grid grid-cols-12 gap-3 mb-2">
                                    <div className="col-span-4">
                                        <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200">
                                            <span className="text-xs text-slate-500 block">Chênh lệch số lượng</span>
                                            <span className={`text-base font-bold ${varianceQty === 0 ? 'text-emerald-600' : varianceQty > 0 ? 'text-blue-600' : 'text-red-600'}`}>
                                                {varianceQty > 0 ? `+${varianceQty}` : varianceQty} cái
                                            </span>
                                        </div>
                                    </div>
                                    <div className="col-span-4">
                                        <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200">
                                            <span className="text-xs text-slate-500 block">Chênh lệch giá trị còn lại</span>
                                            <span className={`text-base font-bold ${varianceNet === 0 ? 'text-emerald-600' : varianceNet > 0 ? 'text-blue-600' : 'text-red-600'}`}>
                                                {varianceNet > 0 ? `+${varianceNet.toLocaleString('vi-VN')}` : varianceNet.toLocaleString('vi-VN')} đ
                                            </span>
                                        </div>
                                    </div>
                                    <div className="col-span-4">
                                        <div className="bg-slate-50 p-2.5 rounded-md border border-slate-200">
                                            <span className="text-xs text-slate-500 block">Đánh giá hiện trạng</span>
                                            <span className="text-sm font-semibold text-slate-800">
                                                {varianceQty === 0 && varianceNet === 0 ? '✓ Khớp hoàn toàn số liệu' : '⚠ Có chênh lệch cần xử lý'}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Form>
                </ModalFrame>
            </Modal>
        </PageShell>
    );
};

export default FixedAssetInventory;
