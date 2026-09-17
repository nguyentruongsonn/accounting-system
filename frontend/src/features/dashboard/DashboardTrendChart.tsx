import React from 'react';
import type { DashboardTrendPoint } from './dashboardTrend';

export type DashboardTrendSeries = {
    key: keyof DashboardTrendPoint;
    label: string;
    color: string;
};

type DashboardTrendChartProps = {
    title: string;
    data: DashboardTrendPoint[];
    series: DashboardTrendSeries[];
    valueFormatter: (value: string) => string;
    emptyMessage: string;
};

const toNumber = (value: unknown): number | null => {
    if (typeof value !== 'string' && typeof value !== 'number') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : null;
};

const DashboardTrendChart: React.FC<DashboardTrendChartProps> = ({
    title,
    data,
    series,
    valueFormatter,
    emptyMessage,
}) => {
    const availableValues = data.flatMap((point) => series.map((item) => toNumber(point[item.key]))).filter((value): value is number => value !== null);
    const hasValues = availableValues.length > 0;
    const maxValue = Math.max(...availableValues, 1);
    const chartWidth = 640;
    const chartHeight = 210;
    const horizontalPadding = 28;
    const verticalPadding = 24;
    const plotWidth = chartWidth - horizontalPadding * 2;
    const plotHeight = chartHeight - verticalPadding * 2;

    return (
        <section className="dashboard-trend-card" aria-label={title}>
            <div className="dashboard-trend-card-header">
                <div>
                    <h3>{title}</h3>
                    {data.length >= 2 && (
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 5, flexWrap: 'wrap' }}>
                            <span style={{ fontSize: 11, color: '#64748B', fontWeight: 500 }}>
                                So với tháng trước ({data[data.length - 2].label} → {data[data.length - 1].label}):
                            </span>
                            {series.map((item) => {
                                const latestVal = toNumber(data[data.length - 1][item.key]);
                                const prevVal = toNumber(data[data.length - 2][item.key]);
                                if (latestVal === null || prevVal === null) return null;
                                const diff = latestVal - prevVal;
                                const isZero = diff === 0;
                                const isUp = diff > 0;
                                let pct = '';
                                if (Math.abs(prevVal) > 0) {
                                    pct = `${((Math.abs(diff) / Math.abs(prevVal)) * 100).toFixed(1)}%`;
                                }
                                return (
                                    <span
                                        key={String(item.key)}
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            gap: 4,
                                            fontSize: 11,
                                            fontWeight: 600,
                                            padding: '1px 6px',
                                            borderRadius: 4,
                                            color: isZero ? '#475467' : isUp ? '#027A48' : '#B42318',
                                            background: isZero ? '#F2F4F7' : isUp ? '#ECFDF3' : '#FEF3F2',
                                        }}
                                        title={`${item.label}: ${valueFormatter(String(latestVal))} (kỳ trước: ${valueFormatter(String(prevVal))})`}
                                    >
                                        <span style={{ width: 6, height: 6, borderRadius: '50%', backgroundColor: item.color, display: 'inline-block' }} />
                                        {item.label}: {isZero ? '0%' : `${isUp ? '+' : '-'}${pct || ''}`}
                                    </span>
                                );
                            })}
                        </div>
                    )}
                </div>
                <div className="dashboard-trend-legend" aria-label="Chú giải biểu đồ">
                    {series.map((item) => <span key={String(item.key)}><i style={{ backgroundColor: item.color }} />{item.label}</span>)}
                </div>
            </div>
            <div className="dashboard-trend-chart" role="img" aria-label={title}>
                {!hasValues ? (
                    <div className="dashboard-trend-empty">{emptyMessage}</div>
                ) : (
                    <svg viewBox={`0 0 ${chartWidth} ${chartHeight}`} preserveAspectRatio="none">
                        <line x1={horizontalPadding} y1={chartHeight - verticalPadding} x2={chartWidth - horizontalPadding} y2={chartHeight - verticalPadding} stroke="#E5E7EB" />
                        {series.map((item) => {
                            const points = data.map((point, index) => {
                                const value = toNumber(point[item.key]);
                                if (value === null) return null;
                                const x = data.length === 1
                                    ? chartWidth / 2
                                    : horizontalPadding + (index / (data.length - 1)) * plotWidth;
                                const y = chartHeight - verticalPadding - (value / maxValue) * plotHeight;
                                return `${x},${y}`;
                            }).filter((point): point is string => point !== null).join(' ');

                            return <polyline key={String(item.key)} points={points} fill="none" stroke={item.color} strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />;
                        })}
                    </svg>
                )}
            </div>
            {hasValues && (
                <div className="dashboard-trend-labels">
                    {data.map((point) => <span key={point.label}>{point.label}</span>)}
                </div>
            )}
            {hasValues && <span className="dashboard-trend-sr-only">{valueFormatter(String(maxValue))}</span>}
        </section>
    );
};

export default DashboardTrendChart;
