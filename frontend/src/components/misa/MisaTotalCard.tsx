import React from 'react';
import { readMoneyToVietnameseWords } from '../../utils/numberToWords';

export interface MisaTotalCardProps {
    label?: string;
    title?: string;
    value?: number;
    amount?: number;
    currency?: string;
    showWords?: boolean;
    readWords?: boolean;
}

export const MisaTotalCard: React.FC<MisaTotalCardProps> = ({
    label,
    title,
    value,
    amount,
    currency = '₫',
    showWords,
    readWords
}) => {
    const displayLabel = label || title || 'Tổng tiền thanh toán';
    const displayValue = value ?? amount ?? 0;
    const displayWords = showWords ?? readWords ?? true;

    return (
        <div className="misa-total-card">
            <div className="misa-total-card-label">
                {displayLabel}
            </div>
            <div className="misa-total-card-value">
                {new Intl.NumberFormat('vi-VN').format(displayValue)} <span className="misa-fs-16 misa-fw-600">{currency}</span>
            </div>
            {displayWords && displayValue > 0 && (
                <div className="misa-fs-11 misa-color-muted misa-italic misa-mt-2">
                    {readMoneyToVietnameseWords(displayValue)}
                </div>
            )}
        </div>
    );
};

export default MisaTotalCard;
