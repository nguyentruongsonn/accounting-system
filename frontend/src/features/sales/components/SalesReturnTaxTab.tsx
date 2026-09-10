import React from 'react';
import { Select } from 'antd';
import { AccountSelect } from '../../../components/misa';
import type { SalesReturnLine } from '../types';

export interface SalesReturnTaxTabProps {
    line: SalesReturnLine;
    index: number;
    disabled?: boolean;
    onUpdateLine: (index: number, changes: Partial<SalesReturnLine>) => void;
}

export const SalesReturnTaxTab: React.FC<SalesReturnTaxTabProps> = ({
    line,
    index,
    disabled = false,
    onUpdateLine
}) => {
    return (
        <>
            <td>
                <Select
                    value={line.tax_rate}
                    variant="borderless"
                    disabled={disabled}
                    className="misa-table-input misa-w-full"
                    onChange={val => onUpdateLine(index, { tax_rate: val })}
                    options={[
                        { value: 0, label: '0%' },
                        { value: 5, label: '5%' },
                        { value: 8, label: '8%' },
                        { value: 10, label: '10%' },
                    ]}
                />
            </td>
            <td className="misa-text-right misa-fw-700">
                {new Intl.NumberFormat('vi-VN').format(Number(line.tax_amount) || 0)}
            </td>
            <td>
                <AccountSelect
                    value={line.tax_account}
                    disabled={disabled}
                    onChange={val => onUpdateLine(index, { tax_account: val })}
                />
            </td>
            <td>
                <input
                    className="misa-table-input"
                    value={line.invoice_number || ''}
                    placeholder="Số HĐ gốc"
                    disabled={disabled}
                    onChange={e => onUpdateLine(index, { invoice_number: e.target.value })}
                />
            </td>
            <td>
                <input
                    className="misa-table-input"
                    type="date"
                    value={line.invoice_date || ''}
                    disabled={disabled}
                    onChange={e => onUpdateLine(index, { invoice_date: e.target.value })}
                />
            </td>
        </>
    );
};

export default SalesReturnTaxTab;
