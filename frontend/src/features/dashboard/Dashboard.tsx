import React, { useEffect, useState } from 'react';
import { Button, Col, DatePicker, Row, Skeleton, Statistic, Tabs, Tag } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import { 
    DollarCircleOutlined, 
    BankOutlined, 
    FallOutlined, 
    RiseOutlined,
    ArrowRightOutlined,
    LineChartOutlined,
    AppstoreOutlined,
    ArrowUpOutlined,
    ArrowDownOutlined,
    MinusOutlined,
} from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../../api/axios';
import {
    absDecimalMoney,
    addDecimalMoney,
    compareDecimalMoney,
    decimalRatio,
    formatDecimalMoney,
    subtractDecimalMoney,
} from '../../utils/decimalMoney';
import PageShell from '../../components/layout/PageShell';
import PageHeader from '../../components/layout/PageHeader';
import { toast } from '../../components/feedback/toast';
import DashboardTrendChart from './DashboardTrendChart';
import { parseDashboardTrendResponse } from './dashboardTrend';
import './dashboard.css';

const { RangePicker } = DatePicker;

type MetricComparison = {
    diff: string;
    percent: string | null;
    direction: 'up' | 'down' | 'flat';
};

const computeComparison = (
    current: string | null,
    previous: string | null,
): MetricComparison | null => {
    if (current === null || previous === null) return null;
    try {
        const cmp = compareDecimalMoney(current, previous);
        const diff = subtractDecimalMoney(current, previous);
        const direction: 'up' | 'down' | 'flat' = cmp > 0 ? 'up' : cmp < 0 ? 'down' : 'flat';
        const prevAbs = absDecimalMoney(previous);
        let percent: string | null = null;
        if (compareDecimalMoney(prevAbs, '0.00') > 0) {
            const diffAbs = absDecimalMoney(diff);
            const ratio = (Number(diffAbs) / Number(prevAbs)) * 100;
            if (Number.isFinite(ratio)) {
                percent = ratio >= 1000 ? '>999%' : `${ratio.toFixed(1)}%`;
            }
        }
        return {
            diff,
            percent,
            direction,
        };
    } catch {
        return null;
    }
};

const ComparisonBadge: React.FC<{
    comparison: MetricComparison | null;
    periodLabel?: string;
    reverseColor?: boolean;
}> = ({ comparison, periodLabel = '', reverseColor = false }) => {
    if (!comparison) {
        return <span style={{ fontSize: 11, color: '#94A3B8' }}>—</span>;
    }

    const { direction, percent } = comparison;
    if (direction === 'flat') {
        return (
            <span style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 3,
                fontSize: 11,
                fontWeight: 600,
                color: '#475467',
                background: '#F2F4F7',
                padding: '2px 6px',
                borderRadius: 4,
            }}>
                <MinusOutlined style={{ fontSize: 9 }} />
                0%{periodLabel ? ` vs ${periodLabel}` : ''}
            </span>
        );
    }

    const isGood = reverseColor ? direction === 'down' : direction === 'up';
    const color = isGood ? '#027A48' : '#B42318';
    const bg = isGood ? '#ECFDF3' : '#FEF3F2';
    const sign = direction === 'up' ? '+' : '-';

    return (
        <span style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 3,
            fontSize: 11,
            fontWeight: 600,
            color,
            background: bg,
            padding: '2px 6px',
            borderRadius: 4,
        }}>
            {direction === 'up' ? <ArrowUpOutlined style={{ fontSize: 10 }} /> : <ArrowDownOutlined style={{ fontSize: 10 }} />}
            {percent ? `${sign}${percent}` : (direction === 'up' ? '+ Mới' : '-')}
            {periodLabel ? ` vs ${periodLabel}` : ''}
        </span>
    );
};

const Dashboard: React.FC = () => {
    const [activeTab, setActiveTab] = useState('tinh-hinh');
    const [trendRange, setTrendRange] = useState<[Dayjs, Dayjs]>(() => [
        dayjs().startOf('year'),
        dayjs(),
    ]);
    const navigate = useNavigate();

    type DashboardBalanceData = {
        assets: unknown[];
        liabilities: unknown[];
        equity: unknown[];
    };

    const parseBalanceSheet = (value: unknown): DashboardBalanceData => {
        if (!value || typeof value !== 'object'
            || !Array.isArray((value as DashboardBalanceData).assets)
            || !Array.isArray((value as DashboardBalanceData).liabilities)
            || !Array.isArray((value as DashboardBalanceData).equity)) {
            throw new Error('Invalid dashboard balance-sheet response');
        }
        return value as DashboardBalanceData;
    };

    const parseIncomeStatement = (value: unknown): unknown[] => {
        if (!Array.isArray(value)) {
            throw new Error('Invalid dashboard income-statement response');
        }
        return value;
    };

    // Fetch real data from reports API to show on dashboard
    const balanceQuery = useQuery({
        queryKey: ['balance-sheet-kpi'],
        queryFn: async () => {
            const { data } = await api.get('/reports/balance-sheet');
            return parseBalanceSheet(data);
        },
    });

    const incomeQuery = useQuery({
        queryKey: ['income-statement-kpi'],
        queryFn: async () => {
            const { data } = await api.get('/reports/income-statement');
            return parseIncomeStatement(data);
        },
    });

    const trendQuery = useQuery({
        queryKey: ['dashboard-trends', trendRange[0].format('YYYY-MM-DD'), trendRange[1].format('YYYY-MM-DD')],
        queryFn: async () => {
            const { data } = await api.get('/reports/dashboard-trends', {
                params: {
                    from_date: trendRange[0].format('YYYY-MM-DD'),
                    to_date: trendRange[1].format('YYYY-MM-DD'),
                },
            });
            return parseDashboardTrendResponse(data);
        },
    });

    useEffect(() => {
        if (trendQuery.isError) {
            toast.error('Không thể tải xu hướng tài chính trong kỳ đã chọn.');
        }
    }, [trendQuery.isError]);

    const { data: balanceData } = balanceQuery;
    const { data: incomeData } = incomeQuery;
    const reportUnavailable = balanceQuery.isError || incomeQuery.isError;

    type DashboardAmount = string | null;

    const asAmount = (value: unknown): DashboardAmount => {
        if (typeof value === 'string' || typeof value === 'number') return String(value);
        return null;
    };

    const formatCurrency = (value: DashboardAmount) =>
        value === null ? '—' : formatDecimalMoney(value, { currency: true });

    const findAmount = (rows: unknown, code: string, field: string): DashboardAmount => {
        if (!Array.isArray(rows)) return null;
        const row = rows.find((item) => item && typeof item === 'object' && (item as { code?: unknown }).code === code);
        return row && typeof row === 'object' ? asAmount((row as Record<string, unknown>)[field]) : null;
    };

    // Extract KPIs - Current period
    const cash = findAmount(balanceData?.assets, '111', 'end_balance');
    const bank = findAmount(balanceData?.assets, '112', 'end_balance');
    const receivables = findAmount(balanceData?.assets, '131', 'end_balance');
    const payables = findAmount(balanceData?.liabilities, '331', 'end_balance');

    const revenue = findAmount(incomeData, '10', 'this_period');
    const grossCost = findAmount(incomeData, '11', 'this_period');
    const profit = findAmount(incomeData, '60', 'this_period');
    const cashTotal = cash !== null && bank !== null
        ? addDecimalMoney(cash, bank)
        : null;
    const liquidAssets = cashTotal !== null && receivables !== null
        ? addDecimalMoney(cashTotal, receivables)
        : null;
    const payablesPositive = payables !== null && compareDecimalMoney(payables, '0.00') > 0;

    const salesExpense = findAmount(incomeData, '25', 'this_period');
    const adminExpense = findAmount(incomeData, '26', 'this_period');
    const operatingExpenses = salesExpense !== null && adminExpense !== null
        ? addDecimalMoney(salesExpense, adminExpense)
        : null;
    const profitBeforeTax = findAmount(incomeData, '50', 'this_period');

    // Extract KPIs - Comparative period (Start balance for Balance Sheet, Previous period for Income Statement)
    const cashStart = findAmount(balanceData?.assets, '111', 'start_balance');
    const bankStart = findAmount(balanceData?.assets, '112', 'start_balance');
    const cashTotalStart = cashStart !== null && bankStart !== null
        ? addDecimalMoney(cashStart, bankStart)
        : null;
    const receivablesStart = findAmount(balanceData?.assets, '131', 'start_balance');
    const payablesStart = findAmount(balanceData?.liabilities, '331', 'start_balance');

    const revenuePrev = findAmount(incomeData, '10', 'prev_period');
    const grossCostPrev = findAmount(incomeData, '11', 'prev_period');
    const salesExpensePrev = findAmount(incomeData, '25', 'prev_period');
    const adminExpensePrev = findAmount(incomeData, '26', 'prev_period');
    const operatingExpensesPrev = salesExpensePrev !== null && adminExpensePrev !== null
        ? addDecimalMoney(salesExpensePrev, adminExpensePrev)
        : null;
    const profitBeforeTaxPrev = findAmount(incomeData, '50', 'prev_period');
    const profitPrev = findAmount(incomeData, '60', 'prev_period');

    // Comparative metrics
    const cashComp = computeComparison(cashTotal, cashTotalStart);
    const receivablesComp = computeComparison(receivables, receivablesStart);
    const payablesComp = computeComparison(payables, payablesStart);
    const profitComp = computeComparison(profit, profitPrev);

    const revenueComp = computeComparison(revenue, revenuePrev);
    const grossCostComp = computeComparison(grossCost, grossCostPrev);
    const operatingExpensesComp = computeComparison(operatingExpenses, operatingExpensesPrev);
    const profitBeforeTaxComp = computeComparison(profitBeforeTax, profitBeforeTaxPrev);

    const financialTabContent = reportUnavailable ? (
        <div className="misa-p-12 misa-mb-12" style={{ background: '#FFFBEB', border: '1px solid #FDE68A', borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
            <div>
                <div style={{ fontWeight: 600, color: '#92400E', fontSize: 13 }}>Bảng điều khiển chưa có dữ liệu báo cáo hợp lệ</div>
                <div style={{ color: '#B45309', fontSize: 12 }}>Máy chủ không trả về đủ cấu trúc Bảng cân đối/Kết quả kinh doanh; giao diện không hiển thị KPI tạm hoặc số 0 thay thế.</div>
            </div>
            <Button
                size="small"
                onClick={() => {
                    void balanceQuery.refetch();
                    void incomeQuery.refetch();
                }}
            >
                Thử lại
            </Button>
        </div>
    ) : (
        <div className="dashboard-overview" style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
            <div className="dashboard-trend-toolbar">
                <RangePicker
                    value={trendRange}
                    allowClear={false}
                    format="DD/MM/YYYY"
                    onChange={(values) => {
                        if (values?.[0] && values[1]) setTrendRange([values[0], values[1]]);
                    }}
                />
            </div>
            {/* Top KPI Cards */}
            <Row gutter={[16, 16]}>
                <Col xs={24} sm={12} lg={6}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '16px 20px',
                        boxShadow: 'none',
                        position: 'relative',
                        overflow: 'hidden',
                    }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: '#1F5AA6' }} />
                        <Statistic
                            title="Tổng Tiền (111 + 112)"
                            value={cashTotal ?? undefined}
                            prefix={<DollarCircleOutlined style={{ color: '#1F5AA6', marginRight: 6 }} />}
                            formatter={() => <span style={{ color: '#1C1E21', fontWeight: 700, fontSize: 20 }}>{formatCurrency(cashTotal)}</span>}
                        />
                        <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 4 }}>
                            <ComparisonBadge comparison={cashComp} periodLabel="đầu kỳ" />
                            <span style={{ fontSize: 11, color: '#64748B', fontWeight: 500 }}>
                                Đầu kỳ: {formatCurrency(cashTotalStart)}
                            </span>
                        </div>
                    </div>
                </Col>
                
                <Col xs={24} sm={12} lg={6}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '16px 20px',
                        boxShadow: 'none',
                        position: 'relative',
                        overflow: 'hidden',
                    }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: '#1F5AA6' }} />
                        <Statistic
                            title={<span style={{ color: '#666A72', fontSize: 13, fontWeight: 500 }}>Phải thu khách hàng (131)</span>}
                            value={receivables ?? undefined}
                            prefix={<RiseOutlined style={{ color: '#1F5AA6', marginRight: 6 }} />}
                            formatter={() => <span style={{ color: '#1C1E21', fontWeight: 700, fontSize: 20 }}>{formatCurrency(receivables)}</span>}
                        />
                        <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 4 }}>
                            <ComparisonBadge comparison={receivablesComp} periodLabel="đầu kỳ" />
                            <span style={{ fontSize: 11, color: '#64748B', fontWeight: 500 }}>
                                Đầu kỳ: {formatCurrency(receivablesStart)}
                            </span>
                        </div>
                    </div>
                </Col>
                
                <Col xs={24} sm={12} lg={6}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '16px 20px',
                        boxShadow: 'none',
                        position: 'relative',
                        overflow: 'hidden',
                    }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: '#1F5AA6' }} />
                        <Statistic
                            title={<span style={{ color: '#666A72', fontSize: 13, fontWeight: 500 }}>Phải trả người bán (331)</span>}
                            value={payables ?? undefined}
                            prefix={<FallOutlined style={{ color: '#1F5AA6', marginRight: 6 }} />}
                            formatter={() => <span style={{ color: '#1C1E21', fontWeight: 700, fontSize: 20 }}>{formatCurrency(payables)}</span>}
                        />
                        <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 4 }}>
                            <ComparisonBadge comparison={payablesComp} periodLabel="đầu kỳ" reverseColor />
                            <span style={{ fontSize: 11, color: '#64748B', fontWeight: 500 }}>
                                Đầu kỳ: {formatCurrency(payablesStart)}
                            </span>
                        </div>
                    </div>
                </Col>

                <Col xs={24} sm={12} lg={6}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '16px 20px',
                        boxShadow: 'none',
                        position: 'relative',
                        overflow: 'hidden',
                    }}>
                        <div style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 3, background: '#1F5AA6' }} />
                        <Statistic
                            title={<span style={{ color: '#666A72', fontSize: 13, fontWeight: 500 }}>Lợi nhuận sau thuế</span>}
                            value={profit ?? undefined}
                            prefix={<BankOutlined style={{ color: '#1F5AA6', marginRight: 6 }} />}
                            formatter={() => <span style={{ color: '#1C1E21', fontWeight: 700, fontSize: 20 }}>{formatCurrency(profit)}</span>}
                        />
                        <div style={{ marginTop: 8, display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 4 }}>
                            <ComparisonBadge comparison={profitComp} periodLabel="kỳ trước" />
                            <span style={{ fontSize: 11, color: '#64748B', fontWeight: 500 }}>
                                Kỳ trước: {formatCurrency(profitPrev)}
                            </span>
                        </div>
                    </div>
                </Col>
            </Row>

            {/* Income & Solvency Details */}
            <Row gutter={[16, 16]}>
                <Col xs={24} lg={12}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '20px',
                        boxShadow: 'none',
                    }}>
                        <div style={{ fontSize: 15, fontWeight: 700, color: '#1C1E21', marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <LineChartOutlined style={{ color: '#1F5AA6' }} />
                            Kết quả hoạt động kinh doanh
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, padding: '10px 14px', background: '#FAFBFC', borderRadius: 8, border: '1px solid #E5E7EB' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontWeight: 600, color: '#374151', fontSize: 13 }}>Doanh thu thuần</span>
                                    <span style={{ fontWeight: 700, color: '#1F5AA6', fontSize: 14 }}>{formatCurrency(revenue)}</span>
                                </div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 11, color: '#64748B' }}>
                                    <span>Kỳ trước: {formatCurrency(revenuePrev)}</span>
                                    <ComparisonBadge comparison={revenueComp} />
                                </div>
                            </div>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, padding: '10px 14px', background: '#FAFBFC', borderRadius: 8, border: '1px solid #E5E7EB' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontWeight: 600, color: '#374151', fontSize: 13 }}>Giá vốn hàng bán</span>
                                    <span style={{ fontWeight: 700, color: '#64748B', fontSize: 14 }}>
                                        {formatCurrency(grossCost)}
                                    </span>
                                </div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 11, color: '#64748B' }}>
                                    <span>Kỳ trước: {formatCurrency(grossCostPrev)}</span>
                                    <ComparisonBadge comparison={grossCostComp} reverseColor />
                                </div>
                            </div>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, padding: '10px 14px', background: '#FAFBFC', borderRadius: 8, border: '1px solid #E5E7EB' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontWeight: 600, color: '#374151', fontSize: 13 }}>Chi phí bán hàng & QLDN</span>
                                    <span style={{ fontWeight: 700, color: '#64748B', fontSize: 14 }}>
                                        {formatCurrency(operatingExpenses)}
                                    </span>
                                </div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 11, color: '#64748B' }}>
                                    <span>Kỳ trước: {formatCurrency(operatingExpensesPrev)}</span>
                                    <ComparisonBadge comparison={operatingExpensesComp} reverseColor />
                                </div>
                            </div>
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 4, padding: '12px 14px', background: '#F8FAFC', border: '1px solid #E2E8F0', borderRadius: 8 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                                    <span style={{ fontWeight: 700, color: '#1F5AA6', fontSize: 13 }}>LỢI NHUẬN TRƯỚC THUẾ</span>
                                    <span style={{ fontWeight: 800, color: '#1F5AA6', fontSize: 16 }}>
                                        {formatCurrency(profitBeforeTax)}
                                    </span>
                                </div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 11, color: '#64748B' }}>
                                    <span>Kỳ trước: {formatCurrency(profitBeforeTaxPrev)}</span>
                                    <ComparisonBadge comparison={profitBeforeTaxComp} />
                                </div>
                            </div>
                        </div>
                    </div>
                </Col>

                <Col xs={24} lg={12}>
                    <div style={{
                        background: '#FFFFFF',
                        border: '1px solid #E5E7EB',
                        borderRadius: 8,
                        padding: '20px',
                        boxShadow: 'none',
                    }}>
                        <div style={{ fontSize: 15, fontWeight: 700, color: '#1C1E21', marginBottom: 16, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <BankOutlined style={{ color: '#1F5AA6' }} />
                            Chỉ số an toàn thanh toán
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                            <div style={{ padding: '14px', border: '1px solid #E5E7EB', borderRadius: 8, background: '#FFFFFF' }}>
                                <div style={{ fontSize: 12, color: '#666A72', marginBottom: 4 }}>Tỷ số thanh toán hiện hành (Current Ratio)</div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#1C1E21' }}>
                                    {payablesPositive && liquidAssets !== null ? decimalRatio(liquidAssets, payables) ?? '—' : '—'}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748B', marginTop: 4, fontWeight: 500 }}>
                                    Tài sản lưu động / Nợ ngắn hạn (&gt; 1.0 là an toàn)
                                </div>
                            </div>
                            <div style={{ padding: '14px', border: '1px solid #E5E7EB', borderRadius: 8, background: '#FFFFFF' }}>
                                <div style={{ fontSize: 12, color: '#666A72', marginBottom: 4 }}>Tỷ số thanh toán nhanh (Quick Ratio)</div>
                                <div style={{ fontSize: 22, fontWeight: 800, color: '#1C1E21' }}>
                                    {payablesPositive && cashTotal !== null ? decimalRatio(cashTotal, payables) ?? '—' : '—'}
                                </div>
                                <div style={{ fontSize: 11, color: '#64748B', marginTop: 4, fontWeight: 500 }}>
                                    Tiền mặt / Nợ ngắn hạn
                                </div>
                            </div>
                        </div>
                    </div>
                </Col>
            </Row>

            <div className="dashboard-trend-grid">
                {trendQuery.isLoading ? (
                    <div className="dashboard-trend-card dashboard-trend-loading"><Skeleton active paragraph={{ rows: 5 }} /></div>
                ) : (
                    <DashboardTrendChart
                        title="Kết quả theo kỳ"
                        data={trendQuery.data?.data ?? []}
                        series={[
                            { key: 'revenue', label: 'Doanh thu', color: '#1F5AA6' },
                            { key: 'gross_cost', label: 'Giá vốn', color: '#94A3B8' },
                            { key: 'profit', label: 'Lợi nhuận', color: '#64748B' },
                        ]}
                        valueFormatter={(value) => formatCurrency(value)}
                        emptyMessage="Chưa đủ dữ liệu trong kỳ đã chọn"
                    />
                )}
                {trendQuery.isLoading ? (
                    <div className="dashboard-trend-card dashboard-trend-loading"><Skeleton active paragraph={{ rows: 5 }} /></div>
                ) : (
                    <DashboardTrendChart
                        title="Thanh khoản & công nợ"
                        data={trendQuery.data?.data ?? []}
                        series={[
                            { key: 'cash', label: 'Tiền mặt', color: '#1F5AA6' },
                            { key: 'bank', label: 'Tiền gửi', color: 'var(--ui-border)' },
                            { key: 'receivables', label: 'Phải thu', color: '#64748B' },
                            { key: 'payables', label: 'Phải trả', color: '#94A3B8' },
                        ]}
                        valueFormatter={(value) => formatCurrency(value)}
                        emptyMessage="Chưa đủ dữ liệu trong kỳ đã chọn"
                    />
                )}
            </div>
        </div>
    );

    const workspaceLinks = [
        { title: 'Tiền mặt', description: 'Lập và tra cứu phiếu thu, phiếu chi tiền mặt, kiểm kê quỹ.', route: '/cash', tag: 'Quỹ' },
        { title: 'Mua hàng', description: 'Đơn mua (PO), chứng từ mua hàng qua kho, giảm giá, trả lại.', route: '/purchase', tag: 'Mua' },
        { title: 'Bán hàng', description: 'Báo giá, đơn hàng (SO), chứng từ bán hàng, xuất hóa đơn.', route: '/sales', tag: 'Bán' },
        { title: 'Kho & Giá vốn', description: 'Phiếu nhập kho, xuất kho, chuyển kho, tính giá xuất tự động.', route: '/inventory', tag: 'Kho' },
        { title: 'Sổ cái & Tổng hợp', description: 'Phiếu kế toán, kết chuyển lãi lỗ, khóa sổ kỳ kế toán.', route: '/gl', tag: 'GL' },
        { title: 'Báo cáo nội bộ', description: 'Bảng cân đối, kết quả kinh doanh, cân đối tài khoản và sổ chi tiết.', route: '/reports', tag: 'Báo cáo' },
        { title: 'Danh mục tài khoản', description: 'Hệ thống tài khoản và cấu hình hạch toán được máy chủ phê duyệt.', route: '/settings/account-catalogues', tag: 'COA' },
    ];

    const workspaceTabContent = (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <div style={{ fontSize: 15, fontWeight: 700, color: '#1C1E21', display: 'flex', alignItems: 'center', gap: 8 }}>
                <AppstoreOutlined style={{ color: '#1F5AA6' }} />
                Bàn làm việc nội bộ
            </div>

            <Row gutter={[16, 16]}>
                {workspaceLinks.map((workflow) => (
                    <Col xs={24} sm={12} lg={8} key={workflow.route}>
                        <div
                            onClick={() => navigate(workflow.route)}
                            style={{
                                background: '#FFFFFF',
                                border: '1px solid #E5E7EB',
                                borderRadius: 8,
                                padding: '18px 20px',
                                height: '100%',
                                display: 'flex',
                                flexDirection: 'column',
                                justifyContent: 'space-between',
                                cursor: 'pointer',
                                transition: 'border-color 150ms ease',
                                boxShadow: 'none',
                            }}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.borderColor = '#1F5AA6';
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.borderColor = '#E5E7EB';
                            }}
                        >
                            <div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                                    <span style={{ fontSize: 15, fontWeight: 700, color: '#1C1E21' }}>{workflow.title}</span>
                                    <Tag color="blue" style={{ borderRadius: 4, margin: 0, fontSize: 10, padding: '0 6px' }}>{workflow.tag}</Tag>
                                </div>
                                <p style={{ fontSize: 12, color: '#666A72', lineHeight: 1.5, margin: 0 }}>
                                    {workflow.description}
                                </p>
                            </div>
                            <div style={{ marginTop: 14, display: 'flex', alignItems: 'center', gap: 6, color: '#1F5AA6', fontSize: 12, fontWeight: 600 }}>
                                <span>Mở phân hệ</span>
                                <ArrowRightOutlined style={{ fontSize: 11 }} />
                            </div>
                        </div>
                    </Col>
                ))}
            </Row>
        </div>
    );

    return (
        <PageShell
            title={(
                <PageHeader
                    eyebrow="TỔNG QUAN"
                    title="Bảng điều khiển tổng quan"
                    description="Theo dõi sức khỏe tài chính, dòng tiền và truy cập nhanh các phân hệ kế toán."
                />
            )}
         >
            <div style={{
                background: '#FFFFFF',
                borderRadius: 8,
                border: '1px solid #E5E7EB',
                padding: '16px 20px',
                boxShadow: 'none',
            }}>
                <Tabs
                    activeKey={activeTab}
                    onChange={setActiveTab}
                    items={[
                        { key: 'tinh-hinh', label: 'TÌNH HÌNH TÀI CHÍNH', children: financialTabContent },
                        { key: 'ban-lam-viec', label: 'BÀN LÀM VIỆC NGHIỆP VỤ', children: workspaceTabContent },
                    ]}
                />
            </div>
        </PageShell>
    );
};

export default Dashboard;
