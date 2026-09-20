import { useEffect, useState } from 'react';
import { Button, Form, Input, InputNumber, Table, Typography, DatePicker } from 'antd';
import type { MenuProps } from 'antd';
import { PlusOutlined, SyncOutlined, ToolOutlined, EditOutlined, DeleteOutlined, StopOutlined, SettingOutlined, CloseOutlined, SearchOutlined, QuestionCircleOutlined, EyeOutlined } from '@ant-design/icons';
import { MisaMasterCard, MisaTotalCard } from '../../components/misa';
import dayjs from 'dayjs';
import api from '../../api/axios';
import { notifyDataChanged } from '../../lib/queryClient';
import { toast as message } from '../../components/feedback/toast';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import Modal from '../../components/layout/AppModal';
import ModalFrame from '../../components/layout/ModalFrame';
import { VoucherActionCell } from '../../components/common/VoucherActionCell';
import { VoucherStatusBadge } from '../../components/common/VoucherStatusBadge';
import { useVoucherShortcuts } from '../../hooks/useVoucherShortcuts';

const { Text } = Typography;

export interface ToolsProps {
    embedded?: boolean;
}

interface ToolRecord {
    id: number;
    tool_code: string;
    tool_name: string;
    purchase_date?: string | null;
    original_cost: number | string | null;
    accumulated_allocation: number | string | null;
    remaining_value: number | string | null;
    allocation_months: number | string | null;
    monthly_allocation: number | string | null;
    is_active: boolean;
    tool_account?: string | null;
    expense_account?: string | null;
}

interface ToolCreateValues {
    tool_code: string;
    tool_name: string;
    purchase_date: any;
    original_cost: number;
    allocation_months: number;
    tool_account: string;
    expense_account: string;
}

function parseToolsResponse(value: unknown): ToolRecord[] {
    if (Array.isArray(value)) return value as ToolRecord[];
    if (value && typeof value === 'object') {
        const obj = value as { data?: unknown };
        if (Array.isArray(obj.data)) return obj.data as ToolRecord[];
        if (obj.data && typeof obj.data === 'object' && Array.isArray((obj.data as { data?: unknown }).data)) {
            return (obj.data as { data: ToolRecord[] }).data;
        }
    }
    return [];
}

function formatAmount(value: number | string | null | undefined, negative = false): string {
    if (value === null || value === undefined || value === '') return '—';
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '—';
    return `${negative ? '-' : ''}${amount.toLocaleString('vi-VN')}`;
}

function isPersistedToolResponse(value: unknown): value is { data: ToolRecord } {
    const tool = (value as { data?: unknown } | null)?.data;
    return !!tool && typeof tool === 'object' && Number((tool as { id?: unknown }).id) > 0;
}

const defaultCreateValues: ToolCreateValues = {
    tool_code: '',
    tool_name: '',
    purchase_date: dayjs(),
    original_cost: 0,
    allocation_months: 12,
    tool_account: '242',
    expense_account: '6423',
};

export default function Tools({ embedded = false }: ToolsProps = {}) {
    const [data, setData] = useState<ToolRecord[]>([]);
    const [loading, setLoading] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [createLoading, setCreateLoading] = useState(false);
    const [searchText, setSearchText] = useState('');
    const [form] = Form.useForm<ToolCreateValues>();
    const watchedCost = Form.useWatch('original_cost', form) || 0;
    const watchedMonths = Form.useWatch('allocation_months', form) || 0;
    const monthlyAllocationEstimate = watchedMonths && watchedCost ? Math.round(Number(watchedCost) / Number(watchedMonths)) : 0;

    useEffect(() => {
        const handleOpen = () => {
            setEditingId(null);
            form.setFieldsValue({
                ...defaultCreateValues,
                purchase_date: dayjs(),
            });
            setCreateOpen(true);
        };
        const handlePreviewEvent = () => void handlePreview();
        const handleAllocationEvent = () => void handleRunAllocation();
        const handleRefreshEvent = () => void loadTools();
        const handleFilterEvent = (e: any) => {
            if (e.detail?.searchText !== undefined) {
                setSearchText(e.detail.searchText);
            }
        };

        window.addEventListener('open-tool-create', handleOpen);
        window.addEventListener('open-tool-preview', handlePreviewEvent);
        window.addEventListener('run-tool-allocation', handleAllocationEvent);
        window.addEventListener('refresh-tools', handleRefreshEvent);
        window.addEventListener('accounting-data-changed', handleRefreshEvent);
        window.addEventListener('fixed-asset-filter-change', handleFilterEvent);

        return () => {
            window.removeEventListener('open-tool-create', handleOpen);
            window.removeEventListener('open-tool-preview', handlePreviewEvent);
            window.removeEventListener('run-tool-allocation', handleAllocationEvent);
            window.removeEventListener('refresh-tools', handleRefreshEvent);
            window.removeEventListener('accounting-data-changed', handleRefreshEvent);
            window.removeEventListener('fixed-asset-filter-change', handleFilterEvent);
        };
    }, [form]);

    const loadTools = async () => {
        setLoading(true);
        try {
            const { data: responseData } = await api.get('/tools');
            setData(parseToolsResponse(responseData));
        } catch (error) {
            setData([]);
            message.error('Không thể tải danh sách công cụ dụng cụ từ máy chủ.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        void loadTools();
    }, []);

    const handleCreate = async (values: ToolCreateValues) => {
        setCreateLoading(true);
        try {
            const purchaseDateStr = dayjs.isDayjs(values.purchase_date)
                ? values.purchase_date.format('YYYY-MM-DD')
                : (values.purchase_date ? dayjs(values.purchase_date).format('YYYY-MM-DD') : dayjs().format('YYYY-MM-DD'));

            const payload = {
                ...values,
                purchase_date: purchaseDateStr,
                original_cost: Number(values.original_cost || 0),
                allocation_months: Number(values.allocation_months || 0),
            };
            const response = editingId === null
                ? await api.post('/tools', payload)
                : await api.put(`/tools/${editingId}`, payload);

            if (!isPersistedToolResponse(response?.data)) {
                message.error('Máy chủ chưa xác nhận CCDC đã được lưu.');
                return;
            }

            message.success(editingId === null ? `Đã thêm CCDC ${values.tool_code}.` : `Đã cập nhật CCDC ${values.tool_code}.`);
            notifyDataChanged();
            setCreateOpen(false);
            setEditingId(null);
            form.resetFields();
            await loadTools();
        } catch (error: any) {
            message.error(error?.response?.data?.message ?? 'Không thể lưu công cụ dụng cụ.');
            console.error(error);
        } finally {
            setCreateLoading(false);
        }
    };

    const handleEdit = (tool: ToolRecord) => {
        setEditingId(tool.id);
        form.setFieldsValue({
            tool_code: tool.tool_code,
            tool_name: tool.tool_name,
            purchase_date: tool.purchase_date ? dayjs(tool.purchase_date) : dayjs(),
            original_cost: Number(tool.original_cost || 0),
            allocation_months: Number(tool.allocation_months || 1),
            tool_account: tool.tool_account || '242',
            expense_account: tool.expense_account || '6423',
        });
        setCreateOpen(true);
    };

    useVoucherShortcuts({
        onSave: () => form.submit(),
        onPost: () => form.submit(),
        onClose: () => {
            if (!createLoading) setCreateOpen(false);
        },
        enabled: createOpen,
    });

    const handleDisable = async (tool: ToolRecord) => {
        if (!window.confirm(`Ngừng sử dụng CCDC ${tool.tool_code}?`)) return;
        try {
            await api.delete(`/tools/${tool.id}`, { data: { reason: 'Ngừng sử dụng từ danh mục CCDC' } });
            message.success(`Đã ngừng sử dụng CCDC ${tool.tool_code}.`);
            notifyDataChanged();
            await loadTools();
        } catch (error: any) {
            message.error(error?.response?.data?.message ?? 'Không thể ngừng sử dụng CCDC.');
        }
    };

    const handleWriteOff = async (tool: ToolRecord) => {
        if (!window.confirm(`Ghi giảm CCDC ${tool.tool_code} và kết thúc phân bổ?`)) return;
        try {
            const response = await api.delete(`/tools/${tool.id}`, {
                data: {
                    write_off: true,
                    write_off_date: dayjs().format('YYYY-MM-DD'),
                    reason: 'Ghi giảm từ danh mục CCDC',
                },
            });
            if (!isPersistedToolResponse(response?.data)) {
                message.error('Máy chủ chưa xác nhận CCDC đã ghi giảm.');
                return;
            }
            message.success(`Đã ghi giảm CCDC ${tool.tool_code} và ngừng phân bổ.`);
            notifyDataChanged();
            await loadTools();
        } catch (error: any) {
            message.error(error?.response?.data?.message ?? 'Không thể ghi giảm CCDC.');
        }
    };

    const handlePreview = async () => {
        try {
            const month = dayjs().format('YYYY-MM');
            const response = await api.get('/tools/allocate/preview', { params: { month } });
            const preview = response?.data?.data;
            message.info(preview ? `Tháng ${dayjs().format('MM/YYYY')}: ${preview.tool_count} CCDC, tổng phân bổ ${formatAmount(preview.total_amount)}.` : 'Không có dữ liệu xem trước.');
        } catch (error: any) {
            message.error(error?.response?.data?.message ?? 'Không thể xem trước phân bổ CCDC.');
        }
    };

    const handleRunAllocation = async () => {
        setLoading(true);
        try {
            const month = dayjs().format('YYYY-MM');
            const response = await api.post('/tools/allocate', { month });
            const allocationLog = response?.data?.data;

            // The endpoint returns `data: null` when there are no active tools
            // to allocate. A 2xx response alone is not evidence of persistence.
            if (!allocationLog || allocationLog.id === undefined || allocationLog.id === null) {
                message.info('Không có CCDC đủ điều kiện để phân bổ trong kỳ đã chọn.');
                return;
            }

            message.success(`Phân bổ CCDC tháng ${dayjs().format('MM/YYYY')} đã chạy và tự động hạch toán thành công!`);
            notifyDataChanged();
            await loadTools();
        } catch (error) {
            message.error('Có lỗi xảy ra khi tính phân bổ.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    const columns = [
        {
            title: 'Mã CCDC',
            dataIndex: 'tool_code',
            key: 'tool_code',
            render: (text: string) => <span className="misa-text-semibold text-purple-700">{text}</span>,
        },
        {
            title: 'Tên CCDC',
            dataIndex: 'tool_name',
            key: 'tool_name',
            render: (text: string) => <strong>{text}</strong>,
        },
        {
            title: 'Nguyên giá',
            dataIndex: 'original_cost',
            key: 'original_cost',
            align: 'right' as const,
            render: (value: number | string | null) => formatAmount(value),
        },
        {
            title: 'Đã phân bổ',
            dataIndex: 'accumulated_allocation',
            key: 'accumulated_allocation',
            align: 'right' as const,
            render: (value: number | string | null) => <Text type="danger">{formatAmount(value, true)}</Text>,
        },
        {
            title: 'Giá trị còn lại',
            dataIndex: 'remaining_value',
            key: 'remaining_value',
            align: 'right' as const,
            render: (value: number | string | null) => <Text type="success">{formatAmount(value)}</Text>,
        },
        {
            title: 'Kỳ phân bổ',
            dataIndex: 'allocation_months',
            key: 'allocation_months',
            align: 'center' as const,
            render: (value: number | string | null, record: ToolRecord) => {
                const months = Number(value || 0);
                const remaining = months > 0 && Number(record.monthly_allocation || 0) > 0
                    ? Math.ceil(Number(record.remaining_value || 0) / Number(record.monthly_allocation))
                    : 0;
                return `${remaining}/${months} tháng`;
            },
        },
        {
            title: 'Phân bổ hàng tháng',
            dataIndex: 'monthly_allocation',
            key: 'monthly_allocation',
            align: 'right' as const,
            render: (value: number | string | null) => formatAmount(value),
        },
        {
            title: 'Trạng thái',
            dataIndex: 'is_active',
            key: 'is_active',
            align: 'center' as const,
            width: 130,
            render: (active: boolean) => (
                <VoucherStatusBadge
                    status={active ? 'active' : 'disposed'}
                    customLabel={active ? 'Đang phân bổ' : 'Đã phân bổ hết'}
                />
            ),
        },
        {
            title: 'Chức năng',
            key: 'actions',
            align: 'center' as const,
            width: 140,
            render: (_value: unknown, record: ToolRecord) => {
                const menuItems: MenuProps['items'] = [
                    {
                        key: 'edit',
                        label: 'Sửa',
                        icon: <EditOutlined className="misa-icon-primary" />,
                        onClick: () => handleEdit(record)
                    },
                    ...(record.is_active ? [
                        {
                            key: 'disable',
                            label: 'Ngừng phân bổ',
                            icon: <StopOutlined className="misa-icon-warning" />,
                            onClick: () => void handleDisable(record)
                        },
                        {
                            key: 'writeoff',
                            label: 'Ghi giảm CCDC',
                            danger: true,
                            icon: <DeleteOutlined />,
                            onClick: () => void handleWriteOff(record)
                        }
                    ] : [])
                ];

                return (
                    <VoucherActionCell
                        primaryActionLabel="Sửa"
                        primaryAriaLabel={`Sửa ${record.tool_code}`}
                        onPrimaryAction={() => handleEdit(record)}
                        menuItems={menuItems}
                    />
                );
            },
        },
    ];

    return (
        <PageShell
            embedded={embedded}
            title={<PageHeader
                eyebrow="Tài sản & phân bổ"
                title={<><ToolOutlined className="mr-2 text-purple-600" />Công cụ dụng cụ</>}
                description="Quản lý chi phí trả trước (242) và phân bổ CCDC tự động hàng tháng."
            />}
            toolbar={<PageToolbar actions={<>
                <Button className="misa-btn-tool" icon={<PlusOutlined />} onClick={() => {
                    setEditingId(null);
                    form.setFieldsValue(defaultCreateValues);
                    setCreateOpen(true);
                }}>
                    Thêm CCDC
                </Button>
                <Button onClick={() => void handlePreview()} disabled={loading}>Xem trước phân bổ</Button>
                <Button type="primary" onClick={handleRunAllocation} icon={<SyncOutlined />} loading={loading} className="misa-btn-primary">
                    Chạy phân bổ tháng {dayjs().format('MM')}
                </Button>
            </>} />}
        >
            <DataTableSurface>
                {/* Search Toolbar */}
                <div className="p-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2 flex-1 max-w-md">
                        <Input
                            placeholder="Tìm theo mã hoặc tên CCDC..."
                            prefix={<SearchOutlined className="text-slate-400" />}
                            value={searchText}
                            onChange={e => setSearchText(e.target.value)}
                            allowClear
                            size="small"
                        />
                    </div>
                    <div className="flex items-center gap-2">
                        {embedded && (
                            <>
                                <Button size="small" icon={<EyeOutlined />} onClick={() => void handlePreview()} disabled={loading}>
                                    Xem trước
                                </Button>
                                <Button size="small" type="primary" onClick={handleRunAllocation} icon={<SyncOutlined />} loading={loading} className="misa-btn-primary">
                                    Phân bổ tháng {dayjs().format('MM')}
                                </Button>
                            </>
                        )}
                        <div className="text-xs text-slate-500 font-medium ml-2">
                            Tổng số: <span className="font-bold text-slate-700">{data.filter(t => !searchText.trim() || t.tool_code?.toLowerCase().includes(searchText.toLowerCase()) || t.tool_name?.toLowerCase().includes(searchText.toLowerCase())).length}</span> CCDC
                        </div>
                    </div>
                </div>

                <Table
                    columns={columns}
                    dataSource={data.filter(t => !searchText.trim() || t.tool_code?.toLowerCase().includes(searchText.toLowerCase()) || t.tool_name?.toLowerCase().includes(searchText.toLowerCase()))}
                    loading={loading}
                    rowKey="id"
                    pagination={{ pageSize: 10, showSizeChanger: true, showTotal: (total) => `Tổng ${total} bản ghi` }}
                    size="middle"
                    className="misa-voucher-table"
                    locale={{ emptyText: 'Chưa có CCDC.' }}
                />
            </DataTableSurface>

            <Modal
                className="misa-voucher-modal"
                closable={false}
                centered
                destroyOnHidden
                title={
                    <div className="misa-voucher-custom-header">
                        <div className="misa-voucher-header-left">
                            <span className="misa-voucher-title">
                                {editingId === null ? 'Ghi nhận Công cụ dụng cụ (CCDC)' : 'Sửa Công cụ dụng cụ (CCDC)'}
                            </span>
                            <span className="text-xs bg-blue-50 text-blue-700 px-2 py-0.5 rounded font-medium border border-blue-200 ml-3">
                                Chi phí trả trước (TK 242)
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
                                onClick={() => {
                                    if (!createLoading) setCreateOpen(false);
                                }}
                            >
                                <CloseOutlined />
                            </button>
                        </div>
                    </div>
                }
                open={createOpen}
                onCancel={() => {
                    if (!createLoading) setCreateOpen(false);
                }}
                width="min(1820px, calc(100vw - 32px))"
                footer={
                    <div className="misa-voucher-fixed-footer">
                        <div className="misa-footer-left">
                            <Button
                                className="misa-btn-footer-cancel"
                                icon={<QuestionCircleOutlined />}
                                onClick={() => message.info('Trợ giúp: Khai báo chi phí trả trước (TK 242) và phân bổ dần vào chi phí sản xuất kinh doanh theo chuẩn TT200/TT133.')}
                            >
                                Giúp
                            </Button>
                        </div>
                        <div className="misa-footer-right">
                            <Button
                                onClick={() => {
                                    if (!createLoading) setCreateOpen(false);
                                }}
                                className="misa-btn-footer-cancel"
                            >
                                Hủy
                            </Button>
                            <Button
                                type="primary"
                                onClick={() => form.submit()}
                                loading={createLoading}
                                className="misa-btn-footer-save-add"
                            >
                                {editingId === null ? 'Cất' : 'Cất thay đổi'}
                            </Button>
                        </div>
                    </div>
                }
            >
                <ModalFrame>
                    <Form<ToolCreateValues>
                        form={form}
                        layout="vertical"
                        initialValues={defaultCreateValues}
                        onFinish={handleCreate}
                        size="small"
                        className="misa-voucher-form-container"
                    >
                        <div className="misa-voucher-scroll-body">
                            <MisaMasterCard>
                                <MisaMasterCard.Left>
                                    <div className="text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">Thông tin công cụ dụng cụ</div>
                                    <div className="grid grid-cols-12 gap-x-3 gap-y-2 mb-2">
                                        <div className="col-span-4">
                                            <Form.Item
                                                name="tool_code"
                                                label="Mã CCDC"
                                                rules={[{ required: true, whitespace: true, message: 'Nhập mã CCDC.' }]}
                                                className="mb-0"
                                            >
                                                <Input placeholder="Ví dụ: CCDC-001" style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-8">
                                            <Form.Item
                                                name="tool_name"
                                                label="Tên CCDC"
                                                rules={[{ required: true, whitespace: true, message: 'Nhập tên CCDC.' }]}
                                                className="mb-0"
                                            >
                                                <Input placeholder="Tên công cụ, dụng cụ" style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-6">
                                            <Form.Item
                                                name="tool_account"
                                                label="Tài khoản CCDC"
                                                rules={[{ required: true, whitespace: true, message: 'Nhập tài khoản CCDC.' }]}
                                                className="mb-0"
                                            >
                                                <Input placeholder="242" style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                        <div className="col-span-6">
                                            <Form.Item
                                                name="expense_account"
                                                label="Tài khoản chi phí"
                                                rules={[{ required: true, whitespace: true, message: 'Nhập tài khoản chi phí.' }]}
                                                className="mb-0"
                                            >
                                                <Input placeholder="6423" style={{ width: '100%' }} />
                                            </Form.Item>
                                        </div>
                                    </div>
                                </MisaMasterCard.Left>
                                <MisaMasterCard.Right>
                                    <MisaMasterCard.MetaRow label="Ngày ghi nhận" required>
                                        <Form.Item
                                            name="purchase_date"
                                            noStyle
                                            rules={[{ required: true, message: 'Chọn ngày ghi nhận.' }]}
                                        >
                                            <DatePicker format="DD/MM/YYYY" className="w-full" style={{ width: '100%' }} />
                                        </Form.Item>
                                    </MisaMasterCard.MetaRow>
                                    <MisaMasterCard.MetaRow label="Số tháng PB" required>
                                        <Form.Item
                                            name="allocation_months"
                                            noStyle
                                            rules={[{ required: true, message: 'Nhập số tháng phân bổ.' }]}
                                        >
                                            <InputNumber min={1} max={240} className="w-full" style={{ width: '100%' }} addonAfter="tháng" />
                                        </Form.Item>
                                    </MisaMasterCard.MetaRow>
                                    <MisaTotalCard label="NGUYÊN GIÁ CCDC" value={Number(watchedCost || 0)} />
                                </MisaMasterCard.Right>
                            </MisaMasterCard>

                            <div className="mt-3 bg-white rounded-md border border-slate-200 overflow-hidden p-3">
                                <h4 className="font-semibold text-slate-700 text-sm mb-2">Thiết lập giá trị & phân bổ</h4>
                                <div className="grid grid-cols-12 gap-3">
                                    <div className="col-span-6">
                                        <Form.Item
                                            name="original_cost"
                                            label="Nguyên giá"
                                            rules={[{ required: true, message: 'Nhập nguyên giá.' }]}
                                            className="mb-0"
                                        >
                                            <InputNumber min={0} className="w-full" style={{ width: '100%' }} addonAfter="VND" formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')} />
                                        </Form.Item>
                                    </div>
                                    <div className="col-span-6">
                                        <Form.Item label="Mức phân bổ hàng tháng (ước tính)" className="mb-0">
                                            <InputNumber
                                                value={monthlyAllocationEstimate}
                                                disabled
                                                className="w-full"
                                                style={{ width: '100%' }}
                                                addonAfter="VND/tháng"
                                                formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                                            />
                                        </Form.Item>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </Form>
                </ModalFrame>
            </Modal>
        </PageShell>
    );
}
