import React from 'react';

export interface VoucherSummaryItem {
    label: string;
    value: number;
    isTotal?: boolean;
    hideIfZero?: boolean;
}

export interface MisaVoucherSummaryCardProps {
    items: VoucherSummaryItem[];
    className?: string;
    style?: React.CSSProperties;
    suffix?: string;
}

export const MisaVoucherSummaryCard: React.FC<MisaVoucherSummaryCardProps> = ({
    items,
    className = '',
    style,
    suffix = ''
}) => {
    return (
        <div 
            className={`misa-voucher-summary-card ${className}`.trim()}
            style={{ marginLeft: 'auto', ...style }}
        >
            {items.map((item, idx) => {
                if (item.hideIfZero && item.value === 0) return null;
                return (
                    <div 
                        key={idx} 
                        className={`misa-voucher-summary-row ${item.isTotal ? 'total' : ''}`}
                    >
                        <span>{item.label}</span>
                        <span className="value">
                            {new Intl.NumberFormat('vi-VN').format(item.value)}{suffix ? ` ${suffix}` : ''}
                        </span>
                    </div>
                );
            })}
        </div>
    );
};

export default MisaVoucherSummaryCard;
