import React, { useState, useEffect, useRef } from 'react';
import { Alert, Button, DatePicker, Form, Select, Table } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import { PrinterOutlined, SearchOutlined, ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import ExportExcelButton from '../../components/ExportExcelButton';
import ManagementReportGate from '../reports/ManagementReportGate';
import ManagementReportDataError from '../reports/ManagementReportDataError';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';
import { buildStockReportParams, type StockReportFormValues, type StockReportRequestParams } from './stockReportFilters';
import { addDecimalMoney, formatDecimalMoney } from '../../utils/decimalMoney';
import { addDecimalQuantity } from './inventoryReportMath';

type OptionRecord = { id: number; code?: string; name?: string };

type StockReportRow = {
    item_id: number | string;
    item_code?: string;
    item_name?: string;
    unit?: string;
    opening_qty?: string | number | null;
    opening_amt?: string | number | null;
    in_qty?: string | number | null;
    in_amt?: string | number | null;
    out_qty?: string | number | null;
    out_amt?: string | number | null;
    end_qty?: string | number | null;
    end_amt?: string | number | null;
};

const REPORT_DECIMAL_FIELDS: ReadonlyArray<keyof StockReportRow> = [
    'opening_qty', 'opening_amt', 'in_qty', 'in_amt', 'out_qty', 'out_amt', 'end_qty', 'end_amt',
];

const isReportDecimal = (value: unknown): value is string | number | null => (
    value === null
    || value === undefined
    || (typeof value === 'number' && Number.isFinite(value))
    || (typeof value === 'string' && /^-?\d+(?:\.\d+)?$/.test(value.trim()))
);

const parseStockReportRows = (rows: unknown[]): StockReportRow[] => rows.map((row, index) => {
    if (!row || typeof row !== 'object' || !('item_id' in row) || (row as { item_id?: unknown }).item_id === undefined) {
        throw new Error(`Invalid stock-report row at index ${index}`);
    }
    for (const field of REPORT_DECIMAL_FIELDS) {
        if (!isReportDecimal((row as Record<string, unknown>)[field])) {
            throw new Error(`Invalid stock-report row at index ${index}: ${field} must be a decimal value`);
        }
    }
    return row as StockReportRow;
});

const asOptions = (data: unknown): OptionRecord[] => {
    if (Array.isArray(data)) return data as OptionRecord[];
    if (data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)) {
        return (data as { data: OptionRecord[] }).data;
    }
    return [];
};

const parseOptionsResponse = (data: unknown, resource: string): OptionRecord[] => {
    if (Array.isArray(data)) return data as OptionRecord[];
    if (data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)) {
        return (data as { data: OptionRecord[] }).data;
    }
    throw new Error(`Invalid ${resource} response`);
};

const StockReportContent: React.FC<{ embedded?: boolean }> = ({ embedded = false }) => {
    const [form] = Form.useForm<StockReportFormValues>();
    const [submittedFilters, setSubmittedFilters] = useState<StockReportRequestParams | null>(() => buildStockReportParams({}));

    const { data: warehousesResponse, isLoading: isLoadingWarehouses, isError: isWarehousesError } = useQuery({
        queryKey: ['warehouses', 'stock-report-filter'],
        queryFn: async () => parseOptionsResponse((await api.get('/master/warehouses')).data, 'warehouse catalogue'),
    });
    const { data: itemsResponse, isLoading: isLoadingItems, isError: isItemsError } = useQuery({
        queryKey: ['inventory-items', 'stock-report-filter'],
        queryFn: async () => parseOptionsResponse((await api.get('/inventory/items')).data, 'inventory item catalogue'),
    });
    const { data: reportData, isLoading, isError, refetch, error } = useQuery({
        queryKey: ['stock-report', submittedFilters],
        enabled: submittedFilters !== null,
        queryFn: async () => {
            const { data } = await api.get('/inventory/stock-report', { params: submittedFilters ?? {} });
            const payload: unknown = data;
            const rows = Array.isArray(payload)
                ? payload
                : (payload as { data?: unknown } | null)?.data;
            if (!Array.isArray(rows)) {
                throw new Error('Invalid stock-report response');
            }
            return parseStockReportRows(rows);
        },
    });

    const hasNotifiedScope = useRef(false);
    useEffect(() => {
        if (!hasNotifiedScope.current && reportData !== undefined) {
            hasNotifiedScope.current = true;
            message.info('Báo cáo chỉ tính chứng từ đã ghi sổ. Phiếu nhập/xuất đang ở trạng thái Bản nháp chưa ảnh hưởng đến số liệu tồn kho.');
        }
    }, [reportData]);

    const warehouses = asOptions(warehousesResponse);
    const items = asOptions(itemsResponse);

    const handleSearch = (values: StockReportFormValues) => {
        try {
            setSubmittedFilters(buildStockReportParams(values));
        } catch (error) {
            message.error(error instanceof Error ? error.message : 'Khoảng ngày không hợp lệ.');
        }
    };

    const reportRows = (reportData ?? []) as StockReportRow[];
    const formatCurrency = (val: unknown) => formatDecimalMoney(val as string | number | null | undefined, { currency: true });
    const formatQuantity = (val: unknown) => {
        const raw = String(val ?? '0');
        const negative = raw.startsWith('-');
        const unsigned = negative ? raw.slice(1) : raw;
        const [integer = '0', fraction] = unsigned.split('.');
        const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return `${negative ? '-' : ''}${grouped}${fraction ? `,${fraction.replace(/0+$/, '')}` : ''}`;
    };
    const sumQuantity = (key: keyof StockReportRow) => addDecimalQuantity(...reportRows.map(row => row[key] as string | number | null | undefined));
    const sumMoney = (key: keyof StockReportRow) => reportRows.reduce((sum, row) => addDecimalMoney(sum, row[key] as string | number | null | undefined), '0.00');
    const totalsRow: StockReportRow = {
        item_id: 'total', item_name: 'Tổng cộng',
        opening_qty: sumQuantity('opening_qty'), opening_amt: sumMoney('opening_amt'),
        in_qty: sumQuantity('in_qty'), in_amt: sumMoney('in_amt'),
        out_qty: sumQuantity('out_qty'), out_amt: sumMoney('out_amt'),
        end_qty: sumQuantity('end_qty'), end_amt: sumMoney('end_amt'),
    };

    const hasDateRange = submittedFilters?.from_date !== undefined;
    const catalogueUnavailable = isWarehousesError || isItemsError;

    const columns = [
        { title: 'Mã HH', dataIndex: 'item_code', key: 'item_code' },
        { title: 'Tên Hàng Hóa', dataIndex: 'item_name', key: 'item_name' },
        { title: 'ĐVT', dataIndex: 'unit', key: 'unit' },
        {
            title: hasDateRange ? 'Tồn đầu kỳ' : 'Tồn đầu dữ liệu',
            children: [
                { title: 'SL', dataIndex: 'opening_qty', key: 'opening_qty', render: formatQuantity },
                { title: 'Thành tiền', dataIndex: 'opening_amt', key: 'opening_amt', render: formatCurrency },
            ],
        },
        {
            title: hasDateRange ? 'Nhập trong khoảng đã chọn' : 'Nhập trên toàn bộ ngày dữ liệu',
            children: [
                { title: 'SL', dataIndex: 'in_qty', key: 'in_qty' },
                { title: 'Thành tiền', dataIndex: 'in_amt', key: 'in_amt', render: formatCurrency },
            ]
        },
        {
            title: hasDateRange ? 'Xuất trong khoảng đã chọn' : 'Xuất trên toàn bộ ngày dữ liệu',
            children: [
                { title: 'SL', dataIndex: 'out_qty', key: 'out_qty' },
                { title: 'Thành tiền', dataIndex: 'out_amt', key: 'out_amt', render: formatCurrency },
            ]
        },
        {
            title: hasDateRange ? 'Tồn cuối kỳ' : 'Tồn cuối dữ liệu',
            className: 'bg-green-50',
            children: [
                { title: 'SL', dataIndex: 'end_qty', key: 'end_qty', className: 'bg-green-50 font-bold' },
                { title: 'Thành tiền', dataIndex: 'end_amt', key: 'end_amt', render: formatCurrency, className: 'bg-green-50 font-bold' },
            ]
        },
    ];
    const exportColumns = [
        { title: 'Mã HH', dataIndex: 'item_code' },
        { title: 'Tên Hàng Hóa', dataIndex: 'item_name' },
        { title: 'ĐVT', dataIndex: 'unit' },
        { title: `${hasDateRange ? 'Tồn đầu kỳ' : 'Tồn đầu dữ liệu'} - SL`, dataIndex: 'opening_qty' },
        { title: `${hasDateRange ? 'Tồn đầu kỳ' : 'Tồn đầu dữ liệu'} - Thành tiền`, dataIndex: 'opening_amt' },
        { title: `${hasDateRange ? 'Nhập trong khoảng đã chọn' : 'Nhập trên toàn bộ ngày dữ liệu'} - SL`, dataIndex: 'in_qty' },
        { title: `${hasDateRange ? 'Nhập trong khoảng đã chọn' : 'Nhập trên toàn bộ ngày dữ liệu'} - Thành tiền`, dataIndex: 'in_amt' },
        { title: `${hasDateRange ? 'Xuất trong khoảng đã chọn' : 'Xuất trên toàn bộ ngày dữ liệu'} - SL`, dataIndex: 'out_qty' },
        { title: `${hasDateRange ? 'Xuất trong khoảng đã chọn' : 'Xuất trên toàn bộ ngày dữ liệu'} - Thành tiền`, dataIndex: 'out_amt' },
        { title: `${hasDateRange ? 'Tồn cuối kỳ' : 'Tồn cuối dữ liệu'} - SL`, dataIndex: 'end_qty' },
        { title: `${hasDateRange ? 'Tồn cuối kỳ' : 'Tồn cuối dữ liệu'} - Thành tiền`, dataIndex: 'end_amt' },
    ];
    const exportRows = [...reportRows, totalsRow];
    const reportActions = (
        <div className="stock-report-actions flex flex-wrap items-center justify-end gap-2" data-ui="stock-report-actions">
            <ExportExcelButton
                data={exportRows}
                columns={exportColumns}
                filename="Bao_Cao_Nhap_Xuat_Ton"
                monetaryFields={['opening_amt', 'in_amt', 'out_amt', 'end_amt']}
                disabled={isLoading || isError || reportRows.length === 0}
            />
            <Button
                icon={<PrinterOutlined />}
                onClick={() => window.print()}
                className="misa-btn-tool"
                title="In báo cáo"
                disabled={isLoading || isError || reportRows.length === 0}
            />
            <Button
                icon={<ReloadOutlined />}
                onClick={() => void refetch()}
                className="misa-btn-tool"
                title="Làm mới (F5)"
                loading={isLoading}
            />
        </div>
    );

    return (
        <PageShell embedded={embedded} title={<PageHeader
                eyebrow="KHO"
                title="Tổng hợp nhập – xuất – tồn"
                description="Dữ liệu theo ngày và kho đã chọn; chỉ tính chứng từ đã ghi sổ và số dư đầu kỳ đã xác nhận."
            />}>
            {!embedded && <PageToolbar leading={catalogueUnavailable ? <Alert type="error" showIcon message="Không thể tải danh mục lọc tồn kho" description="Không có dữ liệu thay thế; yêu cầu báo cáo bị khóa cho đến khi máy chủ trả đúng danh mục kho/hàng hóa." /> : undefined} filters={(
                <Form<StockReportFormValues> form={form} layout="inline" onFinish={handleSearch}>
                    <Form.Item name="dateRange" label="Khoảng ngày (tùy chọn)">
                        <DatePicker.RangePicker format="DD/MM/YYYY" />
                    </Form.Item>
                    <Form.Item name="warehouse_id" label="Kho (tùy chọn)">
                        <Select
                            allowClear
                            loading={isLoadingWarehouses}
                            className="w-[220px]"
                            placeholder="Không lọc kho"
                            options={warehouses.map((warehouse) => ({
                                value: warehouse.id,
                                label: [warehouse.code, warehouse.name].filter(Boolean).join(' - ') || `Kho #${warehouse.id}`,
                            }))}
                        />
                    </Form.Item>
                    <Form.Item name="item_id" label="Hàng hóa (tùy chọn)">
                        <Select
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            loading={isLoadingItems}
                            className="w-[260px]"
                            placeholder="Không lọc hàng hóa"
                            options={items.map((item) => ({
                                value: item.id,
                                label: [item.code, item.name].filter(Boolean).join(' - ') || `Hàng hóa #${item.id}`,
                            }))}
                        />
                    </Form.Item>
                    <Form.Item>
                        <Button type="primary" htmlType="submit" icon={<SearchOutlined />} disabled={catalogueUnavailable} className="misa-btn-primary">
                            Xem báo cáo
                        </Button>
                    </Form.Item>
                </Form>
            )} actions={reportActions} />}
            {embedded && (
                <PageToolbar filters={(
                    <>
                    {catalogueUnavailable && <Alert className="mb-3" type="error" showIcon message="Không thể tải danh mục lọc tồn kho" description="Không có dữ liệu thay thế; yêu cầu báo cáo bị khóa cho đến khi máy chủ trả đúng danh mục kho/hàng hóa." />}
                    <Form<StockReportFormValues> form={form} layout="inline" onFinish={handleSearch}>
                        <Form.Item name="dateRange" label="Khoảng ngày (tùy chọn)"><DatePicker.RangePicker format="DD/MM/YYYY" /></Form.Item>
                        <Form.Item name="warehouse_id" label="Kho (tùy chọn)"><Select allowClear loading={isLoadingWarehouses} className="w-[220px]" placeholder="Không lọc kho" options={warehouses.map((warehouse) => ({ value: warehouse.id, label: [warehouse.code, warehouse.name].filter(Boolean).join(' - ') || `Kho #${warehouse.id}` }))} /></Form.Item>
                        <Form.Item name="item_id" label="Hàng hóa (tùy chọn)"><Select allowClear showSearch optionFilterProp="label" loading={isLoadingItems} className="w-[260px]" placeholder="Không lọc hàng hóa" options={items.map((item) => ({ value: item.id, label: [item.code, item.name].filter(Boolean).join(' - ') || `Hàng hóa #${item.id}` }))} /></Form.Item>
                        <Form.Item><Button type="primary" htmlType="submit" icon={<SearchOutlined />} disabled={catalogueUnavailable} className="misa-btn-primary">Xem báo cáo</Button></Form.Item>
                    </Form>
                    </>
                )} actions={reportActions} />
            )}

            {submittedFilters !== null && (
                <>
                    {isError ? (
                        <ManagementReportDataError reportName="báo cáo tồn kho vận hành" onRetry={refetch} error={error} />
                    ) : (
                        <>
                            <DataTableSurface>
                                <Table
                                    className="misa-voucher-table"
                                    columns={columns}
                                    dataSource={reportRows}
                                    rowKey="item_id"
                                    loading={isLoading}
                                    pagination={false}
                                    bordered
                                    scroll={{ x: 'max-content' }}
                                    summary={() => (
                                        <Table.Summary fixed>
                                            <Table.Summary.Row>
                                                <Table.Summary.Cell index={0} colSpan={3}><strong>Tổng cộng</strong></Table.Summary.Cell>
                                                <Table.Summary.Cell index={3}>{formatQuantity(totalsRow.opening_qty)}</Table.Summary.Cell>
                                                <Table.Summary.Cell index={4}>{formatCurrency(totalsRow.opening_amt)}</Table.Summary.Cell>
                                                <Table.Summary.Cell index={5}>{formatQuantity(totalsRow.in_qty)}</Table.Summary.Cell>
                                                <Table.Summary.Cell index={6}>{formatCurrency(totalsRow.in_amt)}</Table.Summary.Cell>
                                                <Table.Summary.Cell index={7}>{formatQuantity(totalsRow.out_qty)}</Table.Summary.Cell>
                                                <Table.Summary.Cell index={8}>{formatCurrency(totalsRow.out_amt)}</Table.Summary.Cell>
                                                <Table.Summary.Cell index={9}>{formatQuantity(totalsRow.end_qty)}</Table.Summary.Cell>
                                                <Table.Summary.Cell index={10}>{formatCurrency(totalsRow.end_amt)}</Table.Summary.Cell>
                                            </Table.Summary.Row>
                                        </Table.Summary>
                                    )}
                                />
                            </DataTableSurface>
                        </>
                    )}
                </>
            )}
        </PageShell>
    );
};

const StockReport: React.FC<{ embedded?: boolean }> = ({ embedded = false }) => (
    <ManagementReportGate capabilityKey="stock_movement_balance">
        <StockReportContent embedded={embedded} />
    </ManagementReportGate>
);

export default StockReport;
