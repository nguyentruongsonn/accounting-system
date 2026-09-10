import React, { useState } from 'react';
import { Alert, Button, Table, Input, Select, Dropdown } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import type { ColumnsType } from 'antd/es/table';
import type { MenuProps } from 'antd';
import {
    PlusOutlined,
    DeleteOutlined,
    DownOutlined,
    ReloadOutlined,
    PrinterOutlined,
    SearchOutlined,
    EditOutlined,
    CheckCircleOutlined,
    CloseCircleOutlined,
    CopyOutlined,
    EyeOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { formatDate } from '../../utils/dateUtils';
import { VoucherPrintModal, type VoucherPrintData, useVoucherShortcuts } from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { useSourceRecordDeepLink } from '../reports/useSourceRecordDeepLink';
import { PurchaseDiscountModal } from './modals/PurchaseDiscountModal';
import type { PurchaseDiscountRecord, PurchaseDiscountLine } from './types';

export const PurchaseDiscounts: React.FC = () => {
    const [isModalVisible, setIsModalVisible] = useState<boolean>(false);
    const [isViewMode, setIsViewMode] = useState<boolean>(false);
    const [selectedRecord, setSelectedRecord] = useState<PurchaseDiscountRecord | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [datePreset, setDatePreset] = useState<string>('Tháng này');
    const [searchText, setSearchText] = useState<string>('');

    // Print Modal State
    const [isPrintModalOpen, setIsPrintModalOpen] = useState<boolean>(false);
    const [printData, setPrintData] = useState<VoucherPrintData | null>(null);

    const queryClient = useQueryClient();

    const persistedActionId = (response: any) => response?.data?.data?.id ?? response?.data?.id;
    const hasDeleteEvidence = (response: any) => typeof response?.data?.message === 'string' && response.data.message.trim() !== '';

    const { data: discounts = [], isLoading, isError: isListError, refetch: refetchList } = useQuery<PurchaseDiscountRecord[]>({
        queryKey: ['purchase-discounts'],
        queryFn: async () => {
            const { data } = await api.get('/purchase/discounts');
            if (Array.isArray(data)) return data;
            if (Array.isArray(data?.data)) return data.data;
            throw new Error('Máy chủ không trả về danh sách chứng từ giảm giá hợp lệ.');
        },
    });

    const postMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/purchase/discounts/${id}/post`);
        },
        onSuccess: (response) => {
            if (persistedActionId(response) === undefined || persistedActionId(response) === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã cập nhật; không thể xác nhận ghi sổ.');
                return;
            }
            message.success('Ghi sổ chứng từ giảm giá hàng mua thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi ghi sổ!');
        }
    });

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/purchase/discounts/${id}/unpost`);
        },
        onSuccess: (response) => {
            if (persistedActionId(response) === undefined || persistedActionId(response) === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã cập nhật; không thể xác nhận bỏ ghi sổ.');
                return;
            }
            message.success('Bỏ ghi sổ chứng từ giảm giá hàng mua thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi bỏ ghi sổ!');
        }
    });

    const voidMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/purchase/discounts/${id}/void`);
        },
        onSuccess: (response) => {
            if (persistedActionId(response) === undefined || persistedActionId(response) === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã cập nhật; không thể xác nhận hủy.');
                return;
            }
            message.success('Hủy chứng từ giảm giá hàng mua thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi hủy chứng từ!');
        }
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/purchase/discounts/${id}/duplicate`);
        },
        onSuccess: (response) => {
            if (persistedActionId(response) === undefined || persistedActionId(response) === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã nhân bản; không thể báo thành công.');
                return;
            }
            message.success('Nhân bản chứng từ giảm giá hàng mua thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi nhân bản chứng từ!');
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.delete(`/purchase/discounts/${id}`);
        },
        onSuccess: (response) => {
            if (!hasDeleteEvidence(response)) {
                message.error('Máy chủ không xác nhận đã xóa chứng từ giảm giá; không thể báo thành công.');
                return;
            }
            message.success('Đã xóa chứng từ giảm giá hàng mua thành công!');
            queryClient.invalidateQueries({ queryKey: ['purchase-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi xóa chứng từ!');
        }
    });

    const handleOpenCreateModal = () => {
        setSelectedRecord(null);
        setEditingId(null);
        setIsViewMode(false);
        setIsModalVisible(true);
    };

    const handleOpenViewRecord = (record: PurchaseDiscountRecord) => {
        setSelectedRecord(record);
        setEditingId(record.id);
        setIsViewMode(true);
        setIsModalVisible(true);
    };

    useSourceRecordDeepLink({
        records: discounts,
        isLoading,
        isError: isListError,
        onOpen: handleOpenViewRecord,
        onMissing: () => message.error('Không tìm thấy chứng từ giảm giá hàng mua nguồn.'),
    });

    const handleOpenEditRecord = (record: PurchaseDiscountRecord) => {
        if (record.is_posted) {
            Modal.confirm({
                title: 'Xác nhận bỏ ghi sổ để sửa',
                content: `Chứng từ ${record.voucher_number} đã ghi sổ. Bạn có muốn BỎ GHI SỔ để chỉnh sửa không?`,
                okText: 'Bỏ ghi và Sửa',
                okType: 'primary',
                cancelText: 'Hủy',
                onOk: async () => {
                    await unpostMutation.mutateAsync(record.id);
                    setSelectedRecord({ ...record, is_posted: false });
                    setEditingId(record.id);
                    setIsViewMode(false);
                    setIsModalVisible(true);
                }
            });
        } else {
            setSelectedRecord(record);
            setEditingId(record.id);
            setIsViewMode(false);
            setIsModalVisible(true);
        }
    };

    const handleOpenPrintRecord = (record: PurchaseDiscountRecord) => {
        const safeLines = record.lines ?? [];
        if (safeLines.length === 0) {
            message.warning('Không thể in chứng từ vì chưa có dòng chi tiết được lưu.');
            return;
        }

        setPrintData({
            voucher_number: record.voucher_number,
            voucher_date: record.voucher_date || record.accounting_date,
            customer_name: record.supplier_name || record.supplier?.name,
            contact_name: record.deliverer_name || record.supplier_name || record.supplier?.name,
            address: record.supplier_address || record.supplier?.address,
            tax_code: record.tax_code || record.supplier?.tax_code,
            description: record.description,
            sub_total: Number(record.sub_total || record.total_amount || 0),
            tax_amount: Number(record.tax_amount || 0),
            total_amount: Number(record.total_amount || 0),
            lines: safeLines
        });
        setIsPrintModalOpen(true);
    };

    const handleDeleteRecord = (record: PurchaseDiscountRecord) => {
        Modal.confirm({
            title: 'Xác nhận xóa chứng từ giảm giá',
            content: `Bạn có chắc muốn xóa chứng từ ${record.voucher_number}? Thao tác này không thể hoàn tác.`,
            okText: 'Xóa',
            okType: 'danger',
            cancelText: 'Hủy',
            onOk: () => deleteMutation.mutate(record.id)
        });
    };

    useVoucherShortcuts({
        onClose: () => setIsModalVisible(false),
        enabled: !isModalVisible
    });

    const columns: ColumnsType<PurchaseDiscountRecord> = [
        {
            title: 'Ngày hạch toán',
            dataIndex: 'accounting_date',
            key: 'accounting_date',
            width: 110,
            align: 'center',
            render: (val: string) => formatDate(val)
        },
        {
            title: 'Ngày chứng từ',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 110,
            align: 'center',
            render: (val: string) => formatDate(val)
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 125,
            render: (text: string, record: PurchaseDiscountRecord) => (
                <button
                    type="button"
                    className="misa-btn-link-action-bold"
                    onClick={() => handleOpenViewRecord(record)}
                >
                    {text}
                </button>
            )
        },
        {
            title: 'Nhà cung cấp',
            dataIndex: 'supplier_name',
            key: 'supplier_name',
            width: 240,
            render: (name: string, r: PurchaseDiscountRecord) => (
                <div className="misa-cell-title-box">
                    <div className="misa-cell-main-title-dark">{name || r.supplier?.name || '—'}</div>
                    <div className="misa-cell-sub-title">MST: {r.tax_code || r.supplier?.tax_code || '-'}</div>
                </div>
            )
        },
        {
            title: 'Diễn giải',
            dataIndex: 'description',
            key: 'description'
        },
        {
            title: 'Tổng tiền giảm giá',
            dataIndex: 'total_amount',
            key: 'total_amount',
            align: 'right',
            width: 160,
            render: (amount: number, record: PurchaseDiscountRecord) => {
                const total = amount || record.lines?.reduce((s: number, l: PurchaseDiscountLine) => s + (Number(l?.amount) || 0) + (Number(l?.tax_amount) || 0), 0) || 0;
                return <span className="misa-table-amount-bold text-danger">-{new Intl.NumberFormat('vi-VN').format(total)} ₫</span>;
            }
        },
        {
            title: 'Trạng thái ghi sổ',
            dataIndex: 'is_posted',
            key: 'is_posted',
            align: 'center',
            width: 120,
            render: (posted: boolean) => (
                <span className={`misa-apple-pill ${posted ? 'misa-apple-pill-green' : 'misa-apple-pill-orange'}`}>
                    <span className="misa-apple-pill-dot" />
                    {posted ? 'Đã ghi sổ' : 'Bản nháp'}
                </span>
            )
        },
        {
            title: 'Chức năng',
            key: 'action',
            align: 'center',
            width: 140,
            render: (_: unknown, record: PurchaseDiscountRecord) => {
                const menuItems: MenuProps['items'] = [
                    {
                        key: 'view',
                        label: 'Xem chứng từ',
                        icon: <EyeOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenViewRecord(record)
                    },
                    record.is_posted ? {
                        key: 'unpost',
                        label: 'Bỏ ghi sổ',
                        icon: <CloseCircleOutlined className="misa-icon-warning" />,
                        onClick: () => unpostMutation.mutate(record.id)
                    } : {
                        key: 'post',
                        label: 'Ghi sổ',
                        icon: <CheckCircleOutlined className="misa-icon-success" />,
                        onClick: () => postMutation.mutate(record.id)
                    },
                    {
                        key: 'edit',
                        label: 'Chỉnh sửa',
                        icon: <EditOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenEditRecord(record)
                    },
                    {
                        key: 'duplicate',
                        label: 'Nhân bản',
                        icon: <CopyOutlined className="misa-icon-info" />,
                        onClick: () => duplicateMutation.mutate(record.id)
                    },
                    {
                        key: 'void',
                        label: 'Hủy chứng từ',
                        icon: <CloseCircleOutlined className="misa-icon-warning" />,
                        onClick: () => voidMutation.mutate(record.id)
                    },
                    {
                        key: 'print',
                        label: 'In chứng từ',
                        icon: <PrinterOutlined className="misa-icon-info" />,
                        onClick: () => handleOpenPrintRecord(record)
                    },
                    {
                        type: 'divider'
                    },
                    {
                        key: 'delete',
                        label: 'Xóa chứng từ',
                        icon: <DeleteOutlined className="misa-icon-danger" />,
                        danger: true,
                        onClick: () => handleDeleteRecord(record)
                    }
                ];

                return (
                    <div className="misa-table-action-group">
                        <button
                            type="button"
                            className="misa-btn-link-action"
                            onClick={() => handleOpenViewRecord(record)}
                        >
                            Xem
                        </button>
                        <Dropdown menu={{ items: menuItems }} trigger={['click']} placement="bottomRight">
                            <button
                                type="button"
                                className="misa-btn-dropdown-trigger"
                                aria-label="Thao tác"
                            >
                                <DownOutlined style={{ fontSize: 10 }} />
                            </button>
                        </Dropdown>
                    </div>
                );
            }
        }
    ];

    const filteredDiscounts = discounts.filter((r: PurchaseDiscountRecord) => {
        if (!searchText) return true;
        const s = searchText.toLowerCase();
        return (
            r.voucher_number?.toLowerCase().includes(s) ||
            r.supplier_name?.toLowerCase().includes(s) ||
            r.description?.toLowerCase().includes(s) ||
            r.tax_code?.toLowerCase().includes(s)
        );
    });

    const totalFilteredAmount = filteredDiscounts.reduce((acc, curr) => acc + (Number(curr.total_amount) || 0), 0);

    return (
        <PageShell
            className="apple-ledger-page"
            title={<PageHeader
                eyebrow="MUA HÀNG"
                title="Giảm giá hàng mua"
                description="Quản lý các chứng từ giảm giá hàng mua từ nhà cung cấp."
            />}
            toolbar={(
                <PageToolbar
                    filters={(
                        <div className="ui-page-toolbar__filter-group flex items-center gap-2">
                            <Input
                                placeholder="Tìm theo số CT, tên NCC, diễn giải..."
                                prefix={<SearchOutlined className="misa-color-muted" />}
                                value={searchText}
                                onChange={e => setSearchText(e.target.value)}
                                className="misa-w-280"
                                allowClear
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
                                    { value: 'Năm nay', label: 'Năm nay' }
                                ]}
                            />
                        </div>
                    )}
                    actions={(
                        <div className="ui-page-toolbar__action-group flex items-center gap-2">
                            <Button
                                icon={<ReloadOutlined />}
                                className="misa-btn-tool"
                                onClick={() => void refetchList()}
                                title="Tải lại dữ liệu (F5)"
                            />
                            <Button
                                icon={<PrinterOutlined />}
                                className="misa-btn-tool"
                                onClick={() => window.print()}
                                title="In danh sách"
                            />
                            <Button
                                type="primary"
                                className="misa-btn-primary"
                                icon={<PlusOutlined />}
                                onClick={handleOpenCreateModal}
                            >
                                Thêm giảm giá hàng mua
                            </Button>
                        </div>
                    )}
                />
            )}
        >

            {isListError && (
                <Alert
                    className="mb-3"
                    type="error"
                    showIcon
                    message="Không thể tải danh sách chứng từ"
                    description="Dữ liệu hiển thị không được thay bằng danh sách rỗng. Kiểm tra kết nối hoặc quyền truy cập rồi thử lại."
                    action={<Button size="small" onClick={() => void refetchList()}>Thử lại</Button>}
                />
            )}

            {/* Main Table */}
            <DataTableSurface
                className="purchase-discounts-table-surface"
                summary={(
                    <div className="misa-grid-summary-footer" style={{ marginTop: 12, padding: '10px 16px', background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 6, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                        <div style={{ color: '#64748b', fontSize: 13 }}>
                            Tổng số: <strong style={{ color: '#0f172a' }}>{filteredDiscounts.length}</strong> chứng từ
                        </div>
                        <div style={{ fontSize: 13 }}>
                            Tổng tiền giảm giá: <strong style={{ color: '#dc2626', fontSize: 15 }}>-{new Intl.NumberFormat('vi-VN').format(totalFilteredAmount)} ₫</strong>
                        </div>
                    </div>
                )}
            >
                <Table
                    columns={columns}
                    dataSource={filteredDiscounts}
                    rowKey="id"
                    loading={isLoading}
                    pagination={filteredDiscounts.length > 0 ? {
                        pageSize: 15,
                        showSizeChanger: true,
                        showTotal: (total, range) => `${range[0]}-${range[1]} của ${total} chứng từ`,
                    } : false}
                    locale={{ emptyText: isLoading ? 'Đang tải chứng từ…' : 'Chưa có chứng từ giảm giá mua hàng.' }}
                    size="middle"
                    bordered
                    scroll={{ x: 1200 }}
                />

            </DataTableSurface>

            {/* Modal Create/Edit/View */}
            {isModalVisible && (
                <PurchaseDiscountModal
                    open={isModalVisible}
                    onClose={() => setIsModalVisible(false)}
                    recordId={editingId}
                    initialRecord={selectedRecord}
                    isViewMode={isViewMode}
                    onSuccess={() => {
                        queryClient.invalidateQueries({ queryKey: ['purchase-discounts'] });
                    }}
                />
            )}

            {/* Print Modal */}
            {isPrintModalOpen && printData && (
                <VoucherPrintModal
                    open={isPrintModalOpen}
                    onCancel={() => setIsPrintModalOpen(false)}
                    data={printData || undefined}
                />
            )}
        </PageShell>
    );
};
export default PurchaseDiscounts;
