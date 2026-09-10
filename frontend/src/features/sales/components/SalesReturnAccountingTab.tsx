import React from 'react';
import { InputNumber } from 'antd';
import { AccountSelect } from '../../../components/misa';
import type { SalesReturnLine } from '../types';

export interface SalesReturnAccountingTabProps {
    line: SalesReturnLine;
    index: number;
    disabled?: boolean;
    onUpdateLine: (index: number, changes: Partial<SalesReturnLine>) => void;
}

export const SalesReturnAccountingTab: React.FC<SalesReturnAccountingTabProps> = ({
    line,
    index,
    disabled = false,
    onUpdateLine
}) => {
    return (
        <>
            <td>
                <AccountSelect
                    value={line.debit_account}
                    disabled={disabled}
                    onChange={val => onUpdateLine(index, { debit_account: val })}
                />
            </td>
            <td>
                <AccountSelect
                    value={line.credit_account}
                    disabled={disabled}
                    onChange={val => onUpdateLine(index, { credit_account: val })}
                />
            </td>
            <td>
                <input
                    className="misa-table-input"
                    value={line.unit || ''}
                    disabled={disabled}
                    onChange={e => onUpdateLine(index, { unit: e.target.value })}
                />
            </td>
            <td>
                <InputNumber
                    min={0}
                    value={line.quantity}
                    variant="borderless"
                    disabled={disabled}
                    className="misa-table-input misa-w-full misa-text-right"
                    onChange={val => onUpdateLine(index, { quantity: Number(val) || 0 })}
                />
            </td>
            <td>
                <InputNumber
                    min={0}
                    value={line.unit_price}
                    variant="borderless"
                    disabled={disabled}
                    className="misa-table-input misa-w-full misa-text-right"
                    formatter={v => `${v}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}
                    onChange={val => onUpdateLine(index, { unit_price: Number(val) || 0 })}
                />
            </td>
            <td className="misa-text-right misa-fw-700 misa-color-darker">
                {new Intl.NumberFormat('vi-VN').format(Number(line.amount) || 0)}
            </td>
            <td>
                <input
                    className="misa-table-input"
                    value={line.description || ''}
                    disabled={disabled}
                    onChange={e => onUpdateLine(index, { description: e.target.value })}
                />
            </td>
        </>
    );
};

export default SalesReturnAccountingTab;
