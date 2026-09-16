import React from 'react';

export interface SummaryItem {
    label: string;
    value: number | string;
    format?: 'currency' | 'number' | 'none';
    isCurrency?: boolean;
    highlight?: boolean;
    isHighlight?: boolean;
}

export interface MisaTableSummaryBarProps {
    items?: SummaryItem[];
    metrics?: SummaryItem[];
    lineCount?: number;
    leftContent?: React.ReactNode;
}

export const MisaTableSummaryBar: React.FC<MisaTableSummaryBarProps> = ({
    items,
    metrics,
    lineCount,
    leftContent
}) => {
    const rawItems = items || metrics || [];

    const formatValue = (item: SummaryItem) => {
        if (typeof item.value === 'string') return item.value;
        if (item.format === 'number') {
            return new Intl.NumberFormat('vi-VN').format(item.value);
        }
        if (item.format === 'none') {
            return `${item.value}`;
        }
        if (item.isCurrency || item.format === 'currency' || item.format === undefined) {
            return `${new Intl.NumberFormat('vi-VN').format(item.value)} ₫`;
        }
        return `${new Intl.NumberFormat('vi-VN').format(item.value)} ₫`;
    };

    const renderedLeft = leftContent !== undefined
        ? leftContent
        : (lineCount !== undefined ? <span className="misa-fs-12 misa-color-muted">Số dòng: <strong>{lineCount}</strong></span> : null);

    return (
        <div className="misa-table-summary-bar">
            <div>
                {renderedLeft}
            </div>

            <div className="misa-summary-items">
                {rawItems.map((item, idx) => (
                    <div key={idx} className={`misa-summary-item ${item.highlight || item.isHighlight ? 'highlight' : ''}`}>
                        {item.label}: <strong>{formatValue(item)}</strong>
                    </div>
                ))}
            </div>
        </div>
    );
};

export default MisaTableSummaryBar;
