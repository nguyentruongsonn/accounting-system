import React from 'react';
import type { SalesDiscountLine } from '../types';

export interface SalesDiscountStatisticTabProps {
    line: SalesDiscountLine;
    index: number;
    disabled?: boolean;
    onUpdateLine: (index: number, changes: Partial<SalesDiscountLine>) => void;
}

export const SalesDiscountStatisticTab: React.FC<SalesDiscountStatisticTabProps> = ({
    line,
    index,
    disabled = false,
    onUpdateLine
}) => {
    return (
        <>
            <td>
                <input
                    className="misa-table-input"
                    value={line.order_reference || ''}
                    placeholder="Mã đơn hàng"
                    disabled={disabled}
                    onChange={e => onUpdateLine(index, { order_reference: e.target.value })}
                />
            </td>
            <td>
                <input
                    className="misa-table-input"
                    value={line.contract_reference || ''}
                    placeholder="Số hợp đồng"
                    disabled={disabled}
                    onChange={e => onUpdateLine(index, { contract_reference: e.target.value })}
                />
            </td>
            <td>
                <input
                    className="misa-table-input"
                    value={line.expense_item_code || ''}
                    placeholder="Khoản mục CP"
                    disabled={disabled}
                    onChange={e => onUpdateLine(index, { expense_item_code: e.target.value })}
                />
            </td>
        </>
    );
};

export default SalesDiscountStatisticTab;
