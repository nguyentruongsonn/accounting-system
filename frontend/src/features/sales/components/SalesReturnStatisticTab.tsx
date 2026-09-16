import React from 'react';
import type { SalesReturnLine } from '../types';

export interface SalesReturnStatisticTabProps {
    line: SalesReturnLine;
    index: number;
    disabled?: boolean;
    onUpdateLine: (index: number, changes: Partial<SalesReturnLine>) => void;
}

export const SalesReturnStatisticTab: React.FC<SalesReturnStatisticTabProps> = ({
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

export default SalesReturnStatisticTab;
