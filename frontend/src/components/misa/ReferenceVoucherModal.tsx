import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { Alert, Select, DatePicker, Button, Input, Table, Tooltip } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { toast as message } from '../feedback/toast';
import Modal from '../layout/AppModal';
import { 
    SearchOutlined, 
    QuestionCircleOutlined, 
    CloseOutlined, 
    InboxOutlined 
} from '@ant-design/icons';
import dayjs from 'dayjs';
import isBetween from 'dayjs/plugin/isBetween';
import quarterOfYear from 'dayjs/plugin/quarterOfYear';
import { formatDate } from '../../utils/dateUtils';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';

dayjs.extend(isBetween);
dayjs.extend(quarterOfYear);

interface ReferenceVoucherModalProps {
    open: boolean;
    onCancel: () => void;
    onSelect: (selectedVouchers: any[]) => void;
    /** Bank/deposit references are outside the internal cash-only pilot. */
    includeBankVouchers?: boolean;
}

const PERIOD_OPTIONS = [
    { value: 'Hôm nay', label: 'Hôm nay' },
    { value: 'Tuần này', label: 'Tuần này' },
    { value: 'Đầu tuần đến hiện tại', label: 'Đầu tuần đến hiện tại' },
    { value: 'Tuần trước', label: 'Tuần trước' },
    { value: 'Tháng này', label: 'Tháng này' },
    { value: 'Đầu tháng đến hiện tại', label: 'Đầu tháng đến hiện tại' },
    { value: 'Tháng trước', label: 'Tháng trước' },
    { value: 'Quý này', label: 'Quý này' },
    { value: 'Đầu quý đến hiện tại', label: 'Đầu quý đến hiện tại' },
    { value: 'Quý trước', label: 'Quý trước' },
    { value: '6 tháng đầu năm', label: '6 tháng đầu năm' },
    { value: '6 tháng cuối năm', label: '6 tháng cuối năm' },
    { value: 'Năm nay', label: 'Năm nay' },
    { value: 'Đầu năm đến hiện tại', label: 'Đầu năm đến hiện tại' },
    { value: 'Năm trước', label: 'Năm trước' },
    { value: 'Tùy chọn', label: 'Tùy chọn' },
];

export const VOUCHER_TYPE_GROUPS = [
    {
        label: 'Tất cả',
        options: [
            { value: 'Tất cả', label: 'Tất cả chứng từ' },
        ]
    },
    {
        label: 'Tài sản cố định',
        options: [
            { value: 'Ghi tăng tài sản cố định', label: 'Ghi tăng tài sản cố định' },
            { value: 'Ghi giảm tài sản cố định', label: 'Ghi giảm tài sản cố định' },
            { value: 'Đánh giá lại tài sản cố định', label: 'Đánh giá lại tài sản cố định' },
            { value: 'Điều chuyển tài sản', label: 'Điều chuyển tài sản' },
            { value: 'Bảng tính khấu hao tài sản cố định', label: 'Bảng tính khấu hao tài sản cố định' },
        ]
    },
    {
        label: 'Công cụ dụng cụ',
        options: [
            { value: 'Ghi tăng công cụ dụng cụ', label: 'Ghi tăng công cụ dụng cụ' },
            { value: 'Ghi giảm công cụ dụng cụ', label: 'Ghi giảm công cụ dụng cụ' },
            { value: 'Phân bổ công cụ dụng cụ', label: 'Phân bổ công cụ dụng cụ' },
            { value: 'Điều chuyển công cụ dụng cụ', label: 'Điều chuyển công cụ dụng cụ' },
            { value: 'Kiểm kê công cụ dụng cụ', label: 'Kiểm kê công cụ dụng cụ' },
        ]
    },
    {
        label: 'Bán hàng',
        options: [
            { value: 'Báo giá', label: 'Báo giá' },
            { value: 'Đơn đặt hàng', label: 'Đơn đặt hàng' },
            { value: 'Hợp đồng bán', label: 'Hợp đồng bán' },
            { value: 'Hóa đơn bán hàng', label: 'Hóa đơn bán hàng' },
            { value: 'Chứng từ bán hàng', label: 'Chứng từ bán hàng' },
            { value: 'Chứng từ giảm giá hàng bán', label: 'Chứng từ giảm giá hàng bán' },
            { value: 'Chứng từ trả lại hàng bán', label: 'Chứng từ trả lại hàng bán' },
        ]
    },
    {
        label: 'Mua hàng',
        options: [
            { value: 'Đơn mua hàng', label: 'Đơn mua hàng' },
            { value: 'Hợp đồng mua', label: 'Hợp đồng mua' },
            { value: 'Hóa đơn mua hàng', label: 'Hóa đơn mua hàng' },
            { value: 'Chứng từ mua dịch vụ', label: 'Chứng từ mua dịch vụ' },
            { value: 'Chứng từ giảm giá hàng mua', label: 'Chứng từ giảm giá hàng mua' },
            { value: 'Chứng từ trả lại hàng mua', label: 'Chứng từ trả lại hàng mua' },
        ]
    },
    {
        label: 'Quỹ (Tiền mặt)',
        options: [
            { value: 'Phiếu thu', label: 'Phiếu thu' },
            { value: 'Phiếu chi', label: 'Phiếu chi' },
        ]
    },
    {
        label: 'Ngân hàng',
        options: [
            { value: 'Thu tiền gửi', label: 'Thu tiền gửi (Báo Có)' },
            { value: 'Ủy nhiệm chi', label: 'Ủy nhiệm chi (Báo Nợ)' },
            { value: 'Séc chuyển khoản', label: 'Séc chuyển khoản' },
            { value: 'Séc tiền mặt', label: 'Séc tiền mặt' },
            { value: 'Bảng kê nộp séc', label: 'Bảng kê nộp séc' },
        ]
    },
    {
        label: 'Kho',
        options: [
            { value: 'Phiếu nhập kho', label: 'Phiếu nhập kho' },
            { value: 'Phiếu xuất kho', label: 'Phiếu xuất kho' },
            { value: 'Phiếu chuyển kho', label: 'Phiếu chuyển kho' },
            { value: 'Lệnh sản xuất', label: 'Lệnh sản xuất' },
        ]
    },
    {
        label: 'Tiền lương',
        options: [
            { value: 'Bảng tính lương', label: 'Bảng tính lương' },
        ]
    },
    {
        label: 'Tổng hợp',
        options: [
            { value: 'Chứng từ nghiệp vụ khác', label: 'Chứng từ nghiệp vụ khác' },
            { value: 'Kết chuyển lãi lỗ', label: 'Kết chuyển lãi lỗ' },
        ]
    }
];

export const VOUCHER_TYPES = VOUCHER_TYPE_GROUPS.flatMap(g => g.options);

const parseLookupList = (payload: any, label: string): any[] => {
    const rows = Array.isArray(payload)
        ? payload
        : Array.isArray(payload?.data)
            ? payload.data
            : Array.isArray(payload?.data?.data)
                ? payload.data.data
                : null;
    if (!rows) throw new Error(`Invalid ${label} lookup response`);
    return rows;
};

const getPeriodDateRange = (periodKey: string): [dayjs.Dayjs, dayjs.Dayjs] => {
    const now = dayjs();

    switch (periodKey) {
        case 'Hôm nay':
            return [now.startOf('day'), now.endOf('day')];
        case 'Tuần này':
            return [now.startOf('week'), now.endOf('week')];
        case 'Đầu tuần đến hiện tại':
            return [now.startOf('week'), now.endOf('day')];
        case 'Tuần trước':
            return [now.subtract(1, 'week').startOf('week'), now.subtract(1, 'week').endOf('week')];
        case 'Tháng này':
            return [now.startOf('month'), now.endOf('month')];
        case 'Đầu tháng đến hiện tại':
            return [now.startOf('month'), now.endOf('day')];
        case 'Tháng trước':
            return [now.subtract(1, 'month').startOf('month'), now.subtract(1, 'month').endOf('month')];
        case 'Quý này':
            return [now.startOf('quarter'), now.endOf('quarter')];
        case 'Đầu quý đến hiện tại':
            return [now.startOf('quarter'), now.endOf('day')];
        case 'Quý trước':
            return [now.subtract(1, 'quarter').startOf('quarter'), now.subtract(1, 'quarter').endOf('quarter')];
        case '6 tháng đầu năm':
            return [now.startOf('year'), now.month(5).endOf('month')];
        case '6 tháng cuối năm':
            return [now.month(6).startOf('month'), now.endOf('year')];
        case 'Năm nay':
            return [now.startOf('year'), now.endOf('year')];
        case 'Đầu năm đến hiện tại':
            return [now.startOf('year'), now.endOf('day')];
        case 'Năm trước':
            return [now.subtract(1, 'year').startOf('year'), now.subtract(1, 'year').endOf('year')];
        case 'Tùy chọn':
        default:
            return [now.startOf('month'), now.endOf('month')];
    }
};

export const ReferenceVoucherModal: React.FC<ReferenceVoucherModalProps> = ({
    open,
    onCancel,
    onSelect,
    includeBankVouchers = false,
}) => {
    const voucherTypeGroups = useMemo(
        () => includeBankVouchers
            ? VOUCHER_TYPE_GROUPS
            : VOUCHER_TYPE_GROUPS.filter((group) => group.label !== 'Ngân hàng'),
        [includeBankVouchers],
    );
    // Filter State
    const [searchBy, setSearchBy] = useState<string>('Loại chứng từ');
    const [searchValue, setSearchValue] = useState<string>('Tất cả');
    const [period, setPeriod] = useState<string>('Tháng này');
    const [dateRange, setDateRange] = useState<[dayjs.Dayjs, dayjs.Dayjs]>(() => getPeriodDateRange('Tháng này'));
    const [searchVoucherNumber, setSearchVoucherNumber] = useState<string>('');

    // Table Selection State
    const [selectedRows, setSelectedRows] = useState<any[]>([]);

    // Reset selection when modal opens
    useEffect(() => {
        if (open) {
            setSelectedRows([]);
            setSearchVoucherNumber('');
        }
    }, [open]);

    const handlePeriodChange = (val: string) => {
        setPeriod(val);
        if (val !== 'Tùy chọn') {
            const range = getPeriodDateRange(val);
            setDateRange(range);
        }
    };

    // Query API
    const voucherQuery = useQuery({
        queryKey: [
            'voucher-references-search', 
            searchBy, 
            searchValue, 
            dateRange?.[0]?.format('YYYY-MM-DD'), 
            dateRange?.[1]?.format('YYYY-MM-DD'),
            searchVoucherNumber
        ],
        queryFn: async () => {
            const params: any = {
                search_by: searchBy === 'Loại chứng từ' ? 'voucher_type' : (searchBy === 'Đối tượng' ? 'contact' : 'voucher_number'),
                search_value: searchValue,
                from_date: dateRange?.[0] ? dateRange[0].format('YYYY-MM-DD') : undefined,
                to_date: dateRange?.[1] ? dateRange[1].format('YYYY-MM-DD') : undefined,
                keyword: searchVoucherNumber.trim()
            };
            const { data } = await api.get('/voucher-references/search', { params });
            return parseLookupList(data, 'voucher reference');
        },
        enabled: open
    });
    const { data: responseData, isLoading, refetch } = voucherQuery;

    const customerQuery = useQuery({
        queryKey: ['customers-ref-lookup'],
        queryFn: async () => {
            const { data } = await api.get('/master/customers');
            return parseLookupList(data, 'customer');
        },
        enabled: open && searchBy === 'Đối tượng'
    });
    const { data: customers = [] } = customerQuery;

    const supplierQuery = useQuery({
        queryKey: ['suppliers-ref-lookup'],
        queryFn: async () => {
            const { data } = await api.get('/master/suppliers');
            return parseLookupList(data, 'supplier');
        },
        enabled: open && searchBy === 'Đối tượng'
    });
    const { data: suppliers = [] } = supplierQuery;

    const employeeQuery = useQuery({
        queryKey: ['employees-ref-lookup'],
        queryFn: async () => {
            const { data } = await api.get('/master/employees');
            return parseLookupList(data, 'employee');
        },
        enabled: open && searchBy === 'Đối tượng'
    });
    const { data: employees = [] } = employeeQuery;

    const lookupError = searchBy === 'Đối tượng' 
        ? (voucherQuery.error ?? customerQuery.error ?? supplierQuery.error ?? employeeQuery.error)
        : voucherQuery.error;
    const retryLookups = useCallback(async () => {
        if (searchBy === 'Đối tượng') {
            await Promise.all([voucherQuery.refetch(), customerQuery.refetch(), supplierQuery.refetch(), employeeQuery.refetch()]);
        } else {
            await voucherQuery.refetch();
        }
    }, [searchBy, voucherQuery.refetch, customerQuery.refetch, supplierQuery.refetch, employeeQuery.refetch]);

    const vouchers: any[] = responseData || [];

    const handleToggleRow = (row: any) => {
        const exists = selectedRows.some(r => r.id === row.id);
        if (exists) {
            setSelectedRows(selectedRows.filter(r => r.id !== row.id));
        } else {
            setSelectedRows([...selectedRows, row]);
        }
    };

    const handleConfirm = useCallback(() => {
        if (selectedRows.length === 0) {
            message.warning('Vui lòng chọn ít nhất một chứng từ tham chiếu!');
            return;
        }
        onSelect(selectedRows);
        setSelectedRows([]);
        onCancel();
    }, [selectedRows, onSelect, onCancel]);

    // Handle double-click row to select and confirm immediately
    const handleDoubleClickRow = (row: any) => {
        onSelect([row]);
        setSelectedRows([]);
        onCancel();
    };

    // Keyboard Shortcuts (Esc to close, Ctrl+Enter / Enter to confirm, F1 for help)
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (!open) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                onCancel();
            } else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                handleConfirm();
            } else if (e.key === 'F1') {
                e.preventDefault();
                message.info('Trợ giúp: Chọn chứng từ tham chiếu để tự động điền thông tin và hạch toán vào chứng từ hiện tại.');
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [open, handleConfirm, onCancel]);

    // Summary calculation for selected rows
    const totalSelectedAmount = useMemo(() => {
        return selectedRows.reduce((sum, r) => sum + Number(r.total_amount || 0), 0);
    }, [selectedRows]);

    const columns: ColumnsType<any> = [
        {
            title: 'Ngày HT',
            dataIndex: 'posting_date',
            key: 'posting_date',
            width: 100,
            align: 'center',
            render: (val: any) => formatDate(val),
        },
        {
            title: 'Ngày CT',
            dataIndex: 'voucher_date',
            key: 'voucher_date',
            width: 100,
            align: 'center',
            render: (val: any) => formatDate(val),
        },
        {
            title: 'Số chứng từ',
            dataIndex: 'voucher_number',
            key: 'voucher_number',
            width: 135,
            render: (val: any) => (
                <span className="misa-ref-voucher-code" title="Click đúp chuột để chọn nhanh">
                    {val || '-'}
                </span>
            ),
        },
        {
            title: 'Loại chứng từ',
            dataIndex: 'voucher_type',
            key: 'voucher_type',
            width: 135,
            ellipsis: true,
            render: (val: any) => val || '-',
        },
        {
            title: 'Diễn giải',
            dataIndex: 'description',
            key: 'description',
            ellipsis: true,
            render: (val: any) => val || '-',
        },
        {
            title: 'Đối tượng',
            dataIndex: 'contact_code',
            key: 'contact_code',
            width: 110,
            render: (val: any) => val || '-',
        },
        {
            title: 'Tên đối tượng',
            dataIndex: 'contact_name',
            key: 'contact_name',
            width: 170,
            ellipsis: true,
            render: (val: any) => val || '-',
        },
        {
            title: 'TK Nợ',
            dataIndex: 'debit_account',
            key: 'debit_account',
            width: 75,
            align: 'center',
            render: (val: any) => val || '-',
        },
        {
            title: 'TK Có',
            dataIndex: 'credit_account',
            key: 'credit_account',
            width: 75,
            align: 'center',
            render: (val: any) => val || '-',
        },
        {
            title: 'Số tiền',
            dataIndex: 'total_amount',
            key: 'total_amount',
            width: 130,
            align: 'right',
            render: (val: any) => (
                <span className="misa-text-semibold">
                    {new Intl.NumberFormat('vi-VN').format(Number(val) || 0)}
                </span>
            ),
        },
    ];

    const rowSelection = {
        selectedRowKeys: selectedRows.map(r => r.id),
        onChange: (selectedKeys: React.Key[], newSelectedRows: any[]) => {
            const keySet = new Set(selectedKeys);
            const preserved = selectedRows.filter(r => keySet.has(r.id));
            const freshlySelected = newSelectedRows.filter(r => !selectedRows.some(sr => sr.id === r.id));
            setSelectedRows([...preserved, ...freshlySelected]);
        },
    };

    return (
        <Modal
            open={open}
            onCancel={onCancel}
            width={1050}
            zIndex={2500}
            className="misa-ref-modal"
            centered={true}
            closable={true}
            title="Chọn chứng từ tham chiếu"
            closeIcon={
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <Tooltip title="Trợ giúp (F1)">
                        <QuestionCircleOutlined 
                            style={{ fontSize: 16, color: '#64748b', cursor: 'pointer' }}
                            onClick={(e) => {
                                e.stopPropagation();
                                message.info('Trợ giúp: Chọn chứng từ tham chiếu để tự động điền thông tin và hạch toán vào chứng từ hiện tại.');
                            }} 
                        />
                    </Tooltip>
                    <Tooltip title="Đóng (Esc)">
                        <CloseOutlined style={{ fontSize: 16, color: '#64748b', cursor: 'pointer' }} />
                    </Tooltip>
                </div>
            }
            footer={
                <div className="misa-ref-footer-wrapper">
                    <div className="misa-ref-footer-left">
                        <Button 
                            type="link" 
                            icon={<QuestionCircleOutlined />} 
                            className="misa-ref-btn-help"
                            onClick={() => message.info('Trợ giúp: Chọn chứng từ tham chiếu để tự động điền thông tin và hạch toán vào chứng từ hiện tại.')}
                        >
                            Giúp
                        </Button>
                        <span className="misa-ref-summary-text">
                            Đã chọn: <b>{selectedRows.length}</b> chứng từ
                            {selectedRows.length > 0 && (
                                <> | Tổng tiền: <b style={{ color: '#1677ff' }}>{new Intl.NumberFormat('vi-VN').format(totalSelectedAmount)} đ</b></>
                            )}
                        </span>
                    </div>
                    <div className="misa-ref-footer-right">
                        <Button 
                            onClick={onCancel} 
                            className="misa-ref-btn-cancel"
                        >
                            Hủy
                        </Button>
                        <Button 
                            type="primary"
                            onClick={handleConfirm}
                            className="misa-ref-btn-confirm"
                            disabled={selectedRows.length === 0}
                        >
                            Đồng ý
                        </Button>
                    </div>
                </div>
            }
        >
            <div className="misa-ref-body">
                {/* Filter Section */}
                <div className="misa-ref-filter-section">
                    {/* Row 1: Tìm theo & Giá trị */}
                    <div className="misa-ref-filter-row">
                        <div className="misa-ref-filter-item">
                            <span className="misa-ref-filter-label">Tìm theo</span>
                            <Select 
                                value={searchBy} 
                                onChange={(val) => {
                                    setSearchBy(val);
                                    setSearchValue('Tất cả');
                                }}
                                style={{ width: 160 }}
                                className="misa-input"
                                options={[
                                    { value: 'Loại chứng từ', label: 'Loại chứng từ' },
                                    { value: 'Đối tượng', label: 'Đối tượng' },
                                    { value: 'Số chứng từ', label: 'Số chứng từ' },
                                ]}
                                getPopupContainer={() => document.body}
                            />
                        </div>

                        <div className="misa-ref-filter-item misa-ref-filter-item--fill">
                            <span className="misa-ref-filter-label">Giá trị</span>
                            {searchBy === 'Loại chứng từ' ? (
                                <Select 
                                    value={searchValue} 
                                    placeholder="Chọn loại chứng từ..."
                                    allowClear={false}
                                    onChange={(val) => setSearchValue(val || 'Tất cả')}
                                    showSearch
                                    style={{ width: '100%' }}
                                    className="misa-input"
                                    options={voucherTypeGroups}
                                    popupMatchSelectWidth={false}
                                    getPopupContainer={() => document.body}
                                />
                            ) : searchBy === 'Đối tượng' ? (
                                <Select 
                                    value={searchValue === 'Tất cả' ? undefined : searchValue}
                                    placeholder="Chọn hoặc tìm đối tượng..."
                                    allowClear
                                    showSearch
                                    onChange={(val) => setSearchValue(val || 'Tất cả')}
                                    style={{ width: '100%' }}
                                    className="misa-input"
                                    options={[
                                        {
                                            label: 'Khách hàng',
                                            options: customers.map((c: any) => ({
                                                value: c.code || c.name,
                                                label: `${c.code ? c.code + ' - ' : ''}${c.name}`
                                            }))
                                        },
                                        {
                                            label: 'Nhà cung cấp',
                                            options: suppliers.map((s: any) => ({
                                                value: s.code || s.name,
                                                label: `${s.code ? s.code + ' - ' : ''}${s.name}`
                                            }))
                                        },
                                        {
                                            label: 'Nhân viên',
                                            options: employees.map((e: any) => ({
                                                value: e.code || e.name,
                                                label: `${e.code ? e.code + ' - ' : ''}${e.name}`
                                            }))
                                        }
                                    ]}
                                    popupMatchSelectWidth={false}
                                    getPopupContainer={() => document.body}
                                />
                            ) : (
                                <Input 
                                    placeholder="Nhập số chứng từ cần tìm..." 
                                    value={searchValue === 'Tất cả' ? '' : searchValue}
                                    onChange={(e) => setSearchValue(e.target.value)}
                                    style={{ width: '100%' }}
                                    className="misa-input"
                                    allowClear
                                />
                            )}
                        </div>
                    </div>

                    {/* Row 2: Khoảng thời gian, Từ ngày, Đến ngày, Button Lấy dữ liệu */}
                    <div className="misa-ref-filter-row">
                        <div className="misa-ref-filter-item">
                            <span className="misa-ref-filter-label">Khoảng thời gian</span>
                            <Select 
                                value={period} 
                                onChange={handlePeriodChange}
                                style={{ width: 160 }}
                                className="misa-input"
                                options={PERIOD_OPTIONS}
                                getPopupContainer={() => document.body}
                            />
                        </div>

                        <div className="misa-ref-filter-item">
                            <span className="misa-ref-filter-label">Từ ngày</span>
                            <DatePicker 
                                value={dateRange?.[0] || null}
                                onChange={(date) => {
                                    setDateRange([date, dateRange?.[1] ?? null] as any);
                                    setPeriod('Tùy chọn');
                                }}
                                format="DD/MM/YYYY"
                                style={{ width: 130 }}
                                className="misa-input"
                            />
                        </div>

                        <div className="misa-ref-filter-item">
                            <span className="misa-ref-filter-label">Đến ngày</span>
                            <DatePicker 
                                value={dateRange?.[1] || null}
                                onChange={(date) => {
                                    setDateRange([dateRange?.[0] ?? null, date] as any);
                                    setPeriod('Tùy chọn');
                                }}
                                format="DD/MM/YYYY"
                                style={{ width: 130 }}
                                className="misa-input"
                            />
                        </div>

                        <Button 
                            type="default"
                            icon={<SearchOutlined />}
                            className="misa-ref-btn-fetch"
                            onClick={() => refetch()}
                            loading={isLoading}
                        >
                            Lấy dữ liệu
                        </Button>
                    </div>
                </div>

                {/* Toolbar above table */}
                <div className="misa-ref-table-toolbar">
                    <Input 
                        placeholder="Tìm kiếm theo số chứng từ, đối tượng, diễn giải..." 
                        prefix={<SearchOutlined style={{ color: '#94a3b8' }} />}
                        value={searchVoucherNumber}
                        onChange={(e) => setSearchVoucherNumber(e.target.value)}
                        allowClear
                        style={{ width: 380 }}
                        className="misa-ref-search-input"
                    />
                    <div className="misa-ref-count-text">
                        Tổng số: <b>{vouchers.length}</b> chứng từ
                    </div>
                </div>

                {lookupError ? (
                    <Alert
                        style={{ marginBottom: 10 }}
                        type="error"
                        showIcon
                        message="Không thể tải dữ liệu tham chiếu"
                        description="Các lựa chọn và dòng đang hiển thị được giữ nguyên; máy chủ chưa trả về dữ liệu mới hợp lệ."
                        action={<Button size="small" onClick={() => void retryLookups()}>Thử lại</Button>}
                    />
                ) : null}

                {/* Table Data Grid */}
                <div className="misa-ref-grid-wrapper">
                    <Table 
                        columns={columns}
                        dataSource={vouchers}
                        rowKey="id"
                        size="small"
                        bordered
                        pagination={false}
                        loading={isLoading}
                        scroll={{ y: 340, x: 1150 }}
                        rowSelection={rowSelection}
                        onRow={(record) => ({
                            onClick: (e: React.MouseEvent) => {
                                if ((e.target as HTMLElement).closest('.ant-checkbox-wrapper') || (e.target as HTMLElement).closest('.ant-table-selection-column')) {
                                    return;
                                }
                                handleToggleRow(record);
                            },
                            onDoubleClick: () => handleDoubleClickRow(record),
                            style: { cursor: 'pointer' },
                        })}
                        rowClassName={(record) => selectedRows.some(r => r.id === record.id) ? 'misa-ref-row-selected' : ''}
                        locale={{
                            emptyText: (
                                <div className="misa-ref-empty-state">
                                    <InboxOutlined className="misa-empty-icon" />
                                    <span>Không tìm thấy chứng từ tham chiếu phù hợp</span>
                                </div>
                            )
                        }}
                    />
                </div>
            </div>
        </Modal>
    );
};

export default ReferenceVoucherModal;
