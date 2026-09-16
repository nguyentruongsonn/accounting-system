export type DashboardTrendMetric = string | null;

export type DashboardTrendPoint = {
    period_start: string | null;
    period_end: string | null;
    label: string;
    revenue: DashboardTrendMetric;
    gross_cost: DashboardTrendMetric;
    operating_expenses: DashboardTrendMetric;
    profit: DashboardTrendMetric;
    cash: DashboardTrendMetric;
    bank: DashboardTrendMetric;
    receivables: DashboardTrendMetric;
    payables: DashboardTrendMetric;
};

export type DashboardTrendResponse = {
    data: DashboardTrendPoint[];
    meta: {
        from_date?: string;
        to_date?: string;
        fiscal_year_id?: number;
        source: string;
        complete: boolean;
    };
};

const metricKeys = [
    'revenue',
    'gross_cost',
    'operating_expenses',
    'profit',
    'cash',
    'bank',
    'receivables',
    'payables',
] as const;

const isRecord = (value: unknown): value is Record<string, unknown> => (
    typeof value === 'object' && value !== null
);

const asMetric = (value: unknown): DashboardTrendMetric => (
    typeof value === 'string' || typeof value === 'number' ? String(value) : null
);

export const parseDashboardTrendResponse = (value: unknown): DashboardTrendResponse => {
    if (!isRecord(value) || !Array.isArray(value.data) || !isRecord(value.meta)) {
        throw new Error('Invalid dashboard trend response');
    }

    const data = value.data.map((rawPoint): DashboardTrendPoint => {
        if (!isRecord(rawPoint) || typeof rawPoint.label !== 'string') {
            throw new Error('Invalid dashboard trend response');
        }

        const point = {
            period_start: typeof rawPoint.period_start === 'string' ? rawPoint.period_start : null,
            period_end: typeof rawPoint.period_end === 'string' ? rawPoint.period_end : null,
            label: rawPoint.label,
        } as DashboardTrendPoint;

        for (const key of metricKeys) {
            point[key] = asMetric(rawPoint[key]);
        }

        return point;
    });

    if (typeof value.meta.source !== 'string' || typeof value.meta.complete !== 'boolean') {
        throw new Error('Invalid dashboard trend response');
    }

    return {
        data,
        meta: {
            from_date: typeof value.meta.from_date === 'string' ? value.meta.from_date : undefined,
            to_date: typeof value.meta.to_date === 'string' ? value.meta.to_date : undefined,
            fiscal_year_id: typeof value.meta.fiscal_year_id === 'number' ? value.meta.fiscal_year_id : undefined,
            source: value.meta.source,
            complete: value.meta.complete,
        },
    };
};
