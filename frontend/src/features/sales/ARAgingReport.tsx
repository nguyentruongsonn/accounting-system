import React, { useState } from 'react';
import { Alert, Button, Input, Space, Table } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import api from '../../api/axios';
import { formatDecimalMoney } from '../../utils/decimalMoney';
import ManagementReportGate from '../reports/ManagementReportGate';
import ManagementReportDataError from '../reports/ManagementReportDataError';
import { executableAgingV2, v2RequestConfig } from '../reports/agingV2Execution';
import { useManagementReportCapabilities } from '../reports/managementReportCapabilities';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

type ARAgingRow = {
    customer_id?: number;
    customer_code: string;
    customer_name: string;
    total_due: string | number;
    current: string | number;
    days_1_30: string | number;
    days_31_60: string | number;
    days_over_60: string | number;
    credit_balance?: string | number;
};

type ARAgingV2Response = {
    definition_version: string;
    as_of_date: string;
    rows: ARAgingRow[];
};

const money = (value: string | number) => formatDecimalMoney(value, { currency: true });

const isMoneyValue = (value: unknown): value is string | number => {
    if (typeof value === 'number') return Number.isFinite(value);
    return typeof value === 'string' && /^[-+]?\d+(?:\.\d{1,2})?$/.test(value.trim());
};

const isPartyId = (value: unknown): value is number =>
    value === undefined || (typeof value === 'number' && Number.isInteger(value) && value > 0);

function parseRows(value: unknown, label: string, requireCreditBalance = false): ARAgingRow[] {
    if (!Array.isArray(value)) throw new Error(`Invalid ${label} aging response shape.`);
    const amountFields = ['total_due', 'current', 'days_1_30', 'days_31_60', 'days_over_60'];
    value.forEach((row, index) => {
        const creditBalance = row && typeof row === 'object'
            ? (row as Record<string, unknown>).credit_balance
            : undefined;
        if (!row || typeof row !== 'object' || typeof (row as { customer_code?: unknown }).customer_code !== 'string'
            || typeof (row as { customer_name?: unknown }).customer_name !== 'string'
            || !isPartyId((row as { customer_id?: unknown }).customer_id)
            || amountFields.some((field) => !isMoneyValue((row as Record<string, unknown>)[field]))
            || (requireCreditBalance ? !isMoneyValue(creditBalance) : creditBalance !== undefined && !isMoneyValue(creditBalance))) {
            throw new Error(`Invalid ${label} aging row at index ${index}.`);
        }
    });
    return value as ARAgingRow[];
}

export function parseLegacyResponse(value: unknown): ARAgingRow[] {
    if (Array.isArray(value)) return parseRows(value, 'AR');
    if (value && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data)) {
        return parseRows((value as { data: unknown }).data, 'AR');
    }
    throw new Error('Invalid AR aging response envelope.');
}

export function parseV2Response(value: unknown): ARAgingV2Response {
    if (!value || typeof value !== 'object') throw new Error('Invalid AR aging v2 response envelope.');
    const response = value as Record<string, unknown>;
    if (typeof response.definition_version !== 'string' || typeof response.as_of_date !== 'string') {
        throw new Error('AR aging v2 definition/cutoff evidence is missing.');
    }
    return { definition_version: response.definition_version, as_of_date: response.as_of_date, rows: parseRows(response.rows, 'AR v2', true) };
}

const legacyColumns: ColumnsType<ARAgingRow> = [
    { title: 'Mã KH', dataIndex: 'customer_code', key: 'customer_code', width: 120 },
    { title: 'Tên khách hàng', dataIndex: 'customer_name', key: 'customer_name' },
    { title: 'Số dư phải thu', dataIndex: 'total_due', key: 'total_due', align: 'right', render: money, className: 'font-bold' },
    { title: 'Trong hạn', dataIndex: 'current', key: 'current', align: 'right', render: money },
    { title: 'Quá hạn 1-30 ngày', dataIndex: 'days_1_30', key: 'days_1_30', align: 'right', render: money },
    { title: 'Quá hạn 31-60 ngày', dataIndex: 'days_31_60', key: 'days_31_60', align: 'right', render: money },
    { title: 'Quá hạn >60 ngày', dataIndex: 'days_over_60', key: 'days_over_60', align: 'right', render: money, className: 'text-red-500 font-bold' },
];

const v2Columns: ColumnsType<ARAgingRow> = [
    { title: 'Mã KH', dataIndex: 'customer_code', key: 'customer_code', width: 120 },
    { title: 'Tên khách hàng', dataIndex: 'customer_name', key: 'customer_name' },
    { title: 'Số dư phải thu', dataIndex: 'total_due', key: 'total_due', align: 'right', render: money, className: 'font-bold' },
    { title: 'Trong hạn', dataIndex: 'current', key: 'current', align: 'right', render: money },
    { title: 'Quá hạn 1-30 ngày', dataIndex: 'days_1_30', key: 'days_1_30', align: 'right', render: money },
    { title: 'Quá hạn 31-60 ngày', dataIndex: 'days_31_60', key: 'days_31_60', align: 'right', render: money },
    { title: 'Quá hạn >60 ngày', dataIndex: 'days_over_60', key: 'days_over_60', align: 'right', render: money, className: 'text-red-500 font-bold' },
    { title: 'Dư có/Phân bổ vượt', dataIndex: 'credit_balance', key: 'credit_balance', align: 'right', render: money },
];

type ARAgingReportProps = { embedded?: boolean };

const ARAgingReportContent: React.FC<ARAgingReportProps> = ({ embedded = false }) => {
    const { data: manifest } = useManagementReportCapabilities();
    const capability = manifest?.capabilities.find((item) => item.key === 'accounts_receivable_aging');
    const v2 = executableAgingV2(capability);
    const [asOfDate, setAsOfDate] = useState('');
    const [requestedAsOfDate, setRequestedAsOfDate] = useState<string | null>(null);

    const legacy = useQuery<ARAgingRow[]>({
        queryKey: ['ar-aging', 'legacy-v1', requestedAsOfDate],
        queryFn: async () => {
            const { data } = await api.get('/sales/ar-aging', requestedAsOfDate ? { params: { as_of_date: requestedAsOfDate } } : undefined);
            return parseLegacyResponse(data);
        },
        enabled: Boolean(manifest) && v2 === null,
    });

    const v2Report = useQuery<ARAgingV2Response>({
        queryKey: ['ar-aging', 'v2', v2?.apiPath, requestedAsOfDate],
        queryFn: async () => {
            if (!v2 || !requestedAsOfDate) throw new Error('Thiếu ngày chốt hoặc capability v2.');
            const request = v2RequestConfig(v2.apiPath);
            const { data } = await api.get<ARAgingV2Response>(request.url, {
                baseURL: request.baseURL,
                params: { as_of_date: requestedAsOfDate },
            });
            return parseV2Response(data);
        },
        enabled: v2 !== null && requestedAsOfDate !== null,
    });

    if (v2) {
        const cutoff = v2Report.data?.as_of_date ?? requestedAsOfDate;
        return (
            <PageShell embedded={embedded} title={<PageHeader
                eyebrow="BÁN HÀNG"
                title="Phân tích tuổi nợ phải thu"
                description="Số dư công nợ theo khách hàng, tính đến ngày chốt đã chọn."
            />}>
                    <PageToolbar filters={<Space wrap>
                        <label htmlFor="ar-aging-as-of">Ngày chốt (as-of)</label>
                        <Input id="ar-aging-as-of" aria-label="Ngày chốt AR aging" type="date" value={asOfDate} onChange={(event) => setAsOfDate(event.target.value)} />
                        <Button type="primary" disabled={!asOfDate} onClick={() => setRequestedAsOfDate(asOfDate)}>Xem báo cáo</Button>
                    </Space>} />
                    {cutoff && <p className="misa-color-muted misa-fs-13">Số liệu được tính đến hết ngày: <strong>{cutoff}</strong>.</p>}
                    {v2Report.isError ? (
                        <ManagementReportDataError reportName="báo cáo tuổi nợ phải thu v2" onRetry={v2Report.refetch} error={v2Report.error} />
                    ) : requestedAsOfDate === null ? (
                        <Alert type="warning" showIcon message="Chọn ngày chốt để chạy báo cáo v2; giao diện không tự suy ra ngày as-of." />
                    ) : (
                        <DataTableSurface>
                            <Table columns={v2Columns} dataSource={v2Report.data?.rows ?? []} rowKey={(row) => row.customer_id ?? row.customer_code} loading={v2Report.isLoading} pagination={false} size="small" bordered scroll={{ x: 'max-content' }} />
                        </DataTableSurface>
                    )}
            </PageShell>
        );
    }

    return (
        <PageShell embedded={embedded} title={<PageHeader
            eyebrow="BÁN HÀNG"
            title="Phân tích tuổi nợ phải thu"
            description="Phân nhóm theo ngày chốt; chỉ tính hóa đơn đã ghi sổ và các khoản phân bổ đã ghi nhận đến ngày đó."
        />}>
                <PageToolbar filters={<Space wrap>
                    <label htmlFor="ar-aging-legacy-as-of">Ngày chốt</label>
                    <Input id="ar-aging-legacy-as-of" aria-label="Ngày chốt AR aging legacy" type="date" value={asOfDate} onChange={(event) => setAsOfDate(event.target.value)} />
                    <Button type="primary" disabled={!asOfDate} loading={legacy.isFetching} onClick={() => setRequestedAsOfDate(asOfDate)}>Xem báo cáo</Button>
                    {requestedAsOfDate && <span className="text-gray-500">Đến hết {requestedAsOfDate}</span>}
                </Space>} />
                {legacy.isError ? (
                    <ManagementReportDataError reportName="báo cáo tuổi nợ phải thu" onRetry={legacy.refetch} error={legacy.error} />
                ) : (
                    <DataTableSurface>
                        <Table columns={legacyColumns} dataSource={legacy.data ?? []} rowKey={(row) => row.customer_id ?? row.customer_code} loading={legacy.isLoading} pagination={false} size="small" bordered scroll={{ x: 'max-content' }} />
                    </DataTableSurface>
                )}
        </PageShell>
    );
};

export const ARAgingReport: React.FC<ARAgingReportProps> = ({ embedded = false }) => (
    <ManagementReportGate capabilityKey="accounts_receivable_aging">
        <ARAgingReportContent embedded={embedded} />
    </ManagementReportGate>
);

export default ARAgingReport;
