import React, { useCallback, useEffect, useState } from 'react';
import { Alert, Table, Button, Input, Select, InputNumber, Tag, Space, DatePicker, Tooltip } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import Modal from '../../components/layout/AppModal';
import { 
    ReloadOutlined, 
    ExportOutlined, 
    PrinterOutlined, 
    DeleteOutlined,
    PlusOutlined, 
    FileTextOutlined,
    SearchOutlined
} from '@ant-design/icons';
import api from '../../api/axios';
import dayjs from 'dayjs';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';


interface ForecastItem {
    id: string;
    code: string;
    name: string;
    amount: number | null;
    isParent?: boolean;
    isRemovable?: boolean;
}

interface ForecastRecord {
    id: number;
    period_name: string;
    from_date: string;
    to_date: string;
    opening_balance: number | null;
    expected_receipts: number | null;
    expected_payments: number | null;
    closing_balance: number | null;
    creator: string;
    created_date: string;
    items: ForecastItem[];
}

const createInitialItems = (): ForecastItem[] => [
    { id: 'A', code: 'A', name: 'Tiền tồn đầu kỳ', amount: 0, isParent: true },
    { id: '1', code: '1', name: 'Tiền mặt tại quỹ (TK 111)', amount: 0, isRemovable: false },
    { id: '3', code: '3', name: 'Tiền đang chuyển (TK 113)', amount: 0, isRemovable: false },
    { id: 'B', code: 'B', name: 'Dự kiến thu', amount: 0, isParent: true },
    { id: '100', code: '100', name: 'Thu từ bán hàng thu tiền ngay', amount: 0, isRemovable: true },
    { id: '101', code: '101', name: 'Thu nợ từ bán hàng', amount: 0, isRemovable: true },
    { id: 'C', code: 'C', name: 'Dự kiến chi', amount: 0, isParent: true },
    { id: '200', code: '200', name: 'Chi trả nợ người bán', amount: 0, isRemovable: true },
    { id: '202', code: '202', name: 'Chi tạm ứng cho nhân viên', amount: 0, isRemovable: true },
    { id: '203', code: '203', name: 'Chi trả lương và BHXH, BHYT, KPCĐ', amount: 0, isRemovable: true },
    { id: 'D', code: 'D', name: 'Tiền tồn cuối kỳ', amount: 0, isParent: true },
];

const formatForecastDate = (value: unknown): string => {
    const parsed = dayjs(String(value ?? ''));
    return parsed.isValid() ? parsed.format('DD/MM/YYYY') : '';
};

const sourceMoney = (value: unknown): number | null => {
    if (value === null || value === undefined || value === '') return null;
    const amount = Number(value);
    return Number.isFinite(amount) ? amount : null;
};

const formatForecastMoney = (value: number | null | undefined): string => (
    value === null || value === undefined
        ? '—'
        : `${new Intl.NumberFormat('vi-VN').format(value)} ₫`
);

const normalizeForecast = (value: any): ForecastRecord => {
    const items = Array.isArray(value?.items) ? value.items.map((item: any) => ({
        id: String(item.id ?? item.code),
        code: String(item.code ?? ''),
        name: String(item.name ?? ''),
        amount: sourceMoney(item.amount),
        isParent: Boolean(item.isParent ?? item.is_parent),
        isRemovable: Boolean(item.isRemovable ?? item.is_removable),
    })) : [];

    return {
        id: Number(value?.id),
        period_name: String(value?.period_name ?? ''),
        from_date: formatForecastDate(value?.from_date),
        to_date: formatForecastDate(value?.to_date),
        opening_balance: sourceMoney(value?.opening_balance),
        expected_receipts: sourceMoney(value?.expected_receipts ?? value?.expected_inflow),
        expected_payments: sourceMoney(value?.expected_payments ?? value?.expected_outflow),
        closing_balance: sourceMoney(value?.closing_balance),
        creator: String(value?.creator ?? ''),
        created_date: formatForecastDate(value?.created_date),
        items,
    };
};

const DEFAULT_REVENUE_ITEMS = [
    { code: '100', name: 'Thu từ bán hàng thu tiền ngay' },
    { code: '101', name: 'Thu nợ từ bán hàng' },
    { code: '102', name: 'Thu nợ từ hợp đồng bán' },
    { code: '103', name: 'Thu tiền góp vốn của chủ sở hữu' },
    { code: '106', name: 'Thu lãi từ các khoản đầu tư tài chính' },
    { code: '107', name: 'Thu hồi các khoản ký cược, ký quỹ' },
    { code: '108', name: 'Thu khác' }
];

const DEFAULT_EXPENSE_ITEMS = [
    { code: '200', name: 'Chi trả nợ người bán' },
    { code: '201', name: 'Chi mua hàng trả tiền ngay' },
    { code: '202', name: 'Chi tạm ứng cho nhân viên' },
    { code: '203', name: 'Chi trả lương và BHXH, BHYT, KPCĐ' },
    { code: '204', name: 'Chi nộp thuế và các khoản nộp ngân sách NN' },
    { code: '205', name: 'Chi trả lãi vay và nợ gốc vay' },
    { code: '206', name: 'Chi đầu tư mua sắm TSCĐ, CCDC' },
    { code: '207', name: 'Chi chi phí quản lý doanh nghiệp' },
    { code: '208', name: 'Chi khác' }
];

const parseForecastCollection = (value: unknown): any[] => {
    if (Array.isArray(value)) return value;
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return (value as { data: any[] }).data;
    }
    throw new Error('Invalid cash forecast response');
};

export const CashForecast: React.FC = React.memo(() => {
    const [searchText, setSearchText] = useState('');
    const [periodFilter, setPeriodFilter] = useState(`Năm ${dayjs().year()}`);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [loadError, setLoadError] = useState(false);

    const [isSheetModalOpen, setIsSheetModalOpen] = useState(false);
    const [isSheetReadOnly, setIsSheetReadOnly] = useState(false);
    const [isParamModalOpen, setIsParamModalOpen] = useState(false);
    const [isSelectItemModalOpen, setIsSelectItemModalOpen] = useState(false);
    const [selectItemType, setSelectItemType] = useState<'revenue' | 'expense'>('revenue');

    const [currentPeriodName, setCurrentPeriodName] = useState(`Tháng ${dayjs().month() + 1} năm ${dayjs().year()}`);
    const [fromDate, setFromDate] = useState<any>(dayjs().startOf('month'));
    const [toDate, setToDate] = useState<any>(dayjs().endOf('month'));

    const [currentItems, setCurrentItems] = useState<ForecastItem[]>(createInitialItems);
    const [records, setRecords] = useState<ForecastRecord[]>([]);

    const loadForecasts = useCallback(async () => {
        setLoading(true);
        setLoadError(false);
        try {
            const { data } = await api.get('/cash-forecasts');
            const payload = parseForecastCollection(data);
            setRecords(payload.map(normalizeForecast));
            setLoadError(false);
        } catch (error) {
            setLoadError(true);
            message.error('Không thể tải dự báo dòng tiền từ máy chủ.');
            console.error(error);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void loadForecasts();
    }, [loadForecasts]);

    // Recalculate Totals (A, B, C, D)
    const recalculatedItems = React.useMemo(() => {
        let totalA = 0;
        let totalB = 0;
        let totalC = 0;
        let hasUnknownA = false;
        let hasUnknownB = false;
        let hasUnknownC = false;

        currentItems.forEach(item => {
            if (['1', '2', '3'].includes(item.code)) {
                if (item.amount === null) hasUnknownA = true;
                else totalA += item.amount;
            } else if (item.code.startsWith('1') && item.code !== '1' && !item.isParent) {
                if (item.amount === null) hasUnknownB = true;
                else totalB += item.amount;
            } else if (item.code.startsWith('2') && !item.isParent) {
                if (item.amount === null) hasUnknownC = true;
                else totalC += item.amount;
            }
        });

        const totalD = hasUnknownA || hasUnknownB || hasUnknownC ? null : totalA + totalB - totalC;

        return currentItems.map(item => {
            if (item.code === 'A') return { ...item, amount: hasUnknownA ? null : totalA };
            if (item.code === 'B') return { ...item, amount: hasUnknownB ? null : totalB };
            if (item.code === 'C') return { ...item, amount: hasUnknownC ? null : totalC };
            if (item.code === 'D') return { ...item, amount: totalD };
            return item;
        });
    }, [currentItems]);

    // Handle Amount Change for a row
    const handleAmountChange = (code: string, newAmount: number) => {
        setCurrentItems(prev => prev.map(item => item.code === code ? { ...item, amount: newAmount } : item));
    };

    // Remove an Item
    const handleRemoveItem = (code: string) => {
        setCurrentItems(prev => prev.filter(item => item.code !== code));
    };

    // Add a Selected Item
    const handleAddSelectedItem = (item: { code: string; name: string }) => {
        if (currentItems.some(i => i.code === item.code)) {
            message.warning(`Chỉ tiêu "${item.name}" đã có trong bảng dự báo!`);
            return;
        }

        const newItem: ForecastItem = {
            id: item.code,
            code: item.code,
            name: item.name,
            amount: 0,
            isRemovable: true
        };

        if (selectItemType === 'revenue') {
            // Insert before item 'C'
            const idxC = currentItems.findIndex(i => i.code === 'C');
            const updated = [...currentItems];
            if (idxC !== -1) updated.splice(idxC, 0, newItem);
            else updated.push(newItem);
            setCurrentItems(updated);
        } else {
            // Insert before item 'D'
            const idxD = currentItems.findIndex(i => i.code === 'D');
            const updated = [...currentItems];
            if (idxD !== -1) updated.splice(idxD, 0, newItem);
            else updated.push(newItem);
            setCurrentItems(updated);
        }

        message.success(`Đã thêm chỉ tiêu: ${item.name}`);
        setIsSelectItemModalOpen(false);
    };

    // Save Forecast Sheet through the tenant-scoped backend contract.
    const handleSaveForecast = async (): Promise<boolean> => {
        const itemA = recalculatedItems.find(i => i.code === 'A')?.amount || 0;
        const itemB = recalculatedItems.find(i => i.code === 'B')?.amount || 0;
        const itemC = recalculatedItems.find(i => i.code === 'C')?.amount || 0;
        const itemD = recalculatedItems.find(i => i.code === 'D')?.amount || 0;

        setSaving(true);
        try {
            const { data } = await api.post('/cash-forecasts', {
                period_name: currentPeriodName,
                from_date: fromDate.format('YYYY-MM-DD'),
                to_date: toDate.format('YYYY-MM-DD'),
                opening_balance: itemA,
                expected_inflow: itemB,
                expected_outflow: itemC,
                closing_balance: itemD,
                created_date: dayjs().format('YYYY-MM-DD'),
                items: recalculatedItems.map(item => ({
                    code: item.code,
                    name: item.name,
                    amount: item.amount,
                    isParent: item.isParent,
                    isRemovable: item.isRemovable,
                })),
            });
            const saved = data?.data ?? data;
            if (!saved || !Number.isInteger(Number(saved.id))) {
                message.error('Máy chủ không trả về bảng dự báo đã lưu; không thể xác nhận thao tác thành công.');
                return false;
            }
            setRecords(prev => [normalizeForecast(saved), ...prev]);
            message.success('Đã lưu bảng dự báo dòng tiền thành công!');
            setIsSheetModalOpen(false);
            return true;
        } catch (error) {
            message.error('Không thể lưu dự báo dòng tiền.');
            console.error(error);
            return false;
        } finally {
            setSaving(false);
        }
    };

    const columns = [
        {
            title: 'Kỳ dự báo',
            dataIndex: 'period_name',
            key: 'period_name',
            width: 180,
            render: (t: string, r: ForecastRecord) => (
                <span 
                    className="misa-btn-link-action-bold"
                    onClick={() => {
                        setCurrentItems(r.items);
                        setCurrentPeriodName(r.period_name);
                        setIsSheetReadOnly(true);
                        setIsSheetModalOpen(true);
                    }}
                >
                    {t}
                </span>
            )
        },
        { title: 'Từ ngày', dataIndex: 'from_date', key: 'from_date', width: 110, align: 'center' as const },
        { title: 'Đến ngày', dataIndex: 'to_date', key: 'to_date', width: 110, align: 'center' as const },
        { 
            title: 'Tồn đầu kỳ (₫)', 
            dataIndex: 'opening_balance', 
            key: 'opening_balance', 
            width: 150, 
            align: 'right' as const,
            render: (v: number | null) => <span className="misa-text-bold">{formatForecastMoney(v)}</span>
        },
        { 
            title: 'Dự kiến thu (₫)', 
            dataIndex: 'expected_receipts', 
            key: 'expected_receipts', 
            width: 150, 
            align: 'right' as const,
            render: (v: number | null) => <span className="misa-stat-value-green misa-fw-700">{formatForecastMoney(v)}</span>
        },
        { 
            title: 'Dự kiến chi (₫)', 
            dataIndex: 'expected_payments', 
            key: 'expected_payments', 
            width: 150, 
            align: 'right' as const,
            render: (v: number | null) => <span className="misa-stat-value-orange misa-fw-700">{formatForecastMoney(v)}</span>
        },
        { 
            title: 'Tồn cuối kỳ (₫)', 
            dataIndex: 'closing_balance', 
            key: 'closing_balance', 
            width: 160, 
            align: 'right' as const,
            render: (v: number | null) => <span className="misa-stat-value-blue misa-fw-800">{formatForecastMoney(v)}</span>
        },
        { title: 'Người tạo', dataIndex: 'creator', key: 'creator', width: 140 },
        { title: 'Ngày lập', dataIndex: 'created_date', key: 'created_date', width: 110, align: 'center' as const },
        {
            title: 'Chức năng',
            key: 'action',
            width: 120,
            align: 'center' as const,
            render: (_: any, r: ForecastRecord) => (
                <Space size="small">
                    <Button 
                        type="link" 
                        size="small" 
                        className="misa-btn-link-action"
                        onClick={() => {
                            setCurrentItems(r.items);
                            setCurrentPeriodName(r.period_name);
                            setIsSheetReadOnly(true);
                            setIsSheetModalOpen(true);
                        }}
                    >
                        Xem
                    </Button>
                    <Tooltip title="Backend chưa công bố API xóa dự báo dòng tiền">
                        <Button type="link" danger size="small" disabled className="misa-btn-transparent">
                            Xóa
                        </Button>
                    </Tooltip>
                </Space>
            )
        }
    ];

    return (
        <PageShell 
            title={<PageHeader eyebrow="Tiền mặt" title="Dự báo dòng tiền" description="Lập và theo dõi các kỳ dự báo thu, chi và tồn quỹ." />}
            toolbar={(
                <PageToolbar
                    filters={(
                        <div className="ui-page-toolbar__filter-group flex items-center gap-2">
                            <Input 
                                placeholder="Tìm kiếm kỳ dự báo, người lập..." 
                                prefix={<SearchOutlined className="misa-color-muted" />}
                                className="misa-input misa-w-280" 
                                allowClear
                                value={searchText}
                                onChange={e => setSearchText(e.target.value)}
                            />
                            <Select 
                                value={periodFilter} 
                                onChange={setPeriodFilter}
                                className="misa-w-140"
                                options={[
                                    { value: 'Năm 2026', label: 'Năm 2026' },
                                    { value: 'Quý 3/2026', label: 'Quý 3/2026' },
                                    { value: 'Tháng 8/2026', label: 'Tháng 8/2026' }
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
                                onClick={() => void loadForecasts()}
                                loading={loading}
                            />
                            <Tooltip title="Backend chưa công bố API xuất khẩu dự báo dòng tiền">
                                <Button
                                    icon={<ExportOutlined />}
                                    className="misa-btn-tool"
                                    title="Xuất khẩu"
                                    disabled
                                />
                            </Tooltip>
                            <Button 
                                type="primary" 
                                icon={<PlusOutlined />}
                                className="misa-btn-primary"
                                onClick={() => setIsParamModalOpen(true)}
                            >
                                Thêm dự báo dòng tiền
                            </Button>
                        </div>
                    )}
                />
            )}
        >
            {/* Main Table List */}
            {loadError && (
                <Alert
                    className="mb-4"
                    type="error"
                    showIcon
                    message="Không thể tải dự báo dòng tiền"
                    description="Các dòng đang hiển thị được giữ nguyên; máy chủ chưa trả về dữ liệu mới hợp lệ."
                    action={<Button size="small" onClick={() => void loadForecasts()}>Thử lại</Button>}
                />
            )}
            <DataTableSurface>
                <Table 
                    className="misa-voucher-table"
                    columns={columns}
                    dataSource={records}
                    loading={loading}
                    locale={{ emptyText: loadError ? 'Không thể tải dự báo dòng tiền.' : 'Chưa có dự báo dòng tiền.' }}
                    rowKey="id"
                    pagination={false}
                    size="small"
                    scroll={{ x: 'max-content' }}
                />
            </DataTableSurface>

            {/* Parameter Selection Dialog */}
            <Modal
                title={
                    <div className="misa-flex-center misa-gap-8">
                        <FileTextOutlined className="misa-color-primary misa-fs-18" />
                        <span className="misa-fw-700">Chọn kỳ dự báo dòng tiền</span>
                    </div>
                }
                open={isParamModalOpen}
                onCancel={() => setIsParamModalOpen(false)}
                onOk={() => {
                    setIsParamModalOpen(false);
                    setCurrentItems(createInitialItems());
                    setIsSheetReadOnly(false);
                    setIsSheetModalOpen(true);
                }}
                width={500}
                okText="Đồng ý"
                cancelText="Hủy"
                okButtonProps={{ className: 'misa-btn-primary' }}
            >
                <div className="misa-flex-col-gap-14-pt12">
                    <div>
                        <div className="misa-field-label misa-mb-4">Kỳ dự báo:</div>
                        <Select 
                            className="misa-w-full"
                            value={currentPeriodName}
                            onChange={(val) => {
                                const valStr = String(val || '');
                                setCurrentPeriodName(val);
                                if (valStr.includes('Năm')) {
                                    setFromDate(dayjs().startOf('year'));
                                    setToDate(dayjs().endOf('year'));
                                } else if (valStr.includes('Tháng')) {
                                    setFromDate(dayjs().startOf('month'));
                                    setToDate(dayjs().endOf('month'));
                                }
                            }}
                            options={[
                                { value: `Tháng ${dayjs().month() + 1} năm ${dayjs().year()}`, label: `Tháng ${dayjs().month() + 1} năm ${dayjs().year()}` },
                                { value: `Năm ${dayjs().year()}`, label: `Cả năm ${dayjs().year()}` },
                            ]}
                        />
                    </div>
                    <div className="misa-grid-2col">
                        <div>
                            <div className="misa-field-label misa-mb-4">Từ ngày:</div>
                            <DatePicker className="misa-w-full" format="DD/MM/YYYY" value={fromDate} onChange={setFromDate} />
                        </div>
                        <div>
                            <div className="misa-field-label misa-mb-4">Đến ngày:</div>
                            <DatePicker className="misa-w-full" format="DD/MM/YYYY" value={toDate} onChange={setToDate} />
                        </div>
                    </div>
                </div>
            </Modal>

            {/* MISA Full Forecast Calculation Sheet Modal */}
            <Modal
                title={
                    <div className="misa-modal-detail-header-20">
                        <div className="misa-flex-center misa-gap-12">
                            <span className="misa-fs-18 misa-fw-800 misa-color-dark">
                                DỰ BÁO DÒNG TIỀN: {currentPeriodName}
                            </span>
                            <Tag color="green">
                                {fromDate.format('DD/MM/YYYY')} - {toDate.format('DD/MM/YYYY')}
                            </Tag>
                            {isSheetReadOnly && <Tag color="blue">Bảng đã lưu — chỉ xem</Tag>}
                        </div>
                    </div>
                }
                open={isSheetModalOpen}
                onCancel={() => { setIsSheetModalOpen(false); setIsSheetReadOnly(false); }}
                width={1000}
                className="misa-top-20"
                footer={
                    <div className="misa-modal-btn-footer-between">
                        <Button onClick={() => { setIsSheetModalOpen(false); setIsSheetReadOnly(false); }} className="misa-btn-secondary">Đóng</Button>
                        {!isSheetReadOnly && <Space>
                            <Button icon={<PrinterOutlined />} loading={saving} onClick={async () => { if (await handleSaveForecast()) window.print(); }} className="misa-btn-secondary">Cất và In</Button>
                            <Button
                                type="primary"
                                className="misa-btn-primary"
                                onClick={handleSaveForecast}
                                loading={saving}
                            >
                                Cất
                            </Button>
                        </Space>}
                    </div>
                }
            >
                <div className="misa-forecast-sheet-container">
                    <div className="misa-table-container">
                        <table className="misa-voucher-table misa-min-w-800">
                            <thead>
                                <tr>
                                    <th className="misa-w-80">Mã chỉ tiêu</th>
                                    <th className="misa-min-w-350">Chỉ tiêu dự báo</th>
                                    <th className="misa-w-220 misa-text-right">Số tiền dự kiến (₫)</th>
                                    <th className="misa-w-50 misa-text-center"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {(Array.isArray(currentItems) ? currentItems : []).map(item => {
                                    const isParent = item.isParent;
                                    const isHighlight = ['A', 'B', 'C', 'D'].includes(item.code);
                                    
                                    return (
                                        <tr 
                                            key={item.code}
                                            className={isHighlight ? 'misa-report-header-debit misa-fw-800' : (isParent ? 'misa-fw-800' : '')}
                                        >
                                            <td className={isHighlight ? 'misa-color-dark' : 'misa-color-muted'}>
                                                {item.code}
                                            </td>
                                            <td className={`${isHighlight ? 'misa-color-darker' : 'misa-color-label'} ${isParent ? 'misa-pl-12' : 'misa-pl-28'}`}>
                                                {item.name}
                                            </td>
                                            <td className="misa-text-right">
                                                {isParent ? (
                                                    <span className={item.code === 'D' ? 'misa-stat-value-blue misa-fs-16' : (item.code === 'B' ? 'misa-stat-value-green misa-fs-14' : (item.code === 'C' ? 'misa-stat-value-orange misa-fs-14' : 'misa-color-darker misa-fs-14'))}>
                                                        {formatForecastMoney(item.amount)}
                                                    </span>
                                                ) : (
                                                    <InputNumber 
                                                        className="misa-table-input misa-w-full misa-text-right misa-fw-600"
                                                        disabled={isSheetReadOnly}
                                                        value={item.amount}
                                                        formatter={v => (v !== undefined && v !== null) ? `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',') : ''}
                                                        parser={v => Number(String(v || '').replace(/\$\s?|(,*)/g, '') || 0)}
                                                        onChange={(val) => handleAmountChange(item.code, Number(val) || 0)}
                                                    />
                                                )}
                                            </td>
                                            <td className="misa-text-center">
                                                {item.isRemovable && (
                                                    <button 
                                                        type="button" 
                                                        onClick={() => handleRemoveItem(item.code)}
                                                        disabled={isSheetReadOnly}
                                                        className="misa-btn-icon-del"
                                                    >
                                                        <DeleteOutlined className="misa-fs-13" />
                                                    </button>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Quick Add Indicators Buttons */}
                    <div className="misa-flex misa-gap-10 misa-mt-4">
                        <Button 
                            icon={<PlusOutlined />}
                            className="misa-btn-add-green"
                            disabled={isSheetReadOnly}
                            onClick={() => {
                                setSelectItemType('revenue');
                                setIsSelectItemModalOpen(true);
                            }}
                        >
                            + Thêm chỉ tiêu Thu
                        </Button>
                        <Button 
                            icon={<PlusOutlined />}
                            className="misa-btn-add-orange"
                            disabled={isSheetReadOnly}
                            onClick={() => {
                                setSelectItemType('expense');
                                setIsSelectItemModalOpen(true);
                            }}
                        >
                            + Thêm chỉ tiêu Chi
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Select Indicator Modal */}
            <Modal
                title={`Chọn chỉ tiêu dự kiến ${selectItemType === 'revenue' ? 'Thu' : 'Chi'}`}
                open={isSelectItemModalOpen}
                onCancel={() => setIsSelectItemModalOpen(false)}
                footer={null}
                width={550}
            >
                <div className="misa-flex-col-gap-6 misa-pt-10">
                    {(selectItemType === 'revenue' ? DEFAULT_REVENUE_ITEMS : DEFAULT_EXPENSE_ITEMS).map(item => (
                        <div 
                            key={item.code}
                            className="misa-forecast-item-revenue"
                            onClick={() => handleAddSelectedItem(item)}
                        >
                            <span className="misa-fw-600 misa-color-dark">
                                {item.code}. {item.name}
                            </span>
                            <Button size="small" type="primary" className="misa-btn-primary">
                                Chọn
                            </Button>
                        </div>
                    ))}
                </div>
            </Modal>
        </PageShell>
    );
});

export default CashForecast;
