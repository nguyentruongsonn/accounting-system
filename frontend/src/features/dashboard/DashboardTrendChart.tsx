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
                <h3>{title}</h3>
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
