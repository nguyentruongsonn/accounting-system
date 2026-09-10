import React from 'react';
import { InputNumber } from 'antd';
import { AccountSelect } from '../../../components/misa';
import type { SalesReturnLine } from '../types';

export interface SalesReturnCogsTabProps {
    line: SalesReturnLine;
    index: number;
    disabled?: boolean;
    onUpdateLine: (index: number, changes: Partial<SalesReturnLine>) => void;
}

export const SalesReturnCogsTab: React.FC<SalesReturnCogsTabProps> = ({
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
                    value={line.warehouse_code}
                    disabled={disabled}
                    onChange={e => onUpdateLine(index, { warehouse_code: e.target.value })}
                />
            </td>
            <td>
                <AccountSelect
                    value={line.cogs_debit_account}
                    disabled={disabled}
                    onChange={val => onUpdateLine(index, { cogs_debit_account: val })}
                />
            </td>
            <td>
                <AccountSelect
                    value={line.cogs_credit_account}
                    disabled={disabled}
                    onChange={val => onUpdateLine(index, { cogs_credit_account: val })}
                />
            </td>
            <td>
                <InputNumber
                    min={0}
                    value={line.cogs_unit_price}
                    variant="borderless"
                    disabled={disabled}
                    className="misa-table-input misa-w-full misa-text-right"
                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                    onChange={val => onUpdateLine(index, { cogs_unit_price: Number(val) || 0 })}
                />
            </td>
            <td className="misa-text-right misa-fw-700">
                {new Intl.NumberFormat('vi-VN').format(Number(line.cogs_amount) || 0)}
            </td>
        </>
    );
};

export default SalesReturnCogsTab;
