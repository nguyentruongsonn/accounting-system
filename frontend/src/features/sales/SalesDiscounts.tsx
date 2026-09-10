import React, { useState } from 'react';
import { Alert, Table, Button, Input, Select, Dropdown } from 'antd';
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
    StopOutlined, 
    EyeOutlined 
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import { formatDate } from '../../utils/dateUtils';
import { VoucherPrintModal, type VoucherPrintData } from '../../components/misa';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { useSourceRecordDeepLink } from '../reports/useSourceRecordDeepLink';
import { SalesDiscountModal } from './modals/SalesDiscountModal';
import type { SalesDiscountRecord, SalesDiscountLine } from './types';

export const SalesDiscounts: React.FC = () => {
    const [isModalVisible, setIsModalVisible] = useState<boolean>(false);
    const [isViewMode, setIsViewMode] = useState<boolean>(false);
    const [selectedRecord, setSelectedRecord] = useState<SalesDiscountRecord | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [datePreset, setDatePreset] = useState<string>('Tháng này');
    const [searchText, setSearchText] = useState<string>('');

    // Print Modal State
    const [isPrintModalOpen, setIsPrintModalOpen] = useState<boolean>(false);
    const [printData, setPrintData] = useState<VoucherPrintData | null>(null);

    const queryClient = useQueryClient();

    const persistedActionId = (response: any) => response?.data?.data?.id ?? response?.data?.id;
    const hasDeleteEvidence = (response: any) => typeof response?.data?.message === 'string' && response.data.message.trim() !== '';

    const { data: discounts = [], isLoading, isError: isListError, refetch: refetchList } = useQuery<SalesDiscountRecord[]>({
        queryKey: ['sales-discounts'],
        queryFn: async () => {
            const { data } = await api.get('/sales/discounts');
            if (Array.isArray(data)) return data;
            if (Array.isArray(data?.data)) return data.data;
            throw new Error('Máy chủ không trả về danh sách chứng từ giảm giá hợp lệ.');
        },
    });

    const postMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/sales/discounts/${id}/post`);
        },
        onSuccess: (response) => {
            if (persistedActionId(response) === undefined || persistedActionId(response) === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã cập nhật; không thể xác nhận ghi sổ.');
                return;
            }
            message.success('Ghi sổ chứng từ giảm giá hàng bán thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi ghi sổ!');
        }
    });

    const unpostMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/sales/discounts/${id}/unpost`);
        },
        onSuccess: (response) => {
            if (persistedActionId(response) === undefined || persistedActionId(response) === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã cập nhật; không thể xác nhận bỏ ghi sổ.');
                return;
            }
            message.success('Bỏ ghi sổ chứng từ giảm giá hàng bán thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi bỏ ghi sổ!');
        }
    });

    const voidMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/sales/discounts/${id}/void`);
        },
        onSuccess: (response) => {
            if (persistedActionId(response) === undefined || persistedActionId(response) === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã cập nhật; không thể xác nhận hủy.');
                return;
            }
            message.success('Hủy chứng từ giảm giá hàng bán thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi hủy chứng từ!');
        }
    });

    const duplicateMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.post(`/sales/discounts/${id}/duplicate`);
        },
        onSuccess: (response) => {
            if (persistedActionId(response) === undefined || persistedActionId(response) === null) {
                message.error('Máy chủ không trả về chứng từ giảm giá đã nhân bản; không thể báo thành công.');
                return;
            }
            message.success('Nhân bản chứng từ giảm giá hàng bán thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-discounts'] });
        },
        onError: (err: unknown) => {
            const apiErr = err as { response?: { data?: { message?: string; error?: string } } };
            message.error(apiErr?.response?.data?.error || apiErr?.response?.data?.message || 'Lỗi khi nhân bản chứng từ!');
        }
    });

    const deleteMutation = useMutation({
        mutationFn: async (id: number) => {
            return api.delete(`/sales/discounts/${id}`);
        },
        onSuccess: (response) => {
            if (!hasDeleteEvidence(response)) {
                message.error('Máy chủ không xác nhận đã xóa chứng từ giảm giá; không thể báo thành công.');
                return;
            }
            message.success('Đã xóa chứng từ giảm giá hàng bán thành công!');
            queryClient.invalidateQueries({ queryKey: ['sales-discounts'] });
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

    const handleOpenViewRecord = (record: SalesDiscountRecord) => {
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
        onMissing: () => message.error('Không tìm thấy chứng từ giảm giá hàng bán nguồn.'),
    });

    const handleOpenEditRecord = (record: SalesDiscountRecord) => {
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

    const handleOpenPrintRecord = (record: SalesDiscountRecord) => {
        const safeLines = record.lines ?? [];
        if (safeLines.length === 0) {
            message.warning('Không thể in chứng từ vì chưa có dòng chi tiết được lưu.');
            return;
        }

        setPrintData({
            voucher_number: record.voucher_number,
            voucher_date: record.voucher_date || record.accounting_date,
            customer_name: record.customer_name || record.customer?.name,
            contact_name: record.receiver_name || record.customer_name || record.customer?.name,
            address: record.customer_address || record.customer?.address,
            tax_code: record.tax_code || record.customer?.tax_code,
            description: record.description,
            sub_total: Number(record.sub_total || record.total_amount || 0),
            tax_amount: Number(record.tax_amount || 0),
            total_amount: Number(record.total_amount || 0),
            lines: safeLines
        });
        setIsPrintModalOpen(true);
    };

    const handleDeleteRecord = (record: SalesDiscountRecord) => {
        Modal.confirm({
            title: 'Xác nhận xóa chứng từ giảm giá',
            content: `Bạn có chắc muốn xóa chứng từ ${record.voucher_number}? Thao tác này không thể hoàn tác.`,
            okText: 'Xóa',
            okType: 'danger',
            cancelText: 'Hủy',
            onOk: () => deleteMutation.mutate(record.id)
        });
    };

    const columns: ColumnsType<SalesDiscountRecord> = [
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
            render: (text: string, record: SalesDiscountRecord) => (
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
            title: 'Khách hàng',
            dataIndex: 'customer_name',
            key: 'customer_name',
            width: 240,
            render: (name: string, r: SalesDiscountRecord) => (
                <div className="misa-cell-title-box">
                    <div className="misa-cell-main-title-dark">{name || r.customer?.name || '—'}</div>
                    <div className="misa-cell-sub-title">MST: {r.tax_code || r.customer?.tax_code || '-'}</div>
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
            render: (amount: number, record: SalesDiscountRecord) => {
                const total = amount || record.lines?.reduce((s: number, l: SalesDiscountLine) => s + (Number(l?.amount) || 0) + (Number(l?.tax_amount) || 0), 0) || 0;
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
            render: (_: unknown, record: SalesDiscountRecord) => {
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
                        label: record.is_posted ? 'Sửa (Bỏ ghi)' : 'Sửa',
                        icon: <EditOutlined className="misa-icon-primary" />,
                        onClick: () => handleOpenEditRecord(record)
                    },
                    {
                        key: 'duplicate',
                        label: 'Nhân bản',
                        icon: <CopyOutlined className="misa-icon-primary" />,
                        onClick: () => duplicateMutation.mutate(record.id)
                    },
                    {
                        key: 'print',
                        label: 'In Chứng từ giảm giá (01-VT)',
                        icon: <PrinterOutlined className="misa-icon-success" />,
                        onClick: () => handleOpenPrintRecord(record)
                    },
                    {
                        key: 'void',
                        label: 'Hủy chứng từ',
                        icon: <StopOutlined className="misa-icon-warning" />,
                        onClick: () => voidMutation.mutate(record.id)
                    },
                    { type: 'divider' },
                    {
                        key: 'delete',
                        label: 'Xóa',
                        danger: true,
                        disabled: Boolean(record.is_posted),
                        icon: <DeleteOutlined />,
                        onClick: () => handleDeleteRecord(record)
                    }
                ];

                return (
                    <div className="misa-inline-flex-center misa-gap-4">
                        <Button 
                            type="link" 
                            size="small" 
                            className="misa-text-primary misa-text-semibold misa-px-4"
                            onClick={() => record.is_posted ? handleOpenViewRecord(record) : handleOpenEditRecord(record)}
                        >
                            {record.is_posted ? 'Xem' : 'Sửa'}
                        </Button>
                        <Dropdown trigger={['click']} menu={{ items: menuItems }}>
                            <Button type="link" size="small" className="misa-btn-action-more">
                                <DownOutlined className="misa-icon-xs" />
                            </Button>
                        </Dropdown>
                    </div>
                );
            }
        }
    ];

    const safeDiscountsList = Array.isArray(discounts) ? discounts : [];
    const filteredDiscounts = safeDiscountsList.filter((disc: SalesDiscountRecord) => {
        if (!searchText) return true;
        const matchNo = disc.voucher_number?.toLowerCase().includes(searchText.toLowerCase());
        const matchCust = disc.customer_name?.toLowerCase().includes(searchText.toLowerCase());
        const matchDesc = disc.description?.toLowerCase().includes(searchText.toLowerCase());
        return Boolean(matchNo || matchCust || matchDesc);
    });

    return (
        <PageShell title={<PageHeader eyebrow="Bán hàng" title="Giảm giá hàng bán" description="Danh sách chứng từ chiết khấu thương mại bán ra." />}>
            {/* List Toolbar */}
            <PageToolbar
                filters={(
                    <div className="misa-toolbar-left">
                    <div className="misa-search-box">
                        <Input 
                            placeholder="Tìm số chứng từ, khách hàng..." 
                            prefix={<SearchOutlined className="misa-header-qrcode" />}
                            className="misa-input"
                            value={searchText}
                            onChange={e => setSearchText(e.target.value)}
                            allowClear
                        />
                    </div>
                    <Select 
                        value={datePreset} 
                        onChange={setDatePreset} 
                        className="misa-date-select"
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
                    <div className="misa-toolbar-right">
                    <button 
                        type="button" 
                        className="misa-btn-tool" 
                        title="Làm mới (F5)" 
                        onClick={() => queryClient.invalidateQueries({ queryKey: ['sales-discounts'] })}
                    >
                        <ReloadOutlined />
                    </button>
                    <Button 
                        type="primary" 
                        icon={<PlusOutlined />}
                        className="misa-btn-primary"
                        onClick={handleOpenCreateModal}
                    >
                        Thêm giảm giá hàng bán
                    </Button>
                    </div>
                )}
            />

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

            {/* List Table */}
            <DataTableSurface className="sales-discounts-table-surface">
                <Table 
                    columns={columns} 
                    dataSource={filteredDiscounts} 
                    rowKey="id"
                    loading={isLoading}
                    pagination={{ pageSize: 20, showSizeChanger: true }}
                    size="small"
                    bordered
                    scroll={{ x: 'max-content', y: 'calc(100vh - 280px)' }}
                />
            </DataTableSurface>

            {/* Sales Discount Master-Detail Modal */}
            <SalesDiscountModal
                open={isModalVisible}
                onClose={() => setIsModalVisible(false)}
                recordId={editingId}
                initialRecord={selectedRecord}
                isViewMode={isViewMode}
                onSuccess={() => queryClient.invalidateQueries({ queryKey: ['sales-discounts'] })}
            />

            {/* Voucher Print Modal */}
            <VoucherPrintModal
                open={isPrintModalOpen}
                onClose={() => setIsPrintModalOpen(false)}
                data={printData || undefined}
            />
        </PageShell>
    );
};

export default SalesDiscounts;
