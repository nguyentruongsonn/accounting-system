import React, { useEffect, useState } from 'react';
import { Card, Button, Select, DatePicker, Table, Tag, Input, Space, Popconfirm } from 'antd';
import { toast as message } from '../../components/feedback/toast';
import {
    ThunderboltOutlined,
    CheckCircleOutlined,
    PrinterOutlined,
    ReloadOutlined
} from '@ant-design/icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../../api/axios';
import dayjs from 'dayjs';
import { VoucherPrintModal } from '../../components/misa';
import { compareDecimalMoney, formatDecimalMoney } from '../../utils/decimalMoney';
import ReportDataError from '../reports/ReportDataError';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import PageToolbar from '../../components/layout/PageToolbar';
import DataTableSurface from '../../components/layout/DataTableSurface';

type MoneyEvidence = string | number;

type ClosingPreviewLine = {
    step: string;
    description: string;
    debit_account: string;
    credit_account: string;
    amount: MoneyEvidence;
};

type ClosingPreview = {
    total_revenue: MoneyEvidence;
    total_expenses: MoneyEvidence;
    net_profit: MoneyEvidence;
    suggested_lines: ClosingPreviewLine[];
    execution_available?: boolean;
};

function isMoneyEvidence(value: unknown): value is MoneyEvidence {
    if (typeof value === 'number') return Number.isFinite(value);
    return typeof value === 'string' && /^[+-]?\d+(?:\.\d+)?$/.test(value.trim());
}

function parseClosingPreview(value: unknown): ClosingPreview {
    const envelope = value && typeof value === 'object' ? value as Record<string, unknown> : null;
    const candidate = envelope?.data && typeof envelope.data === 'object'
        ? envelope.data as Record<string, unknown>
        : envelope;

    if (!candidate || !isMoneyEvidence(candidate.total_revenue)
        || !isMoneyEvidence(candidate.total_expenses)
        || !isMoneyEvidence(candidate.net_profit)
        || !Array.isArray(candidate.suggested_lines)) {
        throw new Error('Invalid period-close preview response.');
    }

    candidate.suggested_lines.forEach((line, index) => {
        if (!line || typeof line !== 'object') {
            throw new Error(`Invalid period-close preview line at index ${index}.`);
        }
        const row = line as Record<string, unknown>;
        if (typeof row.step !== 'string' || row.step.trim() === ''
            || typeof row.description !== 'string' || row.description.trim() === ''
            || typeof row.debit_account !== 'string' || row.debit_account.trim() === ''
            || typeof row.credit_account !== 'string' || row.credit_account.trim() === ''
            || !isMoneyEvidence(row.amount)) {
            throw new Error(`Invalid period-close preview line at index ${index}.`);
        }
    });

    if (candidate.execution_available !== undefined && typeof candidate.execution_available !== 'boolean') {
        throw new Error('Invalid period-close execution capability.');
    }

    return candidate as unknown as ClosingPreview;
}

function displayMoney(value: MoneyEvidence | null | undefined): string {
    return value === null || value === undefined ? '—' : `${formatDecimalMoney(value)} ₫`;
}

export const ClosingEntries: React.FC<{ embedded?: boolean }> = ({ embedded = false }) => {
    const [periodType, setPeriodType] = useState('current_month');
    const [fromDate, setFromDate] = useState(dayjs().startOf('month').format('YYYY-MM-DD'));
    const [toDate, setToDate] = useState(dayjs().endOf('month').format('YYYY-MM-DD'));
    const [postingDate, setPostingDate] = useState<any>(dayjs().endOf('month'));
    const [voucherNumber, setVoucherNumber] = useState('KC-' + dayjs().format('YYYYMM'));
    const [reason, setReason] = useState('Kết chuyển kết quả kinh doanh');
    const [isPrintModalVisible, setIsPrintModalVisible] = useState(false);
    const [executedVoucher, setExecutedVoucher] = useState<any>(null);

    const queryClient = useQueryClient();

    // Handle Period Change
    const handlePeriodChange = (val: string) => {
        setPeriodType(val);
        const now = dayjs();
        let from = now.startOf('month');
        let to = now.endOf('month');

        if (val === 'current_month') {
            from = now.startOf('month');
            to = now.endOf('month');
        } else if (val === 'last_month') {
            from = now.subtract(1, 'month').startOf('month');
            to = now.subtract(1, 'month').endOf('month');
        } else if (val === 'current_quarter') {
            from = now.startOf('quarter' as any);
            to = now.endOf('quarter' as any);
        } else if (val === 'current_year') {
            from = now.startOf('year');
            to = now.endOf('year');
        }

        setFromDate(from.format('YYYY-MM-DD'));
        setToDate(to.format('YYYY-MM-DD'));
        setPostingDate(to);
        setVoucherNumber(`KC-${to.format('YYYYMM')}`);
        setReason(`Kết chuyển kết quả kinh doanh kỳ ${from.format('DD/MM/YYYY')} - ${to.format('DD/MM/YYYY')}`);
    };

    // 1. Query Preview
    const { data: previewData, isLoading, isError, error, refetch } = useQuery<ClosingPreview>({
        queryKey: ['closing-preview', fromDate, toDate],
        queryFn: async () => {
            const { data } = await api.get('/gl/closing-entries/preview', {
                params: { from_date: fromDate, to_date: toDate }
            });
            return parseClosingPreview(data);
        },
    });

    // 2. Mutation Execute
    const executeMutation = useMutation({
        mutationFn: async () => {
            const payload = {
                from_date: fromDate,
                to_date: toDate,
                posting_date: postingDate.format('YYYY-MM-DD'),
                voucher_date: postingDate.format('YYYY-MM-DD'),
                voucher_number: voucherNumber,
                reason: reason,
                description: reason,
            };
            const { data } = await api.post('/gl/closing-entries/execute', payload);
            return data?.data || data;
        },
        onSuccess: (data) => {
            // A 2xx response alone is not proof that a journal entry was
            // persisted. Keep the UI fail-closed if a proxy/controller ever
            // returns an empty or malformed success envelope.
            const persistedId = data && (data.id ?? data.uuid);
            if (!persistedId) {
                message.error('Máy chủ không trả về chứng từ kết chuyển đã lưu; không xác nhận thành công.');
                return;
            }
            message.success('Đã tự động kết chuyển lãi lỗ và ghi sổ cái thành công!');
            setExecutedVoucher(data);
            queryClient.invalidateQueries({ queryKey: ['closing-preview'] });
            queryClient.invalidateQueries({ queryKey: ['journal-entries'] });
            queryClient.invalidateQueries({ queryKey: ['financial-reports'] });
        },
        onError: (err: any) => {
            message.error(err.response?.data?.message || 'Có lỗi xảy ra khi thực hiện kết chuyển!');
        }
    });

    const totalRevenue = previewData?.total_revenue ?? null;
    const totalExpenses = previewData?.total_expenses ?? null;
    const netProfit = previewData?.net_profit ?? null;
    const netProfitSign = netProfit === null ? null : compareDecimalMoney(netProfit, '0');
    const suggestedLines = previewData?.suggested_lines ?? [];
    // The legacy preview endpoint does not publish an approved mapping or an
    // execution capability. Keep the action unavailable until the server
    // explicitly supplies both through a controlled contract.
    const executionAvailable = previewData?.execution_available === true;

    useEffect(() => {
        if (previewData && !executionAvailable) {
            message.warning('Kết chuyển cuối kỳ chưa sẵn sàng thực thi: Máy chủ chưa cung cấp mapping kết chuyển đã được duyệt hoặc capability thực thi. Bạn vẫn có thể xem số liệu xem trước.');
        }
    }, [previewData, executionAvailable]);

    const columns = [
        {
            title: '#',
            key: 'idx',
            width: 45,
            align: 'center' as const,
            render: (_: any, __: any, index: number) => index + 1
        },
        {
            title: 'Bước nghiệp vụ',
            dataIndex: 'step',
            key: 'step',
            width: 240,
            render: (t: string) => <span className="misa-text-dark-black font-semibold">{t}</span>
        },
        {
            title: 'Diễn giải bút toán',
            dataIndex: 'description',
            key: 'description',
            minWidth: 260
        },
        {
            title: 'TK Nợ',
            dataIndex: 'debit_account',
            key: 'debit_account',
            width: 100,
            align: 'center' as const,
            render: (t: string) => <Tag color="blue" className="font-bold">{t || '—'}</Tag>
        },
        {
            title: 'TK Có',
            dataIndex: 'credit_account',
            key: 'credit_account',
            width: 100,
            align: 'center' as const,
            render: (t: string) => <Tag color="cyan" className="font-bold">{t || '—'}</Tag>
        },
        {
            title: 'Số tiền kết chuyển',
            dataIndex: 'amount',
            key: 'amount',
            width: 180,
            align: 'right' as const,
            render: (v: MoneyEvidence) => (
                <span className="font-semibold text-blue-700">
                    {displayMoney(v)}
                </span>
            )
        },
    ];

    return (
        <PageShell embedded={embedded} title={<PageHeader eyebrow="Sổ cái" title="Kết chuyển cuối kỳ" description="Xem trước các dòng kết chuyển do máy chủ cung cấp trước khi thực hiện." />}>
            {!embedded && <PageToolbar leading={<span className="ui-page-toolbar__context">Xem trước kết chuyển</span>} />}

            {/* Config & Action Box */}
            <div className="misa-filter-box-lg mb-4 p-4 bg-white border border-gray-200 rounded-lg shadow-sm">
                <div className="grid grid-cols-12 gap-4 items-end">
                    <div className="col-span-3">
                        <div className="font-semibold text-xs text-gray-600 mb-1">Kỳ kết chuyển</div>
                        <Select
                            value={periodType}
                            onChange={handlePeriodChange}
                            className="w-full"
                            options={[
                                { value: 'current_month', label: 'Tháng này' },
                                { value: 'last_month', label: 'Tháng trước' },
                                { value: 'current_quarter', label: 'Quý này' },
                                { value: 'current_year', label: 'Cả năm nay' },
                            ]}
                        />
                    </div>

                    <div className="col-span-2">
                        <div className="font-semibold text-xs text-gray-600 mb-1">Từ ngày</div>
                        <DatePicker
                            value={dayjs(fromDate)}
                            onChange={(d) => d && setFromDate(d.format('YYYY-MM-DD'))}
                            className="w-full"
                            format="DD/MM/YYYY"
                        />
                    </div>

                    <div className="col-span-2">
                        <div className="font-semibold text-xs text-gray-600 mb-1">Đến ngày / Ngày HT</div>
                        <DatePicker
                            value={postingDate}
                            onChange={(d) => {
                                if (d) {
                                    setPostingDate(d);
                                    setToDate(d.format('YYYY-MM-DD'));
                                }
                            }}
                            className="w-full"
                            format="DD/MM/YYYY"
                        />
                    </div>

                    <div className="col-span-2">
                        <div className="font-semibold text-xs text-gray-600 mb-1">Số chứng từ</div>
                        <Input
                            value={voucherNumber}
                            onChange={e => setVoucherNumber(e.target.value)}
                            className="font-semibold"
                        />
                    </div>

                    <div className="col-span-3 flex gap-2">
                        <Button
                            icon={<ReloadOutlined />}
                            onClick={() => refetch()}
                            loading={isLoading}
                        >
                            Xem trước
                        </Button>
                        <Popconfirm
                            title="Thực hiện kết chuyển lãi lỗ kỳ này?"
                            description="Chưa có mapping kết chuyển được phê duyệt; máy chủ phải công bố capability thực thi trước khi tạo hoặc ghi sổ chứng từ."
                            onConfirm={() => executeMutation.mutate()}
                            okText="Kết chuyển ngay"
                            cancelText="Hủy"
                        >
                            <Button
                                type="primary"
                                icon={<ThunderboltOutlined />}
                                loading={executeMutation.isPending}
                                className="misa-btn-primary"
                                disabled={!executionAvailable || suggestedLines.length === 0}
                            >
                                Kết chuyển (chưa sẵn sàng)
                            </Button>
                        </Popconfirm>
                    </div>
                </div>
            </div>

            {/* Financial Summary Cards */}
            <div className="grid grid-cols-3 gap-4 mb-4">
                <Card size="small" className="border-l-4 border-l-blue-500 bg-blue-50/40">
                    <div className="text-xs uppercase font-bold text-blue-800">TỔNG DOANH THU KẾT CHUYỂN (5xx, 7xx)</div>
                    <div className="text-2xl font-bold text-blue-600 mt-1">
                        {displayMoney(totalRevenue)}
                    </div>
                </Card>
                <Card size="small" className="border-l-4 border-l-rose-500 bg-rose-50/40">
                    <div className="text-xs uppercase font-bold text-rose-800">TỔNG CHI PHÍ KẾT CHUYỂN (6xx, 8xx)</div>
                    <div className="text-2xl font-bold text-rose-600 mt-1">
                        {displayMoney(totalExpenses)}
                    </div>
                </Card>
                <Card size="small" className="border-l-4 border-l-indigo-500 bg-indigo-50/40">
                    <div className="text-xs uppercase font-bold text-indigo-800">
                        {netProfitSign === null ? 'KẾT QUẢ KẾ TOÁN —' : 'KẾT QUẢ XEM TRƯỚC'}
                    </div>
                    <div className={`text-2xl font-bold mt-1 ${netProfitSign === null ? 'text-slate-500' : netProfitSign >= 0 ? 'text-indigo-600' : 'text-rose-600'}`}>
                        {displayMoney(netProfit)}
                    </div>
                </Card>
            </div>

            {/* Closing Entries Grid */}
            <div className="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                <div className="flex justify-between items-center mb-3">
                    <span className="font-bold text-slate-800 text-base">
                        Bản xem dòng kết chuyển ({dayjs(fromDate).format('DD/MM/YYYY')} - {dayjs(toDate).format('DD/MM/YYYY')})
                    </span>
                    <Space>
                        <Button
                            icon={<PrinterOutlined />}
                            size="small"
                            onClick={() => setIsPrintModalVisible(true)}
                            disabled={!executionAvailable || suggestedLines.length === 0}
                        >
                            Xem bản in xem trước
                        </Button>
                        {executedVoucher && (
                            <Tag color="blue" icon={<CheckCircleOutlined />}>
                                Đã kết chuyển thành công ({executedVoucher.voucher_number})
                            </Tag>
                        )}
                    </Space>
                </div>
                {isError ? (
                    <ReportDataError
                        reportName="Bản xem kết chuyển"
                        error={error}
                        onRetry={refetch}
                    />
                ) : (
                    <DataTableSurface className="closing-entries-table-surface">
                        <Table
                            columns={columns}
                            dataSource={suggestedLines}
                            rowKey={(item) => `${item.step}:${item.debit_account}:${item.credit_account}:${item.description}`}
                            pagination={false}
                            size="small"
                            loading={isLoading}
                            bordered
                            locale={{ emptyText: previewData ? 'Không có dòng preview do máy chủ cung cấp.' : 'Chưa có evidence preview từ máy chủ.' }}
                        />
                    </DataTableSurface>
                )}
            </div>

            {/* Voucher Print Modal */}
            <VoucherPrintModal
                open={isPrintModalVisible}
                onCancel={() => setIsPrintModalVisible(false)}
                type="journal"
                data={{
                    voucher_number: voucherNumber,
                    voucher_date: postingDate,
                    posting_date: postingDate,
                    description: reason,
                    lines: suggestedLines.map((l: any) => ({
                        description: l.description,
                        debit_account: l.debit_account,
                        credit_account: l.credit_account,
                        amount: l.amount,
                    }))
                }}
            />
        </PageShell>
    );
};

export default ClosingEntries;
