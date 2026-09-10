import React, { useState } from 'react';
import { Alert, Button, Select, Table, Tag, DatePicker, Tooltip } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import {
    CalculatorOutlined,
    CheckCircleOutlined,
    ReloadOutlined
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useVoucherShortcuts } from '../../hooks/useVoucherShortcuts';
import api from '../../api/axios';
import dayjs from 'dayjs';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

const formatNullableNumber = (value: number | null | undefined) =>
    value == null ? '—' : new Intl.NumberFormat('vi-VN').format(value);

type ValuationRun = {
    id: number;
    from_date: string;
    to_date: string;
    method: 'weighted_average' | 'fifo';
    status: 'completed' | 'invalidated';
    has_unverified_cost?: boolean;
    warehouse_id: number | null;
    invalidation_reason: string | null;
};

function parseCostCalculationCollection<T>(payload: unknown, resource: string): T[] {
    if (Array.isArray(payload)) return payload as T[];
    if (payload && typeof payload === 'object' && Array.isArray((payload as { data?: unknown }).data)) {
        return (payload as { data: T[] }).data;
    }
    throw new Error(`Invalid ${resource} response.`);
}

export const CostCalculation: React.FC<{ embedded?: boolean }> = ({ embedded = false }) => {
    const [dateRange, setDateRange] = useState<[dayjs.Dayjs, dayjs.Dayjs]>([
        dayjs().startOf('month'),
        dayjs().endOf('month')
    ]);
    const method = 'weighted_average';
    const [warehouse, setWarehouse] = useState<number | undefined>(undefined);
    const [isCalculating, setIsCalculating] = useState(false);
    const [calculatedData, setCalculatedData] = useState<any[]>([]);
    const [summaryInfo, setSummaryInfo] = useState<{ updatedCount: number | null; totalCost: number | null; unverifiedCount: number } | null>(null);

const {
        data: warehouses = [],
        isError: isWarehousesError,
        refetch: refetchWarehouses,
    } = useQuery({
        queryKey: ['warehouses'],
        queryFn: async () => {
            const { data } = await api.get('/master/warehouses');
            return parseCostCalculationCollection<any>(data, 'warehouses');
        },
});

    const valuationRunQuery = useQuery<ValuationRun[]>({
        queryKey: ['inventory-valuation-runs', dateRange[0].format('YYYY-MM-DD'), dateRange[1].format('YYYY-MM-DD'), method, warehouse ?? null],
        queryFn: async () => {
            const { data } = await api.get('/inventory/cost-calculation/runs', {
                params: {
                    from_date: dateRange[0].format('YYYY-MM-DD'),
                    to_date: dateRange[1].format('YYYY-MM-DD'),
                    method,
                    warehouse_id: warehouse,
                },
            });

            return parseCostCalculationCollection<ValuationRun>(data, 'valuation runs');
        },
    });

    const currentValuationRun = valuationRunQuery.data?.find((run) =>
        run.from_date === dateRange[0].format('YYYY-MM-DD')
        && run.to_date === dateRange[1].format('YYYY-MM-DD')
        && run.method === method
        && run.warehouse_id === (warehouse ?? null),
    );

    useVoucherShortcuts({
        onSave: () => {
            if (!isCalculating) {
                handleRunCalculation();
            }
        },
        onPost: () => {
            if (!isCalculating) {
                handleRunCalculation();
            }
        },
        onClose: () => {
            setCalculatedData([]);
            setSummaryInfo(null);
        },
        enabled: true
    });

    const handleRunCalculation = async () => {
        setIsCalculating(true);
        try {
            const payload = {
                from_date: dateRange[0].format('YYYY-MM-DD'),
                to_date: dateRange[1].format('YYYY-MM-DD'),
                method: method,
                warehouse_id: warehouse,
            };

            const response = await api.post('/inventory/cost-calculation/run', payload);
            const res = response.data;

            if (res?.success === true && Array.isArray(res.items)) {
                const unverifiedReasons = new Map<number, string>(
                    Array.isArray(res.unverified_cost_items)
                        ? res.unverified_cost_items
                            .filter((item: any) => Number.isFinite(Number(item?.item_id)) && typeof item?.reason === 'string')
                            .map((item: any) => [Number(item.item_id), item.reason])
                        : [],
                );
                const results = res.items.map((item: any, idx: number) => ({
                    key: item.item_id || idx,
                    code: item.item_code,
                    name: item.item_name,
                    unit: item.unit ?? '—',
                    opening_qty: item.opening_qty ?? null,
                    opening_amt: item.opening_amt ?? null,
                    in_qty: item.in_qty ?? null,
                    in_amt: item.in_amt ?? null,
                    unit_cost: item.unit_cost ?? null,
                    total_cost: item.total_out_cost ?? null,
                    cost_status: item.cost_status === 'verified' ? 'verified' : item.cost_status === 'unverified' ? 'unverified' : null,
                    cost_status_reason: unverifiedReasons.get(Number(item.item_id)) ?? null,
                }));

                setCalculatedData(results);
                setSummaryInfo({
                    updatedCount: res.updated_issues_count ?? null,
                    totalCost: res.total_cost_amount ?? null,
                    unverifiedCount: Array.isArray(res.unverified_cost_items) ? res.unverified_cost_items.length : 0,
                });
                void valuationRunQuery.refetch();
                if (Array.isArray(res.unverified_cost_items) && res.unverified_cost_items.length > 0) {
                    message.warning(`Đã tính giá nhưng còn ${res.unverified_cost_items.length} mặt hàng chưa chốt do phải dùng giá dự phòng.`);
                } else {
                    message.success(`Tính giá thành công! Đã cập nhật ${res.updated_issues_count == null ? '—' : res.updated_issues_count} phiếu xuất kho.`);
                }
            } else {
                throw new Error(res?.error || 'Máy chủ không trả về kết quả tính giá hợp lệ.');
            }
        } catch (err: any) {
            message.error(err?.response?.data?.error || err?.message || 'Không thể kết nối đến máy chủ tính giá');
        } finally {
            setIsCalculating(false);
        }
    };

    const columns = [
        { title: '#', dataIndex: 'key', key: 'key', width: 40, render: (_: any, __: any, index: number) => index + 1 },
        { title: 'Mã hàng', dataIndex: 'code', key: 'code', width: 130, render: (t: string) => <span className="misa-text-semibold text-blue-600">{t}</span> },
        { title: 'Tên hàng hóa', dataIndex: 'name', key: 'name', minWidth: 200 },
        { title: 'ĐVT', dataIndex: 'unit', key: 'unit', width: 80, align: 'center' as const },
        {
            title: 'Tồn đầu kỳ',
            dataIndex: 'opening_qty',
            key: 'opening_qty',
            width: 100,
            align: 'right' as const,
            render: (v: number | null | undefined) => <span>{v == null ? '—' : new Intl.NumberFormat('vi-VN').format(v)}</span>
        },
        {
            title: 'Nhập trong kỳ',
            dataIndex: 'in_qty',
            key: 'in_qty',
            width: 110,
            align: 'right' as const,
            render: (v: number | null | undefined) => <span>{formatNullableNumber(v)}</span>
        },
        {
            title: 'Đơn giá xuất tính được',
            dataIndex: 'unit_cost',
            key: 'unit_cost',
            width: 170,
            align: 'right' as const,
            render: (v: number | null | undefined, row: any) => row.cost_status === 'unverified'
                ? <Tooltip title={row.cost_status_reason ?? 'Giá đang dùng giá dự phòng, chưa đủ bằng chứng tồn kho.'}><span className="text-amber-700">Chưa chốt</span></Tooltip>
                : <span className="misa-text-semibold text-green-700">{v == null ? '—' : `${formatNullableNumber(v)} ₫`}</span>
        },
        {
            title: 'Tổng tiền giá vốn xuất',
            dataIndex: 'total_cost',
            key: 'total_cost',
            width: 180,
            align: 'right' as const,
            render: (v: number | null | undefined) => <span className="misa-text-semibold">{v == null ? '—' : `${formatNullableNumber(v)} ₫`}</span>
        },
        {
            title: 'Trạng thái hạch toán',
            dataIndex: 'status',
            key: 'status',
            width: 220,
            render: (t: string) => t ? <Tag color="green" icon={<CheckCircleOutlined />}>{t}</Tag> : <span>—</span>
        },
    ];

    return (
        <PageShell embedded={embedded} title={<PageHeader
            eyebrow="KHO"
            title="Tính giá xuất kho"
            description="Tài khoản hạch toán và việc cập nhật sổ cái phải theo cấu hình/mapping được máy chủ phê duyệt."
        />}>
            {!embedded && <PageToolbar
                filters={(
                    <div className="misa-toolbar-left flex items-center gap-3">
                        <DatePicker.RangePicker
                            value={dateRange}
                            onChange={(dates) => dates && setDateRange([dates[0]!, dates[1]!])}
                            format="DD/MM/YYYY"
                            className="misa-w-240"
                            allowClear={false}
                        />
                        <Select
                            value={warehouse}
                            onChange={setWarehouse}
                            placeholder="Tất cả các kho"
                            allowClear
                            className="misa-w-200"
                            options={warehouses?.map((w: any) => ({ value: w.id, label: `${w.code} - ${w.name}` }))}
                        />
                        <Tag color="blue">Bình quân gia quyền cuối kỳ</Tag>
                    </div>
                )}
                actions={(
                    <div className="misa-toolbar-right flex items-center gap-2">
                        <Button
                            icon={<ReloadOutlined />}
                            className="misa-btn-tool"
                            title="Làm mới (F5)"
                            onClick={() => void valuationRunQuery.refetch()}
                        />
                        <Button
                            type="primary"
                            icon={<CalculatorOutlined />}
                            loading={isCalculating}
                            className="misa-btn-primary"
                            onClick={handleRunCalculation}
                        >
                            Thực hiện tính giá
                        </Button>
                    </div>
                )}
            />}
            {embedded && (
                <PageToolbar
                    filters={(
                        <div className="misa-toolbar-left flex items-center gap-3">
                            <DatePicker.RangePicker
                                value={dateRange}
                                onChange={(dates) => dates && setDateRange([dates[0]!, dates[1]!])}
                                format="DD/MM/YYYY"
                                className="misa-w-240"
                                allowClear={false}
                            />
                            <Select
                                value={warehouse}
                                onChange={setWarehouse}
                                placeholder="Tất cả các kho"
                                allowClear
                                className="misa-w-200"
                                options={warehouses?.map((w: any) => ({ value: w.id, label: `${w.code} - ${w.name}` }))}
                            />
                            <Tag color="blue">Bình quân gia quyền cuối kỳ</Tag>
                        </div>
                    )}
                    actions={(
                        <div className="misa-toolbar-right flex items-center gap-2">
                            <Button
                                icon={<ReloadOutlined />}
                                className="misa-btn-tool"
                                title="Làm mới (F5)"
                                onClick={() => void valuationRunQuery.refetch()}
                            />
                            <Button
                                type="primary"
                                icon={<CalculatorOutlined />}
                                loading={isCalculating}
                                className="misa-btn-primary"
                                onClick={handleRunCalculation}
                            >
                                Thực hiện tính giá
                            </Button>
                        </div>
                    )}
                />
            )}

            {(isWarehousesError || valuationRunQuery.isError) && (
                <div className="misa-page-data-errors misa-flex-col misa-gap-8 misa-bottom-12">
                    {isWarehousesError && (
                        <Alert
                            type="error"
                            showIcon
                            title="Không thể tải danh mục kho"
                            action={<Button size="small" onClick={() => void refetchWarehouses()}>Thử lại danh mục kho</Button>}
                        />
                    )}
                    {valuationRunQuery.isError && (
                        <Alert
                            type="error"
                            showIcon
                            title="Không thể tải trạng thái tính giá"
                            action={<Button size="small" onClick={() => void valuationRunQuery.refetch()}>Thử lại trạng thái tính giá</Button>}
                        />
                    )}
                </div>
            )}

            {/* Results Grid */}
            <DataTableSurface>
                {summaryInfo && (
                    <div className="misa-mb-12 flex items-center gap-3">
                        <span className="text-sm text-green-700 bg-green-50 px-2 py-0.5 rounded border border-green-200">
                            Đã cập nhật {summaryInfo.updatedCount == null ? '—' : summaryInfo.updatedCount} phiếu xuất | Tổng giá vốn: {summaryInfo.totalCost == null ? '—' : `${new Intl.NumberFormat('vi-VN').format(summaryInfo.totalCost)} ₫`}
                            {summaryInfo.unverifiedCount > 0 && <span className="text-amber-700"> | Chưa chốt: {summaryInfo.unverifiedCount} mặt hàng</span>}
                        </span>
                    </div>
                )}
                {currentValuationRun?.status === 'invalidated' && (
                    <Alert type="warning" showIcon message="Cần tính lại vì có phát sinh kho thay đổi" className="misa-mb-12" />
                )}
                <Table
                    columns={columns}
                    dataSource={calculatedData}
                    pagination={false}
                    size="small"
                    className="misa-table"
                    locale={{ emptyText: (
                        <div className="py-12 text-center text-gray-400">
                            <CalculatorOutlined className="text-4xl mb-2 block text-gray-300" />
                            Chưa có dữ liệu tính giá. Chọn kỳ và nhấn "Thực hiện tính giá" để bắt đầu.
                        </div>
                    )}}
                />
            </DataTableSurface>
        </PageShell>
    );
};

export default CostCalculation;
